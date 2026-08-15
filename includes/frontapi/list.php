<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_list(FrontApiContext $ctx): never
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

    if (!defined('OS_TABLE_ACCESS_DELEGATED')) {
        require_table_access($table);
    }
    $idCol = id_column();
    $schemaName = $tableCfg['schema'] ?? 'public';
    $cols = column_list($tableCfg);
    $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));

    if (defined('OS_FK_LABEL_COLUMNS')) {
        $keep = array_merge([$idCol], (array) OS_FK_LABEL_COLUMNS);
        $selectCols = array_values(array_intersect($selectCols, $keep));
    }
    $selectSql = implode(', ', array_map(fn($column) => pg_ident($column), $selectCols));
    $filterCol  = $_GET['filter_col'] ?? '';
    $filterVal  = $_GET['filter_val'] ?? '';
    $filterFrom = $_GET['filter_from'] ?? '';
    $filterTo   = $_GET['filter_to'] ?? '';
    $whereSql = '';
    $params = [];
    if ($filterCol !== '' && ($filterVal !== '' || $filterFrom !== '' || $filterTo !== '')) {
        $allowedFilterCols = array_merge([$idCol], array_keys($tableCfg['columns'] ?? []));

        if (defined('OS_FK_LABEL_COLUMNS')) {
            $allowedFilterCols = array_values(array_intersect($allowedFilterCols, $selectCols));
        }
        if (in_array($filterCol, $allowedFilterCols, true)) {
            if ($filterFrom !== '' || $filterTo !== '') {
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
            fn($column) => sprintf('%s::text ILIKE $%d', pg_ident($column), $paramNum),
            $selectCols
        );
        $whereSql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . '(' . implode(' OR ', $searchClauses) . ')';
        $params[]  = $likeVal;
    }

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
    while ($row = pg_fetch_assoc($res)) {
        if ($dbTotal === 0) {
            $dbTotal = (int)($row['__spw_total'] ?? 0);
        }
        unset($row['__spw_total']);
        $rows[] = $row;
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
        while ($row = pg_fetch_assoc($res)) {
            $key = (string)$row['fk_val'];
            if (isset($counts[$key])) {
                $counts[$key] += (int)$row['cnt'];
            }
        }
        pg_free_result($res);
    }

    $nonZero = array_filter($counts, fn($v) => $v > 0);
    exit(json_encode(['success' => true, 'counts' => $nonZero ?: (object)[]]));
}
