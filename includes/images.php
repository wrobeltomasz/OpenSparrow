<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// includes/images.php — Record image galleries (spw_files rows tagged related_field = IMAGES_FIELD)
// Functions: images_config, images_for_record, images_count, images_for_rows

require_once __DIR__ . '/db.php';

// Discriminator stored in spw_files.related_field to tell gallery images apart from
// ordinary record attachments uploaded through the Files tab.
const IMAGES_FIELD = '__image';

// Max thumbnails fetched per row for the grid column.
const IMAGES_GRID_LIMIT = 4;

/**
 * Normalised image-gallery config for a table, or null when the table has none.
 *
 * @param array $schema Raw "schema" config (config_get('schema')).
 */
function images_config(array $schema, string $table): ?array
{
    $cfg = $schema['tables'][$table]['images'] ?? null;
    if (!is_array($cfg) || empty($cfg['enabled'])) {
        return null;
    }

    $max = (int)($cfg['max_per_record'] ?? 10);
    if ($max < 1) {
        $max = 1;
    } elseif ($max > 50) {
        $max = 50;
    }

    return [
        'enabled'        => true,
        'label'          => (string)($cfg['label'] ?? ''),
        'max_per_record' => $max,
        'show_in_grid'   => (bool)($cfg['show_in_grid'] ?? true),
    ];
}

/**
 * All gallery images of a single record, oldest first.
 *
 * @return array<int, array{uuid:string,name:string,display_name:?string,size_bytes:string,created_at:string}>
 */
function images_for_record(\PgSql\Connection $conn, string $table, int $recordId): array
{
    $sql = 'SELECT uuid, name, display_name, size_bytes, created_at
              FROM ' . sys_table('files') . '
             WHERE related_table = $1
               AND related_id = $2
               AND related_field = $3
               AND deleted_at IS NULL
             ORDER BY id';

    $res = @pg_query_params($conn, $sql, [$table, $recordId, IMAGES_FIELD]);
    if (!$res) {
        error_log('images_for_record failed: ' . pg_last_error($conn));
        return [];
    }

    $out = [];
    while ($row = pg_fetch_assoc($res)) {
        $out[] = $row;
    }
    return $out;
}

/** Number of gallery images attached to a record (used to enforce max_per_record). */
function images_count(\PgSql\Connection $conn, string $table, int $recordId): int
{
    $sql = 'SELECT COUNT(*) AS c
              FROM ' . sys_table('files') . '
             WHERE related_table = $1
               AND related_id = $2
               AND related_field = $3
               AND deleted_at IS NULL';

    $res = @pg_query_params($conn, $sql, [$table, $recordId, IMAGES_FIELD]);
    if (!$res) {
        error_log('images_count failed: ' . pg_last_error($conn));
        return 0;
    }
    return (int)(pg_fetch_result($res, 0, 'c') ?: 0);
}

/**
 * Batch lookup for the grid column: record id => list of images (capped per row).
 *
 * @param  int[] $ids
 * @return array<string, array<int, array{uuid:string,name:string}>>
 */
function images_for_rows(\PgSql\Connection $conn, string $table, array $ids, int $perRow = IMAGES_GRID_LIMIT): array
{
    if (empty($ids)) {
        return [];
    }

    $sql = 'SELECT related_id, uuid, name, display_name
              FROM ' . sys_table('files') . '
             WHERE related_table = $1
               AND related_field = $2
               AND deleted_at IS NULL
               AND related_id = ANY($3::int[])
             ORDER BY related_id, id';

    $pgArray = '{' . implode(',', array_map('intval', $ids)) . '}';
    $res     = @pg_query_params($conn, $sql, [$table, IMAGES_FIELD, $pgArray]);
    if (!$res) {
        error_log('images_for_rows failed: ' . pg_last_error($conn));
        return [];
    }

    $out = [];
    while ($row = pg_fetch_assoc($res)) {
        $rid = (string)$row['related_id'];
        if (!isset($out[$rid])) {
            $out[$rid] = ['items' => [], 'total' => 0];
        }
        $out[$rid]['total']++;
        if (count($out[$rid]['items']) < $perRow) {
            $out[$rid]['items'][] = [
                'uuid' => (string)$row['uuid'],
                'name' => (string)($row['display_name'] ?: $row['name']),
            ];
        }
    }
    return $out;
}
