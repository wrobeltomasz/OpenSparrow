<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const EXTERNAL_API_STATS_RETENTION_DAYS = 90;

function external_api_stats_record(
    \PgSql\Connection $conn,
    array $api,
    int $durationMs,
    ?int $rowsReturned
): void {
    $table = sys_table('external_api_log');
    $result = @pg_query_params(
        $conn,
        "INSERT INTO {$table} (api_id, api_name, table_name, status, rows_returned, duration_ms)
         VALUES (\$1, \$2, \$3, 'ok', \$4, \$5)",
        [
            mb_substr((string) ($api['id'] ?? ''), 0, 64),
            mb_substr((string) ($api['name'] ?? ''), 0, 255),
            mb_substr((string) ($api['table'] ?? ''), 0, 100),
            $rowsReturned,
            $durationMs,
        ]
    );
    if (!$result) {
        error_log('[external_api_stats] insert failed: ' . pg_last_error($conn));
    }
}

function external_api_stats_purge_expired(\PgSql\Connection $conn): ?int
{
    $table = sys_table('external_api_log');
    if (!@pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0")) {
        return null;
    }

    $result = @pg_query_params(
        $conn,
        "DELETE FROM {$table} WHERE created_at < NOW() - (\$1 || ' days')::interval",
        [EXTERNAL_API_STATS_RETENTION_DAYS]
    );
    return $result ? pg_affected_rows($result) : null;
}
