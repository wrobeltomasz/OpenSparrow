<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class CommentLinksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/comment_links.php';
    }

    public function testExtractsSingleMarker(): void
    {
        $this->assertSame(
            ['deals' => [12 => true]],
            comment_extract_record_links('See [Record: deals:12] for details.')
        );
    }

    public function testExtractsMultipleMarkersAcrossTables(): void
    {
        $this->assertSame(
            ['deals' => [12 => true, 15 => true], 'contacts' => [3 => true]],
            comment_extract_record_links('A [Record: deals:12] and [Record: contacts:3] and [Record: deals:15].')
        );
    }

    public function testDeduplicatesRepeatedIds(): void
    {
        $this->assertSame(
            ['deals' => [12 => true]],
            comment_extract_record_links('[Record: deals:12] again [Record: deals:12].')
        );
    }

    public function testIgnoresNonPositiveIds(): void
    {
        $this->assertSame([], comment_extract_record_links('[Record: deals:0] [Record: deals:-3]'));
    }

    public function testIgnoresMalformedMarkers(): void
    {
        $this->assertSame(
            [],
            comment_extract_record_links('[Record: deals] [Record: :12] [view: deals:12] [Record: de.als:12]')
        );
    }

    public function testReturnsEmptyForNoMarkers(): void
    {
        $this->assertSame([], comment_extract_record_links('Just plain text with **markdown** and http://example.com'));
    }
}
