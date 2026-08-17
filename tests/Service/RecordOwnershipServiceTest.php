<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use App\Service\RecordOwnershipService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecordOwnershipServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/db.php';
    }

    public function testUnrestrictedTableIsNotRestricted(): void
    {
        $this->assertFalse(RecordOwnershipService::isRestricted([]));
        $this->assertFalse(RecordOwnershipService::isRestricted(['owner_restricted' => false]));
    }

    public function testRestrictedTableIsRestricted(): void
    {
        $this->assertTrue(RecordOwnershipService::isRestricted(['owner_restricted' => true]));
    }

    public function testRestrictionSqlRequiresQualifiedIdExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecordOwnershipService::restrictionSql('id', 1, 2);
    }

    public function testRestrictionSqlEmbedsBothPlaceholders(): void
    {
        $sql = RecordOwnershipService::restrictionSql('_t.id', 3, 4);

        $this->assertStringContainsString('ro.table_name = $3', $sql);
        $this->assertStringContainsString('ro.owner_id != $4', $sql);
        $this->assertStringContainsString('ro.record_id = _t.id', $sql);
        $this->assertStringContainsString('ro.is_current = true', $sql);
    }

    public function testRestrictionSqlExcludesRatherThanIncludes(): void
    {
        $this->assertStringContainsString('AND NOT EXISTS', RecordOwnershipService::restrictionSql('_t.id', 1, 2));
    }
}
