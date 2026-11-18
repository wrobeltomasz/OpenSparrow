<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class FileLinksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/file_links.php';
    }

    public function testExtractsSingleMarker(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $this->assertSame(
            [$uuid => true],
            file_extract_links('See [File: ' . $uuid . '] for details.')
        );
    }

    public function testExtractsMultipleMarkers(): void
    {
        $first  = '123e4567-e89b-12d3-a456-426614174000';
        $second = '9f8e7d6c-5b4a-3210-fedc-ba9876543210';
        $this->assertSame(
            [$first => true, $second => true],
            file_extract_links('A [File: ' . $first . '] and [File: ' . $second . '].')
        );
    }

    public function testDeduplicatesRepeatedUuids(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $this->assertSame(
            [$uuid => true],
            file_extract_links('[File: ' . $uuid . '] again [File: ' . strtoupper($uuid) . '].')
        );
    }

    public function testIgnoresMalformedMarkers(): void
    {
        $this->assertSame(
            [],
            file_extract_links(
                '[File: not-a-uuid] [File: ] [File: 12345678] [File: 123e4567-e89b-12d3-a456-42661417400g]'
            )
        );
    }

    public function testReturnsEmptyForNoMarkers(): void
    {
        $this->assertSame([], file_extract_links('Just plain text with **markdown** and http://example.com'));
    }
}
