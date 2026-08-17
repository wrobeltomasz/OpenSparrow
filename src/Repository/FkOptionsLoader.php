<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\TableConfig;
use App\Persistence\PgConnection;
use App\Persistence\Identifier;

final readonly class FkOptionsLoader
{
    public function __construct(private PgConnection $conn)
    {
    }

    public function load(array $foreignKeyConfig, array $rawSchema): array
    {
        $referencedTable  = $foreignKeyConfig['reference_table'];
        $referencedPrimaryKey     = $foreignKeyConfig['reference_column'] ?? 'id';
        $referencedSchema = $rawSchema['tables'][$referencedTable]['schema'] ?? 'public';

        $dispRaw = is_array($foreignKeyConfig['display_column'] ?? null)
            ? $foreignKeyConfig['display_column']
            : [$foreignKeyConfig['display_column'] ?? $referencedPrimaryKey];
        if (empty($dispRaw)) {
            $dispRaw = [$referencedPrimaryKey];
        }

        $referenceColumnsSql  = implode(', ', array_map([Identifier::class, 'quote'], $dispRaw));
        $orderColumnSql = Identifier::quote($dispRaw[0]);
        $sql = sprintf(
            'SELECT %s, %s FROM %s ORDER BY %s ASC',
            Identifier::quote($referencedPrimaryKey),
            $referenceColumnsSql,
            Identifier::quoteQualified($referencedSchema, $referencedTable),
            $orderColumnSql
        );

        try {
            $result = $this->conn->exec($sql);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(sprintf(
                'Foreign key configuration error: display column(s) [%s] not found on table "%s"."%s" '
                . '(reference_column "%s"). Check the FK settings in the schema editor. Original error: %s',
                implode(', ', $dispRaw),
                $referencedSchema,
                $referencedTable,
                $referencedPrimaryKey,
                $exception->getMessage()
            ), 0, $exception);
        }
        $options = [];
        while ($row = pg_fetch_assoc($result)) {
            $parts = [];
            foreach ($dispRaw as $dateColumn) {
                if (isset($row[$dateColumn]) && $row[$dateColumn] !== '') {
                    $parts[] = $row[$dateColumn];
                }
            }
            $options[$row[$referencedPrimaryKey]] = implode(' - ', $parts) ?: $row[$referencedPrimaryKey];
        }
        return $options;
    }

    public function expandDisplay(TableConfig $config, array $rows, array $rawSchema): array
    {
        if (empty($rows) || empty($config->foreignKeys)) {
            return $rows;
        }

        foreach ($config->foreignKeys as $fkColumn => $foreignKeyConfig) {
            $fkValues = array_unique(
                array_filter(array_column($rows, $fkColumn), fn($value) => $value !== null && $value !== '')
            );
            if (empty($fkValues)) {
                continue;
            }

            $referencedTable  = $foreignKeyConfig['reference_table'];
            $referencedPrimaryKey     = $foreignKeyConfig['reference_column'] ?? 'id';
            $referencedSchema = $rawSchema['tables'][$referencedTable]['schema'] ?? 'public';

            $dispRaw = is_array($foreignKeyConfig['display_column'] ?? null)
                ? $foreignKeyConfig['display_column']
                : [$foreignKeyConfig['display_column'] ?? $referencedPrimaryKey];
            if (empty($dispRaw)) {
                $dispRaw = [$referencedPrimaryKey];
            }

            $escapedColumns = array_map([Identifier::class, 'quote'], $dispRaw);
            $displaySql     = count($escapedColumns) > 1
                ? 'CONCAT_WS(\' - \', ' . implode(', ', $escapedColumns) . ')'
                : $escapedColumns[0];

            $escapedValues = array_map(
                fn($value) => pg_escape_literal($this->conn->native(), (string)$value),
                $fkValues
            );

            $sql = sprintf(
                'SELECT %s AS id, %s AS disp FROM %s WHERE %s IN (%s)',
                Identifier::quote($referencedPrimaryKey),
                $displaySql,
                Identifier::quoteQualified($referencedSchema, $referencedTable),
                Identifier::quote($referencedPrimaryKey),
                implode(', ', $escapedValues)
            );

            $map = [];
            $result = pg_query($this->conn->native(), $sql);
            if ($result) {
                while ($row = pg_fetch_assoc($result)) {
                    $map[$row['id']] = $row['disp'];
                }
                pg_free_result($result);
            } else {
                error_log(sprintf(
                    '[FkOptionsLoader] Foreign key configuration error: display column(s) [%s] not found on '
                    . 'table "%s"."%s" (reference_column "%s"). Check the FK settings in the schema editor. '
                    . 'Original error: %s',
                    implode(', ', $dispRaw),
                    $referencedSchema,
                    $referencedTable,
                    $referencedPrimaryKey,
                    trim(pg_last_error($this->conn->native()))
                ));
            }

            foreach ($rows as &$row) {
                if (isset($row[$fkColumn]) && array_key_exists($row[$fkColumn], $map)) {
                    $row[$fkColumn . '__display'] = $map[$row[$fkColumn]];
                }
            }
            unset($row);
        }
        return $rows;
    }
}
