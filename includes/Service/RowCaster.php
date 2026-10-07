<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class RowCaster
{
    public static function cast(?string $value, string $columnType): mixed
    {
        $trimmed = ($value === null) ? null : trim($value);
        if ($trimmed === '' || $trimmed === null) {
            return null;
        }
        $normalizedType = strtolower($columnType);

        if (str_contains($normalizedType, 'bool')) {
            return in_array(strtolower($trimmed), ['1', 'true', 't', 'yes', 'y'], true) ? 'true' : 'false';
        }
        if (str_contains($normalizedType, 'int') || str_contains($normalizedType, 'serial')) {
            return is_numeric($trimmed) ? (string)(int) $trimmed : null;
        }
        if (
            str_contains($normalizedType, 'numeric') || str_contains($normalizedType, 'decimal') ||
            str_contains($normalizedType, 'float')   || str_contains($normalizedType, 'real')    ||
            str_contains($normalizedType, 'double')
        ) {
            $normalizedNumber = str_replace(',', '.', $trimmed);
            return is_numeric($normalizedNumber) ? (string)(float) $normalizedNumber : null;
        }
        if ($normalizedType === 'date') {
            return self::toDate($trimmed);
        }
        if (str_contains($normalizedType, 'timestamp') || str_contains($normalizedType, 'datetime')) {
            return self::toTimestamp($trimmed);
        }
        if (str_contains($normalizedType, 'time')) {
            return self::toTime($trimmed);
        }
        return $trimmed;
    }

    private static function toDate(string $value): ?string
    {
        if (preg_match('/^(\d{2})[.\\/](\d{2})[.\\/](\d{4})$/', $value, $matches)) {
            $value = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private static function toTimestamp(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function toTime(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('H:i:s', $timestamp) : null;
    }
}
