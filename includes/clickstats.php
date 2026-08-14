<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/clickstats.php — shared Click Statistics settings and retention sweep.
//
// Included by the admin module (includes/admin/clickstats.php), the collector
// endpoint (public/api/clickstats.php) and the notifications cron, so the three
// cannot disagree about what the stored config means or when a row has expired.
// Holds no action dispatch and emits nothing: safe to include from CLI.

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/db.php';

// Automatic retention applied by the notifications cron, in days.
//
// This log has no other expiry, so a default of "keep everything" would mean an
// admin who switches collection on and forgets grows spw_clickstats without bound —
// it is the one module of its kind with no worker of its own. 90 days leaves the
// question the module exists to answer ("which elements are actually used?") fully
// answerable while bounding the table. Opting out is explicit, not the default.
const CLICKSTATS_DEFAULT_RETENTION_DAYS = 90;

// retention_days value meaning "never expire automatically" — manual purge only.
const CLICKSTATS_RETENTION_FOREVER = 0;

// Upper bound on a retention window (10 years). Mirrors ADMIN_PURGE_MAX_DAYS in
// includes/admin/helpers.php, which enforces the same ceiling on the manual purge;
// duplicated because that file needs the admin request context and this one runs
// from cron. ClickstatsSettingsTest asserts the two stay equal.
const CLICKSTATS_MAX_RETENTION_DAYS = 3650;

/**
 * Normalise a stored or submitted retention window to a whole number of days
 * between 1 and CLICKSTATS_MAX_RETENTION_DAYS, or CLICKSTATS_RETENTION_FOREVER.
 *
 * An unusable value falls back to the default rather than to "keep everything":
 * a typo or a client bug must not quietly remove the only automatic bound on the
 * table. Absence means the same — a config written before retention existed has
 * no such key, and those installs are exactly the ones that need the bound.
 */
function clickstats_retention_days(mixed $raw): int
{
    if (!is_int($raw) && !is_string($raw)) {
        return CLICKSTATS_DEFAULT_RETENTION_DAYS;
    }
    $days = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => CLICKSTATS_MAX_RETENTION_DAYS]]
    );
    return $days === false ? CLICKSTATS_DEFAULT_RETENTION_DAYS : $days;
}

/**
 * The stored config, normalised so no caller has to guess a default.
 * $absentTtl is passed through to config_get() — see the note there.
 *
 * @return array{enabled: bool, track_records: bool, retention_days: int}
 */
function clickstats_settings(int $absentTtl = 0): array
{
    $cfg = config_get('clickstats', $absentTtl) ?? [];
    return [
        'enabled'        => !empty($cfg['enabled']),
        'track_records'  => $cfg['track_records'] ?? true,
        'retention_days' => clickstats_retention_days($cfg['retention_days'] ?? null),
    ];
}

/**
 * Delete recorded clicks past the configured retention window. Returns the number
 * of rows removed, or null when nothing was done — the window is set to "forever",
 * the table does not exist yet (migration not run), or the DELETE failed.
 *
 * Deliberately not conditional on collection being enabled: switching the module
 * off must not freeze whatever it already recorded into the table permanently.
 * Failures are silent by design — this is housekeeping inside a cron whose actual
 * job is notifications, and it must never take that run down with it.
 */
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

    $res = @pg_query_params(
        $conn,
        "DELETE FROM {$table} WHERE created_at < NOW() - (\$1 || ' days')::interval",
        [$days]
    );
    return $res ? pg_affected_rows($res) : null;
}
