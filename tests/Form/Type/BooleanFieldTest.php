<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\BooleanField;
use PHPUnit\Framework\TestCase;

final class BooleanFieldTest extends TestCase
{
    private BooleanField $field;

    protected function setUp(): void
    {
        $this->field = new BooleanField();
    }

    private function col(string $type = 'boolean'): ColumnConfig
    {
        return new ColumnConfig('active', $type, 'Active');
    }

    public function testSupportsBoolTypes(): void
    {
        $this->assertTrue($this->field->supports($this->col('boolean'), false));
        $this->assertTrue($this->field->supports($this->col('bool'), false));
    }

    public function testDoesNotSupportNonBool(): void
    {
        $this->assertFalse($this->field->supports($this->col('text'), false));
        $this->assertFalse($this->field->supports($this->col('integer'), false));
    }

    public function testBindCheckedReturnsTrue(): void
    {
        $boundValue = $this->field->bind('active', ['active' => 'on']);
        $this->assertSame('true', $boundValue->value);
        $this->assertSame('boolean', $boundValue->cast);
    }

    public function testBindUncheckedReturnsFalse(): void
    {
        $boundValue = $this->field->bind('active', []);
        $this->assertSame('false', $boundValue->value);
        $this->assertSame('boolean', $boundValue->cast);
    }

    public function testRenderCheckedState(): void
    {
        $html = $this->field->render($this->col(), 'true', new RenderContext(false));
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function testRenderUncheckedState(): void
    {
        $html = $this->field->render($this->col(), 'false', new RenderContext(false));
        $this->assertStringNotContainsString('checked', $html);
    }

    public function testRenderLockedAddsHiddenAndDisabled(): void
    {
        $html = $this->field->render($this->col(), 'true', new RenderContext(true));
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }
}
