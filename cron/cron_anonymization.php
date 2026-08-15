<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/exception_handler.php';

use App\Exception\ForbiddenException;

if (php_sapi_name() !== 'cli') {
    os_register_exception_handler('html');
    throw new ForbiddenException('This script may only be run from the command line.');
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);

function anon_log(string $message): void
{
    echo $message . "\n";
    echo str_pad('', 4096) . "\n";
    flush();
}

function anon_edpb_assessment(string $method): array
{
    return match ($method) {
        'static_replacement' => [
            'single_out_risk'  => 'none',
            'linkability_risk' => 'low',
            'inference_risk'   => 'low',
        ],
        default => [
            'single_out_risk'  => 'unknown',
            'linkability_risk' => 'unknown',
            'inference_risk'   => 'unknown',
        ],
    };
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

function cron_anonymization_main(array $argv): int
{
    $triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';
    $dryRun      = (($argv[2] ?? '') === 'dry');

    if ($dryRun) {
        anon_log('[anonymization] DRY RUN — previewing only, no data will be modified.');
    }
    anon_log('[anonymization] Starting (' . $triggeredBy . ')...');

    require_once __DIR__ . '/../includes/config_store.php';
    $config = config_get('anonymization');
    if (!is_array($config)) {
        anon_log('[anonymization] Config not found. Create it via Admin > Anonymization.');
        return 1;
    }

    $enabled   = (bool)($config['enabled'] ?? false);
    $frequency = (string)($config['frequency'] ?? 'daily');
    $rules     = (array)($config['rules']    ?? []);

    if (!$enabled && !$dryRun) {
        anon_log('[anonymization] Module is disabled. Exiting.');
        return 0;
    }

    if (empty($rules)) {
        anon_log('[anonymization] No rules configured. Exiting.');
        return 0;
    }

    try {
        $conn = db_connect();
    } catch (\RuntimeException $exception) {
        anon_log('[anonymization] DB connection failed: ' . $exception->getMessage());
        return 1;
    }

    $anonymizationLogTable    = sys_table('anonymization_log');
    $anonymizationReportTable = sys_table('anonymization_report');

    if ($triggeredBy === 'cron' && $frequency !== 'manual') {
        $intervalMap = ['daily' => '1 day', 'weekly' => '7 days', 'monthly' => '30 days'];
        $interval    = $intervalMap[$frequency] ?? '1 day';
        $recentResult   = @pg_query_params(
            $conn,
            "SELECT 1 FROM {$anonymizationLogTable} WHERE status = 'success'"
            . " AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
            []
        );
        if ($recentResult && pg_num_rows($recentResult) > 0) {
            anon_log("[anonymization] Skipping: a successful run exists within the '{$frequency}' window.");
            return 0;
        }
    }

    if ($frequency === 'manual' && $triggeredBy === 'cron') {
        anon_log('[anonymization] Frequency set to manual — only runs when triggered via admin panel.');
        return 0;
    }

    $logId = null;
    if (!$dryRun) {
        $logResult = @pg_query_params(
            $conn,
            "INSERT INTO {$anonymizationLogTable} (triggered_by, status) VALUES (\$1, 'running') RETURNING id",
            [$triggeredBy]
        );
        if ($logResult && ($logRow = pg_fetch_assoc($logResult))) {
            $logId = (int)$logRow['id'];
        }
    }

    $rulesProcessed = 0;
    $rowsAnonymized = 0;
    $errorMessage   = null;
    $reportDetails  = [];

    $schemaCfg = [];
    {
        $decoded = config_get('schema');
    if (is_array($decoded) && isset($decoded['tables'])) {
        $schemaCfg = $decoded['tables'];
    }
    }

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $table       = trim((string)($rule['table']       ?? ''));
        $dateColumn  = trim((string)($rule['date_column'] ?? ''));
        $days        = (int)($rule['days']               ?? 0);
        $column      = trim((string)($rule['column']      ?? ''));
        $replacement = (string)($rule['replacement'] ?? '');

        if ($table === '' || $column === '' || $dateColumn === '' || $days < 1) {
            anon_log('[anonymization] Skipping invalid rule (missing table, column, date_column, or days).');
            continue;
        }

        $tableSchema = sys_schema();
        if (isset($schemaCfg[$table]['schema']) && $schemaCfg[$table]['schema'] !== '') {
            $tableSchema = (string)$schemaCfg[$table]['schema'];
        }

        $schemaIdentifier  = pg_ident($tableSchema);
        $tableIdentifier   = pg_ident($table);
        $columnIdentifier  = pg_ident($column);
        $dateColumnIdentifier = pg_ident($dateColumn);

        if ($dryRun) {
            $sql = "SELECT COUNT(*) AS cnt FROM {$schemaIdentifier}.{$tableIdentifier}
                    WHERE {$columnIdentifier} IS NOT NULL AND {$columnIdentifier} != \$1
                      AND {$dateColumnIdentifier} < NOW() - (\$2::int * INTERVAL '1 day')";
            $result = @pg_query_params($conn, $sql, [$replacement, $days]);
            if (!$result) {
                $databaseError = pg_last_error($conn);
                error_log('[cron_anonymization] Preview failed on ' . $table . '.' . $column . ': ' . $databaseError);
                anon_log("[anonymization] ERROR previewing {$table}.{$column} — check server error log.");
                if ($errorMessage === null) {
                    $errorMessage = 'Error previewing ' . $table . '.' . $column;
                }
                continue;
            }
            $cntRow          = pg_fetch_assoc($result);
            $wouldAffect     = (int)($cntRow['cnt'] ?? 0);
            $rowsAnonymized += $wouldAffect;
            $rulesProcessed++;
            anon_log("[anonymization] {$tableSchema}.{$table}.{$column}"
                . " (date: {$dateColumn}, older than {$days} days):"
                . " {$wouldAffect} row(s) would be anonymized.");
            continue;
        }

        anon_log("[anonymization] Rule: {$tableSchema}.{$table}.{$column}"
            . " (date: {$dateColumn}, older than {$days} days) -> '{$replacement}'");

        $sql = "UPDATE {$schemaIdentifier}.{$tableIdentifier}
                SET {$columnIdentifier} = \$1
                WHERE {$columnIdentifier} IS NOT NULL AND {$columnIdentifier} != \$1
                  AND {$dateColumnIdentifier} < NOW() - (\$2::int * INTERVAL '1 day')";

        $result = @pg_query_params($conn, $sql, [$replacement, $days]);
        if (!$result) {
            $databaseError = pg_last_error($conn);
            error_log('[cron_anonymization] Update failed on ' . $table . '.' . $column . ': ' . $databaseError);
            anon_log("[anonymization] ERROR on {$table}.{$column} — check server error log.");
            if ($errorMessage === null) {
                $errorMessage = 'Error processing ' . $table . '.' . $column;
            }
            continue;
        }

        $affected        = pg_affected_rows($result);
        $rowsAnonymized += $affected;
        $rulesProcessed++;

        $reportDetails[] = [
            'table_name'  => $table,
            'schema_name' => $tableSchema,
            'column_name' => $column,
            'method'      => 'static_replacement',
            'parameters'  => [
                'replacement_value' => $replacement,
                'date_column'       => $dateColumn,
                'retention_days'    => $days,
            ],
            'is_reversible'   => false,
            'rows_affected'   => $affected,
            'edpb_compliance' => anon_edpb_assessment('static_replacement'),
        ];

        anon_log("[anonymization] Updated {$affected} row(s) in {$tableSchema}.{$table}.{$column}.");
    }

    $finalStatus = $errorMessage !== null ? 'error' : 'success';

    if ($logId !== null) {
        @pg_query_params(
            $conn,
            "UPDATE {$anonymizationLogTable}
             SET finished_at = now(), status = \$1, rules_processed = \$2, rows_anonymized = \$3, error_message = \$4
             WHERE id = \$5",
            [$finalStatus, $rulesProcessed, $rowsAnonymized, $errorMessage, $logId]
        );

        $affectedTables = [];
        foreach ($reportDetails as $detail) {
            if ($detail['rows_affected'] > 0) {
                $affectedTables[$detail['schema_name'] . '.' . $detail['table_name']] = true;
            }
        }

        $report = [
            'report_id'         => sprintf('JOB-%s-%04d', date('Ymd'), $logId),
            'timestamp'         => gmdate('Y-m-d\TH:i:s\Z'),
            'system'            => 'opensparrow',
            'version'           => defined('OPENSPARROW_VERSION') ? OPENSPARROW_VERSION : null,
            'triggered_by'      => $triggeredBy,
            'status'            => $finalStatus,
            'execution_summary' => [
                'total_rules_processed' => $rulesProcessed,
                'total_tables_affected' => count($affectedTables),
                'total_rows_affected'   => $rowsAnonymized,
            ],
            'details'           => array_values($reportDetails),
        ];
        if ($errorMessage !== null) {
            $report['error_message'] = $errorMessage;
        }

        $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($reportJson !== false) {
            $reportResult = @pg_query_params(
                $conn,
                "INSERT INTO {$anonymizationReportTable} (log_id, report_id,"
                . " triggered_by, status, rows_affected, report)
                 VALUES (\$1, \$2, \$3, \$4, \$5, \$6::jsonb)",
                [$logId, $report['report_id'], $triggeredBy, $finalStatus, $rowsAnonymized, $reportJson]
            );
            if (!$reportResult) {
                error_log('[cron_anonymization] Could not persist report — '
                    . 'run Initialize System Tables to create the report table.');
                anon_log('[anonymization] WARNING: report table missing — run Initialize System Tables.');
            } else {
                anon_log('[anonymization] Report ' . $report['report_id'] . ' saved to ' . $anonymizationReportTable
                    . ' (run #' . $logId . ').');
                anon_log(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }

    if ($dryRun) {
        anon_log("[anonymization] DRY RUN complete. Rules previewed: {$rulesProcessed},"
            . " total rows that would be anonymized: {$rowsAnonymized}.");
        anon_log('[anonymization] No data was modified.');
        return $errorMessage !== null ? 1 : 0;
    }

    anon_log("[anonymization] Done. Rules processed: {$rulesProcessed}, rows anonymized: {$rowsAnonymized}.");
    anon_log('[anonymization] Status: ' . $finalStatus);

    return 0;
}

exit(cron_anonymization_main($argv));
