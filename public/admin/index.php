<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/session.php';

if (!file_exists(__DIR__ . '/../../config/database.json')) {
    header('Location: ../setup.php');
    exit;
}

start_session();

$firstRun = false;
require_once __DIR__ . '/../../includes/db.php';
$_conn = @db_connect();
if ($_conn) {
    $tUsers = sys_table('users');

    $sqlState = null;
    if (@pg_send_query($_conn, "SELECT 1 FROM $tUsers LIMIT 1")) {
        $chk = @pg_get_result($_conn);
        if ($chk !== false) {
            $sqlState = pg_result_error_field($chk, PGSQL_DIAG_SQLSTATE);
        }
        while (@pg_get_result($_conn)) {
        }
    }
    $firstRun = ($sqlState === '42P01');
}
unset($_conn, $chk, $sqlState, $tUsers);

if (!$firstRun && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

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
