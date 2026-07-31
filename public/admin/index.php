<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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
// Narrow on purpose: only a *working* connection reporting SQLSTATE 42P01
// (undefined_table) counts as first run. Treating any db_connect()/pg_query
// failure as first run would drop the login and role gates on a transient
// connection or permission error.
$firstRun = false;
require_once __DIR__ . '/../../includes/db.php';
$_conn = @db_connect();
if ($_conn) {
    $tUsers = sys_table('users');
    // pg_send_query()/pg_get_result() rather than pg_query(): a failed pg_query()
    // returns false and discards the result object, so its SQLSTATE is unreadable.
    $sqlState = null;
    if (@pg_send_query($_conn, "SELECT 1 FROM $tUsers LIMIT 1")) {
        $chk = @pg_get_result($_conn);
        if ($chk !== false) {
            $sqlState = pg_result_error_field($chk, PGSQL_DIAG_SQLSTATE);
        }
        while (@pg_get_result($_conn)) {
            // drain any remaining results so the connection stays usable
        }
    }
    $firstRun = ($sqlState === '42P01');
}
unset($_conn, $chk, $sqlState, $tUsers);

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
