<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_api_errors.php';

os_api_bootstrap(['connect' => false, 'role' => 'admin']);

const CSV_MAX_BYTES   = 524288000;
const CSV_BATCH_SIZE  = 1000;
const CSV_PREVIEW_ROWS = 5;

final class CsvReader
{
    public static function read(string $path, string $delimiter = ',', string $encoding = 'UTF-8'): \Generator
    {
        $fileHandle = fopen($path, 'r');
        if ($fileHandle === false) {
            throw new \RuntimeException('Cannot open CSV file for reading.');
        }
        try {
            $headers = fgetcsv($fileHandle, 0, $delimiter, '"', '\\');
            if ($headers === false || $headers === null) {
                return;
            }
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $headers    = array_map('trim', $headers);
            if ($encoding !== 'UTF-8') {
                $headers = array_map(fn($header) => mb_convert_encoding($header, 'UTF-8', $encoding), $headers);
            }
            yield 0 => $headers;

            $rowNumber = 1;
            while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }
                $count = count($headers);
                $row   = array_pad(array_slice($row, 0, $count), $count, null);
                if ($encoding !== 'UTF-8') {
                    $row = array_map(
                        fn($value) => $value !== null
                            ? mb_convert_encoding($value, 'UTF-8', $encoding)
                            : null,
                        $row
                    );
                }
                yield $rowNumber++ => array_combine($headers, $row);
            }
        } finally {
            fclose($fileHandle);
        }
    }
}

final class CsvFileValidator
{
    public static function validate(array $file): void
    {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini (currently '
                    . ini_get('upload_max_filesize') . '). Restart the PHP server after editing php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the MAX_FILE_SIZE limit specified in the form.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];
            throw new \InvalidArgumentException($uploadMessages[$uploadError] ?? 'Upload error code: ' . $uploadError);
        }
        if ((int) ($file['size'] ?? 0) > CSV_MAX_BYTES) {
            throw new \InvalidArgumentException('File exceeds ' . (CSV_MAX_BYTES / 1048576) . ' MB limit.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new \InvalidArgumentException('Only .csv files are accepted.');
        }
        $finfo  = new \finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->file((string) ($file['tmp_name'] ?? ''));
        $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if (!in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid MIME type: {$mime}. Expected a CSV/text file.");
        }
    }
}

final class RowCaster
{
    public static function cast(?string $value, string $colType): mixed
    {
        $trimmed = ($value === null) ? null : trim($value);
        if ($trimmed === '' || $trimmed === null) {
            return null;
        }
        $normalizedType = strtolower($colType);

        if (str_contains($normalizedType, 'bool')) {
            return in_array(strtolower($trimmed), ['1', 'true', 't', 'yes', 'y'], true) ? 'true' : 'false';
        }
        if (str_contains($normalizedType, 'int') || str_contains($normalizedType, 'serial')) {
            return is_numeric($trimmed) ? (string)(int) $trimmed : null;
        }
        if (
            str_contains($normalizedType, 'numeric') || str_contains($normalizedType, 'decimal') ||
            str_contains($normalizedType, 'float')   || str_contains($normalizedType, 'real')    ||
            str_contains($normalizedType, 'double')
        ) {
            $normalizedNumber = str_replace(',', '.', $trimmed);
            return is_numeric($normalizedNumber) ? (string)(float) $normalizedNumber : null;
        }
        if ($normalizedType === 'date') {
            return self::toDate($trimmed);
        }
        if (str_contains($normalizedType, 'timestamp') || str_contains($normalizedType, 'datetime')) {
            return self::toTimestamp($trimmed);
        }
        if (str_contains($normalizedType, 'time')) {
            return self::toTime($trimmed);
        }
        return $trimmed;
    }

