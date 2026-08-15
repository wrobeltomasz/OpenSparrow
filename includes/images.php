<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/autoload.php';

use App\Service\ImageService;

const IMAGES_FIELD = ImageService::FIELD;

const IMAGES_GRID_LIMIT = ImageService::GRID_LIMIT;

function images_config(array $schema, string $table): ?array
{
    return ImageService::config($schema, $table);
}

function images_for_record(\PgSql\Connection $conn, string $table, int $recordId): array
{
    return (new ImageService($conn))->forRecord($table, $recordId);
}

function images_count(\PgSql\Connection $conn, string $table, int $recordId): int
{
    return (new ImageService($conn))->countForRecord($table, $recordId);
}

function images_for_rows(
    \PgSql\Connection $conn,
    string $table,
    array $ids,
    int $perRow = ImageService::GRID_LIMIT
): array {
    return (new ImageService($conn))->forRows($table, $ids, $perRow);
}
