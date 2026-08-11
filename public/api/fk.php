<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// api/fk.php — Foreign-key options lookup for FK dropdowns (AJAX-only, read-only)
// Auth gate: session + X-Requested-With header required; UA enforcement
// GET table+col -> resolves reference_table from schema.json foreign_keys, returns selectable rows
// Defensive: returns {"rows":[]} on any failure (missing schema, unknown relation)

require_once __DIR__ . '/../../includes/bootstrap.php';

// Read-only AJAX endpoint: auth + AJAX gates, no CSRF, no DB connection here
// (the request is delegated to api.php below, which connects itself)
os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

$table = $_GET['table'] ?? '';
$col = $_GET['col'] ?? '';
require_once __DIR__ . '/../../includes/config_store.php';
$schemaData = config_get('schema');
// Verify valid schema structure and check if relation exists
if (!is_array($schemaData) || !isset($schemaData['tables'][$table]['foreign_keys'][$col])) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

// The SOURCE table is request-supplied, so it is gated. The reference table below
// is not: it comes from the schema config, and a user legitimately working on an
// allowed table must still be able to resolve its FK labels.
require_table_access($table);

$refTable = $schemaData['tables'][$table]['foreign_keys'][$col]['reference_table'] ?? '';
if (empty($refTable)) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

// Validate optional filter_col against reference table columns to prevent injection
$filterCol = $_GET['filter_col'] ?? '';
$filterVal = $_GET['filter_val'] ?? '';
if ($filterCol !== '') {
    $refColumns = array_keys($schemaData['tables'][$refTable]['columns'] ?? []);
    if (!in_array($filterCol, $refColumns, true)) {
        unset($_GET['filter_col'], $_GET['filter_val']);
    }
}

// A reference table outside the user's scope stays readable — a dropdown on an
// allowed table cannot render without its labels — but only as labels. Name the
// columns a dropdown consumes (the key plus the configured display columns) and
// api.php narrows its projection to them, so the exemption below can no longer be
// turned into a full read of a table the user may not open. Filtering and search
// then span nothing but these columns too, because the list branch derives both
// from the same projection.
if (!user_can_access_table($refTable)) {
    $fkCfg   = $schemaData['tables'][$table]['foreign_keys'][$col];
    $labelCols = [(string) ($fkCfg['reference_column'] ?? 'id')];
    // display_column may be a string or a list; display_columns is the plural spelling
    // the workflow wizard writes. Both shapes appear in stored schemas.
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

// Rewrite GET parameters to simulate a direct call to api.php for the reference table.
// The constant tells api.php's list branch that this table name came from the schema
// config, not from the client, so the per-user table gate must not apply to it —
// without it every FK pointing outside the user's tables would return 403.
define('OS_TABLE_ACCESS_DELEGATED', true);
$_GET['api'] = 'list';
$_GET['table'] = $refTable;
// Delegate response generation to main API handler
require __DIR__ . '/../api.php';
exit;
