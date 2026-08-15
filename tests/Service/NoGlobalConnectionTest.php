<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class NoGlobalConnectionTest extends TestCase
{
    private const ROOTS = ['includes', 'public', 'src', 'cron', 'templates'];

    private static function codeWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (!is_array($token)) {
                $code .= $token;
                continue;
            }
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
        }
        return $code;
    }

    private static function phpFiles(): iterable
    {
        $base = dirname(__DIR__, 2);
        foreach (self::ROOTS as $root) {
            $directory = $base . DIRECTORY_SEPARATOR . $root;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    yield $root . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                }
            }
        }
    }

    public function testNoSourceFileReachesForTheConnectionThroughGlobals(): void
    {
        $base = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::phpFiles() as $relative) {
            $code = self::codeWithoutComments($base . DIRECTORY_SEPARATOR . $relative);
            if (str_contains($code, '$GLOBALS') || preg_match('/\bglobal\s+\$/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The database connection is injected through constructors; these files reach for global state instead.'
        );
    }
}
