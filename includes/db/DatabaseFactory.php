<?php

declare(strict_types=1);

namespace OpenSparrow\Db;

class DatabaseFactory
{
    /** @var string[] */
    private static array $mysqlTables = [];

    /** @param string[] $tables */
    public static function setMysqlTables(array $tables): void
    {
        self::$mysqlTables = $tables;
    }

    /**
     * Returns a MysqlGateway when $table is in the MySQL routing list and a PDO
     * connection is available; falls back to PostgresGateway otherwise.
     *
     * Example usage in an api.php that returns JSON:
     *
     *   use OpenSparrow\Db\DatabaseFactory;
     *   require_once 'includes/db/DatabaseGatewayInterface.php';
     *   require_once 'includes/db/PostgresGateway.php';
     *   require_once 'includes/db/MysqlGateway.php';
     *   require_once 'includes/db/DatabaseFactory.php';
     *
     *   $cfg = json_decode(file_get_contents('config/mysql_gateway.json'), true);
     *   DatabaseFactory::setMysqlTables($cfg['mysql_tables'] ?? []);
     *
     *   $gateway = DatabaseFactory::make($_GET['table'], db_connect(), $mysqlPdo);
     *   header('Content-Type: application/json');
     *   echo json_encode($gateway->fetchAll($_GET['table']));
     *
     * @param resource $pgConn
     */
    public static function make(string $table, $pgConn, ?\PDO $mysqlPdo = null): DatabaseGatewayInterface
    {
        if ($mysqlPdo !== null && in_array($table, self::$mysqlTables, true)) {
            return new MysqlGateway($mysqlPdo);
        }
        return new PostgresGateway($pgConn);
    }
}
