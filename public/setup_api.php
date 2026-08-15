<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

ini_set('display_errors', '0');

header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../config/database.json')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'System is already configured. Access denied.'
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

function pg_connstr_escape(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

function is_private_ip(string $host): bool
{
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip === false) {
        return false;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function read_json_body(int $maxBytes = 8192): ?array
{
    $raw = fread(fopen('php://input', 'r'), $maxBytes + 1);
    if (strlen($raw) > $maxBytes) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

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

    if ($port < 1 || $port > 65535) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid port number'
        ]);
        exit;
    }

    if (is_private_ip($host)) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
        exit;
    }

    $connStr = "host=" . pg_connstr_escape($host) .
               " port=" . (int)$port .
               " dbname=" . pg_connstr_escape($dbname) .
               " user=" . pg_connstr_escape($user) .
               " password=" . pg_connstr_escape($password) .
               " connect_timeout=5";

    $conn = @pg_connect($connStr);

    if (!$conn) {
        $safeError = 'Connection failed. Check host, port, database name, username, or password.';
        echo json_encode([
            'success' => false,
            'message' => $safeError
        ]);
        exit;
    }

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
    $dropSchema = (bool)($data['drop_schema'] ?? false);
    $installDemo = (bool)($data['install_demo'] ?? false);

    if (!$host || !$dbname || !$user || !$schema) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit;
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid schema name. Use alphanumeric characters and underscores only.'
        ]);
        exit;
    }

    if ($dropSchema && strtolower($schema) === 'public') {
        echo json_encode([
            'success' => false,
            'message' => 'Refusing to drop the "public" schema. Choose a dedicated schema name instead.'
        ]);
        exit;
    }

    if (is_private_ip($host)) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
        exit;
    }

    try {
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

        function table_ident($schema, $table)
        {
            return '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $table) . '"';
        }

        $schemaIdent = '"' . str_replace('"', '""', $schema) . '"';
        $tUsers = table_ident($schema, 'spw_users');
        $tMigrations = table_ident($schema, 'spw_migrations');

        if ($dropSchema) {
            $dropResult = @pg_query($conn, "DROP SCHEMA IF EXISTS $schemaIdent CASCADE");
            if (!$dropResult) {
                throw new Exception('Failed to drop existing schema "' . $schema . '": ' . pg_last_error($conn));
            }
        }

        require_once __DIR__ . '/../includes/system_tables.php';
        $queries = array_merge(
            [
                "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
                "CREATE TABLE IF NOT EXISTS $tMigrations ( id serial4 NOT NULL, name varchar(100) NOT NULL, applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
            ],
            system_tables_ddl(static fn(string $n): string => table_ident($schema, 'spw_' . $n)),
            system_tables_comments_ddl(static fn(string $n): string => table_ident($schema, 'spw_' . $n)),
            system_tables_user_contact_ddl(static fn(string $n): string => table_ident($schema, 'spw_' . $n)),
            system_tables_clickstats_ddl(static fn(string $n): string => table_ident($schema, 'spw_' . $n)),
            [

                "ALTER TABLE " . table_ident($schema, 'spw_notes') . " ALTER COLUMN reminder_date TYPE timestamp",

                "INSERT INTO $tMigrations (name) VALUES ('3.0_baseline') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $tMigrations (name) VALUES ('3.1_table_comments') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $tMigrations (name) VALUES ('3.1_notes_reminder_time') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $tMigrations (name) VALUES ('3.3_user_contact') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $tMigrations (name) VALUES ('3.3_clickstats') ON CONFLICT (name) DO NOTHING",
            ]
        );

        foreach ($queries as $q) {
            $res = @pg_query($conn, $q);
            if (!$res) {
                error_log('setup init_db error: ' . pg_last_error($conn));
                throw new Exception('Database initialization failed. Check that the user has CREATE privileges on the schema.');
            }
        }

        $tmpPassword    = bin2hex(random_bytes(12));
        $firstAdminSalt = bin2hex(random_bytes(32));

        $argonOpts      = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $firstAdminHash = password_hash($firstAdminSalt . $tmpPassword, PASSWORD_ARGON2ID, $argonOpts);
        error_log('[OpenSparrow] First-run admin account created. Change the password shown in the setup wizard immediately after login.');
        $resAdmin = @pg_query_params(
            $conn,
            "INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role) SELECT 'admin', \$1, \$2, \$3, \$4, true, 'admin' WHERE NOT EXISTS (SELECT 1 FROM $tUsers LIMIT 1) RETURNING id",
            [$firstAdminHash, $firstAdminSalt, 'argon2id', json_encode($argonOpts)]
        );

        if (!$resAdmin) {
            error_log('setup seed admin error: ' . pg_last_error($conn));
            throw new Exception('Failed to create admin account. Check database permissions.');
        }

        $adminId = pg_num_rows($resAdmin) > 0 ? (int) pg_fetch_result($resAdmin, 0, 'id') : null;

        pg_close($conn);

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

        if (!file_exists($configPath)) {
            throw new Exception('Configuration file was not created.');
        }

        $demoInstalled = false;
        $demoError = null;
        if ($installDemo && $adminId === null) {
            $demoError = 'Demo data was skipped because no admin account was created.';
        }
        if ($installDemo && $adminId !== null) {
            try {
                require_once __DIR__ . '/../includes/session.php';
                require_once __DIR__ . '/../includes/admin_api_errors.php';
                require_once __DIR__ . '/../includes/api_helpers.php';
                require_once __DIR__ . '/../includes/config_store.php';

                start_session();
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = $adminId;
                $_SESSION['username'] = 'admin';
                $_SESSION['role'] = 'admin';
                $_SESSION['avatar_id'] = null;
                $_SESSION['created_at'] = time();
                $_SESSION['user_agent'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                require_once __DIR__ . '/admin/demo/seed.php';
                $demoResult = demo_install_run('crm');
                $demoInstalled = ($demoResult['status'] ?? '') === 'success';
                if (!$demoInstalled) {
                    $demoError = $demoResult['error'] ?? 'Demo installation failed.';
                }
            } catch (Throwable $e) {
                error_log('setup demo install error: ' . $e->getMessage());
                $demoError = 'Demo installation failed. Check server logs for details.';
            }
        }

        echo json_encode([
            'success'         => true,
            'message'         => $adminId !== null
                ? 'System initialized successfully.'
                : 'System initialized, but the database already contained user accounts —'
                    . ' no admin account was created and no password was set.'
                    . ' Sign in with an existing account.',
            'admin_user'      => $adminId !== null ? 'admin' : null,
            'admin_password'  => $adminId !== null ? $tmpPassword : null,
            'demo_installed'  => $demoInstalled,
            'demo_error'      => $demoError,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Invalid action'
]);
exit;
