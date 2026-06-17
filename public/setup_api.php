<?php

// setup_api.php — First-run setup wizard API endpoint
// Refuses to run (403) if config/database.json already exists — single-use by design
// actions: test_connection (validate PG credentials) and init_database (create schema/tables + admin user, then write config/database.json)
// SSRF guard: rejects private/loopback IPs (is_private_ip); libpq connstr values escaped (pg_connstr_escape); request body size-limited

// Prevent PHP warnings/notices from polluting JSON output
ini_set('display_errors', '0');

header('Content-Type: application/json');

// Check if already configured - reject setup if database.json exists
if (file_exists(__DIR__ . '/../config/database.json')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'System is already configured. Access denied.'
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

// Escape a value for use in a libpq keyword=value connection string.
// Single-quote the value and escape backslashes and single quotes inside it.
function pg_connstr_escape(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

// Reject private/loopback IP ranges to prevent SSRF via test_connection.
// Only applies when the host parses as a numeric IP; hostnames are not blocked
// (the PG server may legitimately live on an internal DNS name).
function is_private_ip(string $host): bool
{
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip === false) {
        return false; // hostname — allow
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Read and size-limit the JSON request body.
function read_json_body(int $maxBytes = 8192): ?array
{
    $raw = fread(fopen('php://input', 'r'), $maxBytes + 1);
    if (strlen($raw) > $maxBytes) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// Action: test_connection
// Tests PostgreSQL connectivity with provided credentials
if ($action === 'test_connection') {
    $data = read_json_body();
    if ($data === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid or oversized request body']);
        exit;
    }

    $host = $data['host'] ?? '';
    $port = (int)($data['port'] ?? 5432);
    $dbname = $data['dbname'] ?? '';
    $user = $data['user'] ?? '';
    $password = $data['password'] ?? '';

    if (!$host || !$dbname || !$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit;
    }

    // Validate port
    if ($port < 1 || $port > 65535) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid port number'
        ]);
        exit;
    }

    // Reject private/reserved IPs to prevent SSRF probing
    if (is_private_ip($host)) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
        exit;
    }

    // Build connection string
    $connStr = "host=" . pg_connstr_escape($host) .
               " port=" . (int)$port .
               " dbname=" . pg_connstr_escape($dbname) .
               " user=" . pg_connstr_escape($user) .
               " password=" . pg_connstr_escape($password) .
               " connect_timeout=5";

    // Attempt connection
    $conn = @pg_connect($connStr);

    if (!$conn) {
        $error = pg_last_error() ?: 'Unknown connection error';
        // Sanitize error message (don't expose internal PG details)
        $safeError = 'Connection failed. Check host, port, database name, username, or password.';
        echo json_encode([
            'success' => false,
            'message' => $safeError
        ]);
        exit;
    }

    // Connection successful - get schema list
    $schemas = [];
    $res = @pg_query($conn, "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('pg_catalog', 'information_schema') ORDER BY schema_name");
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $schemas[] = $row['schema_name'];
        }
    }

    pg_close($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Connection successful',
        'schemas' => $schemas
    ]);
    exit;
}

