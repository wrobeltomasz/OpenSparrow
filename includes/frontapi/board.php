<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;

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
        throw ResponseException::encoded($meta);
    }

    if (!user_can_access_table($table)) {
        $meta['table']         = '';
        $meta['status_column'] = '';
        throw ResponseException::encoded($meta);
    }

    try {
        $tableCfg = safe_table($schema, $table);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        throw ResponseException::encoded($meta);
    }

    if (!isset($tableCfg['columns'][$statusCol])) {
        throw ResponseException::encoded($meta);
    }

    $schemaName   = $tableCfg['schema'] ?? 'public';
    $idCol        = id_column();
    $titleCol     = $boardCfg['title_column'] ?? '';
    if ($titleCol === '' || !isset($tableCfg['columns'][$titleCol])) {
        $titleCol = $idCol;
    }
    $defaultColor = $boardCfg['color'] ?? '#005A9E';

    $cardCols = [];
    foreach (($boardCfg['card_columns'] ?? []) as $column) {
        if (is_string($column) && isset($tableCfg['columns'][$column]) && $column !== $statusCol) {
            $cardCols[] = $column;
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
            while ($row = pg_fetch_assoc($rd)) {
                $val = (string)$row['v'];
                $lanes[] = ['value' => $val, 'label' => $val, 'color' => $defaultColor];
            }
            pg_free_result($rd);
        }
    }

    $cols       = column_list($tableCfg);
    $selectCols = array_values(array_unique(array_merge([$idCol, $statusCol, $titleCol], $cols)));
    $cards = [];
    $selectSql  = implode(', ', array_map(fn($column) => pg_ident($column), $selectCols));

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
        while ($row = pg_fetch_assoc($res)) {
            $rows[] = $row;
        }
        pg_free_result($res);
    }
    $rows = map_fk_display($schema, $tableCfg, $rows, $conn);
    foreach ($rows as $row) {
        $fields = [];
        foreach ($cardCols as $column) {
            $label = $tableCfg['columns'][$column]['display_name'] ?? $column;
            $value = $row[$column . '__display'] ?? $row[$column] ?? '';
            if ($value === null || $value === '') {
                continue;
            }
            $fields[] = ['label' => $label, 'value' => $value];
        }
        $cards[] = [
            'id'      => $row[$idCol],
            'status'  => (string)($row[$statusCol] ?? ''),
            'title'   => $row[$titleCol . '__display'] ?? $row[$titleCol] ?? ('#' . $row[$idCol]),
            'fields'  => $fields,
            'rowData' => $row,
        ];
    }

    $meta['configured']    = true;
    $meta['title_column']  = $titleCol;
    $meta['default_color'] = $defaultColor;
    $meta['status_label']  = $statusDef['display_name'] ?? $statusCol;
    $meta['table_label']   = $tableCfg['display_name'] ?? $table;
    $meta['columns']       = $lanes;
    $meta['cards']         = $cards;
    throw ResponseException::encoded($meta);
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
        throw new ForbiddenException('Forbidden');
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
        throw new BadRequestException('Invalid board table');
    }
    if (!isset($tableCfg['columns'][$statusCol])) {
        throw new BadRequestException('Invalid status column');
    }

    $id        = (int)($body['id'] ?? 0);
    $newStatus = (string)($body['newStatus'] ?? '');
    if ($id <= 0) {
        throw new BadRequestException('Invalid ID');
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
            while ($row = pg_fetch_assoc($rD)) {
                $allowed[] = (string)$row['v'];
            }
            pg_free_result($rD);
        }
    }
    if (!in_array($newStatus, $allowed, true)) {
        throw new BadRequestException('Invalid status value');
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
        throw ResponseException::sent();
    }
    if (pg_affected_rows($res) === 0) {
        throw new NotFoundException('Record not found');
    }

    log_user_action($conn, $ctx->userId, 'BOARD_MOVE', $table, $id);
    throw ResponseException::encoded(['success' => true]);
}
