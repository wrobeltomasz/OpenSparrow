<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use PgSql\Connection;

final class M2MService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public static function resolveOtherTable(array $config, array $rawSchema): string
    {
        $otherTable = (string) ($config['other_table'] ?? '');
        if ($otherTable !== '') {
            return $otherTable;
        }
        $junctionTable = (string) ($config['junction_table'] ?? '');
        $otherForeignKey = (string) ($config['other_fk'] ?? '');
        return (string) ($rawSchema['tables'][$junctionTable]['foreign_keys'][$otherForeignKey]['reference_table']
            ?? '');
    }

    public static function junctionParts(array $config): ?array
    {
        $junctionTable = (string) ($config['junction_table'] ?? '');
        $selfForeignKey = (string) ($config['self_fk'] ?? '');
        $otherForeignKey = (string) ($config['other_fk'] ?? '');
        if ($junctionTable === '' || $selfForeignKey === '' || $otherForeignKey === '') {
            return null;
        }
        return [$junctionTable, $selfForeignKey, $otherForeignKey];
    }

    public function options(array $config, array $rawSchema): array
    {
        $otherTable = self::resolveOtherTable($config, $rawSchema);
        if ($otherTable === '') {
            return [];
        }

        $schemaName = (string) ($rawSchema['tables'][$otherTable]['schema'] ?? 'public');
        $displayColumn = (string) ($config['display_column'] ?? 'id');

        $sql = sprintf(
            'SELECT "id", %s AS label FROM %s ORDER BY %s',
            Sql::ident($displayColumn),
            Sql::qualified($schemaName, $otherTable),
            Sql::ident($displayColumn)
        );

        $result = @pg_query($this->conn, $sql);
        if (!$result) {
            error_log('[M2MService::options] ' . pg_last_error($this->conn));
            return [];
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = ['id' => (string) $row['id'], 'label' => (string) $row['label']];
        }
        return $rows;
    }

    public function selected(array $config, int $recordId, array $rawSchema): array
    {
        $parts = self::junctionParts($config);
        if ($parts === null) {
            return [];
        }
        [$junctionTable, $selfForeignKey, $otherForeignKey] = $parts;

        $schemaName = (string) ($rawSchema['tables'][$junctionTable]['schema'] ?? 'public');

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = $1',
            Sql::ident($otherForeignKey),
            Sql::qualified($schemaName, $junctionTable),
            Sql::ident($selfForeignKey)
        );

        $result = @pg_query_params($this->conn, $sql, [$recordId]);
        if (!$result) {
            error_log('[M2MService::selected] ' . pg_last_error($this->conn));
            return [];
        }

        $ids = [];
        while ($row = pg_fetch_assoc($result)) {
            $ids[] = (string) $row[$otherForeignKey];
        }
        return $ids;
    }

    public function sync(array $config, int $recordId, array $selectedIds, array $rawSchema): bool
    {
        $parts = self::junctionParts($config);
        if ($parts === null) {
            return false;
        }
        [$junctionTable, $selfForeignKey, $otherForeignKey] = $parts;

        $schemaName = (string) ($rawSchema['tables'][$junctionTable]['schema'] ?? 'public');
        $reference = Sql::qualified($schemaName, $junctionTable);

        pg_query($this->conn, 'BEGIN');

        $deleteSql = sprintf('DELETE FROM %s WHERE %s = $1', $reference, Sql::ident($selfForeignKey));
        if (!@pg_query_params($this->conn, $deleteSql, [$recordId])) {
            pg_query($this->conn, 'ROLLBACK');
            error_log('[M2MService::sync] delete failed: ' . pg_last_error($this->conn));
            return false;
        }

        $insertSql = sprintf(
            'INSERT INTO %s (%s, %s) VALUES ($1, $2)',
            $reference,
            Sql::ident($selfForeignKey),
            Sql::ident($otherForeignKey)
        );

        foreach ($selectedIds as $otherId) {
            if (!ctype_digit((string) $otherId)) {
                continue;
            }
            if (!@pg_query_params($this->conn, $insertSql, [$recordId, $otherId])) {
                pg_query($this->conn, 'ROLLBACK');
                error_log('[M2MService::sync] insert failed: ' . pg_last_error($this->conn));
                return false;
            }
        }

        pg_query($this->conn, 'COMMIT');
        return true;
    }
}
