<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Domain\Schema;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;
use PHPUnit\Framework\TestCase;

final class TableConfigTest extends TestCase
{
    private function makeTable(array $columns, array $foreignKeys = []): TableConfig
    {
        return new TableConfig('users', 'app', 'Users', $columns, $foreignKeys, []);
    }

    private function col(
        string $name,
        string $type = 'text',
        bool $readonly = false,
        bool $showInEdit = true
    ): ColumnConfig {
        return new ColumnConfig($name, $type, $name, $readonly, false, $showInEdit);
    }

    public function testWritableColumnsExcludesPK(): void
    {
        $table = $this->makeTable(['id' => $this->col('id'), 'name' => $this->col('name')]);
        $writable = $table->writableColumns();
        $this->assertArrayHasKey('name', $writable);
        $this->assertArrayNotHasKey('id', $writable);
    }

    public function testWritableColumnsExcludesReadonly(): void
    {
        $table = $this->makeTable([
            'id'         => $this->col('id'),
            'name'       => $this->col('name'),
            'created_at' => $this->col('created_at', 'timestamp', true),
        ]);
        $this->assertArrayNotHasKey('created_at', $table->writableColumns());
    }

    public function testWritableColumnsExcludesVirtual(): void
    {
        $table = $this->makeTable([
            'id'    => $this->col('id'),
            'label' => new ColumnConfig('label', 'virtual', 'Label'),
        ]);
        $this->assertArrayNotHasKey('label', $table->writableColumns());
    }

    public function testVisibleColumnsExcludesHiddenFromEdit(): void
    {
        $table = $this->makeTable([
            'id'   => $this->col('id', 'text', false, false),
            'name' => $this->col('name'),
        ]);
        $visible = $table->visibleColumns();
        $this->assertArrayHasKey('name', $visible);
        $this->assertArrayNotHasKey('id', $visible);
    }

    public function testVisibleColumnsExcludesVirtual(): void
    {
        $table = $this->makeTable([
            'label' => new ColumnConfig('label', 'virtual', 'Label'),
            'name'  => $this->col('name'),
        ]);
        $this->assertArrayNotHasKey('label', $table->visibleColumns());
    }

    public function testColumnThrowsOnUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeTable([])->column('nonexistent');
    }

    public function testColumnReturnsKnown(): void
    {
        $column   = $this->col('name');
        $table = $this->makeTable(['name' => $column]);
        $this->assertSame($column, $table->column('name'));
    }

    public function testHasForeignKey(): void
    {
        $table = $this->makeTable([], ['user_id' => ['table' => 'users']]);
        $this->assertTrue($table->hasForeignKey('user_id'));
        $this->assertFalse($table->hasForeignKey('name'));
    }

    public function testForeignKeyThrowsOnUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeTable([])->foreignKey('missing');
    }
}
