<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class ApiConfigValidator
{
    public const FILTER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains'];

    public const MAX_LIMIT = 1000;

    public const VIEW_NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

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

    public function validate(array $api, array $schema, array $viewsConfig = [], array $viewColumnTypes = []): array
    {
        $name = trim((string) ($api['name'] ?? ''));
        if ($name === '') {
            throw new \AdminApiMessage('Each API needs a name.');
        }

        $type = ($api['type'] ?? '') === 'view' ? 'view' : 'table';

        if ($type === 'view') {
            [$source, $availableColumns, $columnTypes] = $this->validateView($api, $name, $viewsConfig, $viewColumnTypes);
        } else {
            [$source, $availableColumns, $columnTypes] = $this->validateTable($api, $name, $schema);
        }

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
                    'API "' . $name . '": column "' . $column . '" does not exist in '
                    . ($type === 'view' ? 'view "' : 'table "') . $source . '".'
                );
            }
        }

        $filters = $this->validateFilters($api, $name, $source, $availableColumns, $columnTypes);

        $limit = (int) ($api['limit'] ?? 100);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $limit = 100;
        }

        return [
            'name'    => $name,
            'type'    => $type,
            'table'   => $source,
            'columns' => $columns,
            'filters' => $filters,
            'limit'   => $limit,
        ];
    }

    private function validateView(
        array $api,
        string $name,
        array $viewsConfig,
        array $viewColumnTypes
    ): array {
        $view = trim((string) ($api['table'] ?? ''));
        if ($view === '' || !is_array($viewsConfig['views'][$view] ?? null)) {
            throw new \AdminApiMessage(
                'API "' . $name . '": the selected view does not exist in the views configuration.'
            );
        }
        $viewConfig = $viewsConfig['views'][$view];
        if (!empty($viewConfig['hidden'])) {
            throw new \AdminApiMessage(
                'API "' . $name . '": hidden views cannot be exposed through the external API.'
            );
        }
        if (($viewConfig['source'] ?? 'postgres') !== 'postgres') {
            throw new \AdminApiMessage(
                'API "' . $name . '": only PostgreSQL views can be exposed through the external API.'
            );
        }
        if (preg_match(self::VIEW_NAME_PATTERN, $view) !== 1) {
            throw new \AdminApiMessage(
                'API "' . $name . '": the view name "' . $view . '" is not a valid identifier.'
            );
        }

        $liveColumns = $viewColumnTypes[$view] ?? [];
        if (is_array($liveColumns) && $liveColumns !== []) {
            return [$view, array_keys($liveColumns), $liveColumns];
        }

        $configColumns = array_keys(array_filter(
            (array) ($viewConfig['columns'] ?? []),
            static fn($column) => is_array($column)
        ));
        return [$view, $configColumns, []];
    }

    private function validateTable(array $api, string $name, array $schema): array
    {
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
        $realColumns = array_keys(array_filter(
            $tableConfig['columns'] ?? [],
            static fn($column) => ($column['type'] ?? '') !== 'virtual'
        ));

        $columnTypes = ['id' => 'number'];
        foreach ($realColumns as $column) {
            $columnTypes[$column] = (string) ($tableConfig['columns'][$column]['type'] ?? 'text');
        }

        return [$table, array_merge(['id'], $realColumns), $columnTypes];
    }

    private function validateFilters(
        array $api,
        string $name,
        string $source,
        array $availableColumns,
        array $columnTypes
    ): array {
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
                    'API "' . $name . '": filter column "' . $column . '" does not exist in '
                    . 'the selected source "' . $source . '".'
                );
            }
            if (!in_array($operator, self::FILTER_OPERATORS, true)) {
                throw new \AdminApiMessage('API "' . $name . '": unsupported filter operator "' . $operator . '".');
            }
            if ($operator !== 'contains') {
                try {
                    $value = ApiFilterValueCoercer::coerce(
                        $name,
                        $column,
                        (string) ($columnTypes[$column] ?? 'text'),
                        $value
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new \AdminApiMessage($exception->getMessage());
                }
            }
            $filters[] = [
                'column'   => $column,
                'operator' => $operator,
                'value'    => $value,
            ];
        }
        return $filters;
    }
}
