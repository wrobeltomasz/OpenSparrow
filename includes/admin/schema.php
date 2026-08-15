<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'create_table') {
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);

    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? 'public'));
    $tableName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table'] ?? ''));

    if (empty($tableName) || empty($schemaName)) {
        admin_err('Invalid schema or table name.');
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable = pg_escape_identifier($conn, $tableName);

        $sql = "CREATE TABLE " . $safeSchema . "." . $safeTable . " (id serial4 NOT NULL PRIMARY KEY)";
        $result = @pg_query($conn, $sql);

        if (!$result) {
            admin_db_fail($conn, 'create_table');
        }

        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'add_column') {
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);

    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? ''));
    $tableName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']  ?? ''));
    $colName    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['column'] ?? ''));
    $colType    = $input['type'] ?? 'varchar(255)';
    $comment    = isset($input['comment']) ? trim((string)$input['comment']) : '';
    $fkTable    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_table']  ?? ''));
    $fkCol      = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_column'] ?? ''));
    $indexType  = $input['index'] ?? '';
    $notNull    = !empty($input['not_null']);
    $default    = trim((string)($input['default'] ?? ''));

    if (empty($tableName) || empty($colName)) {
        admin_err('Invalid table or column name.');
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        if ($schemaName === '') {
            $schemaName = sys_schema();
        }
        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable  = pg_escape_identifier($conn, $tableName);
        $safeCol    = pg_escape_identifier($conn, $colName);

        $allowedTypes = ['varchar(255)', 'int4', 'int8', 'boolean', 'text', 'date', 'timestamp', 'timestamptz'];
        if (!in_array($colType, $allowedTypes, true)) {
            throw new AdminApiMessage('Invalid data type provided.');
        }

        $sql = "ALTER TABLE " . $safeSchema . "." . $safeTable . " ADD COLUMN " . $safeCol . " " . $colType;

        if ($default !== '') {
            $safeExpressions = ['now()', 'current_timestamp', 'current_date', 'current_time', 'true', 'false', 'null'];
            if (in_array(strtolower($default), $safeExpressions, true)) {
                $sql .= ' DEFAULT ' . strtolower($default);
            } elseif (preg_match('/^\-?\d+(\.\d+)?$/', $default)) {
                $sql .= ' DEFAULT ' . $default;
            } else {
                $sql .= ' DEFAULT ' . pg_escape_literal($conn, $default);
            }
        }

        if ($notNull) {
            $sql .= ' NOT NULL';
        }

        $result = @pg_query($conn, $sql);
        if (!$result) {
            admin_db_fail($conn, 'add_column');
        }

        if ($comment !== '') {
            $safeComment = pg_escape_literal($conn, $comment);
            $sqlComment = "COMMENT ON COLUMN " . $safeSchema . "." . $safeTable . "." . $safeCol
                . " IS " . $safeComment;
            @pg_query($conn, $sqlComment);
        }

        if ($fkTable !== '' && $fkCol !== '') {
            $safeFkTable  = pg_escape_identifier($conn, $fkTable);
            $safeFkCol    = pg_escape_identifier($conn, $fkCol);
            $constraintName = pg_escape_identifier($conn, 'fk_' . $tableName . '_' . $colName);
            $foreignKeySql = "ALTER TABLE " . $safeSchema . "." . $safeTable
                . " ADD CONSTRAINT " . $constraintName
                . " FOREIGN KEY (" . $safeCol . ")"
                . " REFERENCES " . $safeSchema . "." . $safeFkTable . " (" . $safeFkCol . ")";
            $resFk = @pg_query($conn, $foreignKeySql);
            if (!$resFk) {
                admin_db_fail($conn, 'add_column_fk');
            }
        }

        $allowedIndexTypes = ['btree', 'hash', 'unique'];
        if (in_array($indexType, $allowedIndexTypes, true)) {
            $idxName = pg_escape_identifier($conn, 'idx_' . $tableName . '_' . $colName);
            $unique  = $indexType === 'unique' ? 'UNIQUE ' : '';
            $using   = $indexType === 'hash' ? 'HASH' : 'BTREE';
            $sqlIdx  = "CREATE {$unique}INDEX {$idxName} ON " . $safeSchema . "." . $safeTable
                . " USING {$using} (" . $safeCol . ")";
            $resIdx  = @pg_query($conn, $sqlIdx);
            if (!$resIdx) {
                admin_db_fail($conn, 'add_column_index');
            }
        }

        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'schema_add_table') {
    require_not_demo('Disabled in Demo Mode.', 403);

    $input       = json_decode(file_get_contents('php://input'), true);
    $tableName   = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']   ?? ''));
    $schemaName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema']  ?? 'public'));
    $displayName = trim(strip_tags((string)($input['display_name'] ?? '')));
    $columns     = is_array($input['columns'] ?? null) ? $input['columns'] : [];

    if (empty($tableName)) {
        admin_err('Table name is required.');
    }

    if ($displayName === '') {
        $displayName = ucwords(str_replace('_', ' ', $tableName));
    }

    $typeMap = [
        'varchar(255)' => 'text',
        'text'         => 'text',
        'int4'         => 'number',
        'int8'         => 'number',
        'boolean'      => 'boolean',
        'date'         => 'date',
        'timestamp'    => 'datetime',
    ];

    $colsObj = [
        'id' => [
            'display_name' => 'ID', 'type' => 'number', 'not_null' => true,
            'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true,
        ],
    ];

    foreach ($columns as $column) {
        $cName = preg_replace('/[^a-z0-9_]/', '', strtolower($column['name'] ?? ''));
        $cType = $column['type'] ?? 'varchar(255)';
        if ($cName === '' || !isset($typeMap[$cType])) {
            continue;
        }
        $cDisplay = trim(strip_tags((string)($column['display_name'] ?? '')));
        if ($cDisplay === '') {
            $cDisplay = ucwords(str_replace('_', ' ', $cName));
        }
        $entry = [
            'display_name' => $cDisplay,
            'type'         => $typeMap[$cType],
            'not_null'     => !empty($column['not_null']),
            'show_in_grid' => true,
            'show_in_edit' => true,
            'readonly'     => false,
        ];
        if (!empty($column['description'])) {
            $entry['description'] = trim(strip_tags((string)$column['description']));
        }
        if (!empty($column['fk_table']) && !empty($column['fk_column'])) {
            $entry['fk_table']  = preg_replace('/[^a-z0-9_]/', '', strtolower($column['fk_table']));
            $entry['fk_column'] = preg_replace('/[^a-z0-9_]/', '', strtolower($column['fk_column']));
        }
        $colsObj[$cName] = $entry;
    }

    require_once __DIR__ . '/../config_store.php';
    $schemaData = config_get('schema') ?? [];
    if (!isset($schemaData['tables'])) {
        $schemaData['tables'] = [];
    }

    $schemaData['tables'][$tableName] = [
        'display_name' => $displayName,
        'schema'       => $schemaName,
        'columns'      => $colsObj,
        'foreign_keys' => [],
        'subtables'    => [],
        'hidden'       => false,
        'icon'         => '',
    ];

    $schemaUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $schemaResult = config_save('schema', $schemaData, null, $schemaUserId);
    if ($schemaResult['status'] !== 'ok') {
        admin_err($schemaResult['error'] ?? 'Could not save schema.');
    }
    admin_ok();
}

