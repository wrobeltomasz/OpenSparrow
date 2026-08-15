<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller;

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Security\UserRole;
use FrontApiContext;
use FrontApiWriteContext;
use I18n;
use Throwable;

final class FrontApiController
{
    private const READ_ROUTES = [
        'workflows'       => ['workflows', 'frontapi_workflows'],
        'dashboard'       => ['dashboard', 'frontapi_dashboard'],
        'calendar'        => ['calendar', 'frontapi_calendar'],
        'board'           => ['board', 'frontapi_board'],
        'm2m_rows'        => ['m2m', 'frontapi_m2m_rows'],
        'image_rows'      => ['m2m', 'frontapi_image_rows'],
        'list'            => ['list', 'frontapi_list'],
        'subtable_counts' => ['list', 'frontapi_subtable_counts'],
    ];

    public function __construct(
        private readonly SessionInterface $session,
        private readonly PhpRequest $request,
    ) {
    }

    public function handle(PhpRequest $request): void
    {
        $osFrontApiHandler = static function (string $module, string $function): callable {
            require_once __DIR__ . '/../frontapi/' . $module . '.php';
            return $function;
        };

        $method = $request->method();
        $role   = UserRole::fromSession();

        $profileAction = $request->query('action');
        if (in_array($profileAction, ['update_avatar', 'change_password'], true)) {
            $handler = $osFrontApiHandler('profile', 'frontapi_profile');
            $handler(db_connect(), $method, $profileAction, $this->session->userId());
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
        require_once __DIR__ . '/../automations.php';

        $osCtx = new FrontApiContext(
            $conn,
            $schema,
            (string) $schemaJson,
            $role,
            $this->session->userId(),
        );

        try {
            $apiParam = $request->query('api');

            if ($method === 'GET' && $apiParam === 'schema') {
                throw ResponseException::raw((string) $schemaJson);
            }

            if ($method === 'GET' && isset(self::READ_ROUTES[$apiParam])) {
                [$module, $function] = self::READ_ROUTES[$apiParam];
                $handler = $osFrontApiHandler($module, $function);
                $handler($osCtx);
            }

            if ($method === 'POST' && $apiParam === 'workflow_procedure') {
                $handler = $osFrontApiHandler('workflow_procedure', 'frontapi_workflow_procedure');
                $handler($osCtx);
            }

            if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
                $body  = json_decode(file_get_contents('php://input') ?: '[]', true);
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
    }
}
