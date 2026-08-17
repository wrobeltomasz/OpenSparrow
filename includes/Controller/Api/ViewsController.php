<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Service\AppContext;
use Throwable;

final class ViewsController
{
    private readonly array $viewsConfig;

    private readonly array $views;

    public function __construct(private readonly AppContext $context)
    {
        $this->viewsConfig = config_get('views') ?? [];
        $this->views       = $this->viewsConfig['views'] ?? [];
    }

    public function handle(): void
    {
        $request = $this->context->request();
        $role    = $this->context->session()->role();
        $action  = $request->query('action');
        $method  = $request->method();

        try {
            if ($action === 'list' && $method === 'GET') {
                $this->listViews();
            }

            if ($action === 'config' && $method === 'GET' && $role === 'admin') {
                throw ResponseException::encoded(['status' => 'ok', 'config' => $this->viewsConfig]);
            }

            if ($action === 'data' && $method === 'GET') {
                $this->viewData();
            }

            if ($action === 'schemas' && $method === 'GET' && $role === 'admin') {
                $this->databaseSchemas();
            }

            if ($action === 'sync' && $method === 'GET' && $role === 'admin') {
                $this->syncViews();
            }

            if ($action === 'save' && $method === 'POST' && $role === 'admin') {
                $this->saveConfig();
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
    }

    private function listViews(): void
    {
        $result = [];
        foreach ($this->views as $name => $cfg) {
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

    private function viewData(): void
    {
        $request  = $this->context->request();
        $viewName = $request->query('view');
        if (!isset($this->views[$viewName])) {
            throw new NotFoundException('View not found');
        }
        require_view_access((string) $viewName);

        $cfg        = $this->views[$viewName];
        $conn       = $this->context->connection();
        $schemaName = $cfg['schema'] ?? sys_schema();
        $level      = max(0, (int) $request->query('level', '0'));
        $filterColumn  = $request->query('filter_col');
        $filterValue  = $request->queryAll()['filter_val'] ?? null;

        $drillLevels = $cfg['drill_down']['levels'] ?? [];
        $groupBy     = null;
        if (!empty($drillLevels) && isset($drillLevels[$level])) {
            $groupBy = $drillLevels[$level]['group_by'] ?? null;
        }

        $params      = [];
        $whereClause = '';

        if ($filterColumn !== '' && $filterValue !== null) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterColumn)) {
                throw new BadRequestException('Invalid filter column');
            }
            $params[]    = $filterValue;
            $whereClause = 'WHERE ' . pg_ident($filterColumn) . ' = $1';
        }

        if ($groupBy !== null) {
            $sql = $this->groupedSql($cfg, $groupBy, $schemaName, (string) $viewName, $whereClause);
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

    private function groupedSql(
        array $cfg,
        string $groupBy,
        string $schemaName,
        string $viewName,
        string $whereClause
    ): string {
        $columnsCfg  = $cfg['columns'] ?? [];
        $aggParts = [];
        foreach ($columnsCfg as $colName => $colCfg) {
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

        return sprintf(
            'SELECT %s, %s FROM %s.%s %s GROUP BY %s ORDER BY 2 DESC LIMIT 1000',
            pg_ident($groupBy),
            $selectExtra,
            pg_ident($schemaName),
            pg_ident($viewName),
            $whereClause,
            pg_ident($groupBy)
        );
    }

    private function databaseSchemas(): void
    {
        $conn = $this->context->connection();
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

        $selected = is_array($this->viewsConfig['schemas'] ?? null) ? $this->viewsConfig['schemas'] : [];
        if (empty($selected)) {
            $selected = [sys_schema()];
        }

        throw ResponseException::encoded(['status' => 'ok', 'schemas' => $schemas, 'selected' => $selected]);
    }

    private function syncViews(): void
    {
        $conn    = $this->context->connection();
        $schemas = is_array($this->viewsConfig['schemas'] ?? null) ? $this->viewsConfig['schemas'] : [];
        $schemas = array_values(array_filter(
            array_map('strval', $schemas),
            fn($schemaName) => $schemaName !== ''
        ));
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
        foreach ($dbViews as $viewName) {
            $viewsColumns[$viewName] = $this->viewColumns($conn, $viewSchemas[$viewName], $viewName);
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

    private function viewColumns(\PgSql\Connection $conn, string $schemaName, string $viewName): array
    {
        $colSql = 'SELECT a.attname AS column_name, '
            . 'pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type '
            . 'FROM pg_catalog.pg_attribute a '
            . 'JOIN pg_catalog.pg_class c ON c.oid = a.attrelid '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = $1 AND c.relname = $2 '
            . 'AND a.attnum > 0 AND NOT a.attisdropped '
            . 'ORDER BY a.attnum';
        $columnsResult = @pg_query_params($conn, $colSql, [$schemaName, $viewName]);
        $columns   = [];
        if ($columnsResult) {
            while ($column = pg_fetch_assoc($columnsResult)) {
                $columns[$column['column_name']] = ['data_type' => $column['data_type']];
            }
            pg_free_result($columnsResult);
        }

        return $columns;
    }

    private function saveConfig(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['views'])) {
            throw new BadRequestException('Invalid payload');
        }

        $newConfig = ['views' => $body['views']];
        if (is_array($this->viewsConfig['schemas'] ?? null)) {
            $newConfig['schemas'] = $this->viewsConfig['schemas'];
        }

        $session = $this->context->session();
        $userId  = $session->has('user_id') ? $session->userId() : null;
        $result = config_save('views', $newConfig, null, $userId);
        if ($result['status'] !== 'ok') {
            $tooLarge = ($result['error'] ?? '') === 'Config too large';
            http_response_code($tooLarge ? 413 : 500);
            throw ResponseException::encoded(['error' => $result['error'] ?? 'Write failed']);
        }

        throw ResponseException::encoded(['status' => 'ok']);
    }
}
