<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class ConfigStoreCacheTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/config_store.php';
    }

    protected function tearDown(): void
    {
        config_cache('spw_test_absent', null, true);
    }

    public function testAbsentMarkerIsReportedAsNotConfigured(): void
    {
        config_cache('spw_test_absent', CONFIG_CACHE_ABSENT, true, 60);

        $this->assertNull(
            config_get_row('spw_test_absent'),
            'A cached "absent" must read back as no row, not as the marker array.'
        );
        $this->assertNull(config_get('spw_test_absent'));
    }

    public function testRealRowIsStillReturnedFromCache(): void
    {
        config_cache('spw_test_absent', ['value' => ['enabled' => true], 'version' => 3], true);

        $this->assertSame(
            ['value' => ['enabled' => true], 'version' => 3],
            config_get_row('spw_test_absent')
        );
        $this->assertSame(['enabled' => true], config_get('spw_test_absent'));
    }

    public function testWritingTheKeyReplacesTheAbsentMarker(): void
    {
        config_cache('spw_test_absent', CONFIG_CACHE_ABSENT, true, 60);
        $this->assertNull(config_get('spw_test_absent'));

        config_cache('spw_test_absent', ['value' => ['enabled' => true], 'version' => 1], true);
        $this->assertSame(['enabled' => true], config_get('spw_test_absent'));
    }
}
