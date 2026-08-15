<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../src/Security/UserRole.php';

use App\Exception\ForbiddenException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Service\RecordOwnershipService;
use App\Service\RecordSnapshotService;

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

    return array_keys(array_filter($cols, fn($column) => ($column['type'] ?? '') !== 'virtual'));
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

function map_fk_display(array $schema, array $tableCfg, array $rows, \PgSql\Connection $conn): array
{
    if (empty($rows) || !isset($tableCfg['foreign_keys'])) {
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

        $refDispRaw = $fkCfg['display_column'] ?? [$refColId];
        if (!is_array($refDispRaw)) {
            $refDispRaw = [$refDispRaw];
        }
        if (empty($refDispRaw)) {
            $refDispRaw = [$refColId];
        }

        $escapedDispCols = array_map(pg_ident(...), $refDispRaw);
        if (count($escapedDispCols) > 1) {
            $dispSql = "CONCAT_WS(' - ', " . implode(', ', $escapedDispCols) . ")";
        } else {
            $dispSql = $escapedDispCols[0];
        }

        $escapedVals = array_map(fn($v) => pg_escape_literal($conn, (string)$v), $fkValues);
        $inClause = implode(', ', $escapedVals);

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
            while ($row = pg_fetch_assoc($res)) {
                $map[$row['id']] = $row['disp'];
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

function log_user_action(
    \PgSql\Connection $conn,
    int $userId,
    string $action,
    ?string $targetTable = null,
    ?int $recordId = null
): ?int {
    $sql = 'INSERT INTO ' . sys_table('users_log')
         . ' (user_id, action, target_table, record_id) VALUES ($1, $2, $3, $4) RETURNING id';
    $res = @pg_query_params($conn, $sql, [$userId, $action, $targetTable, $recordId]);
    if ($res && ($row = pg_fetch_row($res))) {
        return (int) $row[0];
    }
    return null;
}

function fetch_record_json(\PgSql\Connection $conn, string $schemaName, string $table, int $recordId): ?string
{
    return (new RecordSnapshotService($conn))->recordJson($schemaName, $table, $recordId);
}

function get_record_owner_id(\PgSql\Connection $conn, string $table, int $recordId): ?int
{
    return (new RecordOwnershipService($conn))->ownerId($table, $recordId);
}

function can_access_record(
    \PgSql\Connection $conn,
    array $tableCfg,
    string $table,
    int $recordId,
    int $userId,
    string $role = ''
): bool {
    return (new RecordOwnershipService($conn))->canAccess($tableCfg, $table, $recordId, $userId, $role);
}

function check_record_ownership(
    \PgSql\Connection $conn,
    array $tableCfg,
    string $table,
    int $recordId,
    int $userId,
    string $message = 'Forbidden'
): void {
    if (!can_access_record($conn, $tableCfg, $table, $recordId, $userId)) {
        throw new ForbiddenException($message, ['error' => $message]);
    }
}

function owner_restriction_sql(string $idExpr, int $tableParam, int $ownerParam): string
{
    return RecordOwnershipService::restrictionSql($idExpr, $tableParam, $ownerParam);
}

function filter_visible_ids(
    \PgSql\Connection $conn,
    array $tableCfg,
    string $table,
    array $ids,
    int $userId
): array {
    return (new RecordOwnershipService($conn))->filterVisibleIds($tableCfg, $table, $ids, $userId);
}

function set_record_owner(\PgSql\Connection $conn, string $table, int $recordId, int $ownerId, int $changedBy): void
{
    (new RecordOwnershipService($conn))->assign($table, $recordId, $ownerId, $changedBy);
}

function snapshot_record(\PgSql\Connection $conn, string $schemaName, string $table, int $recordId, int $logId): void
{
    (new RecordSnapshotService($conn))->capture($schemaName, $table, $recordId, $logId);
}

function record_label_columns(array $tableCfg, array $configured): array
{
    $cols = $tableCfg['columns'] ?? [];

    if (!empty($configured)) {
        $valid = array_values(array_filter(
            $configured,
            fn($column) => is_string($column) && isset($cols[$column]) && ($cols[$column]['type'] ?? '') !== 'virtual'
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

function record_label_sql(array $tableCfg, array $configured): string
{
    $cols = array_map('pg_ident', record_label_columns($tableCfg, $configured));

    return count($cols) > 1
        ? "CONCAT_WS(' - ', " . implode(', ', $cols) . ')'
        : $cols[0];
}

function jsonError(string $msg, int $code = 400): never
{
    throw HttpException::fromStatus($code, $msg, ['success' => false, 'error' => $msg]);
}

function jsonSuccess(array $data = [], int $code = 200): never
{
    $data['success'] = true;
    throw ResponseException::json($data, $code);
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        jsonError('Unauthorised', 401);
    }
}

function requireWrite(array $roles = ['editor', 'admin']): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        jsonError('Forbidden: read-only access', 403);
    }
}

function require_not_demo(string $message = 'Action disabled in Demo Mode.', int $code = 403): void
{
    if (!DEMO_MODE) {
        return;
    }
    throw HttpException::fromStatus($code, $message, ['status' => 'error', 'error' => $message]);
}

function validate_column_regexp(array $colCfg, mixed $val): ?string
{
    $pattern = $colCfg['validation_regexp'] ?? '';
    if (!is_string($pattern) || $pattern === '' || $val === null || $val === '') {
        return null;
    }

    $result = @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', (string) $val);
    if ($result === false) {
        error_log('[validate_column_regexp] invalid validation_regexp in schema.json: ' . $pattern);
        return null;
    }
    return $result === 1 ? null : (string) ($colCfg['validation_message'] ?? 'Invalid format');
}

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

const USER_ACCESS_SCOPES = [
    'tables' => [
        'config' => 'schema',    'path' => 'tables',    'id' => null,
        'label'  => 'display_name', 'noun' => 'table',    'plural' => 'tables',
        'title'  => 'Tables',    'empty' => 'No tables in the schema configuration.',
    ],
    'views' => [
        'config' => 'views',     'path' => 'views',     'id' => null,
        'label'  => 'display_name', 'noun' => 'view',     'plural' => 'views',
        'title'  => 'Views',     'empty' => 'No views configured.',
    ],
    'prints' => [
        'config' => 'print',     'path' => 'prints',    'id' => null,
        'label'  => 'display_name', 'noun' => 'printout', 'plural' => 'printouts',
        'title'  => 'Printouts', 'empty' => 'No printouts configured.',
    ],
    'boards' => [
        'config' => 'board',     'path' => 'boards',    'id' => 'id',
        'label'  => 'menu_name', 'noun' => 'board',     'plural' => 'boards',
        'title'  => 'Boards',    'empty' => 'No boards configured.',
    ],
    'workflows' => [
        'config' => 'workflows', 'path' => 'workflows', 'id' => 'id',
        'label'  => 'title',     'noun' => 'workflow',  'plural' => 'workflows',
        'title'  => 'Workflows', 'empty' => 'No workflows configured.',
    ],
];

function access_scope(string $scope): array
{
    if (!isset(USER_ACCESS_SCOPES[$scope])) {
        throw new InvalidArgumentException("Unknown access scope: {$scope}");
    }
    return USER_ACCESS_SCOPES[$scope];
}

function access_scope_items(string $scope, bool $includeHidden = false): array
{
    require_once __DIR__ . '/config_store.php';
    $def   = access_scope($scope);
    $items = (config_get($def['config']) ?? [])[$def['path']] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $out = [];
    foreach ($items as $key => $cfg) {
        if (!is_array($cfg) || (!$includeHidden && !empty($cfg['hidden']))) {
            continue;
        }

        $name = $def['id'] === null ? (string) $key : (string) ($cfg[$def['id']] ?? '');
        if ($name === '') {
            continue;
        }

        $label      = $cfg[$def['label']] ?? null;
        $out[$name] = is_string($label) && $label !== '' ? $label : $name;
    }
    return $out;
}

function user_allowed_items(string $scope, ?int $userId = null): ?array
{
    static $cache = [];

    $isSelf = ($userId === null);

    $userId ??= (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    if ($isSelf && ($_SESSION['role'] ?? '') === 'admin') {
        return null;
    }
    access_scope($scope);

    if (!array_key_exists($userId, $cache)) {
        require_once __DIR__ . '/config_store.php';
        $cfg   = config_get('user_table_access');
        $entry = $cfg['users'][(string) $userId] ?? null;

        if (is_array($entry) && array_is_list($entry)) {
            $entry = ['tables' => $entry];
        }
        if (!is_array($entry)) {
            $entry = [];
        }

        $resolved = [];
        foreach (array_keys(USER_ACCESS_SCOPES) as $s) {
            $list = is_array($entry[$s] ?? null) ? $entry[$s] : [];
            $list = array_values(array_unique(array_filter($list, 'is_string')));
            $resolved[$s] = ($list === [] ? null : $list);
        }
        if ($resolved['tables'] !== null) {
            $resolved['tables'] = with_hidden_subtables($resolved['tables']);
        }
        $cache[$userId] = $resolved;
    }

    return $cache[$userId][$scope];
}

function with_hidden_subtables(array $tables): array
{
    require_once __DIR__ . '/config_store.php';
    $schema = (config_get('schema') ?? [])['tables'] ?? [];

    $out   = array_fill_keys($tables, true);
    $queue = $tables;

    while ($queue !== []) {
        $parent = (string) array_shift($queue);
        foreach ($schema[$parent]['subtables'] ?? [] as $sub) {
            $child = is_array($sub) ? (string) ($sub['table'] ?? '') : '';
            if ($child === '' || isset($out[$child]) || empty($schema[$child]['hidden'])) {
                continue;
            }
            $out[$child] = true;
            $queue[]     = $child;
        }
    }

    return array_map('strval', array_keys($out));
}

const OS_REQUEST_SCOPE_PARAMS = [
    'table'         => 'tables',
    'related_table' => 'tables',
    'view'          => 'views',
    'print'         => 'prints',
    'board'         => 'boards',
    'workflow'      => 'workflows',
    'workflow_id'   => 'workflows',
];

function os_request_scope_violation(array $body = [], array $overrides = []): ?array
{
    foreach (OS_REQUEST_SCOPE_PARAMS as $param => $scope) {
        if (($overrides[$param] ?? true) === false) {
            continue;
        }

        if ($param === 'table' && defined('OS_TABLE_ACCESS_DELEGATED')) {
            continue;
        }

        foreach ([$_GET[$param] ?? null, $_POST[$param] ?? null, $body[$param] ?? null] as $value) {
            foreach (is_array($value) ? $value : [$value] as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                if (!isset(access_scope_items($scope, true)[$name])) {
                    continue;
                }
                if (!user_can_access($scope, $name)) {
                    return [$scope, $name];
                }
            }
        }
    }
    return null;
}

function os_gate_request_body(): array
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        return [];
    }
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function os_gate_request_scopes(array $overrides = []): void
{
    $violation = os_request_scope_violation(os_gate_request_body(), $overrides);
    if ($violation !== null) {
        require_access($violation[0], $violation[1]);
    }
}

function user_allowed_tables(?int $userId = null): ?array
{
    return user_allowed_items('tables', $userId);
}

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

function require_access(string $scope, string $name, ?int $userId = null): void
{
    if (!user_can_access($scope, $name, $userId)) {
        jsonError('Forbidden: no access to this ' . access_scope($scope)['noun'] . '.', 403);
    }
}

function require_table_access(string $table, ?int $userId = null): void
{
    require_access('tables', $table, $userId);
}

function require_view_access(string $view, ?int $userId = null): void
{
    require_access('views', $view, $userId);
}

function require_print_access(string $print, ?int $userId = null): void
{
    require_access('prints', $print, $userId);
}

function filter_by_user_access(string $scope, array $items, ?int $userId = null): array
{
    $allowed = user_allowed_items($scope, $userId);
    if ($allowed === null) {
        return $items;
    }

    $idField = access_scope($scope)['id'];
    if ($idField === null) {
        return array_intersect_key($items, array_flip($allowed));
    }
    return array_values(array_filter(
        $items,
        static fn($item): bool => is_array($item)
            && in_array((string) ($item[$idField] ?? ''), $allowed, true)
    ));
}

function filter_tables_for_user(array $tables, ?int $userId = null): array
{
    return filter_by_user_access('tables', $tables, $userId);
}

function merge_user_access_selection(array $submitted, array $stored): array
{
    $out = [];
    foreach (array_keys(USER_ACCESS_SCOPES) as $scope) {
        $list = is_array($submitted[$scope] ?? null)
            ? $submitted[$scope]
            : ($stored[$scope] ?? []);
        $out[$scope] = is_array($list)
            ? array_values(array_unique(array_filter($list, 'is_string')))
            : [];
    }
    return $out;
}

function workflow_tables_in_scope(mixed $wf, ?int $userId = null): bool
{
    if (!is_array($wf)) {
        return false;
    }
    foreach ((array) ($wf['steps'] ?? []) as $step) {
        $stepTable = is_array($step) ? (string) ($step['table'] ?? '') : '';
        if ($stepTable !== '' && !user_can_access_table($stepTable, $userId)) {
            return false;
        }
    }
    return true;
}
