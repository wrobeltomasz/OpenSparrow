<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

function frontapi_dashboard(FrontApiContext $context): never
{
    require_once __DIR__ . '/../dashboard_query.php';

    $conn   = $context->conn;
    $schema = $context->schema;

    $dashboard = config_get('dashboard');
    if ($dashboard === null) {
        throw ResponseException::encoded(['layout' => [], 'widgets' => []]);
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
            $tableConfig = safe_table($schema, $table);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            continue;
        }

        $schemaName = $tableConfig['schema'] ?? 'public';
        $queryType = $widget['query']['type'] ?? 'list';
        $data = null;
        $sqlWhere = '';

        $conditions = is_array($widget['query']['conditions'] ?? null) ? $widget['query']['conditions'] : [];

        $conditionsSql = dashboard_conditions_sql($conn, $tableConfig, $conditions);

        $dateFilter = $_GET['date_filter'] ?? 'all';
        $dateTarget = $_GET['date_target'] ?? 'all';
        $widgetTargetId = $widget['id'] ?? $widget['table'] ?? '';
        $dateSqlCurrent  = null;
        $previousDateSql = null;
        if ($dateFilter !== 'all' && ($dateTarget === 'all' || $dateTarget === $widgetTargetId)) {
            $dateColumn = array_find_key($tableConfig['columns'], static function (array $calendarConfig): bool {
                $columnType = strtolower($calendarConfig['type'] ?? '');
                return str_contains($columnType, 'date') || str_contains($columnType, 'time');
            });

            if ($dateColumn) {
                $dateColumnIdentifier = pg_ident($dateColumn);
                [$dateSqlCurrent, $previousDateSql] = match ($dateFilter) {
                    'today' => [
                        $dateColumnIdentifier . ' >= CURRENT_DATE',
                        '(' . $dateColumnIdentifier . " >= CURRENT_DATE - INTERVAL '1 day' AND " . $dateColumnIdentifier
                            . ' < CURRENT_DATE)',
                    ],
                    '7d' => [
                        $dateColumnIdentifier . " >= CURRENT_DATE - INTERVAL '7 days'",
                        '(' . $dateColumnIdentifier . " >= CURRENT_DATE - INTERVAL '14 days' AND "
                            . $dateColumnIdentifier . " < CURRENT_DATE - INTERVAL '7 days')",
                    ],
                    '30d' => [
                        $dateColumnIdentifier . " >= CURRENT_DATE - INTERVAL '30 days'",
                        '(' . $dateColumnIdentifier . " >= CURRENT_DATE - INTERVAL '60 days' AND "
                            . $dateColumnIdentifier . " < CURRENT_DATE - INTERVAL '30 days')",
                    ],
                    'this_month' => [
                        "DATE_TRUNC('month', " . $dateColumnIdentifier . ") = DATE_TRUNC('month', CURRENT_DATE)",
                        "DATE_TRUNC('month', " . $dateColumnIdentifier
                            . ") = DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month'",
                    ],
                    default => [null, null],
                };
            }
        }

        $whereParts = array_values(array_filter([$conditionsSql, $dateSqlCurrent ?? '']));
        $sqlWhere = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $previousWhereSql = null;
        if ($previousDateSql !== null) {
            $previousParts = array_values(array_filter([$conditionsSql, $previousDateSql]));
            $previousWhereSql = ' WHERE ' . implode(' AND ', $previousParts);
        }

        $result = dashboard_run_widget_query(
            $conn,
            $tableConfig,
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

        if (
            $previousWhereSql !== null
            && in_array($queryType, ['count', 'sum', 'avg'], true)
            && !isset($result['sql_error'])
        ) {
            $previousResult = dashboard_run_widget_query(
                $conn,
                $tableConfig,
                $schemaName,
                $table,
                $widget['query'] ?? [],
                $widget['display_columns'] ?? [id_column()],
                $previousWhereSql
            );
            if (!isset($previousResult['sql_error'])) {
                $widget['prev_data'] = $previousResult['data'];
            }
        }

        $widget['data'] = $data;
        $response['widgets'][] = $widget;
    }

    throw ResponseException::encoded($response);
}
