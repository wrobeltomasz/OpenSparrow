<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/exception_handler.php';
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\ForbiddenException;
use App\Exception\ResponseException;

os_register_exception_handler('json');

$expectedToken = get_env('CRON_SECRET', '');
$providedToken = (string) os_request()->post('token', os_request()->query('token'));

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    throw new ForbiddenException('Forbidden');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/cron_notifications.php';

@set_time_limit(0);
@ini_set('display_errors', '0');

ob_start();
$exitCode = cron_notifications_run(['cron']);
$output = (string) ob_get_clean();

throw ResponseException::raw($output, 'text/plain; charset=utf-8', $exitCode === 0 ? 200 : 500);
