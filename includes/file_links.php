<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use PgSql\Connection;

function file_link_pattern(): string
{
    return '/\[File:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]/i';
}

function file_extract_links(string $body): array
{
    $uuids = [];
    if (!preg_match_all(file_link_pattern(), $body, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $uuids[strtolower($match[1])] = true;
    }
    return $uuids;
}

function file_links_resolve(Connection $conn, array $bodies, int $userId, string $role = ''): array
{
    $uuids = [];
    foreach ($bodies as $body) {
        foreach (file_extract_links((string)$body) as $uuid => $ignored) {
            $uuids[$uuid] = true;
        }
    }

    if ($uuids === []) {
        return [];
    }

    require_once __DIR__ . '/config_store.php';
    $schema = config_get('schema') ?? [];

    $result = pg_query_params(
        $conn,
        'SELECT uuid, name, display_name, related_table, related_id FROM ' . sys_table('files')
        . ' WHERE uuid = ANY($1) AND deleted_at IS NULL',
        ['{' . implode(',', array_keys($uuids)) . '}']
    );
    if (!$result) {
        error_log('[file_links] lookup failed: ' . pg_last_error($conn));
        return [];
    }

    $links = [];
    while ($row = pg_fetch_assoc($result)) {
        $uuid = strtolower((string)$row['uuid']);

        $relatedTable = $row['related_table'] ?? null;
        $relatedIdRaw = $row['related_id'] ?? null;

        if ($relatedTable !== null && $relatedIdRaw !== null && $relatedIdRaw !== '') {
            $tableConfig = $schema['tables'][$relatedTable] ?? null;
            if (is_array($tableConfig)) {
                if (!user_can_access_table((string)$relatedTable, $userId)) {
                    continue;
                }
                if (
                    !can_access_record(
                        $conn,
                        $tableConfig,
                        (string)$relatedTable,
                        (int)$relatedIdRaw,
                        $userId,
                        $role
                    )
                ) {
                    continue;
                }
            }
        }

        $label = trim((string)($row['display_name'] ?? ''));
        if ($label === '') {
            $label = trim((string)($row['name'] ?? ''));
        }

        $links[$uuid] = [
            'uuid'  => $uuid,
            'label' => $label !== '' ? $label : $uuid,
            'url'   => 'file_download.php?uuid=' . rawurlencode($uuid),
        ];
    }

    return $links;
}
