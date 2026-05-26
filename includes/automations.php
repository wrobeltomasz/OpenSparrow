<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

function auto_load_config(): array
{
    $path = __DIR__ . '/../config/automations.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return $data['automations'] ?? [];
}

/**
 * Evaluate automation rules for a given table event and execute matching actions.
 * Called from api.php after INSERT and PATCH mutations.
 */
function evaluate_automation_rules(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    string $event,
    int $userId
): void {
    if ($event === 'delete') {
        return;
    }

    $all   = auto_load_config();
    $rules = array_filter($all, static function (array $r) use ($table, $event): bool {
        return !empty($r['enabled'])
            && ($r['trigger_table'] ?? '') === $table
            && ($r['trigger_event'] ?? '') === $event;
    });

    if (empty($rules)) {
        return;
    }

    $sql    = sprintf('SELECT * FROM %s.%s WHERE id = $1', pg_ident($tableSchema), pg_ident($table));
    $recRes = @pg_query_params($conn, $sql, [$recordId]);
    if (!$recRes) {
        return;
    }
    $record = pg_fetch_assoc($recRes);
    pg_free_result($recRes);
    if (!$record) {
        return;
    }

    foreach ($rules as $rule) {
        $conditions = $rule['conditions'] ?? ['type' => 'AND', 'rules' => []];
        $actions    = $rule['actions'] ?? [];
        $ruleId     = (string) ($rule['id'] ?? '');
        $ruleName   = (string) ($rule['name'] ?? '');

        if (!auto_check_conditions($conditions, $record)) {
            auto_log_run($conn, $ruleId, $ruleName, $table, $recordId, $event, 'skipped', null);
            continue;
        }

        $errors = [];
        foreach ($actions as $action) {
            $err = auto_execute_action($conn, $tableSchema, $table, $recordId, $record, $action, $userId, $ruleId);
            if ($err !== null) {
                $errors[] = $err;
            }
        }

        $status = empty($errors) ? 'ok' : 'error';
        auto_log_run(
            $conn,
            $ruleId,
            $ruleName,
            $table,
            $recordId,
            $event,
            $status,
            $errors !== [] ? implode('; ', $errors) : null
        );
    }
}

// Recursive: group has type (AND|OR) + rules array. Rules can be leaf conditions or nested groups.
function auto_check_conditions(array $group, array $record): bool
{
    $type  = strtoupper((string) ($group['type'] ?? 'AND'));
    $items = $group['rules'] ?? [];

    if (empty($items)) {
        return true;
    }

    foreach ($items as $item) {
        $result = isset($item['type'], $item['rules'])
            ? auto_check_conditions($item, $record)
            : auto_eval_condition($item, $record);

        if ($type === 'OR' && $result) {
            return true;
        }
        if ($type === 'AND' && !$result) {
            return false;
        }
    }

    // AND: all passed. OR: none matched.
    return $type === 'AND';
}

function auto_eval_condition(array $rule, array $record): bool
{
    $field = (string) ($rule['field'] ?? '');
    if ($field === '') {
        return true;
    }

    $op     = (string) ($rule['operator'] ?? '=');
    $value  = (string) ($rule['value'] ?? '');
    $recVal = array_key_exists($field, $record) ? (string) ($record[$field] ?? '') : null;

    return match ($op) {
        '='            => $recVal !== null && $recVal === $value,
        '!='           => $recVal !== null && $recVal !== $value,
        'contains'     => $recVal !== null && str_contains($recVal, $value),
        'not_contains' => $recVal !== null && !str_contains($recVal, $value),
        'is_empty'     => $recVal === null || $recVal === '',
        'is_not_empty' => $recVal !== null && $recVal !== '',
        default        => false,
    };
}

// Returns error string or null on success.
function auto_execute_action(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId,
    string $ruleId = ''
): ?string {
    return match ($action['type'] ?? '') {
        'update'        => auto_action_update($conn, $tableSchema, $table, $recordId, $record, $action, $userId),
        'notify'        => auto_action_notify($conn, $recordId, $ruleId, $record, $action, $userId),
        'create_record' => auto_action_create_record($conn, $tableSchema, $record, $action, $userId),
        default         => null,
    };
}

function auto_action_update(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId
): ?string {
    $set        = $action['set'] ?? [];
    $setClauses = [];
    $params     = [];
    $i          = 1;

    foreach ($set as $col => $val) {
        if ((string) $col === '') {
            continue;
        }
        $val          = auto_resolve_template((string) $val, $record, $userId);
        $setClauses[] = pg_ident((string) $col) . ' = $' . $i;
        $params[]     = $val;
        $i++;
    }

    if (empty($setClauses)) {
        return null;
    }

    $params[] = $recordId;
    $sql      = sprintf(
        'UPDATE %s.%s SET %s WHERE id = $%d',
        pg_ident($tableSchema),
        pg_ident($table),
        implode(', ', $setClauses),
        $i
    );

    $res = @pg_query_params($conn, $sql, $params);
    return $res === false ? ('update failed: ' . pg_last_error($conn)) : null;
}

