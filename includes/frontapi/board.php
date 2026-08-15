<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_board(FrontApiContext $ctx): never
{
    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $boardsCfg = config_get('board') ?? [];
    $boardId   = substr($_GET['board'] ?? '', 0, 64);

    $boards   = filter_by_user_access('boards', $boardsCfg['boards'] ?? []);
    $boardCfg = null;
    foreach ($boards as $b) {
        if (($b['id'] ?? '') === $boardId) {
            $boardCfg = $b;
            break;
        }
    }
    if ($boardCfg === null) {
        $boardCfg = $boards[0] ?? [];
    }

    $meta = [
        'menu_name'     => $boardCfg['menu_name'] ?? 'Board',
        'menu_icon'     => $boardCfg['menu_icon'] ?? '',
        'hidden'        => !empty($boardCfg['hidden']),
        'configured'    => false,
        'table'         => $boardCfg['table'] ?? '',
        'status_column' => $boardCfg['status_column'] ?? '',
        'columns'       => [],
        'cards'         => [],
        'can_edit'      => !$ctx->isViewer(),
    ];

    $table     = $boardCfg['table'] ?? '';
    $statusCol = $boardCfg['status_column'] ?? '';
    if ($table === '' || $statusCol === '') {
        echo json_encode($meta);
        exit;
    }

    if (!user_can_access_table($table)) {
        $meta['table']         = '';
        $meta['status_column'] = '';
        echo json_encode($meta);
        exit;
    }

    try {
        $tableCfg = safe_table($schema, $table);
    } catch (Throwable $e) {
        echo json_encode($meta);
        exit;
    }

    if (!isset($tableCfg['columns'][$statusCol])) {
        echo json_encode($meta);
        exit;
    }

    $schemaName   = $tableCfg['schema'] ?? 'public';
    $idCol        = id_column();
    $titleCol     = $boardCfg['title_column'] ?? '';
    if ($titleCol === '' || !isset($tableCfg['columns'][$titleCol])) {
        $titleCol = $idCol;
    }
    $defaultColor = $boardCfg['color'] ?? '#005A9E';

    $cardCols = [];
    foreach (($boardCfg['card_columns'] ?? []) as $c) {
        if (is_string($c) && isset($tableCfg['columns'][$c]) && $c !== $statusCol) {
            $cardCols[] = $c;
        }
    }

    $statusDef  = $tableCfg['columns'][$statusCol];
    $statusType = strtolower($statusDef['type'] ?? '');
    $enumColors = is_array($statusDef['enum_colors'] ?? null) ? $statusDef['enum_colors'] : [];
    $lanes      = [];
    if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
        foreach ($statusDef['options'] as $opt) {
            $val = (string)$opt;
            $lanes[] = [
                'value' => $val,
                'label' => $val,
                'color' => $enumColors[$val] ?? $defaultColor,
            ];
        }
    } else {
        $laneParams = [];
        $laneOwner  = '';
        if (!empty($tableCfg['owner_restricted'])) {
            $laneOwner  = owner_restriction_sql('_t.' . pg_ident($idCol), 1, 2);
            $laneParams = [$table, $ctx->userId];
        }
        $sqlDistinct = sprintf(
            'SELECT DISTINCT %s AS v FROM %s.%s AS _t WHERE %s IS NOT NULL%s ORDER BY 1',
            pg_ident($statusCol),
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($statusCol),
            $laneOwner
        );
        $rd = @pg_query_params($conn, $sqlDistinct, $laneParams);
        if ($rd) {
            while ($r = pg_fetch_assoc($rd)) {
                $val = (string)$r['v'];
                $lanes[] = ['value' => $val, 'label' => $val, 'color' => $defaultColor];
            }
            pg_free_result($rd);
        }
    }

    $cols       = column_list($tableCfg);
    $selectCols = array_values(array_unique(array_merge([$idCol, $statusCol, $titleCol], $cols)));
    $cards = [];
    $selectSql  = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));

    $cardParams = [];
    $cardWhere  = '';
    if (!empty($tableCfg['owner_restricted'])) {
        $cardWhere  = ' WHERE TRUE' . owner_restriction_sql('_t.' . pg_ident($idCol), 1, 2);
        $cardParams = [$table, $ctx->userId];
    }
    $sql = sprintf(
        'SELECT %s FROM %s.%s AS _t%s ORDER BY %s DESC',
        $selectSql,
        pg_ident($schemaName),
        pg_ident($table),
        $cardWhere,
        pg_ident($idCol)
    );
    $res  = @pg_query_params($conn, $sql, $cardParams);
    $rows = [];
    if ($res) {
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        pg_free_result($res);
    }
    $rows = map_fk_display($schema, $tableCfg, $rows);
    foreach ($rows as $r) {
        $fields = [];
        foreach ($cardCols as $c) {
            $label = $tableCfg['columns'][$c]['display_name'] ?? $c;
            $value = $r[$c . '__display'] ?? $r[$c] ?? '';
            if ($value === null || $value === '') {
                continue;
            }
            $fields[] = ['label' => $label, 'value' => $value];
        }
        $cards[] = [
            'id'      => $r[$idCol],
            'status'  => (string)($r[$statusCol] ?? ''),
            'title'   => $r[$titleCol . '__display'] ?? $r[$titleCol] ?? ('#' . $r[$idCol]),
            'fields'  => $fields,
            'rowData' => $r,
        ];
    }

    $meta['configured']    = true;
    $meta['title_column']  = $titleCol;
    $meta['default_color'] = $defaultColor;
    $meta['status_label']  = $statusDef['display_name'] ?? $statusCol;
    $meta['table_label']   = $tableCfg['display_name'] ?? $table;
    $meta['columns']       = $lanes;
    $meta['cards']         = $cards;
    echo json_encode($meta);
    exit;
}

