<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../src/Security/UserRole.php';

use App\Security\UserRole;

final class FrontApiContext
{
    public function __construct(
        public readonly \PgSql\Connection $conn,
        public readonly array $schema,
        public readonly string $schemaJson,
        public readonly UserRole $role,
        public readonly int $userId,
    ) {
    }

    public function isViewer(): bool
    {
        return $this->role === UserRole::Viewer;
    }
}

final class FrontApiWriteContext
{
    public function __construct(
        public readonly \PgSql\Connection $conn,
        public readonly array $schema,
        public readonly UserRole $role,
        public readonly int $userId,
        public readonly array $body,
        public readonly string $table,
        public readonly array $tableConfig,
        public readonly string $schemaName,
        public readonly string $idColumn,
    ) {
    }

    public static function fromApi(
        FrontApiContext $apiContext,
        array $body,
        string $table,
        array $tableConfig,
        string $schemaName,
        string $idColumn,
    ): self {
        return new self(
            $apiContext->conn,
            $apiContext->schema,
            $apiContext->role,
            $apiContext->userId,
            $body,
            $table,
            $tableConfig,
            $schemaName,
            $idColumn,
        );
    }

    public function isViewer(): bool
    {
        return $this->role === UserRole::Viewer;
    }
}
