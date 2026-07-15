<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// bootstrap.php — Central bootstrap for every public entry point
// os_page_bootstrap() — HTML controllers: session, setup check, auth redirect, staleness enforcement, admin redirect, CSRF token, CSP nonce + security headers
// os_api_bootstrap()  — JSON endpoints: silences HTML errors, JSON Content-Type, security headers, session, 401 gate, staleness enforcement, optional AJAX/role gates, header CSRF, optional DB connection
// os_require_csrf()   — CSRF validation from the X-CSRF-Token header or a body/form csrf_token field
// os_user_caps()      — capability flags (canEdit/canExport) exposed to the client instead of the raw role
// os_boot_app()       — full OOP object graph for form pages (create.php, edit.php): repositories, field registry, CSRF manager, audit logger

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/page_helpers.php';
require_once __DIR__ . '/../src/Security/UserRole.php';

use App\Audit\DbAuditLogger;
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
use App\Persistence\MysqlConnection;
use App\Persistence\PgConnection;
use App\Repository\FkOptionsLoader;
use App\Repository\MysqlRecordRepository;
use App\Repository\PgFileRepository;
use App\Repository\PgRecordRepository;
use App\Repository\RoutingRecordRepository;
use App\Security\UserRole;

// Redirect to the first-run wizard when the app has not been configured yet.
function os_require_setup(): void
{
    if (!file_exists(__DIR__ . '/../config/database.json')) {
        header('Location: setup.php');
        exit;
    }
}

// Capability flags exposed to the client instead of the raw role name
// to reduce attack surface during reconnaissance.
function os_user_caps(?string $role = null): array
{
    $r = $role !== null
        ? (UserRole::tryFrom($role) ?? UserRole::Viewer)
        : UserRole::fromSession();
    return [
        'canEdit'   => $r === UserRole::Editor,
        'canExport' => in_array($r, [UserRole::Editor, UserRole::Export], true),
    ];
}

// Make sure the session owns a CSRF token and return it.
function os_ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate the CSRF token against the session; stop with 403 JSON on mismatch.
// 'header' reads X-CSRF-Token; 'body' reads csrf_token from $_POST or a decoded JSON body.
function os_require_csrf(string $source = 'header', array $body = []): void
{
    $stored = $_SESSION['csrf_token'] ?? '';
    $given  = $source === 'header'
        ? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
        : ($_POST['csrf_token'] ?? $body['csrf_token'] ?? '');
    if ($stored === '' || !hash_equals($stored, (string) $given)) {
        http_response_code(403);
        exit(json_encode(['error' => 'CSRF token mismatch']));
    }
}

// Standard guard for HTML page controllers. Options:
//   'csp'            CSP mode passed to send_security_headers() (default 'default')
//   'hsts'           include the HSTS header (default true)
//   'guest'          page is reachable without login — skips the auth/admin gates (default false)
//   'redirect_admin' send admin users to /admin (default true)
//   'setup_check'    redirect to setup.php when config/database.json is missing (default false)
// Returns ['nonce' => CSP nonce, 'csrf' => token, 'role' => role, 'caps' => capability flags].
function os_page_bootstrap(array $options = []): array
{
    start_session();

    $guest = !empty($options['guest']);

    // Guest pages (login) check unconditionally; authenticated pages only before first login
    if (!empty($options['setup_check']) && ($guest || !isset($_SESSION['user_id']))) {
        os_require_setup();
    }

    if (!$guest) {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
        // Hard session-lifetime + User-Agent enforcement (centralised in session.php)
        enforce_session_redirect();
        // Admin role belongs in the admin panel, not the frontend
        if (($options['redirect_admin'] ?? true) && UserRole::fromSession() === UserRole::Admin) {
            header('Location: admin/');
            exit;
        }
    }

    $csrf  = os_ensure_csrf_token();
    $nonce = bin2hex(random_bytes(16));
    send_security_headers($nonce, $options['hsts'] ?? true, $options['csp'] ?? 'default');

    // Normalised via the enum: unknown session values degrade to 'viewer'
    $role = UserRole::fromSession()->value;

    return ['nonce' => $nonce, 'csrf' => $csrf, 'role' => $role, 'caps' => os_user_caps($role)];
}

