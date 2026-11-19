<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config_store.php';

const USER_CUSTOM_FIELD_TYPES = ['text', 'email', 'phone', 'number', 'date'];

function user_custom_column_present(\PgSql\Connection $conn): bool
{
    static $present = null;
    if ($present === null) {
        $present = (bool) @pg_query(
            $conn,
            'SELECT custom_fields FROM ' . sys_table('users') . ' LIMIT 0'
        );
    }
    return $present;
}

function user_custom_field_definitions(): array
{
    $config = config_get('user_custom_fields') ?? [];
    $raw = is_array($config['fields'] ?? null) ? $config['fields'] : [];

    $definitions = [];
    foreach ($raw as $field) {
        if (!is_array($field)) {
            continue;
        }
        $keyValue = $field['key'] ?? '';
        $labelValue = $field['label'] ?? '';
        $typeValue = $field['type'] ?? 'text';
        if (!is_string($keyValue) || !is_string($labelValue) || !is_string($typeValue)) {
            continue;
        }
        $key = trim($keyValue);
        $label = trim($labelValue);
        $type = $typeValue;
        if ($key === '' || $label === '' || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) !== 1) {
            continue;
        }
        if (!in_array($type, USER_CUSTOM_FIELD_TYPES, true)) {
            continue;
        }
        if (mb_strlen($label) > 100 || isset($definitions[$key])) {
            continue;
        }
        $definitions[$key] = ['key' => $key, 'label' => $label, 'type' => $type];
    }

    return array_values($definitions);
}

function user_custom_fields_decode(mixed $raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function user_custom_fields_resolve(mixed $raw, array $definitions): array
{
    $values = user_custom_fields_decode($raw);
    $rows = [];
    foreach ($definitions as $definition) {
        $value = $values[$definition['key']] ?? null;
        if (!is_scalar($value) || (is_string($value) && trim($value) === '')) {
            continue;
        }
        $rows[] = [
            'key'   => $definition['key'],
            'label' => $definition['label'],
            'value' => (string)$value,
        ];
    }
    return $rows;
}
