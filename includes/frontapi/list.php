<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

function frontapi_list(FrontApiContext $context): never
{
    $conn   = $context->conn;
    $schema = $context->schema;

    $table = $_GET['table'] ?? '';

    try {
        $tableConfig = safe_table($schema, $table);
    } catch (\RuntimeException $exception) {
        throw new BadRequestException('Unknown table');
    }

    if (!defined('OS_TABLE_ACCESS_DELEGATED')) {
        require_table_access($table);
    }
    $idColumn = id_column();
    $schemaName = $tableConfig['schema'] ?? 'public';
    $columns = column_list($tableConfig);
    $selectColumns = array_values(array_unique(array_merge([$idColumn], $columns)));

    if (defined('OS_FK_LABEL_COLUMNS')) {
        $keep = array_merge([$idColumn], (array) OS_FK_LABEL_COLUMNS);
        $selectColumns = array_values(array_intersect($selectColumns, $keep));
    }
    $selectSql = implode(', ', array_map(fn($column) => pg_ident($column), $selectColumns));
    $allowedFilterColumns = array_merge([$idColumn], array_keys($tableConfig['columns'] ?? []));

    if (defined('OS_FK_LABEL_COLUMNS')) {
        $allowedFilterColumns = array_values(array_intersect($allowedFilterColumns, $selectColumns));
    }

    $filterColumn  = $_GET['filter_col'] ?? '';
    $filterValue  = $_GET['filter_val'] ?? '';
    $filterFrom = $_GET['filter_from'] ?? '';
    $filterTo   = $_GET['filter_to'] ?? '';
    $whereSql = '';
    $parameters = [];
    $rangeClauses = [];
    if ($filterColumn !== '' && ($filterValue !== '' || $filterFrom !== '' || $filterTo !== '')) {
        if (in_array($filterColumn, $allowedFilterColumns, true)) {
            if ($filterFrom !== '' || $filterTo !== '') {
                if ($filterFrom !== '') {
                    $rangeClauses[] = sprintf('%s >= $%d', pg_ident($filterColumn), count($parameters) + 1);
                    $parameters[] = $filterFrom;
                }
                if ($filterTo !== '') {
                    $rangeClauses[] = sprintf('%s < $%d', pg_ident($filterColumn), count($parameters) + 1);
                    $parameters[] = $filterTo;
                }
            } else {
                $rangeClauses[] = sprintf('%s = $%d', pg_ident($filterColumn), count($parameters) + 1);
                $parameters[] = $filterValue;
            }
        }
    }

    $columnFilters = $_GET['column_filters'] ?? '';
    if (is_string($columnFilters) && $columnFilters !== '') {
        $decodedFilters = json_decode($columnFilters, true);
        if (is_array($decodedFilters)) {
            foreach ($decodedFilters as $filterName => $filterPayload) {
                if (!in_array((string) $filterName, $allowedFilterColumns, true) || !is_array($filterPayload)) {
                    continue;
                }
                $filterSql = build_column_filter_sql((string) $filterName, $filterPayload, $parameters);
                if ($filterSql !== '') {
                    $rangeClauses[] = $filterSql;
                }
            }
        }
    }

    if ($rangeClauses !== []) {
        $whereSql = ' WHERE ' . implode(' AND ', $rangeClauses);
    }

    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $likeValue  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $parameterNumber = count($parameters) + 1;
        $searchClauses = array_map(
            fn($column) => sprintf('%s::text ILIKE $%d', pg_ident($column), $parameterNumber),
            $selectColumns
        );
        $whereSql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . '(' . implode(' OR ', $searchClauses) . ')';
        $parameters[]  = $likeValue;
    }

    if (!empty($tableConfig['owner_restricted'])) {
        $ownerSql = owner_restriction_sql(
            '_t.' . pg_ident($idColumn),
            count($parameters) + 1,
            count($parameters) + 2
        );
        $parameters[] = $table;
        $parameters[] = $context->userId;
        $whereSql .= ($whereSql === '' ? ' WHERE TRUE' : '') . $ownerSql;
    }

    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $orderClauses = [];
    $requestedOrder = $_GET['order'] ?? '';
    if (is_string($requestedOrder) && $requestedOrder !== '') {
        $orderRules = explode(',', $requestedOrder);
        foreach (array_slice($orderRules, 0, 3) as $orderRule) {
            [$orderColumn, $orderDir] = array_pad(explode(':', trim($orderRule), 2), 2, 'asc');
            $orderDir = strtolower($orderDir) === 'desc' ? 'DESC' : 'ASC';
            if (in_array($orderColumn, $allowedFilterColumns, true)) {
                $orderClauses[] = pg_ident($orderColumn) . ' ' . $orderDir;
            }
        }
    }

    if ($orderClauses === []) {
        $defaultSort  = $tableConfig['default_sort'] ?? [];
        if (is_array($defaultSort)) {
            foreach ($defaultSort as $rule) {
                $columnName = $rule['column'] ?? '';
                $directory = strtoupper($rule['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                if ($columnName !== '' && (isset($tableConfig['columns'][$columnName]) || $columnName === $idColumn)) {
                    $orderClauses[] = pg_ident($columnName) . ' ' . $directory;
                }
            }
        }
    }
    if (empty($orderClauses)) {
        $orderClauses[] = pg_ident($idColumn) . ' DESC';
    }

    $initialLimit = (int)($tableConfig['initial_limit'] ?? 0);
    $rowCap       = $initialLimit > 0 ? $initialLimit : MAX_LIST_ROWS;

    $requestedLimit = (int)($_GET['limit'] ?? 0);
    if ($requestedLimit > 0) {
        $rowCap = min($rowCap, max(1, $requestedLimit));
    }

    $includeTotal = (int)($_GET['include_total'] ?? 1) === 1;
    $totalSql = $includeTotal ? ', COUNT(1) OVER() AS __spw_total' : '';

    $sql = sprintf(
        'SELECT %s%s FROM %s.%s AS _t%s ORDER BY %s LIMIT %d OFFSET %d',
        $selectSql,
        $totalSql,
        pg_ident($schemaName),
        pg_ident($table),
        $whereSql,
        implode(', ', $orderClauses),
        $rowCap,
        $offset
    );
    $result = @pg_query_params($conn, $sql, $parameters);
    if (!$result) {
        error_log('[api][list] ' . pg_last_error($conn));
        throw new ServerErrorException('Database error');
    }

    $rows = [];
    $dbTotal = 0;
    while ($row = pg_fetch_assoc($result)) {
        if ($dbTotal === 0 && isset($row['__spw_total'])) {
            $dbTotal = (int)$row['__spw_total'];
        }
        unset($row['__spw_total']);
        $rows[] = $row;
    }
    pg_free_result($result);
    $rows = map_fk_display($schema, $tableConfig, $rows, $conn);
    $rowCount = count($rows);
    echo json_encode([
        'columns'   => $selectColumns,
        'rows'      => $rows,
        'truncated' => $rowCount === $rowCap,
        'total'     => $dbTotal,
        'table'     => [
            'name'         => $table,
            'display_name' => to_display_name($tableConfig),
        ],
    ]);
    throw ResponseException::sent();
}

