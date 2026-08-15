<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/exception_handler.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/page_helpers.php';
require_once __DIR__ . '/../src/Security/UserRole.php';

use App\Audit\DbAuditLogger;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\RedirectException;
use App\Exception\UnauthorizedException;
use App\Csrf\SessionCsrfTokenManager;
use App\Domain\Schema\JsonSchemaRepository;
use App\Form\FieldTypeRegistry;
use App\Form\Type\BooleanField;
use App\Form\Type\DateField;
use App\Form\Type\EnumField;
use App\Form\Type\TimestampField;
use App\Form\Type\ForeignKeyField;
use App\Form\Type\TextField;
use App\Form\UpdateMapper;
use App\Http\PhpRequest;
use App\Http\PhpSession;
use App\Persistence\PgConnection;
use App\Repository\FkOptionsLoader;
use App\Repository\PgFileRepository;
use App\Repository\PgRecordRepository;
use App\Security\UserRole;
use App\Service\ServiceContainer;

function os_require_setup(): void
{
    if (!file_exists(__DIR__ . '/../config/database.json')) {
        throw new RedirectException('setup.php');
    }
}

function os_user_caps(?string $role = null): array
{
    $userRole = $role !== null
        ? (UserRole::tryFrom($role) ?? UserRole::Viewer)
        : UserRole::fromSession();
    return [
        'canEdit'   => $userRole === UserRole::Editor,
        'canExport' => in_array($userRole, [UserRole::Editor, UserRole::Export], true),
    ];
}

function os_ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function os_require_csrf(string $source = 'header', array $body = []): void
{
    $stored = $_SESSION['csrf_token'] ?? '';
    $given  = $source === 'header'
        ? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
        : ($_POST['csrf_token'] ?? $body['csrf_token'] ?? '');
    if ($stored === '' || !hash_equals($stored, (string) $given)) {
        throw new ForbiddenException('CSRF token mismatch');
    }
}

function os_page_bootstrap(array $options = []): array
{
    os_register_exception_handler('html');
    start_session();

    $guest = !empty($options['guest']);

    if (!empty($options['setup_check']) && ($guest || !isset($_SESSION['user_id']))) {
        os_require_setup();
    }

    if (!$guest) {
        if (!isset($_SESSION['user_id'])) {
            throw new RedirectException('login.php');
        }

        enforce_session_redirect();

        if (($options['redirect_admin'] ?? true) && UserRole::fromSession() === UserRole::Admin) {
            throw new RedirectException('admin/');
        }
    }

    $csrf  = os_ensure_csrf_token();
    $nonce = bin2hex(random_bytes(16));
    send_security_headers($nonce, $options['hsts'] ?? true, $options['csp'] ?? 'default');

    $role = UserRole::fromSession()->value;

    return ['nonce' => $nonce, 'csrf' => $csrf, 'role' => $role, 'caps' => os_user_caps($role)];
}

function os_api_bootstrap(array $options = []): ?\PgSql\Connection
{
    ini_set('display_errors', '0');

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_helpers.php';

    os_register_exception_handler('json');

    header('Content-Type: application/json; charset=utf-8');
    send_security_headers();
    start_session();

    if (empty($_SESSION['user_id'])) {
        throw new UnauthorizedException('Unauthorized');
    }

    enforce_session_json();

    if (
        !empty($options['require_ajax'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
    ) {
        throw new ForbiddenException('Forbidden');
    }

    if (isset($options['role']) && UserRole::fromSession() !== UserRole::from($options['role'])) {
        throw new ForbiddenException('Forbidden: ' . $options['role'] . ' role required');
    }

    if (
        ($options['csrf'] ?? 'header') === 'header'
        && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)
    ) {
        os_require_csrf('header');
    }

    $gate = $options['gate'] ?? true;
    if ($gate !== false) {
        os_gate_request_scopes(is_array($gate) ? $gate : []);
    }

    return ($options['connect'] ?? true) ? db_connect() : null;
}

function os_api_action(): array
{
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $body   = [];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $body   = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = is_array($body) ? ($body['action'] ?? '') : '';
        } else {
            $action = $_POST['action'] ?? '';
        }
    }

    return [
        'method' => $method,
        'action' => (string) $action,
        'body'   => is_array($body) ? $body : [],
    ];
}

function os_api_dispatch(
    string $action,
    array $handlers,
    string $logTag,
    string $missingMessage = 'Missing action.'
): void {
    if ($action === '') {
        jsonError($missingMessage, 400);
    }
    if (!isset($handlers[$action])) {
        jsonError("Unknown action: {$action}", 400);
    }

    try {
        $handlers[$action]();
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        error_log('[' . $logTag . '] ' . $e->getMessage());
        jsonError('Internal server error.', 500);
    }
}

function os_boot_app(): array
{
    require_once __DIR__ . '/autoload.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_helpers.php';
    require_once __DIR__ . '/automations.php';
    require_once __DIR__ . '/images.php';

    $session = new PhpSession();
    $request = new PhpRequest();
    $csrf    = new SessionCsrfTokenManager($session);

    $pgConn   = db_connect();
    $db       = new PgConnection($pgConn);
    $services = new ServiceContainer($pgConn);

    require_once __DIR__ . '/config_store.php';
    $schemas  = new JsonSchemaRepository(config_get('schema') ?? ['tables' => []]);
    $fkLoader = new FkOptionsLoader($db);

    $fieldRegistry = new FieldTypeRegistry([
        new ForeignKeyField(),
        new BooleanField(),
        new EnumField(),
        new TimestampField(),
        new DateField(),
        new TextField(),
    ]);

    $records = new PgRecordRepository($db, $schemas, $fkLoader);

    return [
        'session'       => $session,
        'request'       => $request,
        'csrf'          => $csrf,
        'db'            => $db,
        'conn'          => $pgConn,
        'services'      => $services,
        'schemas'       => $schemas,
        'fkLoader'      => $fkLoader,
        'fieldRegistry' => $fieldRegistry,
        'mapper'        => new UpdateMapper($fieldRegistry),
        'records'       => $records,
        'files'         => new PgFileRepository($db),
        'audit'         => new DbAuditLogger($db),
    ];
}
