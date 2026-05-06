<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized access. Log in first.']);
    exit;
}

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$isDemoMode = DEMO_MODE;

// Never leak raw Postgres errors (schema names, constraint names, column lists)
// into the HTTP response. Details go to the PHP error log; the client gets a
// stable, generic message so the operator knows to check the server logs.
function admin_db_fail($conn, string $context): void
{
    $raw = $conn !== null ? pg_last_error($conn) : 'no connection';
    error_log('[admin_api][' . $context . '] ' . $raw);
    throw new RuntimeException('Database operation failed. Check server logs for details.');
}
// CSRF Protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

// Ensure state-changing actions use POST method to prevent CSRF via GET
$postActions = ['save', 'import', 'init_db', 'users_add', 'users_toggle', 'users_update_role', 'users_change_password', 'create_table', 'add_column', 'schema_add_table', 'run_cron_notifications', 'backup_tables', 'set_snapshot_setting'];
if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// Run cron_notifications.php ad-hoc and return captured output
if ($action === 'run_cron_notifications') {
    header('Content-Type: application/json');
    $cronScript = realpath(__DIR__ . '/../cron/cron_notifications.php');
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => 'Cron script not found.']);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $lines = [];
    $returnCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' admin 2>&1', $lines, $returnCode);
    echo json_encode(['status' => 'success', 'output' => implode("\n", $lines)]);
    exit;
}

