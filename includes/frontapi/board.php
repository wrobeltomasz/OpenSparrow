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

function frontapi_board(FrontApiContext $context): never
{
    $conn   = $context->conn;
    $schema = $context->schema;

    $boardsConfig = config_get('board') ?? [];
    $boardId   = substr($_GET['board'] ?? '', 0, 64);

    $boards   = filter_by_user_access('boards', $boardsConfig['boards'] ?? []);
    $boardConfig = null;
    foreach ($boards as $board) {
        if (($board['id'] ?? '') === $boardId) {
            $boardConfig = $board;
            break;
        }
    }
    if ($boardConfig === null) {
        $boardConfig = $boards[0] ?? [];
    }

    $meta = [
        'menu_name'     => $boardConfig['menu_name'] ?? 'Board',
        'menu_icon'     => $boardConfig['menu_icon'] ?? '',
        'hidden'        => !empty($boardConfig['hidden']),
        'configured'    => false,
        'table'         => $boardConfig['table'] ?? '',
        'status_column' => $boardConfig['status_column'] ?? '',
        'columns'       => [],
        'cards'         => [],
        'can_edit'      => !$context->isViewer(),
    ];

    $table     = $boardConfig['table'] ?? '';
    $statusColumn = $boardConfig['status_column'] ?? '';
    if ($table === '' || $statusColumn === '') {
        throw ResponseException::encoded($meta);
    }

    if (!user_can_access_table($table)) {
        $meta['table']         = '';
        $meta['status_column'] = '';
        throw ResponseException::encoded($meta);
    }

    try {
        $tableConfig = safe_table($schema, $table);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        throw ResponseException::encoded($meta);
    }

    if (!isset($tableConfig['columns'][$statusColumn])) {
        throw ResponseException::encoded($meta);
    }

    $schemaName   = $tableConfig['schema'] ?? 'public';
    $idColumn        = id_column();
    $titleColumn     = $boardConfig['title_column'] ?? '';
    if ($titleColumn === '' || !isset($tableConfig['columns'][$titleColumn])) {
        $titleColumn = $idColumn;
    }
    $defaultColor = $boardConfig['color'] ?? '#005A9E';

    $cardColumns = [];
    foreach (($boardConfig['card_columns'] ?? []) as $column) {
        if (is_string($column) && isset($tableConfig['columns'][$column]) && $column !== $statusColumn) {
            $cardColumns[] = $column;
        }
    }

    $statusDefinition  = $tableConfig['columns'][$statusColumn];
    $statusType = strtolower($statusDefinition['type'] ?? '');
    $enumColors = is_array($statusDefinition['enum_colors'] ?? null) ? $statusDefinition['enum_colors'] : [];
    $lanes      = [];
    if ($statusType === 'enum' && is_array($statusDefinition['options'] ?? null)) {
        foreach ($statusDefinition['options'] as $option) {
            $laneValue = (string)$option;
            $lanes[] = [
                'value' => $laneValue,
                'label' => $laneValue,
                'color' => $enumColors[$laneValue] ?? $defaultColor,
            ];
        }
    } else {
        $laneParameters = [];
        $laneOwner  = '';
        if (!empty($tableConfig['owner_restricted'])) {
            $laneOwner  = owner_restriction_sql('_t.' . pg_ident($idColumn), 1, 2);
            $laneParameters = [$table, $context->userId];
        }
        $sqlDistinct = sprintf(
            'SELECT DISTINCT %s AS v FROM %s.%s AS _t WHERE %s IS NOT NULL%s ORDER BY 1',
            pg_ident($statusColumn),
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($statusColumn),
            $laneOwner
        );
        $laneDistinctResult = @pg_query_params($conn, $sqlDistinct, $laneParameters);
        if ($laneDistinctResult) {
            while ($row = pg_fetch_assoc($laneDistinctResult)) {
                $laneValue = (string)$row['v'];
                $lanes[] = ['value' => $laneValue, 'label' => $laneValue, 'color' => $defaultColor];
            }
            pg_free_result($laneDistinctResult);
        }
    }

    $columns       = column_list($tableConfig);
    $selectColumns = array_values(array_unique(array_merge([$idColumn, $statusColumn, $titleColumn], $columns)));
    $cards = [];
    $selectSql  = implode(', ', array_map(fn($column) => pg_ident($column), $selectColumns));

    $cardParameters = [];
    $cardWhere  = '';
    if (!empty($tableConfig['owner_restricted'])) {
        $cardWhere  = ' WHERE TRUE' . owner_restriction_sql('_t.' . pg_ident($idColumn), 1, 2);
        $cardParameters = [$table, $context->userId];
    }
    $sql = sprintf(
        'SELECT %s FROM %s.%s AS _t%s ORDER BY %s DESC',
        $selectSql,
        pg_ident($schemaName),
        pg_ident($table),
        $cardWhere,
        pg_ident($idColumn)
    );
    $result  = @pg_query_params($conn, $sql, $cardParameters);
    $rows = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        pg_free_result($result);
    }
    $rows = map_fk_display($schema, $tableConfig, $rows, $conn);
    foreach ($rows as $row) {
        $fields = [];
        foreach ($cardColumns as $column) {
            $label = $tableConfig['columns'][$column]['display_name'] ?? $column;
            $value = $row[$column . '__display'] ?? $row[$column] ?? '';
            if ($value === null || $value === '') {
                continue;
            }
            $fields[] = ['label' => $label, 'value' => $value];
        }
        $cards[] = [
            'id'      => $row[$idColumn],
            'status'  => (string)($row[$statusColumn] ?? ''),
            'title'   => $row[$titleColumn . '__display'] ?? $row[$titleColumn] ?? ('#' . $row[$idColumn]),
            'fields'  => $fields,
            'rowData' => $row,
        ];
    }

    $meta['configured']    = true;
    $meta['title_column']  = $titleColumn;
    $meta['default_color'] = $defaultColor;
    $meta['status_label']  = $statusDefinition['display_name'] ?? $statusColumn;
    $meta['table_label']   = $tableConfig['display_name'] ?? $table;
    $meta['columns']       = $lanes;
    $meta['cards']         = $cards;
    throw ResponseException::encoded($meta);
}

