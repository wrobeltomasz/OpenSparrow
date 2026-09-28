<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

const BACKUP_MAX_KEEP = 100;

const BACKUP_MAX_RETENTION_DAYS = 3650;

function backup_settings(): array
{
    $config = config_get('backup');
    $keepCount = (int) ($config['keep_count'] ?? 0);
    $retentionDays = (int) ($config['retention_days'] ?? 0);
    return [
        'keep_count'     => $keepCount >= 1 && $keepCount <= BACKUP_MAX_KEEP ? $keepCount : 0,
        'retention_days' => $retentionDays >= 1 && $retentionDays <= BACKUP_MAX_RETENTION_DAYS ? $retentionDays : 0,
    ];
}

function backup_all_copies(\PgSql\Connection $conn): array
{
    $sql = 'SELECT n.nspname AS table_schema, c.relname AS table_name'
        . ' FROM pg_class c'
        . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
        . " WHERE c.relname ~ '^[0-9]{12}_[0-9a-zA-Z_]+$'"
        . " AND c.relkind IN ('r', 'p')"
        . ' ORDER BY n.nspname, c.relname DESC';
    $result = @pg_query($conn, $sql);
    if (!$result) {
        admin_db_fail($conn, 'backup_all_copies');
    }
    return admin_fetch_all($result);
}

function backup_find_copies(\PgSql\Connection $conn, string $schemaName, string $tableName): array
{
    $sql = 'SELECT c.relname AS backup_name'
        . ' FROM pg_class c'
        . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
        . ' WHERE n.nspname = $1'
        . " AND c.relname ~ ('^([0-9]{12})_' || \$2 || '(\$|[^0-9a-zA-Z_])')"
        . " AND c.relkind IN ('r', 'p')"
        . ' ORDER BY c.relname DESC';
    $result = @pg_query_params($conn, $sql, [$schemaName, $tableName]);
    if (!$result) {
        admin_db_fail($conn, 'backup_find_copies');
    }
    return admin_fetch_all($result);
}

function backup_drop_tables(\PgSql\Connection $conn, string $schemaName, array $tableNames): int
{
    $dropped = 0;
    foreach ($tableNames as $tableName) {
        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable = pg_escape_identifier($conn, $tableName);
        if (@pg_query($conn, "DROP TABLE IF EXISTS $safeSchema.$safeTable")) {
            $dropped++;
        } else {
            $dropContext = $schemaName . '.' . $tableName;
            error_log('[backup_cleanup] failed to drop ' . $dropContext . ': ' . pg_last_error($conn));
        }
    }
    return $dropped;
}

function backup_stale_copies(string $tableName, array $existingCopies): array
{
    $settings = backup_settings();
    if ($settings === ['keep_count' => 0, 'retention_days' => 0]) {
        return [];
    }

    $matched = [];
    foreach ($existingCopies as $copyRow) {
        $copyName = (string) $copyRow['backup_name'];
        $pattern = '/^(\d{12})_' . preg_quote($tableName, '/') . '($|[^0-9a-zA-Z_])/';
        if (!preg_match($pattern, $copyName, $matches)) {
            continue;
        }
        $matched[] = ['name' => $copyName, 'timestamp' => $matches[1]];
    }
    if ($matched === []) {
        return [];
    }

    $staleNames = [];
    if ($settings['keep_count'] >= 1 && count($matched) > $settings['keep_count']) {
        foreach (array_slice($matched, $settings['keep_count']) as $copyEntry) {
            $staleNames[$copyEntry['name']] = true;
        }
    }
    if ($settings['retention_days'] >= 1) {
        $cutoff = new DateTimeImmutable('-' . $settings['retention_days'] . ' days');
        foreach ($matched as $copyEntry) {
            $copyDate = DateTimeImmutable::createFromFormat('YmdHi', $copyEntry['timestamp']);
            if ($copyDate !== false && $copyDate < $cutoff) {
                $staleNames[$copyEntry['name']] = true;
            }
        }
    }
    return array_keys($staleNames);
}

function backup_apply_retention(\PgSql\Connection $conn, string $schemaName, string $tableName): int
{
    $existingCopies = backup_find_copies($conn, $schemaName, $tableName);
    if ($existingCopies === []) {
        return 0;
    }
    $stale = backup_stale_copies($tableName, $existingCopies);
    if ($stale === []) {
        return 0;
    }
    return backup_drop_tables($conn, $schemaName, $stale);
}
