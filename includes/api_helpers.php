<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// api_helpers.php — Shared helper functions for API endpoints
// Provides safe table/column access, FK display mapping, boolean normalization, type min values, audit logging, ownership checks, and record snapshots
// All SQL identifiers are quoted with pg_ident(); values are escaped or parameterized; uses sys_table() for system tables
// Functions: safe_table, column_list, pg_ident, map_fk_display, log_user_action, get_record_owner_id, can_access_record, set_record_owner, snapshot_record, jsonError, jsonSuccess, requireLogin, requireWrite, require_not_demo, validatedTable

require_once __DIR__ . '/config.php';
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

        // A foreign key may point at a table that has since been dropped from the
        // schema config. That is an administrator's configuration error, not a bad
        // request, so it must not become a 500: this helper runs while mapping
        // result rows, after the caller has already validated the table it was
        // asked for, and an exception here would take down the grid, m2m rows and
        // subtable listings alike. Skip the dangling key and leave the raw value
        // in place — a column showing an id instead of a label is a far better
        // failure than three read paths returning "Internal server error" with no
        // hint as to which reference is broken.
        //
        // The name is read defensively: a malformed entry, or one missing the key
        // altogether, would raise a TypeError rather than the RuntimeException
        // caught below and so slip straight past this guard.
        $refName = is_array($fkCfg) ? (string) ($fkCfg['reference_table'] ?? '') : '';
        try {
            $refTable = safe_table($schema, $refName);
        } catch (\RuntimeException $e) {
            error_log(sprintf(
                '[map_fk_display] dangling foreign key %s -> %s: reference table not in schema config',
                (string) $fkCol,
                $refName === '' ? '(missing reference_table)' : $refName
            ));
            continue;
        }
        $refSchema = $refTable['schema'] ?? 'public';
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

// SQL predicate for bulk statements on owner-restricted tables: excludes rows whose
// current owner is another user (unowned rows pass, matching can_access_record()).
// $tableParam and $ownerParam are the 1-based pg placeholder numbers the caller binds
// the table name and user id to. Bulk counterpart of can_access_record() — keep the
// two policies in sync.
//
// $idExpr MUST be table-qualified ('_t.id', never a bare 'id'): the predicate is a
// correlated subquery over spw_record_owners, which has its own "id" column, so an
// unqualified reference resolves to the *inner* ro.id. That turns the condition into
// `ro.record_id = ro.id`, which is essentially never true, and the whole filter
// silently degrades to a no-op. Alias the outer table and qualify the reference.
function owner_restriction_sql(string $idExpr, int $tableParam, int $ownerParam): string
{
    if (!str_contains($idExpr, '.')) {
        throw new InvalidArgumentException(
            'owner_restriction_sql(): $idExpr must be table-qualified (e.g. "_t.id"), got "' . $idExpr . '".'
        );
    }
    $tOwners = sys_table('record_owners');
    return " AND NOT EXISTS (SELECT 1 FROM {$tOwners} ro"
        . " WHERE ro.table_name = \${$tableParam} AND ro.record_id = {$idExpr}"
        . " AND ro.is_current = true AND ro.owner_id != \${$ownerParam})";
}

// Narrow a client-supplied list of record ids down to the ones the user may see.
// Read-side counterpart of owner_restriction_sql() for the grid's side-channel
// endpoints (image thumbnails, subtable counts): those take ids as *input* instead of
// selecting rows themselves, so there is no outer query to hang a NOT EXISTS off — and
// without this an id the grid never returned can still be probed directly.
//
// Returns $ids unchanged (as ints) for tables that are not owner_restricted. Mirrors
// can_access_record(): unowned rows pass, rows owned by someone else are dropped.
//
// @param  array<int|string> $ids
// @return int[]
function filter_visible_ids(
    \PgSql\Connection $conn,
    array $tableCfg,
    string $table,
    array $ids,
    int $userId
): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($tableCfg['owner_restricted']) || $ids === []) {
        return $ids;
    }

    $tOwners = sys_table('record_owners');
    $sql = "SELECT ro.record_id FROM {$tOwners} ro"
         . ' WHERE ro.table_name = $1 AND ro.is_current = true'
         . ' AND ro.owner_id IS NOT NULL AND ro.owner_id != $2'
         . ' AND ro.record_id = ANY($3::int[])';

    $res = @pg_query_params($conn, $sql, [
        $table,
        $userId,
        '{' . implode(',', $ids) . '}',
    ]);
    if (!$res) {
        // Fail closed: a broken ownership lookup must not widen visibility.
        error_log('filter_visible_ids failed: ' . pg_last_error($conn));
        return [];
    }

    $blocked = [];
    while ($row = pg_fetch_assoc($res)) {
        $blocked[] = (int)$row['record_id'];
    }
    pg_free_result($res);

    return $blocked === [] ? $ids : array_values(array_diff($ids, $blocked));
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

