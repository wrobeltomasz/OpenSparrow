<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Controller\Api\OwnersController;

os_api_bootstrap(['csrf' => 'manual']);

$controller = new OwnersController(os_boot_app(), os_api_action());

$controller->handle();
