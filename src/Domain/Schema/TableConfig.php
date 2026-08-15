<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Domain\Schema;

final readonly class TableConfig
{
    public function __construct(
        public string $name,
        public string $schema,
        public string $displayName,
        public array $columns,
        public array $foreignKeys,
        public array $subtables,
        public string $primaryKey = 'id',
        public string $icon = '',
    ) {
    }

    public function visibleColumns(): array
    {
        return array_filter($this->columns, fn(ColumnConfig $c) => $c->showInEdit && !$c->isVirtual());
    }

    public function writableColumns(): array
    {
        return array_filter(
            $this->columns,
            fn(ColumnConfig $c) => $c->name !== $this->primaryKey && !$c->readonly && !$c->isVirtual()
        );
    }

    public function dbColumns(): array
    {
        return array_filter($this->columns, fn(ColumnConfig $c) => !$c->isVirtual());
    }

    public function column(string $name): ColumnConfig
    {
        return $this->columns[$name]
            ?? throw new \InvalidArgumentException("Unknown column: {$name}");
    }

    public function hasForeignKey(string $colName): bool
    {
        return isset($this->foreignKeys[$colName]);
    }

    public function foreignKey(string $colName): array
    {
        return $this->foreignKeys[$colName]
            ?? throw new \InvalidArgumentException("No FK for column: {$colName}");
    }
}
