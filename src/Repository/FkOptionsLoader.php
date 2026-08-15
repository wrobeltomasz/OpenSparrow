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

    public function load(array $fkCfg, array $rawSchema): array
    {
        $refTable  = $fkCfg['reference_table'];
        $refPk     = $fkCfg['reference_column'] ?? 'id';
        $refSchema = $rawSchema['tables'][$refTable]['schema'] ?? 'public';

        $dispRaw = is_array($fkCfg['display_column'] ?? null)
            ? $fkCfg['display_column']
            : [$fkCfg['display_column'] ?? $refPk];
        if (empty($dispRaw)) {
            $dispRaw = [$refPk];
        }

        $refColsSql  = implode(', ', array_map([Identifier::class, 'quote'], $dispRaw));
        $orderColSql = Identifier::quote($dispRaw[0]);
        $sql = sprintf(
            'SELECT %s, %s FROM %s ORDER BY %s ASC',
            Identifier::quote($refPk),
            $refColsSql,
            Identifier::quoteQualified($refSchema, $refTable),
            $orderColSql
        );

        try {
            $res = $this->conn->exec($sql);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf(
                'Foreign key configuration error: display column(s) [%s] not found on table "%s"."%s" '
                . '(reference_column "%s"). Check the FK settings in the schema editor. Original error: %s',
                implode(', ', $dispRaw),
                $refSchema,
                $refTable,
                $refPk,
                $e->getMessage()
            ), 0, $e);
        }
        $options = [];
        while ($row = pg_fetch_assoc($res)) {
            $parts = [];
            foreach ($dispRaw as $dc) {
                if (isset($row[$dc]) && $row[$dc] !== '') {
                    $parts[] = $row[$dc];
                }
            }
            $options[$row[$refPk]] = implode(' - ', $parts) ?: $row[$refPk];
        }
        return $options;
    }

    public function expandDisplay(TableConfig $cfg, array $rows, array $rawSchema): array
    {
        if (empty($rows) || empty($cfg->foreignKeys)) {
            return $rows;
        }

        foreach ($cfg->foreignKeys as $fkCol => $fkCfg) {
            $fkValues = array_unique(
                array_filter(array_column($rows, $fkCol), fn($v) => $v !== null && $v !== '')
            );
            if (empty($fkValues)) {
                continue;
            }

            $refTable  = $fkCfg['reference_table'];
            $refPk     = $fkCfg['reference_column'] ?? 'id';
            $refSchema = $rawSchema['tables'][$refTable]['schema'] ?? 'public';

            $dispRaw = is_array($fkCfg['display_column'] ?? null)
                ? $fkCfg['display_column']
                : [$fkCfg['display_column'] ?? $refPk];
            if (empty($dispRaw)) {
                $dispRaw = [$refPk];
            }

            $escapedCols = array_map([Identifier::class, 'quote'], $dispRaw);
            $dispSql     = count($escapedCols) > 1
                ? 'CONCAT_WS(\' - \', ' . implode(', ', $escapedCols) . ')'
                : $escapedCols[0];

            $escapedVals = array_map(
                fn($v) => pg_escape_literal($this->conn->native(), (string)$v),
                $fkValues
            );

            $sql = sprintf(
                'SELECT %s AS id, %s AS disp FROM %s WHERE %s IN (%s)',
                Identifier::quote($refPk),
                $dispSql,
                Identifier::quoteQualified($refSchema, $refTable),
                Identifier::quote($refPk),
                implode(', ', $escapedVals)
            );

            $map = [];
            $res = pg_query($this->conn->native(), $sql);
            if ($res) {
                while ($r = pg_fetch_assoc($res)) {
                    $map[$r['id']] = $r['disp'];
                }
                pg_free_result($res);
            } else {
                error_log(sprintf(
                    '[FkOptionsLoader] Foreign key configuration error: display column(s) [%s] not found on '
                    . 'table "%s"."%s" (reference_column "%s"). Check the FK settings in the schema editor. '
                    . 'Original error: %s',
                    implode(', ', $dispRaw),
                    $refSchema,
                    $refTable,
                    $refPk,
                    trim(pg_last_error($this->conn->native()))
                ));
            }

            foreach ($rows as &$row) {
                if (isset($row[$fkCol]) && array_key_exists($row[$fkCol], $map)) {
                    $row[$fkCol . '__display'] = $map[$row[$fkCol]];
                }
            }
            unset($row);
        }
        return $rows;
    }
}