// Record label column(s) for an arbitrary table, concatenated with CONCAT_WS() when
// there's more than one. Prefers the caller-supplied $configured columns (e.g. from
// the admin "User Records" > "Column Mapping" tab, config_get('user_records')); falls
// back to a best-effort guess (first text column shown in the grid, else any grid
// column, else the id). Shared by api/owners.php (My records), api/comments.php
// (My comments) and api/notes.php (record picker) — single source of truth for this
// heuristic.
function record_label_columns(array $tableCfg, array $configured): array
{
    $cols = $tableCfg['columns'] ?? [];

    if (!empty($configured)) {
        $valid = array_values(array_filter(
            $configured,
            fn($c) => is_string($c) && isset($cols[$c]) && ($cols[$c]['type'] ?? '') !== 'virtual'
        ));
        if (!empty($valid)) {
            return $valid;
        }
    }

    $firstGridCol = null;
    foreach ($cols as $colName => $colCfg) {
        if (empty($colCfg['show_in_grid'])) {
            continue;
        }
        if ($firstGridCol === null) {
            $firstGridCol = $colName;
        }
        if (($colCfg['type'] ?? '') === 'text') {
            return [$colName];
        }
    }
    return [$firstGridCol ?? 'id'];
}

// SELECT expression rendering a record's display label, built from the columns resolved
// by record_label_columns(). Identifiers are escaped with pg_ident(), so the result is
// safe to interpolate into a query. Callers still apply their own '#'.$id fallback when
// the resulting label comes back blank.
function record_label_sql(array $tableCfg, array $configured): string
{
    $cols = array_map('pg_ident', record_label_columns($tableCfg, $configured));

    return count($cols) > 1
        ? "CONCAT_WS(' - ', " . implode(', ', $cols) . ')'
        : $cols[0];
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

// Demo Mode guard for write actions. There is no central gate — every action
// that mutates data or configuration must call this itself. Emits the standard
// {status:error} envelope and exits when DEMO_MODE is on; pass $code 0 to leave
// the HTTP status untouched (legacy call sites that expect a 200 body).
// Single source of truth — endpoints must not define their own copies.
function require_not_demo(string $message = 'Action disabled in Demo Mode.', int $code = 403): void
{
    if (!DEMO_MODE) {
        return;
    }
    if ($code !== 0) {
        http_response_code($code);
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => $message]);
    exit;
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
// Also enforces the per-user table allow-list: a table that exists but is out of
// the user's scope is 403 (not 400) — the distinction matters, "unknown" and
// "not yours" are different answers and only the second one is an access denial.
function validatedTable(string $table, string $field = 'table'): string
{
    if ($table === '') {
        jsonError($field . ' is required.', 400);
    }
    require_once __DIR__ . '/config_store.php';
    $schema = config_get('schema');
    if (!isset($schema['tables'][$table])) {
        jsonError('Unknown table.', 400);
    }
    require_table_access($table);
    return $table;
}

// ── Per-user access to tables, views and printouts ───────────────────────────
// Admins may restrict a frontend user to a subset of the schema's tables, of the
// configured PostgreSQL views, and of the print templates. All three live in one
// 'user_table_access' spw_config document:
//
//   {"users": {"<user_id>": {"tables": [...], "views": [...], "prints": [...]}}}
//
// Views and printouts are named objects with no table binding of their own, which
// is why they are granted directly by name instead of being derived from a table.
//
// An ABSENT OR EMPTY list means UNRESTRICTED for that scope, not "no access". That
// is what keeps the feature backward compatible: every existing user keeps working
// until an admin deliberately ticks entries. "No access at all" is expressed by
// deactivating the account (is_active = false), not by an empty list. The three
// scopes are independent — restricting tables leaves views untouched.
//
// Never cache the resolved lists in $_SESSION: an admin revoking access must take
// effect on the user's next request, not on their next login. The static cache
// below is per-request only.

// Scopes a user's access can be narrowed to. Also the key set of the stored
// per-user document — anything else in it is ignored.
const USER_ACCESS_SCOPES = ['tables', 'views', 'prints'];

// The user's allow-list for one scope, or null when unrestricted (no entry, empty
// list, admin role, or no session at all — cron/CLI contexts must not be filtered).
// @return string[]|null
function user_allowed_items(string $scope, ?int $userId = null): ?array
{
    static $cache = [];

    $userId ??= (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    // Admins never reach the frontend (bootstrap redirects them to admin/), and the
    // admin panel must keep seeing everything in order to configure it.
    if (($_SESSION['role'] ?? '') === 'admin') {
        return null;
    }
    if (!in_array($scope, USER_ACCESS_SCOPES, true)) {
        throw new InvalidArgumentException("Unknown access scope: {$scope}");
    }

    if (!array_key_exists($userId, $cache)) {
        require_once __DIR__ . '/config_store.php';
        $cfg   = config_get('user_table_access');
        $entry = $cfg['users'][(string) $userId] ?? null;

        // A bare list is the pre-scopes shape and means tables only. Kept so a
        // document written before views/printouts existed keeps working unchanged.
        if (is_array($entry) && array_is_list($entry)) {
            $entry = ['tables' => $entry];
        }
        if (!is_array($entry)) {
            $entry = [];
        }

        $resolved = [];
        foreach (USER_ACCESS_SCOPES as $s) {
            $list = is_array($entry[$s] ?? null) ? $entry[$s] : [];
            $list = array_values(array_unique(array_filter($list, 'is_string')));
            $resolved[$s] = ($list === [] ? null : $list);
        }
        $cache[$userId] = $resolved;
    }

    return $cache[$userId][$scope];
}

// The user's allowed tables, or null when unrestricted. Thin wrapper kept because
// it reads better at the ~20 table call sites than the scoped form.
// @return string[]|null
function user_allowed_tables(?int $userId = null): ?array
{
    return user_allowed_items('tables', $userId);
}

// Whether the current (or given) user may touch this item at all.
function user_can_access(string $scope, string $name, ?int $userId = null): bool
{
    $allowed = user_allowed_items($scope, $userId);
    return $allowed === null || in_array($name, $allowed, true);
}

function user_can_access_table(string $table, ?int $userId = null): bool
{
    return user_can_access('tables', $table, $userId);
}

function user_can_access_view(string $view, ?int $userId = null): bool
{
    return user_can_access('views', $view, $userId);
}

function user_can_access_print(string $print, ?int $userId = null): bool
{
    return user_can_access('prints', $print, $userId);
}

// JSON gate for API endpoints. Call it only with a REQUEST-supplied name.
// Config-supplied names (FK reference_table, subtables, board/calendar bindings)
// must NOT go through here — gating those would break label lookups inside tables
// the user is legitimately allowed to see.
function require_access(string $scope, string $name, string $label, ?int $userId = null): void
{
    if (!user_can_access($scope, $name, $userId)) {
        jsonError('Forbidden: no access to this ' . $label . '.', 403);
    }
}

function require_table_access(string $table, ?int $userId = null): void
{
    require_access('tables', $table, 'table', $userId);
}

function require_view_access(string $view, ?int $userId = null): void
{
    require_access('views', $view, 'view', $userId);
}

function require_print_access(string $print, ?int $userId = null): void
{
    require_access('prints', $print, 'printout', $userId);
}

// Narrow a name-keyed map (schema['tables'], the views config, the prints config)
// down to what the user may see. Preserves key order.
function filter_by_user_access(string $scope, array $items, ?int $userId = null): array
{
    $allowed = user_allowed_items($scope, $userId);
    if ($allowed === null) {
        return $items;
    }
    return array_intersect_key($items, array_flip($allowed));
}

function filter_tables_for_user(array $tables, ?int $userId = null): array
{
    return filter_by_user_access('tables', $tables, $userId);
}
