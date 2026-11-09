<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use PgSql\Connection;

final class RecordSnapshotService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function recordJson(string $schemaName, string $table, int $recordId): ?string
    {
        $reference = Sql::qualified($schemaName, $table);
        $result = pg_query_params(
            $this->conn,
            "SELECT row_to_json(t) FROM (SELECT * FROM {$reference} WHERE id = \$1) t",
            [$recordId]
        );
        if (!$result) {
            return null;
        }
        $row = pg_fetch_row($result);
        return ($row && $row[0] !== null) ? $row[0] : null;
    }

    public function capture(string $schemaName, string $table, int $recordId, int $logId): void
    {
        $json = $this->recordJson($schemaName, $table, $recordId);
        if ($json === null) {
            return;
        }
        @pg_query_params(
            $this->conn,
            'INSERT INTO ' . \sys_table('record_snapshots')
                . ' (log_id, table_name, record_id, snapshot) VALUES ($1, $2, $3, $4)',
            [$logId, $table, $recordId, $json]
        );
    }

    public function recentChanges(string $table, int $recordId): ?array
    {
        $result = pg_query_params(
            $this->conn,
            'SELECT snapshot, created_at FROM ' . \sys_table('record_snapshots')
                . ' WHERE table_name = $1 AND record_id = $2 ORDER BY id DESC LIMIT 2',
            [$table, $recordId]
        );
        if (!$result) {
            return null;
        }
        $rows = pg_fetch_all($result);
        if (!$rows || count($rows) < 2) {
            return null;
        }

        $current  = json_decode($rows[0]['snapshot'], true);
        $previous = json_decode($rows[1]['snapshot'], true);
        if (!is_array($current) || !is_array($previous)) {
            return null;
        }

        $changes = [];
        foreach ($current as $column => $value) {
            $before = $previous[$column] ?? null;
            if ($before === $value) {
                continue;
            }
            $changes[] = [
                'column' => $column,
                'old'    => self::displayValue($before),
                'new'    => self::displayValue($value),
            ];
        }

        return [
            'changed_at' => $rows[0]['created_at'] ?? null,
            'changes'    => $changes,
        ];
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
