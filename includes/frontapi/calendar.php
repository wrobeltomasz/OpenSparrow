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

function frontapi_calendar(FrontApiContext $context): never
{
    $conn   = $context->conn;
    $schema = $context->schema;

    $calendar = config_get('calendar');
    if ($calendar === null) {
        throw ResponseException::encoded(['events' => []]);
    }

    $requestedYear  = filter_var(
        $_GET['year'] ?? date('Y'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 9999]]
    );
    $requestedMonth = filter_var(
        $_GET['month'] ?? date('n'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 12]]
    );
    if ($requestedYear  === false) {
        $requestedYear  = (int)date('Y');
    }
    if ($requestedMonth === false) {
        $requestedMonth = (int)date('n');
    }
    $dateFrom = sprintf('%04d-%02d-01', $requestedYear, $requestedMonth);
    $dateTo   = date('Y-m-t', mktime(0, 0, 0, $requestedMonth, 1, $requestedYear));

    $events = [];
    foreach ($calendar['sources'] ?? [] as $sourceEntry) {
        $table = $sourceEntry['table'] ?? '';
        if (!$table) {
            continue;
        }

        if (!user_can_access_table($table)) {
            continue;
        }

        try {
            $tableConfig = safe_table($schema, $table);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            continue;
        }

        $schemaName = $tableConfig['schema'] ?? 'public';
        $idColumn = id_column();
        $titleColumn = $sourceEntry['title_column'] ?? $idColumn;

        $subtitleColumn = $sourceEntry['subtitle_column'] ?? '';
        if ($subtitleColumn !== '' && !isset($tableConfig['columns'][$subtitleColumn])) {
            $subtitleColumn = '';
        }
        $dateColumn = $sourceEntry['date_column'] ?? '';
        $color = $sourceEntry['color'] ?? '#3b82f6';
        if (isset($tableConfig['columns'][$dateColumn])) {
            $columns = column_list($tableConfig);
            $selectColumns = array_values(array_unique(array_merge([$idColumn], $columns)));

            $selectSql = implode(', ', array_map(fn($column) => pg_ident($column), $selectColumns));

            $sqlParameters  = [$dateFrom, $dateTo];
            $ownerSql = '';
            if (!empty($tableConfig['owner_restricted'])) {
                $ownerSql  = owner_restriction_sql('_t.' . pg_ident($idColumn), 3, 4);
                $sqlParameters[] = $table;
                $sqlParameters[] = $context->userId;
            }

            $sql = sprintf(
                'SELECT %s FROM %s.%s AS _t WHERE %s IS NOT NULL AND %s BETWEEN $1 AND $2%s',
                $selectSql,
                pg_ident($schemaName),
                pg_ident($table),
                pg_ident($dateColumn),
                pg_ident($dateColumn),
                $ownerSql
            );
            $result = @pg_query_params($conn, $sql, $sqlParameters);
            if ($result) {
                $rows = [];
                while ($row = pg_fetch_assoc($result)) {
                    $rows[] = $row;
                }
                pg_free_result($result);
                $rows = map_fk_display($schema, $tableConfig, $rows, $conn);
                foreach ($rows as $row) {
                    $events[] = [
                        'id' => $row[$idColumn],
                        'table' => $table,
                        'title' => $row[$titleColumn] ?? 'No title',
                        'subtitle' => $subtitleColumn !== ''
                            ? (string)($row[$subtitleColumn . '__display'] ?? $row[$subtitleColumn] ?? '')
                            : '',
                        'date' => substr($row[$dateColumn], 0, 10),
                        'color' => $color,
                        'icon' => $sourceEntry['icon'] ?? null,
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

function frontapi_calendar_move_event(FrontApiWriteContext $context): never
{
    $conn       = $context->conn;
    $body       = $context->body;
    $table      = $context->table;
    $tableConfig   = $context->tableConfig;
    $schemaName = $context->schemaName;
    $idColumn   = $context->idColumn;

    if ($context->isViewer()) {
        throw new ForbiddenException('Forbidden');
    }

    $calendarConfig = config_get('calendar') ?? ['sources' => []];
    $sources = $calendarConfig['sources'] ?? [];

    $allowedTables = array_column($sources, 'table');
    if (!in_array($table, $allowedTables, true)) {
        throw new BadRequestException('Invalid table');
    }

    $id = (int)($body['id'] ?? 0);
    $newDate = $body['newDate'] ?? '';
    if ($id <= 0) {
        throw new BadRequestException('Invalid ID');
    }

    check_record_ownership($conn, $tableConfig, $table, $id, $context->userId);

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
        pg_ident($idColumn)
    );
    $result = @pg_query_params($conn, $sql, [$newDate, $id]);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log('Calendar move_event error: ' . pg_last_error($conn));
        throw ResponseException::sent();
    }

    if (pg_affected_rows($result) === 0) {
        throw new NotFoundException('Record not found');
    }

    log_user_action($conn, $context->userId, 'CALENDAR_MOVE', $table, $id);

    throw ResponseException::encoded(['success' => true]);
}
