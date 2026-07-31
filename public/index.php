<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// index.php — Front controller / main table data-grid page
// Boots via includes/bootstrap.php: os_page_bootstrap('unsafe-style' CSP, setup check) — auth gate, admin redirect, UA/lifetime enforcement, CSRF token, CSP nonce + headers
// ?api routes the request straight to api.php; otherwise includes templates/template.php (the data grid UI)

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap(['csp' => 'unsafe-style', 'setup_check' => true]);
$cspNonce = $page['nonce'];
$userRole = $page['role'];
// Route API requests directly to api.php
if (isset($_GET['api'])) {
    require __DIR__ . '/api.php';
    exit;
}

// Load the UI template (schema is no longer injected here)
include __DIR__ . '/../templates/template.php';
