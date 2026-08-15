<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\RedirectException;
use App\Exception\ResponseException;

$page     = os_page_bootstrap(['csp' => 'unsafe-style', 'setup_check' => true]);
$cspNonce = $page['nonce'];
$userRole = $page['role'];

if (isset($_GET['api'])) {
    require __DIR__ . '/api.php';
    throw ResponseException::sent();
}

$requestedTable = is_string($_GET['table'] ?? '') ? (string) $_GET['table'] : '';
if ($requestedTable !== '') {
    os_require_table_access(os_validated_table_name($requestedTable));
}

$requestedWorkflow = substr($_GET['workflow'] ?? '', 0, 64);
if ($requestedWorkflow !== '') {
    os_require_access('workflows', $requestedWorkflow);

    require_once __DIR__ . '/../includes/config_store.php';
    foreach ((config_get('workflows') ?? [])['workflows'] ?? [] as $wfItem) {
        if (is_array($wfItem) && ($wfItem['id'] ?? '') === $requestedWorkflow) {
            if (!workflow_tables_in_scope($wfItem)) {
                throw new RedirectException('index.php');
            }
            break;
        }
    }
}

include __DIR__ . '/../templates/template.php';
