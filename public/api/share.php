<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/config_store.php';
require_once __DIR__ . '/../../includes/autoload.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use App\Security\UserRole;
use App\Service\SharedLinkRepository;
use App\Service\SharedLinkValidator;

ini_set('display_errors', '0');
os_register_exception_handler('json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
send_security_headers();

const SHARE_TOKEN_RATE_LIMIT_PER_MIN = 60;
const SHARE_IP_RATE_LIMIT_PER_MIN = 300;

$ipAddress = client_ip();
if ($ipAddress !== '') {
    $ipBucket = 'share_ip_' . substr(hash('sha256', $ipAddress), 0, 16);
    if (!os_rate_limit_ok($ipBucket, SHARE_IP_RATE_LIMIT_PER_MIN, 'share-ip')) {
        header('Retry-After: 60');
        throw HttpException::fromStatus(429, 'Too many requests. Please slow down.');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    throw HttpException::fromStatus(405, 'Method Not Allowed');
}

$token = os_query_string('t');
if ($token === '' || strlen($token) > 128) {
    throw HttpException::fromStatus(404, 'Not Found');
}

$tokenBucket = 'share_t_' . substr(hash('sha256', $token), 0, 16);
if (!os_rate_limit_ok($tokenBucket, SHARE_TOKEN_RATE_LIMIT_PER_MIN, 'share-token')) {
    header('Retry-After: 60');
    throw HttpException::fromStatus(429, 'Too many requests. Please slow down.');
}

$repository = new SharedLinkRepository();
$matched = $repository->entryByToken($token);
if ($matched === null || empty($matched['entry']['enabled'])) {
    throw HttpException::fromStatus(404, 'Not Found');
}

$sharedTable = (string) $matched['table'];
$schema = config_get('schema');
if (!is_array($schema) || !isset($schema['tables'][$sharedTable])) {
    throw new NotFoundException('Not Found');
}
if (!SharedLinkValidator::isShareable($schema, $sharedTable)) {
    throw new NotFoundException('Not Found');
}

require_once __DIR__ . '/../../includes/frontapi/context.php';
require_once __DIR__ . '/../../includes/frontapi/list.php';

$shareContext = new FrontApiContext(
    db_connect(),
    $schema,
    '',
    UserRole::Viewer,
    0,
);

$_GET['table'] = $sharedTable;
unset($_GET['api']);
define('OS_TABLE_ACCESS_DELEGATED', true);

frontapi_list($shareContext);
