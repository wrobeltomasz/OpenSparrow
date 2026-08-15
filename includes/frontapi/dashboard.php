<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_dashboard(FrontApiContext $ctx): never
{
    require_once __DIR__ . '/../dashboard_query.php';

    $conn   = $ctx->conn;
    $schema = $ctx->schema;

    $dashboard = config_get('dashboard');
    if ($dashboard === null) {
        echo json_encode(['layout' => [], 'widgets' => []]);
        exit;
    }

    $response = [
        'menu_name' => $dashboard['menu_name'] ?? 'Dashboard',
        'menu_icon' => $dashboard['menu_icon'] ?? '',
        'hidden' => !empty($dashboard['hidden']),
        'layout' => $dashboard['layout'] ?? [],
        'widgets' => []
    ];
    foreach ($dashboard['widgets'] ?? [] as $widget) {
        $table = $widget['table'] ?? '';
        if (!$table) {
            continue;
        }

        if (!user_can_access_table($table)) {
            continue;
        }

        try {
            $tableCfg = safe_table($schema, $table);
        } catch (Throwable $e) {
            continue;
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $qType = $widget['query']['type'] ?? 'list';
        $data = null;
        $sqlWhere = '';

        $conditions = is_array($widget['query']['conditions'] ?? null) ? $widget['query']['conditions'] : [];

        $condSql = dashboard_conditions_sql($conn, $tableCfg, $conditions);

        $dateFilter = $_GET['date_filter'] ?? 'all';
        $dateTarget = $_GET['date_target'] ?? 'all';
        $widgetTargetId = $widget['id'] ?? $widget['table'] ?? '';
        $dateSqlCur  = null;
        $dateSqlPrev = null;
        if ($dateFilter !== 'all' && ($dateTarget === 'all' || $dateTarget === $widgetTargetId)) {
            $dateCol = array_find_key($tableCfg['columns'], static function (array $cCfg): bool {
                $cType = strtolower($cCfg['type'] ?? '');
                return str_contains($cType, 'date') || str_contains($cType, 'time');
            });

            if ($dateCol) {
                $dc = pg_ident($dateCol);
                [$dateSqlCur, $dateSqlPrev] = match ($dateFilter) {
                    'today' => [
                        $dc . ' >= CURRENT_DATE',
                        '(' . $dc . " >= CURRENT_DATE - INTERVAL '1 day' AND " . $dc . ' < CURRENT_DATE)',
                    ],
                    '7d' => [
                        $dc . " >= CURRENT_DATE - INTERVAL '7 days'",
                        '(' . $dc . " >= CURRENT_DATE - INTERVAL '14 days' AND " . $dc
                            . " < CURRENT_DATE - INTERVAL '7 days')",
                    ],
                    '30d' => [
                        $dc . " >= CURRENT_DATE - INTERVAL '30 days'",
                        '(' . $dc . " >= CURRENT_DATE - INTERVAL '60 days' AND " . $dc
                            . " < CURRENT_DATE - INTERVAL '30 days')",
                    ],
                    'this_month' => [
                        "DATE_TRUNC('month', " . $dc . ") = DATE_TRUNC('month', CURRENT_DATE)",
                        "DATE_TRUNC('month', " . $dc . ") = DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month'",
                    ],
                    default => [null, null],
                };
            }
        }

        $whereParts = array_values(array_filter([$condSql, $dateSqlCur ?? '']));
        $sqlWhere = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $sqlWherePrev = null;
        if ($dateSqlPrev !== null) {
            $prevParts = array_values(array_filter([$condSql, $dateSqlPrev]));
            $sqlWherePrev = ' WHERE ' . implode(' AND ', $prevParts);
        }

        $result = dashboard_run_widget_query(
            $conn,
            $tableCfg,
            $schemaName,
            $table,
            $widget['query'] ?? [],
            $widget['display_columns'] ?? [id_column()],
            $sqlWhere
        );
        $data = $result['data'];
        if (isset($result['sql_error'])) {
            $widget['sql_error'] = $result['sql_error'];
        }
        if (isset($result['column_type'])) {
            $widget['column_type'] = $result['column_type'];
        }
        if (isset($result['column_types'])) {
            $widget['column_types'] = $result['column_types'];
        }

        if ($sqlWherePrev !== null && in_array($qType, ['count', 'sum', 'avg'], true) && !isset($result['sql_error'])) {
            $prevResult = dashboard_run_widget_query(
                $conn,
                $tableCfg,
                $schemaName,
                $table,
                $widget['query'] ?? [],
                $widget['display_columns'] ?? [id_column()],
                $sqlWherePrev
            );
            if (!isset($prevResult['sql_error'])) {
                $widget['prev_data'] = $prevResult['data'];
            }
        }

        $widget['data'] = $data;
        $response['widgets'][] = $widget;
    }

    echo json_encode($response);
    exit;
}
