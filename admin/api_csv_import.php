<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

start_session();

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

const CSV_MAX_BYTES   = 524288000; // 500 MB
const CSV_BATCH_SIZE  = 1000;
const CSV_PREVIEW_ROWS = 5;

// ── Domain ────────────────────────────────────────────────────────────────────

/**
 * Reads a CSV file row-by-row using a generator to keep memory usage flat.
 * Yields key 0 => raw headers array, then key N => [header => value] assoc arrays.
 */
final class CsvReader
{
    public static function read(string $path, string $delimiter = ',', string $encoding = 'UTF-8'): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open CSV file for reading.');
        }
        try {
            $headers = fgetcsv($fh, 0, $delimiter);
            if ($headers === false || $headers === null) {
                return;
            }
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF"); // strip UTF-8 BOM
            $headers    = array_map('trim', $headers);
            if ($encoding !== 'UTF-8') {
                $headers = array_map(fn($h) => mb_convert_encoding($h, 'UTF-8', $encoding), $headers);
            }
            yield 0 => $headers;

            $rowNum = 1;
            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue; // skip blank lines
                }
                $count = count($headers);
                $row   = array_pad(array_slice($row, 0, $count), $count, null);
                if ($encoding !== 'UTF-8') {
                    $row = array_map(fn($v) => $v !== null ? mb_convert_encoding($v, 'UTF-8', $encoding) : null, $row);
                }
                yield $rowNum++ => array_combine($headers, $row);
            }
        } finally {
            fclose($fh);
        }
    }
}

/**
 * Validates a CSV file upload: size, extension, and real MIME type via finfo.
 */
