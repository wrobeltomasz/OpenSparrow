<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\TextField;
use PHPUnit\Framework\TestCase;

final class TextFieldTest extends TestCase
{
    private TextField $field;

    protected function setUp(): void
    {
        $this->field = new TextField();
    }

    private function col(
        string $type = 'text',
        ?string $regexp = null,
        ?string $message = null
    ): ColumnConfig {
        return new ColumnConfig('name', $type, 'Name', false, false, true, [], [], $regexp, $message);
    }

    public function testSupportsAllTypes(): void
    {
        $this->assertTrue($this->field->supports($this->col('text'), false));
        $this->assertTrue($this->field->supports($this->col('integer'), false));
        $this->assertTrue($this->field->supports($this->col('varchar'), true));
    }

    public function testBindWithValue(): void
    {
        $boundValue = $this->field->bind('name', ['name' => 'Alice']);
        $this->assertSame('Alice', $boundValue->value);
    }

    public function testBindEmptyReturnsNull(): void
    {
        $boundValue = $this->field->bind('name', ['name' => '']);
        $this->assertNull($boundValue->value);
    }

    public function testBindMissingKeyReturnsNull(): void
    {
        $boundValue = $this->field->bind('name', []);
        $this->assertNull($boundValue->value);
    }

    public function testRenderOutputsTextInput(): void
    {
        $html = $this->field->render($this->col(), 'Alice', new RenderContext(false));
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('value="Alice"', $html);
    }

    public function testRenderAddsValidationAttributes(): void
    {
        $column  = $this->col('text', '^[A-Z]+$', 'Must be uppercase');
        $html = $this->field->render($column, '', new RenderContext(false));
        $this->assertStringContainsString('data-pattern="^[A-Z]+$"', $html);
        $this->assertStringContainsString('data-message="Must be uppercase"', $html);
    }

    public function testRenderNoValidationAttributesWhenNull(): void
    {
        $html = $this->field->render($this->col(), '', new RenderContext(false));
        $this->assertStringNotContainsString('data-pattern', $html);
        $this->assertStringNotContainsString('data-message', $html);
    }

    public function testRenderReadonlyAddsAttr(): void
    {
        $html = $this->field->render($this->col(), 'Alice', new RenderContext(true));
        $this->assertStringContainsString('readonly', $html);
    }
}
