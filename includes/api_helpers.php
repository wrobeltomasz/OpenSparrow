<?php

// api_helpers.php — Shared helper functions for API endpoints
// Provides safe table/column access, FK display mapping, boolean normalization, type min values, audit logging, ownership checks, and record snapshots
// All SQL identifiers are quoted with pg_ident(); values are escaped or parameterized; uses sys_table() for system tables
// Functions: safe_table, column_list, pg_ident, map_fk_display, log_user_action, get_record_owner_id, can_access_record, set_record_owner, snapshot_record, jsonError, jsonSuccess, requireLogin, validatedTable

require_once __DIR__ . '/../src/Security/UserRole.php';

use App\Security\UserRole;

function safe_table(array $schema, string $table): array
{
    if (!isset($schema['tables'][$table])) {
        throw new RuntimeException("Unknown table: {$table}");
    }
    return $schema['tables'][$table];
}

function column_list(array $tableCfg): array
{
    $cols = $tableCfg['columns'] ?? [];
    // Virtual columns don't exist in the database — exclude from SELECT
    return array_keys(array_filter($cols, fn($c) => ($c['type'] ?? '') !== 'virtual'));
}

function id_column(): string
{
    return 'id';
}

function pg_ident(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function to_display_name(array $tableCfg): string
{
    return $tableCfg['display_name'] ?? ($tableCfg['name'] ?? 'Unknown');
}

function map_fk_display(array $schema, array $tableCfg, array $rows, ?\PgSql\Connection $conn = null): array
{
    if (empty($rows) || !isset($tableCfg['foreign_keys'])) {
        return $rows;
    }

    $conn = $conn ?? $GLOBALS['conn'] ?? null;
    if ($conn === null) {
        return $rows;
    }
    foreach ($tableCfg['foreign_keys'] as $fkCol => $fkCfg) {
        $fkValues = [];
        foreach ($rows as $row) {
            if (isset($row[$fkCol]) && $row[$fkCol] !== '' && $row[$fkCol] !== null) {
                $fkValues[] = $row[$fkCol];
            }
        }
        $fkValues = array_unique($fkValues);
        if (empty($fkValues)) {
            continue;
        }

        $refTable = safe_table($schema, $fkCfg['reference_table']);
        $refSchema = $refTable['schema'] ?? 'public';
        $refName   = $fkCfg['reference_table'];
        $refColId  = $fkCfg['reference_column'] ?? 'id';

        // Handle array of display columns dynamically
        $refDispRaw = $fkCfg['display_column'] ?? [$refColId];
        if (!is_array($refDispRaw)) {
            $refDispRaw = [$refDispRaw];
        }
        if (empty($refDispRaw)) {
            $refDispRaw = [$refColId];
        }

        // Escape all columns and merge them using CONCAT_WS for PostgreSQL
        $escapedDispCols = array_map(pg_ident(...), $refDispRaw);
        if (count($escapedDispCols) > 1) {
            $dispSql = "CONCAT_WS(' - ', " . implode(', ', $escapedDispCols) . ")";
        } else {
            $dispSql = $escapedDispCols[0];
        }

        $escapedVals = array_map(fn($v) => pg_escape_literal($conn, (string)$v), $fkValues);
        $inClause = implode(', ', $escapedVals);

        // Build the safe SQL query with concatenated display columns
        $sql = sprintf(
            'SELECT %s AS id, %s AS disp FROM %s.%s WHERE %s IN (%s)',
            pg_ident($refColId),
            $dispSql,
            pg_ident($refSchema),
            pg_ident($refName),
            pg_ident($refColId),
            $inClause
        );

        $map = [];
        $res = pg_query($conn, $sql);
        if ($res) {
            while ($r = pg_fetch_assoc($res)) {
                $map[$r['id']] = $r['disp'];
            }
            pg_free_result($res);
        }

        foreach ($rows as &$row) {
            if (isset($row[$fkCol]) && array_key_exists($row[$fkCol], $map)) {
                $row[$fkCol . '__display'] = $map[$row[$fkCol]];
            }
        }
        unset($row);
    }

    return $rows;
}

function normalize_boolean(mixed $val): string
{
    $truthy = ['true', '1', 1, true, 't', 'T', 'TRUE'];
    return in_array($val, $truthy, true) ? 'TRUE' : 'FALSE';
}

function type_min_value(string $type): string|int
{
    $t = strtolower($type);
    if (str_contains($t, 'bool')) {
        return 'FALSE';
    }
    if (str_contains($t, 'int') || str_contains($t, 'numeric') || str_contains($t, 'float')) {
        return 0;
    }
    if (str_contains($t, 'date') || str_contains($t, 'time')) {
        return '1970-01-01';
    }

    return '';
}

// Log action to db — returns the new log row id so callers can attach snapshots.
function log_user_action(\PgSql\Connection $conn, int $userId, string $action, ?string $targetTable = null, ?int $recordId = null): ?int
{
    $sql = 'INSERT INTO ' . sys_table('users_log')
         . ' (user_id, action, target_table, record_id) VALUES ($1, $2, $3, $4) RETURNING id';
    $res = @pg_query_params($conn, $sql, [$userId, $action, $targetTable, $recordId]);
    if ($res && ($row = pg_fetch_row($res))) {
        return (int) $row[0];
    }
    return null;
}

// Fetch a single record as a JSON string using row_to_json().
// row_to_json requires SELECT * to capture all columns dynamically regardless of schema.
function fetch_record_json(\PgSql\Connection $conn, string $schemaName, string $table, int $recordId): ?string
{
    $safeRef = pg_ident($schemaName) . '.' . pg_ident($table);
    $res = pg_query_params(
        $conn,
        "SELECT row_to_json(t) FROM (SELECT * FROM {$safeRef} WHERE id = \$1) t",
        [$recordId]
    );
    if (!$res) {
        return null;
    }
    $row = pg_fetch_row($res);
    return ($row && $row[0] !== null) ? $row[0] : null;
}

// Returns the current owner_id for a record, or null if no ownership row exists.
function get_record_owner_id(\PgSql\Connection $conn, string $table, int $recordId): ?int
{
    $t   = sys_table('record_owners');
    $res = @pg_query_params(
        $conn,
        "SELECT owner_id FROM $t WHERE table_name = \$1 AND record_id = \$2 AND is_current = true",
        [$table, $recordId]
    );
    if (!$res || pg_num_rows($res) === 0) {
        return null;
    }
    $row = pg_fetch_assoc($res);
    return $row['owner_id'] !== null ? (int)$row['owner_id'] : null;
}

// Row-level access policy for a single record. Tables without the owner_restricted
// flag are open to any authenticated user. For restricted tables, access is granted
// only when the record is unowned or owned by the user; admins always pass. Mirrors
// the ownership policy enforced for PATCH and DELETE in api.php.
function can_access_record(\PgSql\Connection $conn, array $tableCfg, string $table, int $recordId, int $userId, string $role = ''): bool
{
    if (empty($tableCfg['owner_restricted'])) {
        return true;
    }
    if ($role === UserRole::Admin->value) {
        return true;
    }
    $ownerId = get_record_owner_id($conn, $table, $recordId);
    return $ownerId === null || $ownerId === $userId;
}

// Enforce owner-restricted access on a mutation: emit 403 + JSON error and exit when
// the record is owned by another user. No-op for open tables or records the user may
// touch. Wraps can_access_record() so the policy stays defined in one place.
function check_record_ownership(\PgSql\Connection $conn, array $tableCfg, string $table, int $recordId, int $userId, string $message = 'Forbidden'): void
{
    if (!can_access_record($conn, $tableCfg, $table, $recordId, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => $message]);
        exit;
    }
}

