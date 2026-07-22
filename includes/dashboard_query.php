<?php

declare(strict_types=1);

// includes/dashboard_query.php — shared dashboard widget query builder/executor.
// Used by public/api.php (GET api=dashboard, live rendering + global date filter) and
// includes/admin/dashboard.php (admin "Calculate" preview against real data, no date
// filter). One implementation so the two never drift apart.

// Builds a parenthesised WHERE fragment (without the WHERE keyword) from a widget's
// structured conditions array. Column validated against $tableCfg, op against an
// allowlist, values escaped via pg_escape_string(). Returns '' if nothing is valid.
function dashboard_conditions_sql($conn, array $tableCfg, array $conditions): string
{
    $condParts = [];
    $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IS NULL', 'IS NOT NULL'];
    foreach ($conditions as $cond) {
        $col = $cond['col'] ?? '';
        $op  = $cond['op']  ?? '=';
        $val = (string)($cond['val'] ?? '');
        if (!isset($tableCfg['columns'][$col])) {
            continue;
        }
        if (!in_array($op, $allowedOps, true)) {
            continue;
        }
        $colSql = pg_ident($col);
        $logic = strtoupper($cond['logic'] ?? 'AND') === 'OR' ? 'OR' : 'AND';
        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            $condParts[] = [$colSql . ' ' . $op, $logic];
        } else {
            $condParts[] = [$colSql . ' ' . $op . " '" . pg_escape_string($conn, $val) . "'", $logic];
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

// Executes one widget's query (count/sum/avg/group_by/time_series/list) given an
// already-built ' WHERE ...' fragment (may be ''). Returns the same shape that
// api=dashboard merges onto the widget: data / sql_error / column_type / column_types.
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
    $out = ['data' => null];

    if ($qType === 'count') {
        $col = $query['column'] ?? id_column();
        if (isset($tableCfg['columns'][$col]) || $col === id_column()) {
            $sql = sprintf('SELECT COUNT(%s) AS count FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWhere);
            $res = @pg_query($conn, $sql);
            if ($res) {
                $row = pg_fetch_assoc($res);
                $out['data'] = (int)($row['count'] ?? 0);
                pg_free_result($res);
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($qType === 'sum') {
        $col = $query['column'] ?? '';
        if (isset($tableCfg['columns'][$col])) {
            $sql = sprintf('SELECT COALESCE(SUM(%s), 0) AS total FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWhere);
            $res = @pg_query($conn, $sql);
            if ($res) {
                $row = pg_fetch_assoc($res);
                $val = (float)($row['total'] ?? 0);
                $out['data'] = ($val == (int)$val) ? (int)$val : round($val, 2);
                pg_free_result($res);
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($qType === 'avg') {
        $col = $query['column'] ?? '';
        if (isset($tableCfg['columns'][$col])) {
            $sql = sprintf('SELECT COALESCE(AVG(%s), 0) AS total FROM %s.%s%s', pg_ident($col), pg_ident($schemaName), pg_ident($table), $sqlWhere);
            $res = @pg_query($conn, $sql);
            if ($res) {
                $row = pg_fetch_assoc($res);
                $val = (float)($row['total'] ?? 0);
                $out['data'] = ($val == (int)$val) ? (int)$val : round($val, 2);
                pg_free_result($res);
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($qType === 'group_by') {
        $grpCol = $query['group_column'] ?? '';
        $aggCol = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        if (isset($tableCfg['columns'][$grpCol])) {
            $sql = sprintf('SELECT %s AS label, %s(%s) AS value FROM %s.%s%s GROUP BY %s ORDER BY value DESC', pg_ident($grpCol), $aggType, pg_ident($aggCol), pg_ident($schemaName), pg_ident($table), $sqlWhere, pg_ident($grpCol));
            $res = @pg_query($conn, $sql);
            if ($res) {
                $data = [];
                while ($r = pg_fetch_assoc($res)) {
                    $r['value'] = is_numeric($r['value']) ? (float)$r['value'] : $r['value'];
                    $data[] = $r;
                }
                pg_free_result($res);
                $out['data'] = $data;
                $out['column_type'] = $tableCfg['columns'][$grpCol]['type'] ?? 'text';
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    } elseif ($qType === 'time_series') {
        $xCol = $query['x_column'] ?? '';
        $aggCol = $query['agg_column'] ?? id_column();
        $aggType = strtoupper($query['agg_type'] ?? 'COUNT');
        $allowedAgg = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $aggType = in_array($aggType, $allowedAgg, true) ? $aggType : 'COUNT';
        $granularity = strtolower($query['granularity'] ?? 'month');
        $allowedGran = ['day', 'week', 'month', 'year'];
        $granularity = in_array($granularity, $allowedGran, true) ? $granularity : 'month';
        if (isset($tableCfg['columns'][$xCol])) {
            $bucket = sprintf("DATE_TRUNC('%s', %s)", $granularity, pg_ident($xCol));
            $sql = sprintf(
                'SELECT %s AS label, %s(%s) AS value FROM %s.%s%s GROUP BY 1 ORDER BY 1 ASC',
                $bucket,
                $aggType,
                pg_ident($aggCol),
                pg_ident($schemaName),
                pg_ident($table),
                $sqlWhere
            );
            $res = @pg_query($conn, $sql);
            if ($res) {
                $data = [];
                while ($r = pg_fetch_assoc($res)) {
                    $r['value'] = is_numeric($r['value']) ? (float)$r['value'] : $r['value'];
                    $data[] = $r;
                }
                pg_free_result($res);
                $out['data'] = $data;
                $out['column_type'] = $tableCfg['columns'][$xCol]['type'] ?? 'text';
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    } else {
        $limit = (int)($query['limit'] ?? 5);
        $orderBy = $query['order_by'] ?? id_column();
        $dir = strtoupper($query['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $displayCols = $displayColumns ?: [id_column()];
        $validCols = array_filter($displayCols, fn($c) => isset($tableCfg['columns'][$c]) || $c === id_column());
        if (empty($validCols)) {
            $validCols = [id_column()];
        }

        $selectSql = implode(', ', array_map('pg_ident', $validCols));
        if (isset($tableCfg['columns'][$orderBy]) || $orderBy === id_column()) {
            $sql = sprintf('SELECT %s FROM %s.%s%s ORDER BY %s %s LIMIT %d', $selectSql, pg_ident($schemaName), pg_ident($table), $sqlWhere, pg_ident($orderBy), $dir, $limit);
            $res = @pg_query($conn, $sql);
            if ($res) {
                $data = [];
                while ($r = pg_fetch_assoc($res)) {
                    $data[] = $r;
                }
                pg_free_result($res);
                $out['data'] = $data;
                $colTypes = [];
                foreach ($validCols as $col) {
                    $colTypes[$col] = $tableCfg['columns'][$col]['type'] ?? 'text';
                }
                $out['column_types'] = $colTypes;
            } else {
                $out['sql_error'] = 'Query failed.';
            }
        }
    }

    return $out;
}
