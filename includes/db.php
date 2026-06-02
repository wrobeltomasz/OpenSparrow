<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db_connect(): \PgSql\Connection
{
    $configFile = __DIR__ . '/../config/database.json';

    // Default values fallback
    $host = DB_HOST;
    $port = DB_PORT;
    $dbname = getenv('PGDATABASE') ?: '';
    $user = getenv('PGUSER') ?: '';
    $password = getenv('PGPASSWORD') ?: '';

    // Load config from JSON file if exists
    if (file_exists($configFile)) {
        $json = file_get_contents($configFile);
        $config = json_decode($json, true);
        if (is_array($config)) {
            $host = !empty($config['host']) ? $config['host'] : $host;
            $port = !empty($config['port']) ? $config['port'] : $port;
            $dbname = !empty($config['dbname']) ? $config['dbname'] : $dbname;
            $user = !empty($config['user']) ? $config['user'] : $user;
            $password = $config['password'] ?? $password;
        }
    }

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
    $configFile = __DIR__ . '/../config/database.json';
    if (file_exists($configFile)) {
        $json = @file_get_contents($configFile);
        $config = @json_decode($json, true);
        if (is_array($config) && !empty($config['schema'])) {
            $schema = (string) $config['schema'];
        }
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
