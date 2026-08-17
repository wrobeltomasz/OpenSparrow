<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\BadRequestException;
use App\Exception\ConflictException;
use App\Exception\ControlFlowException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Service\AppContext;
use Throwable;

final class PrintController
{
    private readonly array $printConfig;

    private readonly int $printVersion;

    private readonly array $prints;

    public function __construct(private readonly AppContext $context)
    {
        $printRow           = config_get_row('print');
        $this->printConfig  = $printRow['value'] ?? [];
        $this->printVersion = (int) ($printRow['version'] ?? 0);
        $this->prints       = $this->printConfig['prints'] ?? [];
    }

    public function handle(): void
    {
        $request = $this->context->request();
        $role    = $this->context->session()->role();
        $action  = $request->query('action');
        $method  = $request->method();

        try {
            if ($action === 'list' && $method === 'GET') {
                $this->listPrints();
            }

            if ($action === 'config' && $method === 'GET' && $role === 'admin') {
                $this->adminConfig();
            }

            if ($action === 'columns' && $method === 'GET' && $role === 'admin') {
                $this->viewColumns();
            }

            if ($action === 'data' && $method === 'GET') {
                $this->printData();
            }

            if ($action === 'param_options' && $method === 'GET') {
                $this->parameterOptions();
            }

            if ($action === 'save' && $method === 'POST' && $role === 'admin') {
                $this->saveTemplates();
            }

            http_response_code(400);
            echo json_encode(['error' => 'Invalid action or insufficient permissions']);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            error_log('[api_print][exception] ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    private function availableViews(): array
    {
        $decoded = config_get('views');
        if ($decoded === null) {
            return [];
        }
        $output = [];
        foreach (($decoded['views'] ?? []) as $name => $cfg) {
            if (!is_array($cfg) || ($cfg['source'] ?? 'postgres') !== 'postgres') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $name)) {
                continue;
            }
            $output[(string) $name] = $cfg;
        }
        return $output;
    }

    private function sanitizeTemplate(array $template, array $availableViews): ?array
    {
        $view = (string) ($template['view'] ?? '');
        if ($view !== '' && !isset($availableViews[$view])) {
            return null;
        }

        $icon = (string) ($template['icon'] ?? '');
        if (
            $icon !== ''
            && (str_contains($icon, '..')
                || !preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon))
        ) {
            $icon = '';
        }

        $blocks = $this->sanitizeBlocks($template);
        if ($blocks === null) {
            return null;
        }

        $params = $this->sanitizeParams($template, $availableViews);
        if ($params === null) {
            return null;
        }

        return [
            'display_name' => mb_substr((string) ($template['display_name'] ?? ''), 0, 120),
            'menu_name'    => mb_substr((string) ($template['menu_name'] ?? ''), 0, 120),
            'description'  => mb_substr((string) ($template['description'] ?? ''), 0, 500),
            'icon'         => $icon,
            'hidden'       => !empty($template['hidden']),
            'view'         => $view,
            'blocks'       => $blocks,
            'params'       => $params,
        ];
    }

