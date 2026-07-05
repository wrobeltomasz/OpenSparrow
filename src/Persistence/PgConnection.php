<?php

declare(strict_types=1);

namespace App\Persistence;

final readonly class PgConnection implements ConnectionInterface
{
    public function __construct(private \PgSql\Connection $connection)
    {
    }

    #[\Override]
    public function execute(string $sql, array $params = []): \PgSql\Result
    {
        $res = pg_query_params($this->connection, $sql, $params);
        if ($res === false) {
            throw new \RuntimeException('Query failed: ' . pg_last_error($this->connection));
        }
        return $res;
    }

    #[\Override]
    public function exec(string $sql): \PgSql\Result
    {
        $res = pg_query($this->connection, $sql);
        if ($res === false) {
            throw new \RuntimeException('Query failed: ' . pg_last_error($this->connection));
        }
        return $res;
    }

    #[\Override]
    public function escapeLiteral(string $value): string
    {
        return pg_escape_literal($this->connection, $value);
    }

    #[\Override]
    public function native(): \PgSql\Connection
    {
        return $this->connection;
    }
}
