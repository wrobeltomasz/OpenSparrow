<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Domain\Schema;

final class JsonSchemaRepository
{
    private array $rawData;

    private array $cache = [];

    public function __construct(string|array $source)
    {
        if (is_array($source)) {
            $data = $source;
        } else {
            $json = file_get_contents($source);
            if ($json === false) {
                throw new \RuntimeException("Cannot read schema file: {$source}");
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid schema JSON in: {$source}");
            }
        }
        $this->rawData = $data;
    }

    public function table(string $name): TableConfig
    {
        if (!$this->hasTable($name)) {
            throw new \InvalidArgumentException("Unknown table: {$name}");
        }
        return $this->cache[$name] ??= $this->build($name, $this->rawData['tables'][$name]);
    }

    public function hasTable(string $name): bool
    {
        return isset($this->rawData['tables'][$name]);
    }

    public function all(): array
    {
        $result = [];
        foreach (array_keys($this->rawData['tables'] ?? []) as $name) {
            $result[$name] = $this->table($name);
        }
        return $result;
    }

    public function raw(): array
    {
        return $this->rawData;
    }

    private function build(string $name, array $config): TableConfig
    {
        $columns = [];
        foreach ($config['columns'] ?? [] as $columnName => $columnConfig) {
            $columns[$columnName] = new ColumnConfig(
                name: $columnName,
                type: $columnConfig['type'] ?? 'text',
                displayName: $columnConfig['display_name'] ?? $columnName,
                readonly: !empty($columnConfig['readonly']),
                notNull: !empty($columnConfig['not_null']),
                showInEdit: ($columnConfig['show_in_edit'] ?? true) !== false,
                options: $columnConfig['options'] ?? [],
                enumColors: $columnConfig['enum_colors'] ?? [],
                validationRegexp: $columnConfig['validation_regexp'] ?? null,
                validationMessage: $columnConfig['validation_message'] ?? null,
            );
        }
        return new TableConfig(
            name: $name,
            schema: $config['schema'] ?? 'public',
            displayName: $config['display_name'] ?? $name,
            columns: $columns,
            foreignKeys: $config['foreign_keys'] ?? [],
            subtables: $config['subtables'] ?? [],
            primaryKey: 'id',
            icon: $config['icon'] ?? '',
        );
    }
}