// Record ownership: mark previous current row inactive, insert new current row.
function set_record_owner(\PgSql\Connection $conn, string $table, int $recordId, int $ownerId, int $changedBy): void
{
    $t = sys_table('record_owners');
    @pg_query_params($conn, "UPDATE $t SET is_current = false WHERE table_name = \$1 AND record_id = \$2 AND is_current = true", [$table, $recordId]);
    @pg_query_params($conn, "INSERT INTO $t (table_name, record_id, owner_id, changed_by, is_current) VALUES (\$1, \$2, \$3, \$4, true)", [$table, $recordId, $ownerId, $changedBy]);
}

// Save a JSONB snapshot of the current record state linked to a log entry.
function snapshot_record(\PgSql\Connection $conn, string $schemaName, string $table, int $recordId, int $logId): void
{
    $json = fetch_record_json($conn, $schemaName, $table, $recordId);
    if ($json === null) {
        return;
    }
    @pg_query_params(
        $conn,
        'INSERT INTO ' . sys_table('record_snapshots')
            . ' (log_id, table_name, record_id, snapshot) VALUES ($1, $2, $3, $4)',
        [$logId, $table, $recordId, $json]
    );
}

// ---------------------------------------------------------------------------
// Shared JSON response + request-guard helpers for the api/ endpoints
// ---------------------------------------------------------------------------

// Emit a JSON error envelope and stop. Shape kept stable for the frontend.
function jsonError(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Emit a JSON success envelope (adds success=true) and stop.
function jsonSuccess(array $data = [], int $code = 200): never
{
    http_response_code($code);
    $data['success'] = true;
    echo json_encode($data);
    exit;
}

// Reject unauthenticated requests with 401.
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        jsonError('Unauthorised', 401);
    }
}

// Reject sessions whose role cannot write (403). Write access means editor or
// admin; viewers are read-only. Pass a narrower list to restrict an action
// further (e.g. ['editor']). Single source of truth for API write gates —
// endpoints must not define their own copies.
function requireWrite(array $roles = ['editor', 'admin']): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        jsonError('Forbidden: read-only access', 403);
    }
}

// Server-side mirror of the client data-pattern check (assets/js/grid_actions.js):
// unanchored match, skipped for NULL/empty values, fail-open on an invalid pattern
// (logged) so a broken regexp in schema.json cannot lock editing. Returns the
// column's validation_message (or a default) on mismatch, null when the value passes.
function validate_column_regexp(array $colCfg, mixed $val): ?string
{
    $pattern = $colCfg['validation_regexp'] ?? '';
    if (!is_string($pattern) || $pattern === '' || $val === null || $val === '') {
        return null;
    }
    // '~' delimiter: not a JS regex metacharacter, so schema patterns written for
    // the client never need it escaped — escaping any literal '~' here is enough.
    $result = @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', (string) $val);
    if ($result === false) {
        error_log('[validate_column_regexp] invalid validation_regexp in schema.json: ' . $pattern);
        return null;
    }
    return $result === 1 ? null : (string) ($colCfg['validation_message'] ?? 'Invalid format');
}

// Validate a table name against schema.json. $field names the offending input in
// the "is required" message so callers preserve their existing error wording.
function validatedTable(string $table, string $field = 'table'): string
{
    if ($table === '') {
        jsonError($field . ' is required.', 400);
    }
    $schema = json_decode((string)file_get_contents(__DIR__ . '/../config/schema.json'), true);
    if (!isset($schema['tables'][$table])) {
        jsonError('Unknown table.', 400);
    }
    return $table;
}
