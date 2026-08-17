<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

final class MigrationRegistryTest extends TestCase
{
    private const MIGRATIONS_PHP = __DIR__ . '/../../includes/admin/migrations.php';
    private const OVERVIEW_PHP   = __DIR__ . '/../../includes/admin/overview.php';
    private const SETUP_API_PHP  = __DIR__ . '/../../public/setup_api.php';

    private static function arrayKeys(string $file, string $variable, bool $keysOnly): array
    {
        $source = (string) file_get_contents($file);
        preg_match('/\\' . $variable . '\s*=\s*\[(.*?)\n\s*\];/s', $source, $matches);
        $body = $matches[1] ?? '';

        if ($keysOnly) {
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
        $overview = self::arrayKeys(self::OVERVIEW_PHP, '$knownMigrations', false);

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

    public function testSetupWizardRecordsEveryMigration(): void
    {
        $registry = self::arrayKeys(self::MIGRATIONS_PHP, '$migrations', true);
        $source   = (string) file_get_contents(self::SETUP_API_PHP);

        preg_match_all(
            "/INSERT INTO \\\$migrationsTable \(name\) VALUES \('([0-9]+\.[0-9]+_[a-z0-9_]+)'\)/",
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
