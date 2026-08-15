<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ConflictException;
use App\Exception\ControlFlowException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/config_store.php';

os_api_bootstrap(['connect' => false]);

$role   = $_SESSION['role'] ?? 'viewer';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$printRow     = config_get_row('print');
$printConfig  = $printRow['value'] ?? [];
$printVersion = $printRow['version'] ?? 0;
$prints       = $printConfig['prints'] ?? [];

function print_available_views(): array
{
    $decoded = config_get('views');
    if ($decoded === null) {
        return [];
    }
    $out = [];
    foreach (($decoded['views'] ?? []) as $name => $cfg) {
        if (!is_array($cfg) || ($cfg['source'] ?? 'postgres') !== 'postgres') {
            continue;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $name)) {
            continue;
        }
        $out[(string) $name] = $cfg;
    }
    return $out;
}

function print_sanitize_template(array $tpl, array $availableViews): ?array
{
    $view = (string) ($tpl['view'] ?? '');
    if ($view !== '' && !isset($availableViews[$view])) {
        return null;
    }

    $icon = (string) ($tpl['icon'] ?? '');
    if (
        $icon !== ''
        && (str_contains($icon, '..') || !preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon))
    ) {
        $icon = '';
    }

    $blocks = [];
    foreach (array_slice((array) ($tpl['blocks'] ?? []), 0, 50) as $block) {
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
            $cols = [];
            foreach (array_slice((array) ($block['columns'] ?? []), 0, 50) as $col) {
                $name = is_string($col) ? $col : (string) ($col['name'] ?? '');
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $name)) {
                    continue;
                }
                $entry = ['name' => $name, 'align' => 'left'];
                if (is_array($col)) {
                    if (in_array($col['align'] ?? '', ['left', 'center', 'right'], true)) {
                        $entry['align'] = $col['align'];
                    }
                    if (isset($col['width']) && is_numeric($col['width'])) {
                        $width = (int) $col['width'];
                        if ($width >= 1 && $width <= 100) {
                            $entry['width'] = $width;
                        }
                    }
                }
                $cols[] = $entry;
            }
            $blocks[] = ['type' => 'table', 'columns' => $cols];
        } else {
            return null;
        }
    }

    $params    = [];
    $paramKeys = [];
    foreach (array_slice((array) ($tpl['params'] ?? []), 0, 20) as $prm) {
        if (!is_array($prm)) {
            return null;
        }
        $key = (string) ($prm['key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) || in_array($key, $paramKeys, true)) {
            return null;
        }
        $column = (string) ($prm['column'] ?? '');
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $column)) {
            return null;
        }

        $paramKeys[] = $key;
        $entry       = [
            'key'      => $key,
            'label'    => mb_substr((string) ($prm['label'] ?? ''), 0, 120),
            'type'     => 'select',
            'column'   => $column,
            'required' => !empty($prm['required']),
        ];

        $sourceView = (string) ($prm['source_view'] ?? '');
        $valueCol   = (string) ($prm['value_column'] ?? '');
        $labelCol   = (string) ($prm['label_column'] ?? '');
        if (
            $sourceView !== ''
            && isset($availableViews[$sourceView])
            && preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $valueCol)
            && preg_match('/^[a-zA-Z_][a-zA-Z0-9_ ]*$/', $labelCol)
        ) {
            $entry['source_view']  = $sourceView;
            $entry['value_column'] = $valueCol;
            $entry['label_column'] = $labelCol;
        }

        $params[] = $entry;
    }

    return [
        'display_name' => mb_substr((string) ($tpl['display_name'] ?? ''), 0, 120),
        'menu_name'    => mb_substr((string) ($tpl['menu_name'] ?? ''), 0, 120),
        'description'  => mb_substr((string) ($tpl['description'] ?? ''), 0, 500),
        'icon'         => $icon,
        'hidden'       => !empty($tpl['hidden']),
        'view'         => $view,
        'blocks'       => $blocks,
        'params'       => $params,
    ];
}