final class CsvFileValidator
{
    public static function validate(array $file): void
    {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini (currently ' . ini_get('upload_max_filesize') . '). Restart the PHP server after editing php.ini.',
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

/**
 * Casts raw CSV string values to PostgreSQL-compatible types based on column schema.
 */
final class RowCaster
{
    public static function cast(?string $value, string $colType): mixed
    {
        $v = ($value === null) ? null : trim($value);
        if ($v === '' || $v === null) {
            return null;
        }
        $t = strtolower($colType);

        if (str_contains($t, 'bool')) {
            return in_array(strtolower($v), ['1', 'true', 't', 'yes', 'y'], true) ? 'true' : 'false';
        }
        if (str_contains($t, 'int') || str_contains($t, 'serial')) {
            return is_numeric($v) ? (string)(int) $v : null;
        }
        if (
            str_contains($t, 'numeric') || str_contains($t, 'decimal') ||
            str_contains($t, 'float')   || str_contains($t, 'real')    ||
            str_contains($t, 'double')
        ) {
            $n = str_replace(',', '.', $v);
            return is_numeric($n) ? (string)(float) $n : null;
        }
        if ($t === 'date') {
            return self::toDate($v);
        }
        if (str_contains($t, 'timestamp') || str_contains($t, 'datetime')) {
            return self::toTimestamp($v);
        }
        if (str_contains($t, 'time')) {
            return self::toTime($v);
        }
        return $v; // text, varchar, uuid, etc.
    }

    private static function toDate(string $v): ?string
    {
        // Accept dd.mm.yyyy, dd/mm/yyyy, yyyy-mm-dd, or any strtotime-parseable value
        if (preg_match('/^(\d{2})[.\\/](\d{2})[.\\/](\d{4})$/', $v, $m)) {
            $v = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private static function toTimestamp(string $v): ?string
    {
        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function toTime(string $v): ?string
    {
        $ts = strtotime($v);
        return $ts !== false ? date('H:i:s', $ts) : null;
    }
}

// ── Infrastructure ────────────────────────────────────────────────────────────

/**
 * Persists and queries import records and per-row error logs.
 */
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
        ?string $conflictCol
    ): int {
        $sql = 'INSERT INTO ' . sys_table('imports')
            . ' (user_id, filename, target_table, column_mapping, conflict_column, status)'
            . ' VALUES ($1,$2,$3,$4,$5,$6) RETURNING id';
        $res = @pg_query_params($this->conn, $sql, [
            $userId, $filename, $tableName,
            json_encode($mapping), $conflictCol, 'running',
        ]);
        if ($res === false) {
            throw new \RuntimeException('Failed to create import record. Check that spw_imports table exists (run Initialize System Tables).');
        }
        return (int) pg_fetch_row($res)[0];
    }

    public function finalize(
        int $importId,
        string $status,
        int $total,
        int $imported,
        int $skipped,
        ?string $errorMsg = null
    ): void {
        $sql = 'UPDATE ' . sys_table('imports')
            . ' SET status=$1,total_rows=$2,imported_rows=$3,skipped_rows=$4,error_message=$5,finished_at=now()'
            . ' WHERE id=$6';
        @pg_query_params($this->conn, $sql, [$status, $total, $imported, $skipped, $errorMsg, $importId]);
    }

    /** Batch-insert per-row error entries. */
    public function logRows(int $importId, array $rowErrors): void
    {
        if (empty($rowErrors)) {
            return;
        }
        $t    = sys_table('import_rows_log');
        $ph   = [];
        $args = [];
        $i    = 1;
        foreach ($rowErrors as $entry) {
            $ph[]   = "(\${$i},\$" . ($i + 1) . ",\$" . ($i + 2) . ",\$" . ($i + 3) . ')';
            $args[] = $importId;
            $args[] = $entry['row_number'];
            $args[] = json_encode($entry['raw_data']);
            $args[] = $entry['error'];
            $i += 4;
        }
        $sql = "INSERT INTO {$t} (import_id,row_number,raw_data,error_message) VALUES " . implode(',', $ph);
        @pg_query_params($this->conn, $sql, $args);
    }

    /** @return list<array<string,mixed>> */
    public function getHistory(): array
    {
        $ti = sys_table('imports');
        $tu = sys_table('users');
        $sql = "SELECT i.id,i.filename,i.target_table,i.status,i.total_rows,i.imported_rows,
                       i.skipped_rows,i.started_at,i.finished_at,u.username
                FROM {$ti} i
                LEFT JOIN {$tu} u ON u.id=i.user_id
                ORDER BY i.started_at DESC LIMIT 100";
        $res = @pg_query($this->conn, $sql);
        if ($res === false) {
            return [];
        }
        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function getRowLog(int $importId): array
    {
        $t   = sys_table('import_rows_log');
        $res = @pg_query_params(
            $this->conn,
            "SELECT row_number,raw_data,error_message,logged_at FROM {$t} WHERE import_id=\$1 ORDER BY row_number ASC",
            [$importId]
        );
        if ($res === false) {
            return [];
        }
        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        return $rows;
    }
}

// ── Application ───────────────────────────────────────────────────────────────

/**
 * Orchestrates batch import: reads CSV with a generator, casts values, bulk-inserts
 * in transactions of up to CSV_BATCH_SIZE rows. Row-level cast failures are logged
 * and skipped without aborting the import. A DB-level batch failure rolls back only
 * that batch and records all its rows as failed.
 */
final class CsvImportService
{
    public function __construct(
        private readonly \PgSql\Connection $conn,
        private readonly ImportRepository $repo,
    ) {
    }

    /**
     * @param  array<string,string|null> $mapping      csvHeader => dbColumn (null = skip)
     * @param  array<string,string>      $colTypes     dbColumn => schemaType
     * @return array{0:int,1:int,2:int}  [total, imported, skipped]
     */
    public function execute(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        array $colTypes,
        ?string $conflictCol,
        int $importId,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdent = pg_ident($tableSchema) . '.' . pg_ident($tableName);
        $dbCols     = array_values(array_unique(array_filter($mapping)));

        // Dynamic batch size guard: PostgreSQL allows up to 65 535 bind parameters.
        $batchSize = max(1, min(CSV_BATCH_SIZE, (int) floor(65000 / max(1, count($dbCols)))));

        $total     = 0;
        $imported  = 0;
        $skipped   = 0;
        $rowErrors = [];
        $batch     = [];

        foreach (CsvReader::read($csvPath, $delimiter, $encoding) as $rowNum => $rowData) {
            if ($rowNum === 0) {
                continue; // key 0 is the raw headers array, not a data row
            }
            $total++;

            $castRow   = [];
            $castError = null;

            foreach ($mapping as $csvHeader => $dbCol) {
                if ($dbCol === null || $dbCol === '') {
                    continue;
                }
                $rawVal  = isset($rowData[$csvHeader]) ? (string) $rowData[$csvHeader] : null;
                $colType = $colTypes[$dbCol] ?? 'text';
                $casted  = RowCaster::cast($rawVal, $colType);
                $castRow[$dbCol] = $casted;
            }

            if (empty($castRow)) {
                $skipped++;
                $rowErrors[] = [
                    'row_number' => $rowNum,
                    'raw_data'   => $rowData,
                    'error'      => 'All mapped columns empty after cast.',
                ];
                continue;
            }

            $batch[] = ['rowNum' => $rowNum, 'data' => $castRow, 'raw' => $rowData];

            if (count($batch) >= $batchSize) {
                [$imp, $skip, $errs] = $this->flushBatch($batch, $tableIdent, $dbCols, $conflictCol);
                $imported += $imp;
                $skipped  += $skip;
                array_push($rowErrors, ...$errs);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            [$imp, $skip, $errs] = $this->flushBatch($batch, $tableIdent, $dbCols, $conflictCol);
            $imported += $imp;
            $skipped  += $skip;
            array_push($rowErrors, ...$errs);
        }

        $this->repo->logRows($importId, $rowErrors);

        return [$total, $imported, $skipped];
    }

    /**
     * Wraps one batch in a transaction. On DB error, rolls back and marks all rows failed.
     *
     * @return array{0:int,1:int,2:list<array>} [imported, skipped, errors]
     */
    private function flushBatch(
        array $batch,
        string $tableIdent,
        array $dbCols,
        ?string $conflictCol
    ): array {
        @pg_query($this->conn, 'BEGIN');

        $sql    = $this->buildInsertSql($batch, $tableIdent, $dbCols, $conflictCol);
        $params = $this->buildParams($batch, $dbCols);
        $res    = @pg_query_params($this->conn, $sql, $params);

        if ($res === false) {
            @pg_query($this->conn, 'ROLLBACK');
            $err    = substr(pg_last_error($this->conn), 0, 300);
            $errors = array_map(
                fn($e) => ['row_number' => $e['rowNum'], 'raw_data' => $e['raw'], 'error' => "Batch DB error: {$err}"],
                $batch
            );
            return [0, count($batch), $errors];
        }

        @pg_query($this->conn, 'COMMIT');
        return [count($batch), 0, []];
    }

    private function buildInsertSql(
        array $batch,
        string $tableIdent,
        array $dbCols,
        ?string $conflictCol
    ): string {
        $colList = implode(',', array_map('pg_ident', $dbCols));
        $numCols = count($dbCols);
        $rows    = [];
        $idx     = 1;
        foreach ($batch as $_) {
            $ph = [];
            for ($c = 0; $c < $numCols; $c++) {
                $ph[] = '$' . $idx++;
            }
            $rows[] = '(' . implode(',', $ph) . ')';
        }
        $sql = "INSERT INTO {$tableIdent} ({$colList}) VALUES " . implode(',', $rows);

        if ($conflictCol !== null && $conflictCol !== '') {
            $ci         = pg_ident($conflictCol);
            $updateCols = array_filter($dbCols, fn($c) => $c !== $conflictCol);
            if (!empty($updateCols)) {
                $sets = array_map(fn($c) => pg_ident($c) . '=EXCLUDED.' . pg_ident($c), $updateCols);
                $sql .= " ON CONFLICT ({$ci}) DO UPDATE SET " . implode(',', $sets);
            } else {
                $sql .= " ON CONFLICT ({$ci}) DO NOTHING";
            }
        }

        return $sql;
    }

    private function buildParams(array $batch, array $dbCols): array
    {
        $params = [];
        foreach ($batch as $entry) {
            foreach ($dbCols as $col) {
                $params[] = $entry['data'][$col] ?? null;
            }
        }
        return $params;
    }

    /**
     * Streams all rows via PostgreSQL COPY FROM STDIN (CSV format).
     *
     * Fast path (all CSV columns mapped, no skipping): raw fgets() line streaming
     * into 512 KB pg_put_line() batches — avoids CSV parsing entirely (~60x faster
     * than fgetcsv on large-row files).
     *
     * Slow path (some columns skipped / reordered): fgets() + str_getcsv() per row,
     * still ~40% faster than fgetcsv.
     *
     * No per-row error tracking — a type mismatch rolls back the entire COPY.
     *
     * @param  array<string,string|null> $mapping  csvHeader => dbColumn (null = skip)
     * @return array{0:int,1:int,2:int}  [total, imported, skipped=0]
     */
    public function executeCopy(
        string $csvPath,
        string $tableName,
        string $tableSchema,
        array $mapping,
        int $importId,
        string $delimiter = ',',
        string $encoding = 'UTF-8'
    ): array {
        $tableIdent = pg_ident($tableSchema) . '.' . pg_ident($tableName);

        $colMap = [];
        foreach ($mapping as $csvHdr => $dbCol) {
            if ($dbCol !== null && $dbCol !== '' && !isset($colMap[$dbCol])) {
                $colMap[$dbCol] = $csvHdr;
            }
        }
        if (empty($colMap)) {
            throw new \RuntimeException('No columns mapped.');
        }

        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open CSV file.');
        }

        try {
            // fgetcsv handles quoted fields with embedded newlines — fgets() does not
            $csvHeaders = fgetcsv($fh, 0, $delimiter);
            if ($csvHeaders === false || $csvHeaders === null) {
                throw new \RuntimeException('Empty CSV file.');
            }
            $csvHeaders[0] = ltrim((string) $csvHeaders[0], "\xEF\xBB\xBF");
            $csvHeaders    = array_map('trim', $csvHeaders);

            $mappedCount    = count(array_filter($csvHeaders, fn($h) => isset($mapping[$h]) && $mapping[$h] !== null && $mapping[$h] !== ''));
            $isDirectStream = $mappedCount === count($csvHeaders);

            $headerIdx  = array_flip($csvHeaders);
            $colIndices = $isDirectStream ? null : array_map(
                fn($csvHdr) => $headerIdx[$csvHdr] ?? null,
                array_values($colMap)
            );

            $colList = implode(',', array_map('pg_ident', array_keys($colMap)));
            $sql     = "COPY {$tableIdent} ({$colList}) FROM STDIN WITH (FORMAT CSV, NULL '')";

            if (@pg_query($this->conn, $sql) === false) {
                throw new \RuntimeException('COPY init failed: ' . substr(pg_last_error($this->conn), 0, 300));
            }

            $total  = 0;
            $buffer = '';

            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                if (count($row) === 1 && $row[0] === null) {
                    continue; // blank line
                }
                $total++;
                $headerCount = count($csvHeaders);
                // Normalise row length to match header count — prevents "unexpected data" errors
                // when a data row has extra commas (unquoted) or is shorter than the header.
                $row = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                if ($isDirectStream) {
                    $fields = array_map(function ($v) use ($encoding) {
                        $s = (string) $v;
                        if ($encoding !== 'UTF-8') {
                            $s = mb_convert_encoding($s, 'UTF-8', $encoding);
                        }
                        return self::quoteForCopy($s);
                    }, $row);
                } else {
                    $fields = [];
                    foreach ($colIndices as $idx) {
                        $val = ($idx !== null && isset($row[$idx])) ? (string) $row[$idx] : '';
                        if ($encoding !== 'UTF-8') {
                            $val = mb_convert_encoding($val, 'UTF-8', $encoding);
                        }
                        $fields[] = self::quoteForCopy($val);
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
                $pgErr = pg_last_error($this->conn);
                $hint  = '';
                if (
                    preg_match('/invalid input syntax for type (\w+).*column (\w+)/i', $pgErr, $m)
                    || preg_match('/niepra.*?dla typu (\w+).*kolumn[ay] (\w+)/iu', $pgErr, $m)
                ) {
                    $hint = " Column \"{$m[2]}\" is typed {$m[1]} but received a non-{$m[1]} value."
                        . ' Cause: an earlier field in that row has an unquoted delimiter, shifting all subsequent columns.'
                        . ' Fix: use Normal mode (per-row error reporting) or correct the source CSV quoting.';
                } elseif (str_contains($pgErr, 'unexpected data') || str_contains($pgErr, 'nieoczekiwane dane')) {
                    $hint = ' A row has more fields than the header.'
                        . ' Check the Delimiter setting or fix quoting in the source CSV.';
                }
                throw new \RuntimeException('COPY failed: ' . substr($pgErr, 0, 400) . $hint);
            }

            return [$total, $total, 0];
        } finally {
            fclose($fh);
        }
    }

    private static function quoteForCopy(string $val): string
    {
        if (
            str_contains($val, ',') || str_contains($val, '"')
            || str_contains($val, "\n") || str_contains($val, "\r")
        ) {
            return '"' . str_replace('"', '""', $val) . '"';
        }
        return $val;
    }
}

// ── HTTP routing ──────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

function csv_fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $msg]);
    exit;
}

// GET: import history
if ($action === 'csv_import_history') {
    try {
        $conn = db_connect();
        $repo = new ImportRepository($conn);
        echo json_encode(['status' => 'success', 'imports' => $repo->getHistory()]);
    } catch (\Exception $e) {
        csv_fail($e->getMessage());
    }
    exit;
}

// GET: per-row error log for one import
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
    } catch (\Exception $e) {
        csv_fail($e->getMessage());
    }
    exit;
}

