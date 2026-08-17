<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use App\Security\UserRole;
use InvalidArgumentException;
use PgSql\Connection;

final class RecordOwnershipService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public static function isRestricted(array $tableConfig): bool
    {
        return !empty($tableConfig['owner_restricted']);
    }

    public static function restrictionSql(string $idExpression, int $tableParameter, int $ownerParameter): string
    {
        if (!str_contains($idExpression, '.')) {
            throw new InvalidArgumentException(
                'restrictionSql(): $idExpression must be table-qualified (e.g. "_t.id"), got "'
                    . $idExpression . '".'
            );
        }
        $ownersTable = \sys_table('record_owners');
        return " AND NOT EXISTS (SELECT 1 FROM {$ownersTable} ro"
            . " WHERE ro.table_name = \${$tableParameter} AND ro.record_id = {$idExpression}"
            . " AND ro.is_current = true AND ro.owner_id != \${$ownerParameter})";
    }

    public function ownerId(string $table, int $recordId): ?int
    {
        $ownersTable = \sys_table('record_owners');
        $result = @pg_query_params(
            $this->conn,
            "SELECT owner_id FROM {$ownersTable}"
                . ' WHERE table_name = $1 AND record_id = $2 AND is_current = true',
            [$table, $recordId]
        );
        if (!$result || pg_num_rows($result) === 0) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        return $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
    }

    public function canAccess(array $tableConfig, string $table, int $recordId, int $userId, string $role = ''): bool
    {
        if (!self::isRestricted($tableConfig)) {
            return true;
        }
        if ($role === UserRole::Admin->value) {
            return true;
        }
        $ownerId = $this->ownerId($table, $recordId);
        return $ownerId === null || $ownerId === $userId;
    }

    public function filterVisibleIds(array $tableConfig, string $table, array $ids, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!self::isRestricted($tableConfig) || $ids === []) {
            return $ids;
        }

        $ownersTable = \sys_table('record_owners');
        $sql = "SELECT ro.record_id FROM {$ownersTable} ro"
             . ' WHERE ro.table_name = $1 AND ro.is_current = true'
             . ' AND ro.owner_id IS NOT NULL AND ro.owner_id != $2'
             . ' AND ro.record_id = ANY($3::int[])';

        $result = @pg_query_params($this->conn, $sql, [$table, $userId, Sql::intArray($ids)]);
        if (!$result) {
            error_log('filterVisibleIds failed: ' . pg_last_error($this->conn));
            return [];
        }

        $blocked = [];
        while ($row = pg_fetch_assoc($result)) {
            $blocked[] = (int) $row['record_id'];
        }
        pg_free_result($result);

        return $blocked === [] ? $ids : array_values(array_diff($ids, $blocked));
    }

    public function assign(string $table, int $recordId, int $ownerId, int $changedBy): void
    {
        $ownersTable = \sys_table('record_owners');
        @pg_query_params(
            $this->conn,
            "UPDATE {$ownersTable} SET is_current = false"
                . ' WHERE table_name = $1 AND record_id = $2 AND is_current = true',
            [$table, $recordId]
        );
        @pg_query_params(
            $this->conn,
            "INSERT INTO {$ownersTable} (table_name, record_id, owner_id, changed_by, is_current)"
                . ' VALUES ($1, $2, $3, $4, true)',
            [$table, $recordId, $ownerId, $changedBy]
        );
    }
}
