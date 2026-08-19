<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

function db_conninfo_value(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function db_connect(): \PgSql\Connection
{
    $host = DB_HOST;
    $port = DB_PORT;
    $dbname = DB_NAME;
    $user = DB_USER;
    $password = DB_PASSWORD;

    $config = db_config();
    $host = !empty($config['host']) ? $config['host'] : $host;
    $port = !empty($config['port']) ? $config['port'] : $port;
    $dbname = !empty($config['dbname']) ? $config['dbname'] : $dbname;
    $user = !empty($config['user']) ? $config['user'] : $user;
    $password = $config['password'] ?? $password;
    $sslMode = !empty($config['sslmode']) ? (string) $config['sslmode'] : DB_SSLMODE;
    $sslRootCert = !empty($config['sslrootcert']) ? (string) $config['sslrootcert'] : DB_SSLROOTCERT;

    $parameters = [
        'host'            => (string) $host,
        'port'            => (string) $port,
        'dbname'          => (string) $dbname,
        'user'            => (string) $user,
        'password'        => (string) $password,
        'connect_timeout' => (string) DB_CONNECT_TIMEOUT,
        'sslmode'         => $sslMode,
    ];
    if ($sslRootCert !== '') {
        $parameters['sslrootcert'] = $sslRootCert;
    }

    $connectionParts = [];
    foreach ($parameters as $parameterName => $parameterValue) {
        $connectionParts[] = $parameterName . '=' . db_conninfo_value($parameterValue);
    }
    $connectionString = implode(' ', $connectionParts);

    $conn = @pg_connect($connectionString);

    if (!$conn) {
        throw new RuntimeException('Cannot connect to Postgres. Check database credentials or server status.');
    }

    pg_query($conn, 'SET TIME ZONE ' . pg_escape_literal($conn, APP_TIMEZONE));

    if (DB_STATEMENT_TIMEOUT > 0 && PHP_SAPI !== 'cli') {
        pg_query(
            $conn,
            'SET statement_timeout = ' . pg_escape_literal($conn, (string) DB_STATEMENT_TIMEOUT)
        );
    }

    return $conn;
}

function sys_schema(): string
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }
    $schema = DB_SCHEMA;
    $config = db_config();
    if (!empty($config['schema'])) {
        $schema = (string) $config['schema'];
    }
    return $schema;
}

function sys_table(string $name): string
{
    $schema = sys_schema();
    $table = 'spw_' . $name;
    $quote = static fn(string $identifier): string => '"' . str_replace('"', '""', $identifier) . '"';
    return $quote($schema) . '.' . $quote($table);
}
