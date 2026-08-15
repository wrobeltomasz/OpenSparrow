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

function frontapi_calendar(FrontApiContext $ctx): never
{
    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $calendar = config_get('calendar');
    if ($calendar === null) {
        throw ResponseException::encoded(['events' => []]);
    }

    $reqYear  = filter_var(
        $_GET['year'] ?? date('Y'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 9999]]
    );
    $reqMonth = filter_var(
        $_GET['month'] ?? date('n'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 12]]
    );
    if ($reqYear  === false) {
        $reqYear  = (int)date('Y');
    }
    if ($reqMonth === false) {
        $reqMonth = (int)date('n');
    }
    $dateFrom = sprintf('%04d-%02d-01', $reqYear, $reqMonth);
    $dateTo   = date('Y-m-t', mktime(0, 0, 0, $reqMonth, 1, $reqYear));

    $events = [];
    foreach ($calendar['sources'] ?? [] as $src) {
        $table = $src['table'] ?? '';
        if (!$table) {
            continue;
        }

        if (!user_can_access_table($table)) {
            continue;
        }

        try {
            $tableCfg = safe_table($schema, $table);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $e) {
            continue;
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $idCol = id_column();
        $titleCol = $src['title_column'] ?? $idCol;

        $subCol = $src['subtitle_column'] ?? '';
        if ($subCol !== '' && !isset($tableCfg['columns'][$subCol])) {
            $subCol = '';
        }
        $dateCol = $src['date_column'] ?? '';
        $color = $src['color'] ?? '#3b82f6';
        if (isset($tableCfg['columns'][$dateCol])) {
            $cols = column_list($tableCfg);
            $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));

            $selectSql = implode(', ', array_map(fn($column) => pg_ident($column), $selectCols));

            $qParams  = [$dateFrom, $dateTo];
            $ownerSql = '';
            if (!empty($tableCfg['owner_restricted'])) {
                $ownerSql  = owner_restriction_sql('_t.' . pg_ident($idCol), 3, 4);
                $qParams[] = $table;
                $qParams[] = $ctx->userId;
            }

            $sql = sprintf(
                'SELECT %s FROM %s.%s AS _t WHERE %s IS NOT NULL AND %s BETWEEN $1 AND $2%s',
                $selectSql,
                pg_ident($schemaName),
                pg_ident($table),
                pg_ident($dateCol),
                pg_ident($dateCol),
                $ownerSql
            );
            $res = @pg_query_params($conn, $sql, $qParams);
            if ($res) {
                $rows = [];
                while ($row = pg_fetch_assoc($res)) {
                    $rows[] = $row;
                }
                pg_free_result($res);
                $rows = map_fk_display($schema, $tableCfg, $rows, $conn);
                foreach ($rows as $row) {
                    $events[] = [
                        'id' => $row[$idCol],
                        'table' => $table,
                        'title' => $row[$titleCol] ?? 'No title',
                        'subtitle' => $subCol !== ''
                            ? (string)($row[$subCol . '__display'] ?? $row[$subCol] ?? '')
                            : '',
                        'date' => substr($row[$dateCol], 0, 10),
                        'color' => $color,
                        'icon' => $src['icon'] ?? null,
                        'rowData' => $row
                    ];
                }
            }
        }
    }

    echo json_encode([
        'menu_name' => $calendar['menu_name'] ?? 'Calendar',
        'menu_icon' => $calendar['menu_icon'] ?? '',
        'hidden' => !empty($calendar['hidden']),
        'events' => $events
    ]);
    throw ResponseException::sent();
}

function frontapi_calendar_move_event(FrontApiWriteContext $ctx): never
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

    $calConfig = config_get('calendar') ?? ['sources' => []];
    $sources = $calConfig['sources'] ?? [];

    $allowedTables = array_column($sources, 'table');
    if (!in_array($table, $allowedTables, true)) {
        throw new BadRequestException('Invalid table');
    }

    $id = (int)($body['id'] ?? 0);
    $newDate = $body['newDate'] ?? '';
    if ($id <= 0) {
        throw new BadRequestException('Invalid ID');
    }

    check_record_ownership($conn, $tableCfg, $table, $id, $ctx->userId);

    $dateIsValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) && checkdate(
        (int)substr($newDate, 5, 2),
        (int)substr($newDate, 8, 2),
        (int)substr($newDate, 0, 4)
    );
    if (!$dateIsValid) {
        throw new BadRequestException('Invalid date format');
    }

    $dateColumn = '';
    foreach ($sources as $source) {
        if ($source['table'] === $table) {
            $dateColumn = $source['date_column'];
            break;
        }
    }

    if ($dateColumn === '') {
        throw new BadRequestException('Missing date column config');
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dateColumn)) {
        throw new BadRequestException('Invalid column name');
    }

    $sql = sprintf(
        'UPDATE %s.%s SET %s = $1 WHERE %s = $2',
        pg_ident($schemaName),
        pg_ident($table),
        pg_ident($dateColumn),
        pg_ident($idCol)
    );
    $res = @pg_query_params($conn, $sql, [$newDate, $id]);
    if (!$res) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log('Calendar move_event error: ' . pg_last_error($conn));
        throw ResponseException::sent();
    }

    if (pg_affected_rows($res) === 0) {
        throw new NotFoundException('Record not found');
    }

    log_user_action($conn, $ctx->userId, 'CALENDAR_MOVE', $table, $id);

    throw ResponseException::encoded(['success' => true]);
}
