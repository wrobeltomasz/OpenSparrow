<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class ImportRepository
{
    public function __construct(private readonly \PgSql\Connection $conn)
    {
    }

    public function createRecord(
        int $userId,
        string $filename,
        string $tableName,
        array $mapping,
        ?string $conflictColumn
    ): int {
        $sql = 'INSERT INTO ' . sys_table('imports')
            . ' (user_id, filename, target_table, column_mapping, conflict_column, status)'
            . ' VALUES ($1,$2,$3,$4,$5,$6) RETURNING id';
        $result = @pg_query_params($this->conn, $sql, [
            $userId, $filename, $tableName,
            json_encode($mapping), $conflictColumn, 'running',
        ]);
        if ($result === false) {
            error_log('[csv_import] createRecord failed: ' . pg_last_error($this->conn));
            throw new \AdminApiMessage(
                'Failed to create import record. Check that spw_imports table exists (run Initialize System Tables).'
            );
        }
        return (int) pg_fetch_row($result)[0];
    }

    public function finalize(
        int $importId,
        string $status,
        int $total,
        int $imported,
        int $skipped,
        ?string $errorMessage = null
    ): void {
        $sql = 'UPDATE ' . sys_table('imports')
            . ' SET status=$1,total_rows=$2,imported_rows=$3,skipped_rows=$4,error_message=$5,finished_at=now()'
            . ' WHERE id=$6';
        @pg_query_params($this->conn, $sql, [$status, $total, $imported, $skipped, $errorMessage, $importId]);
    }

    public function logRows(int $importId, array $rowErrors): void
    {
        if (empty($rowErrors)) {
            return;
        }
        $logTable    = sys_table('import_rows_log');
        $placeholders   = [];
        $arguments = [];
        $placeholderIndex    = 1;
        foreach ($rowErrors as $entry) {
            $placeholders[]   = "(\${$placeholderIndex},\$" . ($placeholderIndex + 1)
                . ",\$" . ($placeholderIndex + 2)
                . ",\$" . ($placeholderIndex + 3) . ')';
            $arguments[] = $importId;
            $arguments[] = $entry['row_number'];
            $arguments[] = json_encode($entry['raw_data']);
            $arguments[] = $entry['error'];
            $placeholderIndex += 4;
        }
        $sql = "INSERT INTO {$logTable} (import_id,row_number,raw_data,error_message) VALUES "
            . implode(',', $placeholders);
        @pg_query_params($this->conn, $sql, $arguments);
    }

    public function getHistory(): array
    {
        $importsTable = sys_table('imports');
        $usersTable = sys_table('users');
        $sql = "SELECT i.id,i.filename,i.target_table,i.status,i.total_rows,i.imported_rows,
                       i.skipped_rows,i.started_at,i.finished_at,u.username
                FROM {$importsTable} i
                LEFT JOIN {$usersTable} u ON u.id=i.user_id
                ORDER BY i.started_at DESC LIMIT 100";
        $result = @pg_query($this->conn, $sql);
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getRowLog(int $importId): array
    {
        $logTable   = sys_table('import_rows_log');
        $result = @pg_query_params(
            $this->conn,
            "SELECT row_number,raw_data,error_message,logged_at FROM {$logTable}"
            . " WHERE import_id=\$1 ORDER BY row_number ASC",
            [$importId]
        );
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
}
