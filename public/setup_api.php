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
        $tMigrations = table_ident($schema, 'spw_migrations');

        // The spw_* DDL is shared with the admin init_db action so the two entry points
        // cannot drift apart — see includes/system_tables.php. This wizard only adds the
        // bootstrap (schema + migration tracker) around it and records the baseline.
        require_once __DIR__ . '/../includes/system_tables.php';
        $queries = array_merge(
            [
                "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
                "CREATE TABLE IF NOT EXISTS $tMigrations ( id serial4 NOT NULL, name varchar(100) NOT NULL, applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
            ],
            system_tables_ddl(static fn(string $n): string => table_ident($schema, 'spw_' . $n)),
            ["INSERT INTO $tMigrations (name) VALUES ('3.0_baseline') ON CONFLICT (name) DO NOTHING"]
        );

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
        // setup_api.php runs standalone (before config exists), so it cannot use
        // the ARGON2_OPTIONS constant from includes/config.php — keep in sync.
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
