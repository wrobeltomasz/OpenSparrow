<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\ForeignKeyField;
use PHPUnit\Framework\TestCase;

final class ForeignKeyFieldTest extends TestCase
{
    private ForeignKeyField $field;

    protected function setUp(): void
    {
        $this->field = new ForeignKeyField();
    }

    private function col(): ColumnConfig
    {
        return new ColumnConfig('user_id', 'integer', 'User');
    }

    public function testSupportsTrueWhenHasForeignKey(): void
    {
        $this->assertTrue($this->field->supports($this->col(), true));
    }

    public function testDoesNotSupportWhenNoForeignKey(): void
    {
        $this->assertFalse($this->field->supports($this->col(), false));
    }

    public function testBindWithValue(): void
    {
        $boundValue = $this->field->bind('user_id', ['user_id' => '5']);
        $this->assertSame('5', $boundValue->value);
    }

    public function testBindEmptyReturnsNull(): void
    {
        $boundValue = $this->field->bind('user_id', ['user_id' => '']);
        $this->assertNull($boundValue->value);
    }

    public function testBindMissingKeyReturnsNull(): void
    {
        $boundValue = $this->field->bind('user_id', []);
        $this->assertNull($boundValue->value);
    }

    public function testRenderOutputsSelectWithFkOptions(): void
    {
        $context  = new RenderContext(false, ['user_id' => [1 => 'Alice', 2 => 'Bob']]);
        $html = $this->field->render($this->col(), 1, $context);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
    }

    public function testRenderLockedAddsDisabledAndHiddenInput(): void
    {
        $context  = new RenderContext(true, ['user_id' => [1 => 'Alice']]);
        $html = $this->field->render($this->col(), 1, $context);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }
}
