<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/etl_engine.php';

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

function etl_cli_log(string $msg): void
{
    echo $msg . "\n";
    echo str_pad('', 4096) . "\n";
    flush();
}

function etl_interval_expr(string $frequency): string
{
    return ['daily' => '1 day', 'weekly' => '7 days', 'monthly' => '30 days'][$frequency] ?? '1 day';
}

function etl_log_table_ready(\PgSql\Connection $conn, string $table): bool
{
    return @pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0") !== false;
}
