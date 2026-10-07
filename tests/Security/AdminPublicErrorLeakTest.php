<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class AdminPublicErrorLeakTest extends TestCase
{
    private const CSV_ENDPOINT = __DIR__ . '/../../public/admin/api_csv_import.php';
    private const DEMO_SEED    = __DIR__ . '/../../public/admin/demo/seed.php';

    private static function code(string $path): string
    {
        $output = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $output .= $token[1];
                continue;
            }
            $output .= $token;
        }
        return $output;
    }

    public function testFilesExist(): void
    {
        $this->assertFileExists(self::CSV_ENDPOINT);
        $this->assertFileExists(self::DEMO_SEED);
    }

    public function testCsvEndpointNeverServesPgLastError(): void
    {
        $source = self::code(self::CSV_ENDPOINT);

        $offending = [];
        if (preg_match_all('/csv_fail\s*\([^;]*pg_last_error[^;]*;/', $source, $matches) > 0) {
            $offending = array_merge($offending, $matches[0]);
        }
        if (preg_match_all('/throw\s+new\s+[^;]*pg_last_error[^;]*;/', $source, $matches) > 0) {
            $offending = array_merge($offending, $matches[0]);
        }
        $this->assertSame(
            [],
            $offending,
            'public/admin/api_csv_import.php must never serve pg_last_error() text to the client. '
            . 'It flows into the JSON error field rendered by the admin CSV import UI. '
            . 'Log it via error_log() and serve a generic message. '
            . 'Offending statements: ' . implode(' | ', $offending)
        );
    }

    public function testCsvEndpointNeverPersistsOrServesExceptionMessage(): void
    {
        $source = self::code(self::CSV_ENDPOINT);

        $offending = [];
        if (preg_match_all('/csv_fail\s*\([^;]*getMessage\(\)[^;]*;/', $source, $matches) > 0) {
            $offending = array_merge($offending, $matches[0]);
        }
        if (preg_match_all('/finalize\s*\([^;]*getMessage\(\)[^;]*;/', $source, $matches) > 0) {
            $offending = array_merge($offending, $matches[0]);
        }
        $this->assertSame(
            [],
            $offending,
            'public/admin/api_csv_import.php must never serve or persist $exception->getMessage() '
            . 'in csv_fail() or ImportRepository::finalize(). Use admin_error_message() and persist '
            . 'a generic message — the message reaches the client through the JSON error field and '
            . 'the spw_imports.error_message column served by csv_import_history. '
            . 'Offending statements: ' . implode(' | ', $offending)
        );
    }

    public function testDemoSeedNeverServesExceptionMessage(): void
    {
        $source = self::code(self::DEMO_SEED);

        $offending = [];
        if (preg_match_all('/(?:echo|return)\s*[^;]*getMessage\(\)[^;]*;/', $source, $matches) > 0) {
            $offending = $matches[0];
        }
        $this->assertSame(
            [],
            $offending,
            'public/admin/demo/seed.php must never serve $exception->getMessage() to the client. '
            . 'Use admin_error_message() — the message reaches the client through the JSON error '
            . 'field rendered by the admin demo UI. '
            . 'Offending statements: ' . implode(' | ', $offending)
        );
    }
}