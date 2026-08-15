<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/exception_handler.php';

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\ResponseException;

ini_set('display_errors', '0');
os_register_exception_handler('json');

header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../config/database.json')) {
    throw new ForbiddenException('System is already configured. Access denied.', [
        'success' => false,
        'message' => 'System is already configured. Access denied.'
    ]);
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
    $rawBody = fread(fopen('php://input', 'r'), $maxBytes + 1);
    if (strlen($rawBody) > $maxBytes) {
        return null;
    }
    $data = json_decode($rawBody, true);
    return is_array($data) ? $data : null;
}

if ($action === 'test_connection') {
    $data = read_json_body();
    if ($data === null) {
        throw ResponseException::encoded(['success' => false, 'message' => 'Invalid or oversized request body']);
    }

    $host = $data['host'] ?? '';
    $port = (int)($data['port'] ?? 5432);
    $dbname = $data['dbname'] ?? '';
    $user = $data['user'] ?? '';
    $password = $data['password'] ?? '';

    if (!$host || !$dbname || !$user) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
    }

    if ($port < 1 || $port > 65535) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Invalid port number'
        ]);
    }

    if (is_private_ip($host)) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
    }

    $connectionString = "host=" . pg_connstr_escape($host) .
               " port=" . (int)$port .
               " dbname=" . pg_connstr_escape($dbname) .
               " user=" . pg_connstr_escape($user) .
               " password=" . pg_connstr_escape($password) .
               " connect_timeout=5";

    $conn = @pg_connect($connectionString);

    if (!$conn) {
        $safeError = 'Connection failed. Check host, port, database name, username, or password.';
        throw ResponseException::encoded([
            'success' => false,
            'message' => $safeError
        ]);
    }

    $schemas = [];
    $result = @pg_query(
        $conn,
        "SELECT schema_name FROM information_schema.schemata "
            . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema') ORDER BY schema_name"
    );
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $schemas[] = $row['schema_name'];
        }
    }

    pg_close($conn);

    throw ResponseException::encoded([
        'success' => true,
        'message' => 'Connection successful',
        'schemas' => $schemas
    ]);
}

if ($action === 'init_database') {
    $data = read_json_body();
    if ($data === null) {
        throw ResponseException::encoded(['success' => false, 'message' => 'Invalid or oversized request body']);
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
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Invalid schema name. Use alphanumeric characters and underscores only.'
        ]);
    }

    if ($dropSchema && strtolower($schema) === 'public') {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Refusing to drop the "public" schema. Choose a dedicated schema name instead.'
        ]);
    }

    if (is_private_ip($host)) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => 'Connection failed. Check host, port, database name, username, or password.'
        ]);
    }

    try {
        $connectionString = "host=" . pg_connstr_escape($host) .
                   " port=" . (int)$port .
                   " dbname=" . pg_connstr_escape($dbname) .
                   " user=" . pg_connstr_escape($user) .
                   " password=" . pg_connstr_escape($password) .
                   " connect_timeout=5";

        $conn = @pg_connect($connectionString);

        if (!$conn) {
            throw new Exception('Could not connect to database. Verify credentials and try again.');
        }

        function table_ident($schema, $table)
        {
            return '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $table) . '"';
        }

        $schemaIdentifier = '"' . str_replace('"', '""', $schema) . '"';
        $usersTable = table_ident($schema, 'spw_users');
        $migrationsTable = table_ident($schema, 'spw_migrations');

        if ($dropSchema) {
            $dropResult = @pg_query($conn, "DROP SCHEMA IF EXISTS $schemaIdentifier CASCADE");
            if (!$dropResult) {
                throw new Exception('Failed to drop existing schema "' . $schema . '": ' . pg_last_error($conn));
            }
        }

        require_once __DIR__ . '/../includes/system_tables.php';
        $queries = array_merge(
            [
                "CREATE SCHEMA IF NOT EXISTS $schemaIdentifier",
                "CREATE TABLE IF NOT EXISTS $migrationsTable ( id serial4 NOT NULL, name varchar(100) NOT NULL, "
                    . "applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id), "
                    . "CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
            ],
            system_tables_ddl(static fn(string $name): string => table_ident($schema, 'spw_' . $name)),
            system_tables_comments_ddl(static fn(string $name): string => table_ident($schema, 'spw_' . $name)),
            system_tables_user_contact_ddl(static fn(string $name): string => table_ident($schema, 'spw_' . $name)),
            system_tables_clickstats_ddl(static fn(string $name): string => table_ident($schema, 'spw_' . $name)),
            [

                "ALTER TABLE " . table_ident($schema, 'spw_notes') . " ALTER COLUMN reminder_date TYPE timestamp",

                "INSERT INTO $migrationsTable (name) VALUES ('3.0_baseline') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $migrationsTable (name) VALUES ('3.1_table_comments') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $migrationsTable (name) VALUES ('3.1_notes_reminder_time') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $migrationsTable (name) VALUES ('3.3_user_contact') ON CONFLICT (name) DO NOTHING",
                "INSERT INTO $migrationsTable (name) VALUES ('3.3_clickstats') ON CONFLICT (name) DO NOTHING",
            ]
        );

        foreach ($queries as $query) {
            $result = @pg_query($conn, $query);
            if (!$result) {
                error_log('setup init_db error: ' . pg_last_error($conn));
                throw new Exception(
                    'Database initialization failed. Check that the user has CREATE privileges on the schema.'
                );
            }
        }

        $tmpPassword    = bin2hex(random_bytes(12));
        $firstAdminSalt = bin2hex(random_bytes(32));

        $argonOptions      = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $firstAdminHash = password_hash($firstAdminSalt . $tmpPassword, PASSWORD_ARGON2ID, $argonOptions);
        error_log(
            '[OpenSparrow] First-run admin account created. '
                . 'Change the password shown in the setup wizard immediately after login.'
        );
        $resAdmin = @pg_query_params(
            $conn,
            "INSERT INTO $usersTable (username, password_hash, salt, password_algo, password_params, is_active, role) "
                . "SELECT 'admin', \$1, \$2, \$3, \$4, true, 'admin' "
                . "WHERE NOT EXISTS (SELECT 1 FROM $usersTable LIMIT 1) RETURNING id",
            [$firstAdminHash, $firstAdminSalt, 'argon2id', json_encode($argonOptions)]
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
            } catch (ControlFlowException $signal) {
                throw $signal;
            } catch (Throwable $exception) {
                error_log('setup demo install error: ' . $exception->getMessage());
                $demoError = 'Demo installation failed. Check server logs for details.';
            }
        }

        throw ResponseException::encoded([
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
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Exception $exception) {
        throw ResponseException::encoded([
            'success' => false,
            'message' => $exception->getMessage()
        ]);
    }
}

throw new BadRequestException('Invalid action', [
    'success' => false,
    'message' => 'Invalid action'
]);
