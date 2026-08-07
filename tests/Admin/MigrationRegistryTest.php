<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The set of system-table migration keys is written out by hand in three places
 * (all three must be updated together during a release):
 *
 *   1. $migrations  — includes/admin/migrations.php, the init_db registry that
 *                     actually runs the DDL,
 *   2. $known       — includes/admin/migrations.php, feeding migrations_list,
 *   3. $knownMig    — includes/admin/overview.php, feeding the dashboard's
 *                     pending-migration counter.
 *
 * Drift is silent: a migration missing from $known simply never appears in the
 * admin list, and one missing from $knownMig makes the dashboard under-report
 * pending work. This test compares the three literals so a release that updates
 * only one of them fails the build.
 *
 * It reads the source rather than executing it — the registry lives inside an
 * action block that needs a session, a database and the front controller's
 * scope.
 */
final class MigrationRegistryTest extends TestCase
{
    private const MIGRATIONS_PHP = __DIR__ . '/../../includes/admin/migrations.php';
    private const OVERVIEW_PHP   = __DIR__ . '/../../includes/admin/overview.php';

    /** Extracts the quoted migration keys of a named array literal. */
    private static function arrayKeys(string $file, string $variable, bool $keysOnly): array
    {
        $source = (string) file_get_contents($file);
        preg_match('/\\' . $variable . '\s*=\s*\[(.*?)\n\s*\];/s', $source, $m);
        $body = $m[1] ?? '';

        if ($keysOnly) {
            // '3.1_x' => ...  — only entries acting as array keys
            preg_match_all("/'([0-9]+\.[0-9]+_[a-z0-9_]+)'\s*=>/", $body, $found);
        } else {
            preg_match_all("/'([0-9]+\.[0-9]+_[a-z0-9_]+)'/", $body, $found);
        }
        return $found[1];
    }

    public function testRegistryListAndOverviewAgree(): void
    {
        $registry = self::arrayKeys(self::MIGRATIONS_PHP, '$migrations', true);
        $known    = self::arrayKeys(self::MIGRATIONS_PHP, '$known', false);
        $overview = self::arrayKeys(self::OVERVIEW_PHP, '$knownMig', false);

        $this->assertNotEmpty($registry, 'Could not parse the $migrations registry.');

        $this->assertSame(
            $registry,
            $known,
            'includes/admin/migrations.php: the $known list (migrations_list) must '
            . 'match the $migrations registry keys exactly, in the same order.'
        );
        $this->assertSame(
            $registry,
            $overview,
            'includes/admin/overview.php: $knownMig must match the $migrations '
            . 'registry in includes/admin/migrations.php (dashboard pending count).'
        );
    }

    public function testBaselineIsTheFirstEntry(): void
    {
        $registry = self::arrayKeys(self::MIGRATIONS_PHP, '$migrations', true);
        $this->assertSame(
            '3.0_baseline',
            $registry[0] ?? null,
            '3.0_baseline is the append-only floor and must stay first.'
        );
    }
}