// POST: upload CSV, validate, parse headers, return preview
if ($action === 'csv_import_upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }
    $file = $_FILES['csv_file'] ?? null;
    if (!$file) {
        csv_fail('No file uploaded. Use field name "csv_file".');
    }

    try {
        CsvFileValidator::validate($file);
    } catch (\InvalidArgumentException $e) {
        csv_fail($e->getMessage());
    }

    $allowed   = [',', ';', "\t", '|'];
    $delim     = $_POST['csv_delimiter'] ?? ',';
    $delimiter = in_array($delim, $allowed, true) ? $delim : ',';

    $allowedEnc = ['UTF-8', 'Windows-1250', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-2', 'Windows-1251'];
    $enc        = $_POST['csv_encoding'] ?? 'UTF-8';
    $encoding   = in_array($enc, $allowedEnc, true) ? $enc : 'UTF-8';

    $headers  = [];
    $preview  = [];
    $rowCount = 0;

    foreach (CsvReader::read($file['tmp_name'], $delimiter, $encoding) as $rowNum => $rowData) {
        if ($rowNum === 0) {
            $headers = $rowData;
            continue;
        }
        if ($rowCount < CSV_PREVIEW_ROWS) {
            $preview[] = $rowData;
        }
        $rowCount++;
    }

    // Move temp file to staging directory
    $importDir = realpath(__DIR__ . '/../storage/files') . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR;
    if (!is_dir($importDir)) {
        mkdir($importDir, 0750, true);
        // Deny direct web access to the staging directory
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
    exit;
}

// POST: execute import with mapping config
if ($action === 'csv_import_execute') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        csv_fail('Invalid JSON body.');
    }

    $tmpName      = (string) ($body['tmp_name']        ?? '');
    $tableName    = (string) ($body['table']           ?? '');
    $mapping      = $body['mapping']                   ?? [];
    $conflictCol  = ($body['conflict_column'] ?? '') ?: null;
    $copyMode     = !empty($body['copy_mode']);
    $originalName = (string) ($body['original_name']   ?? 'file.csv');
    $allowed      = [',', ';', "\t", '|'];
    $delim        = (string) ($body['delimiter']       ?? ',');
    $delimiter    = in_array($delim, $allowed, true) ? $delim : ',';
    $allowedEnc   = ['UTF-8', 'Windows-1250', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-2', 'Windows-1251'];
    $enc          = (string) ($body['encoding']        ?? 'UTF-8');
    $encoding     = in_array($enc, $allowedEnc, true) ? $enc : 'UTF-8';

    if (!preg_match('/^[a-f0-9]{32}\.csv$/', $tmpName)) {
        csv_fail('Invalid tmp_name token.');
    }
    if ($tableName === '') {
        csv_fail('Target table not specified.');
    }
    if (!is_array($mapping) || empty($mapping)) {
        csv_fail('No column mapping provided.');
    }

    $csvPath = realpath(__DIR__ . '/../storage/files') . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . $tmpName;

    if (!file_exists($csvPath)) {
        csv_fail('Uploaded file not found. Please re-upload the CSV.');
    }

    // Load and validate schema
    $schemaFile = __DIR__ . '/../config/schema.json';
    $schema     = json_decode((string) file_get_contents($schemaFile), true);
    if (!is_array($schema) || !isset($schema['tables'][$tableName])) {
        @unlink($csvPath);
        csv_fail("Table '{$tableName}' not found in schema configuration.");
    }

    $tableConfig = $schema['tables'][$tableName];
    $tableSchema = (string) ($tableConfig['schema'] ?? 'public');
    $schemaCols  = $tableConfig['columns'] ?? [];

    foreach ($mapping as $csvHeader => $dbCol) {
        if ($dbCol !== null && $dbCol !== '' && !isset($schemaCols[$dbCol])) {
            @unlink($csvPath);
            csv_fail("Column '{$dbCol}' does not exist in table '{$tableName}'.");
        }
    }

    $dbCols = array_values(array_unique(array_filter($mapping)));
    if ($conflictCol !== null && $conflictCol !== '' && !in_array($conflictCol, $dbCols, true)) {
        @unlink($csvPath);
        csv_fail("Conflict column '{$conflictCol}' must be included in the column mapping.");
    }

    $colTypes = array_map(fn($c) => (string) ($c['type'] ?? 'text'), $schemaCols);
    $userId   = (int) ($_SESSION['user_id'] ?? 0);

    $importId = 0;
    try {
        $conn    = db_connect();
        $repo    = new ImportRepository($conn);
        $service = new CsvImportService($conn, $repo);

        $importId  = $repo->createRecord($userId, $originalName, $tableName, $mapping, $copyMode ? null : $conflictCol);
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
                $conflictCol,
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
    } catch (\Exception $e) {
        if ($importId > 0 && isset($repo)) {
            $repo->finalize($importId, 'failed', 0, 0, 0, $e->getMessage());
        }
        @unlink($csvPath);
        csv_fail($e->getMessage());
    }
    exit;
}

// POST: create DB table + columns in one transaction, then register in schema.json
if ($action === 'csv_create_table') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csv_fail('POST required.', 405);
    }

    $body       = json_decode((string) file_get_contents('php://input'), true);
    $tableName  = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['table']  ?? '')));
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($body['schema'] ?? 'public')));
    if ($schemaName === '') {
        $schemaName = 'public';
    }
    $displayName = trim(strip_tags((string) ($body['display_name'] ?? '')));
    $rawCols     = is_array($body['columns'] ?? null) ? $body['columns'] : [];

    if ($tableName === '') {
        csv_fail('Table name is required.');
    }

    $allowedTypes = ['varchar(255)', 'text', 'int4', 'int8', 'boolean', 'date', 'timestamp', 'timestamptz'];

    $colDefs = [];
    $seen    = [];
    foreach ($rawCols as $col) {
        $cName = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($col['name'] ?? '')));
        $cType = in_array((string) ($col['type'] ?? ''), $allowedTypes, true)
            ? (string) $col['type']
            : 'varchar(255)';
        if ($cName === '' || $cName === 'id' || isset($seen[$cName])) {
            continue;
        }
        $seen[$cName] = true;
        $colDefs[]    = ['name' => $cName, 'type' => $cType];
    }

    try {
        $conn = db_connect();

        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable  = pg_escape_identifier($conn, $tableName);

        @pg_query($conn, 'BEGIN');

        $res = @pg_query($conn, "CREATE TABLE {$safeSchema}.{$safeTable} (id serial4 NOT NULL PRIMARY KEY)");
        if ($res === false) {
            @pg_query($conn, 'ROLLBACK');
            csv_fail('Cannot create table: ' . substr(pg_last_error($conn), 0, 300));
        }

        foreach ($colDefs as $col) {
            $safeCol = pg_escape_identifier($conn, $col['name']);
            $res = @pg_query($conn, "ALTER TABLE {$safeSchema}.{$safeTable} ADD COLUMN {$safeCol} {$col['type']}");
            if ($res === false) {
                $err = substr(pg_last_error($conn), 0, 300);
                @pg_query($conn, 'ROLLBACK');
                csv_fail('Cannot add column "' . $col['name'] . '": ' . $err);
            }
        }

        @pg_query($conn, 'COMMIT');

        // Map PG types to schema types
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

        $schemaCols = [
            'id' => [
                'display_name' => 'ID',
                'type'         => 'number',
                'not_null'     => true,
                'show_in_grid' => false,
                'show_in_edit' => false,
                'readonly'     => true,
            ],
        ];
        foreach ($colDefs as $col) {
            $schemaCols[$col['name']] = [
                'display_name' => ucwords(str_replace('_', ' ', $col['name'])),
                'type'         => $typeMap[$col['type']] ?? 'text',
                'not_null'     => false,
                'show_in_grid' => true,
                'show_in_edit' => true,
                'readonly'     => false,
            ];
        }

        $schemaFile = __DIR__ . '/../config/schema.json';
        $schemaData = file_exists($schemaFile)
            ? (json_decode((string) file_get_contents($schemaFile), true) ?? [])
            : [];
        if (!isset($schemaData['tables'])) {
            $schemaData['tables'] = [];
        }
        $schemaData['tables'][$tableName] = [
            'display_name' => $displayName,
            'schema'       => $schemaName,
            'columns'      => $schemaCols,
            'foreign_keys' => [],
            'subtables'    => [],
            'hidden'       => false,
            'icon'         => '',
        ];

        $encoded = json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($schemaFile, $encoded) === false) {
            csv_fail('Table created in DB but failed to write schema.json. Run Sync Columns manually.');
        }

        echo json_encode(['status' => 'success']);
    } catch (\Exception $e) {
        csv_fail($e->getMessage());
    }
    exit;
}

if ($action === 'csv_schemas') {
    try {
        $conn = db_connect();
        $res  = pg_query(
            $conn,
            "SELECT schema_name FROM information_schema.schemata
              WHERE schema_name NOT LIKE 'pg_%'
                AND schema_name <> 'information_schema'
              ORDER BY schema_name"
        );
        if ($res === false) {
            csv_fail('Failed to query schemas.');
        }
        $schemas = [];
        while ($row = pg_fetch_row($res)) {
            $schemas[] = $row[0];
        }
        echo json_encode(['status' => 'success', 'schemas' => $schemas]);
    } catch (\Exception $e) {
        csv_fail($e->getMessage());
    }
    exit;
}

if ($action === 'csv_import_config') {
    echo json_encode([
        'status'            => 'success',
        'max_upload_mb'     => (int) floor(CSV_MAX_BYTES / 1048576),
        'max_execution_sec' => (int) ini_get('max_execution_time'),
        'memory_limit'      => ini_get('memory_limit'),
        'batch_size'        => CSV_BATCH_SIZE,
    ]);
    exit;
}

csv_fail('Unknown action.', 404);
