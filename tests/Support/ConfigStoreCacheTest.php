<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The opt-in negative cache in includes/config_store.php.
 *
 * config_get_row($key, $absentTtl) may store a marker meaning "this key has no row
 * yet" so a hot-path caller does not re-query for it on every request (the frontend
 * layout does this for the "clickstats" flag, which has no row until an admin first
 * saves the module's settings).
 *
 * The marker lives in the same cache slot as a real row, so the one thing that must
 * never happen is it reaching a caller as though it were config: every consumer
 * treats the return value as a decoded document, and ['spw_absent' => true] would be
 * read as a config with an unknown shape rather than as "not configured".
 */
final class ConfigStoreCacheTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/config_store.php';
    }

    /** Cache entries are process-global; keep each test from seeing the last one's. */
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

    /**
     * The marker must not be mistaken for a document, and a document must not be
     * mistaken for the marker — they share one cache slot.
     */
    public function testRealRowIsStillReturnedFromCache(): void
    {
        config_cache('spw_test_absent', ['value' => ['enabled' => true], 'version' => 3], true);

        $this->assertSame(
            ['value' => ['enabled' => true], 'version' => 3],
            config_get_row('spw_test_absent')
        );
        $this->assertSame(['enabled' => true], config_get('spw_test_absent'));
    }

    /**
     * Writing the key is what un-hides it: config_save() ends with exactly this
     * write-through, so enabling a module replaces a cached "absent" immediately
     * instead of waiting out the TTL.
     */
    public function testWritingTheKeyReplacesTheAbsentMarker(): void
    {
        config_cache('spw_test_absent', CONFIG_CACHE_ABSENT, true, 60);
        $this->assertNull(config_get('spw_test_absent'));

        config_cache('spw_test_absent', ['value' => ['enabled' => true], 'version' => 1], true);
        $this->assertSame(['enabled' => true], config_get('spw_test_absent'));
    }
}
