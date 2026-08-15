<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/bootstrap.php';

os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

$table = $_GET['table'] ?? '';
$col = $_GET['col'] ?? '';
require_once __DIR__ . '/../../includes/config_store.php';
$schemaData = config_get('schema');

if (!is_array($schemaData) || !isset($schemaData['tables'][$table]['foreign_keys'][$col])) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

require_table_access($table);

$refTable = $schemaData['tables'][$table]['foreign_keys'][$col]['reference_table'] ?? '';
if (empty($refTable)) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
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
    $fkCfg   = $schemaData['tables'][$table]['foreign_keys'][$col];
    $labelCols = [(string) ($fkCfg['reference_column'] ?? 'id')];

    foreach (['display_column', 'display_columns'] as $key) {
        $raw = $fkCfg[$key] ?? null;
        foreach (is_array($raw) ? $raw : [$raw] as $name) {
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
exit;
