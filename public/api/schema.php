<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw HttpException::fromStatus(405, 'Method Not Allowed');
}

$userRole = $_SESSION['role'] ?? 'viewer';
require_once __DIR__ . '/../../includes/config_store.php';
require_once __DIR__ . '/../../includes/images.php';
$schemaData = config_get('schema');
if (!is_array($schemaData) || !isset($schemaData['tables'])) {
    throw new ServerErrorException('Invalid schema format');
}

$publicSchema = [];
$includeHidden = ($_GET['include_hidden'] ?? '0') === '1';
foreach ($schemaData['tables'] as $tableName => $tableConfig) {
    if (!$includeHidden && !empty($tableConfig['hidden'])) {
        continue;
    }

    if (!user_can_access_table($tableName)) {
        continue;
    }

    $publicColumns = [];
    foreach ($tableConfig['columns'] as $colName => $colDef) {
        $publicColumn = [
            'display_name'  => $colDef['display_name'] ?? $colName,
            'type'          => $colDef['type'] ?? 'text',
            'show_in_grid'  => $colDef['show_in_grid'] ?? true,
            'show_in_edit'  => $colDef['show_in_edit'] ?? true,
            'readonly'      => $colDef['readonly'] ?? false,
            'not_null'      => $colDef['not_null'] ?? false,
        ];

        if ($userRole === 'editor') {
            if (!empty($colDef['validation_regexp'])) {
                $publicColumn['validation_regexp'] = $colDef['validation_regexp'];
            }
            if (!empty($colDef['validation_message'])) {
                $publicColumn['validation_message'] = $colDef['validation_message'];
            }
        }

        if (!empty($colDef['description'])) {
            $publicColumn['description'] = $colDef['description'];
        }

        if (!empty($colDef['formula'])) {
            $publicColumn['formula'] = $colDef['formula'];
        }

        if (!empty($colDef['options'])) {
            $publicColumn['options'] = $colDef['options'];
        }
        if (!empty($colDef['enum_colors'])) {
            $publicColumn['enum_colors'] = $colDef['enum_colors'];
        }

        $publicColumns[$colName] = $publicColumn;
    }

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

    $publicSchema[$tableName] = [
        'display_name'    => $tableConfig['display_name'] ?? $tableName,
        'columns'         => $publicColumns,
        'icon'            => $tableConfig['icon'] ?? null,
        'foreign_keys'    => $foreignKeys,
        'subtables'       => $tableConfig['subtables'] ?? [],
        'many_to_many'    => $m2mList,
        'highlight_rules' => $highlightRules,
    ];

    $imagesCfg = images_config($schemaData, $tableName);
    if ($imagesCfg !== null) {
        $publicSchema[$tableName]['images'] = $imagesCfg;
    }
}

$pageSize = null;
if (isset($schemaData['default_page_size'])) {
    $configuredPageSize = (int) $schemaData['default_page_size'];
    if (in_array($configuredPageSize, [10, 25, 50, 100], true)) {
        $pageSize = $configuredPageSize;
    }
}

$response = ['tables' => $publicSchema];
if ($pageSize !== null) {
    $response['default_page_size'] = $pageSize;
}
throw ResponseException::encoded($response);
