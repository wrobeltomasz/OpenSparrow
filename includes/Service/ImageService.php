<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use PgSql\Connection;

final class ImageService
{
    public const FIELD = '__image';

    public const GRID_LIMIT = 4;

    public const MAX_PER_RECORD = 50;

    public function __construct(private readonly Connection $conn)
    {
    }

    public static function config(array $schema, string $table): ?array
    {
        $config = $schema['tables'][$table]['images'] ?? null;
        if (!is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $maxPerRecord = (int) ($config['max_per_record'] ?? 10);
        if ($maxPerRecord < 1) {
            $maxPerRecord = 1;
        } elseif ($maxPerRecord > self::MAX_PER_RECORD) {
            $maxPerRecord = self::MAX_PER_RECORD;
        }

        return [
            'enabled'        => true,
            'label'          => (string) ($config['label'] ?? ''),
            'max_per_record' => $maxPerRecord,
            'show_in_grid'   => (bool) ($config['show_in_grid'] ?? true),
        ];
    }

    public function forRecord(string $table, int $recordId): array
    {
        $sql = 'SELECT uuid, name, display_name, size_bytes, created_at
                  FROM ' . \sys_table('files') . '
                 WHERE related_table = $1
                   AND related_id = $2
                   AND related_field = $3
                   AND deleted_at IS NULL
                 ORDER BY id';

        $result = @pg_query_params($this->conn, $sql, [$table, $recordId, self::FIELD]);
        if (!$result) {
            error_log('ImageService::forRecord failed: ' . pg_last_error($this->conn));
            return [];
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function countForRecord(string $table, int $recordId): int
    {
        $sql = 'SELECT COUNT(*) AS total
                  FROM ' . \sys_table('files') . '
                 WHERE related_table = $1
                   AND related_id = $2
                   AND related_field = $3
                   AND deleted_at IS NULL';

        $result = @pg_query_params($this->conn, $sql, [$table, $recordId, self::FIELD]);
        if (!$result) {
            error_log('ImageService::countForRecord failed: ' . pg_last_error($this->conn));
            return 0;
        }
        return (int) (pg_fetch_result($result, 0, 'total') ?: 0);
    }

    public function forRows(string $table, array $ids, int $perRow = self::GRID_LIMIT): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = 'SELECT related_id, uuid, name, display_name
                  FROM ' . \sys_table('files') . '
                 WHERE related_table = $1
                   AND related_field = $2
                   AND deleted_at IS NULL
                   AND related_id = ANY($3::int[])
                 ORDER BY related_id, id';

        $result = @pg_query_params($this->conn, $sql, [$table, self::FIELD, Sql::intArray($ids)]);
        if (!$result) {
            error_log('ImageService::forRows failed: ' . pg_last_error($this->conn));
            return [];
        }

        $grouped = [];
        while ($row = pg_fetch_assoc($result)) {
            $relatedId = (string) $row['related_id'];
            if (!isset($grouped[$relatedId])) {
                $grouped[$relatedId] = ['items' => [], 'total' => 0];
            }
            $grouped[$relatedId]['total']++;
            if (count($grouped[$relatedId]['items']) < $perRow) {
                $grouped[$relatedId]['items'][] = [
                    'uuid' => (string) $row['uuid'],
                    'name' => (string) ($row['display_name'] ?: $row['name']),
                ];
            }
        }
        return $grouped;
    }
}
