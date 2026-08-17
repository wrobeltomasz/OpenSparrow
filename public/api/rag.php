<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Controller\Api\RagController;

set_time_limit(240);

require_once __DIR__ . '/../../includes/bootstrap.php';

os_api_bootstrap(['connect' => false]);

require_once __DIR__ . '/../../includes/rag_helpers.php';
require_once __DIR__ . '/../../includes/rag_throttle.php';

$controller = new RagController(os_boot_app());

$controller->handle();
