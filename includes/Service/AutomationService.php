<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use PgSql\Connection;

final class AutomationService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function captureOldRecord(
        string $schemaName,
        string $table,
        int $recordId,
        string $event = 'update'
    ): ?array {
        return \auto_capture_old_record($this->conn, $schemaName, $table, $recordId, $event);
    }

    public function evaluate(
        string $schemaName,
        string $table,
        int $recordId,
        string $event,
        int $userId,
        ?array $oldRecord = null
    ): void {
        \evaluate_automation_rules($this->conn, $schemaName, $table, $recordId, $event, $userId, $oldRecord);
    }
}