try {
    if ($action === 'list' && $method === 'GET') {
        $result = [];
        foreach ($prints as $name => $cfg) {
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

    if ($action === 'config' && $method === 'GET' && $role === 'admin') {
        echo json_encode([
            'status' => 'ok',

            'config'  => ['prints' => (object) $prints],
            'version' => $printVersion,
            'views'   => array_keys(print_available_views()),
        ]);
        throw ResponseException::sent();
    }

    if ($action === 'columns' && $method === 'GET' && $role === 'admin') {
        $viewName = $_GET['view'] ?? '';
        $views    = print_available_views();
        if (!isset($views[$viewName])) {
            throw new NotFoundException('View not found');
        }

        $conn       = db_connect();
        $schemaName = $views[$viewName]['schema'] ?? sys_schema();
        $sql        = 'SELECT column_name, data_type FROM information_schema.columns '
            . 'WHERE table_schema = $1 AND table_name = $2 ORDER BY ordinal_position';
        $res        = @pg_query_params($conn, $sql, [$schemaName, $viewName]);
        if (!$res) {
            error_log('[api_print][columns] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error');
        }

        $cols = [];
        while ($col = pg_fetch_assoc($res)) {
            $cols[] = ['name' => $col['column_name'], 'data_type' => $col['data_type']];
        }
        pg_free_result($res);

        throw ResponseException::encoded(['status' => 'ok', 'view' => $viewName, 'columns' => $cols]);
    }

    if ($action === 'data' && $method === 'GET') {
        $printName = $_GET['print'] ?? '';
        if (!isset($prints[$printName])) {
            throw new NotFoundException('Print template not found');
        }
        require_print_access((string) $printName);

        $cfg           = $prints[$printName];
        $views         = print_available_views();
        $viewName      = (string) ($cfg['view'] ?? '');
        $paramDefs     = $cfg['params'] ?? [];
        $rows          = [];
        $viewCols      = [];
        $appliedParams = [];

        if ($viewName !== '' && isset($views[$viewName])) {
            $conn       = db_connect();
            $schemaName = $views[$viewName]['schema'] ?? sys_schema();

            $where       = [];
            $queryParams = [];
            foreach ($paramDefs as $p) {
                $key = (string) ($p['key'] ?? '');
                $val = $_GET['p_' . $key] ?? '';
                if ($val === '' || $val === null) {
                    continue;
                }
                $queryParams[]        = $val;
                $where[]              = pg_ident($p['column']) . ' = $' . count($queryParams);
                $appliedParams[$key]  = $val;
            }

            $sql = sprintf('SELECT * FROM %s.%s', pg_ident($schemaName), pg_ident($viewName));
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' LIMIT 1000';

            $res = @pg_query_params($conn, $sql, $queryParams);
            if (!$res) {
                error_log('[api_print][data] ' . pg_last_error($conn));
                throw new ServerErrorException('Database error');
            }
            $rows = pg_fetch_all($res) ?: [];
            pg_free_result($res);
            $viewCols = $views[$viewName]['columns'] ?? [];
        }

        echo json_encode([
            'status'         => 'ok',
            'print'          => $printName,
            'display_name'   => $cfg['display_name'] ?? $printName,
            'icon'           => $cfg['icon'] ?? '',
            'view'           => $viewName,
            'blocks'         => $cfg['blocks'] ?? [],
            'rows'           => $rows,
            'columns'        => $viewCols,
            'params'         => $paramDefs,
            'applied_params' => (object) $appliedParams,
        ]);
        throw ResponseException::sent();
    }

    if ($action === 'param_options' && $method === 'GET') {
        $printName = $_GET['print'] ?? '';
        $paramKey  = $_GET['key'] ?? '';
        if (!isset($prints[$printName])) {
            throw new NotFoundException('Print template not found');
        }
        require_print_access((string) $printName);

        $cfg   = $prints[$printName];
        $views = print_available_views();
        $param = null;
        foreach (($cfg['params'] ?? []) as $p) {
            if (($p['key'] ?? '') === $paramKey) {
                $param = $p;
                break;
            }
        }
        if ($param === null) {
            throw new NotFoundException('Parameter not found');
        }

        $conn = db_connect();

        if (!empty($param['source_view']) && isset($views[$param['source_view']])) {
            $srcView    = $param['source_view'];
            $schemaName = $views[$srcView]['schema'] ?? sys_schema();
            $valueIdent = pg_ident($param['value_column']);
            $labelIdent = pg_ident($param['label_column']);
            $sql        = sprintf(
                'SELECT DISTINCT %s AS value, %s AS label FROM %s.%s WHERE %s IS NOT NULL ORDER BY %s LIMIT 500',
                $valueIdent,
                $labelIdent,
                pg_ident($schemaName),
                pg_ident($srcView),
                $valueIdent,
                $labelIdent
            );
        } else {
            $viewName = (string) ($cfg['view'] ?? '');
            if ($viewName === '' || !isset($views[$viewName])) {
                throw ResponseException::encoded(['status' => 'ok', 'options' => []]);
            }
            $schemaName = $views[$viewName]['schema'] ?? sys_schema();
            $colIdent   = pg_ident($param['column']);
            $sql        = sprintf(
                'SELECT DISTINCT %s AS value, %s AS label FROM %s.%s WHERE %s IS NOT NULL ORDER BY %s LIMIT 500',
                $colIdent,
                $colIdent,
                pg_ident($schemaName),
                pg_ident($viewName),
                $colIdent,
                $colIdent
            );
        }

        $res = @pg_query_params($conn, $sql, []);
        if (!$res) {
            error_log('[api_print][param_options] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error');
        }
        $options = pg_fetch_all($res) ?: [];
        pg_free_result($res);

        throw ResponseException::encoded(['status' => 'ok', 'options' => $options]);
    }

    if ($action === 'save' && $method === 'POST' && $role === 'admin') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['prints']) || !is_array($body['prints'])) {
            throw new BadRequestException('Invalid payload');
        }

        $expectedVersion = isset($body['version']) && is_numeric($body['version'])
            ? (int) $body['version'] : null;

        $views     = print_available_views();
        $sanitized = [];
        foreach ($body['prints'] as $name => $tpl) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) || !is_array($tpl)) {
                throw new BadRequestException((string) 'Invalid template key: ' . mb_substr((string) $name, 0, 64));
            }
            $clean = print_sanitize_template($tpl, $views);
            if ($clean === null) {
                throw new BadRequestException((string) 'Invalid template: ' . $name);
            }
            $sanitized[$name] = $clean;
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
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

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or insufficient permissions']);
} catch (ControlFlowException $signal) {
    throw $signal;
} catch (Throwable $e) {
    error_log('[api_print][exception] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