function frontapi_board_move_card(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableConfig   = $context->tableCfg;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;

    if ($context->isViewer()) {
        throw new ForbiddenException('Forbidden');
    }

    $boardsConfig = config_get('board') ?? [];
    $boardId   = substr($body['board'] ?? '', 0, 64);

    $boardConfig  = null;
    foreach (filter_by_user_access('boards', $boardsConfig['boards'] ?? []) as $board) {
        if (($board['id'] ?? '') === $boardId) {
            $boardConfig = $board;
            break;
        }
    }
    $boardConfig  = $boardConfig ?? [];
    $configTable  = $boardConfig['table'] ?? '';
    $statusColumn = $boardConfig['status_column'] ?? '';

    if ($configTable === '' || $statusColumn === '' || $table !== $configTable) {
        throw new BadRequestException('Invalid board table');
    }
    if (!isset($tableConfig['columns'][$statusColumn])) {
        throw new BadRequestException('Invalid status column');
    }

    $id        = (int)($body['id'] ?? 0);
    $newStatus = (string)($body['newStatus'] ?? '');
    if ($id <= 0) {
        throw new BadRequestException('Invalid ID');
    }

    $statusDefinition  = $tableConfig['columns'][$statusColumn];
    $statusType = strtolower($statusDefinition['type'] ?? '');
    $allowed    = [];
    if ($statusType === 'enum' && is_array($statusDefinition['options'] ?? null)) {
        $allowed = array_map('strval', $statusDefinition['options']);
    } else {
        $distinctSql = sprintf(
            'SELECT DISTINCT %s AS v FROM %s.%s WHERE %s IS NOT NULL',
            pg_ident($statusColumn),
            pg_ident($schemaName),
            pg_ident($table),
            pg_ident($statusColumn)
        );
        $distinctResult = @pg_query($conn, $distinctSql);
        if ($distinctResult) {
            while ($row = pg_fetch_assoc($distinctResult)) {
                $allowed[] = (string)$row['v'];
            }
            pg_free_result($distinctResult);
        }
    }
    if (!in_array($newStatus, $allowed, true)) {
        throw new BadRequestException('Invalid status value');
    }

    check_record_ownership($conn, $tableConfig, $table, $id, $context->userId);

    $sql = sprintf(
        'UPDATE %s.%s SET %s = $1 WHERE %s = $2',
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($statusColumn),
        pg_ident($idColumn)
    );
    $result = @pg_query_params($conn, $sql, [$newStatus, $id]);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log('Board move_card error: ' . pg_last_error($conn));
        throw ResponseException::sent();
    }
    if (pg_affected_rows($result) === 0) {
        throw new NotFoundException('Record not found');
    }

    log_user_action($conn, $context->userId, 'BOARD_MOVE', $table, $id);
    throw ResponseException::encoded(['success' => true]);
}