// Action: init_database
// Initializes database schema, tables, and default admin account
if ($action === 'init_database') {
    $data = read_json_body();
    if ($data === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid or oversized request body']);
        exit;
    }

    $host = $data['host'] ?? '';
    $port = (int)($data['port'] ?? 5432);
    $dbname = $data['dbname'] ?? '';
    $user = $data['user'] ?? '';
    $password = $data['password'] ?? '';
    $schema = $data['schema'] ?? 'app';
    $createSchema = (bool)($data['create_schema'] ?? true);

    if (!$host || !$dbname || !$user || !$schema) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit;
    }

    // Validate schema name (alphanumeric + underscore)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid schema name. Use alphanumeric characters and underscores only.'
        ]);
        exit;
    }

    // Reject private/reserved IPs (same SSRF guard as test_connection)
    if (is_private_ip($host)) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
        exit;
    }

    try {
        // Connect to database
        $connStr = "host=" . pg_connstr_escape($host) .
                   " port=" . (int)$port .
                   " dbname=" . pg_connstr_escape($dbname) .
                   " user=" . pg_connstr_escape($user) .
                   " password=" . pg_connstr_escape($password) .
                   " connect_timeout=5";

        $conn = @pg_connect($connStr);

        if (!$conn) {
            throw new Exception('Could not connect to database. Verify credentials and try again.');
        }

        // Helper function to build table identifier
        function table_ident($schema, $table)
        {
            return '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $table) . '"';
        }

        $schemaIdent = '"' . str_replace('"', '""', $schema) . '"';
        $tUsers = table_ident($schema, 'spw_users');
        $tUsersLog = table_ident($schema, 'spw_users_log');
        $tUsersNotifications = table_ident($schema, 'spw_users_notifications');
        $tCronLog = table_ident($schema, 'spw_users_notifications_log');
        $tFiles = table_ident($schema, 'spw_files');
        $tLoginAttempts = table_ident($schema, 'spw_login_attempts');
        $tComments = table_ident($schema, 'spw_comments');
        $tRecordSnapshots = table_ident($schema, 'spw_record_snapshots');

        // Initialize queries (from admin/api.php init_db action)
        $queries = [
            "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
            "CREATE TABLE IF NOT EXISTS $tUsers ( id serial4 NOT NULL, username varchar(50) NOT NULL, password_hash varchar(255) NOT NULL, salt varchar(64), password_algo varchar(32) DEFAULT 'argon2id' NOT NULL, password_params jsonb DEFAULT '{}'::jsonb, is_active bool DEFAULT true, role varchar(20) DEFAULT 'editor' NOT NULL, avatar_id smallint, CONSTRAINT spw_users_pkey PRIMARY KEY (id), CONSTRAINT spw_users_username_key UNIQUE (username) )",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS is_active bool DEFAULT true",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS \"role\" varchar(20) DEFAULT 'editor' NOT NULL",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS salt varchar(64)",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_algo varchar(32) DEFAULT 'argon2id' NOT NULL",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_params jsonb DEFAULT '{}'::jsonb",
            "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS avatar_id smallint",
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
            "CREATE TABLE IF NOT EXISTS $tComments ( id serial4 NOT NULL, related_table varchar(100) NOT NULL, related_id int4 NOT NULL, user_id int4 NOT NULL, body text NOT NULL, created_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, CONSTRAINT spw_comments_pkey PRIMARY KEY (id), CONSTRAINT spw_comments_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
            "CREATE INDEX IF NOT EXISTS idx_spw_comments_related ON $tComments USING btree (related_table, related_id, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_spw_comments_user_id ON $tComments USING btree (user_id)",
            "CREATE TABLE IF NOT EXISTS $tRecordSnapshots ( id serial4 NOT NULL, log_id int4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, snapshot jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_record_snapshots_pkey PRIMARY KEY (id), CONSTRAINT spw_record_snapshots_log_id_fkey FOREIGN KEY (log_id) REFERENCES $tUsersLog(id) ON DELETE CASCADE )",
            "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_log_id ON $tRecordSnapshots USING btree (log_id)",
            "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_table_record ON $tRecordSnapshots USING btree (table_name, record_id)"
        ];

        // Execute DDL queries
        foreach ($queries as $q) {
            $res = @pg_query($conn, $q);
            if (!$res) {
                error_log('setup init_db error: ' . pg_last_error($conn));
                throw new Exception('Database initialization failed. Check that the user has CREATE privileges on the schema.');
            }
        }

        // Create default admin account (only if no users exist).
        // Generates a random temporary password returned to the setup wizard for display.
        // Do not log the password — it is already visible in the wizard response.
        $tmpPassword    = bin2hex(random_bytes(12));
        $firstAdminSalt = bin2hex(random_bytes(32));
        $argonOpts      = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $firstAdminHash = password_hash($firstAdminSalt . $tmpPassword, PASSWORD_ARGON2ID, $argonOpts);
        error_log('[OpenSparrow] First-run admin account created. Change the password shown in the setup wizard immediately after login.');
        $resAdmin = @pg_query_params(
            $conn,
            "INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role) SELECT 'admin', \$1, \$2, \$3, \$4, true, 'admin' WHERE NOT EXISTS (SELECT 1 FROM $tUsers LIMIT 1)",
            [$firstAdminHash, $firstAdminSalt, 'argon2id', json_encode($argonOpts)]
        );

        if (!$resAdmin) {
            error_log('setup seed admin error: ' . pg_last_error($conn));
            throw new Exception('Failed to create admin account. Check database permissions.');
        }

        pg_close($conn);

        // Write database.json configuration file
        $configData = [
            'host' => $host,
            'port' => $port,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $password,
            'schema' => $schema
        ];

        $configJson = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $configDir  = __DIR__ . '/../config';
        $configPath = $configDir . '/database.json';

        if (!is_dir($configDir) && !@mkdir($configDir, 0755, true)) {
            throw new Exception('Failed to create config directory.');
        }

        if (!@file_put_contents($configPath, $configJson)) {
            throw new Exception('Failed to write database.json configuration file.');
        }

        // Verify database.json was written correctly
        if (!file_exists($configPath)) {
            throw new Exception('Configuration file was not created.');
        }

        echo json_encode([
            'success'        => true,
            'message'        => 'System initialized successfully.',
            'admin_user'     => 'admin',
            'admin_password' => $tmpPassword,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

// Invalid action
http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Invalid action'
]);
exit;
