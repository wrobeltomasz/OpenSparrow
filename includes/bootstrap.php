<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_helpers.php';

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
use App\Persistence\PgConnection;
use App\Repository\FkOptionsLoader;
use App\Repository\PgFileRepository;
use App\Repository\PgRecordRepository;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$mapper  = new UpdateMapper($fieldRegistry);
$records = new PgRecordRepository($db, $schemas, $fkLoader);
$files   = new PgFileRepository($db);
$audit   = new DbAuditLogger($db);
