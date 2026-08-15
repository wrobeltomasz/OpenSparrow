<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ResponseException;

function frontapi_workflows(FrontApiContext $context): never
{
    $workflows = config_get('workflows');
    if ($workflows === null) {
        throw ResponseException::encoded(['menu_name' => 'Workflows', 'workflows' => []]);
    }

    if (is_array($workflows['workflows'] ?? null)) {
        $workflows['workflows'] = filter_by_user_access('workflows', $workflows['workflows']);
        $workflows['workflows'] = array_values(array_filter(
            $workflows['workflows'],
            'workflow_tables_in_scope'
        ));
    }

    throw ResponseException::encoded($workflows);
}
