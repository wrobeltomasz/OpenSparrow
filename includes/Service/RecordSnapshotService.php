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
}