function build_column_filter_sql(string $columnName, array $payload, array &$parameters): string
{
    $clauses = [];

    if (array_key_exists('val', $payload) && $payload['val'] !== '' && $payload['val'] !== null) {
        $clauses[] = sprintf('%s = $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = (string) $payload['val'];
    }
    if (!empty($payload['bool'])) {
        $clauses[] = sprintf('%s = $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = $payload['bool'] === 'false' ? 'FALSE' : 'TRUE';
    }
    if (isset($payload['from']) && $payload['from'] !== '') {
        $clauses[] = sprintf('%s >= $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = (string) $payload['from'];
    }
    if (isset($payload['to']) && $payload['to'] !== '') {
        $clauses[] = sprintf('%s < $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = (string) $payload['to'];
    }
    if (isset($payload['min']) && $payload['min'] !== '') {
        $clauses[] = sprintf('%s >= $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = (string) $payload['min'];
    }
    if (isset($payload['max']) && $payload['max'] !== '') {
        $clauses[] = sprintf('%s <= $%d', pg_ident($columnName), count($parameters) + 1);
        $parameters[] = (string) $payload['max'];
    }

    if ($clauses === []) {
        return '';
    }
    return '(' . implode(' AND ', $clauses) . ')';
}

function frontapi_subtable_counts(FrontApiContext $context): never
{
    $conn   = $context->conn;
    $schema = $context->schema;

    $table = $_GET['table'] ?? '';
    try {
        $tableConfig = safe_table($schema, $table);
    } catch (\RuntimeException $exception) {
        throw new BadRequestException('Unknown table');
    }
    require_table_access($table);
    $subtables = $tableConfig['subtables'] ?? [];

    if (empty($subtables)) {
        throw ResponseException::encoded(['success' => true, 'counts' => (object)[]]);
    }

    $rawIds = $_GET['ids'] ?? '';
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $rawIds)),
        fn($id) => $id > 0
    )));

    if (empty($ids)) {
        throw ResponseException::encoded(['success' => true, 'counts' => (object)[]]);
    }

    $ids = filter_visible_ids($conn, $tableConfig, $table, $ids, $context->userId);
    if (empty($ids)) {
        throw ResponseException::encoded(['success' => true, 'counts' => (object)[]]);
    }

    $idColumn  = id_column();
    $counts = array_fill_keys(array_map('strval', $ids), 0);

    foreach ($subtables as $subtableDefinition) {
        $subtableName = $subtableDefinition['table'] ?? '';
        $fkColumn    = $subtableDefinition['foreign_key'] ?? '';
        if ($subtableName === '' || $fkColumn === '') {
            continue;
        }
        if (!isset($schema['tables'][$subtableName])) {
            continue;
        }

        if (!user_can_access_table($subtableName)) {
            continue;
        }
        $subtableConfig  = $schema['tables'][$subtableName];
        $allowed = array_merge([$idColumn], array_keys($subtableConfig['columns'] ?? []));
        if (!in_array($fkColumn, $allowed, true)) {
            continue;
        }
        $subtableSchema    = $subtableConfig['schema'] ?? 'public';
        $placeholders = implode(',', array_map(
            fn($placeholderIndex) => '$' . ($placeholderIndex + 1),
            range(0, count($ids) - 1)
        ));
        $sql = sprintf(
            'SELECT %s AS fk_val, COUNT(*) AS cnt FROM %s.%s WHERE %s IN (%s) GROUP BY %s',
            pg_ident($fkColumn),
            pg_ident($subtableSchema),
            pg_ident($subtableName),
            pg_ident($fkColumn),
            $placeholders,
            pg_ident($fkColumn)
        );
        $result = @pg_query_params($conn, $sql, $ids);
        if (!$result) {
            continue;
        }
        while ($row = pg_fetch_assoc($result)) {
            $key = (string)$row['fk_val'];
            if (isset($counts[$key])) {
                $counts[$key] += (int)$row['cnt'];
            }
        }
        pg_free_result($result);
    }

    $nonZero = array_filter($counts, fn($count) => $count > 0);
    throw ResponseException::encoded(['success' => true, 'counts' => $nonZero ?: (object)[]]);
}
