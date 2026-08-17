<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ImageService;
use PHPUnit\Framework\TestCase;

final class ImageServiceTest extends TestCase
{
    private static function schema(array $images): array
    {
        return ['tables' => ['photos' => ['images' => $images]]];
    }

    public function testMissingSectionIsNotConfigured(): void
    {
        $this->assertNull(ImageService::config(['tables' => ['photos' => []]], 'photos'));
    }

    public function testDisabledSectionIsNotConfigured(): void
    {
        $this->assertNull(ImageService::config(self::schema(['enabled' => false]), 'photos'));
    }

    public function testDefaultMaxPerRecord(): void
    {
        $config = ImageService::config(self::schema(['enabled' => true]), 'photos');

        $this->assertSame(10, $config['max_per_record']);
        $this->assertTrue($config['show_in_grid']);
    }

    public function testMaxPerRecordIsClampedUpwards(): void
    {
        $config = ImageService::config(self::schema(['enabled' => true, 'max_per_record' => 0]), 'photos');

        $this->assertSame(1, $config['max_per_record']);
    }

    public function testMaxPerRecordIsClampedDownwards(): void
    {
        $config = ImageService::config(self::schema(['enabled' => true, 'max_per_record' => 5000]), 'photos');

        $this->assertSame(ImageService::MAX_PER_RECORD, $config['max_per_record']);
    }

    public function testGlobalConstantsStillMirrorTheService(): void
    {
        require_once __DIR__ . '/../../includes/images.php';

        $this->assertSame(ImageService::FIELD, IMAGES_FIELD);
        $this->assertSame(ImageService::GRID_LIMIT, IMAGES_GRID_LIMIT);
    }
}
