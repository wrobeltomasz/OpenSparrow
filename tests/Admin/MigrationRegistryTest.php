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
 *                     pending-migration counter,
 *   4. the INSERT INTO spw_migrations list in public/setup_api.php, which records
 *                     what a fresh install already has.
 *
 * Drift is silent: a migration missing from $known simply never appears in the
 * admin list, one missing from $knownMig makes the dashboard under-report
 * pending work, and one missing from the wizard means fresh installs never get
 * the DDL at all. This test compares the four literals so a release that updates
 * only some of them fails the build.
 *
 * It reads the source rather than executing it — the registry lives inside an
 * action block that needs a session, a database and the front controller's
 * scope.
 */
final class MigrationRegistryTest extends TestCase
{
    private const MIGRATIONS_PHP = __DIR__ . '/../../includes/admin/migrations.php';
    private const OVERVIEW_PHP   = __DIR__ . '/../../includes/admin/overview.php';
    private const SETUP_API_PHP  = __DIR__ . '/../../public/setup_api.php';

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

    /**
     * The setup wizard builds a fresh database without consulting the admin
     * registry, then records the migrations it applied. A key added to the
     * registry but not to the wizard leaves fresh installs behind upgraded ones:
     * the DDL never runs, yet the dashboard reports the migration as pending on a
     * brand-new database. That is invisible until someone actually installs from
     * scratch, so it is asserted here.
     */
    public function testSetupWizardRecordsEveryMigration(): void
    {
        $registry = self::arrayKeys(self::MIGRATIONS_PHP, '$migrations', true);
        $source   = (string) file_get_contents(self::SETUP_API_PHP);

        preg_match_all(
            "/INSERT INTO \\\$tMigrations \(name\) VALUES \('([0-9]+\.[0-9]+_[a-z0-9_]+)'\)/",
            $source,
            $found
        );

        $this->assertSame(
            $registry,
            $found[1],
            'public/setup_api.php must record every migration in the includes/admin/migrations.php '
            . 'registry, in the same order — and must actually run its DDL (see system_tables.php).'
        );
    }
}
