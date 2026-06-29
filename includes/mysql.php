<?php

declare(strict_types=1);

// mysql.php — MySQL Gateway access helpers (single source of truth)
// Keeps MySQL strictly isolated from the PostgreSQL layer in db.php
// mysql_pdo($logTag) lazily opens a guarded, timeout-bounded PDO connection or returns null
// mysql_bt($name) backtick-quotes a MySQL identifier
// MYSQL_* constants come from config.php

require_once __DIR__ . '/config.php';

// Lazy MySQL PDO connection; returns null when the gateway is not configured
// $logTag identifies the calling endpoint in error_log entries
function mysql_pdo(string $logTag = 'api'): ?\PDO
{
    if (MYSQL_HOST === '' || MYSQL_DB === '' || MYSQL_USER === '') {
        return null;
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
            MYSQL_HOST,
            MYSQL_PORT,
            MYSQL_DB,
            MYSQL_CONNECT_TIMEOUT
        );
        return new \PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => MYSQL_CONNECT_TIMEOUT,
        ]);
    } catch (\PDOException $e) {
        error_log('[' . $logTag . '][mysql] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
        return null;
    }
}

// Backtick-quote a MySQL identifier (strips embedded backticks)
function mysql_bt(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}
