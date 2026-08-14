<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// api.php — Main CRUD/data REST API for the frontend: front controller only.
//
// The route bodies live in per-domain modules under includes/frontapi/ (outside the
// docroot), mirroring how public/admin/api.php dispatches to includes/admin/. Unlike
// those, the frontend modules take an explicit FrontApiContext / FrontApiWriteContext
// instead of reading ambient variables, so PHPStan can verify them — see
// includes/frontapi/context.php and docs/MAINTENANCE.md.
//
// This file owns, in order:
//   1. the auth gate, staleness enforcement and header-CSRF (os_api_bootstrap),
//   2. the self-service profile actions, which are permitted for EVERY authenticated
//      user and therefore run ahead of the role gates,
//   3. the admin block and the viewer read-only block,
//   4. the schema load and the access-filtered copy sent to clients,
//   5. read-route dispatch,
//   6. the shared write preamble — body decode, safe_table(), require_table_access()
//      ONCE for all six mutating routes — and write-route dispatch.
//
// public/api/fk.php re-enters this file with $_GET['api'] = 'list' and two constants
// defined, to reuse the list route for FK label lookups; it must stay re-enterable.

use App\Security\UserRole;

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/frontapi/context.php';

/** Loads a route module and returns its handler, ready to call. */
$osFrontApiHandler = static function (string $module, string $function): callable {
    require_once __DIR__ . '/../includes/frontapi/' . $module . '.php';
    return $function;
};

// Auth gate, staleness enforcement and header-CSRF for POST/PATCH/DELETE.
// connect=false: the DB connection is opened per-branch below.
os_api_bootstrap(['connect' => false]);

$method = $_SERVER['REQUEST_METHOD'];
$role = UserRole::fromSession();

// Self-service profile actions — permitted for every authenticated user regardless of
// role, so they answer BEFORE the admin and viewer gates below. Do not move them.
$profileAction = $_GET['action'] ?? '';
if (in_array($profileAction, ['update_avatar', 'change_password'], true)) {
    $handler = $osFrontApiHandler('profile', 'frontapi_profile');
    $handler(db_connect(), $method, $profileAction, (int)$_SESSION['user_id']);
}

// Translation bundle — all authenticated users, no DB required
if ($profileAction === 'i18n_bundle' && $method === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(I18n::flatBundle(), JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin role is restricted to the admin panel; block from frontend data API
if ($role === UserRole::Admin) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Admin accounts cannot access the frontend data API.']));
}

// Block data modification requests for viewer users
if ($role === UserRole::Viewer && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden: Read-only access']));
}

// Load schema from the spw_config store
$schema = config_get('schema');
if ($schema === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot read schema configuration']);
    exit;
}
// Per-user table access applies to the schema document itself: table names, PG schema
// names and every column definition are exactly what the allow-list exists to scope,
// and this endpoint is reachable by any logged-in frontend user. api/schema.php filters
// the same way — the two must not disagree about what a user is allowed to know exists.
$schemaPublic = $schema;
// (object) so a user left with no tables at all still receives a JSON object — an
// empty PHP array would reach the client as [] and behave like a list.
$schemaPublic['tables'] = (object) filter_tables_for_user($schema['tables'] ?? []);
$schemaJson = json_encode($schemaPublic);
// Connect to DB (db.php + api_helpers.php are already loaded by the bootstrap)
$conn = db_connect();
require_once __DIR__ . '/../includes/automations.php';

// The context every route module reads. $schema is the FULL document (config-supplied
// lookups resolve against it); $schemaJson is the filtered one that may be sent out.
$osCtx = new FrontApiContext(
    $conn,
    $schema,
    (string) $schemaJson,
    $role,
    (int)$_SESSION['user_id'],
);

// GET routes: ?api=<name> => [module, handler]. api=schema stays inline — a module
// for one echo would cost more than it explains.
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

    // GET: SCHEMA DATA
    if ($method === 'GET' && $apiParam === 'schema') {
        echo $schemaJson;
        exit;
    }

    if ($method === 'GET' && isset($osReadRoutes[$apiParam])) {
        [$module, $function] = $osReadRoutes[$apiParam];
        $handler = $osFrontApiHandler($module, $function);
        $handler($osCtx);
    }

    // POST: WORKFLOW STEP PROCEDURE (CALL a configured PostgreSQL procedure)
    // Deliberately outside the mutating group below: that group resolves
    // $body['table'] through safe_table(), and this request targets no schema table.
    if ($method === 'POST' && $apiParam === 'workflow_procedure') {
        $handler = $osFrontApiHandler('workflow_procedure', 'frontapi_workflow_procedure');
        $handler($osCtx);
    }

    // POST / PATCH / DELETE
    if (in_array($method, ['POST','PATCH','DELETE'], true)) {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        $table = $body['table'] ?? '';
        try {
            $tableCfg = safe_table($schema, $table);
        } catch (\RuntimeException $e) {
            http_response_code(400);
            exit(json_encode(['error' => 'Unknown table']));
        }
        // Every mutating route below (insert, update, delete, calendar/board move,
        // mass insert) resolves its target from this one $table, so the gate belongs
        // here — one place, no per-route copies to forget. The modules must never
        // repeat it; tests/Security/FrontApiGuardsTest pins both halves of that.
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

        // Ordered, not a map: these routes are selected by payload shape rather than a
        // single action name, and the order below is the order the checks were written
        // in — a PATCH carrying `data` must still be read as a PATCH.
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

        // Every route above is guarded by isset()/action equality and exits on its own.
        // Falling through means the payload matched none of them (e.g. a PATCH whose
        // "value" is null, so isset() was false) — answer explicitly instead of ending
        // with HTTP 200 and an empty body, which the client reads as a successful write.
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported action or malformed request body']);
        exit;
    }
} catch (Throwable $e) {
    error_log('[api][exception] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}

// A GET naming no known ?api= route falls through to here and ends the request with
// HTTP 200 and an empty body. That is pre-existing behaviour, preserved deliberately
// rather than tightened inside a refactor: index.php requires this file whenever ?api
// is present, and the frontend treats an empty body as "nothing to do". Worth
// revisiting on its own, with the client checked alongside.
