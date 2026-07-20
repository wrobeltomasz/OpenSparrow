<?php
// admin/index.php — Admin panel shell (HTML + JS module loader, role: admin only)
// First-run: redirects to ../setup.php if database.json is missing; allows access before spw_users exists so the operator can run "Initialize System Tables", otherwise requires login + admin role
// Renders the admin SPA; tabs/logic live in admin/js/* (loaded by app.js)

require_once __DIR__ . '/../../includes/session.php';

// First-run check: if database.json doesn't exist, redirect to setup wizard
if (!file_exists(__DIR__ . '/../../config/database.json')) {
    header('Location: ../setup.php');
    exit;
}

start_session();

// First-run bypass: if spw_users table doesn't exist yet the panel must be
// reachable so the operator can run "Initialize System Tables". Once the table
// exists and contains at least one admin account, normal auth applies.
$firstRun = false;
require_once __DIR__ . '/../../includes/db.php';
$_conn = @db_connect();
if (!$_conn) {
    $firstRun = true;
} else {
    $tUsers = sys_table('users');
    $chk = @pg_query($_conn, "SELECT 1 FROM $tUsers LIMIT 1");
    if ($chk === false) {
        $firstRun = true;
    }
}
unset($_conn, $chk);

// Redirect to login if not authenticated (skipped on first run)
if (!$firstRun && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Only admin role may access this panel (skipped on first run)
if (!$firstRun && ($_SESSION['role'] ?? '') !== 'admin') {
    $currentRole = $_SESSION['role'] ?? 'none';
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="../assets/css/styles.css">
        <link rel="stylesheet" href="../assets/css/buttons.css">
    </head>
    <body class="admin-403-page">
        <div class="admin-403-card">
            <h1>Access Denied</h1>
            <p>Your account does not have permission to access the admin panel.</p>
            <p>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'unknown'); ?></strong></p>
            <p>Your role: <strong><?php echo htmlspecialchars($currentRole); ?></strong></p>
            <p>Required role: <strong>admin</strong></p>
            <p><a href="../logout.php">Log out</a> | <a href="../">Return to application</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require __DIR__ . '/templates/header.php';
require __DIR__ . '/templates/nav.php';
require __DIR__ . '/templates/footer.php';
