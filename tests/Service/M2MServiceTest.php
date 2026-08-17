<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use App\Service\M2MService;
use PHPUnit\Framework\TestCase;

final class M2MServiceTest extends TestCase
{
    public function testExplicitOtherTableWins(): void
    {
        $config = ['other_table' => 'tags', 'junction_table' => 'post_tag', 'other_fk' => 'tag_id'];

        $this->assertSame('tags', M2MService::resolveOtherTable($config, []));
    }

    public function testOtherTableIsDerivedFromTheJunctionForeignKey(): void
    {
        $rawSchema = [
            'tables' => [
                'post_tag' => [
                    'foreign_keys' => [
                        'tag_id' => ['reference_table' => 'tags'],
                    ],
                ],
            ],
        ];
        $config = ['junction_table' => 'post_tag', 'other_fk' => 'tag_id'];

        $this->assertSame('tags', M2MService::resolveOtherTable($config, $rawSchema));
    }

    public function testUnresolvableOtherTableIsEmpty(): void
    {
        $this->assertSame('', M2MService::resolveOtherTable(['junction_table' => 'nope'], ['tables' => []]));
    }

    public function testJunctionPartsRequireAllThreeKeys(): void
    {
        $this->assertNull(M2MService::junctionParts([]));
        $this->assertNull(M2MService::junctionParts(['junction_table' => 'post_tag', 'self_fk' => 'post_id']));
    }

    public function testJunctionPartsAreReturnedInOrder(): void
    {
        $config = ['junction_table' => 'post_tag', 'self_fk' => 'post_id', 'other_fk' => 'tag_id'];

        $this->assertSame(['post_tag', 'post_id', 'tag_id'], M2MService::junctionParts($config));
    }
}