function frontapi_board_move_card(FrontApiWriteContext $ctx): never
{
    $conn       = $ctx->conn;
    $body       = $ctx->body;
    $table      = $ctx->table;
    $tableCfg   = $ctx->tableCfg;
    $schemaName = $ctx->schemaName;
    $idCol      = $ctx->idCol;

    if ($ctx->isViewer()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $boardsCfg = config_get('board') ?? [];
    $boardId   = substr($body['board'] ?? '', 0, 64);

    $boardCfg  = null;
    foreach (filter_by_user_access('boards', $boardsCfg['boards'] ?? []) as $b) {
        if (($b['id'] ?? '') === $boardId) {
            $boardCfg = $b;
            break;
        }
    }
    $boardCfg  = $boardCfg ?? [];
    $cfgTable  = $boardCfg['table'] ?? '';
    $statusCol = $boardCfg['status_column'] ?? '';

    if ($cfgTable === '' || $statusCol === '' || $table !== $cfgTable) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid board table']);
        exit;
    }
    if (!isset($tableCfg['columns'][$statusCol])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status column']);
        exit;
    }

    $id        = (int)($body['id'] ?? 0);
    $newStatus = (string)($body['newStatus'] ?? '');
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }

    $statusDef  = $tableCfg['columns'][$statusCol];
    $statusType = strtolower($statusDef['type'] ?? '');
    $allowed    = [];
    if ($statusType === 'enum' && is_array($statusDef['options'] ?? null)) {
        $allowed = array_map('strval', $statusDef['options']);
    } else {
        $sqlD = sprintf(
            'SELECT DISTINCT %s AS v FROM %s.%s WHERE %s IS NOT NULL',
            pg_ident($statusCol),
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($statusCol)
        );
        $rD = @pg_query($conn, $sqlD);
        if ($rD) {
            while ($r = pg_fetch_assoc($rD)) {
                $allowed[] = (string)$r['v'];
            }
            pg_free_result($rD);
        }
    }
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status value']);
        exit;
    }

    check_record_ownership($conn, $tableCfg, $table, $id, $ctx->userId);

    $sql = sprintf(
        'UPDATE %s.%s SET %s = $1 WHERE %s = $2',
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($statusCol),
        pg_ident($idCol)
    );
    $res = @pg_query_params($conn, $sql, [$newStatus, $id]);
    if (!$res) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log('Board move_card error: ' . pg_last_error($conn));
        exit;
    }
    if (pg_affected_rows($res) === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found']);
        exit;
    }

    log_user_action($conn, $ctx->userId, 'BOARD_MOVE', $table, $id);
    echo json_encode(['success' => true]);
    exit;
}
