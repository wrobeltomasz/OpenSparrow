<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClickstatsSettingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/clickstats.php';
    }

    protected function tearDown(): void
    {
        config_cache('clickstats', null, true);
    }

    public function testAcceptsWholeDayCounts(): void
    {
        $this->assertSame(30, clickstats_retention_days(30));
        $this->assertSame(30, clickstats_retention_days('30'));
        $this->assertSame(1, clickstats_retention_days(1));
        $this->assertSame(
            CLICKSTATS_MAX_RETENTION_DAYS,
            clickstats_retention_days(CLICKSTATS_MAX_RETENTION_DAYS)
        );
    }

    public function testZeroMeansKeepForever(): void
    {
        $this->assertSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days(0));
        $this->assertSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days('0'));
    }

    public static function unusableWindows(): array
    {
        require_once __DIR__ . '/../../includes/clickstats.php';

        return [
            'absent'        => [null],
            'negative'      => [-5],
            'not a number'  => ['abc'],
            'number + text' => ['90 dni'],
            'true'          => [true],
            'array'         => [[90]],
            'fractional'    => [3.5],
            'over the cap'  => [CLICKSTATS_MAX_RETENTION_DAYS + 1],
            'int overflow'  => ['9999999999999999999'],
        ];
    }

    #[DataProvider('unusableWindows')]
    public function testUnusableWindowFallsBackToTheDefaultNotToForever(mixed $rawDays): void
    {
        $this->assertSame(CLICKSTATS_DEFAULT_RETENTION_DAYS, clickstats_retention_days($rawDays));
        $this->assertNotSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days($rawDays));
    }

    public function testConfigWithoutRetentionGetsTheDefault(): void
    {
        config_cache('clickstats', ['value' => ['enabled' => true], 'version' => 1], true);

        $settings = clickstats_settings();
        $this->assertTrue($settings['enabled']);
        $this->assertSame(CLICKSTATS_DEFAULT_RETENTION_DAYS, $settings['retention_days']);
    }

    public function testStoredSettingsAreReportedAsSaved(): void
    {
        config_cache('clickstats', [
            'value'   => ['enabled' => false, 'track_records' => false, 'retention_days' => 7],
            'version' => 2,
        ], true);

        $this->assertSame(
            ['enabled' => false, 'track_records' => false, 'retention_days' => 7],
            clickstats_settings()
        );
    }

    public function testRetentionCeilingMatchesTheManualPurgeCeiling(): void
    {
        require_once __DIR__ . '/../../includes/admin/helpers.php';

        $this->assertSame(
            ADMIN_PURGE_MAX_DAYS,
            CLICKSTATS_MAX_RETENTION_DAYS,
            'includes/clickstats.php and includes/admin/helpers.php must bound a '
            . 'retention window identically.'
        );
    }
}
