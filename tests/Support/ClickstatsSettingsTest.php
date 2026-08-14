<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Click Statistics retention normalisation (includes/clickstats.php).
 *
 * The window this produces is the only thing that bounds spw_clickstats without
 * someone pressing a button, so the direction of every fallback matters: an
 * unusable value must land on the default window, never on "keep everything".
 */
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

    /** Opting out of automatic expiry has to be possible, and explicit. */
    public function testZeroMeansKeepForever(): void
    {
        $this->assertSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days(0));
        $this->assertSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days('0'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
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

    /**
     * Note which way this falls: to the default window, NOT to "keep everything".
     * A typo or a client bug must not be able to silently switch off the only
     * automatic bound the table has.
     */
    #[DataProvider('unusableWindows')]
    public function testUnusableWindowFallsBackToTheDefaultNotToForever(mixed $raw): void
    {
        $this->assertSame(CLICKSTATS_DEFAULT_RETENTION_DAYS, clickstats_retention_days($raw));
        $this->assertNotSame(CLICKSTATS_RETENTION_FOREVER, clickstats_retention_days($raw));
    }

    /**
     * A config written before retention existed has no such key, and those installs
     * are precisely the ones already accumulating rows with nothing to trim them.
     */
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

    /**
     * The ceiling is written twice — here and as ADMIN_PURGE_MAX_DAYS for the manual
     * purge — because the admin helpers need a request context this file must not.
     * Drift would let the Settings tab accept a window the Log tab rejects.
     */
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
