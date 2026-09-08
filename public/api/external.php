<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/config_store.php';
require_once __DIR__ . '/../../includes/crypto.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/external_api_stats.php';

use App\Controller\Api\ExternalApiController;

ini_set('display_errors', '0');
os_register_exception_handler('json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
send_security_headers();

$controller = new ExternalApiController();

$controller->handle();
