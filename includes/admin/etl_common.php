<?php

declare(strict_types=1);

// includes/admin/etl_common.php — shared helpers for the ETL admin api.php modules
// (etl.php and etl_flow.php). Both include this file; it factors out the run-cron-script
// dispatcher and the log-purge query those two modules used to copy verbatim.
// Runs in the front controller's scope — each helper emits JSON and exits.

/**
 * ETL-flavoured argument order for admin_run_cron_script() (includes/admin/helpers.php),
 * which is now the single implementation shared with cron.php and anonymization.php.
 * Kept so the two ETL modules keep their existing call shape. Never returns.
 */
function etl_admin_run_cron_script(string $absScriptPath, string $itemId, string $notFoundMsg): never
{
    admin_run_cron_script($absScriptPath, $notFoundMsg, $itemId);
}
