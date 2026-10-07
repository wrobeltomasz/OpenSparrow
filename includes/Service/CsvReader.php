<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class CsvReader
{
    public static function read(string $path, string $delimiter = ',', string $encoding = 'UTF-8'): \Generator
    {
        $fileHandle = fopen($path, 'r');
        if ($fileHandle === false) {
            throw new \AdminApiMessage('Cannot open CSV file for reading.');
        }
        try {
            $headers = fgetcsv($fileHandle, 0, $delimiter, '"', '\\');
            if ($headers === false || $headers === null) {
                return;
            }
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $headers    = array_map('trim', $headers);
            if ($encoding !== 'UTF-8') {
                $headers = array_map(fn($header) => mb_convert_encoding($header, 'UTF-8', $encoding), $headers);
            }
            yield 0 => $headers;

            $rowNumber = 1;
            while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }
                $count = count($headers);
                $row   = array_pad(array_slice($row, 0, $count), $count, null);
                if ($encoding !== 'UTF-8') {
                    $row = array_map(
                        fn($value) => $value !== null
                            ? mb_convert_encoding($value, 'UTF-8', $encoding)
                            : null,
                        $row
                    );
                }
                yield $rowNumber++ => array_combine($headers, $row);
            }
        } finally {
            fclose($fileHandle);
        }
    }
}
