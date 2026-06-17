<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// admin/api_fdw.php — MySQL gateway (FDW) configuration admin API
// Auth gate: session + role === 'admin' (403); CSRF on POST
// actions: mysql_status, mysql_credentials_save, mysql_test, mysql_tables_save, mysql_preview, mysql_meta_save, mysql_columns_sync
// Manages config/mysql_gateway.json and the external MySQL connection; uses DatabaseFactory/PostgresGateway/MysqlGateway

declare(strict_types=1);

use OpenSparrow\Db\DatabaseFactory;

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/db/DatabaseGatewayInterface.php';
require_once __DIR__ . '/../../includes/db/PostgresGateway.php';
require_once __DIR__ . '/../../includes/db/MysqlGateway.php';
require_once __DIR__ . '/../../includes/db/DatabaseFactory.php';

start_session();

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

$action = $_GET['action'] ?? '';

function fdw_respond(array $data): void
{
    echo json_encode($data);
    exit;
}

function fdw_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'error' => $message]);
    exit;
}

function fdw_config_path(): string
{
    return __DIR__ . '/../../config/mysql_gateway.json';
}

function fdw_credentials_path(): string
{
    return __DIR__ . '/../../config/mysql_connection.json';
}

function fdw_load_config(): array
{
    $path    = fdw_config_path();
    $raw     = is_file($path) ? @file_get_contents($path) : false;
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : ['mysql_tables' => []];
}

function fdw_save_config(array $config): bool
{
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return @file_put_contents(fdw_config_path(), $json) !== false;
}

function fdw_load_credentials(): array
{
    $path    = fdw_credentials_path();
    $raw     = is_file($path) ? @file_get_contents($path) : false;
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function fdw_save_credentials(array $creds): bool
{
    $json = json_encode($creds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return @file_put_contents(fdw_credentials_path(), $json) !== false;
}

function fdw_mysql_configured(): bool
{
    return MYSQL_HOST !== '' && MYSQL_DB !== '' && MYSQL_USER !== '';
}

function fdw_mysql_pdo(): ?PDO
{
    if (!fdw_mysql_configured()) {
        return null;
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=5',
            MYSQL_HOST,
            MYSQL_PORT,
            MYSQL_DB
        );
        return new PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (\PDOException $e) {
        error_log('[api_fdw][mysql_pdo] ' . $e->getMessage());
        return null;
    }
}

function fdw_test_connection(string $host, int $port, string $db, string $user, string $pass): bool
{
    if ($host === '' || $db === '' || $user === '') {
        return false;
    }
    try {
        $dsn    = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=5',
            $host,
            $port,
            $db
        );
        $testPdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        unset($testPdo);
        return true;
    } catch (\PDOException $e) {
        error_log('[api_fdw][test_connection] ' . $e->getMessage());
        return false;
    }
}

function fdw_credentials_source(): string
{
    if (get_env('MYSQL_HOST', '') !== '') {
        return 'env';
    }
    return is_file(__DIR__ . '/../../config/mysql_connection.json') ? 'file' : 'none';
}

function fdw_map_mysql_type(string $dataType): ?string
{
    static $map = [
        'int'        => 'number',
        'bigint'     => 'number',
        'smallint'   => 'number',
        'tinyint'    => 'number',
        'mediumint'  => 'number',
        'year'       => 'number',
        'decimal'    => 'number',
        'numeric'    => 'number',
        'float'      => 'number',
        'double'     => 'number',
        'bit'        => 'number',
        'varchar'    => 'text',
        'char'       => 'text',
        'text'       => 'text',
        'tinytext'   => 'text',
        'mediumtext' => 'text',
        'longtext'   => 'text',
        'enum'       => 'text',
        'set'        => 'text',
        'json'       => 'text',
        'date'       => 'date',
        'datetime'   => 'timestamp',
        'timestamp'  => 'timestamp',
        'time'       => 'text',
    ];
    return $map[$dataType] ?? null;
}

function fdw_discover_columns(PDO $pdo, string $dbName, string $tableName): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$dbName, $tableName]);
    $rows = $stmt->fetchAll();

    $columns   = [];
    $pkRealCol = null;

    foreach ($rows as $row) {
        $colName  = (string) $row['COLUMN_NAME'];
        $colType  = strtolower((string) $row['COLUMN_TYPE']);
        $dataType = strtolower((string) $row['DATA_TYPE']);
        $isPk     = $row['COLUMN_KEY'] === 'PRI';
        $extra    = strtolower((string) $row['EXTRA']);
        $isAuto   = str_contains($extra, 'auto_increment');
        // Only auto-updating columns (e.g. updated_at ON UPDATE CURRENT_TIMESTAMP)
        // are forced readonly — DB overwrites them on every write. Columns that are
        // merely default-generated (e.g. created_at DEFAULT CURRENT_TIMESTAMP) stay
        // editable so inline editing works.
        $isGen    = str_contains($extra, 'on update');

        $oType = ($colType === 'tinyint(1)') ? 'boolean' : fdw_map_mysql_type($dataType);
        if ($oType === null) {
            continue;
        }

        $col = [
            'type'         => $oType,
            'display_name' => ucwords(str_replace('_', ' ', $colName)),
            'show_in_grid' => !$isPk,
        ];
        if ($isPk || $isAuto || $isGen) {
            $col['readonly'] = true;
        }
        if ($isPk && $pkRealCol === null) {
            $pkRealCol = $colName;
        }
        $columns[$colName] = $col;
    }

    // OpenSparrow requires an 'id' PK column; if the table uses a different
    // primary key name, alias it to 'id' and record the real column name in
    // mysql_pk so the API handlers can emit the correct SQL.
    $mysqlPk = null;
    if ($pkRealCol !== null && $pkRealCol !== 'id' && !isset($columns['id'])) {
        $columns['id']                 = $columns[$pkRealCol];
        $columns['id']['show_in_grid'] = false;
        $columns['id']['readonly']     = true;
        unset($columns[$pkRealCol]);
        $mysqlPk = $pkRealCol;
    }

    return ['columns' => $columns, 'mysql_pk' => $mysqlPk];
}

