<?php

declare(strict_types=1);

namespace OpenSparrow\Db;

interface DatabaseGatewayInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $table): array;
}
