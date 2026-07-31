<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Persistence;

final class Identifier
{
    public static function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public static function quoteQualified(string $schema, string $table): string
    {
        return self::quote($schema) . '.' . self::quote($table);
    }
}