// Initialize database tables and migrations
if ($action === 'init_db') {
    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();

        $schemaIdent = '"' . str_replace('"', '""', sys_schema()) . '"';
        $tUsers = sys_table('users');
        $tUsersLog = sys_table('users_log');
        $tUsersNotifications = sys_table('users_notifications');
        $tCronLog = sys_table('users_notifications_log');
        $tFiles = sys_table('files');
        $tLoginAttempts = sys_table('login_attempts');
        $tComments = sys_table('comments');
        $tRecordSnapshots = sys_table('record_snapshots');

        // Prepare missing DB updates and tables creation
        $queries = [
            "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
			"CREATE TABLE IF NOT EXISTS $tUsers ( id serial4 NOT NULL, username varchar(50) NOT NULL, password_hash varchar(255) NOT NULL, salt varchar(64), password_algo varchar(32) DEFAULT 'argon2id' NOT NULL, password_params jsonb DEFAULT '{}'::jsonb, is_active bool DEFAULT true, role varchar(20) DEFAULT 'editor' NOT NULL, CONSTRAINT spw_users_pkey PRIMARY KEY (id), CONSTRAINT spw_users_username_key UNIQUE (username) )",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS is_active bool DEFAULT true",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS \"role\" varchar(20) DEFAULT 'editor' NOT NULL",
			"ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS salt varchar(64)",
			"ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_algo varchar(32) DEFAULT 'argon2id' NOT NULL",
			"ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_params jsonb DEFAULT '{}'::jsonb",
            "CREATE TABLE IF NOT EXISTS $tUsersLog ( id serial4 NOT NULL, user_id int4 NOT NULL, \"action\" varchar(50) NOT NULL, target_table varchar(100), record_id int4, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_log_pkey PRIMARY KEY (id) )",
            "CREATE TABLE IF NOT EXISTS $tUsersNotifications ( id serial4 NOT NULL, user_id int8 NOT NULL, title varchar(255) NOT NULL, link varchar(255), source_table varchar(100), source_id int8, is_read bool DEFAULT false, notify_date date NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_notifications_pkey PRIMARY KEY (id), CONSTRAINT spw_users_notifications_user_id_source_table_source_id_notify_d_key UNIQUE (user_id, source_table, source_id, notify_date) )",
            "CREATE TABLE IF NOT EXISTS $tFiles ( id serial4 NOT NULL, \"uuid\" uuid DEFAULT gen_random_uuid() NOT NULL, \"name\" varchar(255) NOT NULL, display_name varchar(255) NULL, \"type\" varchar(50) NOT NULL, mime_type varchar(100) NOT NULL, \"extension\" varchar(20) NOT NULL, size_bytes int8 DEFAULT 0 NOT NULL, storage_path text NOT NULL, related_table varchar(100) NULL, related_id int4 NULL, related_field varchar(100) NULL, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, description text NULL, tags _text NULL, metadata jsonb NULL, CONSTRAINT spw_files_pkey PRIMARY KEY (id), CONSTRAINT spw_files_uuid_key UNIQUE (uuid), CONSTRAINT spw_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $tUsers(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_deleted_at ON $tFiles USING btree (deleted_at) WHERE (deleted_at IS NULL)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_metadata ON $tFiles USING gin (metadata)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_related ON $tFiles USING btree (related_table, related_id)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_tags ON $tFiles USING gin (tags)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_type ON $tFiles USING btree (type)",
            "CREATE INDEX IF NOT EXISTS idx_spw_files_uploaded_by ON $tFiles USING btree (uploaded_by)",
            "CREATE TABLE IF NOT EXISTS $tLoginAttempts ( id serial4 NOT NULL, username varchar(50) NOT NULL, ip_hash varchar(64) NOT NULL, attempted_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_login_attempts_pkey PRIMARY KEY (id) )",
            "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_username ON $tLoginAttempts USING btree (username, attempted_at)",
            "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_ip ON $tLoginAttempts USING btree (ip_hash, attempted_at)",
            "CREATE TABLE IF NOT EXISTS $tCronLog ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', sources_processed int4 NULL, notifications_created int4 NULL, error_message text NULL, CONSTRAINT spw_users_notifications_log_pkey PRIMARY KEY (id) )",
            "CREATE INDEX IF NOT EXISTS idx_spw_cron_log_started_at ON $tCronLog USING btree (started_at)",
            "UPDATE $tUsers SET role = 'editor' WHERE role = 'full'",
            "UPDATE $tUsers SET role = 'viewer' WHERE role = 'readonly'",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS avatar_id smallint",
            "CREATE TABLE IF NOT EXISTS $tComments ( id serial4 NOT NULL, related_table varchar(100) NOT NULL, related_id int4 NOT NULL, user_id int4 NOT NULL, body text NOT NULL, created_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, CONSTRAINT spw_comments_pkey PRIMARY KEY (id), CONSTRAINT spw_comments_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
            "CREATE INDEX IF NOT EXISTS idx_spw_comments_related ON $tComments USING btree (related_table, related_id, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_spw_comments_user_id ON $tComments USING btree (user_id)",
            "CREATE TABLE IF NOT EXISTS $tRecordSnapshots ( id serial4 NOT NULL, log_id int4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, snapshot jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_record_snapshots_pkey PRIMARY KEY (id), CONSTRAINT spw_record_snapshots_log_id_fkey FOREIGN KEY (log_id) REFERENCES $tUsersLog(id) ON DELETE CASCADE )",
            "ALTER TABLE $tRecordSnapshots DROP COLUMN IF EXISTS snapshot_type",
            "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_log_id ON $tRecordSnapshots USING btree (log_id)",
            "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_table_record ON $tRecordSnapshots USING btree (table_name, record_id)"
        ];
        
        foreach ($queries as $q) {
            $res = @pg_query($conn, $q);
            if (!$res) {
                admin_db_fail($conn, 'init_db');
            }
        }

        // Create default admin account for a clean installation (only when no users exist at all).
        // Default credentials: admin / admin — must be changed immediately after first login.
        $firstAdminHash = password_hash('admin', PASSWORD_DEFAULT);
        $resAdmin = @pg_query_params(
            $conn,
            "INSERT INTO $tUsers (username, password_hash, is_active, role) SELECT 'admin', \$1, true, 'admin' WHERE NOT EXISTS (SELECT 1 FROM $tUsers LIMIT 1)",
            [$firstAdminHash]
        );
        if (!$resAdmin) {
            admin_db_fail($conn, 'init_db:first_admin');
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'System tables initialized successfully.']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch list of all system users
if ($action === 'users_list') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();
        $sql = "SELECT id, username, is_active, role FROM " . sys_table('users') . " ORDER BY id ASC";
        $res = @pg_query($conn, $sql);
        if (!$res) {
            $err = pg_last_error($conn);
            if (str_contains($err, 'is_active') || str_contains($err, 'does not exist')) {
                throw new Exception("Database schema is outdated or missing. Please initialize tables.");
            }
            admin_db_fail($conn, 'users_list');
        }

        $users = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true);
            $users[] = $row;
        }

        echo json_encode(['status' => 'success', 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Add a new user securely
if ($action === 'users_add') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role = in_array($data['role'] ?? '', ['admin', 'editor', 'viewer'], true) ? $data['role'] : 'editor';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'error' => 'Username and password are required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/api_helpers.php';
        $conn = db_connect();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO " . sys_table('users') . " (username, password_hash, is_active, role) VALUES ($1, $2, true, $3) RETURNING id";
        $res = @pg_query_params($conn, $sql, [$username, $hash, $role]);
        if (!$res) {
            admin_db_fail($conn, 'users_add');
        }
        $newRow = pg_fetch_assoc($res);
        $newUserId = (int)($newRow['id'] ?? 0);
        log_user_action($conn, 0, 'ADD_USER', 'users', $newUserId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Toggle user activation status
if ($action === 'users_toggle') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $isActive = (bool)($data['is_active'] ?? false);
    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET is_active = $1 WHERE id = $2";
        $res = @pg_query_params($conn, $sql, [$isActive ? 'true' : 'false', $userId]);
        if (!$res) {
            admin_db_fail($conn, 'users_toggle');
        }
        log_user_action($conn, 0, 'TOGGLE_USER', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle user role update
if ($action === 'users_update_role') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $role = in_array($data['role'] ?? '', ['admin', 'editor', 'viewer'], true) ? $data['role'] : 'editor';

    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET role = $1 WHERE id = $2";
        $res = @pg_query_params($conn, $sql, [$role, $userId]);
        if (!$res) {
            admin_db_fail($conn, 'users_update_role');
        }
        log_user_action($conn, 0, 'UPDATE_ROLE', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Change a user's password (admin action — no current-password check required)
if ($action === 'users_change_password') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true);
    $userId   = (int)($data['id'] ?? 0);
    $password = $data['password'] ?? '';

    if ($userId <= 0 || $password === '') {
        echo json_encode(['status' => 'error', 'error' => 'User ID and password are required.']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['status' => 'error', 'error' => 'Password must be at least 8 characters.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $opts    = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, $opts);
        $sql     = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $res = @pg_query_params($conn, $sql, [
            $hash, $newSalt, 'argon2id',
            json_encode(['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1]),
            $userId,
        ]);
        if (!$res) {
            admin_db_fail($conn, 'users_change_password');
        }
        log_user_action($conn, 0, 'CHANGE_PASSWORD', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle table creation
if ($action === 'create_table') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Sanitize schema and table variables
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? 'public'));
    $tableName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table'] ?? ''));

    if (empty($tableName) || empty($schemaName)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid schema or table name.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_column') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // Strict input sanitization
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? 'public'));
    $tableName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']  ?? ''));
    $colName    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['column'] ?? ''));
    $colType    = $input['type'] ?? 'varchar(255)';
    $comment    = isset($input['comment']) ? trim((string)$input['comment']) : '';
    $fkTable    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_table']  ?? ''));
    $fkCol      = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_column'] ?? ''));
    $indexType  = $input['index'] ?? '';
    $notNull    = !empty($input['not_null']);
    $default    = trim((string)($input['default'] ?? ''));

    if (empty($tableName) || empty($colName) || empty($schemaName)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid schema, table or column name.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();

        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable  = pg_escape_identifier($conn, $tableName);
        $safeCol    = pg_escape_identifier($conn, $colName);

        $allowedTypes = ['varchar(255)', 'int4', 'int8', 'boolean', 'text', 'date', 'timestamp'];
        if (!in_array($colType, $allowedTypes, true)) {
            throw new Exception('Invalid data type provided.');
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Register a newly created table in schema.json
if ($action === 'schema_add_table') {
    header('Content-Type: application/json');

    if ($isDemoMode) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Disabled in Demo Mode.']);
        exit;
    }

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

    $schemaFile = __DIR__ . '/../includes/schema.json';
    $schemaData = [];
    if (file_exists($schemaFile)) {
        $schemaData = json_decode(file_get_contents($schemaFile), true) ?? [];
    }
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

    $encoded = json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (strlen($encoded) > CONFIG_FILE_MAX_BYTES) {
        echo json_encode(['status' => 'error', 'error' => 'schema.json would exceed maximum allowed size.']);
        exit;
    }

    file_put_contents($schemaFile, $encoded);
    echo json_encode(['status' => 'success']);
    exit;
}

// Check database connection and system health
if ($action === 'health') {
    $db_connected = false;
    $db_error = 'Unknown error';
    $pg_version = null;
    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();
        if ($conn) {
            $db_connected = true;
            $db_error = '';
            $vr = @pg_query($conn, 'SELECT version()');
            if ($vr) {
                $row = pg_fetch_row($vr);
                // Extract short version number from the verbose string e.g. "PostgreSQL 14.11 on ..."
                if (preg_match('/PostgreSQL\s+([\d.]+)/i', $row[0] ?? '', $m)) {
                    $pg_version = $m[1];
                }
            }
            pg_close($conn);
        }
    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }

    $versionFile = __DIR__ . '/../includes/VERSION';
    $appVersion = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

    $displayErrors = ini_get('display_errors');

    $data = [
        'app_version'      => $appVersion,

        // PHP environment
        'php_version'      => PHP_VERSION,
        'php_version_ok'   => version_compare(PHP_VERSION, '8.1.0', '>='),
        'memory_limit'     => ini_get('memory_limit'),
        'memory_limit_ok'  => (int) ini_get('memory_limit') >= 64 || ini_get('memory_limit') === '-1',
        'upload_max_filesize'    => ini_get('upload_max_filesize'),
        'upload_max_filesize_ok' => (int) ini_get('upload_max_filesize') >= 8,
        'display_errors_off'     => $displayErrors === '' || $displayErrors == '0' || strtolower((string) $displayErrors) === 'off',

        // Extensions
        'pgsql_ok'     => extension_loaded('pgsql') || extension_loaded('pdo_pgsql'),
        'json_ok'      => extension_loaded('json'),
        'session_ok'   => extension_loaded('session'),
        'mbstring_ok'  => extension_loaded('mbstring'),
        'fileinfo_ok'  => extension_loaded('fileinfo'),
        'openssl_ok'   => extension_loaded('openssl'),

        // Security functions
        'argon2id_ok'      => defined('PASSWORD_ARGON2ID'),
        'random_bytes_ok'  => function_exists('random_bytes'),
        'hash_equals_ok'   => function_exists('hash_equals'),
        'bin2hex_ok'       => function_exists('bin2hex'),

        // Database
        'db_connected' => $db_connected,
        'db_error'     => $db_error,
        'pg_version'   => $pg_version,

        // Filesystem
        'dir_writable'          => is_writable(__DIR__ . '/../includes'),
        'storage_writable'      => is_dir(__DIR__ . '/../storage') && is_writable(__DIR__ . '/../storage'),
        'storage_files_writable'=> is_dir(__DIR__ . '/../storage/files') && is_writable(__DIR__ . '/../storage/files'),

        // Config files
        'database_json_ok' => (static function () {
            $f = __DIR__ . '/../includes/database.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
        'schema_json_ok' => (static function () {
            $f = __DIR__ . '/../includes/schema.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
        'security_json_ok' => (static function () {
            $f = __DIR__ . '/../includes/security.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
    ];
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Export JSON configurations as a ZIP file
if ($action === 'export') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    $zipFile = sys_get_temp_dir() . '/sparrow_config_' . time() . '.zip';
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $includesDir = __DIR__ . '/../includes/';
        // Dodałem files.json do backupu
        $filesToBackup = ['schema.json', 'dashboard.json', 'calendar.json', 'database.json', 'security.json', 'workflows.json', 'files.json'];
        foreach ($filesToBackup as $f) {
            if (file_exists($includesDir . $f)) {
                $zip->addFile($includesDir . $f, $f);
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sparrow_backup.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cannot create zip file']);
        exit;
    }
}

// Import JSON configurations from a ZIP file safely
if ($action === 'import' && isset($_FILES['backup_file'])) {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup_file']['tmp_name']) === true) {
        $extractPath = __DIR__ . '/../includes/';
        $importAllowed = ['schema', 'dashboard', 'calendar', 'database', 'security', 'workflows', 'files'];
        $validFiles = [];

        // Validate each file inside the archive
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $basename = substr($filename, 0, -5); // strip .json suffix
            // Reject any path separator (blocks subdirs and traversal), non-.json, or unknown config name
            if (str_contains($filename, '/') || str_contains($filename, '\\')
                || substr($filename, -5) !== '.json'
                || !in_array($basename, $importAllowed, true)
            ) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid file detected. Only known config .json files are allowed.']);
                exit;
            }
            $validFiles[] = $filename;
        }

        // Extract only the validated files
        foreach ($validFiles as $file) {
            $zip->extractTo($extractPath, $file);
        }

        $zip->close();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid zip file']);
    exit;
}

// Scan directories for available icons
if ($action === 'list_icons') {
    $icons = [];
    $dirsToScan = [
        'assets/icons' => __DIR__ . '/../assets/icons',
        'assets/img' => __DIR__ . '/../assets/img'
    ];
    foreach ($dirsToScan as $prefix => $dirPath) {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'gif'])) {
                        $icons[] = $prefix . '/' . $file;
                    }
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'icons' => array_values(array_unique($icons))]);
    exit;
}

// List spw_* system tables from the database for the backup page
if ($action === 'list_system_tables') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../includes/db.php';
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// GET: return current record-snapshot setting and whether it is locked by env var
if ($action === 'get_snapshot_setting') {
    header('Content-Type: application/json');
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    $lockedByEnv = ($envVal !== false && $envVal !== '');
    $enabled = false;
    if ($lockedByEnv) {
        $enabled = ($envVal === 'true');
    } else {
        $settingsFile = __DIR__ . '/../includes/settings.json';
        if (is_file($settingsFile)) {
            $raw = @file_get_contents($settingsFile);
            if ($raw !== false) {
                $s = @json_decode($raw, true);
                $enabled = (bool) ($s['record_snapshots_enabled'] ?? false);
            }
        }
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = @db_connect();
        $tSnap = sys_table('record_snapshots');
        $countRes = $conn ? @pg_query($conn, "SELECT COUNT(*) FROM $tSnap") : false;
        $snapshotCount = ($countRes && ($cr = pg_fetch_row($countRes))) ? (int) $cr[0] : null;
        $tableExists = ($countRes !== false);
    } catch (Exception $e) {
        $snapshotCount = null;
        $tableExists = false;
    }

    echo json_encode([
        'enabled'        => $enabled,
        'locked_by_env'  => $lockedByEnv,
        'table_exists'   => $tableExists,
        'snapshot_count' => $snapshotCount,
    ]);
    exit;
}

// POST: toggle record-snapshot setting in includes/settings.json
if ($action === 'set_snapshot_setting') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    if ($envVal !== false && $envVal !== '') {
        echo json_encode(['status' => 'error', 'error' => 'Controlled by RECORD_SNAPSHOTS_ENABLED environment variable — cannot override from admin panel.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = (bool) ($body['enabled'] ?? false);
    $settingsFile = __DIR__ . '/../includes/settings.json';
    $settings = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }
    $settings['record_snapshots_enabled'] = $enabled;
    $written = @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write includes/settings.json. Check directory permissions.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'enabled' => $enabled]);
    exit;
}

// Create a timestamped copy of selected tables (structure + data, no indexes/constraints)
if ($action === 'backup_tables') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $tables = $input['tables'] ?? [];
    if (empty($tables) || !is_array($tables)) {
        echo json_encode(['status' => 'error', 'error' => 'No tables provided.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();
        $prefix = date('YmdHi');
        $results = [];
        foreach ($tables as $t) {
            $tableName  = $t['name']   ?? '';
            $schemaName = $t['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $res = @pg_query($conn, $sql);
            if ($res) {
                $rows = pg_affected_rows($res);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
            } else {
                error_log('[admin_api][backup_tables] ' . pg_last_error($conn));
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Database error. Check server logs.'];
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Shared helpers for menu_config GET and POST
$menuMaxBytes = CONFIG_FILE_MAX_BYTES;
$menuSafeReadJson = static function (string $path) use ($menuMaxBytes) : ?array {
    if (!file_exists($path) || filesize($path) > $menuMaxBytes) {
        return null;
    }
    $content = file_get_contents($path, false, null, 0, $menuMaxBytes);
    if ($content === false) {
        return null;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
};
$menuSanitizeIcon = static function (string $icon) : string {
    if ($icon === '') {
        return '';
    }
    if (preg_match('#^(https://[^\s<>"\']+|assets/[^\s<>"\']*)$#i', $icon)) {
        return $icon;
    }
    return '';
};

// GET: return structured (possibly nested) menu item list for the admin Menu Preview tab
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $inc = __DIR__ . '/../includes';

    // Build catalog: key → full display entry
    $catalog = [];

    $dashRaw = $menuSafeReadJson($inc . '/dashboard.json') ?? [];
    $catalog['dashboard'] = [
        'type' => 'dashboard', 'key' => 'dashboard',
        'name'   => $dashRaw['menu_name'] ?? 'Dashboard',
        'icon'   => $menuSanitizeIcon((string)($dashRaw['menu_icon'] ?? 'assets/icons/dashboard.png')),
        'hidden' => !empty($dashRaw['hidden']),
        'children' => [],
    ];

    $calRaw = $menuSafeReadJson($inc . '/calendar.json') ?? [];
    $catalog['calendar'] = [
        'type' => 'calendar', 'key' => 'calendar',
        'name'   => $calRaw['menu_name'] ?? 'Calendar',
        'icon'   => $menuSanitizeIcon((string)($calRaw['menu_icon'] ?? 'assets/icons/calendar.png')),
        'hidden' => !empty($calRaw['hidden']),
        'children' => [],
    ];

    $filesRaw = $menuSafeReadJson($inc . '/files.json') ?? [];
    $catalog['files'] = [
        'type' => 'files', 'key' => 'files',
        'name'   => $filesRaw['menu_name'] ?? 'Files',
        'icon'   => $menuSanitizeIcon((string)($filesRaw['menu_icon'] ?? 'assets/icons/folder_open.png')),
        'hidden' => !empty($filesRaw['hidden']),
        'children' => [],
    ];

    $schemaRaw = $menuSafeReadJson($inc . '/schema.json') ?? [];
    foreach ($schemaRaw['tables'] ?? [] as $tName => $tConfig) {
        $catalog[$tName] = [
            'type' => 'table', 'key' => $tName,
            'name'   => $tConfig['display_name'] ?? $tName,
            'icon'   => $menuSanitizeIcon((string)($tConfig['icon'] ?? '')),
            'hidden' => !empty($tConfig['hidden']),
            'children' => [],
        ];
    }

    $menuRaw = $menuSafeReadJson($inc . '/menu.json');
    $items   = [];
    $placed  = [];

    if ($menuRaw !== null && isset($menuRaw['items']) && is_array($menuRaw['items'])) {
        foreach ($menuRaw['items'] as $entry) {
            $key = $entry['key'] ?? '';
            if ($key === '' || !isset($catalog[$key])) {
                continue;
            }
            $item = $catalog[$key];
            $item['children'] = [];
            foreach ($entry['children'] ?? [] as $ce) {
                $ck = $ce['key'] ?? '';
                if ($ck === '' || !isset($catalog[$ck])) {
                    continue;
                }
                $child = $catalog[$ck];
                $child['children'] = [];
                $item['children'][] = $child;
                $placed[$ck] = true;
            }
            $items[]      = $item;
            $placed[$key] = true;
        }
        // Append items added after menu.json was last saved
        foreach ($catalog as $key => $entry) {
            if (!isset($placed[$key])) {
                $items[] = $entry;
            }
        }
    } else {
        $items = array_values($catalog);
    }

    echo json_encode(['items' => $items]);
    exit;
}

// POST: save menu structure (order + nesting) to includes/menu.json
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Invalid payload']);
        exit;
    }

    $inc       = __DIR__ . '/../includes';
    $schemaRaw = $menuSafeReadJson($inc . '/schema.json') ?? [];
    $validKeys  = array_merge(['dashboard', 'calendar', 'files'], array_keys($schemaRaw['tables'] ?? []));
    $validTypes = ['dashboard', 'calendar', 'files', 'table'];

    $sanitized = [];
    foreach ($body['items'] as $entry) {
        $key  = $entry['key']  ?? '';
        $type = $entry['type'] ?? '';
        if (!in_array($key, $validKeys, true) || !in_array($type, $validTypes, true)) {
            continue;
        }
        $children = [];
        foreach ($entry['children'] ?? [] as $child) {
            $ck = $child['key']  ?? '';
            $ct = $child['type'] ?? '';
            if (!in_array($ck, $validKeys, true) || !in_array($ct, $validTypes, true)) {
                continue;
            }
            $children[] = ['type' => $ct, 'key' => $ck, 'children' => []];
        }
        $sanitized[] = ['type' => $type, 'key' => $key, 'children' => $children];
    }

    $payload  = json_encode(['items' => $sanitized], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $filePath = $inc . '/menu.json';
    $tmp      = $filePath . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Write failed']);
        exit;
    }
    @rename($tmp, $filePath);
    echo json_encode(['status' => 'success']);
    exit;
}

// Allowed config files for read and write operations
// Dodałem 'files' do autoryzowanych konfiguracji
$allowedFiles = ['schema', 'dashboard', 'calendar', 'database', 'security', 'workflows', 'files'];

// Get content of a JSON config file
if ($action === 'get' && in_array($file, $allowedFiles, true)) {
    $filePath = __DIR__ . '/../includes/' . $file . '.json';
    header('Content-Type: application/json');
    if (file_exists($filePath)) {
        $fileContent = file_get_contents($filePath);
        // Mask sensitive data in Demo Mode
        if ($isDemoMode && $file === 'database') {
            $dbData = json_decode($fileContent, true);
            $dbData['host'] = 'hidden-for-demo.postgres.database.azure.com';
            $dbData['user'] = 'demo_user_hidden';
            $dbData['password'] = '********';
            $dbData['dbname'] = 'demo_db';
            echo json_encode($dbData);
        } else {
            echo $fileContent;
        }
    } else {
        echo json_encode(new stdClass());
    }
    exit;
}

// Save content to a JSON config file
if ($action === 'save' && in_array($file, $allowedFiles, true)) {
    // Block saving sensitive files in Demo Mode
    if ($isDemoMode && in_array($file, ['database', 'security'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Saving ' . $file . ' configuration is disabled in Demo Mode.']);
        exit;
    }

    $data = file_get_contents('php://input');
    $filePath = __DIR__ . '/../includes/' . $file . '.json';
    header('Content-Type: application/json');
    $parsedData = json_decode($data, true);
    if ($parsedData !== null) {
        if (!is_dir(__DIR__ . '/../includes/')) {
            mkdir(__DIR__ . '/../includes/', 0777, true);
        }
        file_put_contents($filePath, json_encode($parsedData, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON']);
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
        require_once __DIR__ . '/../includes/db.php';
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
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
        require_once __DIR__ . '/../includes/db.php';
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}