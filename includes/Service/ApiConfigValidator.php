<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class ApiConfigValidator
{
    public const FILTER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains'];

    public const MAX_LIMIT = 1000;

    public static function coerceBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    public function validate(array $api, array $schema): array
    {
        $name = trim((string) ($api['name'] ?? ''));
        if ($name === '') {
            throw new \AdminApiMessage('Each API needs a name.');
        }

        $table = trim((string) ($api['table'] ?? ''));
        if ($table === '' || !isset($schema['tables'][$table])) {
            throw new \AdminApiMessage(
                'API "' . $name . '": the selected table does not exist in the schema configuration.'
            );
        }
        $tableConfig = $schema['tables'][$table];
        if (!empty($tableConfig['hidden'])) {
            throw new \AdminApiMessage(
                'API "' . $name . '": hidden tables cannot be exposed through the external API.'
            );
        }
        if (!empty($tableConfig['owner_restricted'])) {
            throw new \AdminApiMessage(
                'API "' . $name . '": owner-restricted tables cannot be exposed through the external API.'
            );
        }
        if (\is_system_table($table)) {
            throw new \AdminApiMessage(
                'API "' . $name . '": system tables cannot be exposed through the external API.'
            );
        }
        $availableColumns = array_merge(['id'], array_keys(array_filter(
            $tableConfig['columns'] ?? [],
            static fn($column) => ($column['type'] ?? '') !== 'virtual'
        )));

        $columns = array_values(array_unique(array_filter(
            array_map('trim', (array) ($api['columns'] ?? [])),
            static fn($column) => $column !== ''
        )));
        if ($columns === []) {
            throw new \AdminApiMessage('API "' . $name . '": select at least one column.');
        }
        foreach ($columns as $column) {
            if (!in_array($column, $availableColumns, true)) {
                throw new \AdminApiMessage(
                    'API "' . $name . '": column "' . $column . '" does not exist in table "' . $table . '".'
                );
            }
        }

        $filters = [];
        foreach ((array) ($api['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $column = trim((string) ($filter['column'] ?? ''));
            $operator = trim((string) ($filter['operator'] ?? 'eq'));
            $value = (string) ($filter['value'] ?? '');
            if ($value === '') {
                continue;
            }
            if ($column === '' || !in_array($column, $availableColumns, true)) {
                throw new \AdminApiMessage(
                    'API "' . $name . '": filter column "' . $column . '" does not exist in table "' . $table . '".'
                );
            }
            if (!in_array($operator, self::FILTER_OPERATORS, true)) {
                throw new \AdminApiMessage('API "' . $name . '": unsupported filter operator "' . $operator . '".');
            }
            if ($operator !== 'contains') {
                $value = $this->coerceFilterValue($name, $column, $this->columnType($tableConfig, $column), $value);
            }
            $filters[] = [
                'column'   => $column,
                'operator' => $operator,
                'value'    => $value,
            ];
        }

        $limit = (int) ($api['limit'] ?? 100);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $limit = 100;
        }

        return [
            'name'    => $name,
            'table'   => $table,
            'columns' => $columns,
            'filters' => $filters,
            'limit'   => $limit,
        ];
    }

    private function columnType(array $tableConfig, string $column): string
    {
        if ($column === 'id') {
            return 'number';
        }
        return (string) ($tableConfig['columns'][$column]['type'] ?? 'text');
    }

    private function coerceFilterValue(string $name, string $column, string $type, string $value): string
    {
        return match ($this->normalizeType($type)) {
            'number'   => $this->coerceNumber($name, $column, $value),
            'boolean'  => $this->coerceBoolean($name, $column, $value),
            'date'     => $this->coerceDate($name, $column, $value),
            'datetime' => $this->coerceDatetime($name, $column, $value),
            default    => $value,
        };
    }

    private function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (
            $normalized === 'number'
            || str_contains($normalized, 'int')
            || str_contains($normalized, 'numeric')
            || str_contains($normalized, 'float')
        ) {
            return 'number';
        }
        if (str_contains($normalized, 'bool')) {
            return 'boolean';
        }
        if (str_contains($normalized, 'timestamp') || str_contains($normalized, 'datetime')) {
            return 'datetime';
        }
        if (str_contains($normalized, 'date')) {
            return 'date';
        }
        return 'text';
    }

    private function coerceNumber(string $name, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a whole number for column "' . $column . '".'
            );
        }
        return $trimmed;
    }

    private function coerceBoolean(string $name, string $column, string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['true', 'false', '1', '0', 't', 'f'], true)) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a boolean for column "' . $column . '".'
            );
        }
        return in_array($normalized, ['true', '1', 't'], true) ? 'TRUE' : 'FALSE';
    }

    private function coerceDate(string $name, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches) !== 1
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a valid date (YYYY-MM-DD) for column "' . $column . '".'
            );
        }
        return $trimmed;
    }

    private function coerceDatetime(string $name, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $trimmed, $matches) !== 1) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (isset($matches[4]) && ((int) $matches[4] > 23 || (int) $matches[5] > 59)) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (isset($matches[6]) && (int) $matches[6] > 59) {
            throw new \AdminApiMessage(
                'API "' . $name . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        return $trimmed;
    }
}
