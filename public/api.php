<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/frontapi/context.php';

$osFrontApiHandler = static function (string $module, string $function): callable {
    require_once __DIR__ . '/../includes/frontapi/' . $module . '.php';
    return $function;
};

os_api_bootstrap(['connect' => false]);

$method = $_SERVER['REQUEST_METHOD'];
$role = UserRole::fromSession();

$profileAction = $_GET['action'] ?? '';
if (in_array($profileAction, ['update_avatar', 'change_password'], true)) {
    $handler = $osFrontApiHandler('profile', 'frontapi_profile');
    $handler(db_connect(), $method, $profileAction, (int)$_SESSION['user_id']);
}

if ($profileAction === 'i18n_bundle' && $method === 'GET') {
    header('Cache-Control: public, max-age=3600');
    throw ResponseException::raw(
        (string) json_encode(I18n::flatBundle(), JSON_UNESCAPED_UNICODE),
        'application/json; charset=UTF-8'
    );
}

if ($role === UserRole::Admin) {
    throw new ForbiddenException('Forbidden: Admin accounts cannot access the frontend data API.');
}

if ($role === UserRole::Viewer && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    throw new ForbiddenException('Forbidden: Read-only access');
}

$schema = config_get('schema');
if ($schema === null) {
    throw new ServerErrorException('Cannot read schema configuration');
}

$schemaPublic = $schema;

$schemaPublic['tables'] = (object) filter_tables_for_user($schema['tables'] ?? []);
$schemaJson = json_encode($schemaPublic);

$conn = db_connect();
require_once __DIR__ . '/../includes/automations.php';

$osCtx = new FrontApiContext(
    $conn,
    $schema,
    (string) $schemaJson,
    $role,
    (int)$_SESSION['user_id'],
);

$osReadRoutes = [
    'workflows'       => ['workflows', 'frontapi_workflows'],
    'dashboard'       => ['dashboard', 'frontapi_dashboard'],
    'calendar'        => ['calendar', 'frontapi_calendar'],
    'board'           => ['board', 'frontapi_board'],
    'm2m_rows'        => ['m2m', 'frontapi_m2m_rows'],
    'image_rows'      => ['m2m', 'frontapi_image_rows'],
    'list'            => ['list', 'frontapi_list'],
    'subtable_counts' => ['list', 'frontapi_subtable_counts'],
];

try {
    $apiParam = $_GET['api'] ?? '';

    if ($method === 'GET' && $apiParam === 'schema') {
        throw ResponseException::raw((string) $schemaJson);
    }

    if ($method === 'GET' && isset($osReadRoutes[$apiParam])) {
        [$module, $function] = $osReadRoutes[$apiParam];
        $handler = $osFrontApiHandler($module, $function);
        $handler($osCtx);
    }

    if ($method === 'POST' && $apiParam === 'workflow_procedure') {
        $handler = $osFrontApiHandler('workflow_procedure', 'frontapi_workflow_procedure');
        $handler($osCtx);
    }

    if (in_array($method, ['POST','PATCH','DELETE'], true)) {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        $table = $body['table'] ?? '';
        try {
            $tableCfg = safe_table($schema, $table);
        } catch (\RuntimeException $e) {
            throw new BadRequestException('Unknown table');
        }

        require_table_access($table);
        $schemaName = $tableCfg['schema'] ?? 'public';
        $idCol = id_column();

        $osWriteCtx = FrontApiWriteContext::fromApi(
            $osCtx,
            is_array($body) ? $body : [],
            (string) $table,
            $tableCfg,
            (string) $schemaName,
            $idCol,
        );

        $osWriteRoutes = [
            [
                fn(): bool => $method === 'POST'
                    && ($body['api'] ?? '') === 'calendar'
                    && ($body['action'] ?? '') === 'move_event',
                'calendar',
                'frontapi_calendar_move_event',
            ],
            [
                fn(): bool => $method === 'POST'
                    && ($body['api'] ?? '') === 'board'
                    && ($body['action'] ?? '') === 'move_card',
                'board',
                'frontapi_board_move_card',
            ],
            [
                fn(): bool => $method === 'PATCH' && isset($body['id'], $body['column'], $body['value']),
                'record',
                'frontapi_record_patch',
            ],
            [
                fn(): bool => $method === 'POST' && isset($body['data']),
                'record',
                'frontapi_record_insert',
            ],
            [
                fn(): bool => $method === 'POST'
                    && ($body['action'] ?? '') === 'duplicate'
                    && isset($body['id']),
                'record',
                'frontapi_record_duplicate',
            ],
            [
                fn(): bool => $method === 'DELETE' && isset($body['id']),
                'record',
                'frontapi_record_delete',
            ],
        ];

        foreach ($osWriteRoutes as [$matches, $module, $function]) {
            if ($matches()) {
                $handler = $osFrontApiHandler($module, $function);
                $handler($osWriteCtx);
            }
        }

        throw new BadRequestException('Unsupported action or malformed request body');
    }
} catch (ControlFlowException $signal) {
    throw $signal;
} catch (Throwable $e) {
    error_log('[api][exception] ' . $e->getMessage());
    throw new ServerErrorException('Internal server error');
}
