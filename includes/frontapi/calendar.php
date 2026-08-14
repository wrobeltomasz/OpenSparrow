<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/calendar.php — frontend API route module: the calendar's read
// (GET ?api=calendar) and its drag-and-drop write (POST api=calendar,
// action=move_event). Read and write live together because they are one feature:
// both resolve against the same configured source list.
//
// Dispatched by public/api.php AFTER the auth gate, the admin/viewer role gates and
// the schema load. The write route additionally runs behind the shared write
// preamble, which already resolved and gated $ctx->table — it must not repeat
// require_table_access().

/**
 * Events of one month, gathered across every configured calendar source.
 */
function frontapi_calendar(FrontApiContext $ctx): never
{
    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $calendar = config_get('calendar');
    if ($calendar === null) {
        echo json_encode(['events' => []]);
        exit;
    }

    // Accept optional year/month params so the frontend can request only the
    // visible month. Fall back to the current month when omitted.
    $reqYear  = filter_var($_GET['year']  ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
    $reqMonth = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
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
        // Config-supplied: drop the source, keep the rest of the calendar.
        if (!user_can_access_table($table)) {
            continue;
        }

        try {
            $tableCfg = safe_table($schema, $table);
        } catch (Throwable $e) {
            continue;
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $idCol = id_column();
        $titleCol = $src['title_column'] ?? $idCol;
        // Optional second column rendered after the title on the event tile.
        // Ignored when unset or pointing at a column the table no longer has.
        $subCol = $src['subtitle_column'] ?? '';
        if ($subCol !== '' && !isset($tableCfg['columns'][$subCol])) {
            $subCol = '';
        }
        $dateCol = $src['date_column'] ?? '';
        $color = $src['color'] ?? '#3b82f6';
        if (isset($tableCfg['columns'][$dateCol])) {
            $cols = column_list($tableCfg);
            $selectCols = array_values(array_unique(array_merge([$idCol], $cols)));

            $selectSql = implode(', ', array_map(fn($c) => pg_ident($c), $selectCols));

            // Same row-level ownership rule as api=list, applied per source table:
            // a calendar must not surface events off records the user cannot see.
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
                while ($r = pg_fetch_assoc($res)) {
                    $rows[] = $r;
                }
                pg_free_result($res);
                $rows = map_fk_display($schema, $tableCfg, $rows);
                foreach ($rows as $r) {
                    $events[] = [
                        'id' => $r[$idCol],
                        'table' => $table,
                        'title' => $r[$titleCol] ?? 'No title',
                        'subtitle' => $subCol !== ''
                            ? (string)($r[$subCol . '__display'] ?? $r[$subCol] ?? '')
                            : '',
                        'date' => substr($r[$dateCol], 0, 10),
                        'color' => $color,
                        'icon' => $src['icon'] ?? null,
                        'rowData' => $r
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
    exit;
}

/**
 * Drag-and-drop: move one event to a new date.
 */
function frontapi_calendar_move_event(FrontApiWriteContext $ctx): never
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

    // Load calendar configuration to validate source tables
    $calConfig = config_get('calendar') ?? ['sources' => []];
    $sources = $calConfig['sources'] ?? [];
    // Whitelist payload table against configured calendar sources
    $allowedTables = array_column($sources, 'table');
    if (!in_array($table, $allowedTables, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid table']);
        exit;
    }

    $id = (int)($body['id'] ?? 0);
    $newDate = $body['newDate'] ?? '';
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }

    // Owner-restricted: prevent moving a record owned by someone else.
    check_record_ownership($conn, $tableCfg, $table, $id, $ctx->userId);

    // Validate strict YYYY-MM-DD date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !checkdate((int)substr($newDate, 5, 2), (int)substr($newDate, 8, 2), (int)substr($newDate, 0, 4))) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit;
    }

    // Get date column for specific table configuration
    $dateColumn = '';
    foreach ($sources as $source) {
        if ($source['table'] === $table) {
            $dateColumn = $source['date_column'];
            break;
        }
    }

    if ($dateColumn === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date column config']);
        exit;
    }

    // Perform safety regex check on column identifier
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dateColumn)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid column name']);
        exit;
    }

    // Update record via native pg_query_params for robust SQL injection prevention
    $sql = sprintf('UPDATE %s.%s SET %s = $1 WHERE %s = $2', pg_ident($schemaName), pg_ident($table), pg_ident($dateColumn), pg_ident($idCol));
    $res = @pg_query_params($conn, $sql, [$newDate, $id]);
    if (!$res) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log('Calendar move_event error: ' . pg_last_error($conn));
        exit;
    }

    if (pg_affected_rows($res) === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found']);
        exit;
    }

    log_user_action($conn, $ctx->userId, 'CALENDAR_MOVE', $table, $id);

    echo json_encode(['success' => true]);
    exit;
}
