<?php

declare(strict_types=1);

// includes/admin/performance.php — admin api.php module: PostgreSQL diagnostics (performance_check,
// performance_slow_queries, performance_table_stats,
// performance_db_health, performance_unused_indexes, performance_schema_warnings).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'performance_check') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaCfg    = config_get('schema') ?? [];
        $dashCfg      = config_get('dashboard') ?? [];
        $tables       = $schemaCfg['tables'] ?? [];
        $widgets      = $dashCfg['widgets']  ?? [];

        // Collect [pgSchema][tableName][column] = [reasons]
        $needed = [];

        foreach ($tables as $tableName => $tableCfg) {
            $pgSchema = $tableCfg['schema'] ?? 'app';

            // FK columns on this table (child side of a relation)
            foreach (($tableCfg['foreign_keys'] ?? []) as $fkCol => $fkDef) {
                if (!is_string($fkCol)) {
                    continue;
                }
                $needed[$pgSchema][$tableName][$fkCol][] = 'Foreign key column';
            }

            // Subtables: FK column lives on child table
            foreach (($tableCfg['subtables'] ?? []) as $sub) {
                $child   = $sub['table']       ?? '';
                $fkCol   = $sub['foreign_key'] ?? '';
                if ($child === '' || $fkCol === '') {
                    continue;
                }
                $childSchema = $tables[$child]['schema'] ?? 'app';
                $needed[$childSchema][$child][$fkCol][] = "Subtable join from {$tableName}";
            }

            // Default sort columns
            foreach (($tableCfg['default_sort'] ?? []) as $rule) {
                $col = $rule['column'] ?? '';
                if ($col !== '' && $col !== 'id') {
                    $needed[$pgSchema][$tableName][$col][] = 'Default sort column';
                }
            }
        }

        // Dashboard widget columns
        foreach ($widgets as $widget) {
            $wTable = $widget['table'] ?? '';
            if ($wTable === '' || !isset($tables[$wTable])) {
                continue;
            }
            $wSchema = $tables[$wTable]['schema'] ?? 'app';
            $wTitle  = $widget['title'] ?? ($widget['id'] ?? 'widget');
            $query   = $widget['query'] ?? [];

            foreach (($query['conditions'] ?? []) as $cond) {
                $col = $cond['col'] ?? '';
                if ($col !== '' && $col !== 'id') {
                    $needed[$wSchema][$wTable][$col][] = "Widget filter: \"{$wTitle}\"";
                }
            }
            $orderBy  = $query['order_by']      ?? '';
            $groupCol = $query['group_column']   ?? '';
            $aggCol   = $query['agg_column']     ?? '';
            if ($orderBy  !== '' && $orderBy  !== 'id') {
                $needed[$wSchema][$wTable][$orderBy][]  = "Widget ORDER BY: \"{$wTitle}\"";
            }
            if ($groupCol !== '' && $groupCol !== 'id') {
                $needed[$wSchema][$wTable][$groupCol][] = "Widget GROUP BY: \"{$wTitle}\"";
            }
        }

        // For each table fetch existing pg_indexes, build set of already-indexed leading columns
        $suggestions = [];

        foreach ($needed as $pgSchema => $schemaTables) {
            foreach ($schemaTables as $tableName => $columns) {
                $res = @pg_query_params(
                    $conn,
                    "SELECT indexdef FROM pg_indexes WHERE schemaname = \$1 AND tablename = \$2",
                    [$pgSchema, $tableName]
                );
                $indexedCols = [];
                if ($res) {
                    while ($row = pg_fetch_row($res)) {
                        // Extract column list from indexdef: "... ON schema.table USING btree (col1, col2)"
                        if (preg_match('/\(([^)]+)\)/', $row[0], $m)) {
                            foreach (explode(',', $m[1]) as $ic) {
                                $ic = trim(preg_replace('/\s+(ASC|DESC|NULLS\s+(FIRST|LAST))\s*$/i', '', trim($ic)));
                                $indexedCols[] = $ic;
                            }
                        }
                    }
                }

                foreach ($columns as $col => $reasons) {
                    if (in_array($col, $indexedCols, true)) {
                        continue;
                    }

                    $priority = 'medium';
                    foreach ($reasons as $r) {
                        if (str_contains($r, 'Foreign key') || str_contains($r, 'Subtable join')) {
                            $priority = 'high';
                            break;
                        }
                    }

                    $indexName    = 'idx_' . $tableName . '_' . $col;
                    $suggestions[] = [
                        'schema'   => $pgSchema,
                        'table'    => $tableName,
                        'column'   => $col,
                        'reasons'  => array_values(array_unique($reasons)),
                        'priority' => $priority,
                        'sql'      => "CREATE INDEX IF NOT EXISTS {$indexName} ON \"{$pgSchema}\".\"{$tableName}\" ({$col});",
                    ];
                }
            }
        }

        // High priority first, then alpha by table+column
        usort($suggestions, static function ($a, $b) {
            $pa = $a['priority'] === 'high' ? 0 : 1;
            $pb = $b['priority'] === 'high' ? 0 : 1;
            if ($pa !== $pb) {
                return $pa - $pb;
            }
            $ta = $a['table'] . '.' . $a['column'];
            $tb = $b['table'] . '.' . $b['column'];
            return strcmp($ta, $tb);
        });

        echo json_encode(['status' => 'success', 'suggestions' => $suggestions, 'total' => count($suggestions)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'performance_slow_queries') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // Check extension available
        $extRes = @pg_query($conn, "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
        if (!$extRes || pg_num_rows($extRes) === 0) {
            echo json_encode(['status' => 'unavailable', 'message' => 'pg_stat_statements extension is not installed. Run: CREATE EXTENSION pg_stat_statements;']);
            exit;
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
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'slow_queries');
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'performance_table_stats') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaCfg  = config_get('schema') ?? [];
        $tables     = $schemaCfg['tables'] ?? [];

        // Build set of (pgSchema, tableName) pairs from schema.json
        $tracked = [];
        foreach ($tables as $tableName => $cfg) {
            $tracked[] = [$cfg['schema'] ?? 'app', $tableName];
        }

        if (empty($tracked)) {
            echo json_encode(['status' => 'success', 'rows' => []]);
            exit;
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
                   pg_size_pretty(pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))) AS total_size,
                   c.reltuples::bigint AS estimated_rows
            FROM pg_stat_user_tables s
            JOIN pg_class c ON c.relname = s.relname
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
            WHERE (s.schemaname, s.relname) = ANY(\$1::text[][])
            ORDER BY s.n_dead_tup DESC, s.seq_scan DESC
        ";

        $pairs = '{' . implode(',', array_map(fn($p) => '{"' . $p[0] . '","' . $p[1] . '"}', $tracked)) . '}';
        $res = @pg_query_params($conn, $sql, [$pairs]);
        if (!$res) {
            // Fallback: query per table if array-of-arrays not supported
            $rows = [];
            foreach ($tracked as [$pgSchema, $tableName]) {
                $r2 = @pg_query_params($conn, "
                    SELECT s.schemaname, s.relname AS tablename,
                           s.n_live_tup, s.n_dead_tup,
                           CASE WHEN s.n_live_tup + s.n_dead_tup > 0
                                THEN ROUND(100.0 * s.n_dead_tup / (s.n_live_tup + s.n_dead_tup), 1)
                                ELSE 0 END AS dead_pct,
                           s.seq_scan, s.idx_scan,
                           TO_CHAR(s.last_autovacuum,  'YYYY-MM-DD HH24:MI') AS last_autovacuum,
                           TO_CHAR(s.last_autoanalyze, 'YYYY-MM-DD HH24:MI') AS last_autoanalyze,
                           pg_size_pretty(pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))) AS total_size,
                           c.reltuples::bigint AS estimated_rows
                    FROM pg_stat_user_tables s
                    JOIN pg_class c ON c.relname = s.relname
                    JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
                    WHERE s.schemaname = \$1 AND s.relname = \$2
                ", [$pgSchema, $tableName]);
                if ($r2 && $row = pg_fetch_assoc($r2)) {
                    $rows[] = $row;
                }
            }
            echo json_encode(['status' => 'success', 'rows' => $rows]);
            exit;
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'performance_db_health') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $dbRes = @pg_query($conn, "
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
        if (!$dbRes) {
            admin_db_fail($conn, 'db_health_stat');
        }
        $db = pg_fetch_assoc($dbRes);

        $maxConnRes = @pg_query($conn, "SELECT setting FROM pg_settings WHERE name = 'max_connections'");
        $maxConn = $maxConnRes ? (int)(pg_fetch_row($maxConnRes)[0] ?? 100) : 100;

        $verRes = @pg_query($conn, "SELECT version()");
        $version = $verRes ? (pg_fetch_row($verRes)[0] ?? '') : '';

        $activeRes = @pg_query($conn, "SELECT count(*) FROM pg_stat_activity WHERE state = 'active'");
        $activeConn = $activeRes ? (int)(pg_fetch_row($activeRes)[0] ?? 0) : 0;

        echo json_encode([
            'status'       => 'success',
            'db'           => $db,
            'max_conn'     => $maxConn,
            'active_conn'  => $activeConn,
            'pg_version'   => $version,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'performance_unused_indexes') {
    header('Content-Type: application/json');
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
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'unused_indexes');
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $r['drop_sql'] = 'DROP INDEX IF EXISTS ' . pg_escape_identifier($conn, $r['schemaname']) . '.' . pg_escape_identifier($conn, $r['indexname']) . ';';
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'performance_schema_warnings') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        require_once __DIR__ . '/../config_store.php';
        $schemaCfg   = config_get('schema') ?? [];
        $dashCfg     = config_get('dashboard') ?? [];
        $tables      = $schemaCfg['tables'] ?? [];
        $widgets     = $dashCfg['widgets']  ?? [];

        $warnings = [];

        // Get estimated row counts from pg_class
        $rowCounts = [];
        foreach ($tables as $tableName => $cfg) {
            $pgSchema = $cfg['schema'] ?? 'app';
            $r = @pg_query_params(
                $conn,
                "SELECT c.reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = \$1 AND c.relname = \$2",
                [$pgSchema, $tableName]
            );
            if ($r && $row = pg_fetch_row($r)) {
                $rowCounts[$tableName] = (int)$row[0];
            }
        }

        foreach ($tables as $tableName => $cfg) {
            $cols     = $cfg['columns'] ?? [];
            $colCount = count($cols);
            $estRows  = $rowCounts[$tableName] ?? 0;
            $display  = $cfg['display_name'] ?? $tableName;

            // Too many columns
            if ($colCount > 20) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Schema complexity',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "{$colCount} columns defined — consider splitting or hiding non-essential columns (show_in_grid: false).",
                ];
            }

            // Large table without initial_limit
            if ($estRows > 5000 && empty($cfg['initial_limit'])) {
                $warnings[] = [
                    'severity' => 'high',
                    'category' => 'Load performance',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows) . " rows, no Initial Load Limit set — full table fetched on grid load. Set initial_limit in Schema → Table Properties.",
                ];
            }

            // Large table without default_sort
            if ($estRows > 1000 && empty($cfg['default_sort'])) {
                $warnings[] = [
                    'severity' => 'low',
                    'category' => 'UX / sort',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows) . " rows, no Default Sort configured — falls back to id DESC. Define default_sort in Schema → Table Properties.",
                ];
            }

            // Subtables without columns_to_show
            foreach (($cfg['subtables'] ?? []) as $sub) {
                if (empty($sub['columns_to_show'])) {
                    $warnings[] = [
                        'severity' => 'medium',
                        'category' => 'Subtable config',
                        'table'    => $tableName,
                        'display'  => $display,
                        'message'  => "Subtable \"{$sub['table']}\" has no columns_to_show — all columns fetched in drilldown. Specify columns_to_show in Schema.",
                    ];
                }
            }
        }

        // Widgets without table or on large tables without limit
        foreach ($widgets as $widget) {
            $wTable = $widget['table'] ?? '';
            $wTitle = $widget['title'] ?? ($widget['id'] ?? 'widget');
            if ($wTable === '' || !isset($tables[$wTable])) {
                continue;
            }
            $estRows = $rowCounts[$wTable] ?? 0;

            if ($widget['type'] === 'list' && empty($widget['query']['limit']) && $estRows > 1000) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Widget config',
                    'table'    => $wTable,
                    'display'  => $tables[$wTable]['display_name'] ?? $wTable,
                    'message'  => "List widget \"{$wTitle}\" has no row limit on a table with ~" . number_format($estRows) . " rows — set query.limit in Dashboard editor.",
                ];
            }
        }

        // Sort: high → medium → low
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($warnings, fn($a, $b) => ($order[$a['severity']] ?? 9) - ($order[$b['severity']] ?? 9));

        echo json_encode(['status' => 'success', 'warnings' => $warnings, 'total' => count($warnings)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
