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

// A ?table= the user has no access to (stale bookmark, hand-edited URL) drops them
// back on the default grid instead of rendering an empty shell whose every XHR 403s.
$requestedTable = substr($_GET['table'] ?? '', 0, 64);
if ($requestedTable !== '') {
    os_require_table_access($requestedTable);
}

// Same for ?workflow=: the wizard lives on this page rather than one of its own.
$requestedWorkflow = substr($_GET['workflow'] ?? '', 0, 64);
if ($requestedWorkflow !== '') {
    os_require_access('workflows', $requestedWorkflow);
    // And the step-table half of the rule, so the page agrees with the data call that
    // fills it: api=workflows drops a workflow whose steps target tables the user was
    // not granted, and rendering the wizard shell anyway would leave them on a form
    // that has nothing to submit to. An id matching nothing configured falls through —
    // the page answers for it exactly as it did before.
    require_once __DIR__ . '/../includes/config_store.php';
    foreach ((config_get('workflows') ?? [])['workflows'] ?? [] as $wfItem) {
        if (is_array($wfItem) && ($wfItem['id'] ?? '') === $requestedWorkflow) {
            if (!workflow_tables_in_scope($wfItem)) {
                header('Location: index.php');
                exit;
            }
            break;
        }
    }
}

// Load the UI template (schema is no longer injected here)
include __DIR__ . '/../templates/template.php';
