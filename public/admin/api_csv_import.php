<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;
use App\Service\CopyImportService;
use App\Service\CsvFileValidator;
use App\Service\CsvImportService;
use App\Service\CsvReader;
use App\Service\ImportRepository;

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_api_errors.php';

os_api_bootstrap(['connect' => false, 'role' => 'admin']);

const CSV_MAX_BYTES   = CsvFileValidator::MAX_BYTES;
const CSV_BATCH_SIZE  = CsvImportService::BATCH_SIZE;
const CSV_PREVIEW_ROWS = 5;

$action = os_request()->query('action');

function csv_fail(string $message, int $code = 400): never
{
    http_response_code($code);
    throw ResponseException::encoded(['status' => 'error', 'error' => $message]);
}

if ($action === 'csv_import_history') {
    try {
        $conn = db_connect();
        $repository = new ImportRepository($conn);
        echo json_encode(['status' => 'success', 'imports' => $repository->getHistory()]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_import_log') {
    $importId = (int) os_request()->query('id');
    if ($importId <= 0) {
        csv_fail('Missing or invalid import id.');
    }
    try {
        $conn = db_connect();
        $repository = new ImportRepository($conn);
        $rows = $repository->getRowLog($importId);
        echo json_encode(['status' => 'success', 'rows' => $rows, 'count' => count($rows)]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_import_upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }
    require_not_demo('Demo mode — CSV import is disabled.');
    $file = $_FILES['csv_file'] ?? null;
    if (!$file) {
        csv_fail('No file uploaded. Use field name "csv_file".');
    }

    try {
        CsvFileValidator::validate($file);
    } catch (\AdminApiMessage $exception) {
        csv_fail(admin_error_message($exception));
    }

    $request   = os_request();
    $allowed   = [',', ';', "\t", '|'];
    $delim     = $request->post('csv_delimiter', ',');
    $delimiter = in_array($delim, $allowed, true) ? $delim : ',';

    $allowedEncodings = ['UTF-8', 'Windows-1250', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-2', 'Windows-1251'];
    $requestedEncoding        = $request->post('csv_encoding', 'UTF-8');
    $encoding   = in_array($requestedEncoding, $allowedEncodings, true) ? $requestedEncoding : 'UTF-8';

    $headers  = [];
    $preview  = [];
    $rowCount = 0;

    foreach (CsvReader::read($file['tmp_name'], $delimiter, $encoding) as $rowNumber => $rowData) {
        if ($rowNumber === 0) {
            $headers = $rowData;
            continue;
        }
        if ($rowCount < CSV_PREVIEW_ROWS) {
            $preview[] = $rowData;
        }
        $rowCount++;
    }

    $importDirectory = os_storage_path('files') . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR;
    if (!is_dir($importDirectory)) {
        mkdir($importDirectory, 0750, true);

        file_put_contents($importDirectory . '.htaccess', "Require all denied\nOptions -Indexes\n");
    }

    $temporaryName  = bin2hex(random_bytes(16)) . '.csv';
    $destinationPath = $importDirectory . $temporaryName;

    if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
        csv_fail('Failed to store the uploaded file on the server.');
    }

    echo json_encode([
        'status'        => 'success',
        'headers'       => $headers,
        'preview'       => $preview,
        'row_count'     => $rowCount,
        'original_name' => basename((string) $file['name']),
        'tmp_name'      => $temporaryName,
    ]);
    throw ResponseException::sent();
}

if ($action === 'csv_import_execute') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }
    require_not_demo('Demo mode — CSV import is disabled.');

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        csv_fail('Invalid JSON body.');
    }

    $temporaryName      = (string) ($body['tmp_name']        ?? '');
    $tableName    = (string) ($body['table']           ?? '');
    $mapping      = $body['mapping']                   ?? [];
    $conflictColumn  = ($body['conflict_column'] ?? '') ?: null;
    $copyMode     = !empty($body['copy_mode']);
    $originalName = (string) ($body['original_name']   ?? 'file.csv');
    $allowed      = [',', ';', "\t", '|'];
    $delim        = (string) ($body['delimiter']       ?? ',');
    $delimiter    = in_array($delim, $allowed, true) ? $delim : ',';
    $allowedEncodings   = ['UTF-8', 'Windows-1250', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-2', 'Windows-1251'];
    $requestedEncoding          = (string) ($body['encoding']        ?? 'UTF-8');
    $encoding     = in_array($requestedEncoding, $allowedEncodings, true) ? $requestedEncoding : 'UTF-8';

    if (!preg_match('/^[a-f0-9]{32}\.csv$/', $temporaryName)) {
        csv_fail('Invalid tmp_name token.');
    }
    if ($tableName === '') {
        csv_fail('Target table not specified.');
    }
    if (!is_array($mapping) || empty($mapping)) {
        csv_fail('No column mapping provided.');
    }

    $csvPath = os_storage_path('files')
        . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . $temporaryName;

    if (!file_exists($csvPath)) {
        csv_fail('Uploaded file not found. Please re-upload the CSV.');
    }

    require_once __DIR__ . '/../../includes/config_store.php';
    $schema = config_get('schema');
    if (!is_array($schema) || !isset($schema['tables'][$tableName])) {
        @unlink($csvPath);
        csv_fail("Table '{$tableName}' not found in schema configuration.");
    }

    $tableConfig = $schema['tables'][$tableName];
    $tableSchema = (string) ($tableConfig['schema'] ?? 'public');
    $schemaColumns  = $tableConfig['columns'] ?? [];

    foreach ($mapping as $csvHeader => $dbColumn) {
        if ($dbColumn !== null && $dbColumn !== '' && !isset($schemaColumns[$dbColumn])) {
            @unlink($csvPath);
            csv_fail("Column '{$dbColumn}' does not exist in table '{$tableName}'.");
        }
    }

    $dbColumns = array_values(array_unique(array_filter($mapping)));
    if ($conflictColumn !== null && $conflictColumn !== '' && !in_array($conflictColumn, $dbColumns, true)) {
        @unlink($csvPath);
        csv_fail("Conflict column '{$conflictColumn}' must be included in the column mapping.");
    }

    $columnTypes = array_map(fn($column) => (string) ($column['type'] ?? 'text'), $schemaColumns);
    $userId   = (int) ($_SESSION['user_id'] ?? 0);

    $importId = 0;
    try {
        $conn    = db_connect();
        $repository    = new ImportRepository($conn);
        $importService = new CsvImportService($conn, $repository);
        $copyService   = new CopyImportService($conn);

        $importId  = $repository->createRecord(
            $userId,
            $originalName,
            $tableName,
            $mapping,
            $copyMode ? null : $conflictColumn
        );
        $startTime = microtime(true);

        if ($copyMode) {
            [$total, $imported, $skipped] = $copyService->execute(
                $csvPath,
                $tableName,
                $tableSchema,
                $mapping,
                $delimiter,
                $encoding
            );
        } else {
            [$total, $imported, $skipped] = $importService->execute(
                $csvPath,
                $tableName,
                $tableSchema,
                $mapping,
                $columnTypes,
                $conflictColumn,
                $importId,
                $delimiter,
                $encoding
            );
        }

        $status = ($total > 0 && $skipped === $total) ? 'failed' : 'done';
        $repository->finalize($importId, $status, $total, $imported, $skipped);

        log_user_action($conn, $userId, 'CSV_IMPORT', $tableName, $importId);

        @unlink($csvPath);

        echo json_encode([
            'status'           => 'success',
            'import_id'        => $importId,
            'total_rows'       => $total,
            'imported_rows'    => $imported,
            'skipped_rows'     => $skipped,
            'has_errors'       => $skipped > 0,
            'elapsed_seconds'  => round(microtime(true) - $startTime, 1),
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        error_log('[csv_import] execute failed: ' . $exception->getMessage());
        if ($importId > 0 && isset($repository)) {
            $repository->finalize($importId, 'failed', 0, 0, 0, 'Import failed — check server error log.');
        }
        @unlink($csvPath);
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_create_table') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }
    require_not_demo('Demo mode — creating tables is disabled.');

    $body       = json_decode((string) file_get_contents('php://input'), true);
    $tableName  = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['table']  ?? '')));
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['schema'] ?? 'public')));
    if ($schemaName === '') {
        $schemaName = 'public';
    }
    $displayName = trim(strip_tags((string) ($body['display_name'] ?? '')));
    $rawColumns     = is_array($body['columns'] ?? null) ? $body['columns'] : [];

    if ($tableName === '') {
        csv_fail('Table name is required.');
    }

    $allowedTypes = ['varchar(255)', 'text', 'int4', 'int8', 'boolean', 'date', 'timestamp', 'timestamptz'];

    $columnDefinitions = [];
    $seen    = [];
    foreach ($rawColumns as $columnDefinition) {
        $columnName = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($columnDefinition['name'] ?? '')));
        $columnType = in_array((string) ($columnDefinition['type'] ?? ''), $allowedTypes, true)
            ? (string) $columnDefinition['type']
            : 'varchar(255)';
        if ($columnName === '' || $columnName === 'id' || isset($seen[$columnName])) {
            continue;
        }
        $seen[$columnName] = true;
        $columnDefinitions[]    = ['name' => $columnName, 'type' => $columnType];
    }

    try {
        $conn = db_connect();

        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable  = pg_escape_identifier($conn, $tableName);

        @pg_query($conn, 'BEGIN');

        $result = @pg_query($conn, "CREATE TABLE {$safeSchema}.{$safeTable} (id serial4 NOT NULL PRIMARY KEY)");
        if ($result === false) {
            @pg_query($conn, 'ROLLBACK');
            error_log('[csv_import] CREATE TABLE failed: ' . pg_last_error($conn));
            csv_fail('Cannot create table. Check server error log.');
        }

        foreach ($columnDefinitions as $columnDefinition) {
            $safeColumn = pg_escape_identifier($conn, $columnDefinition['name']);
            $result = @pg_query(
                $conn,
                "ALTER TABLE {$safeSchema}.{$safeTable} ADD COLUMN {$safeColumn} {$columnDefinition['type']}"
            );
            if ($result === false) {
                error_log('[csv_import] ADD COLUMN failed: ' . pg_last_error($conn));
                @pg_query($conn, 'ROLLBACK');
                csv_fail('Cannot add column "' . $columnDefinition['name'] . '". Check server error log.');
            }
        }

        @pg_query($conn, 'COMMIT');

        $typeMap = [
            'varchar(255)' => 'text',
            'text'         => 'text',
            'int4'         => 'number',
            'int8'         => 'number',
            'boolean'      => 'boolean',
            'date'         => 'date',
            'timestamp'    => 'timestamp',
            'timestamptz'  => 'timestamp',
        ];

        if ($displayName === '') {
            $displayName = ucwords(str_replace('_', ' ', $tableName));
        }

        $schemaColumns = [
            'id' => [
                'display_name' => 'ID',
                'type'         => 'number',
                'not_null'     => true,
                'show_in_grid' => false,
                'show_in_edit' => false,
                'readonly'     => true,
            ],
        ];
        foreach ($columnDefinitions as $columnDefinition) {
            $schemaColumns[$columnDefinition['name']] = [
                'display_name' => ucwords(str_replace('_', ' ', $columnDefinition['name'])),
                'type'         => $typeMap[$columnDefinition['type']] ?? 'text',
                'not_null'     => false,
                'show_in_grid' => true,
                'show_in_edit' => true,
                'readonly'     => false,
            ];
        }

        require_once __DIR__ . '/../../includes/config_store.php';
        $schemaData = config_get('schema') ?? [];
        if (!isset($schemaData['tables'])) {
            $schemaData['tables'] = [];
        }
        $schemaData['tables'][$tableName] = [
            'display_name' => $displayName,
            'schema'       => $schemaName,
            'columns'      => $schemaColumns,
            'foreign_keys' => [],
            'subtables'    => [],
            'hidden'       => false,
            'icon'         => '',
        ];

        $csvUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $csvResult = config_save('schema', $schemaData, null, $csvUserId);
        if ($csvResult['status'] !== 'ok') {
            csv_fail('Table created in DB but failed to save schema config. Run Sync Columns manually.');
        }

        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        error_log('[csv_import] create table failed: ' . $exception->getMessage());
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_schemas') {
    try {
        $conn = db_connect();
        $result  = pg_query(
            $conn,
            "SELECT schema_name FROM information_schema.schemata
              WHERE schema_name NOT LIKE 'pg_%'
                AND schema_name <> 'information_schema'
              ORDER BY schema_name"
        );
        if ($result === false) {
            csv_fail('Failed to query schemas.');
        }
        $schemas = [];
        while ($row = pg_fetch_row($result)) {
            $schemas[] = $row[0];
        }
        echo json_encode(['status' => 'success', 'schemas' => $schemas]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_import_config') {
    echo json_encode([
        'status'            => 'success',
        'max_upload_mb'     => (int) floor(CSV_MAX_BYTES / 1048576),
        'max_execution_sec' => (int) ini_get('max_execution_time'),
        'memory_limit'      => ini_get('memory_limit'),
        'batch_size'        => CSV_BATCH_SIZE,
    ]);
    throw ResponseException::sent();
}

csv_fail('Unknown action.', 404);
