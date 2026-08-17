<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'performance_check') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaConfig    = config_get('schema') ?? [];
        $dashConfig      = config_get('dashboard') ?? [];
        $tables       = $schemaConfig['tables'] ?? [];
        $widgets      = $dashConfig['widgets']  ?? [];

        $needed = [];

        foreach ($tables as $tableName => $tableConfig) {
            $pgSchema = $tableConfig['schema'] ?? 'app';

            foreach (($tableConfig['foreign_keys'] ?? []) as $fkColumn => $foreignKeyDefinition) {
                if (!is_string($fkColumn)) {
                    continue;
                }
                $needed[$pgSchema][$tableName][$fkColumn][] = 'Foreign key column';
            }

            foreach (($tableConfig['subtables'] ?? []) as $subtable) {
                $child   = $subtable['table']       ?? '';
                $fkColumn   = $subtable['foreign_key'] ?? '';
                if ($child === '' || $fkColumn === '') {
                    continue;
                }
                $childSchema = $tables[$child]['schema'] ?? 'app';
                $needed[$childSchema][$child][$fkColumn][] = "Subtable join from {$tableName}";
            }

            foreach (($tableConfig['default_sort'] ?? []) as $rule) {
                $column = $rule['column'] ?? '';
                if ($column !== '' && $column !== 'id') {
                    $needed[$pgSchema][$tableName][$column][] = 'Default sort column';
                }
            }
        }

        foreach ($widgets as $widget) {
            $widgetTable = $widget['table'] ?? '';
            if ($widgetTable === '' || !isset($tables[$widgetTable])) {
                continue;
            }
            $widgetSchema = $tables[$widgetTable]['schema'] ?? 'app';
            $widgetTitle  = $widget['title'] ?? ($widget['id'] ?? 'widget');
            $query   = $widget['query'] ?? [];

            foreach (($query['conditions'] ?? []) as $condition) {
                $column = $condition['col'] ?? '';
                if ($column !== '' && $column !== 'id') {
                    $needed[$widgetSchema][$widgetTable][$column][] = "Widget filter: \"{$widgetTitle}\"";
                }
            }
            $orderBy  = $query['order_by']      ?? '';
            $groupColumn = $query['group_column']   ?? '';
            $aggregateColumn   = $query['agg_column']     ?? '';
            if ($orderBy  !== '' && $orderBy  !== 'id') {
                $needed[$widgetSchema][$widgetTable][$orderBy][]  = "Widget ORDER BY: \"{$widgetTitle}\"";
            }
            if ($groupColumn !== '' && $groupColumn !== 'id') {
                $needed[$widgetSchema][$widgetTable][$groupColumn][] = "Widget GROUP BY: \"{$widgetTitle}\"";
            }
        }

        $suggestions = [];

        foreach ($needed as $pgSchema => $schemaTables) {
            foreach ($schemaTables as $tableName => $columns) {
                $result = @pg_query_params(
                    $conn,
                    "SELECT indexdef FROM pg_indexes WHERE schemaname = \$1 AND tablename = \$2",
                    [$pgSchema, $tableName]
                );
                $indexedColumns = [];
                if ($result) {
                    while ($row = pg_fetch_row($result)) {
                        if (preg_match('/\(([^)]+)\)/', $row[0], $matches)) {
                            foreach (explode(',', $matches[1]) as $indexColumn) {
                                $indexColumn = trim(preg_replace(
                                    '/\s+(ASC|DESC|NULLS\s+(FIRST|LAST))\s*$/i',
                                    '',
                                    trim($indexColumn)
                                ));
                                $indexedColumns[] = $indexColumn;
                            }
                        }
                    }
                }

                foreach ($columns as $column => $reasons) {
                    if (in_array($column, $indexedColumns, true)) {
                        continue;
                    }

                    $priority = 'medium';
                    foreach ($reasons as $row) {
                        if (str_contains($row, 'Foreign key') || str_contains($row, 'Subtable join')) {
                            $priority = 'high';
                            break;
                        }
                    }

                    $indexName    = 'idx_' . $tableName . '_' . $column;
                    $suggestions[] = [
                        'schema'   => $pgSchema,
                        'table'    => $tableName,
                        'column'   => $column,
                        'reasons'  => array_values(array_unique($reasons)),
                        'priority' => $priority,
                        'sql'      => "CREATE INDEX IF NOT EXISTS {$indexName}"
                            . " ON \"{$pgSchema}\".\"{$tableName}\" ({$column});",
                    ];
                }
            }
        }

        usort($suggestions, static function ($first, $second) {
            $firstPriority = $first['priority'] === 'high' ? 0 : 1;
            $secondPriority = $second['priority'] === 'high' ? 0 : 1;
            if ($firstPriority !== $secondPriority) {
                return $firstPriority - $secondPriority;
            }
            $firstTarget = $first['table'] . '.' . $first['column'];
            $secondTarget = $second['table'] . '.' . $second['column'];
            return strcmp($firstTarget, $secondTarget);
        });

        echo json_encode(['status' => 'success', 'suggestions' => $suggestions, 'total' => count($suggestions)]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'performance_slow_queries') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $extensionResult = @pg_query($conn, "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
        if (!$extensionResult || pg_num_rows($extensionResult) === 0) {
            echo json_encode([
                'status'  => 'unavailable',
                'message' => 'pg_stat_statements extension is not installed. Run: CREATE EXTENSION pg_stat_statements;',
            ]);
            throw ResponseException::sent();
        }

        $sql = "
            SELECT query,
                   calls,
                   ROUND(mean_exec_time::numeric, 2)  AS mean_ms,
                   ROUND(total_exec_time::numeric, 2) AS total_ms,
                   ROUND(stddev_exec_time::numeric, 2) AS stddev_ms,
                   rows
            FROM pg_stat_statements
            WHERE query NOT LIKE '%pg_stat_statements%'
            ORDER BY mean_exec_time DESC
            LIMIT 15
        ";
        $result = @pg_query($conn, $sql);
        if (!$result) {
            admin_db_fail($conn, 'slow_queries');
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'performance_table_stats') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaConfig  = config_get('schema') ?? [];
        $tables     = $schemaConfig['tables'] ?? [];

        $tracked = [];
        foreach ($tables as $tableName => $config) {
            $tracked[] = [$config['schema'] ?? 'app', $tableName];
        }

        if (empty($tracked)) {
            admin_ok(['rows' => []]);
        }

        $sql = "
            SELECT s.schemaname,
                   s.relname AS tablename,
                   s.n_live_tup,
                   s.n_dead_tup,
                   CASE WHEN s.n_live_tup + s.n_dead_tup > 0
                        THEN ROUND(100.0 * s.n_dead_tup / (s.n_live_tup + s.n_dead_tup), 1)
                        ELSE 0 END AS dead_pct,
                   s.seq_scan,
                   s.idx_scan,
                   TO_CHAR(s.last_vacuum,      'YYYY-MM-DD HH24:MI') AS last_vacuum,
                   TO_CHAR(s.last_autovacuum,  'YYYY-MM-DD HH24:MI') AS last_autovacuum,
                   TO_CHAR(s.last_analyze,     'YYYY-MM-DD HH24:MI') AS last_analyze,
                   TO_CHAR(s.last_autoanalyze, 'YYYY-MM-DD HH24:MI') AS last_autoanalyze,
                   pg_size_pretty(
                       pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))
                   ) AS total_size,
                   c.reltuples::bigint AS estimated_rows
            FROM pg_stat_user_tables s
            JOIN pg_class c ON c.relname = s.relname
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
            WHERE (s.schemaname, s.relname) = ANY(\$1::text[][])
            ORDER BY s.n_dead_tup DESC, s.seq_scan DESC
        ";

        $escapeArrayValue = static fn(string $value): string
            => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        $pairs = '{' . implode(',', array_map(
            static fn($pair) => '{' . $escapeArrayValue((string) $pair[0])
                . ',' . $escapeArrayValue((string) $pair[1]) . '}',
            $tracked
        )) . '}';
        $result = @pg_query_params($conn, $sql, [$pairs]);
        if (!$result) {
            $rows = [];
            foreach ($tracked as [$pgSchema, $tableName]) {
                $indexUsageResult = @pg_query_params($conn, "
                    SELECT s.schemaname, s.relname AS tablename,
                           s.n_live_tup, s.n_dead_tup,
                           CASE WHEN s.n_live_tup + s.n_dead_tup > 0
                                THEN ROUND(100.0 * s.n_dead_tup / (s.n_live_tup + s.n_dead_tup), 1)
                                ELSE 0 END AS dead_pct,
                           s.seq_scan, s.idx_scan,
                           TO_CHAR(s.last_autovacuum,  'YYYY-MM-DD HH24:MI') AS last_autovacuum,
                           TO_CHAR(s.last_autoanalyze, 'YYYY-MM-DD HH24:MI') AS last_autoanalyze,
                           pg_size_pretty(
                               pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))
                           ) AS total_size,
                           c.reltuples::bigint AS estimated_rows
                    FROM pg_stat_user_tables s
                    JOIN pg_class c ON c.relname = s.relname
                    JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
                    WHERE s.schemaname = \$1 AND s.relname = \$2
                ", [$pgSchema, $tableName]);
                if ($indexUsageResult && $row = pg_fetch_assoc($indexUsageResult)) {
                    $rows[] = $row;
                }
            }
            admin_ok(['rows' => $rows]);
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'performance_db_health') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $databaseResult = @pg_query($conn, "
            SELECT datname,
                   blks_hit, blks_read,
                   CASE WHEN blks_hit + blks_read > 0
                        THEN ROUND(100.0 * blks_hit / (blks_hit + blks_read), 2)
                        ELSE 100 END AS cache_hit_ratio,
                   numbackends,
                   xact_commit, xact_rollback, deadlocks,
                   pg_size_pretty(pg_database_size(current_database())) AS db_size
            FROM pg_stat_database
            WHERE datname = current_database()
        ");
        if (!$databaseResult) {
            admin_db_fail($conn, 'db_health_stat');
        }
        $databaseStatistics = pg_fetch_assoc($databaseResult);

        $maxConnectionsResult = @pg_query($conn, "SELECT setting FROM pg_settings WHERE name = 'max_connections'");
        $maxConnections = $maxConnectionsResult ? (int)(pg_fetch_row($maxConnectionsResult)[0] ?? 100) : 100;

        $versionResult = @pg_query($conn, "SELECT version()");
        $version = $versionResult ? (pg_fetch_row($versionResult)[0] ?? '') : '';

        $activeConnectionsResult = @pg_query($conn, "SELECT count(*) FROM pg_stat_activity WHERE state = 'active'");
        $activeConnections = $activeConnectionsResult ? (int)(pg_fetch_row($activeConnectionsResult)[0] ?? 0) : 0;

        echo json_encode([
            'status'       => 'success',
            'db'           => $databaseStatistics,
            'max_conn'     => $maxConnections,
            'active_conn'  => $activeConnections,
            'pg_version'   => $version,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'performance_unused_indexes') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $sql = "
            SELECT s.schemaname, s.relname AS tablename, s.indexrelname AS indexname,
                   s.idx_scan,
                   pg_size_pretty(pg_relation_size(s.indexrelid)) AS index_size,
                   pg_relation_size(s.indexrelid) AS index_bytes,
                   i.indexdef
            FROM pg_stat_user_indexes s
            JOIN pg_indexes i ON i.schemaname = s.schemaname
                              AND i.tablename  = s.relname
                              AND i.indexname  = s.indexrelname
            WHERE s.idx_scan = 0
              AND i.indexdef NOT LIKE '%UNIQUE%'
              AND s.indexrelname NOT LIKE '%_pkey'
            ORDER BY pg_relation_size(s.indexrelid) DESC
        ";
        $result = @pg_query($conn, $sql);
        if (!$result) {
            admin_db_fail($conn, 'unused_indexes');
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $row['drop_sql'] = 'DROP INDEX IF EXISTS '
                . pg_escape_identifier($conn, $row['schemaname'])
                . '.'
                . pg_escape_identifier($conn, $row['indexname'])
                . ';';
            $rows[] = $row;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'performance_schema_warnings') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaConfig   = config_get('schema') ?? [];
        $dashConfig     = config_get('dashboard') ?? [];
        $tables      = $schemaConfig['tables'] ?? [];
        $widgets     = $dashConfig['widgets']  ?? [];

        $warnings = [];

        $rowCounts = [];
        foreach ($tables as $tableName => $config) {
            $pgSchema = $config['schema'] ?? 'app';
            $countResult = @pg_query_params(
                $conn,
                "SELECT c.reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
                    . " WHERE n.nspname = \$1 AND c.relname = \$2",
                [$pgSchema, $tableName]
            );
            if ($countResult && $row = pg_fetch_row($countResult)) {
                $rowCounts[$tableName] = (int)$row[0];
            }
        }

        foreach ($tables as $tableName => $config) {
            $tableColumns     = $config['columns'] ?? [];
            $columnCount = count($tableColumns);
            $estRows  = $rowCounts[$tableName] ?? 0;
            $display  = $config['display_name'] ?? $tableName;

            if ($columnCount > 20) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Schema complexity',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "{$columnCount} columns defined — consider splitting or hiding non-essential"
                        . " columns (show_in_grid: false).",
                ];
            }

            if ($estRows > 5000 && empty($config['initial_limit'])) {
                $warnings[] = [
                    'severity' => 'high',
                    'category' => 'Load performance',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows)
                        . " rows, no Initial Load Limit set — full table fetched on grid load."
                        . " Set initial_limit in Schema → Table Properties.",
                ];
            }

            if ($estRows > 1000 && empty($config['default_sort'])) {
                $warnings[] = [
                    'severity' => 'low',
                    'category' => 'UX / sort',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows)
                        . " rows, no Default Sort configured — falls back to id DESC."
                        . " Define default_sort in Schema → Table Properties.",
                ];
            }

            foreach (($config['subtables'] ?? []) as $subtable) {
                if (empty($subtable['columns_to_show'])) {
                    $warnings[] = [
                        'severity' => 'medium',
                        'category' => 'Subtable config',
                        'table'    => $tableName,
                        'display'  => $display,
                        'message'  => "Subtable \"{$subtable['table']}\" has no columns_to_show — all columns fetched"
                            . " in drilldown. Specify columns_to_show in Schema.",
                    ];
                }
            }
        }

        foreach ($widgets as $widget) {
            $widgetTable = $widget['table'] ?? '';
            $widgetTitle = $widget['title'] ?? ($widget['id'] ?? 'widget');
            if ($widgetTable === '' || !isset($tables[$widgetTable])) {
                continue;
            }
            $estRows = $rowCounts[$widgetTable] ?? 0;

            if ($widget['type'] === 'list' && empty($widget['query']['limit']) && $estRows > 1000) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Widget config',
                    'table'    => $widgetTable,
                    'display'  => $tables[$widgetTable]['display_name'] ?? $widgetTable,
                    'message'  => "List widget \"{$widgetTitle}\" has no row limit on a table with ~"
                        . number_format($estRows) . " rows — set query.limit in Dashboard editor.",
                ];
            }
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($warnings, fn($first, $second) => ($order[$first['severity']] ?? 9) - ($order[$second['severity']] ?? 9));

        echo json_encode(['status' => 'success', 'warnings' => $warnings, 'total' => count($warnings)]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
