<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\RedirectException;

os_register_exception_handler('html');
start_session();

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/db.php';
    require __DIR__ . '/../includes/api_helpers.php';
    $conn = db_connect();
    log_user_action($conn, $_SESSION['user_id'], 'LOGOUT');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parameters['path'],
        $parameters['domain'],
        $parameters['secure'],
        $parameters['httponly']
    );
}

session_unset();
session_destroy();
throw new RedirectException('login.php');
