<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Controller\FrontApiController;
use App\Http\PhpSession;

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/frontapi/context.php';

os_api_bootstrap(['connect' => false]);

$request    = os_request();
$controller = new FrontApiController(new PhpSession(), $request);

$controller->handle($request);