if ($action === 'list_system_tables') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sysSchema = sys_schema();
        $sql = "SELECT table_name, table_schema FROM information_schema.tables
                WHERE table_schema = \$1 AND table_name LIKE 'spw\\_%' ESCAPE '\\'
                AND table_type = 'BASE TABLE' ORDER BY table_name";
        $result = @pg_query_params($conn, $sql, [$sysSchema]);
        if (!$result) {
            admin_db_fail($conn, 'list_system_tables');
        }
        $tables = [];
        while ($row = pg_fetch_assoc($result)) {
            $tables[] = ['name' => $row['table_name'], 'schema' => $row['table_schema']];
        }
        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'sync_schema') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $schemaName = $body['schema_name']
            ?? os_request()->post('schema_name', os_request()->query('schema_name', 'public'));

        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = $1"
            . " AND table_type = 'BASE TABLE' AND table_name NOT LIKE 'spw\\_%' ESCAPE '\\'";
        $result = @pg_query_params($conn, $sql, [$schemaName]);
        if (!$result) {
            admin_db_fail($conn, 'sync_schema');
        }

        $tables = [];
        while ($row = pg_fetch_assoc($result)) {
            $tables[] = $row['table_name'];
        }

        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'get_db_columns') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $tableName = $body['table'] ?? os_request()->post('table', os_request()->query('table'));
        $schemaName = $body['schema_name']
            ?? os_request()->post('schema_name', os_request()->query('schema_name', 'public'));
        $sql = "
            SELECT
                c.column_name,
                c.data_type,
                c.is_nullable,
                c.udt_name,
                c.ordinal_position,
                pgd.description
            FROM information_schema.columns c
            LEFT JOIN pg_catalog.pg_statio_all_tables st
                ON st.schemaname = c.table_schema AND st.relname = c.table_name
            LEFT JOIN pg_catalog.pg_description pgd
                ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
            WHERE c.table_schema = \$1 AND c.table_name = \$2
            ORDER BY c.ordinal_position
        ";
        $result = @pg_query_params($conn, $sql, [$schemaName, $tableName]);
        if (!$result) {
            admin_db_fail($conn, 'get_db_columns');
        }

        $columns = [];
        while ($row = pg_fetch_assoc($result)) {
            $colName = $row['column_name'];
            $dataType = $row['data_type'];
            $udtName = $row['udt_name'];
            $enumValues = null;

            if ($dataType === 'USER-DEFINED') {
                $safeSchema = pg_escape_identifier($conn, $schemaName);
                $safeUdt = pg_escape_identifier($conn, $udtName);
                $enumSql = "SELECT unnest(enum_range(NULL::$safeSchema.$safeUdt))::varchar AS enum_value";
                $enumResult = @pg_query($conn, $enumSql);
                if ($enumResult) {
                    $enumValues = [];
                    while ($enumRow = pg_fetch_assoc($enumResult)) {
                        $enumValues[] = $enumRow['enum_value'];
                    }
                }
            }

            $colData = [
                'column_name' => $colName,
                'type' => $dataType,
                'not_null' => ($row['is_nullable'] === 'NO'),
                'display_name' => ucfirst(str_replace('_', ' ', $colName))
            ];
            if (!empty($row['description'])) {
                $colData['description'] = $row['description'];
            }
            if ($enumValues !== null) {
                $colData['enum_values'] = $enumValues;
            }

            $columns[] = $colData;
        }

        echo json_encode(['status' => 'success', 'columns' => $columns]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
