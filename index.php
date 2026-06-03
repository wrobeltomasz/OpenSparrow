<?php

require_once __DIR__ . '/includes/session.php';
start_session();
// First-run setup check: if database.json doesn't exist and user is not authenticated,
// redirect to the setup wizard
$databaseConfigExists = file_exists(__DIR__ . '/config/database.json');
if (!$databaseConfigExists && !isset($_SESSION['user_id'])) {
    header('Location: setup.php');
    exit;
}

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_redirect();

// Admin role belongs in the admin panel, not the frontend
if (($_SESSION['role'] ?? 'viewer') === 'admin') {
    header("Location: admin/");
    exit;
}

$userRole = $_SESSION['role'] ?? 'viewer';
// Ensure CSRF token exists for this session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cspNonce = bin2hex(random_bytes(16));
send_security_headers($cspNonce, true, 'unsafe-style');
// Route API requests directly to api.php
if (isset($_GET['api'])) {
    require __DIR__ . '/api.php';
    exit;
}

// Load the UI template (schema is no longer injected here)
include __DIR__ . '/templates/template.php';
