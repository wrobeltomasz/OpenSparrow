<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/workflows.php — frontend API route module: GET ?api=workflows.
// Dispatched by public/api.php AFTER the auth gate, the admin/viewer role gates and
// the schema load. Takes its state as a FrontApiContext rather than reading ambient
// variables — see includes/frontapi/context.php.

/**
 * The workflow list, filtered by both halves of the workflow rule.
 */
function frontapi_workflows(FrontApiContext $ctx): never
{
    $workflows = config_get('workflows');
    if ($workflows === null) {
        echo json_encode(['menu_name' => 'Workflows', 'workflows' => []]);
        exit;
    }

    // Two filters, and both are needed. The first is the workflow's own scope: an
    // admin grants workflows by id like views and printouts. The second keeps the
    // table rule honest — every step writes rows into its target table and that
    // write is gated by the mutating branch, so a workflow granted to someone
    // whose tables do not cover its steps would open a wizard that 403s partway
    // through and name the step tables on the way. Granting a workflow does NOT
    // grant its tables; the two are ticked separately and both have to hold.
    if (is_array($workflows['workflows'] ?? null)) {
        $workflows['workflows'] = filter_by_user_access('workflows', $workflows['workflows']);
        $workflows['workflows'] = array_values(array_filter(
            $workflows['workflows'],
            'workflow_tables_in_scope'
        ));
    }

    echo json_encode($workflows);
    exit;
}