function auto_action_notify(
    PgSql\Connection $conn,
    int $recordId,
    string $ruleId,
    array $record,
    array $action,
    int $userId
): ?string {
    // Support user_ids (array, new) with fallback to legacy user_id (single string).
    if (!empty($action['user_ids']) && is_array($action['user_ids'])) {
        $rawIds = $action['user_ids'];
    } elseif (isset($action['user_id']) && (string) $action['user_id'] !== '') {
        $rawIds = [$action['user_id']];
    } else {
        $rawIds = ['{{ current_user.id }}'];
    }

    $title = trim(auto_resolve_template((string) ($action['title'] ?? ''), $record, $userId));
    $link  = trim(auto_resolve_template((string) ($action['link'] ?? ''), $record, $userId));

    if ($title === '') {
        return 'notify: title is required';
    }
    if (empty($rawIds)) {
        return 'notify: no recipients';
    }

    $tNotif = sys_table('users_notifications');
    // source_table = rule scoped key so the UNIQUE constraint allows one notification
    // per (rule, record, user, day) without blocking other rules or records.
    $src = 'auto_' . $ruleId;
    if (strlen($src) > 100) {
        $src = substr($src, 0, 100);
    }

    $sql = "INSERT INTO $tNotif (user_id, title, link, source_table, source_id, notify_date)
            VALUES (\$1, \$2, \$3, \$4, \$5, CURRENT_DATE)
            ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING";

    $errs = [];
    foreach ($rawIds as $rawId) {
        $resolved = auto_resolve_template((string) $rawId, $record, $userId);
        $targetId = (int) $resolved;
        if ($targetId <= 0) {
            $errs[] = "notify: invalid user_id ({$rawId})";
            continue;
        }
        $res = @pg_query_params($conn, $sql, [
            $targetId,
            $title,
            $link !== '' ? $link : null,
            $src,
            $recordId,
        ]);
        if ($res === false) {
            $errs[] = 'notify failed: ' . pg_last_error($conn);
        }
    }

    return $errs !== [] ? implode('; ', $errs) : null;
}

function auto_action_create_record(
    PgSql\Connection $conn,
    string $tableSchema,
    array $record,
    array $action,
    int $userId
): ?string {
    $targetTable = trim((string) ($action['target_table'] ?? ''));
    if ($targetTable === '') {
        return 'create_record: target_table is required';
    }

    $set    = $action['set'] ?? [];
    $cols   = [];
    $params = [];

    foreach ($set as $col => $val) {
        if ((string) $col === '') {
            continue;
        }
        $cols[]   = pg_ident((string) $col);
        $params[] = auto_resolve_template((string) $val, $record, $userId);
    }

    if (empty($cols)) {
        return 'create_record: no fields set';
    }

    $placeholders = implode(', ', array_map(static fn(int $n): string => '$' . $n, range(1, count($params))));
    $sql = sprintf(
        'INSERT INTO %s.%s (%s) VALUES (%s)',
        pg_ident($tableSchema),
        pg_ident($targetTable),
        implode(', ', $cols),
        $placeholders
    );

    $res = @pg_query_params($conn, $sql, $params);
    return $res === false ? ('create_record failed: ' . pg_last_error($conn)) : null;
}

function auto_log_run(
    PgSql\Connection $conn,
    string $ruleId,
    string $ruleName,
    string $tableName,
    int $recordId,
    string $event,
    string $status,
    ?string $errorMsg
): void {
    $tRuns = sys_table('automation_runs');
    @pg_query_params(
        $conn,
        "INSERT INTO $tRuns (rule_id, rule_name, table_name, record_id, event, status, error_msg)
         VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)",
        [$ruleId, $ruleName, $tableName, $recordId, $event, $status, $errorMsg]
    );
}

function auto_resolve_template(string $value, array $record, int $userId): string
{
    $value = preg_replace('/\{\{\s*current_user\.id\s*\}\}/', (string) $userId, $value) ?? $value;
    $value = preg_replace_callback(
        '/\{\{\s*record\.(\w+)\s*\}\}/',
        static function (array $m) use ($record): string {
            return (string) ($record[$m[1]] ?? '');
        },
        $value
    ) ?? $value;
    return $value;
}
