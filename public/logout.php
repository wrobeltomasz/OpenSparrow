<?php

// logout.php — Logout handler
// Writes a LOGOUT audit entry (log_user_action) if a session is active, then clears $_SESSION, expires the session cookie, destroys the session, and redirects to login.php
// No CSRF/role checks — purely tears down the current session

require_once __DIR__ . '/../includes/session.php';
start_session();

if (isset($_SESSION['user_id'])) {
    require __DIR__ . '/../includes/db.php';
    require __DIR__ . '/../includes/api_helpers.php';
    $conn = db_connect();
    log_user_action($conn, $_SESSION['user_id'], 'LOGOUT');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
