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
        static fn(array $rule): bool => !empty($rule['enabled'])
            && ($rule['trigger_table'] ?? '') === $table
            && ($rule['trigger_event'] ?? '') === $event
    );
    if (!$hasRule) {
        return null;
    }

    $sql = sprintf('SELECT * FROM %s.%s WHERE id = $1', pg_ident($tableSchema), pg_ident($table));
    $queryResult = @pg_query_params($conn, $sql, [$recordId]);
    if (!$queryResult) {
        return null;
    }
    $row = pg_fetch_assoc($queryResult);
    pg_free_result($queryResult);
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
    $rules = array_filter($all, static function (array $rule) use ($table, $event): bool {
        return !empty($rule['enabled'])
            && ($rule['trigger_table'] ?? '') === $table
            && ($rule['trigger_event'] ?? '') === $event;
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
        $recordResult = @pg_query_params($conn, $sql, [$recordId]);
        if (!$recordResult) {
            return;
        }
        $record = pg_fetch_assoc($recordResult);
        pg_free_result($recordResult);
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
            $error = auto_execute_action(
                $conn,
                $tableSchema,
                $table,
                $recordId,
                $record,
                $action,
                $userId,
                $ruleId,
                $event,
                $oldRecord
            );
            if ($error !== null) {
                $errors[] = $error;
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

function auto_compare_values(?string $recordValue, string $value): ?int
{
    if ($recordValue === null || $recordValue === '' || $value === '') {
        return null;
    }
    if (is_numeric($recordValue) && is_numeric($value)) {
        return (float) $recordValue <=> (float) $value;
    }
    $recordTimestamp = strtotime($recordValue);
    $valueTimestamp = strtotime($value);
    if ($recordTimestamp !== false && $valueTimestamp !== false) {
        return $recordTimestamp <=> $valueTimestamp;
    }
    return strcmp($recordValue, $value) <=> 0;
}

function auto_eval_condition(array $rule, array $record, ?array $oldRecord = null): bool
{
    $field = (string) ($rule['field'] ?? '');
    if ($field === '') {
        return true;
    }

    $operator     = (string) ($rule['operator'] ?? '=');
    $value  = (string) ($rule['value'] ?? '');

    $value  = preg_replace('/\{\{\s*today\s*\}\}/', date('Y-m-d'), $value) ?? $value;
    $recordValue = array_key_exists($field, $record) ? (string) ($record[$field] ?? '') : null;

    $oldValue = ($oldRecord !== null && array_key_exists($field, $oldRecord))
        ? (string) ($oldRecord[$field] ?? '')
        : null;

    return match ($operator) {
        '='            => $recordValue !== null && $recordValue === $value,
        '!='           => $recordValue !== null && $recordValue !== $value,
        'contains'     => $recordValue !== null && str_contains($recordValue, $value),
        'not_contains' => $recordValue !== null && !str_contains($recordValue, $value),
        'is_empty'     => $recordValue === null || $recordValue === '',
        'is_not_empty' => $recordValue !== null && $recordValue !== '',
        '>'            => auto_compare_values($recordValue, $value) === 1,
        '<'            => auto_compare_values($recordValue, $value) === -1,
        '>='           => in_array(auto_compare_values($recordValue, $value), [0, 1], true),
        '<='           => in_array(auto_compare_values($recordValue, $value), [0, -1], true),
        'changed'      => $recordValue !== $oldValue,
        'not_changed'  => $recordValue === $oldValue,
        'changed_from' => $oldValue !== null && $oldValue === $value && $recordValue !== $oldValue,
        'changed_to'   => $recordValue !== null && $recordValue === $value && $recordValue !== $oldValue,
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
        'update' => auto_action_update(
            $conn,
            $tableSchema,
            $table,
            $recordId,
            $record,
            $action,
            $userId,
            $oldRecord
        ),
        'notify' => auto_action_notify($conn, $recordId, $ruleId, $record, $action, $userId, $oldRecord),
        'create_record' => auto_action_create_record($conn, $record, $action, $userId, $oldRecord),
        'webhook' => auto_action_webhook(
            $conn,
            $table,
            $recordId,
            $record,
            $action,
            $userId,
            $ruleId,
            $event,
            $oldRecord
        ),
        'email' => auto_action_email(
            $conn,
            $table,
            $recordId,
            $record,
            $action,
            $userId,
            $ruleId,
            $oldRecord,
            $event
        ),
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
    $placeholderIndex          = 1;

    foreach ($set as $column => $columnValue) {
        if ((string) $column === '') {
            continue;
        }
        $columnValue          = auto_resolve_template((string) $columnValue, $record, $userId, $oldRecord);
        $setClauses[] = pg_ident((string) $column) . ' = $' . $placeholderIndex;
        $params[]     = $columnValue;
        $placeholderIndex++;
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
        $placeholderIndex
    );

    $queryResult = @pg_query_params($conn, $sql, $params);
    return $queryResult === false ? ('update failed: ' . pg_last_error($conn)) : null;
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

    $notificationsTable = sys_table('users_notifications');

    $source = 'auto_' . $ruleId;
    if (strlen($source) > 100) {
        $source = substr($source, 0, 100);
    }

    $sql = "INSERT INTO $notificationsTable (user_id, title, link, source_table, source_id, notify_date)
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
        $queryResult = @pg_query_params($conn, $sql, [
            $targetId,
            $title,
            $link !== '' ? $link : null,
            $source,
            $recordId,
        ]);
        if ($queryResult === false) {
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
    $columns   = [];
    $params = [];

    foreach ($set as $column => $columnValue) {
        if ((string) $column === '') {
            continue;
        }
        $columns[]   = pg_ident((string) $column);
        $params[] = auto_resolve_template((string) $columnValue, $record, $userId, $oldRecord);
    }

    if (empty($columns)) {
        return 'create_record: no fields set';
    }

    $placeholders = implode(', ', array_map(
        static fn(int $placeholderIndex): string => '$' . $placeholderIndex,
        range(1, count($params))
    ));
    $sql = sprintf(
        'INSERT INTO %s.%s (%s) VALUES (%s)',
        pg_ident($targetSchema),
        pg_ident($targetTable),
        implode(', ', $columns),
        $placeholders
    );

    $queryResult = @pg_query_params($conn, $sql, $params);
    return $queryResult === false ? ('create_record failed: ' . pg_last_error($conn)) : null;
}

function auto_webhook_secret(array $action): string
{
    $encoding = (string) ($action['secret_enc'] ?? '');
    if ($encoding !== '') {
        require_once __DIR__ . '/crypto.php';
        return (string) (secret_decrypt($encoding) ?? '');
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
    $output = [];
    foreach ((array) ($action['headers_enc'] ?? []) as $name => $encoding) {
        $plain = secret_decrypt((string) $encoding);
        if ($plain !== null) {
            $output[(string) $name] = $plain;
        }
    }
    foreach ((array) ($action['headers'] ?? []) as $name => $headerValue) {
        if (!array_key_exists((string) $name, $output)) {
            $output[(string) $name] = (string) $headerValue;
        }
    }
    return $output;
}

function auto_webhook_headers(array $action, array $record, int $userId, ?array $oldRecord): array
{
    $output = [];
    foreach (auto_webhook_header_map($action) as $name => $template) {
        $name = trim((string) $name);

        if ($name === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            continue;
        }
        if (in_array(strtolower($name), AUTO_WEBHOOK_RESERVED_HEADERS, true)) {
            continue;
        }
        $headerValue = auto_resolve_template((string) $template, $record, $userId, $oldRecord);
        $headerValue = trim((string) preg_replace('/[\r\n]+/', ' ', $headerValue));
        if ($headerValue === '') {
            continue;
        }
        $output[] = $name . ': ' . $headerValue;
    }
    return $output;
}

function auto_webhook_is_transient(string $curlError, int $httpCode): bool
{
    return $curlError !== '' || $httpCode === 0 || $httpCode === 408 || $httpCode === 429 || $httpCode >= 500;
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

    if (($guardError = auto_owner_guard($conn, $table, $recordId, $userId, 'webhook', $event)) !== null) {
        return $guardError;
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
        foreach ($mapping as $key => $template) {
            if ((string) $key === '') {
                continue;
            }
            $data[(string) $key] = auto_resolve_template((string) $template, $record, $userId, $oldRecord);
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
    $curlError  = '';
    $httpCode = 0;

    while (true) {
        $curlHandle = curl_init($url);
        curl_setopt_array($curlHandle, [
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
        curl_exec($curlHandle);
        $curlError  = curl_error($curlHandle);
        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        if ($curlError === '' && $httpCode > 0 && $httpCode < 300) {
            break;
        }
        if ($attempt >= $retries || !auto_webhook_is_transient($curlError, $httpCode)) {
            break;
        }

        usleep($attempt === 0 ? 500000 : 1500000);
        $attempt++;
    }

    log_user_action($conn, $userId, 'AUTO_WEBHOOK', $table, $recordId);

    $suffix = $attempt > 0 ? ' (after ' . ($attempt + 1) . ' attempts)' : '';
    if ($curlError !== '') {
        return 'webhook failed: ' . $curlError . $suffix;
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
    $rawRecipients = array_values(array_filter($rawRecipients, static fn($rule) => trim((string) $rule) !== ''));
    if ($rawRecipients === []) {
        return 'email: no recipients';
    }

    $subject = trim(auto_resolve_template((string) ($action['subject'] ?? ''), $record, $userId, $oldRecord));
    $body    = auto_resolve_template((string) ($action['body'] ?? ''), $record, $userId, $oldRecord);
    if ($subject === '') {
        return 'email: subject is required';
    }

    if (($guardError = auto_owner_guard($conn, $table, $recordId, $userId, 'email', $event)) !== null) {
        return $guardError;
    }

    $automationEmailsTable = sys_table('automation_emails');
    $sql     = "INSERT INTO $automationEmailsTable (rule_id, recipient, subject,"
        . " body, source_table, record_id, created_by)
                VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)";

    $errs   = [];
    $queued = 0;
    foreach ($rawRecipients as $rawRecipient) {
        $recipient = trim(auto_resolve_template((string) $rawRecipient, $record, $userId, $oldRecord));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $errs[] = "email: invalid recipient ({$rawRecipient})";
            continue;
        }
        $queryResult = @pg_query_params($conn, $sql, [
            $ruleId,
            substr($recipient, 0, 255),
            substr($subject, 0, 255),
            $body,
            $table,
            $recordId,
            $userId,
        ]);
        if ($queryResult === false) {
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
    ?string $errorMessage
): void {
    $automationRunsTable = sys_table('automation_runs');
    @pg_query_params(
        $conn,
        "INSERT INTO $automationRunsTable (rule_id, rule_name, table_name, record_id, event, status, error_msg)
         VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)",
        [$ruleId, $ruleName, $tableName, $recordId, $event, $status, $errorMessage]
    );
}

function auto_resolve_template(string $value, array $record, int $userId, ?array $oldRecord = null): string
{
    $value = preg_replace('/\{\{\s*current_user\.id\s*\}\}/', (string) $userId, $value) ?? $value;
    $value = preg_replace('/\{\{\s*today\s*\}\}/', date('Y-m-d'), $value) ?? $value;
    $value = preg_replace_callback(
        '/\{\{\s*record\.(\w+)\s*\}\}/',
        static function (array $matches) use ($record): string {
            return (string) ($record[$matches[1]] ?? '');
        },
        $value
    ) ?? $value;

    $value = preg_replace_callback(
        '/\{\{\s*old_record\.(\w+)\s*\}\}/',
        static function (array $matches) use ($oldRecord): string {
            return (string) ($oldRecord[$matches[1]] ?? '');
        },
        $value
    ) ?? $value;
    return $value;
}
