<?php

declare(strict_types=1);

// MysqlGateway.php — MySQL implementation of DatabaseGatewayInterface (external MySQL gateway)
// fetchAll($table): SELECT * via PDO; table name backtick-quoted (backticks stripped); returns [] on failure
// Wraps a \PDO connection; used by DatabaseFactory for tables listed in config/mysql_gateway.json
// Namespace OpenSparrow\Db

namespace OpenSparrow\Db;

class MysqlGateway implements DatabaseGatewayInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function fetchAll(string $table): array
    {
        $safe  = str_replace('`', '', $table);
        $stmt  = $this->pdo->query('SELECT * FROM `' . $safe . '`');
        return ($stmt !== false) ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
