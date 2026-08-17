<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\AppContext;

final class SchemaController
{
    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context)
    {
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');

        if ($this->request->method() !== 'GET') {
            throw HttpException::fromStatus(405, 'Method Not Allowed');
        }

        $userRole = $this->session->role();
        $schemaData = config_get('schema');
        if (!is_array($schemaData) || !isset($schemaData['tables'])) {
            throw new ServerErrorException('Invalid schema format');
        }

        $publicSchema = [];
        $includeHidden = $this->request->query('include_hidden', '0') === '1';
        foreach ($schemaData['tables'] as $tableName => $tableConfig) {
            if (!$includeHidden && !empty($tableConfig['hidden'])) {
                continue;
            }

            if (!user_can_access_table($tableName)) {
                continue;
            }

            $publicSchema[$tableName] = [
                'display_name'    => $tableConfig['display_name'] ?? $tableName,
                'columns'         => $this->publicColumns($tableConfig, $userRole),
                'icon'            => $tableConfig['icon'] ?? null,
                'foreign_keys'    => $this->foreignKeys($tableConfig),
                'subtables'       => $tableConfig['subtables'] ?? [],
                'many_to_many'    => $this->manyToMany($tableConfig),
                'highlight_rules' => $this->highlightRules($tableConfig),
            ];

            $imagesCfg = images_config($schemaData, $tableName);
            if ($imagesCfg !== null) {
                $publicSchema[$tableName]['images'] = $imagesCfg;
            }
        }

        $response = ['tables' => $publicSchema];
        $pageSize = $this->defaultPageSize($schemaData);
        if ($pageSize !== null) {
            $response['default_page_size'] = $pageSize;
        }
        throw ResponseException::encoded($response);
    }

    private function publicColumns(array $tableConfig, string $userRole): array
    {
        $publicColumns = [];
        foreach ($tableConfig['columns'] as $colName => $columnDefinition) {
            $publicColumn = [
                'display_name'  => $columnDefinition['display_name'] ?? $colName,
                'type'          => $columnDefinition['type'] ?? 'text',
                'show_in_grid'  => $columnDefinition['show_in_grid'] ?? true,
                'show_in_edit'  => $columnDefinition['show_in_edit'] ?? true,
                'readonly'      => $columnDefinition['readonly'] ?? false,
                'not_null'      => $columnDefinition['not_null'] ?? false,
            ];

            if ($userRole === 'editor') {
                if (!empty($columnDefinition['validation_regexp'])) {
                    $publicColumn['validation_regexp'] = $columnDefinition['validation_regexp'];
                }
                if (!empty($columnDefinition['validation_message'])) {
                    $publicColumn['validation_message'] = $columnDefinition['validation_message'];
                }
            }

            if (!empty($columnDefinition['description'])) {
                $publicColumn['description'] = $columnDefinition['description'];
            }

            if (!empty($columnDefinition['formula'])) {
                $publicColumn['formula'] = $columnDefinition['formula'];
            }

            if (!empty($columnDefinition['options'])) {
                $publicColumn['options'] = $columnDefinition['options'];
            }
            if (!empty($columnDefinition['enum_colors'])) {
                $publicColumn['enum_colors'] = $columnDefinition['enum_colors'];
            }

            $publicColumns[$colName] = $publicColumn;
        }

        return $publicColumns;
    }

    private function foreignKeys(array $tableConfig): array
    {
        $foreignKeys = [];
        if (!empty($tableConfig['foreign_keys'])) {
            foreach ($tableConfig['foreign_keys'] as $column => $fk) {
                $foreignKeys[$column] = [
                    'display_column'   => $fk['display_column']   ?? 'id',
                    'reference_table'  => $fk['reference_table']  ?? '',
                    'reference_column' => $fk['reference_column'] ?? 'id',
                    'display_columns'  => $fk['display_columns']  ?? [],
                ];
            }
        }

        return $foreignKeys;
    }

    private function manyToMany(array $tableConfig): array
    {
        $m2mList = [];
        foreach ($tableConfig['many_to_many'] ?? [] as $m2m) {
            $m2mList[] = [
                'label'          => $m2m['label']          ?? '',
                'junction_table' => $m2m['junction_table'] ?? '',
                'self_fk'        => $m2m['self_fk']        ?? '',
                'other_fk'       => $m2m['other_fk']       ?? '',
                'other_table'    => $m2m['other_table']    ?? '',
                'display_column' => $m2m['display_column'] ?? 'id',
            ];
        }

        return $m2mList;
    }

    private function highlightRules(array $tableConfig): array
    {
        $highlightRules = [];
        foreach ($tableConfig['highlight_rules'] ?? [] as $rule) {
            if (empty($rule['column']) || empty($rule['op']) || !isset($rule['value']) || empty($rule['color'])) {
                continue;
            }
            $highlightRules[] = [
                'column' => $rule['column'],
                'op'     => $rule['op'],
                'value'  => $rule['value'],
                'color'  => $rule['color'],
            ];
        }

        return $highlightRules;
    }

    private function defaultPageSize(array $schemaData): ?int
    {
        if (isset($schemaData['default_page_size'])) {
            $configuredPageSize = (int) $schemaData['default_page_size'];
            if (in_array($configuredPageSize, [10, 25, 50, 100], true)) {
                return $configuredPageSize;
            }
        }

        return null;
    }
}
