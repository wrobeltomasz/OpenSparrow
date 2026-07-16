<?php

declare(strict_types=1);

// includes/admin/schema.php — admin api.php module: table/column DDL + schema.json sync (create_table, add_column,
// schema_add_table,
// list_system_tables, sync_schema, get_db_columns).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Handle table creation
if ($action === 'create_table') {
    header('Content-Type: application/json');
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);

    // Sanitize schema and table variables
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? 'public'));
    $tableName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table'] ?? ''));

    if (empty($tableName) || empty($schemaName)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid schema or table name.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // Prepare schema-prefixed identifiers
        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable = pg_escape_identifier($conn, $tableName);

        // Execute table creation query
        $sql = "CREATE TABLE " . $safeSchema . "." . $safeTable . " (id serial4 NOT NULL PRIMARY KEY)";
        $res = @pg_query($conn, $sql);

        if (!$res) {
            admin_db_fail($conn, 'create_table');
        }

        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'add_column') {
    header('Content-Type: application/json');
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);

    // Strict input sanitization
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
        echo json_encode(['status' => 'error', 'error' => 'Invalid table or column name.']);
        exit;
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

        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'add_column');
        }

        if ($comment !== '') {
            $safeComment = pg_escape_literal($conn, $comment);
            $sqlComment = "COMMENT ON COLUMN " . $safeSchema . "." . $safeTable . "." . $safeCol . " IS " . $safeComment;
            @pg_query($conn, $sqlComment);
        }

        if ($fkTable !== '' && $fkCol !== '') {
            $safeFkTable  = pg_escape_identifier($conn, $fkTable);
            $safeFkCol    = pg_escape_identifier($conn, $fkCol);
            $constraintName = pg_escape_identifier($conn, 'fk_' . $tableName . '_' . $colName);
            $sqlFk = "ALTER TABLE " . $safeSchema . "." . $safeTable
                . " ADD CONSTRAINT " . $constraintName
                . " FOREIGN KEY (" . $safeCol . ")"
                . " REFERENCES " . $safeSchema . "." . $safeFkTable . " (" . $safeFkCol . ")";
            $resFk = @pg_query($conn, $sqlFk);
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Register a newly created table in schema.json
if ($action === 'schema_add_table') {
    header('Content-Type: application/json');

    require_not_demo('Disabled in Demo Mode.', 403);

    $input       = json_decode(file_get_contents('php://input'), true);
    $tableName   = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']   ?? ''));
    $schemaName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema']  ?? 'public'));
    $displayName = trim(strip_tags((string)($input['display_name'] ?? '')));
    $columns     = is_array($input['columns'] ?? null) ? $input['columns'] : [];

    if (empty($tableName)) {
        echo json_encode(['status' => 'error', 'error' => 'Table name is required.']);
        exit;
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
        'id' => ['display_name' => 'ID', 'type' => 'number', 'not_null' => true, 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true],
    ];

    foreach ($columns as $col) {
        $cName = preg_replace('/[^a-z0-9_]/', '', strtolower($col['name'] ?? ''));
        $cType = $col['type'] ?? 'varchar(255)';
        if ($cName === '' || !isset($typeMap[$cType])) {
            continue;
        }
        $cDisplay = trim(strip_tags((string)($col['display_name'] ?? '')));
        if ($cDisplay === '') {
            $cDisplay = ucwords(str_replace('_', ' ', $cName));
        }
        $entry = [
            'display_name' => $cDisplay,
            'type'         => $typeMap[$cType],
            'not_null'     => !empty($col['not_null']),
            'show_in_grid' => true,
            'show_in_edit' => true,
            'readonly'     => false,
        ];
        if (!empty($col['description'])) {
            $entry['description'] = trim(strip_tags((string)$col['description']));
        }
        if (!empty($col['fk_table']) && !empty($col['fk_column'])) {
            $entry['fk_table']  = preg_replace('/[^a-z0-9_]/', '', strtolower($col['fk_table']));
            $entry['fk_column'] = preg_replace('/[^a-z0-9_]/', '', strtolower($col['fk_column']));
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
        echo json_encode(['status' => 'error', 'error' => $schemaResult['error'] ?? 'Could not save schema.']);
        exit;
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// List spw_* system tables from the database for the backup page
if ($action === 'list_system_tables') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sysSchema = sys_schema();
        $sql = "SELECT table_name, table_schema FROM information_schema.tables
                WHERE table_schema = \$1 AND table_name LIKE 'spw\\_%' ESCAPE '\\'
                AND table_type = 'BASE TABLE' ORDER BY table_name";
        $res = @pg_query_params($conn, $sql, [$sysSchema]);
        if (!$res) {
            admin_db_fail($conn, 'list_system_tables');
        }
        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $tables[] = ['name' => $row['table_name'], 'schema' => $row['table_schema']];
        }
        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Fetch all tables from a specific database schema
// Parameters are accepted via POST JSON body (preferred — avoids WAF/ModSecurity
// rules that flag SQL-looking GET query strings on shared hosting) with a GET
// fallback for backward compatibility.
if ($action === 'sync_schema') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $schemaName = $body['schema_name'] ?? $_POST['schema_name'] ?? $_GET['schema_name'] ?? 'public';
        // Exclude OpenSparrow system tables (spw_*) so they cannot be imported as user tables.
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = $1 AND table_type = 'BASE TABLE' AND table_name NOT LIKE 'spw\\_%' ESCAPE '\\'";
        $res = @pg_query_params($conn, $sql, [$schemaName]);
        if (!$res) {
            admin_db_fail($conn, 'sync_schema');
        }

        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $tables[] = $row['table_name'];
        }

        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Fetch all columns and their data types for a specific table
// Parameters are accepted via POST JSON body (preferred — avoids WAF/ModSecurity
// rules that flag SQL-looking GET query strings on shared hosting) with a GET
// fallback for backward compatibility.
if ($action === 'get_db_columns') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $tableName = $body['table'] ?? $_POST['table'] ?? $_GET['table'] ?? '';
        $schemaName = $body['schema_name'] ?? $_POST['schema_name'] ?? $_GET['schema_name'] ?? 'public';
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
        $res = @pg_query_params($conn, $sql, [$schemaName, $tableName]);
        if (!$res) {
            admin_db_fail($conn, 'get_db_columns');
        }

        $columns = [];
        while ($row = pg_fetch_assoc($res)) {
            $colName = $row['column_name'];
            $dataType = $row['data_type'];
            $udtName = $row['udt_name'];
            $enumValues = null;
            // Fetch ENUM values only for user-defined types safely using pg_escape_identifier
            if ($dataType === 'USER-DEFINED') {
                $safeSchema = pg_escape_identifier($conn, $schemaName);
                $safeUdt = pg_escape_identifier($conn, $udtName);
                $enumSql = "SELECT unnest(enum_range(NULL::$safeSchema.$safeUdt))::varchar AS enum_value";
                $enumRes = @pg_query($conn, $enumSql);
                if ($enumRes) {
                    $enumValues = [];
                    while ($e = pg_fetch_assoc($enumRes)) {
                        $enumValues[] = $e['enum_value'];
                    }
                }
            }

            // Create standard array and append column name
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

            // Append element to array to force PHP to output JSON Array
            $columns[] = $colData;
        }

        echo json_encode(['status' => 'success', 'columns' => $columns]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
