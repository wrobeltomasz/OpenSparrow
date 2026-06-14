<?php

declare(strict_types=1);

namespace OpenSparrow\Db;

class PostgresGateway implements DatabaseGatewayInterface
{
    /** @var resource */
    private $conn;

    /** @param resource $conn */
    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function fetchAll(string $table): array
    {
        $result = pg_query($this->conn, 'SELECT * FROM ' . pg_escape_identifier($this->conn, $table));
        if ($result === false) {
            return [];
        }
        return pg_fetch_all($result) ?: [];
    }
}
