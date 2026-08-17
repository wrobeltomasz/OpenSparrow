<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ExitFreeRequestPathTest extends TestCase
{
    private const SCANNED_DIRECTORIES = ['includes', 'public', 'templates', 'src'];

    private const CLI_ENTRY_POINTS = ['cron'];

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $relativeDirectory): array
    {
        $base = $this->root() . '/' . $relativeDirectory;
        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', (string) $file->getPathname());
            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<array{int, string}>
     */
    private function exitTokens(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $found  = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_EXIT) {
                continue;
            }

            $argument = '';
            for ($next = $index + 1; $next < count($tokens); $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && $candidate[0] === T_WHITESPACE) {
                    continue;
                }
                $argument = is_array($candidate) ? $candidate[1] : $candidate;
                break;
            }

            $found[] = [$token[2], $argument];
        }

        return $found;
    }

    public function testRequestPathContainsNoDieOrExit(): void
    {
        $offenders = [];

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            foreach ($this->phpFiles($directory) as $path) {
                foreach ($this->exitTokens($path) as [$line]) {
                    $offenders[] = str_replace($this->root() . '/', '', $path) . ':' . $line;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Request-path code must signal termination with an App\Exception, never die()/exit(): '
                . implode(', ', $offenders)
        );
    }

    public function testCliEntryPointsExitExactlyOnceWithAProcessStatusCode(): void
    {
        $offenders = [];

        foreach (self::CLI_ENTRY_POINTS as $directory) {
            foreach ($this->phpFiles($directory) as $path) {
                $relative = str_replace($this->root() . '/', '', $path);
                $exits    = $this->exitTokens($path);

                if (count($exits) !== 1) {
                    $offenders[] = $relative . ' has ' . count($exits) . ' exit() calls, expected exactly 1';
                    continue;
                }

                [$line, $argument] = $exits[0];
                if ($argument !== '(') {
                    $offenders[] = $relative . ':' . $line . ' exits without a process status code';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A cron script runs its work in a main() returning int and exits exactly once, at the end: '
                . implode(', ', $offenders)
        );
    }

    public function testCliEntryPointsExitOnTheirLastStatement(): void
    {
        $offenders = [];

        foreach (self::CLI_ENTRY_POINTS as $directory) {
            foreach ($this->phpFiles($directory) as $path) {
                $lines = explode("\n", rtrim(str_replace("\r\n", "\n", (string) file_get_contents($path))));
                $last  = trim((string) end($lines));

                if (preg_match('/^exit\(\w+\(\$argv\)\);$/', $last) !== 1) {
                    $offenders[] = str_replace($this->root() . '/', '', $path) . ' ends with: ' . $last;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A cron script must end with a single exit(<main>($argv)); statement: ' . implode(', ', $offenders)
        );
    }

    public function testBroadCatchBlocksRethrowControlFlowExceptions(): void
    {
        $offenders = [];
        $directories = array_merge(self::SCANNED_DIRECTORIES, self::CLI_ENTRY_POINTS);

        foreach ($directories as $directory) {
            foreach ($this->phpFiles($directory) as $path) {
                $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));

                foreach ($lines as $index => $line) {
                    $isBroad = preg_match(
                        '/catch \((\\\\?Throwable|\\\\?Exception)\s+\$\w+\)\s*\{$/',
                        $line
                    ) === 1;

                    if (!$isBroad) {
                        continue;
                    }
                    if (str_contains($lines[$index - 1] ?? '', 'throw $signal;')) {
                        continue;
                    }
                    $offenders[] = str_replace($this->root() . '/', '', $path) . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A catch of Throwable/Exception must rethrow ControlFlowException first, '
                . 'otherwise it swallows redirects and finished responses: ' . implode(', ', $offenders)
        );
    }
}
