<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form;

use App\Form\BoundValue;
use PHPUnit\Framework\TestCase;

final class BoundValueTest extends TestCase
{
    public function testPlaceholderWithoutCast(): void
    {
        $boundValue = new BoundValue('hello');
        $this->assertSame('$1', $boundValue->placeholder(1));
        $this->assertSame('$3', $boundValue->placeholder(3));
    }

    public function testPlaceholderWithCast(): void
    {
        $boundValue = new BoundValue('true', 'boolean');
        $this->assertSame('$1::boolean', $boundValue->placeholder(1));
    }

    public function testValueAndCastAreReadable(): void
    {
        $boundValue = new BoundValue(42, 'integer');
        $this->assertSame(42, $boundValue->value);
        $this->assertSame('integer', $boundValue->cast);
    }

    public function testNullValue(): void
    {
        $boundValue = new BoundValue(null);
        $this->assertNull($boundValue->value);
        $this->assertNull($boundValue->cast);
    }
}
