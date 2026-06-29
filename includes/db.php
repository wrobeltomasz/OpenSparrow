<?php

declare(strict_types=1);

// db.php — PostgreSQL connection manager and system table naming helpers
// db_config() loads and caches config/database.json once per request
// db_connect() reads config/database.json + env vars (PGHOST, PGPORT, etc.) and returns PgSql\Connection
// sys_schema() returns schema name (default "app") from config/database.json or PGSCHEMA env
// sys_table($name) returns fully qualified quoted identifier: schema."spw_$name"
// Throws RuntimeException on connection failure; sets session timezone using pg_escape_literal

require_once __DIR__ . '/config.php';

// Load and cache config/database.json once per request. Returns [] when absent.
function db_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = [];
        $configFile = __DIR__ . '/../config/database.json';
        if (file_exists($configFile)) {
            $decoded = json_decode((string) @file_get_contents($configFile), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
    }
    return $config;
}

function db_connect(): \PgSql\Connection
{
    // Default values fallback
    $host = DB_HOST;
    $port = DB_PORT;
    $dbname = getenv('PGDATABASE') ?: '';
    $user = getenv('PGUSER') ?: '';
    $password = getenv('PGPASSWORD') ?: '';

    // Apply config/database.json overrides on top of the env defaults
    $config = db_config();
    $host = !empty($config['host']) ? $config['host'] : $host;
    $port = !empty($config['port']) ? $config['port'] : $port;
    $dbname = !empty($config['dbname']) ? $config['dbname'] : $dbname;
    $user = !empty($config['user']) ? $config['user'] : $user;
    $password = $config['password'] ?? $password;

    // Build connection string
    $connStr = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=%d",
        $host,
        $port,
        $dbname,
        $user,
        $password,
        DB_CONNECT_TIMEOUT
    );

    // Suppress native warnings and throw a safe generic exception
    $conn = @pg_connect($connStr);

    if (!$conn) {
        throw new RuntimeException('Cannot connect to Postgres. Check database credentials or server status.');
    }

    // Use pg_escape_literal to prevent SQL injection via APP_TIMEZONE env var
    pg_query($conn, 'SET TIME ZONE ' . pg_escape_literal($conn, APP_TIMEZONE));

    return $conn;
}

// Returns the schema name for OpenSparrow system tables.
// Configurable via "schema" key in config/database.json; defaults to "app".
function sys_schema(): string
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }
    $schema = getenv('PGSCHEMA') ?: 'app';
    $config = db_config();
    if (!empty($config['schema'])) {
        $schema = (string) $config['schema'];
    }
    return $schema;
}

// Returns a fully-qualified, safely-quoted system table identifier.
// Usage: sys_table('users') => "app"."spw_users" (with configured schema).
function sys_table(string $name): string
{
    $schema = sys_schema();
    $table = 'spw_' . $name;
    $quote = static fn(string $s): string => '"' . str_replace('"', '""', $s) . '"';
    return $quote($schema) . '.' . $quote($table);
}
