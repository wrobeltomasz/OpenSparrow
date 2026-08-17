<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Form;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use App\Form\UpdateMapper;
use App\Form\ValidationException;
use PHPUnit\Framework\TestCase;

final class UpdateMapperTest extends TestCase
{
    private function passthroughType(): FieldTypeInterface
    {
        return new class implements FieldTypeInterface {
            public function supports(ColumnConfig $column, bool $hasForeignKey): bool
            {
                return true;
            }
            public function bind(string $columnName, array $postData): BoundValue
            {
                return new BoundValue($postData[$columnName] ?? null);
            }
            public function render(ColumnConfig $column, mixed $currentValue, RenderContext $context): string
            {
                return '';
            }
        };
    }

    public function testFromPostBuildsBindingsForWritableColumns(): void
    {
        $column   = new ColumnConfig('name', 'text', 'Name');
        $table = new TableConfig('users', 'app', 'Users', ['name' => $column], [], []);

        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($table, ['name' => 'Alice']);

        $this->assertFalse($recordData->isEmpty());
        $this->assertSame('name', $recordData->bindings[0]['col']);
        $this->assertSame('Alice', $recordData->bindings[0]['bound']->value);
    }

    public function testFromPostSkipsPrimaryKey(): void
    {
        $id    = new ColumnConfig('id', 'integer', 'ID');
        $name  = new ColumnConfig('name', 'text', 'Name');
        $table = new TableConfig('users', 'app', 'Users', ['id' => $id, 'name' => $name], [], []);

        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($table, ['id' => '99', 'name' => 'Bob']);

        $columns = array_column($recordData->bindings, 'col');
        $this->assertNotContains('id', $columns);
        $this->assertContains('name', $columns);
    }

    public function testFromPostReturnsEmptyRecordWhenNoWritableColumns(): void
    {
        $table  = new TableConfig('users', 'app', 'Users', [], [], []);
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($table, ['name' => 'Alice']);
        $this->assertTrue($recordData->isEmpty());
    }

    public function testFromPostPassesFkFlagToRegistry(): void
    {
        $type = new class implements FieldTypeInterface {
            public array $seenFk = [];
            public function supports(ColumnConfig $column, bool $hasFk): bool
            {
                $this->seenFk[$column->name] = $hasFk;
                return true;
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

        $column   = new ColumnConfig('user_id', 'integer', 'User');
        $table = new TableConfig(
            'orders', 'app', 'Orders',
            ['user_id' => $column],
            ['user_id' => ['table' => 'users']],
            []
        );

        $mapper = new UpdateMapper(new FieldTypeRegistry([$type]));
        $mapper->fromPost($table, []);
        $this->assertTrue($type->seenFk['user_id']);
    }

    private function regexpTable(ColumnConfig $column): TableConfig
    {
        return new TableConfig('t', 'app', 'T', [$column->name => $column], [], []);
    }

    public function testValueMatchingValidationRegexpPasses(): void
    {
        $column    = new ColumnConfig('code', 'text', 'Code', validationRegexp: '^[A-Z]{3}$');
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($this->regexpTable($column), ['code' => 'ABC']);
        $this->assertSame('ABC', $recordData->bindings[0]['bound']->value);
    }

    public function testValueViolatingValidationRegexpThrowsWithColumnMessage(): void
    {
        $column = new ColumnConfig(
            'code',
            'text',
            'Code',
            validationRegexp: '^[A-Z]{3}$',
            validationMessage: 'Three uppercase letters required'
        );
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Three uppercase letters required');
        $mapper->fromPost($this->regexpTable($column), ['code' => 'abc']);
    }

    public function testEmptyValueSkipsValidationRegexp(): void
    {
        $column    = new ColumnConfig('code', 'text', 'Code', validationRegexp: '^[A-Z]{3}$');
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($this->regexpTable($column), ['code' => '']);
        $this->assertSame('', $recordData->bindings[0]['bound']->value);
    }

    public function testUnanchoredPatternMatchesSubstringLikeClient(): void
    {
        $column    = new ColumnConfig('note', 'text', 'Note', validationRegexp: '[0-9]{2}');
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($this->regexpTable($column), ['note' => 'abc12def']);
        $this->assertSame('abc12def', $recordData->bindings[0]['bound']->value);
    }

    public function testInvalidPatternFailsOpenLikeClient(): void
    {
        $column    = new ColumnConfig('code', 'text', 'Code', validationRegexp: '[unclosed');
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $recordData     = $mapper->fromPost($this->regexpTable($column), ['code' => 'anything']);
        $this->assertSame('anything', $recordData->bindings[0]['bound']->value);
    }
}
