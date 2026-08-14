<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/list.php — frontend API route module: the data grid's row listing
// (GET ?api=list) and its subtable badge counts (GET ?api=subtable_counts).
// Dispatched by public/api.php AFTER the auth gate, the admin/viewer role gates and
// the schema load.
//
// SECURITY: the list route is the one place in this API where the per-user table
// allow-list is deliberately NOT enforced by a plain require_table_access() call.
// public/api/fk.php delegates into it for a SCHEMA-supplied reference table so FK
// dropdowns keep resolving, and marks that delegation with two constants:
//
//   OS_TABLE_ACCESS_DELEGATED  the table name came from the config, not the client
//   OS_FK_LABEL_COLUMNS        narrow the projection to the key + label columns
//
// The narrowing is what makes the exemption defensible, and it has to cover the
// filter_col allow-list as well as the SELECT list: a filter discloses what it
// matched without ever being selected, and filter_from/filter_to would otherwise
// turn the exemption into a range probe over any column of a table the caller may
// not open. Pinned by tests/Security/AccessScopeEndpointGuardTest.

/**
 * One page of rows for the data grid, with filtering, search, sorting and the
 * row-level ownership rule applied in SQL.
 */
function frontapi_list(FrontApiContext $ctx): never
{
    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $table = $_GET['table'] ?? '';
    // A table name absent from the configured schema is bad client input, not a
    // server fault: without this the RuntimeException from safe_table() falls
    // through to the catch-all in the front controller and every typo, stale
    // bookmark or probe answers 500 and writes an error_log entry. Mirrors the
    // handling in api/mass_edit.php.
    try {
        $tableCfg = safe_table($schema, $table);
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }
    // api/fk.php delegates here with a schema-supplied reference table and defines
    // this constant to say so — that name never came from the client, so gating it
    // would break FK dropdowns inside tables the user is allowed to use.
    if (!defined('OS_TABLE_ACCESS_DELEGATED')) {
        require_table_access($table);
    }
    $idCol = id_column();
    $schemaName = $tableCfg['schema'] ?? 'public';
    $cols = column_list($tableCfg);
    $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));
    // The delegation above exempts the reference table from the per-user gate so
    // FK labels keep resolving. Without narrowing the projection that exemption
    // would hand back every configured column of a table the user may not open —
    // api/fk.php therefore names the columns a dropdown actually needs, and the
    // response carries nothing else. Intersected with the schema-derived list, so
    // the constant can never introduce a column name of its own.
    if (defined('OS_FK_LABEL_COLUMNS')) {
        $keep = array_merge([$idCol], (array) OS_FK_LABEL_COLUMNS);
        $selectCols = array_values(array_intersect($selectCols, $keep));
    }
    $selectSql = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));
    $filterCol  = $_GET['filter_col'] ?? '';
    $filterVal  = $_GET['filter_val'] ?? '';
    $filterFrom = $_GET['filter_from'] ?? '';
    $filterTo   = $_GET['filter_to'] ?? '';
    $whereSql = '';
    $params = [];
    if ($filterCol !== '' && ($filterVal !== '' || $filterFrom !== '' || $filterTo !== '')) {
        $allowedFilterCols = array_merge([$idCol], array_keys($tableCfg['columns'] ?? []));
        // The same narrowing the projection above got, and for a stronger reason: a
        // filter does not have to appear in the response to disclose what it matched.
        // Left at the full column list, the FK exemption would let a restricted user
        // filter an out-of-scope reference table on any column — and filter_from /
        // filter_to make that a range probe, so a value they may not read is binary-
        // searched out of which rows come back, keyed to the label that does show.
        // Deriving the filter and the projection from one list is what makes the
        // exemption "labels only" rather than "labels, plus anything you can ask
        // yes/no questions about". The search clause below already spans $selectCols.
        if (defined('OS_FK_LABEL_COLUMNS')) {
            $allowedFilterCols = array_values(array_intersect($allowedFilterCols, $selectCols));
        }
        if (in_array($filterCol, $allowedFilterCols, true)) {
            if ($filterFrom !== '' || $filterTo !== '') {
                // Half-open range filter [from, to) — used by time-series drill-down
                // so a chart bucket maps to every row within that period.
                $rangeClauses = [];
                if ($filterFrom !== '') {
                    $rangeClauses[] = sprintf('%s >= $%d', pg_ident($filterCol), count($params) + 1);
                    $params[] = $filterFrom;
                }
                if ($filterTo !== '') {
                    $rangeClauses[] = sprintf('%s < $%d', pg_ident($filterCol), count($params) + 1);
                    $params[] = $filterTo;
                }
                $whereSql = ' WHERE ' . implode(' AND ', $rangeClauses);
            } else {
                $whereSql = sprintf(' WHERE %s = $1', pg_ident($filterCol));
                $params[] = $filterVal;
            }
        }
    }

    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $likeVal  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $paramNum = count($params) + 1;
        $searchClauses = array_map(
            fn($c) => sprintf('%s::text ILIKE $%d', pg_ident($c), $paramNum),
            $selectCols
        );
        $whereSql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . '(' . implode(' OR ', $searchClauses) . ')';
        $params[]  = $likeVal;
    }

    // Row-level ownership filter. Until now owner_restricted only gated writes and file
    // downloads, so the grid handed every row of a restricted table to any authenticated
    // user. Applying it here makes reads follow the same policy as can_access_record():
    // the caller sees rows they own plus unowned rows. No admin exemption is needed —
    // admin accounts are rejected from this whole API in the front controller, so only
    // editors and viewers reach here. Filtering in SQL rather than post-fetch keeps
    // COUNT(1) OVER() and the LIMIT/OFFSET pagination consistent with what is visible.
    //
    // The id expression MUST be table-qualified: spw_record_owners has its own "id"
    // column, so a bare `id` inside the NOT EXISTS subquery binds to ro.id and the
    // filter silently degrades to a no-op.
    if (!empty($tableCfg['owner_restricted'])) {
        $ownerSql = owner_restriction_sql(
            '_t.' . pg_ident($idCol),
            count($params) + 1,
            count($params) + 2
        );
        $params[] = $table;
        $params[] = $ctx->userId;
        $whereSql .= ($whereSql === '' ? ' WHERE TRUE' : '') . $ownerSql;
    }

    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $defaultSort  = $tableCfg['default_sort'] ?? [];
    $orderClauses = [];
    if (is_array($defaultSort)) {
        foreach ($defaultSort as $rule) {
            $col = $rule['column'] ?? '';
            $dir = strtoupper($rule['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            if ($col !== '' && (isset($tableCfg['columns'][$col]) || $col === $idCol)) {
                $orderClauses[] = pg_ident($col) . ' ' . $dir;
            }
        }
    }
    if (empty($orderClauses)) {
        $orderClauses[] = pg_ident($idCol) . ' DESC';
    }

    $initialLimit = (int)($tableCfg['initial_limit'] ?? 0);
    $rowCap       = $initialLimit > 0 ? $initialLimit : MAX_LIST_ROWS;

    $sql = sprintf(
        'SELECT %s, COUNT(1) OVER() AS __spw_total FROM %s.%s AS _t%s ORDER BY %s LIMIT %d OFFSET %d',
        $selectSql,
        pg_ident($schemaName),
        pg_ident($table),
        $whereSql,
        implode(', ', $orderClauses),
        $rowCap,
        $offset
    );
    $res = @pg_query_params($conn, $sql, $params);
    if (!$res) {
        error_log('[api][list] ' . pg_last_error($conn));
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    $rows = [];
    $dbTotal = 0;
    while ($r = pg_fetch_assoc($res)) {
        if ($dbTotal === 0) {
            $dbTotal = (int)($r['__spw_total'] ?? 0);
        }
        unset($r['__spw_total']);
        $rows[] = $r;
    }
    pg_free_result($res);
    $rows = map_fk_display($schema, $tableCfg, $rows);
    $rowCount = count($rows);
    echo json_encode([
        'columns'   => $selectCols,
        'rows'      => $rows,
        'truncated' => $rowCount === $rowCap,
        'total'     => $dbTotal,
        'table'     => [
            'name'         => $table,
            'display_name' => to_display_name($tableCfg),
        ],
    ]);
    exit;
}

/**
 * Total linked records per row across all configured subtables — the grid's badges.
 */
function frontapi_subtable_counts(FrontApiContext $ctx): never
{
    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $table = $_GET['table'] ?? '';
    try {
        $tableCfg = safe_table($schema, $table);
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }
    require_table_access($table);
    $subtables = $tableCfg['subtables'] ?? [];

    if (empty($subtables)) {
        exit(json_encode(['success' => true, 'counts' => (object)[]]));
    }

    $rawIds = $_GET['ids'] ?? '';
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $rawIds)),
        fn($id) => $id > 0
    )));

    if (empty($ids)) {
        exit(json_encode(['success' => true, 'counts' => (object)[]]));
    }

    // Filter the client-supplied parent ids once, before the per-subtable loop, so a
    // restricted row yields no badge instead of a count of its children. Only the
    // parent's ownership is applied — see the note on api=m2m_rows for why the child
    // table's own restriction is deliberately not layered on top.
    $ids = filter_visible_ids($conn, $tableCfg, $table, $ids, $ctx->userId);
    if (empty($ids)) {
        exit(json_encode(['success' => true, 'counts' => (object)[]]));
    }

    $idCol  = id_column();
    $counts = array_fill_keys(array_map('strval', $ids), 0);

    foreach ($subtables as $sub) {
        $subTable = $sub['table'] ?? '';
        $fkCol    = $sub['foreign_key'] ?? '';
        if ($subTable === '' || $fkCol === '') {
            continue;
        }
        if (!isset($schema['tables'][$subTable])) {
            continue;
        }
        // Mirrors the subtable filtering in edit.php: a count is still a fact
        // about a table the user may not open, so out-of-scope children are
        // skipped rather than counted.
        if (!user_can_access_table($subTable)) {
            continue;
        }
        $subCfg  = $schema['tables'][$subTable];
        $allowed = array_merge([$idCol], array_keys($subCfg['columns'] ?? []));
        if (!in_array($fkCol, $allowed, true)) {
            continue;
        }
        $subSchema    = $subCfg['schema'] ?? 'public';
        $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), range(0, count($ids) - 1)));
        $sql = sprintf(
            'SELECT %s AS fk_val, COUNT(*) AS cnt FROM %s.%s WHERE %s IN (%s) GROUP BY %s',
            pg_ident($fkCol),
            pg_ident($subSchema),
            pg_ident($subTable),
            pg_ident($fkCol),
            $placeholders,
            pg_ident($fkCol)
        );
        $res = @pg_query_params($conn, $sql, $ids);
        if (!$res) {
            continue;
        }
        while ($r = pg_fetch_assoc($res)) {
            $key = (string)$r['fk_val'];
            if (isset($counts[$key])) {
                $counts[$key] += (int)$r['cnt'];
            }
        }
        pg_free_result($res);
    }

    $nonZero = array_filter($counts, fn($v) => $v > 0);
    exit(json_encode(['success' => true, 'counts' => $nonZero ?: (object)[]]));
}
