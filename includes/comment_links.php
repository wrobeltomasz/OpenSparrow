<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use PgSql\Connection;

function comment_record_link_pattern(): string
{
    return '/\[Record:\s*([a-zA-Z0-9_]+):(\d+)\]/';
}

function record_links_resolve(Connection $conn, array $bodies, int $userId): array
{
    $pairs = [];
    foreach ($bodies as $body) {
        foreach (comment_extract_record_links((string)$body) as $tableName => $ids) {
            foreach ($ids as $recordId => $ignored) {
                $pairs[$tableName][$recordId] = true;
            }
        }
    }

    if ($pairs === []) {
        return [];
    }

    require_once __DIR__ . '/config_store.php';
    $schema = config_get('schema') ?? [];
    $userRecordsConfig = config_get('user_records') ?? [];
    $configuredColumns = is_array($userRecordsConfig['columns'] ?? null) ? $userRecordsConfig['columns'] : [];

    $links = [];
    foreach ($pairs as $tableName => $ids) {
        $tableConfig = $schema['tables'][$tableName] ?? null;
        if ($tableConfig === null || !empty($tableConfig['hidden'])) {
            continue;
        }
        if (!user_can_access_table($tableName, $userId)) {
            continue;
        }

        $rowsSql = sprintf(
            'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
            record_label_sql($tableConfig, $configuredColumns[$tableName] ?? []),
            pg_ident($tableConfig['schema'] ?? 'public'),
            pg_ident($tableName)
        );
        $labelResult = pg_query_params(
            $conn,
            $rowsSql,
            ['{' . implode(',', array_keys($ids)) . '}']
        );
        if (!$labelResult) {
            error_log('[record_links] lookup failed: ' . pg_last_error($conn));
            continue;
        }

        while ($row = pg_fetch_assoc($labelResult)) {
            $recordId = (int)$row['id'];
            $label = trim((string)($row['label'] ?? ''));
            $links[$tableName . ':' . $recordId] = [
                'table' => $tableName,
                'id'    => $recordId,
                'label' => $label !== '' ? $label : ('#' . $recordId),
                'url'   => 'edit.php?table=' . rawurlencode($tableName) . '&id=' . $recordId,
            ];
        }
    }

    return $links;
}

function comment_extract_record_links(string $body): array
{
    $pairs = [];
    if (!preg_match_all(comment_record_link_pattern(), $body, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $recordId = (int) $match[2];
        if ($recordId > 0) {
            $pairs[$match[1]][$recordId] = true;
        }
    }
    return $pairs;
}
