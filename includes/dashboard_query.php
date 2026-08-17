<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function dashboard_conditions_sql($conn, array $tableConfig, array $conditions): string
{
    $conditionParts = [];
    $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];
    foreach ($conditions as $condition) {
        $columnName = $condition['col'] ?? '';
        $operator  = $condition['op']  ?? '=';
        $value = (string)($condition['val'] ?? '');
        if (!isset($tableConfig['columns'][$columnName])) {
            continue;
        }
        if (!in_array($operator, $allowedOps, true)) {
            continue;
        }
        $columnSql = pg_ident($columnName);
        $logic = strtoupper($condition['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND';
        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            $conditionParts[] = [$columnSql . ' ' . $operator, $logic];
        } else {
            $conditionParts[] = [$columnSql . ' ' . $operator . " '" . pg_escape_string($conn, $value) . "'", $logic];
        }
    }
    if (empty($conditionParts)) {
        return '';
    }
    $built = $conditionParts[0][0];
    for ($i = 1; $i < count($conditionParts); $i++) {
        $built .= ' ' . $conditionParts[$i][1] . ' ' . $conditionParts[$i][0];
    }
    return count($conditionParts) > 1 ? '(' . $built . ')' : $built;
}

function dashboard_run_widget_query(
    $conn,
    array $tableConfig,
    string $schemaName,
    string $table,
    array $query,
    array $displayColumns,
    string $sqlWhere
): array {
    $queryType = $query['type'] ?? 'list';
    $output = ['data' => null];

    if ($queryType === 'count') {
        $columnName = $query['column'] ?? id_column();
        if (isset($tableConfig['columns'][$columnName]) || $columnName === id_column()) {
            $sql = sprintf(
                'SELECT COUNT(%s) AS count FROM %s.%s%s',
                pg_ident($columnName),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $row = pg_fetch_assoc($result);
                $output['data'] = (int)($row['count'] ?? 0);
                pg_free_result($result);
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($queryType === 'sum') {
        $columnName = $query['column'] ?? '';
        if (isset($tableConfig['columns'][$columnName])) {
            $sql = sprintf(
                'SELECT COALESCE(SUM(%s), 0) AS total FROM %s.%s%s',
                pg_ident($columnName),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $row = pg_fetch_assoc($result);
                $value = (float)($row['total'] ?? 0);
                $output['data'] = ($value == (int)$value) ? (int)$value : round($value, 2);
                pg_free_result($result);
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($queryType === 'avg') {
        $columnName = $query['column'] ?? '';
        if (isset($tableConfig['columns'][$columnName])) {
            $sql = sprintf(
                'SELECT COALESCE(AVG(%s), 0) AS total FROM %s.%s%s',
                pg_ident($columnName),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $row = pg_fetch_assoc($result);
                $value = (float)($row['total'] ?? 0);
                $output['data'] = ($value == (int)$value) ? (int)$value : round($value, 2);
                pg_free_result($result);
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($queryType === 'group_by') {
        $groupColumn = $query['group_column'] ?? '';
        $aggregateColumn = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        if (isset($tableConfig['columns'][$groupColumn])) {
            $sql = sprintf(
                'SELECT %s AS label, %s(%s) AS value FROM %s.%s%s GROUP BY %s ORDER BY value DESC',
                pg_ident($groupColumn),
                $aggType,
                pg_ident($aggregateColumn),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere,
                pg_ident($groupColumn)
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $data = [];
                while ($row = pg_fetch_assoc($result)) {
                    $row['value'] = is_numeric($row['value']) ? (float)$row['value'] : $row['value'];
                    $data[] = $row;
                }
                pg_free_result($result);
                $output['data'] = $data;
                $output['column_type'] = $tableConfig['columns'][$groupColumn]['type'] ?? 'text';
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($queryType === 'time_series') {
        $xAxisColumn = $query['x_column'] ?? '';
        $aggregateColumn = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        $granularity = strtolower($query['granularity'] ?? 'month');
        $allowedGran = ['day', 'week', 'month', 'year'];
        $granularity = in_array($granularity, $allowedGran, true) ? $granularity : 'month';
        if (isset($tableConfig['columns'][$xAxisColumn])) {
            $bucket = sprintf("DATE_TRUNC('%s', %s)", $granularity, pg_ident($xAxisColumn));
            $sql = sprintf(
                'SELECT %s AS label, %s(%s) AS value FROM %s.%s%s GROUP BY 1 ORDER BY 1 ASC',
                $bucket,
                $aggType,
                pg_ident($aggregateColumn),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $data = [];
                while ($row = pg_fetch_assoc($result)) {
                    $row['value'] = is_numeric($row['value']) ? (float)$row['value'] : $row['value'];
                    $data[] = $row;
                }
                pg_free_result($result);
                $output['data'] = $data;
                $output['column_type'] = $tableConfig['columns'][$xAxisColumn]['type'] ?? 'text';
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } else {
        $limit = (int)($query['limit'] ?? 5);
        $orderBy = $query['order_by'] ?? id_column();
        $directory = strtoupper($query['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $resolvedDisplayColumns = $displayColumns ?: [id_column()];
        $validColumns = array_filter(
            $resolvedDisplayColumns,
            fn($column) => isset($tableConfig['columns'][$column]) || $column === id_column()
        );
        if (empty($validColumns)) {
            $validColumns = [id_column()];
        }

        $selectSql = implode(', ', array_map('pg_ident', $validColumns));
        if (isset($tableConfig['columns'][$orderBy]) || $orderBy === id_column()) {
            $sql = sprintf(
                'SELECT %s FROM %s.%s%s ORDER BY %s %s LIMIT %d',
                $selectSql,
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere,
                pg_ident($orderBy),
                $directory,
                $limit
            );
            $result = @pg_query($conn, $sql);
            if ($result) {
                $data = [];
                while ($row = pg_fetch_assoc($result)) {
                    $data[] = $row;
                }
                pg_free_result($result);
                $output['data'] = $data;
                $columnTypes = [];
                foreach ($validColumns as $columnName) {
                    $columnTypes[$columnName] = $tableConfig['columns'][$columnName]['type'] ?? 'text';
                }
                $output['column_types'] = $columnTypes;
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    }

    return $output;
}
