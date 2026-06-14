<?php

declare(strict_types=1);

namespace OpenSparrow\Db;

class MysqlGateway implements DatabaseGatewayInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function fetchAll(string $table): array
    {
        $safe  = str_replace('`', '', $table);
        $stmt  = $this->pdo->query('SELECT * FROM `' . $safe . '`');
        return ($stmt !== false) ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
