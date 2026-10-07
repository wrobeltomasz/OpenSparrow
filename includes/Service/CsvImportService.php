<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class CsvImportService
{
    public const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly \PgSql\Connection $conn,
        private readonly ImportRepository $repository,
    ) {
    }

    public function execute(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        array $columnTypes,
        ?string $conflictColumn,
        int $importId,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdentifier = pg_ident($tableSchema) . '.' . pg_ident($tableName);
        $dbColumns     = array_values(array_unique(array_filter($mapping)));

        $batchSize = max(1, min(self::BATCH_SIZE, (int) floor(65000 / max(1, count($dbColumns)))));

        $total     = 0;
        $imported  = 0;
        $skipped   = 0;
        $rowErrors = [];
        $batch     = [];

        foreach (CsvReader::read($csvPath, $delimiter, $encoding) as $rowNumber => $rowData) {
            if ($rowNumber === 0) {
                continue;
            }
            $total++;

            $castRow = [];

            foreach ($mapping as $csvHeader => $dbColumn) {
                if ($dbColumn === null || $dbColumn === '') {
                    continue;
                }
                $rawValue  = isset($rowData[$csvHeader]) ? (string) $rowData[$csvHeader] : null;
                $columnType = $columnTypes[$dbColumn] ?? 'text';
                $casted  = RowCaster::cast($rawValue, $columnType);
                $castRow[$dbColumn] = $casted;
            }

            if (empty($castRow)) {
                $skipped++;
                $rowErrors[] = [
                    'row_number' => $rowNumber,
                    'raw_data'   => $rowData,
                    'error'      => 'All mapped columns empty after cast.',
                ];
                continue;
            }

            $batch[] = ['rowNum' => $rowNumber, 'data' => $castRow, 'raw' => $rowData];

            if (count($batch) >= $batchSize) {
                [$importedCount, $skip, $errors] = $this->flushBatch(
                    $batch,
                    $tableIdentifier,
                    $dbColumns,
                    $conflictColumn
                );
                $imported += $importedCount;
                $skipped  += $skip;
                array_push($rowErrors, ...$errors);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            [$importedCount, $skip, $errors] = $this->flushBatch($batch, $tableIdentifier, $dbColumns, $conflictColumn);
            $imported += $importedCount;
            $skipped  += $skip;
            array_push($rowErrors, ...$errors);
        }

        $this->repository->logRows($importId, $rowErrors);

        return [$total, $imported, $skipped];
    }

    private function flushBatch(
        array $batch,
        string $tableIdentifier,
        array $dbColumns,
        ?string $conflictColumn
    ): array {
        @pg_query($this->conn, 'BEGIN');

        $sql    = $this->buildInsertSql($batch, $tableIdentifier, $dbColumns, $conflictColumn);
        $parameters = $this->buildParams($batch, $dbColumns);
        $result    = @pg_query_params($this->conn, $sql, $parameters);

        if ($result === false) {
            @pg_query($this->conn, 'ROLLBACK');
            error_log('[csv_import] batch insert failed: ' . pg_last_error($this->conn));
            $errors = array_map(
                fn($batchEntry) => [
                    'row_number' => $batchEntry['rowNum'],
                    'raw_data'   => $batchEntry['raw'],
                    'error'      => 'Batch DB error — check server error log.',
                ],
                $batch
            );
            return [0, count($batch), $errors];
        }

        @pg_query($this->conn, 'COMMIT');
        return [count($batch), 0, []];
    }

    private function buildInsertSql(
        array $batch,
        string $tableIdentifier,
        array $dbColumns,
        ?string $conflictColumn
    ): string {
        $columnList = implode(',', array_map('pg_ident', $dbColumns));
        $columnCount = count($dbColumns);
        $rows    = [];
        $index     = 1;
        foreach ($batch as $_) {
            $placeholders = [];
            for ($column = 0; $column < $columnCount; $column++) {
                $placeholders[] = '$' . $index++;
            }
            $rows[] = '(' . implode(',', $placeholders) . ')';
        }
        $sql = "INSERT INTO {$tableIdentifier} ({$columnList}) VALUES " . implode(',', $rows);

        if ($conflictColumn !== null && $conflictColumn !== '') {
            $conflictIdentifier         = pg_ident($conflictColumn);
            $updateColumns = array_filter($dbColumns, fn($column) => $column !== $conflictColumn);
            if (!empty($updateColumns)) {
                $sets = array_map(fn($column) => pg_ident($column) . '=EXCLUDED.' . pg_ident($column), $updateColumns);
                $sql .= " ON CONFLICT ({$conflictIdentifier}) DO UPDATE SET " . implode(',', $sets);
            } else {
                $sql .= " ON CONFLICT ({$conflictIdentifier}) DO NOTHING";
            }
        }

        return $sql;
    }

    private function buildParams(array $batch, array $dbColumns): array
    {
        $parameters = [];
        foreach ($batch as $entry) {
            foreach ($dbColumns as $columnName) {
                $parameters[] = $entry['data'][$columnName] ?? null;
            }
        }
        return $parameters;
    }
}
