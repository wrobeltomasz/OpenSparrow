<?php

// cron/cron_anonymization.php — Data anonymization worker
// CLI-only. Reads config/anonymization.json and runs UPDATE statements for each rule.
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

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$triggeredBy = ($argv[1] ?? '') === 'admin' ? 'admin' : 'cron';

anon_log('[anonymization] Starting (' . $triggeredBy . ')...');

$configPath = __DIR__ . '/../config/anonymization.json';
if (!is_file($configPath)) {
    anon_log('[anonymization] Config file not found. Create it via Admin > Anonymization.');
    exit(1);
}

$raw = @file_get_contents($configPath);
if ($raw === false) {
    anon_log('[anonymization] Cannot read config file.');
    exit(1);
}

$config = @json_decode($raw, true);
if (!is_array($config)) {
    anon_log('[anonymization] Invalid JSON in anonymization.json.');
    exit(1);
}

$enabled   = (bool)($config['enabled'] ?? false);
$frequency = (string)($config['frequency'] ?? 'daily');
$rules     = (array)($config['rules']    ?? []);

if (!$enabled) {
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

$tLog = sys_table('anonymization_log');

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

// Insert log entry.
$logId  = null;
$logRes = @pg_query_params(
    $conn,
    "INSERT INTO {$tLog} (triggered_by, status) VALUES (\$1, 'running') RETURNING id",
    [$triggeredBy]
);
if ($logRes && ($logRow = pg_fetch_assoc($logRes))) {
    $logId = (int)$logRow['id'];
}

$rulesProcessed = 0;
$rowsAnonymized = 0;
$errorMessage   = null;

// Load schema.json once for per-table schema lookup.
$schemaPath = __DIR__ . '/../config/schema.json';
$schemaCfg  = [];
if (is_file($schemaPath)) {
    $schemaRaw = @file_get_contents($schemaPath);
    if ($schemaRaw !== false) {
        $decoded = @json_decode($schemaRaw, true);
        if (is_array($decoded) && isset($decoded['tables'])) {
            $schemaCfg = $decoded['tables'];
        }
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
}

anon_log("[anonymization] Done. Rules processed: {$rulesProcessed}, rows anonymized: {$rowsAnonymized}.");
anon_log('[anonymization] Status: ' . $finalStatus);
