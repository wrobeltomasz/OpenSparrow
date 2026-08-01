<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.
//
// automations.php — Rule engine for automation triggers (update, notify, create_record, webhook, email)
// Loads rules from the "automations" config; evaluates conditions (AND/OR groups, operators: =, !=, contains, is_empty,
// comparisons >, <, >=, <= — numeric, then date, then string fallback — and change detection: changed, not_changed,
// changed_from, changed_to — compare old vs new value on update events)
// Old state comes from auto_capture_old_record() called by writers BEFORE the UPDATE
// Templates support {{record.field}}, {{old_record.field}}, {{current_user.id}}, {{today}} (condition values too)
// Webhooks can carry an X-Sparrow-Signature header (HMAC SHA-256 of the JSON body) when the action has a secret
// (stored encrypted as secret_enc), plus free-form custom headers for the receiver's own auth (e.g. n8n Header Auth)
// Delete rules run against the caller's pre-delete snapshot, since the row is gone by then
// Executes actions: update fields (with template placeholders {{record.field}}, {{current_user.id}}), create notifications (with daily de-duplication), insert related records,
// send outbound HTTP webhooks (JSON payload with mapped record fields), or queue emails to spw_automation_emails (delivered by cron/cron_notifications.php)
// Webhook and email respect record ownership (owner_restricted tables) and write to the audit trail via log_user_action()
// Logs each run to spw_automation_runs; called from api.php after INSERT/PATCH/DELETE

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

/**
 * Fetch the pre-update state of a record so change-based conditions
 * (changed / changed_from / changed_to) and {{ old_record.field }} templates
 * can compare old vs new values. Callers invoke this BEFORE running the UPDATE
 * and pass the result to evaluate_automation_rules(). Returns null (skipping
 * the extra SELECT) when no enabled rule for $event targets the table.
 *
 * Delete writers call it with $event = 'delete': the row is gone by the time the
 * rules run, so this snapshot becomes the record the delete rules see.
 */
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

/**
 * Evaluate automation rules for a given table event and execute matching actions.
 * Called from api.php after INSERT, PATCH and DELETE mutations. Delete events
 * require $oldRecord — see auto_capture_old_record().
 */
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
        // The row no longer exists — the caller's pre-delete snapshot IS the record.
        // Without one there is nothing to evaluate or ship to the receiver.
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

// Recursive: group has type (AND|OR) + rules array. Rules can be leaf conditions or nested groups.
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

    // AND: all passed. OR: none matched.
    return $type === 'AND';
}

// Ordered comparison for >, <, >=, <=. Numeric when both sides are numeric,
// date/time when both parse via strtotime, string comparison as a fallback.
// Returns null (condition fails) when either side is empty or missing.
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
    // {{ today }} in condition values enables date rules like "deadline <= {{ today }}".
    $value  = preg_replace('/\{\{\s*today\s*\}\}/', date('Y-m-d'), $value) ?? $value;
    $recVal = array_key_exists($field, $record) ? (string) ($record[$field] ?? '') : null;

    // Old value is null on create events (no previous state) and when the old
    // record could not be captured — then "changed" compares against nothing,
    // so it matches, while "changed_from" never can.
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

// Returns error string or null on success.
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
    // "update" edits the triggering row, which no longer exists on delete events.
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

// Cached table config from schema.json — needed for the owner_restricted flag.
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

// Outbound actions (webhook, email) ship record data outside the app, so they
// re-check the ownership policy: on owner_restricted tables the record must be
// unowned or owned by the triggering user. Returns error string or null when allowed.
// Delete events skip the check: the row and its spw_record_owners entry are already
// gone, so it could only ever fail — the writer enforced ownership before deleting.
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
    // Support user_ids (array, new) with fallback to legacy user_id (single string).
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

    // Resolve the target's own schema from the config rather than reusing the schema of
    // the table whose change fired the rule — they are not necessarily the same, and a
    // cross-schema rule would otherwise either error out or, worse, silently write into a
    // same-named table elsewhere. An unconfigured target is rejected outright.
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

