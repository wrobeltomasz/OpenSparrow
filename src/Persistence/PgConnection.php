<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Persistence;

final readonly class PgConnection
{
    public function __construct(private \PgSql\Connection $connection)
    {
    }

    public function execute(string $sql, array $parameters = []): \PgSql\Result
    {
        $result = pg_query_params($this->connection, $sql, $parameters);
        if ($result === false) {
            throw new \RuntimeException('Query failed: ' . pg_last_error($this->connection));
        }
        return $result;
    }

    public function exec(string $sql): \PgSql\Result
    {
        $result = pg_query($this->connection, $sql);
        if ($result === false) {
            throw new \RuntimeException('Query failed: ' . pg_last_error($this->connection));
        }
        return $result;
    }

    public function escapeLiteral(string $value): string
    {
        return pg_escape_literal($this->connection, $value);
    }

    public function native(): \PgSql\Connection
    {
        return $this->connection;
    }
}
