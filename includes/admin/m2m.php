<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../config_store.php';
require_once __DIR__ . '/../api_helpers.php';

if ($action === 'list_m2m') {
    $schema = config_get('schema');
    if (!is_array($schema['tables'] ?? null)) {
        echo json_encode(['tables' => [], 'relationships' => []]);
        exit;
    }

    $tables = [];
    $relationships = [];
    foreach ($schema['tables'] as $tName => $tCfg) {
        if (!empty($tCfg['hidden'])) {
            continue;
        }
        $tables[] = [
            'name'         => $tName,
            'display_name' => $tCfg['display_name'] ?? $tName,
            'columns'      => array_keys($tCfg['columns'] ?? []),
        ];
        foreach ($tCfg['many_to_many'] ?? [] as $i => $m2m) {
            $otherTable = $m2m['other_table'] ?? '';
            $relationships[] = [
                'table_a'         => $tName,
                'table_a_display' => $tCfg['display_name'] ?? $tName,
                'table_b'         => $otherTable,
                'table_b_display' => $schema['tables'][$otherTable]['display_name'] ?? $otherTable,
                'junction_table'  => $m2m['junction_table']  ?? '',
                'label'           => $m2m['label']           ?? '',
                'display_column'  => $m2m['display_column']  ?? '',
                'm2m_index'       => $i,
            ];
        }
    }
    echo json_encode(['tables' => $tables, 'relationships' => $relationships]);
    exit;
}

