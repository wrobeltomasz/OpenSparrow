<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class CronCliGuardTest extends TestCase
{
    private const CRON_DIR = __DIR__ . '/../../cron';

    private const BOOT_HELPER = __DIR__ . '/../../includes/etl_cli.php';

    private const BOOT_CALL = 'etl_cli_boot()';

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

    private static function cronScripts(): array
    {
        $paths = glob(self::CRON_DIR . '/*.php') ?: [];
        sort($paths);
        return $paths;
    }

    private static function refusesNonCli(string $source): bool
    {
        return preg_match(
            '/php_sapi_name\(\)\s*!==\s*[\'"]cli[\'"]/',
            $source
        ) === 1;
    }

    public function testCronDirectoryIsNotEmpty(): void
    {
        $this->assertNotSame(
            [],
            self::cronScripts(),
            'No cron scripts found — this guard would pass vacuously.'
        );
    }

    public function testTheSharedBootHelperRefusesNonCli(): void
    {
        $source = self::code(self::BOOT_HELPER);

        $this->assertTrue(
            self::refusesNonCli($source),
            'includes/etl_cli.php::etl_cli_boot() must refuse a non-CLI SAPI. '
            . 'Two cron scripts delegate their only guard to it.'
        );
        $this->assertStringContainsString(
            'ForbiddenException',
            $source,
            'etl_cli_boot() must throw rather than continue when the SAPI is not CLI.'
        );
    }

    public function testEveryCronScriptRefusesWebExecution(): void
    {
        $unguarded = [];

        foreach (self::cronScripts() as $path) {
            $source = self::code($path);
            if (self::refusesNonCli($source) || str_contains($source, self::BOOT_CALL)) {
                continue;
            }
            $unguarded[] = basename($path);
        }

        $this->assertSame(
            [],
            $unguarded,
            'Cron script(s) with no CLI-only guard. cron/ sits outside the public/ docroot, '
            . 'but this check is what stops them running over HTTP if the docroot is ever '
            . 'pointed at the repository root: ' . implode(', ', $unguarded)
        );
    }

    public function testGuardIsReachedBeforeAnyDatabaseWork(): void
    {
        foreach (self::cronScripts() as $path) {
            $source = self::code($path);

            $guardOffset = null;
            if (preg_match('/php_sapi_name\(\)\s*!==\s*[\'"]cli[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $guardOffset = $matches[0][1];
            } elseif (($callOffset = strpos($source, self::BOOT_CALL)) !== false) {
                $guardOffset = $callOffset;
            }

            $this->assertNotNull($guardOffset, basename($path) . ' has no CLI guard at all.');

            $connectOffset = strpos($source, 'db_connect(');
            if ($connectOffset === false) {
                continue;
            }

            $this->assertLessThan(
                $connectOffset,
                $guardOffset,
                basename($path) . ' opens a database connection before refusing a non-CLI SAPI.'
            );
        }
    }
}
