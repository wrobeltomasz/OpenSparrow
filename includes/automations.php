<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';

function auto_load_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    require_once __DIR__ . '/config_store.php';
    $data = config_get('automations');
    return $cache = (is_array($data) ? ($data['automations'] ?? []) : []);
}

function auto_capture_old_record(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    string $event = 'update'
): ?array {
    $hasRule = array_any(
        auto_load_config(),
        static fn(array $r): bool => !empty($r['enabled'])
            && ($r['trigger_table'] ?? '') === $table
            && ($r['trigger_event'] ?? '') === $event
    );
    if (!$hasRule) {
        return null;
    }

    $sql = sprintf('SELECT * FROM %s.%s WHERE id = $1', pg_ident($tableSchema), pg_ident($table));
    $res = @pg_query_params($conn, $sql, [$recordId]);
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res);
    pg_free_result($res);
    return $row ?: null;
}

function evaluate_automation_rules(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    string $event,
    int $userId,
    ?array $oldRecord = null
): void {
    $all   = auto_load_config();
    $rules = array_filter($all, static function (array $r) use ($table, $event): bool {
        return !empty($r['enabled'])
            && ($r['trigger_table'] ?? '') === $table
            && ($r['trigger_event'] ?? '') === $event;
    });

    if (empty($rules)) {
        return;
    }

    if ($event === 'delete') {
        if ($oldRecord === null) {
            return;
        }
        $record = $oldRecord;
    } else {
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
    }

    foreach ($rules as $rule) {
        $conditions = $rule['conditions'] ?? ['type' => 'AND', 'rules' => []];
        $actions    = $rule['actions'] ?? [];
        $ruleId     = (string) ($rule['id'] ?? '');
        $ruleName   = (string) ($rule['name'] ?? '');

        if (!auto_check_conditions($conditions, $record, $oldRecord)) {
            auto_log_run($conn, $ruleId, $ruleName, $table, $recordId, $event, 'skipped', null);
            continue;
        }

        $errors = [];
        foreach ($actions as $action) {
            $err = auto_execute_action($conn, $tableSchema, $table, $recordId, $record, $action, $userId, $ruleId, $event, $oldRecord);
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

function auto_check_conditions(array $group, array $record, ?array $oldRecord = null): bool
{
    $type  = strtoupper((string) ($group['type'] ?? 'AND'));
    $items = $group['rules'] ?? [];

    if (empty($items)) {
        return true;
    }

    foreach ($items as $item) {
        $result = isset($item['type'], $item['rules'])
            ? auto_check_conditions($item, $record, $oldRecord)
            : auto_eval_condition($item, $record, $oldRecord);

        if ($type === 'OR' && $result) {
            return true;
        }
        if ($type === 'AND' && !$result) {
            return false;
        }
    }

    return $type === 'AND';
}

function auto_compare_values(?string $recVal, string $value): ?int
{
    if ($recVal === null || $recVal === '' || $value === '') {
        return null;
    }
    if (is_numeric($recVal) && is_numeric($value)) {
        return (float) $recVal <=> (float) $value;
    }
    $a = strtotime($recVal);
    $b = strtotime($value);
    if ($a !== false && $b !== false) {
        return $a <=> $b;
    }
    return strcmp($recVal, $value) <=> 0;
}

function auto_eval_condition(array $rule, array $record, ?array $oldRecord = null): bool
{
    $field = (string) ($rule['field'] ?? '');
    if ($field === '') {
        return true;
    }

    $op     = (string) ($rule['operator'] ?? '=');
    $value  = (string) ($rule['value'] ?? '');

    $value  = preg_replace('/\{\{\s*today\s*\}\}/', date('Y-m-d'), $value) ?? $value;
    $recVal = array_key_exists($field, $record) ? (string) ($record[$field] ?? '') : null;

    $oldVal = ($oldRecord !== null && array_key_exists($field, $oldRecord))
        ? (string) ($oldRecord[$field] ?? '')
        : null;

    return match ($op) {
        '='            => $recVal !== null && $recVal === $value,
        '!='           => $recVal !== null && $recVal !== $value,
        'contains'     => $recVal !== null && str_contains($recVal, $value),
        'not_contains' => $recVal !== null && !str_contains($recVal, $value),
        'is_empty'     => $recVal === null || $recVal === '',
        'is_not_empty' => $recVal !== null && $recVal !== '',
        '>'            => auto_compare_values($recVal, $value) === 1,
        '<'            => auto_compare_values($recVal, $value) === -1,
        '>='           => in_array(auto_compare_values($recVal, $value), [0, 1], true),
        '<='           => in_array(auto_compare_values($recVal, $value), [0, -1], true),
        'changed'      => $recVal !== $oldVal,
        'not_changed'  => $recVal === $oldVal,
        'changed_from' => $oldVal !== null && $oldVal === $value && $recVal !== $oldVal,
        'changed_to'   => $recVal !== null && $recVal === $value && $recVal !== $oldVal,
        default        => false,
    };
}

function auto_execute_action(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId,
    string $ruleId = '',
    string $event = '',
    ?array $oldRecord = null
): ?string {
    if ($event === 'delete' && ($action['type'] ?? '') === 'update') {
        return 'update: not available on delete events';
    }

    return match ($action['type'] ?? '') {
        'update'        => auto_action_update($conn, $tableSchema, $table, $recordId, $record, $action, $userId, $oldRecord),
        'notify'        => auto_action_notify($conn, $recordId, $ruleId, $record, $action, $userId, $oldRecord),
        'create_record' => auto_action_create_record($conn, $record, $action, $userId, $oldRecord),
        'webhook'       => auto_action_webhook($conn, $table, $recordId, $record, $action, $userId, $ruleId, $event, $oldRecord),
        'email'         => auto_action_email($conn, $table, $recordId, $record, $action, $userId, $ruleId, $oldRecord, $event),
        default         => null,
    };
}

function auto_table_cfg(string $table): array
{
    static $tables = null;
    if ($tables === null) {
        require_once __DIR__ . '/config_store.php';
        $data   = config_get('schema');
        $tables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
    }
    return is_array($tables[$table] ?? null) ? $tables[$table] : [];
}

function auto_owner_guard(
    PgSql\Connection $conn,
    string $table,
    int $recordId,
    int $userId,
    string $actionName,
    string $event = ''
): ?string {
    if ($event === 'delete') {
        return null;
    }
    if (!can_access_record($conn, auto_table_cfg($table), $table, $recordId, $userId)) {
        return $actionName . ': blocked — record is owned by another user';
    }
    return null;
}

function auto_action_update(
    PgSql\Connection $conn,
    string $tableSchema,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId,
    ?array $oldRecord = null
): ?string {
    $set        = $action['set'] ?? [];
    $setClauses = [];
    $params     = [];
    $i          = 1;

    foreach ($set as $col => $val) {
        if ((string) $col === '') {
            continue;
        }
        $val          = auto_resolve_template((string) $val, $record, $userId, $oldRecord);
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
    int $userId,
    ?array $oldRecord = null
): ?string {
    if (!empty($action['user_ids']) && is_array($action['user_ids'])) {
        $rawIds = $action['user_ids'];
    } elseif (isset($action['user_id']) && (string) $action['user_id'] !== '') {
        $rawIds = [$action['user_id']];
    } else {
        $rawIds = ['{{ current_user.id }}'];
    }

    $title = trim(auto_resolve_template((string) ($action['title'] ?? ''), $record, $userId, $oldRecord));
    $link  = trim(auto_resolve_template((string) ($action['link'] ?? ''), $record, $userId, $oldRecord));

    if ($title === '') {
        return 'notify: title is required';
    }
    if (empty($rawIds)) {
        return 'notify: no recipients';
    }

    $tNotif = sys_table('users_notifications');

    $src = 'auto_' . $ruleId;
    if (strlen($src) > 100) {
        $src = substr($src, 0, 100);
    }

    $sql = "INSERT INTO $tNotif (user_id, title, link, source_table, source_id, notify_date)
            VALUES (\$1, \$2, \$3, \$4, \$5, CURRENT_DATE)
            ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING";

    $errs = [];
    foreach ($rawIds as $rawId) {
        $resolved = auto_resolve_template((string) $rawId, $record, $userId, $oldRecord);
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
    array $record,
    array $action,
    int $userId,
    ?array $oldRecord = null
): ?string {
    $targetTable = trim((string) ($action['target_table'] ?? ''));
    if ($targetTable === '') {
        return 'create_record: target_table is required';
    }

    $targetCfg = auto_table_cfg($targetTable);
    if ($targetCfg === []) {
        return 'create_record: unknown target_table ' . $targetTable;
    }
    $targetSchema = (string) ($targetCfg['schema'] ?? 'public');

    $set    = $action['set'] ?? [];
    $cols   = [];
    $params = [];

    foreach ($set as $col => $val) {
        if ((string) $col === '') {
            continue;
        }
        $cols[]   = pg_ident((string) $col);
        $params[] = auto_resolve_template((string) $val, $record, $userId, $oldRecord);
    }

    if (empty($cols)) {
        return 'create_record: no fields set';
    }

    $placeholders = implode(', ', array_map(static fn(int $n): string => '$' . $n, range(1, count($params))));
    $sql = sprintf(
        'INSERT INTO %s.%s (%s) VALUES (%s)',
        pg_ident($targetSchema),
        pg_ident($targetTable),
        implode(', ', $cols),
        $placeholders
    );

    $res = @pg_query_params($conn, $sql, $params);
    return $res === false ? ('create_record failed: ' . pg_last_error($conn)) : null;
}

function auto_webhook_secret(array $action): string
{
    $enc = (string) ($action['secret_enc'] ?? '');
    if ($enc !== '') {
        require_once __DIR__ . '/crypto.php';
        return (string) (secret_decrypt($enc) ?? '');
    }
    return (string) ($action['secret'] ?? '');
}

const AUTO_WEBHOOK_RESERVED_HEADERS = [
    'content-type',
    'content-length',
    'user-agent',
    'host',
    'x-sparrow-signature',
];

function auto_webhook_header_map(array $action): array
{
    require_once __DIR__ . '/crypto.php';
    $out = [];
    foreach ((array) ($action['headers_enc'] ?? []) as $name => $enc) {
        $plain = secret_decrypt((string) $enc);
        if ($plain !== null) {
            $out[(string) $name] = $plain;
        }
    }
    foreach ((array) ($action['headers'] ?? []) as $name => $val) {
        if (!array_key_exists((string) $name, $out)) {
            $out[(string) $name] = (string) $val;
        }
    }
    return $out;
}

function auto_webhook_headers(array $action, array $record, int $userId, ?array $oldRecord): array
{
    $out = [];
    foreach (auto_webhook_header_map($action) as $name => $tpl) {
        $name = trim((string) $name);

        if ($name === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            continue;
        }
        if (in_array(strtolower($name), AUTO_WEBHOOK_RESERVED_HEADERS, true)) {
            continue;
        }
        $val = auto_resolve_template((string) $tpl, $record, $userId, $oldRecord);
        $val = trim((string) preg_replace('/[\r\n]+/', ' ', $val));
        if ($val === '') {
            continue;
        }
        $out[] = $name . ': ' . $val;
    }
    return $out;
}

function auto_webhook_is_transient(string $curlErr, int $httpCode): bool
{
    return $curlErr !== '' || $httpCode === 0 || $httpCode === 408 || $httpCode === 429 || $httpCode >= 500;
}

function auto_action_webhook(
    PgSql\Connection $conn,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId,
    string $ruleId,
    string $event,
    ?array $oldRecord = null
): ?string {
    $url = trim((string) ($action['url'] ?? ''));
    if ($url === '') {
        return 'webhook: url is required';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'webhook: only http/https URLs are allowed';
    }
    if (!function_exists('curl_init')) {
        return 'webhook: PHP curl extension is not available';
    }

    if (($guardErr = auto_owner_guard($conn, $table, $recordId, $userId, 'webhook', $event)) !== null) {
        return $guardErr;
    }

    $method  = strtoupper(trim((string) ($action['method'] ?? 'POST')));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $method = 'POST';
    }

    $mapping = is_array($action['payload'] ?? null) ? $action['payload'] : [];
    $data    = [];
    if ($mapping === []) {
        $data = $record;
    } else {
        foreach ($mapping as $key => $tpl) {
            if ((string) $key === '') {
                continue;
            }
            $data[(string) $key] = auto_resolve_template((string) $tpl, $record, $userId, $oldRecord);
        }
    }

    $envelope = [
        'rule_id'      => $ruleId,
        'event'        => $event,
        'table'        => $table,
        'record_id'    => $recordId,
        'triggered_by' => $userId,
        'data'         => $data,
    ];

    if ($oldRecord !== null && $event !== 'create') {
        $envelope['old_data'] = $oldRecord;
    }
    $payload = json_encode($envelope, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: OpenSparrow-Automation/' . (defined('OPENSPARROW_VERSION') ? OPENSPARROW_VERSION : ''),
    ];
    $headers = array_merge($headers, auto_webhook_headers($action, $record, $userId, $oldRecord));

    $secret = auto_webhook_secret($action);
    if ($secret !== '') {
        $headers[] = 'X-Sparrow-Signature: sha256=' . hash_hmac('sha256', (string) $payload, $secret);
    }

    $retries  = max(0, min(2, (int) ($action['retries'] ?? 0)));
    $attempt  = 0;
    $curlErr  = '';
    $httpCode = 0;

    while (true) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($curlErr === '' && $httpCode > 0 && $httpCode < 300) {
            break;
        }
        if ($attempt >= $retries || !auto_webhook_is_transient($curlErr, $httpCode)) {
            break;
        }

        usleep($attempt === 0 ? 500000 : 1500000);
        $attempt++;
    }

    log_user_action($conn, $userId, 'AUTO_WEBHOOK', $table, $recordId);

    $suffix = $attempt > 0 ? ' (after ' . ($attempt + 1) . ' attempts)' : '';
    if ($curlErr !== '') {
        return 'webhook failed: ' . $curlErr . $suffix;
    }
    if ($httpCode >= 300 || $httpCode === 0) {
        return 'webhook failed: endpoint returned HTTP ' . $httpCode . $suffix;
    }
    return null;
}

function auto_action_email(
    PgSql\Connection $conn,
    string $table,
    int $recordId,
    array $record,
    array $action,
    int $userId,
    string $ruleId,
    ?array $oldRecord = null,
    string $event = ''
): ?string {
    $rawRecipients = $action['recipients'] ?? [];
    if (is_string($rawRecipients)) {
        $rawRecipients = array_map('trim', explode(',', $rawRecipients));
    }
    $rawRecipients = array_values(array_filter($rawRecipients, static fn($r) => trim((string) $r) !== ''));
    if ($rawRecipients === []) {
        return 'email: no recipients';
    }

    $subject = trim(auto_resolve_template((string) ($action['subject'] ?? ''), $record, $userId, $oldRecord));
    $body    = auto_resolve_template((string) ($action['body'] ?? ''), $record, $userId, $oldRecord);
    if ($subject === '') {
        return 'email: subject is required';
    }

    if (($guardErr = auto_owner_guard($conn, $table, $recordId, $userId, 'email', $event)) !== null) {
        return $guardErr;
    }

    $tEmails = sys_table('automation_emails');
    $sql     = "INSERT INTO $tEmails (rule_id, recipient, subject, body, source_table, record_id, created_by)
                VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)";

    $errs   = [];
    $queued = 0;
    foreach ($rawRecipients as $rawRecipient) {
        $recipient = trim(auto_resolve_template((string) $rawRecipient, $record, $userId, $oldRecord));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $errs[] = "email: invalid recipient ({$rawRecipient})";
            continue;
        }
        $res = @pg_query_params($conn, $sql, [
            $ruleId,
            substr($recipient, 0, 255),
            substr($subject, 0, 255),
            $body,
            $table,
            $recordId,
            $userId,
        ]);
        if ($res === false) {
            $errs[] = 'email queue failed: ' . pg_last_error($conn);
        } else {
            $queued++;
        }
    }

    if ($queued > 0) {
        log_user_action($conn, $userId, 'AUTO_EMAIL', $table, $recordId);
    }

    return $errs !== [] ? implode('; ', $errs) : null;
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

function auto_resolve_template(string $value, array $record, int $userId, ?array $oldRecord = null): string
{
    $value = preg_replace('/\{\{\s*current_user\.id\s*\}\}/', (string) $userId, $value) ?? $value;
    $value = preg_replace('/\{\{\s*today\s*\}\}/', date('Y-m-d'), $value) ?? $value;
    $value = preg_replace_callback(
        '/\{\{\s*record\.(\w+)\s*\}\}/',
        static function (array $m) use ($record): string {
            return (string) ($record[$m[1]] ?? '');
        },
        $value
    ) ?? $value;

    $value = preg_replace_callback(
        '/\{\{\s*old_record\.(\w+)\s*\}\}/',
        static function (array $m) use ($oldRecord): string {
            return (string) ($oldRecord[$m[1]] ?? '');
        },
        $value
    ) ?? $value;
    return $value;
}
