<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class Sql
{
    public static function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public static function qualified(string $schemaName, string $table): string
    {
        return self::ident($schemaName) . '.' . self::ident($table);
    }

    public static function intArray(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }
}
