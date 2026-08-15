<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function m2m_options(mixed $conn, array $cfg, array $rawSchema): array
{
    $otherTable = $cfg['other_table'] ?? '';

    if ($otherTable === '') {
        $jt  = $cfg['junction_table'] ?? '';
        $ofk = $cfg['other_fk']       ?? '';
        $otherTable = $rawSchema['tables'][$jt]['foreign_keys'][$ofk]['reference_table'] ?? '';
    }

    if ($otherTable === '') {
        return [];
    }

    $pgSchema   = $rawSchema['tables'][$otherTable]['schema'] ?? 'public';
    $displayCol = $cfg['display_column'] ?? 'id';

    $sql = sprintf(
        'SELECT "id", %s AS label FROM %s.%s ORDER BY %s',
        pg_ident($displayCol),
        pg_ident($pgSchema),
        pg_ident($otherTable),
        pg_ident($displayCol)
    );

    $res = @pg_query($conn, $sql);
    if (!$res) {
        error_log('[m2m_options] ' . pg_last_error($conn));
        return [];
    }

    $rows = [];
    while ($r = pg_fetch_assoc($res)) {
        $rows[] = ['id' => (string)$r['id'], 'label' => (string)$r['label']];
    }
    return $rows;
}

function m2m_selected(mixed $conn, array $cfg, int $recordId, array $rawSchema): array
{
    $jt       = $cfg['junction_table'] ?? '';
    $selfFk   = $cfg['self_fk']        ?? '';
    $otherFk  = $cfg['other_fk']       ?? '';

    if (!$jt || !$selfFk || !$otherFk) {
        return [];
    }

    $pgSchema = $rawSchema['tables'][$jt]['schema'] ?? 'public';

    $sql = sprintf(
        'SELECT %s FROM %s.%s WHERE %s = $1',
        pg_ident($otherFk),
        pg_ident($pgSchema),
        pg_ident($jt),
        pg_ident($selfFk)
    );

    $res = @pg_query_params($conn, $sql, [$recordId]);
    if (!$res) {
        error_log('[m2m_selected] ' . pg_last_error($conn));
        return [];
    }

    $ids = [];
    while ($r = pg_fetch_assoc($res)) {
        $ids[] = (string)$r[$otherFk];
    }
    return $ids;
}

function m2m_sync(mixed $conn, array $cfg, int $recordId, array $selectedIds, array $rawSchema): bool
{
    $jt      = $cfg['junction_table'] ?? '';
    $selfFk  = $cfg['self_fk']        ?? '';
    $otherFk = $cfg['other_fk']       ?? '';

    if (!$jt || !$selfFk || !$otherFk) {
        return false;
    }

    $pgSchema = $rawSchema['tables'][$jt]['schema'] ?? 'public';

    pg_query($conn, 'BEGIN');

    $del = sprintf(
        'DELETE FROM %s.%s WHERE %s = $1',
        pg_ident($pgSchema),
        pg_ident($jt),
        pg_ident($selfFk)
    );
    if (!@pg_query_params($conn, $del, [$recordId])) {
        pg_query($conn, 'ROLLBACK');
        error_log('[m2m_sync] delete failed: ' . pg_last_error($conn));
        return false;
    }

    foreach ($selectedIds as $otherId) {
        if (!ctype_digit((string)$otherId)) {
            continue;
        }
        $ins = sprintf(
            'INSERT INTO %s.%s (%s, %s) VALUES ($1, $2)',
            pg_ident($pgSchema),
            pg_ident($jt),
            pg_ident($selfFk),
            pg_ident($otherFk)
        );
        if (!@pg_query_params($conn, $ins, [$recordId, $otherId])) {
            pg_query($conn, 'ROLLBACK');
            error_log('[m2m_sync] insert failed: ' . pg_last_error($conn));
            return false;
        }
    }

    pg_query($conn, 'COMMIT');
    return true;
}
