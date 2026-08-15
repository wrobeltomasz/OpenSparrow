<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Exception\ResponseException;

os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

$table = $_GET['table'] ?? '';
$column = $_GET['col'] ?? '';
require_once __DIR__ . '/../../includes/config_store.php';
$schemaData = config_get('schema');

if (!is_array($schemaData) || !isset($schemaData['tables'][$table]['foreign_keys'][$column])) {
    throw ResponseException::json(['rows' => []]);
}

require_table_access($table);

$refTable = $schemaData['tables'][$table]['foreign_keys'][$column]['reference_table'] ?? '';
if (empty($refTable)) {
    throw ResponseException::json(['rows' => []]);
}

$filterCol = $_GET['filter_col'] ?? '';
$filterVal = $_GET['filter_val'] ?? '';
if ($filterCol !== '') {
    $refColumns = array_keys($schemaData['tables'][$refTable]['columns'] ?? []);
    if (!in_array($filterCol, $refColumns, true)) {
        unset($_GET['filter_col'], $_GET['filter_val']);
    }
}

if (!user_can_access_table($refTable)) {
    $foreignKeyConfig   = $schemaData['tables'][$table]['foreign_keys'][$column];
    $labelCols = [(string) ($foreignKeyConfig['reference_column'] ?? 'id')];

    foreach (['display_column', 'display_columns'] as $key) {
        $rawDisplay = $foreignKeyConfig[$key] ?? null;
        foreach (is_array($rawDisplay) ? $rawDisplay : [$rawDisplay] as $name) {
            if (is_string($name) && $name !== '') {
                $labelCols[] = $name;
            }
        }
    }
    define('OS_FK_LABEL_COLUMNS', array_values(array_unique($labelCols)));
}

define('OS_TABLE_ACCESS_DELEGATED', true);
$_GET['api'] = 'list';
$_GET['table'] = $refTable;

require __DIR__ . '/../api.php';
throw ResponseException::sent();
