<?php

declare(strict_types=1);

// api_bootstrap.php — Standard bootstrap for JSON API endpoints
// Disables HTML error output, loads session/db/helper modules, then api_bootstrap()
// sends security headers, starts the enforced session, and returns a live PG connection
// Use only for endpoints that connect first and authenticate via enforce_session_json()

ini_set('display_errors', '0');

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_helpers.php';

// Run the standard endpoint setup and return an open PostgreSQL connection
function api_bootstrap(): \PgSql\Connection
{
    header('Content-Type: application/json; charset=utf-8');
    send_security_headers();
    start_session();
    // Hard session-lifetime + User-Agent enforcement (centralised in session.php)
    enforce_session_json();
    return db_connect();
}
