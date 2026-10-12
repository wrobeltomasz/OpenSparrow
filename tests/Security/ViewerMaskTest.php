<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class ViewerMaskTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/api_helpers.php';
    }

    public function testNonViewerGetsNoMaskedColumns(): void
    {
        $tableConfig = [
            'columns' => [
                'passport' => ['type' => 'text', 'mask_for_viewer' => true],
            ],
        ];

        $this->assertSame([], masked_columns_for_viewer($tableConfig, false));
    }

    public function testViewerGetsMaskedColumns(): void
    {
        $tableConfig = [
            'columns' => [
                'name'     => ['type' => 'text'],
                'passport' => ['type' => 'text', 'mask_for_viewer' => true],
                'id_card'  => ['type' => 'text', 'mask_for_viewer' => true],
            ],
        ];

        $this->assertSame(
            ['passport', 'id_card'],
            masked_columns_for_viewer($tableConfig, true)
        );
    }

    public function testMaskViewerRowReplacesValueAndDisplay(): void
    {
        $row = [
            'id'              => '7',
            'passport'        => 'AB123456',
            'passport__display' => 'AB123456',
            'name'            => 'Anna',
        ];

        $masked = mask_viewer_row($row, ['passport']);

        $this->assertSame(OS_VIEWER_MASK, $masked['passport']);
        $this->assertSame(OS_VIEWER_MASK, $masked['passport__display']);
        $this->assertSame('Anna', $masked['name']);
        $this->assertSame('7', $masked['id']);
    }

    public function testMaskViewerRowIgnoresMissingColumns(): void
    {
        $row = ['id' => '7', 'name' => 'Anna'];

        $masked = mask_viewer_row($row, ['passport']);

        $this->assertSame(['id' => '7', 'name' => 'Anna'], $masked);
    }
}
