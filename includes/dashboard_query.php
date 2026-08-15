<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function dashboard_conditions_sql($conn, array $tableCfg, array $conditions): string
{
    $condParts = [];
    $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];
    foreach ($conditions as $cond) {
        $columnName = $cond['col'] ?? '';
        $operator  = $cond['op']  ?? '=';
        $value = (string)($cond['val'] ?? '');
        if (!isset($tableCfg['columns'][$columnName])) {
            continue;
        }
        if (!in_array($operator, $allowedOps, true)) {
            continue;
        }
        $colSql = pg_ident($columnName);
        $logic = strtoupper($cond['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND';
        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            $condParts[] = [$colSql . ' ' . $operator, $logic];
        } else {
            $condParts[] = [$colSql . ' ' . $operator . " '" . pg_escape_string($conn, $value) . "'", $logic];
        }
    }
    if (empty($condParts)) {
        return '';
    }
    $built = $condParts[0][0];
    for ($i = 1; $i < count($condParts); $i++) {
        $built .= ' ' . $condParts[$i][1] . ' ' . $condParts[$i][0];
    }
    return count($condParts) > 1 ? '(' . $built . ')' : $built;
}

function dashboard_run_widget_query(
    $conn,
    array $tableCfg,
    string $schemaName,
    string $table,
    array $query,
    array $displayColumns,
    string $sqlWhere
): array {
    $qType = $query['type'] ?? 'list';
    $output = ['data' => null];

    if ($qType === 'count') {
        $columnName = $query['column'] ?? id_column();
        if (isset($tableCfg['columns'][$columnName]) || $columnName === id_column()) {
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
    } elseif ($qType === 'sum') {
        $columnName = $query['column'] ?? '';
        if (isset($tableCfg['columns'][$columnName])) {
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
    } elseif ($qType === 'avg') {
        $columnName = $query['column'] ?? '';
        if (isset($tableCfg['columns'][$columnName])) {
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
    } elseif ($qType === 'group_by') {
        $groupColumn = $query['group_column'] ?? '';
        $aggregateColumn = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        if (isset($tableCfg['columns'][$groupColumn])) {
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
                $output['column_type'] = $tableCfg['columns'][$groupColumn]['type'] ?? 'text';
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($qType === 'time_series') {
        $xColumn = $query['x_column'] ?? '';
        $aggregateColumn = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        $granularity = strtolower($query['granularity'] ?? 'month');
        $allowedGran = ['day', 'week', 'month', 'year'];
        $granularity = in_array($granularity, $allowedGran, true) ? $granularity : 'month';
        if (isset($tableCfg['columns'][$xColumn])) {
            $bucket = sprintf("DATE_TRUNC('%s', %s)", $granularity, pg_ident($xColumn));
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
                $output['column_type'] = $tableCfg['columns'][$xColumn]['type'] ?? 'text';
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    } else {
        $limit = (int)($query['limit'] ?? 5);
        $orderBy = $query['order_by'] ?? id_column();
        $dir = strtoupper($query['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $resolvedDisplayColumns = $displayColumns ?: [id_column()];
        $validColumns = array_filter(
            $resolvedDisplayColumns,
            fn($column) => isset($tableCfg['columns'][$column]) || $column === id_column()
        );
        if (empty($validColumns)) {
            $validColumns = [id_column()];
        }

        $selectSql = implode(', ', array_map('pg_ident', $validColumns));
        if (isset($tableCfg['columns'][$orderBy]) || $orderBy === id_column()) {
            $sql = sprintf(
                'SELECT %s FROM %s.%s%s ORDER BY %s %s LIMIT %d',
                $selectSql,
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere,
                pg_ident($orderBy),
                $dir,
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
                $colTypes = [];
                foreach ($validColumns as $columnName) {
                    $colTypes[$columnName] = $tableCfg['columns'][$columnName]['type'] ?? 'text';
                }
                $output['column_types'] = $colTypes;
            } else {
                $output['sql_error'] = 'Query failed.';
            }
        }
    }

    return $output;
}
