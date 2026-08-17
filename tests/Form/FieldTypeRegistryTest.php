<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private function makeType(bool $supports): FieldTypeInterface
    {
        return new class ($supports) implements FieldTypeInterface {
            public function __construct(private readonly bool $supported)
            {
            }
            public function supports(ColumnConfig $column, bool $hasForeignKey): bool
            {
                return $this->supported;
            }
            public function bind(string $columnName, array $postData): BoundValue
            {
                return new BoundValue(null);
            }
            public function render(ColumnConfig $column, mixed $currentValue, RenderContext $context): string
            {
                return '';
            }
        };
    }

    public function testReturnsFirstMatchingType(): void
    {
        $first    = $this->makeType(false);
        $second   = $this->makeType(true);
        $registry = new FieldTypeRegistry([$first, $second]);
        $column      = new ColumnConfig('x', 'text', 'X');
        $this->assertSame($second, $registry->for($column, false));
    }

    public function testThrowsLogicExceptionWhenNoMatch(): void
    {
        $registry = new FieldTypeRegistry([$this->makeType(false)]);
        $column      = new ColumnConfig('x', 'text', 'X');
        $this->expectException(\LogicException::class);
        $registry->for($column, false);
    }

    public function testFirstMatchWinsOverLaterCandidates(): void
    {
        $first  = $this->makeType(true);
        $second = $this->makeType(true);
        $registry = new FieldTypeRegistry([$first, $second]);
        $column = new ColumnConfig('x', 'text', 'X');
        $this->assertSame($first, $registry->for($column, false));
    }
}
