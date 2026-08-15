<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/db.php';

const CLICKSTATS_DEFAULT_RETENTION_DAYS = 90;

const CLICKSTATS_RETENTION_FOREVER = 0;

const CLICKSTATS_MAX_RETENTION_DAYS = 3650;

function clickstats_retention_days(mixed $rawDays): int
{
    if (!is_int($rawDays) && !is_string($rawDays)) {
        return CLICKSTATS_DEFAULT_RETENTION_DAYS;
    }
    $days = filter_var(
        $rawDays,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => CLICKSTATS_MAX_RETENTION_DAYS]]
    );
    return $days === false ? CLICKSTATS_DEFAULT_RETENTION_DAYS : $days;
}

function clickstats_settings(int $absentTtl = 0): array
{
    $cfg = config_get('clickstats', $absentTtl) ?? [];
    return [
        'enabled'        => !empty($cfg['enabled']),
        'track_records'  => $cfg['track_records'] ?? true,
        'retention_days' => clickstats_retention_days($cfg['retention_days'] ?? null),
    ];
}

function clickstats_purge_expired(\PgSql\Connection $conn): ?int
{
    $days = clickstats_settings()['retention_days'];
    if ($days === CLICKSTATS_RETENTION_FOREVER) {
        return null;
    }

    $table = sys_table('clickstats');
    if (!@pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0")) {
        return null;
    }

    $result = @pg_query_params(
        $conn,
        "DELETE FROM {$table} WHERE created_at < NOW() - (\$1 || ' days')::interval",
        [$days]
    );
    return $result ? pg_affected_rows($result) : null;
}
