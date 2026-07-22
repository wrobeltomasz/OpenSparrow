<?php

declare(strict_types=1);

// includes/admin/etl_common.php — shared helpers for the ETL admin api.php modules
// (etl.php and etl_flow.php). Both include this file; it factors out the run-cron-script
// dispatcher and the log-purge query those two modules used to copy verbatim.
// Runs in the front controller's scope — each helper emits JSON and exits.

/**
 * Validate, invoke a cron worker script with an optional single-item id, and emit the
 * captured output as JSON. Unlike the old inline copies, the run status reflects the
 * worker's exit code, so a failed run reports status:'error' (with the output still
 * included so the admin sees what happened). Never returns — always exits.
 */
function etl_admin_run_cron_script(string $absScriptPath, string $itemId, string $notFoundMsg): void
{
    $cronScript = realpath($absScriptPath);
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => $notFoundMsg]);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $args = 'admin';
    if ($itemId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
        $args .= ' ' . escapeshellarg($itemId);
    }
    $lines = [];
    $code  = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' ' . $args . ' 2>&1', $lines, $code);
    echo json_encode([
        'status'    => $code === 0 ? 'success' : 'error',
        'output'    => implode("\n", $lines),
        'exit_code' => $code,
    ]);
    exit;
}
