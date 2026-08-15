<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ResponseException;

function frontapi_m2m_rows(FrontApiContext $ctx): never
{
    $schema = $ctx->schema;
    $table  = $_GET['table']     ?? '';
    $m2mIdx = (int)($_GET['m2m_index'] ?? 0);
    $idsRaw = $_GET['ids']       ?? '';
    if (!isset($schema['tables'][$table])) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }
    require_table_access($table);

    $ids = array_values(array_filter(explode(',', $idsRaw), 'ctype_digit'));
    if (empty($ids)) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $m2mList = $schema['tables'][$table]['many_to_many'] ?? [];
    if (!isset($m2mList[$m2mIdx])) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $cfg        = $m2mList[$m2mIdx];
    $jt         = $cfg['junction_table'] ?? '';
    $selfFk     = $cfg['self_fk']        ?? '';
    $otherFk    = $cfg['other_fk']       ?? '';
    $otherTable = $cfg['other_table']    ?? '';
    $displayCol = $cfg['display_column'] ?? 'id';

    if (
        !$jt || !$selfFk || !$otherFk || !$otherTable
        || !isset($schema['tables'][$jt], $schema['tables'][$otherTable])
    ) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $jtSchema = $schema['tables'][$jt]['schema']         ?? 'public';
    $otSchema = $schema['tables'][$otherTable]['schema'] ?? 'public';
    $placeholders = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($ids)));

    $qParams  = $ids;
    $ownerSql = '';
    if (!empty($schema['tables'][$table]['owner_restricted'])) {
        $ownerSql  = owner_restriction_sql('j.' . pg_ident($selfFk), count($ids) + 1, count($ids) + 2);
        $qParams[] = $table;
        $qParams[] = $ctx->userId;
    }

    $sql = sprintf(
        'SELECT j.%s AS sid, o.%s AS label
           FROM %s.%s j
           JOIN %s.%s o ON o."id" = j.%s
          WHERE j.%s IN (%s)%s
          ORDER BY j.%s, o.%s',
        pg_ident($selfFk),
        pg_ident($displayCol),
        pg_ident($jtSchema),
        pg_ident($jt),
        pg_ident($otSchema),
        pg_ident($otherTable),
        pg_ident($otherFk),
        pg_ident($selfFk),
        $placeholders,
        $ownerSql,
        pg_ident($selfFk),
        pg_ident($displayCol)
    );
    $res = @pg_query_params($ctx->conn, $sql, $qParams);
    if (!$res) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $data = [];
    while ($row = pg_fetch_assoc($res)) {
        $sid = (string)$row['sid'];
        $data[$sid][] = (string)$row['label'];
    }

    throw ResponseException::encoded(['data' => $data ?: (object)[]]);
}

function frontapi_image_rows(FrontApiContext $ctx): never
{
    require_once __DIR__ . '/../images.php';
    $schema = $ctx->schema;
    $table  = $_GET['table'] ?? '';
    if (!isset($schema['tables'][$table]) || images_config($schema, $table) === null) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }
    require_table_access($table);

    $ids = array_values(array_filter(explode(',', $_GET['ids'] ?? ''), 'ctype_digit'));
    $ids = array_slice($ids, 0, 200);
    if (empty($ids)) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $ids = filter_visible_ids($ctx->conn, $schema['tables'][$table], $table, $ids, $ctx->userId);
    if (empty($ids)) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $data = images_for_rows($ctx->conn, $table, $ids);
    throw ResponseException::encoded(['data' => $data ?: (object)[]]);
}
