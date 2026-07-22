<?php

declare(strict_types=1);

// includes/etl_cli.php — shared CLI plumbing for the ETL cron workers
// (cron/cron_etl.php and cron/cron_etl_flow.php). Factors out the identical
// SAPI guard, output-buffer reset, unbuffered logger, frequency-window
// interval mapping and log-table probe those two workers used to each copy.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/etl_engine.php';

/**
 * CLI-only entry guard + unbuffered output setup. Exits 403 when reached over the web
 * SAPI; otherwise disables output buffering so log lines stream to the caller live.
 */
function etl_cli_boot(): void
{
    if (php_sapi_name() !== 'cli') {
        http_response_code(403);
        exit;
    }
    @ini_set('output_buffering', 'off');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_implicit_flush(true);
}

/**
 * Emit one log line and force it out through any proxy/web buffers. The str_pad padding
 * line defeats fixed-size upstream buffers so progress shows in near real time.
 */
function etl_cli_log(string $msg): void
{
    echo $msg . "\n";
    echo str_pad('', 4096) . "\n";
    flush();
}

/**
 * Map a schedule frequency to a PostgreSQL INTERVAL literal for the "already ran in this
 * window?" guard. Unknown/absent frequencies fall back to one day.
 */
function etl_interval_expr(string $frequency): string
{
    return ['daily' => '1 day', 'weekly' => '7 days', 'monthly' => '30 days'][$frequency] ?? '1 day';
}

/**
 * Whether $table exists and is queryable (best-effort probe). Both workers tolerate the
 * spw_etl_* log tables being absent before Initialize System Tables has been run.
 */
function etl_log_table_ready(\PgSql\Connection $conn, string $table): bool
{
    return @pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0") !== false;
}