// ---- mysql_status (GET) ----------------------------------------------------
if ($action === 'mysql_status') {
    $configured = fdw_mysql_configured();
    $connected  = $configured && fdw_mysql_pdo() !== null;
    $cfg        = fdw_load_config();
    fdw_respond([
        'status'       => 'success',
        'configured'   => $configured,
        'connected'    => $connected,
        'source'       => fdw_credentials_source(),
        'host'         => MYSQL_HOST,
        'port'         => MYSQL_PORT,
        'database'     => MYSQL_DB,
        'user'         => MYSQL_USER,
        'has_password' => MYSQL_PASSWORD !== '',
        'mysql_tables' => $cfg['mysql_tables'] ?? [],
        'table_meta'   => $cfg['table_meta'] ?? (object) [],
    ]);
}

// ---- mysql_credentials_save (POST) -----------------------------------------
if ($action === 'mysql_credentials_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fdw_fail('POST required.', 405);
    }
    if (DEMO_MODE) {
        fdw_fail('Blocked in demo mode.', 403);
    }
    $body = (array) json_decode((string) file_get_contents('php://input'), true);
    $host = trim((string) ($body['host'] ?? ''));
    $port = max(1, min(65535, (int) ($body['port'] ?? 3306)));
    $db   = trim((string) ($body['database'] ?? ''));
    $user = trim((string) ($body['user'] ?? ''));
    $pass = (string) ($body['password'] ?? '');

    if ($host === '' || $db === '' || $user === '') {
        fdw_fail('Host, database and user are required.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
        fdw_fail('Invalid host value.');
    }

    // Keep existing password if the form field was left blank.
    if ($pass === '') {
        $existing = fdw_load_credentials();
        $pass     = (string) ($existing['password'] ?? '');
    }

    $creds = ['host' => $host, 'port' => $port, 'database' => $db, 'user' => $user, 'password' => $pass];
    if (!fdw_save_credentials($creds)) {
        fdw_fail('Could not write config/mysql_connection.json.', 500);
    }

    $connected = fdw_test_connection($host, $port, $db, $user, $pass);
    $conn      = db_connect();
    $userId    = (int) ($_SESSION['user_id'] ?? 0);
    log_user_action($conn, $userId, 'MYSQL_CREDENTIALS_SAVE', 'mysql_gateway', null);
    fdw_respond([
        'status'    => 'success',
        'connected' => $connected,
        'message'   => $connected
            ? 'Credentials saved. Connection successful.'
            : 'Credentials saved, but connection test failed — check host, database, user and password.',
    ]);
}

// ---- mysql_test (POST) -----------------------------------------------------
if ($action === 'mysql_test') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fdw_fail('POST required.', 405);
    }
    if (DEMO_MODE) {
        fdw_fail('Blocked in demo mode.', 403);
    }
    if (!fdw_mysql_configured()) {
        fdw_fail('MySQL not configured. Enter credentials in the form or set MYSQL_* env vars.');
    }
    if (fdw_mysql_pdo() === null) {
        fdw_fail('Connection failed. Verify host, port, database, user and password.');
    }
    fdw_respond(['status' => 'success', 'message' => 'Connection successful.']);
}

