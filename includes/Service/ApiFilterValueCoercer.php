<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class ApiFilterValueCoercer
{
    public static function coerce(string $apiName, string $column, string $type, string $value): string
    {
        return match (self::normalizeType($type)) {
            'number'   => self::coerceNumber($apiName, $column, $value),
            'boolean'  => self::coerceBoolean($apiName, $column, $value),
            'date'     => self::coerceDate($apiName, $column, $value),
            'datetime' => self::coerceDatetime($apiName, $column, $value),
            default    => $value,
        };
    }

    public static function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (
            $normalized === 'number'
            || str_contains($normalized, 'int')
            || str_contains($normalized, 'numeric')
            || str_contains($normalized, 'float')
        ) {
            return 'number';
        }
        if (str_contains($normalized, 'bool')) {
            return 'boolean';
        }
        if (str_contains($normalized, 'timestamp') || str_contains($normalized, 'datetime')) {
            return 'datetime';
        }
        if (str_contains($normalized, 'date')) {
            return 'date';
        }
        return 'text';
    }

    private static function coerceNumber(string $apiName, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a whole number for column "' . $column . '".'
            );
        }
        return $trimmed;
    }

    private static function coerceBoolean(string $apiName, string $column, string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['true', 'false', '1', '0', 't', 'f'], true)) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a boolean for column "' . $column . '".'
            );
        }
        return in_array($normalized, ['true', '1', 't'], true) ? 'TRUE' : 'FALSE';
    }

    private static function coerceDate(string $apiName, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches) !== 1
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a valid date (YYYY-MM-DD) for column "' . $column . '".'
            );
        }
        return $trimmed;
    }

    private static function coerceDatetime(string $apiName, string $column, string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (isset($matches[4]) && ((int) $matches[4] > 23 || (int) $matches[5] > 59)) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        if (isset($matches[6]) && (int) $matches[6] > 59) {
            throw new InvalidArgumentException(
                'API "' . $apiName . '": filter value "' . $value
                . '" is not a valid datetime for column "' . $column . '".'
            );
        }
        return $trimmed;
    }
}
