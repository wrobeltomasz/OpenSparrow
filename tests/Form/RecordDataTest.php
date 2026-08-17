<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form;

use App\Form\BoundValue;
use App\Form\RecordData;
use PHPUnit\Framework\TestCase;

final class RecordDataTest extends TestCase
{
    public function testIsEmptyWhenNoBindings(): void
    {
        $recordData = new RecordData([]);
        $this->assertTrue($recordData->isEmpty());
    }

    public function testIsNotEmptyWithBindings(): void
    {
        $recordData = new RecordData([
            ['col' => 'name', 'bound' => new BoundValue('Alice')],
        ]);
        $this->assertFalse($recordData->isEmpty());
    }

    public function testBindingsArePreserved(): void
    {
        $boundValue = new BoundValue('test');
        $recordData = new RecordData([['col' => 'name', 'bound' => $boundValue]]);
        $this->assertCount(1, $recordData->bindings);
        $this->assertSame('name', $recordData->bindings[0]['col']);
        $this->assertSame($boundValue, $recordData->bindings[0]['bound']);
    }
}