// ---- mysql_tables_save (POST) ----------------------------------------------
if ($action === 'mysql_tables_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fdw_fail('POST required.', 405);
    }
    if (DEMO_MODE) {
        fdw_fail('Blocked in demo mode.', 403);
    }
    $body   = (array) json_decode((string) file_get_contents('php://input'), true);
    $raw    = array_map('trim', (array) ($body['mysql_tables'] ?? []));
    $tables = [];
    foreach ($raw as $t) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t) === 1) {
            $tables[] = $t;
        }
    }
    $tables    = array_values(array_unique($tables));
    $cfg       = fdw_load_config();
    $oldTables = array_values((array) ($cfg['mysql_tables'] ?? []));
    $removed   = array_values(array_diff($oldTables, $tables));
    $added     = array_values(array_diff($tables, $oldTables));

    foreach ($removed as $rt) {
        unset($cfg['table_meta'][$rt]);
    }
    $cfg['mysql_tables'] = $tables;
    if (!fdw_save_config($cfg)) {
        fdw_fail('Could not write config/mysql_gateway.json.', 500);
    }

    $schemaPath = __DIR__ . '/../../config/schema.json';
    $schemaRaw  = is_file($schemaPath) ? @file_get_contents($schemaPath) : false;
    $schema     = ($schemaRaw !== false) ? json_decode($schemaRaw, true) : null;

    if (is_array($schema)) {
        foreach ($removed as $rt) {
            unset($schema['tables'][$rt]);
        }
        if (!empty($added)) {
            $pdo = fdw_mysql_pdo();
            foreach ($added as $at) {
                if (isset($schema['tables'][$at])) {
                    continue;
                }
                $label = ucwords(str_replace('_', ' ', $at));
                $entry = ['display_name' => $label, 'schema' => 'public', 'columns' => (object) []];
                if ($pdo !== null) {
                    $disc = fdw_discover_columns($pdo, MYSQL_DB, $at);
                    $entry['columns'] = $disc['columns'] ?: (object) [];
                    if ($disc['mysql_pk'] !== null) {
                        $entry['mysql_pk'] = $disc['mysql_pk'];
                    }
                }
                $schema['tables'][$at] = $entry;
            }
        }
        @file_put_contents(
            $schemaPath,
            json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $conn   = db_connect();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    log_user_action($conn, $userId, 'MYSQL_GATEWAY_TABLES_SAVE', 'mysql_gateway', null);
    fdw_respond(['status' => 'success', 'mysql_tables' => $tables]);
}

// ---- mysql_preview (GET) — DatabaseFactory usage example ------------------
if ($action === 'mysql_preview') {
    $table = trim($_GET['table'] ?? '');
    if ($table === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        fdw_fail('Invalid or missing table name.');
    }
    $cfg = fdw_load_config();
    DatabaseFactory::setMysqlTables($cfg['mysql_tables'] ?? []);

    $pgConn   = db_connect();
    $mysqlPdo = fdw_mysql_pdo();
    $gateway  = DatabaseFactory::make($table, $pgConn, $mysqlPdo);
    $rows     = $gateway->fetchAll($table);

    $isMysql = $mysqlPdo !== null && in_array($table, $cfg['mysql_tables'] ?? [], true);
    fdw_respond([
        'status'  => 'success',
        'table'   => $table,
        'gateway' => $isMysql ? 'mysql' : 'postgres',
        'rows'    => array_slice($rows, 0, 5),
    ]);
}

// ---- mysql_meta_save (POST) — per-table display_name / icon / hidden -------
if ($action === 'mysql_meta_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fdw_fail('POST required.', 405);
    }
    if (DEMO_MODE) {
        fdw_fail('Blocked in demo mode.', 403);
    }
    $body  = (array) json_decode((string) file_get_contents('php://input'), true);
    $table = trim((string) ($body['table'] ?? ''));
    if ($table === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        fdw_fail('Invalid table name.');
    }

    $cfg = fdw_load_config();
    if (!in_array($table, $cfg['mysql_tables'] ?? [], true)) {
        fdw_fail('Table is not in the MySQL routing list.');
    }

    $displayName = trim((string) ($body['display_name'] ?? ''));
    $icon        = trim((string) ($body['icon'] ?? ''));
    $hidden      = !empty($body['hidden']);

    if ($icon !== '' && preg_match('/^assets\/icons\/[A-Za-z0-9_.%-]+\.png$/', $icon) !== 1) {
        fdw_fail('Invalid icon path.');
    }

    // Persist metadata in mysql_gateway.json
    if (!isset($cfg['table_meta']) || !is_array($cfg['table_meta'])) {
        $cfg['table_meta'] = [];
    }
    $cfg['table_meta'][$table] = [
        'display_name' => $displayName,
        'icon'         => $icon,
        'hidden'       => $hidden,
    ];
    if (!fdw_save_config($cfg)) {
        fdw_fail('Could not write config/mysql_gateway.json.', 500);
    }

    // Propagate to schema.json so the frontend picks it up immediately
    $schemaPath = __DIR__ . '/../../config/schema.json';
    $schemaRaw  = is_file($schemaPath) ? @file_get_contents($schemaPath) : false;
    $schema     = ($schemaRaw !== false) ? json_decode($schemaRaw, true) : null;
    if (!is_array($schema)) {
        fdw_fail('Cannot read config/schema.json.', 500);
    }
    if (!isset($schema['tables'][$table])) {
        $schema['tables'][$table] = ['schema' => 'public', 'columns' => (object) []];
    }
    if ($displayName !== '') {
        $schema['tables'][$table]['display_name'] = $displayName;
    }
    if ($icon !== '') {
        $schema['tables'][$table]['icon'] = $icon;
    } else {
        unset($schema['tables'][$table]['icon']);
    }
    if ($hidden) {
        $schema['tables'][$table]['hidden'] = true;
    } else {
        unset($schema['tables'][$table]['hidden']);
    }

    $newJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($schemaPath, $newJson) === false) {
        fdw_fail('Could not write config/schema.json.', 500);
    }

    $conn   = db_connect();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    log_user_action($conn, $userId, 'MYSQL_TABLE_META_SAVE', 'mysql_gateway', null);
    fdw_respond(['status' => 'success', 'table' => $table]);
}

