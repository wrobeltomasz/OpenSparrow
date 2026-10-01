<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class SharedLinkValidator
{
    public static function coercBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    public static function isShareable(array $schema, string $table): bool
    {
        $tableConfig = $schema['tables'][$table] ?? null;
        if (!is_array($tableConfig)) {
            return false;
        }
        if (!empty($tableConfig['hidden'])) {
            return false;
        }
        if (!empty($tableConfig['owner_restricted'])) {
            return false;
        }
        if (\is_system_table($table)) {
            return false;
        }
        return true;
    }

    public function shareableTables(array $schema): array
    {
        $tables = is_array($schema['tables'] ?? null) ? $schema['tables'] : [];
        return array_values(array_filter(
            array_keys($tables),
            static fn(string $table): bool => self::isShareable($schema, $table)
        ));
    }

    public function validateTableName(array $schema, string $table): void
    {
        if ($table === '') {
            throw new \AdminApiMessage('The table no longer exists in the schema configuration.');
        }
        if (!isset($schema['tables'][$table])) {
            throw new \AdminApiMessage(
                'Table "' . $table . '" does not exist in the schema configuration.'
            );
        }
        if (!empty($schema['tables'][$table]['hidden'])) {
            throw new \AdminApiMessage(
                'Hidden tables cannot be shared through a public link.'
            );
        }
        if (!empty($schema['tables'][$table]['owner_restricted'])) {
            throw new \AdminApiMessage(
                'Owner-restricted tables cannot be shared through a public link.'
            );
        }
        if (\is_system_table($table)) {
            throw new \AdminApiMessage('System tables cannot be shared through a public link.');
        }
    }
}
