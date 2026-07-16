<?php

// cron/cron_anonymization.php — Data anonymization worker
// CLI-only. Reads the "anonymization" config (spw_config store, legacy
// config/anonymization.json fallback) and runs UPDATE statements for each rule.
// Logs each execution to spw_anonymization_log.
// Usage: php cron_anonymization.php [admin]

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);

function anon_log(string $msg): void
{
    echo $msg . "\n";
    echo str_pad('', 4096) . "\n";
    flush();
}

/**
 * Map an anonymization technique to an EDPB-style residual-risk assessment
 * (singling-out / linkability / inference), per EDPB/WP29 WP216 criteria.
 * OpenSparrow currently applies retention-based static replacement only;
 * the match keeps the report extensible for future techniques.
 */
function anon_edpb_assessment(string $method): array
{
    return match ($method) {
        // Column value is irreversibly overwritten with a constant token:
        // that attribute can no longer single out a data subject, but other
        // remaining attributes may still allow linkage / inference.
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
    exit(1);
}

$enabled   = (bool)($config['enabled'] ?? false);
$frequency = (string)($config['frequency'] ?? 'daily');
$rules     = (array)($config['rules']    ?? []);

if (!$enabled && !$dryRun) {
    anon_log('[anonymization] Module is disabled. Exiting.');
    exit(0);
}

if (empty($rules)) {
    anon_log('[anonymization] No rules configured. Exiting.');
    exit(0);
}

try {
    $conn = db_connect();
} catch (\RuntimeException $e) {
    anon_log('[anonymization] DB connection failed: ' . $e->getMessage());
    exit(1);
}

$tLog    = sys_table('anonymization_log');
$tReport = sys_table('anonymization_report');

// Frequency guard — skip if a successful run already occurred within the window.
if ($triggeredBy === 'cron' && $frequency !== 'manual') {
    $intervalMap = ['daily' => '1 day', 'weekly' => '7 days', 'monthly' => '30 days'];
    $interval    = $intervalMap[$frequency] ?? '1 day';
    $recentRes   = @pg_query_params(
        $conn,
        "SELECT 1 FROM {$tLog} WHERE status = 'success' AND started_at >= NOW() - INTERVAL '{$interval}' LIMIT 1",
        []
    );
    if ($recentRes && pg_num_rows($recentRes) > 0) {
        anon_log("[anonymization] Skipping: a successful run exists within the '{$frequency}' window.");
        exit(0);
    }
}

if ($frequency === 'manual' && $triggeredBy === 'cron') {
    anon_log('[anonymization] Frequency set to manual — only runs when triggered via admin panel.');
    exit(0);
}

// Insert log entry (skipped for dry runs — previews are not audited).
$logId = null;
if (!$dryRun) {
    $logRes = @pg_query_params(
        $conn,
        "INSERT INTO {$tLog} (triggered_by, status) VALUES (\$1, 'running') RETURNING id",
        [$triggeredBy]
    );
    if ($logRes && ($logRow = pg_fetch_assoc($logRes))) {
        $logId = (int)$logRow['id'];
    }
}

$rulesProcessed = 0;
$rowsAnonymized = 0;
$errorMessage   = null;
$reportDetails  = []; // per-rule entries for the structured JSON report

// Load the schema config once for per-table schema lookup.
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

    // Determine the DB schema for this table.
    $tableSchema = sys_schema();
    if (isset($schemaCfg[$table]['schema']) && $schemaCfg[$table]['schema'] !== '') {
        $tableSchema = (string)$schemaCfg[$table]['schema'];
    }

    $schemaIdent  = pg_ident($tableSchema);
    $tableIdent   = pg_ident($table);
    $columnIdent  = pg_ident($column);
    $dateColIdent = pg_ident($dateColumn);

    if ($dryRun) {
        $sql = "SELECT COUNT(*) AS cnt FROM {$schemaIdent}.{$tableIdent}
                WHERE {$columnIdent} IS NOT NULL AND {$columnIdent} != \$1
                  AND {$dateColIdent} < NOW() - (\$2::int * INTERVAL '1 day')";
        $res = @pg_query_params($conn, $sql, [$replacement, $days]);
        if (!$res) {
            $dbErr = pg_last_error($conn);
            error_log('[cron_anonymization] Preview failed on ' . $table . '.' . $column . ': ' . $dbErr);
            anon_log("[anonymization] ERROR previewing {$table}.{$column} — check server error log.");
            if ($errorMessage === null) {
                $errorMessage = 'Error previewing ' . $table . '.' . $column;
            }
            continue;
        }
        $cntRow          = pg_fetch_assoc($res);
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

    $sql = "UPDATE {$schemaIdent}.{$tableIdent}
            SET {$columnIdent} = \$1
            WHERE {$columnIdent} IS NOT NULL AND {$columnIdent} != \$1
              AND {$dateColIdent} < NOW() - (\$2::int * INTERVAL '1 day')";

    $res = @pg_query_params($conn, $sql, [$replacement, $days]);
    if (!$res) {
        $dbErr = pg_last_error($conn);
        error_log('[cron_anonymization] Update failed on ' . $table . '.' . $column . ': ' . $dbErr);
        anon_log("[anonymization] ERROR on {$table}.{$column} — check server error log.");
        if ($errorMessage === null) {
            $errorMessage = 'Error processing ' . $table . '.' . $column;
        }
        continue;
    }

    $affected        = pg_affected_rows($res);
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
        "UPDATE {$tLog}
         SET finished_at = now(), status = \$1, rules_processed = \$2, rows_anonymized = \$3, error_message = \$4
         WHERE id = \$5",
        [$finalStatus, $rulesProcessed, $rowsAnonymized, $errorMessage, $logId]
    );

    // Build a structured, GDPR/EDPB-style processing report and persist it as
    // JSON on the log row (report column added in migration 2.9_anonymization_report).
    $affectedTables = [];
    foreach ($reportDetails as $d) {
        if ($d['rows_affected'] > 0) {
            $affectedTables[$d['schema_name'] . '.' . $d['table_name']] = true;
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
        $repRes = @pg_query_params(
            $conn,
            "INSERT INTO {$tReport} (log_id, report_id, triggered_by, status, rows_affected, report)
             VALUES (\$1, \$2, \$3, \$4, \$5, \$6::jsonb)",
            [$logId, $report['report_id'], $triggeredBy, $finalStatus, $rowsAnonymized, $reportJson]
        );
        if (!$repRes) {
            error_log('[cron_anonymization] Could not persist report — '
                . 'run Initialize System Tables to create the report table.');
            anon_log('[anonymization] WARNING: report table missing — run Initialize System Tables.');
        } else {
            anon_log('[anonymization] Report ' . $report['report_id'] . ' saved to ' . $tReport
                . ' (run #' . $logId . ').');
            anon_log(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}

if ($dryRun) {
    anon_log("[anonymization] DRY RUN complete. Rules previewed: {$rulesProcessed},"
        . " total rows that would be anonymized: {$rowsAnonymized}.");
    anon_log('[anonymization] No data was modified.');
    exit($errorMessage !== null ? 1 : 0);
}

anon_log("[anonymization] Done. Rules processed: {$rulesProcessed}, rows anonymized: {$rowsAnonymized}.");
anon_log('[anonymization] Status: ' . $finalStatus);