// ---- mysql_columns_sync (POST) — re-discover MySQL columns for a table --------
if ($action === 'mysql_columns_sync') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fdw_fail('POST required.', 405);
    }
    if (DEMO_MODE) {
        fdw_fail('Blocked in demo mode.', 403);
    }
    $body  = (array) json_decode((string) file_get_contents('php://input'), true);
    $table = trim((string) ($body['table'] ?? ''));
    if ($table === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        fdw_fail('Invalid table name.');
    }

    $cfg = fdw_load_config();
    if (!in_array($table, $cfg['mysql_tables'] ?? [], true)) {
        fdw_fail('Table is not in the MySQL routing list.');
    }

    $pdo = fdw_mysql_pdo();
    if ($pdo === null) {
        fdw_fail('MySQL connection not available.', 503);
    }

    $disc = fdw_discover_columns($pdo, MYSQL_DB, $table);

    $schemaPath = __DIR__ . '/../../config/schema.json';
    $schemaRaw  = is_file($schemaPath) ? @file_get_contents($schemaPath) : false;
    $schema     = ($schemaRaw !== false) ? json_decode($schemaRaw, true) : null;

    if (!is_array($schema)) {
        fdw_fail('Cannot read config/schema.json.', 500);
    }

    if (!isset($schema['tables'][$table])) {
        $label = ucwords(str_replace('_', ' ', $table));
        $schema['tables'][$table] = ['display_name' => $label, 'schema' => 'public'];
    }

    // External MySQL tables are not addressed by a PostgreSQL schema; keep the
    // schema slot at 'public' so an old MySQL-database-name value is corrected.
    $schema['tables'][$table]['schema'] = 'public';
    $schema['tables'][$table]['columns'] = $disc['columns'] ?: (object) [];
    if ($disc['mysql_pk'] !== null) {
        $schema['tables'][$table]['mysql_pk'] = $disc['mysql_pk'];
    } else {
        unset($schema['tables'][$table]['mysql_pk']);
    }

    $newJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($schemaPath, $newJson) === false) {
        fdw_fail('Could not write config/schema.json.', 500);
    }

    $conn   = db_connect();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    log_user_action($conn, $userId, 'MYSQL_COLUMNS_SYNC', 'mysql_gateway', null);
    fdw_respond([
        'status'   => 'success',
        'columns'  => $disc['columns'],
        'mysql_pk' => $disc['mysql_pk'],
        'table'    => $table,
    ]);
}

// ---- fallthrough -----------------------------------------------------------
http_response_code(400);
echo json_encode(['status' => 'error', 'error' => 'Unknown action.']);