    private function sanitizeBlocks(array $template): ?array
    {
        $blocks = [];
        foreach (array_slice((array) ($template['blocks'] ?? []), 0, 50) as $block) {
            if (!is_array($block)) {
                return null;
            }
            $type = $block['type'] ?? '';
            if ($type === 'header') {
                $level    = (int) ($block['level'] ?? 1);
                $blocks[] = [
                    'type'  => 'header',
                    'text'  => mb_substr((string) ($block['text'] ?? ''), 0, 500),
                    'level' => max(1, min(3, $level)),
                ];
            } elseif ($type === 'text') {
                $blocks[] = [
                    'type' => 'text',
                    'text' => mb_substr((string) ($block['text'] ?? ''), 0, 5000),
                ];
            } elseif ($type === 'table') {
                $columns = [];
                foreach (array_slice((array) ($block['columns'] ?? []), 0, 50) as $columnDefinition) {
                    $name = is_string($columnDefinition)
                        ? $columnDefinition
                        : (string) ($columnDefinition['name'] ?? '');
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $name)) {
                        continue;
                    }
                    $entry = ['name' => $name, 'align' => 'left'];
                    if (is_array($columnDefinition)) {
                        if (in_array($columnDefinition['align'] ?? '', ['left', 'center', 'right'], true)) {
                            $entry['align'] = $columnDefinition['align'];
                        }
                        if (isset($columnDefinition['width']) && is_numeric($columnDefinition['width'])) {
                            $width = (int) $columnDefinition['width'];
                            if ($width >= 1 && $width <= 100) {
                                $entry['width'] = $width;
                            }
                        }
                    }
                    $columns[] = $entry;
                }
                $blocks[] = ['type' => 'table', 'columns' => $columns];
            } else {
                return null;
            }
        }

        return $blocks;
    }

    private function sanitizeParams(array $template, array $availableViews): ?array
    {
        $params    = [];
        $paramKeys = [];
        foreach (array_slice((array) ($template['params'] ?? []), 0, 20) as $parameter) {
            if (!is_array($parameter)) {
                return null;
            }
            $key = (string) ($parameter['key'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) || in_array($key, $paramKeys, true)) {
                return null;
            }
            $column = (string) ($parameter['column'] ?? '');
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $column)) {
                return null;
            }

            $paramKeys[] = $key;
            $entry       = [
                'key'      => $key,
                'label'    => mb_substr((string) ($parameter['label'] ?? ''), 0, 120),
                'type'     => 'select',
                'column'   => $column,
                'required' => !empty($parameter['required']),
            ];

            $sourceView = (string) ($parameter['source_view'] ?? '');
            $valueColumn   = (string) ($parameter['value_column'] ?? '');
            $labelColumn   = (string) ($parameter['label_column'] ?? '');
            if (
                $sourceView !== ''
                && isset($availableViews[$sourceView])
                && preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $valueColumn)
                && preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $labelColumn)
            ) {
                $entry['source_view']  = $sourceView;
                $entry['value_column'] = $valueColumn;
                $entry['label_column'] = $labelColumn;
            }

            $params[] = $entry;
        }

        return $params;
    }

    private function listPrints(): void
    {
        $result = [];
        foreach ($this->prints as $name => $cfg) {
            if (!empty($cfg['hidden'])) {
                continue;
            }

            if (!user_can_access_print((string) $name)) {
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
        throw ResponseException::encoded(['status' => 'ok', 'prints' => $result]);
    }

    private function adminConfig(): void
    {
        echo json_encode([
            'status' => 'ok',

            'config'  => ['prints' => (object) $this->prints],
            'version' => $this->printVersion,
            'views'   => array_keys($this->availableViews()),
        ]);
        throw ResponseException::sent();
    }

    private function viewColumns(): void
    {
        $viewName = $this->context->request()->query('view');
        $views    = $this->availableViews();
        if (!isset($views[$viewName])) {
            throw new NotFoundException('View not found');
        }

        $conn       = $this->context->connection();
        $schemaName = $views[$viewName]['schema'] ?? sys_schema();
        $sql        = 'SELECT column_name, data_type FROM information_schema.columns '
            . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position';
        $queryResult        = @pg_query_params($conn, $sql, [$schemaName, $viewName]);
        if (!$queryResult) {
            error_log('[api_print][columns] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error');
        }

        $columns = [];
        while ($columnRow = pg_fetch_assoc($queryResult)) {
            $columns[] = ['name' => $columnRow['column_name'], 'data_type' => $columnRow['data_type']];
        }
        pg_free_result($queryResult);

        throw ResponseException::encoded(['status' => 'ok', 'view' => $viewName, 'columns' => $columns]);
    }

    private function printData(): void
    {
        $request   = $this->context->request();
        $printName = $request->query('print');
        if (!isset($this->prints[$printName])) {
            throw new NotFoundException('Print template not found');
        }
        require_print_access((string) $printName);

        $cfg           = $this->prints[$printName];
        $views         = $this->availableViews();
        $viewName      = (string) ($cfg['view'] ?? '');
        $parameterDefinitions     = $cfg['params'] ?? [];
        $rows          = [];
        $viewColumns      = [];
        $appliedParams = [];

        if ($viewName !== '' && isset($views[$viewName])) {
            $conn       = $this->context->connection();
            $schemaName = $views[$viewName]['schema'] ?? sys_schema();

            $where           = [];
            $queryParams     = [];
            $queryParameters = $request->queryAll();
            foreach ($parameterDefinitions as $parameterDefinition) {
                $key = (string) ($parameterDefinition['key'] ?? '');
                $value = $queryParameters['p_' . $key] ?? '';
                if ($value === '' || $value === null) {
                    continue;
                }
                $queryParams[]        = $value;
                $where[]              = pg_ident($parameterDefinition['column']) . ' = $' . count($queryParams);
                $appliedParams[$key]  = $value;
            }

            $sql = sprintf('SELECT * FROM %s.%s', pg_ident($schemaName), pg_ident($viewName));
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' LIMIT 1000';

            $queryResult = @pg_query_params($conn, $sql, $queryParams);
            if (!$queryResult) {
                error_log('[api_print][data] ' . pg_last_error($conn));
                throw new ServerErrorException('Database error');
            }
            $rows = pg_fetch_all($queryResult) ?: [];
            pg_free_result($queryResult);
            $viewColumns = $views[$viewName]['columns'] ?? [];
        }

        echo json_encode([
            'status'         => 'ok',
            'print'          => $printName,
            'display_name'   => $cfg['display_name'] ?? $printName,
            'icon'           => $cfg['icon'] ?? '',
            'view'           => $viewName,
            'blocks'         => $cfg['blocks'] ?? [],
            'rows'           => $rows,
            'columns'        => $viewColumns,
            'params'         => $parameterDefinitions,
            'applied_params' => (object) $appliedParams,
        ]);
        throw ResponseException::sent();
    }

    private function parameterOptions(): void
    {
        $request   = $this->context->request();
        $printName = $request->query('print');
        $paramKey  = $request->query('key');
        if (!isset($this->prints[$printName])) {
            throw new NotFoundException('Print template not found');
        }
        require_print_access((string) $printName);

        $cfg   = $this->prints[$printName];
        $views = $this->availableViews();
        $param = null;
        foreach (($cfg['params'] ?? []) as $parameterDefinition) {
            if (($parameterDefinition['key'] ?? '') === $paramKey) {
                $param = $parameterDefinition;
                break;
            }
        }
        if ($param === null) {
            throw new NotFoundException('Parameter not found');
        }

        $conn = $this->context->connection();

        if (!empty($param['source_view']) && isset($views[$param['source_view']])) {
            $srcView    = $param['source_view'];
            $schemaName = $views[$srcView]['schema'] ?? sys_schema();
            $valueColumnIdentifier = pg_ident($param['value_column']);
            $labelColumnIdentifier = pg_ident($param['label_column']);
            $sql        = sprintf(
                'SELECT DISTINCT %s AS value, %s AS label FROM %s.%s WHERE %s IS NOT NULL ORDER BY %s LIMIT 500',
                $valueColumnIdentifier,
                $labelColumnIdentifier,
                pg_ident($schemaName),
                pg_ident($srcView),
                $valueColumnIdentifier,
                $labelColumnIdentifier
            );
        } else {
            $viewName = (string) ($cfg['view'] ?? '');
            if ($viewName === '' || !isset($views[$viewName])) {
                throw ResponseException::encoded(['status' => 'ok', 'options' => []]);
            }
            $schemaName = $views[$viewName]['schema'] ?? sys_schema();
            $columnIdentifier   = pg_ident($param['column']);
            $sql        = sprintf(
                'SELECT DISTINCT %s AS value, %s AS label FROM %s.%s WHERE %s IS NOT NULL ORDER BY %s LIMIT 500',
                $columnIdentifier,
                $columnIdentifier,
                pg_ident($schemaName),
                pg_ident($viewName),
                $columnIdentifier,
                $columnIdentifier
            );
        }

        $queryResult = @pg_query_params($conn, $sql, []);
        if (!$queryResult) {
            error_log('[api_print][param_options] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error');
        }
        $options = pg_fetch_all($queryResult) ?: [];
        pg_free_result($queryResult);

        throw ResponseException::encoded(['status' => 'ok', 'options' => $options]);
    }

    private function saveTemplates(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['prints']) || !is_array($body['prints'])) {
            throw new BadRequestException('Invalid payload');
        }

        $expectedVersion = isset($body['version']) && is_numeric($body['version'])
            ? (int) $body['version'] : null;

        $views     = $this->availableViews();
        $sanitized = [];
        foreach ($body['prints'] as $name => $template) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) || !is_array($template)) {
                throw new BadRequestException((string) 'Invalid template key: ' . mb_substr((string) $name, 0, 64));
            }
            $clean = $this->sanitizeTemplate($template, $views);
            if ($clean === null) {
                throw new BadRequestException((string) 'Invalid template: ' . $name);
            }
            $sanitized[$name] = $clean;
        }

        $session = $this->context->session();
        $userId  = $session->has('user_id') ? $session->userId() : null;
        $result = config_save('print', ['prints' => $sanitized], $expectedVersion, $userId);
        if ($result['status'] === 'conflict') {
            throw new ConflictException('Config was modified by someone else — reload and retry');
        }
        if ($result['status'] !== 'ok') {
            $tooLarge = ($result['error'] ?? '') === 'Config too large';
            http_response_code($tooLarge ? 413 : 500);
            throw ResponseException::encoded(['error' => $result['error'] ?? 'Write failed']);
        }

        throw ResponseException::encoded(['status' => 'ok', 'version' => $result['version']]);
    }
}
