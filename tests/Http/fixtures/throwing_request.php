<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/exception_handler.php';

use App\Exception\BadRequestException;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\RedirectException;
use App\Exception\ResponseException;

ini_set('error_log', (string) getenv('OS_TEST_ERROR_LOG'));

$statusFile = (string) getenv('OS_TEST_STATUS_FILE');

register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, var_export(http_response_code(), true));
});

os_register_exception_handler((string) getenv('OS_TEST_MODE'));

if (getenv('OS_TEST_SWITCH_MODE') !== false) {
    os_register_exception_handler((string) getenv('OS_TEST_SWITCH_MODE'));
}

throw match ((string) getenv('OS_TEST_EXCEPTION')) {
    'forbidden' => new ForbiddenException('Read-only access'),
    'bad_request' => new BadRequestException('Missing table parameter'),
    'not_found' => new NotFoundException('Record not found'),
    'redirect' => new RedirectException('login.php'),
    'response' => ResponseException::json(['ok' => true], 201),
    'unhandled' => new RuntimeException('database connection lost'),
    default => new LogicException('Unknown OS_TEST_EXCEPTION'),
};