if ($action === 'create_m2m') {
    require_not_demo('Demo mode — writes disabled.');

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA     = $body['table_a']       ?? '';
    $tableB     = $body['table_b']       ?? '';
    $jt         = $body['junction_table'] ?? '';
    $selfFk     = $body['self_fk']       ?? '';
    $otherFk    = $body['other_fk']      ?? '';
    $label      = $body['label']         ?? '';
    $displayCol = $body['display_column'] ?? 'name';

    $identRe = '/^[a-z][a-z0-9_]*$/';
    $identifiers = ['tableA' => $tableA, 'tableB' => $tableB, 'jt' => $jt, 'selfFk' => $selfFk, 'otherFk' => $otherFk];
    foreach ($identifiers as $field => $val) {
        if (!preg_match($identRe, $val)) {
            echo json_encode(['status' => 'error', 'error' => "Invalid identifier: $val"]);
            exit;
        }
    }
    if ($tableA === $tableB) {
        echo json_encode(['status' => 'error', 'error' => 'Tables must be different.']);
        exit;
    }

    $schema = config_get('schema') ?? [];
    if (!isset($schema['tables'][$tableA]) || !isset($schema['tables'][$tableB])) {
        echo json_encode(['status' => 'error', 'error' => 'One or both tables not found in schema.']);
        exit;
    }

    foreach ($schema['tables'][$tableA]['many_to_many'] ?? [] as $existing) {
        if (($existing['junction_table'] ?? '') === $jt) {
            echo json_encode(['status' => 'error', 'error' => "M2M via $jt already exists on $tableA."]);
            exit;
        }
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $pgSchema = $schema['tables'][$tableA]['schema'] ?? 'public';

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s.%s (
                id         SERIAL PRIMARY KEY,
                %s         INT NOT NULL REFERENCES %s.%s(id) ON DELETE CASCADE,
                %s         INT NOT NULL REFERENCES %s.%s(id) ON DELETE CASCADE,
                UNIQUE(%s, %s)
            )',
            pg_ident($pgSchema),
            pg_ident($jt),
            pg_ident($selfFk),
            pg_ident($pgSchema),
            pg_ident($tableA),
            pg_ident($otherFk),
            pg_ident($pgSchema),
            pg_ident($tableB),
            pg_ident($selfFk),
            pg_ident($otherFk)
        );
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'create_m2m');
        }

        if (!isset($schema['tables'][$jt])) {
            $schema['tables'][$jt] = [
                'display_name' => str_replace('_', '–', $jt),
                'schema'       => $pgSchema,
                'hidden'       => true,
                'columns'      => [
                    'id'     => [
                        'display_name' => 'ID',
                        'type' => 'number', 'not_null' => true, 'readonly' => true,
                        'show_in_grid' => true, 'show_in_edit' => true,
                    ],
                    $selfFk  => [
                        'display_name' => ucfirst(str_replace('_', ' ', $selfFk)),
                        'type' => 'number', 'not_null' => true, 'readonly' => false,
                        'show_in_grid' => true, 'show_in_edit' => true,
                    ],
                    $otherFk => [
                        'display_name' => ucfirst(str_replace('_', ' ', $otherFk)),
                        'type' => 'number', 'not_null' => true, 'readonly' => false,
                        'show_in_grid' => true, 'show_in_edit' => true,
                    ],
                ],
                'foreign_keys' => [
                    $selfFk  => ['reference_table' => $tableA, 'reference_column' => 'id', 'display_column' => 'id'],
                    $otherFk => [
                        'reference_table' => $tableB,
                        'reference_column' => 'id',
                        'display_column' => $displayCol,
                    ],
                ],
                'subtables' => [],
            ];
        }

        $existingM2m = $schema['tables'][$tableA]['many_to_many'] ?? null;
        if (!isset($existingM2m) || !is_array($existingM2m)) {
            $schema['tables'][$tableA]['many_to_many'] = [];
        }
        $schema['tables'][$tableA]['many_to_many'][] = [
            'label'          => $label ?: ucfirst($tableB),
            'junction_table' => $jt,
            'self_fk'        => $selfFk,
            'other_fk'       => $otherFk,
            'other_table'    => $tableB,
            'display_column' => $displayCol,
        ];

        $m2mUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $m2mResult = config_save('schema', $schema, null, $m2mUserId);
        if ($m2mResult['status'] !== 'ok') {
            echo json_encode(['status' => 'error', 'error' => $m2mResult['error'] ?? 'Failed to save schema.']);
            exit;
        }

        echo json_encode(['status' => 'success', 'junction_table' => $jt]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'delete_m2m') {
    require_not_demo('Demo mode — writes disabled.');

    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA        = $body['table_a']      ?? '';
    $m2mIndex      = (int)($body['m2m_index'] ?? -1);
    $junctionTable = $body['junction_table'] ?? '';
    $dropTable     = !empty($body['drop_table']);

    if (!preg_match('/^[a-z][a-z0-9_]*$/', $tableA)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid table_a.']);
        exit;
    }

    $schema = config_get('schema') ?? [];

    if (!isset($schema['tables'][$tableA]['many_to_many'][$m2mIndex])) {
        echo json_encode(['status' => 'error', 'error' => 'M2M entry not found.']);
        exit;
    }

    array_splice($schema['tables'][$tableA]['many_to_many'], $m2mIndex, 1);
    if (empty($schema['tables'][$tableA]['many_to_many'])) {
        unset($schema['tables'][$tableA]['many_to_many']);
    }

    try {
        if ($dropTable && preg_match('/^[a-z][a-z0-9_]*$/', $junctionTable)) {
            require_once __DIR__ . '/../../includes/db.php';
            $conn     = db_connect();
            $pgSchema = $schema['tables'][$junctionTable]['schema'] ?? 'public';
            @pg_query($conn, sprintf('DROP TABLE IF EXISTS %s.%s', pg_ident($pgSchema), pg_ident($junctionTable)));
        }

        $junctionIsHidden = $schema['tables'][$junctionTable]['hidden'] ?? null;
        if ($junctionTable && $junctionIsHidden === true) {
            $stillUsed = false;
            foreach ($schema['tables'] as $tCfg) {
                foreach ($tCfg['many_to_many'] ?? [] as $m) {
                    if (($m['junction_table'] ?? '') === $junctionTable) {
                        $stillUsed = true;
                        break 2;
                    }
                }
            }
            if (!$stillUsed) {
                unset($schema['tables'][$junctionTable]);
            }
        }

        $m2mUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $m2mResult = config_save('schema', $schema, null, $m2mUserId);
        if ($m2mResult['status'] !== 'ok') {
            echo json_encode(['status' => 'error', 'error' => $m2mResult['error'] ?? 'Failed to save schema.']);
            exit;
        }

        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
