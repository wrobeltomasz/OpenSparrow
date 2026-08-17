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
$queryParameters = os_request()->queryAll();
if (isset($queryParameters['api'])) {
    require __DIR__ . '/api.php';
    throw ResponseException::sent();
}

$requestedTable = os_query_string('table');
if ($requestedTable !== '') {
    os_require_table_access(os_validated_table_name($requestedTable));
}

$requestedWorkflow = substr(os_query_string('workflow'), 0, 64);
if ($requestedWorkflow !== '') {
    os_require_access('workflows', $requestedWorkflow);

    require_once __DIR__ . '/../includes/config_store.php';
    foreach ((config_get('workflows') ?? [])['workflows'] ?? [] as $workflowItem) {
        if (is_array($workflowItem) && ($workflowItem['id'] ?? '') === $requestedWorkflow) {
            if (!workflow_tables_in_scope($workflowItem)) {
                throw new RedirectException('index.php');
            }
            break;
        }
    }
}

include __DIR__ . '/../templates/template.php';
