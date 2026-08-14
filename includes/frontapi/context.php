<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/context.php — the explicit request context the frontend data
// API hands to its route modules.
//
// The admin side (includes/admin/*.php) passes its request state through the
// including scope: its modules read $action, $file and $isDemoMode as ambient
// variables. That works, but no static analyser can verify it — those modules are
// the single largest group of entries in phpstan-baseline.neon, and they are why
// the project cannot yet raise PHPStan past level 2 (see docs/MAINTENANCE.md).
//
// The frontend modules therefore take their state as a parameter instead. Every
// field a module reads is a typed, readonly property, so a typo is a PHPStan error
// rather than a silent null at runtime.

require_once __DIR__ . '/../../src/Security/UserRole.php';

use App\Security\UserRole;

/**
 * Request state shared by every frontend API route.
 *
 * $schema is the FULL, unfiltered schema document. Every config-supplied lookup in
 * this API (FK references, subtables, board and calendar bindings) resolves against
 * it and must keep working for tables the user cannot open directly.
 *
 * $schemaJson is the ACCESS-FILTERED document, already encoded — the only form that
 * may be sent to the client. The two must not be confused: see
 * tests/Security/AccessScopeEndpointGuardTest::testSchemaEndpointIsFilteredByTableAccess().
 */
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

    /** Viewer accounts reach the read routes but may not change anything. */
    public function isViewer(): bool
    {
        return $this->role === UserRole::Viewer;
    }
}

/**
 * Request state for the mutating routes (POST / PATCH / DELETE), which all target a
 * single record table.
 *
 * Constructing one of these is what proves the shared write preamble ran: the body
 * was decoded, $table was resolved through safe_table() (unknown table => 400) and
 * require_table_access() gated it. That gate lives in public/api.php and runs ONCE
 * for every mutating route — the modules must never repeat it, because a per-route
 * copy is a list to keep by hand, which is exactly how the admin $postActions
 * whitelist and the DEMO_MODE guards have drifted before.
 *
 * Pinned by tests/Security/FrontApiGuardsTest.
 */
final class FrontApiWriteContext
{
    public function __construct(
        public readonly \PgSql\Connection $conn,
        public readonly array $schema,
        public readonly UserRole $role,
        public readonly int $userId,
        public readonly array $body,
        public readonly string $table,
        public readonly array $tableCfg,
        public readonly string $schemaName,
        public readonly string $idCol,
    ) {
    }

    /**
     * Builds the write context from the shared one plus the resolved, already-gated
     * target. Called only by public/api.php, right after the write preamble.
     */
    public static function fromApi(
        FrontApiContext $api,
        array $body,
        string $table,
        array $tableCfg,
        string $schemaName,
        string $idCol,
    ): self {
        return new self(
            $api->conn,
            $api->schema,
            $api->role,
            $api->userId,
            $body,
            $table,
            $tableCfg,
            $schemaName,
            $idCol,
        );
    }

    public function isViewer(): bool
    {
        return $this->role === UserRole::Viewer;
    }
}
