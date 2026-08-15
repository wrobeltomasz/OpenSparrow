<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

require_once __DIR__ . '/../../includes/bootstrap.php';

os_api_bootstrap(['connect' => false]);

$role   = $_SESSION['role'] ?? 'viewer';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

require_once __DIR__ . '/../../includes/config_store.php';
$viewsConfig = config_get('views') ?? [];
$views       = $viewsConfig['views'] ?? [];

try {
    if ($action === 'list' && $method === 'GET') {
        $result = [];
        foreach ($views as $name => $cfg) {
            if (!empty($cfg['hidden'])) {
                continue;
            }

            if (!user_can_access_view((string) $name)) {
                continue;
            }
            $result[] = [
                'name'         => $name,
                'display_name' => $cfg['display_name'] ?? $name,
                'description'  => $cfg['description'] ?? '',
                'icon'         => $cfg['icon'] ?? '',
                'menu_name'    => $cfg['menu_name'] ?? ($cfg['display_name'] ?? $name),
            ];
        }
        throw ResponseException::encoded(['status' => 'ok', 'views' => $result]);
    }

    if ($action === 'config' && $method === 'GET' && $role === 'admin') {
        throw ResponseException::encoded(['status' => 'ok', 'config' => $viewsConfig]);
    }

    if ($action === 'data' && $method === 'GET') {
        $viewName = $_GET['view'] ?? '';
        if (!isset($views[$viewName])) {
            throw new NotFoundException('View not found');
        }
        require_view_access((string) $viewName);

        $cfg        = $views[$viewName];
        $conn       = db_connect();
        $schemaName = $cfg['schema'] ?? sys_schema();
        $level      = max(0, (int)($_GET['level'] ?? 0));
        $filterColumn  = $_GET['filter_col'] ?? '';
        $filterVal  = isset($_GET['filter_val']) ? $_GET['filter_val'] : null;

        $drillLevels = $cfg['drill_down']['levels'] ?? [];
        $groupBy     = null;
        if (!empty($drillLevels) && isset($drillLevels[$level])) {
            $groupBy = $drillLevels[$level]['group_by'] ?? null;
        }

        $params      = [];
        $whereClause = '';

        if ($filterColumn !== '' && $filterVal !== null) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterColumn)) {
                throw new BadRequestException('Invalid filter column');
            }
            $params[]    = $filterVal;
            $whereClause = 'WHERE ' . pg_ident($filterColumn) . ' = $1';
        }

        if ($groupBy !== null) {
            $colsCfg  = $cfg['columns'] ?? [];
            $aggParts = [];
            foreach ($colsCfg as $colName => $colCfg) {
                if ($colName === $groupBy) {
                    continue;
                }
                $aggregate = strtolower($colCfg['aggregate'] ?? '');
                if ($aggregate === 'count') {
                    $aggParts[] = 'COUNT(*) AS ' . pg_ident($colName);
                } elseif ($aggregate === 'sum') {
                    $aggParts[] = 'SUM(' . pg_ident($colName) . ') AS ' . pg_ident($colName);
                } elseif ($aggregate === 'avg') {
                    $aggParts[] = 'ROUND(AVG(' . pg_ident($colName) . ')::numeric, 2) AS ' . pg_ident($colName);
                }
            }

            $selectExtra = empty($aggParts) ? 'COUNT(*) AS _count' : implode(', ', $aggParts);
            $sql         = sprintf(
                'SELECT %s, %s FROM %s.%s %s GROUP BY %s ORDER BY 2 DESC LIMIT 1000',
                pg_ident($groupBy),
                $selectExtra,
                pg_ident($schemaName),
                pg_ident($viewName),
                $whereClause,
                pg_ident($groupBy)
            );
        } else {
            $sql = sprintf(
                'SELECT * FROM %s.%s %s LIMIT 1000',
                pg_ident($schemaName),
                pg_ident($viewName),
                $whereClause
            );
        }

        $queryResult = @pg_query_params($conn, $sql, $params);
        if (!$queryResult) {
            error_log('[api_views][data] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error');
        }

        $rows = pg_fetch_all($queryResult) ?: [];
        pg_free_result($queryResult);

        echo json_encode([
            'status'       => 'ok',
            'view'         => $viewName,
            'display_name' => $cfg['display_name'] ?? $viewName,
            'level'        => $level,
            'max_level'    => max(0, count($drillLevels) - 1),
            'group_by'     => $groupBy,
            'drill_enabled' => !empty($cfg['drill_down']['enabled']),
            'rows'         => $rows,
            'columns'      => $cfg['columns'] ?? [],
            'drill_down'   => $cfg['drill_down'] ?? ['enabled' => false, 'levels' => []],
            'group_rows'   => $cfg['group_rows'] ?? '',
            'icon'         => $cfg['icon'] ?? '',
        ]);
        throw ResponseException::sent();
    }

    if ($action === 'schemas' && $method === 'GET' && $role === 'admin') {
        $conn = db_connect();
        $sql  = 'SELECT schema_name FROM information_schema.schemata '
            . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema') "
            . "AND schema_name NOT LIKE 'pg\\_toast%' AND schema_name NOT LIKE 'pg\\_temp%' "
            . 'ORDER BY schema_name';
        $queryResult = @pg_query($conn, $sql);
        if (!$queryResult) {
            throw new ServerErrorException('Database error');
        }

        $schemas = [];
        while ($row = pg_fetch_assoc($queryResult)) {
            $schemas[] = $row['schema_name'];
        }
        pg_free_result($queryResult);

        $selected = is_array($viewsConfig['schemas'] ?? null) ? $viewsConfig['schemas'] : [];
        if (empty($selected)) {
            $selected = [sys_schema()];
        }

        throw ResponseException::encoded(['status' => 'ok', 'schemas' => $schemas, 'selected' => $selected]);
    }

    if ($action === 'sync' && $method === 'GET' && $role === 'admin') {
        $conn    = db_connect();
        $schemas = is_array($viewsConfig['schemas'] ?? null) ? $viewsConfig['schemas'] : [];
        $schemas = array_values(array_filter(array_map('strval', $schemas), fn($schemaName) => $schemaName !== ''));
        if (empty($schemas)) {
            $schemas = [sys_schema()];
        }

        $placeholders = implode(',', array_map(
            fn($placeholderIndex) => '$' . ($placeholderIndex + 1),
            array_keys($schemas)
        ));

        $sql = 'SELECT n.nspname AS table_schema, c.relname AS table_name, c.relkind '
            . 'FROM pg_catalog.pg_class c '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE c.relkind IN ('v', 'm') AND n.nspname IN ($placeholders) "
            . "AND pg_catalog.has_table_privilege(c.oid, 'SELECT') "
            . 'ORDER BY n.nspname, c.relname';
        $queryResult = @pg_query_params($conn, $sql, $schemas);
        if (!$queryResult) {
            throw new ServerErrorException('Database error');
        }

        $dbViews     = [];
        $viewSchemas = [];
        $viewKinds   = [];
        while ($row = pg_fetch_assoc($queryResult)) {
            $dbViews[]                       = $row['table_name'];
            $viewSchemas[$row['table_name']] = $row['table_schema'];
            $viewKinds[$row['table_name']]   = $row['relkind'] === 'm' ? 'materialized' : 'view';
        }
        pg_free_result($queryResult);

        $viewsColumns = [];
        foreach ($dbViews as $vName) {
            $colSql = 'SELECT a.attname AS column_name, '
                . 'pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type '
                . 'FROM pg_catalog.pg_attribute a '
                . 'JOIN pg_catalog.pg_class c ON c.oid = a.attrelid '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = $1 AND c.relname = $2 '
                . 'AND a.attnum > 0 AND NOT a.attisdropped '
                . 'ORDER BY a.attnum';
            $columnsResult = @pg_query_params($conn, $colSql, [$viewSchemas[$vName], $vName]);
            $columns   = [];
            if ($columnsResult) {
                while ($column = pg_fetch_assoc($columnsResult)) {
                    $columns[$column['column_name']] = ['data_type' => $column['data_type']];
                }
                pg_free_result($columnsResult);
            }
            $viewsColumns[$vName] = $columns;
        }

        echo json_encode([
            'status'       => 'ok',
            'db_views'     => $dbViews,
            'columns'      => $viewsColumns,
            'view_schemas' => $viewSchemas,
            'view_kinds'   => $viewKinds,
            'source'       => 'postgres',
        ]);
        throw ResponseException::sent();
    }

    if ($action === 'save' && $method === 'POST' && $role === 'admin') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['views'])) {
            throw new BadRequestException('Invalid payload');
        }

        $newConfig = ['views' => $body['views']];
        if (is_array($viewsConfig['schemas'] ?? null)) {
            $newConfig['schemas'] = $viewsConfig['schemas'];
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $result = config_save('views', $newConfig, null, $userId);
        if ($result['status'] !== 'ok') {
            $tooLarge = ($result['error'] ?? '') === 'Config too large';
            http_response_code($tooLarge ? 413 : 500);
            throw ResponseException::encoded(['error' => $result['error'] ?? 'Write failed']);
        }

        throw ResponseException::encoded(['status' => 'ok']);
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or insufficient permissions']);
} catch (ControlFlowException $signal) {
    throw $signal;
} catch (Throwable $exception) {
    error_log('[api_views][exception] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