// Standard guard for JSON API endpoints. Options:
//   'connect'      open and return a PostgreSQL connection (default true)
//   'csrf'         'header' validates X-CSRF-Token on POST/PATCH/DELETE; 'manual' skips —
//                  the endpoint calls os_require_csrf() itself on its mutating actions (default 'header')
//   'require_ajax' require the X-Requested-With: XMLHttpRequest header (default false)
//   'role'         require this exact role, e.g. 'editor' (default: any authenticated user)
// Always loads db.php + api_helpers.php, so endpoints may call db_connect(), jsonError(), sys_table() etc.
function os_api_bootstrap(array $options = []): ?\PgSql\Connection
{
    ini_set('display_errors', '0');

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_helpers.php';

    header('Content-Type: application/json; charset=utf-8');
    send_security_headers();
    start_session();

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        exit(json_encode(['error' => 'Unauthorized']));
    }
    // Hard session-lifetime + User-Agent enforcement (centralised in session.php)
    enforce_session_json();

    if (
        !empty($options['require_ajax'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
    ) {
        http_response_code(403);
        exit(json_encode(['error' => 'Forbidden']));
    }

    // UserRole::from() throws on an unknown option value, so a typo in an
    // endpoint's required role surfaces as a hard error instead of a silent 403.
    if (isset($options['role']) && UserRole::fromSession() !== UserRole::from($options['role'])) {
        http_response_code(403);
        exit(json_encode(['error' => 'Forbidden: ' . $options['role'] . ' role required']));
    }

    if (
        ($options['csrf'] ?? 'header') === 'header'
        && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)
    ) {
        os_require_csrf('header');
    }

    return ($options['connect'] ?? true) ? db_connect() : null;
}

// Build the full OOP object graph used by the form pages (create.php, edit.php).
// Sets $GLOBALS['conn'] for legacy api_helpers functions and returns the graph
// as an associative array for destructuring at the call site.
function os_boot_app(): array
{
    require_once __DIR__ . '/autoload.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_helpers.php';
    require_once __DIR__ . '/automations.php';

    $session = new PhpSession();
    $request = new PhpRequest();
    $csrf    = new SessionCsrfTokenManager($session);

    $pgConn          = db_connect();
    $db              = new PgConnection($pgConn);
    $GLOBALS['conn'] = $pgConn; // backward-compat: raw PgSql\Connection for legacy api_helpers functions

    $schemas  = new JsonSchemaRepository(__DIR__ . '/../config/schema.json');
    $fkLoader = new FkOptionsLoader($db);

    $fieldRegistry = new FieldTypeRegistry([
        new ForeignKeyField(),
        new BooleanField(),
        new EnumField(),
        new TimestampField(),
        new DateField(),
        new TextField(), // universal fallback — must be last
    ]);

    // Records go through a router: PostgreSQL by default, MySQL for tables listed in
    // config/mysql_gateway.json. The MySQL connection is built lazily — it opens
    // only when a MySQL-routed table is actually accessed, so PostgreSQL-only pages
    // never open (or stall on) the external MySQL gateway. When MySQL is not
    // configured, PostgreSQL tables keep working and only MySQL tables error.
    $records = new RoutingRecordRepository(
        new PgRecordRepository($db, $schemas, $fkLoader),
        static function (): ?MysqlRecordRepository {
            $conn = MysqlConnection::fromConfig();
            return $conn !== null ? new MysqlRecordRepository($conn) : null;
        }
    );

    return [
        'session'       => $session,
        'request'       => $request,
        'csrf'          => $csrf,
        'db'            => $db,
        'conn'          => $pgConn,
        'schemas'       => $schemas,
        'fkLoader'      => $fkLoader,
        'fieldRegistry' => $fieldRegistry,
        'mapper'        => new UpdateMapper($fieldRegistry),
        'records'       => $records,
        'files'         => new PgFileRepository($db),
        'audit'         => new DbAuditLogger($db),
    ];
}
