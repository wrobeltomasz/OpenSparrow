<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class CopyImportService
{
    private const int BUFFER_FLUSH_BYTES = 524288;

    public function __construct(private readonly \PgSql\Connection $conn)
    {
    }

    public function execute(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdentifier = pg_ident($tableSchema) . '.' . pg_ident($tableName);

        $columnMap = [];
        foreach ($mapping as $csvHeaderName => $dbColumn) {
            if ($dbColumn !== null && $dbColumn !== '' && !isset($columnMap[$dbColumn])) {
                $columnMap[$dbColumn] = $csvHeaderName;
            }
        }
        if (empty($columnMap)) {
            throw new \AdminApiMessage('No columns mapped.');
        }

        $fileHandle = fopen($csvPath, 'r');
        if ($fileHandle === false) {
            throw new \AdminApiMessage('Cannot open CSV file.');
        }

        try {
            $csvHeaders = fgetcsv($fileHandle, 0, $delimiter, '"', '\\');
            if ($csvHeaders === false || $csvHeaders === null) {
                throw new \AdminApiMessage('Empty CSV file.');
            }
            $csvHeaders[0] = ltrim((string) $csvHeaders[0], "\xEF\xBB\xBF");
            $csvHeaders    = array_map('trim', $csvHeaders);

            $mappedCount    = count(array_filter(
                $csvHeaders,
                fn($header) => isset($mapping[$header]) && $mapping[$header] !== null && $mapping[$header] !== ''
            ));
            $isDirectStream = $mappedCount === count($csvHeaders);

            $headerIndexes  = array_flip($csvHeaders);
            $columnIndices = $isDirectStream ? null : array_map(
                fn($csvHeaderName) => $headerIndexes[$csvHeaderName] ?? null,
                array_values($columnMap)
            );

            $copyColumns = $isDirectStream
                ? array_map(fn($header) => $mapping[$header], $csvHeaders)
                : array_keys($columnMap);
            $columnList = implode(',', array_map(pg_ident(...), $copyColumns));
            $sql     = "COPY {$tableIdentifier} ({$columnList}) FROM STDIN WITH (FORMAT CSV, NULL '')";

            if (@pg_query($this->conn, $sql) === false) {
                error_log('[csv_import] COPY init failed: ' . pg_last_error($this->conn));
                throw new \AdminApiMessage('COPY import failed to start — check server error log.');
            }

            $total  = 0;
            $buffer = '';

            while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }
                $total++;
                $headerCount = count($csvHeaders);

                $row = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                if ($isDirectStream) {
                    $fields = array_map(function ($value) use ($encoding) {
                        $text = (string) $value;
                        if ($encoding !== 'UTF-8') {
                            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
                        }
                        return self::quoteForCopy($text);
                    }, $row);
                } else {
                    $fields = [];
                    foreach ($columnIndices as $index) {
                        $cellValue = ($index !== null && isset($row[$index])) ? (string) $row[$index] : '';
                        if ($encoding !== 'UTF-8') {
                            $cellValue = mb_convert_encoding($cellValue, 'UTF-8', $encoding);
                        }
                        $fields[] = self::quoteForCopy($cellValue);
                    }
                }
                $buffer .= implode(',', $fields) . "\n";
                if (strlen($buffer) >= self::BUFFER_FLUSH_BYTES) {
                    @pg_put_line($this->conn, $buffer);
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                @pg_put_line($this->conn, $buffer);
            }
            @pg_put_line($this->conn, "\\.\n");

            if (@pg_end_copy($this->conn) === false) {
                $pgError = pg_last_error($this->conn);
                error_log('[csv_import] COPY failed: ' . $pgError);
                $hint  = '';
                if (
                    preg_match('/invalid input syntax for type (\w+).*column (\w+)/i', $pgError, $matches)
                    || preg_match(
                        '/niepra.*?dla typu (\w+).*kolumn[ay] (\w+)/iu',
                        $pgError,
                        $matches
                    )
                ) {
                    $hint = " Column \"{$matches[2]}\" is typed {$matches[1]} but received a non-{$matches[1]} value."
                        . ' Cause: an earlier field in that row has an unquoted delimiter, '
                        . 'shifting all subsequent columns.'
                        . ' Fix: use Normal mode (per-row error reporting) or correct the source CSV quoting.';
                } elseif (str_contains($pgError, 'unexpected data') || str_contains($pgError, 'nieoczekiwane dane')) {
                    $hint = ' A row has more fields than the header.'
                        . ' Check the Delimiter setting or fix quoting in the source CSV.';
                }
                throw new \AdminApiMessage('COPY failed — one or more rows were rejected by the database.' . $hint);
            }

            return [$total, $total, 0];
        } finally {
            fclose($fileHandle);
        }
    }

    private static function quoteForCopy(string $cellValue): string
    {
        if (
            str_contains($cellValue, ',') || str_contains($cellValue, '"')
            || str_contains($cellValue, "\n") || str_contains($cellValue, "\r")
        ) {
            return '"' . str_replace('"', '""', $cellValue) . '"';
        }
        return $cellValue;
    }
}