// Resolve a webhook signing secret. New rules store it encrypted (secret_enc);
// rules saved before that carry a plaintext "secret" and keep working until re-saved.
function auto_webhook_secret(array $action): string
{
    $enc = (string) ($action['secret_enc'] ?? '');
    if ($enc !== '') {
        require_once __DIR__ . '/crypto.php';
        return (string) (secret_decrypt($enc) ?? '');
    }
    return (string) ($action['secret'] ?? '');
}

// Header names the transport owns — a rule must not be able to forge the signature,
// misdeclare the body, or rewrite the request target.
const AUTO_WEBHOOK_RESERVED_HEADERS = [
    'content-type',
    'content-length',
    'user-agent',
    'host',
    'x-sparrow-signature',
];

// Decrypt the action's custom header values. Header names stay in the clear — the
// editor and the save validator need them — but the values are credentials, so they
// live encrypted in headers_enc. A name mapped to an empty string means "declared,
// no value yet". Rules saved before this carry a plaintext "headers" map and keep
// working until re-saved.
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

// Build extra request headers from the action's header map (name => template).
// This is what lets a receiver such as n8n use a Header Auth credential.
// Values run through the template resolver; CR/LF is stripped to prevent header
// injection, and reserved names are dropped.
function auto_webhook_headers(array $action, array $record, int $userId, ?array $oldRecord): array
{
    $out = [];
    foreach (auto_webhook_header_map($action) as $name => $tpl) {
        $name = trim((string) $name);
        // RFC 7230 token characters only.
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

// A failure worth repeating: the receiver was unreachable, rate-limited or broken,
// as opposed to a 4xx that says the request itself is wrong and will never succeed.
function auto_webhook_is_transient(string $curlErr, int $httpCode): bool
{
    return $curlErr !== '' || $httpCode === 0 || $httpCode === 408 || $httpCode === 429 || $httpCode >= 500;
}

// Send an outbound HTTP request with a JSON payload built from the record.
// Config: url (http/https), method (POST|PUT|PATCH|DELETE), payload (map: json_key => template),
// headers_enc (map: name => encrypted template), secret_enc (HMAC signing key), retries (0-2).
// Empty payload map sends the full record under "data". Response codes >= 300 are errors.
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
    // Ship the pre-change state alongside the new one so the receiver can diff
    // without keeping its own copy. Absent on create (there is no previous state).
    if ($oldRecord !== null && $event !== 'create') {
        $envelope['old_data'] = $oldRecord;
    }
    $payload = json_encode($envelope, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: OpenSparrow-Automation/' . (defined('OPENSPARROW_VERSION') ? OPENSPARROW_VERSION : ''),
    ];
    $headers = array_merge($headers, auto_webhook_headers($action, $record, $userId, $oldRecord));
    // Optional signing secret (GitHub/Stripe webhook pattern): the receiver can
    // recompute HMAC SHA-256 over the raw body to verify origin and integrity.
    $secret = auto_webhook_secret($action);
    if ($secret !== '') {
        $headers[] = 'X-Sparrow-Signature: sha256=' . hash_hmac('sha256', (string) $payload, $secret);
    }

    // Retries are opt-in: each attempt blocks the user's request for up to the
    // full timeout, so the default stays 0 and the ceiling is deliberately low.
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
        // 500 ms, then 1500 ms.
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

// Queue email notifications to spw_automation_emails; cron/cron_notifications.php
// delivers them asynchronously. Config: recipients (array of addresses or templates,
// e.g. {{ record.email }}), subject (template, required), body (template).
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
    // Pre-update value; empty string on create events or when not captured.
    $value = preg_replace_callback(
        '/\{\{\s*old_record\.(\w+)\s*\}\}/',
        static function (array $m) use ($oldRecord): string {
            return (string) ($oldRecord[$m[1]] ?? '');
        },
        $value
    ) ?? $value;
    return $value;
}