    private static function toDate(string $value): ?string
    {
        if (preg_match('/^(\d{2})[.\\/](\d{2})[.\\/](\d{4})$/', $value, $matches)) {
            $value = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private static function toTimestamp(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function toTime(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('H:i:s', $timestamp) : null;
    }
}

final class ImportRepository
{
    public function __construct(private readonly \PgSql\Connection $conn)
    {
    }

    public function createRecord(
        int $userId,
        string $filename,
        string $tableName,
        array $mapping,
        ?string $conflictColumn
    ): int {
        $sql = 'INSERT INTO ' . sys_table('imports')
            . ' (user_id, filename, target_table, column_mapping, conflict_column, status)'
            . ' VALUES ($1,$2,$3,$4,$5,$6) RETURNING id';
        $result = @pg_query_params($this->conn, $sql, [
            $userId, $filename, $tableName,
            json_encode($mapping), $conflictColumn, 'running',
        ]);
        if ($result === false) {
            throw new \RuntimeException(
                'Failed to create import record. Check that spw_imports table exists (run Initialize System Tables).'
            );
        }
        return (int) pg_fetch_row($result)[0];
    }

    public function finalize(
        int $importId,
        string $status,
        int $total,
        int $imported,
        int $skipped,
        ?string $errorMessage = null
    ): void {
        $sql = 'UPDATE ' . sys_table('imports')
            . ' SET status=$1,total_rows=$2,imported_rows=$3,skipped_rows=$4,error_message=$5,finished_at=now()'
            . ' WHERE id=$6';
        @pg_query_params($this->conn, $sql, [$status, $total, $imported, $skipped, $errorMessage, $importId]);
    }

    public function logRows(int $importId, array $rowErrors): void
    {
        if (empty($rowErrors)) {
            return;
        }
        $logTable    = sys_table('import_rows_log');
        $placeholders   = [];
        $args = [];
        $placeholderIndex    = 1;
        foreach ($rowErrors as $entry) {
            $placeholders[]   = "(\${$placeholderIndex},\$" . ($placeholderIndex + 1)
                . ",\$" . ($placeholderIndex + 2)
                . ",\$" . ($placeholderIndex + 3) . ')';
            $args[] = $importId;
            $args[] = $entry['row_number'];
            $args[] = json_encode($entry['raw_data']);
            $args[] = $entry['error'];
            $placeholderIndex += 4;
        }
        $sql = "INSERT INTO {$logTable} (import_id,row_number,raw_data,error_message) VALUES "
            . implode(',', $placeholders);
        @pg_query_params($this->conn, $sql, $args);
    }

    public function getHistory(): array
    {
        $importsTable = sys_table('imports');
        $usersTable = sys_table('users');
        $sql = "SELECT i.id,i.filename,i.target_table,i.status,i.total_rows,i.imported_rows,
                       i.skipped_rows,i.started_at,i.finished_at,u.username
                FROM {$importsTable} i
                LEFT JOIN {$usersTable} u ON u.id=i.user_id
                ORDER BY i.started_at DESC LIMIT 100";
        $result = @pg_query($this->conn, $sql);
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getRowLog(int $importId): array
    {
        $logTable   = sys_table('import_rows_log');
        $result = @pg_query_params(
            $this->conn,
            "SELECT row_number,raw_data,error_message,logged_at FROM {$logTable}"
            . " WHERE import_id=\$1 ORDER BY row_number ASC",
            [$importId]
        );
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
}

final class CsvImportService
{
    public function __construct(
        private readonly \PgSql\Connection $conn,
        private readonly ImportRepository $repo,
    ) {
    }

    public function execute(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        array $colTypes,
        ?string $conflictColumn,
        int $importId,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdentifier = pg_ident($tableSchema) . '.' . pg_ident($tableName);
        $dbColumns     = array_values(array_unique(array_filter($mapping)));

        $batchSize = max(1, min(CSV_BATCH_SIZE, (int) floor(65000 / max(1, count($dbColumns)))));

        $total     = 0;
        $imported  = 0;
        $skipped   = 0;
        $rowErrors = [];
        $batch     = [];

        foreach (CsvReader::read($csvPath, $delimiter, $encoding) as $rowNumber => $rowData) {
            if ($rowNumber === 0) {
                continue;
            }
            $total++;

            $castRow   = [];
            $castError = null;

            foreach ($mapping as $csvHeader => $dbColumn) {
                if ($dbColumn === null || $dbColumn === '') {
                    continue;
                }
                $rawValue  = isset($rowData[$csvHeader]) ? (string) $rowData[$csvHeader] : null;
                $colType = $colTypes[$dbColumn] ?? 'text';
                $casted  = RowCaster::cast($rawValue, $colType);
                $castRow[$dbColumn] = $casted;
            }

            if (empty($castRow)) {
                $skipped++;
                $rowErrors[] = [
                    'row_number' => $rowNumber,
                    'raw_data'   => $rowData,
                    'error'      => 'All mapped columns empty after cast.',
                ];
                continue;
            }

            $batch[] = ['rowNum' => $rowNumber, 'data' => $castRow, 'raw' => $rowData];

            if (count($batch) >= $batchSize) {
                [$importedCount, $skip, $errs] = $this->flushBatch(
                    $batch,
                    $tableIdentifier,
                    $dbColumns,
                    $conflictColumn
                );
                $imported += $importedCount;
                $skipped  += $skip;
                array_push($rowErrors, ...$errs);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            [$importedCount, $skip, $errs] = $this->flushBatch($batch, $tableIdentifier, $dbColumns, $conflictColumn);
            $imported += $importedCount;
            $skipped  += $skip;
            array_push($rowErrors, ...$errs);
        }

        $this->repo->logRows($importId, $rowErrors);

        return [$total, $imported, $skipped];
    }

    private function flushBatch(
        array $batch,
        string $tableIdentifier,
        array $dbColumns,
        ?string $conflictColumn
    ): array {
        @pg_query($this->conn, 'BEGIN');

        $sql    = $this->buildInsertSql($batch, $tableIdentifier, $dbColumns, $conflictColumn);
        $params = $this->buildParams($batch, $dbColumns);
        $result    = @pg_query_params($this->conn, $sql, $params);

        if ($result === false) {
            @pg_query($this->conn, 'ROLLBACK');
            $error    = substr(pg_last_error($this->conn), 0, 300);
            $errors = array_map(
                fn($batchEntry) => [
                    'row_number' => $batchEntry['rowNum'],
                    'raw_data'   => $batchEntry['raw'],
                    'error'      => "Batch DB error: {$error}",
                ],
                $batch
            );
            return [0, count($batch), $errors];
        }

        @pg_query($this->conn, 'COMMIT');
        return [count($batch), 0, []];
    }

    private function buildInsertSql(
        array $batch,
        string $tableIdentifier,
        array $dbColumns,
        ?string $conflictColumn
    ): string {
        $colList = implode(',', array_map('pg_ident', $dbColumns));
        $columnCount = count($dbColumns);
        $rows    = [];
        $index     = 1;
        foreach ($batch as $_) {
            $placeholders = [];
            for ($column = 0; $column < $columnCount; $column++) {
                $placeholders[] = '$' . $index++;
            }
            $rows[] = '(' . implode(',', $placeholders) . ')';
        }
        $sql = "INSERT INTO {$tableIdentifier} ({$colList}) VALUES " . implode(',', $rows);

        if ($conflictColumn !== null && $conflictColumn !== '') {
            $conflictIdentifier         = pg_ident($conflictColumn);
            $updateColumns = array_filter($dbColumns, fn($column) => $column !== $conflictColumn);
            if (!empty($updateColumns)) {
                $sets = array_map(fn($column) => pg_ident($column) . '=EXCLUDED.' . pg_ident($column), $updateColumns);
                $sql .= " ON CONFLICT ({$conflictIdentifier}) DO UPDATE SET " . implode(',', $sets);
            } else {
                $sql .= " ON CONFLICT ({$conflictIdentifier}) DO NOTHING";
            }
        }

        return $sql;
    }

    private function buildParams(array $batch, array $dbColumns): array
    {
        $params = [];
        foreach ($batch as $entry) {
            foreach ($dbColumns as $columnName) {
                $params[] = $entry['data'][$columnName] ?? null;
            }
        }
        return $params;
    }

    public function executeCopy(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        int $importId,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdentifier = pg_ident($tableSchema) . '.' . pg_ident($tableName);

        $colMap = [];
        foreach ($mapping as $csvHeaderName => $dbColumn) {
            if ($dbColumn !== null && $dbColumn !== '' && !isset($colMap[$dbColumn])) {
                $colMap[$dbColumn] = $csvHeaderName;
            }
        }
        if (empty($colMap)) {
            throw new \RuntimeException('No columns mapped.');
        }

        $fileHandle = fopen($csvPath, 'r');
        if ($fileHandle === false) {
            throw new \RuntimeException('Cannot open CSV file.');
        }

        try {
            $csvHeaders = fgetcsv($fileHandle, 0, $delimiter, '"', '\\');
            if ($csvHeaders === false || $csvHeaders === null) {
                throw new \RuntimeException('Empty CSV file.');
            }
            $csvHeaders[0] = ltrim((string) $csvHeaders[0], "\xEF\xBB\xBF");
            $csvHeaders    = array_map('trim', $csvHeaders);

            $mappedCount    = count(array_filter(
                $csvHeaders,
                fn($header) => isset($mapping[$header]) && $mapping[$header] !== null && $mapping[$header] !== ''
            ));
            $isDirectStream = $mappedCount === count($csvHeaders);

            $headerIndexes  = array_flip($csvHeaders);
            $colIndices = $isDirectStream ? null : array_map(
                fn($csvHeaderName) => $headerIndexes[$csvHeaderName] ?? null,
                array_values($colMap)
            );

            $colList = implode(',', array_map(pg_ident(...), array_keys($colMap)));
            $sql     = "COPY {$tableIdentifier} ({$colList}) FROM STDIN WITH (FORMAT CSV, NULL '')";

            if (@pg_query($this->conn, $sql) === false) {
                throw new \RuntimeException('COPY init failed: ' . substr(pg_last_error($this->conn), 0, 300));
            }

            $total  = 0;
            $buffer = '';

            while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }
                $total++;
                $headerCount = count($csvHeaders);

                $row = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                if ($isDirectStream) {
                    $fields = array_map(function ($value) use ($encoding) {
                        $text = (string) $value;
                        if ($encoding !== 'UTF-8') {
                            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
                        }
                        return self::quoteForCopy($text);
                    }, $row);
                } else {
                    $fields = [];
                    foreach ($colIndices as $index) {
                        $cellValue = ($index !== null && isset($row[$index])) ? (string) $row[$index] : '';
                        if ($encoding !== 'UTF-8') {
                            $cellValue = mb_convert_encoding($cellValue, 'UTF-8', $encoding);
                        }
                        $fields[] = self::quoteForCopy($cellValue);
                    }
                }
                $buffer .= implode(',', $fields) . "\n";
                if (strlen($buffer) >= 524288) {
                    @pg_put_line($this->conn, $buffer);
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                @pg_put_line($this->conn, $buffer);
            }
            @pg_put_line($this->conn, "\\.\n");

            if (@pg_end_copy($this->conn) === false) {
                $pgError = pg_last_error($this->conn);
                $hint  = '';
                if (
                    preg_match('/invalid input syntax for type (\w+).*column (\w+)/i', $pgError, $matches)
                    || preg_match(
                        '/niepra.*?dla typu (\w+).*kolumn[ay] (\w+)/iu',
                        $pgError,
                        $matches
                    )
                ) {
                    $hint = " Column \"{$matches[2]}\" is typed {$matches[1]} but received a non-{$matches[1]} value."
                        . ' Cause: an earlier field in that row has an unquoted delimiter, '
                        . 'shifting all subsequent columns.'
                        . ' Fix: use Normal mode (per-row error reporting) or correct the source CSV quoting.';
                } elseif (str_contains($pgError, 'unexpected data') || str_contains($pgError, 'nieoczekiwane dane')) {
                    $hint = ' A row has more fields than the header.'
                        . ' Check the Delimiter setting or fix quoting in the source CSV.';
                }
                throw new \RuntimeException('COPY failed: ' . substr($pgError, 0, 400) . $hint);
            }

            return [$total, $total, 0];
        } finally {
            fclose($fileHandle);
        }
    }

    private static function quoteForCopy(string $cellValue): string
    {
        if (
            str_contains($cellValue, ',') || str_contains($cellValue, '"')
            || str_contains($cellValue, "\n") || str_contains($cellValue, "\r")
        ) {
            return '"' . str_replace('"', '""', $cellValue) . '"';
        }
        return $cellValue;
    }
}

$action = $_GET['action'] ?? '';

function csv_fail(string $message, int $code = 400): never
{
    http_response_code($code);
    throw ResponseException::encoded(['status' => 'error', 'error' => $message]);
}

if ($action === 'csv_import_history') {
    try {
        $conn = db_connect();
        $repo = new ImportRepository($conn);
        echo json_encode(['status' => 'success', 'imports' => $repo->getHistory()]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (\Exception $exception) {
        csv_fail(admin_error_message($exception));
    }
    throw ResponseException::sent();
}

if ($action === 'csv_import_log') {
    $importId = (int) ($_GET['id'] ?? 0);
    if ($importId <= 0) {
        csv_fail('Missing or invalid import id.');
    }
    try {
        $conn = db_connect();
        $repo = new ImportRepository($conn);
        $rows = $repo->getRowLog($importId);
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
    } catch (\InvalidArgumentException $exception) {
        csv_fail($exception->getMessage());
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

    $importDir = realpath(__DIR__ . '/../../storage/files') . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR;
    if (!is_dir($importDir)) {
        mkdir($importDir, 0750, true);

        file_put_contents($importDir . '.htaccess', "Require all denied\nOptions -Indexes\n");
    }

    $tmpName  = bin2hex(random_bytes(16)) . '.csv';
    $destPath = $importDir . $tmpName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        csv_fail('Failed to store the uploaded file on the server.');
    }

    echo json_encode([
        'status'        => 'success',
        'headers'       => $headers,
        'preview'       => $preview,
        'row_count'     => $rowCount,
        'original_name' => basename((string) $file['name']),
        'tmp_name'      => $tmpName,
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

    $tmpName      = (string) ($body['tmp_name']        ?? '');
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

    if (!preg_match('/^[a-f0-9]{32}\.csv$/', $tmpName)) {
        csv_fail('Invalid tmp_name token.');
    }
    if ($tableName === '') {
        csv_fail('Target table not specified.');
    }
    if (!is_array($mapping) || empty($mapping)) {
        csv_fail('No column mapping provided.');
    }

    $csvPath = realpath(__DIR__ . '/../../storage/files')
        . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . $tmpName;

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

    $colTypes = array_map(fn($column) => (string) ($column['type'] ?? 'text'), $schemaColumns);
    $userId   = (int) ($_SESSION['user_id'] ?? 0);

    $importId = 0;
    try {
        $conn    = db_connect();
        $repo    = new ImportRepository($conn);
        $service = new CsvImportService($conn, $repo);

        $importId  = $repo->createRecord(
            $userId,
            $originalName,
            $tableName,
            $mapping,
            $copyMode ? null : $conflictColumn
        );
        $startTime = microtime(true);

        if ($copyMode) {
            [$total, $imported, $skipped] = $service->executeCopy(
                $csvPath,
                $tableName,
                $tableSchema,
                $mapping,
                $importId,
                $delimiter,
                $encoding
            );
        } else {
            [$total, $imported, $skipped] = $service->execute(
                $csvPath,
                $tableName,
                $tableSchema,
                $mapping,
                $colTypes,
                $conflictColumn,
                $importId,
                $delimiter,
                $encoding
            );
        }

        $status = ($total > 0 && $skipped === $total) ? 'failed' : 'done';
        $repo->finalize($importId, $status, $total, $imported, $skipped);

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
        if ($importId > 0 && isset($repo)) {
            $repo->finalize($importId, 'failed', 0, 0, 0, $exception->getMessage());
        }
        @unlink($csvPath);
        csv_fail($exception->getMessage());
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
            csv_fail('Cannot create table: ' . substr(pg_last_error($conn), 0, 300));
        }

        foreach ($columnDefinitions as $columnDefinition) {
            $safeColumn = pg_escape_identifier($conn, $columnDefinition['name']);
            $result = @pg_query(
                $conn,
                "ALTER TABLE {$safeSchema}.{$safeTable} ADD COLUMN {$safeColumn} {$columnDefinition['type']}"
            );
            if ($result === false) {
                $error = substr(pg_last_error($conn), 0, 300);
                @pg_query($conn, 'ROLLBACK');
                csv_fail('Cannot add column "' . $columnDefinition['name'] . '": ' . $error);
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
        csv_fail($exception->getMessage());
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
