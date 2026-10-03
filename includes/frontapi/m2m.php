<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ResponseException;
use App\Service\M2MService;

function frontapi_m2m_options(FrontApiContext $context): never
{
    $schema = $context->schema;
    $table  = $_GET['table'] ?? '';
    $m2mIndex = (int)($_GET['m2m_index'] ?? 0);
    $rowId = (int)($_GET['row_id'] ?? 0);
    if (!isset($schema['tables'][$table])) {
        throw ResponseException::encoded(['options' => [], 'selected' => []]);
    }
    require_table_access($table);

    if ($rowId <= 0) {
        throw ResponseException::encoded(['options' => [], 'selected' => []]);
    }

    $tableConfig = $schema['tables'][$table];
    if (!empty($tableConfig['owner_restricted'])) {
        $visibleIds = filter_visible_ids($context->conn, $tableConfig, $table, [$rowId], $context->userId);
        if (empty($visibleIds)) {
            throw ResponseException::encoded(['options' => [], 'selected' => []]);
        }
    }

    $m2mList = $schema['tables'][$table]['many_to_many'] ?? [];
    if (!isset($m2mList[$m2mIndex])) {
        throw ResponseException::encoded(['options' => [], 'selected' => []]);
    }

    $config = $m2mList[$m2mIndex];
    $otherTable = M2MService::resolveOtherTable($config, $schema);
    if (
        $otherTable === ''
        || !isset($schema['tables'][$otherTable])
        || !isset($schema['tables'][$config['junction_table'] ?? ''])
    ) {
        throw ResponseException::encoded(['options' => [], 'selected' => []]);
    }
    if (!user_can_access_table($otherTable)) {
        throw ResponseException::encoded(['options' => [], 'selected' => []]);
    }

    $otherConfig = $schema['tables'][$otherTable];
    $otherRestricted = !empty($otherConfig['owner_restricted']);

    $m2mService = new M2MService($context->conn);
    $options  = $m2mService->options($config, $schema);
    $selected = $m2mService->selected($config, $rowId, $schema);

    if ($otherRestricted) {
        $optionIds = array_column($options, 'id');
        $visibleOptionIds = array_flip(filter_visible_ids($context->conn, $otherConfig, $otherTable, $optionIds, $context->userId));
        $visibleSelected = array_flip(filter_visible_ids($context->conn, $otherConfig, $otherTable, $selected, $context->userId));
        $options = array_values(array_filter(
            $options,
            static fn(array $option): bool => isset($visibleOptionIds[(string) $option['id']])
        ));
        $selected = array_values(array_filter(
            $selected,
            static fn(string $selectedId): bool => isset($visibleSelected[$selectedId])
        ));
    }

    throw ResponseException::encoded(['options' => $options, 'selected' => $selected]);
}

function frontapi_m2m_rows(FrontApiContext $context): never
{
    $schema = $context->schema;
    $table  = $_GET['table']     ?? '';
    $m2mIndex = (int)($_GET['m2m_index'] ?? 0);
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
    if (!isset($m2mList[$m2mIndex])) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $config        = $m2mList[$m2mIndex];
    $junctionTable         = $config['junction_table'] ?? '';
    $selfFk     = $config['self_fk']        ?? '';
    $otherFk    = $config['other_fk']       ?? '';
    $otherTable = $config['other_table']    ?? '';
    $displayColumn = $config['display_column'] ?? 'id';

    if (
        !$junctionTable || !$selfFk || !$otherFk || !$otherTable
        || !isset($schema['tables'][$junctionTable], $schema['tables'][$otherTable])
    ) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $jtSchema = $schema['tables'][$junctionTable]['schema']         ?? 'public';
    $otSchema = $schema['tables'][$otherTable]['schema'] ?? 'public';
    $placeholders = implode(',', array_map(fn($placeholderIndex) => '$' . ($placeholderIndex + 1), array_keys($ids)));

    $sqlParameters  = $ids;
    $ownerSql = '';
    if (!empty($schema['tables'][$table]['owner_restricted'])) {
        $ownerSql  = owner_restriction_sql('j.' . pg_ident($selfFk), count($ids) + 1, count($ids) + 2);
        $sqlParameters[] = $table;
        $sqlParameters[] = $context->userId;
    }

    $sql = sprintf(
        'SELECT j.%s AS sid, o.%s AS label
           FROM %s.%s j
           JOIN %s.%s o ON o."id" = j.%s
          WHERE j.%s IN (%s)%s
          ORDER BY j.%s, o.%s',
        pg_ident($selfFk),
        pg_ident($displayColumn),
        pg_ident($jtSchema),
        pg_ident($junctionTable),
        pg_ident($otSchema),
        pg_ident($otherTable),
        pg_ident($otherFk),
        pg_ident($selfFk),
        $placeholders,
        $ownerSql,
        pg_ident($selfFk),
        pg_ident($displayColumn)
    );
    $result = @pg_query_params($context->conn, $sql, $sqlParameters);
    if (!$result) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $data = [];
    while ($row = pg_fetch_assoc($result)) {
        $sourceId = (string)$row['sid'];
        $data[$sourceId][] = (string)$row['label'];
    }

    throw ResponseException::encoded(['data' => $data ?: (object)[]]);
}

function frontapi_image_rows(FrontApiContext $context): never
{
    require_once __DIR__ . '/../images.php';
    $schema = $context->schema;
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

    $ids = filter_visible_ids($context->conn, $schema['tables'][$table], $table, $ids, $context->userId);
    if (empty($ids)) {
        throw ResponseException::encoded(['data' => (object)[]]);
    }

    $data = images_for_rows($context->conn, $table, $ids);
    throw ResponseException::encoded(['data' => $data ?: (object)[]]);
}
