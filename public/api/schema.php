<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Controller\Api\SchemaController;

os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

require_once __DIR__ . '/../../includes/config_store.php';
require_once __DIR__ . '/../../includes/images.php';

$controller = new SchemaController(os_boot_app());

$controller->handle();
