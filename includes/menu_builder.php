<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (!function_exists('menu_config_from_store')) {
    function menu_config_from_store(string $baseName): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $baseName)) {
            return [];
        }

        require_once __DIR__ . '/config_store.php';
        $stored = config_get($baseName);
        if ($stored !== null) {
            return $stored;
        }

        $includeDirectory = __DIR__ . '/../config';
        $realBase = realpath($includeDirectory);
        if ($realBase === false) {
            return [];
        }
        $candidates = [
            $includeDirectory . '/' . $baseName . '.json',
            $includeDirectory . '/' . $baseName . '_config.json',
            $includeDirectory . '/config/' . $baseName . '.json',
            dirname($includeDirectory) . '/config/' . $baseName . '.json',
        ];
        foreach ($candidates as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !str_starts_with($realPath, $realBase)) {
                continue;
            }
            $decoded = menu_read_json($realPath);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        return [];
    }
}

if (!function_exists('menu_read_json')) {
    function menu_read_json(string $path, int $maxBytes = 524288): ?array
    {
        if (!file_exists($path) || filesize($path) > $maxBytes) {
            return null;
        }
        $content = file_get_contents($path, false, null, 0, $maxBytes);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('menu_current_page')) {
    function menu_current_page(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
}

if (!function_exists('menu_build_items')) {
    function menu_build_items(): array
    {
        require_once __DIR__ . '/config_store.php';
        require_once __DIR__ . '/api_helpers.php';

        $queryParameters = os_request()->queryAll();

        $currentPage    = menu_current_page();
        $currentTable   = substr(os_query_string('table'), 0, 64);
        $currentView    = substr(os_query_string('view'), 0, 64);
        $currentPrint   = substr(os_query_string('print'), 0, 64);
        $currentBoard   = substr(os_query_string('board'), 0, 64);
        $currentRoadmap = substr(os_query_string('roadmap'), 0, 64);
        $currentWorkflow = substr(os_query_string('workflow'), 0, 64);
        $isWorkflows    = isset($queryParameters['workflows']);

        $tables = filter_tables_for_user((config_get('schema') ?? [])['tables'] ?? []);

        $dashConfig = menu_config_from_store('dashboard');
        $calendarConfig = menu_config_from_store('calendar');
        $boardConfig = menu_config_from_store('board');
        $roadmapConfig = menu_config_from_store('roadmap');
        $filesConfig = menu_config_from_store('files');
        $workflowsConfig = menu_config_from_store('workflows');
        $viewsConfig = menu_config_from_store('views');

        $menuCatalog = [
            'dashboard' => [
                'type'   => 'dashboard',
                'href'   => 'dashboard.php',
                'name'   => $dashConfig['menu_name']  ?? 'Dashboard',
                'icon'   => $dashConfig['menu_icon']  ?? 'assets/icons/material/dashboard.svg',
                'hidden' => !empty($dashConfig['hidden']),
                'active' => $currentPage === 'dashboard.php',
            ],
            'calendar' => [
                'type'   => 'calendar',
                'href'   => 'calendar.php',
                'name'   => $calendarConfig['menu_name']   ?? 'Calendar',
                'icon'   => $calendarConfig['menu_icon']   ?? 'assets/icons/material/calendar_month.svg',
                'hidden' => !empty($calendarConfig['hidden']),
                'active' => $currentPage === 'calendar.php',
            ],
            'files' => [
                'type'   => 'files',
                'href'   => 'files.php',
                'name'   => $filesConfig['menu_name'] ?? 'Files',
                'icon'   => $filesConfig['menu_icon'] ?? 'assets/icons/material/folder_open.svg',
                'hidden' => !empty($filesConfig['hidden']),
                'active' => $currentPage === 'files.php',
            ],
        ];

        $boardChildren = [];

        foreach (filter_by_user_access('boards', $boardConfig['boards'] ?? []) as $boardItem) {
            if (empty($boardItem['table']) || empty($boardItem['status_column']) || !empty($boardItem['hidden'])) {
                continue;
            }
            if (!user_can_access_table((string) $boardItem['table'])) {
                continue;
            }
            $boardId = (string) ($boardItem['id'] ?? '');
            if ($boardId === '') {
                continue;
            }
            $boardChildren[] = [
                'type'   => 'board',
                'href'   => 'board.php?board=' . urlencode($boardId),
                'name'   => $boardItem['menu_name'] ?? 'Board',
                'icon'   => $boardItem['menu_icon'] ?? '',
                'hidden' => false,
                'active' => $currentPage === 'board.php' && $currentBoard === $boardId,
            ];
        }
        if (!empty($boardChildren)) {
            $menuCatalog['board'] = [
                'type'     => 'board',
                'href'     => $boardChildren[0]['href'],
                'name'     => $boardConfig['menu_name'] ?? 'Board',
                'icon'     => $boardConfig['menu_icon'] ?? 'assets/icons/material/account_tree.svg',
                'hidden'   => !empty($boardConfig['hidden']),
                'active'   => $currentPage === 'board.php',
                'children' => $boardChildren,
            ];
        }

        $roadmapChildren = [];

        foreach (filter_by_user_access('roadmaps', $roadmapConfig['roadmaps'] ?? []) as $roadmapItem) {
            $roadmapIncomplete = empty($roadmapItem['table'])
                || empty($roadmapItem['start_column'])
                || empty($roadmapItem['end_column'])
                || !empty($roadmapItem['hidden']);
            if ($roadmapIncomplete) {
                continue;
            }
            if (!user_can_access_table((string) $roadmapItem['table'])) {
                continue;
            }
            $roadmapEntryId  = (string) ($roadmapItem['id'] ?? '');
            if ($roadmapEntryId === '') {
                continue;
            }
            $roadmapChildren[] = [
                'type'   => 'roadmap',
                'href'   => 'roadmap.php?roadmap=' . urlencode($roadmapEntryId),
                'name'   => $roadmapItem['menu_name'] ?? 'Roadmap',
                'icon'   => $roadmapItem['menu_icon'] ?? '',
                'hidden' => false,
                'active' => $currentPage === 'roadmap.php' && $currentRoadmap === $roadmapEntryId,
            ];
        }
        if (!empty($roadmapChildren)) {
            $menuCatalog['roadmap'] = [
                'type'     => 'roadmap',
                'href'     => $roadmapChildren[0]['href'],
                'name'     => $roadmapConfig['menu_name'] ?? 'Roadmap',
                'icon'     => $roadmapConfig['menu_icon'] ?? 'assets/icons/material/timeline.svg',
                'hidden'   => !empty($roadmapConfig['hidden']),
                'active'   => $currentPage === 'roadmap.php',
                'children' => $roadmapChildren,
            ];
        }

        $workflowChildren = [];
        foreach (filter_by_user_access('workflows', $workflowsConfig['workflows'] ?? []) as $workflowItem) {
            $workflowId = (string) ($workflowItem['id'] ?? '');
            if ($workflowId === '' || !workflow_tables_in_scope($workflowItem)) {
                continue;
            }
            $workflowChildren[] = [
                'type'             => 'workflow',
                'href'             => 'index.php?workflows=1&workflow=' . urlencode($workflowId),
                'name'             => $workflowItem['title'] ?? $workflowId,
                'icon'             => $workflowItem['icon'] ?? '',
                'hidden'           => false,
                'active'           => $isWorkflows && $currentPage === 'index.php' && $currentWorkflow === $workflowId,
                'data-workflow-id' => $workflowId,
            ];
        }

        if (!empty($workflowChildren)) {
            $menuCatalog['workflows'] = [
                'type'      => 'workflows',
                'href'      => 'index.php?workflows=1',
                'name'      => $workflowsConfig['menu_name'] ?? 'Workflows',
                'icon'      => $workflowsConfig['menu_icon'] ?? '',
                'hidden'    => !empty($workflowsConfig['hidden']),
                'active'    => $isWorkflows && $currentPage === 'index.php' && $currentWorkflow === '',
                'data-page' => 'workflows',
                'children'  => $workflowChildren,
            ];
        }

        $viewChildren = [];
        foreach ($viewsConfig['views'] ?? [] as $viewName => $viewConfig) {
            if (!empty($viewConfig['hidden'])) {
                continue;
            }
            if (!user_can_access_view((string) $viewName)) {
                continue;
            }
            $viewName          = (string) $viewName;
            $viewChildren[] = [
                'type'   => 'view',
                'href'   => 'views.php?view=' . urlencode($viewName),
                'name'   => $viewConfig['menu_name'] ?? ($viewConfig['display_name'] ?? $viewName),
                'icon'   => $viewConfig['icon'] ?? '',
                'hidden' => false,
                'active' => $currentPage === 'views.php' && $currentView === $viewName,
            ];
        }
        if (!empty($viewChildren)) {
            $menuCatalog['views'] = [
                'type'     => 'views',
                'href'     => 'views.php',
                'name'     => $viewsConfig['menu_name'] ?? 'Views',
                'icon'     => $viewsConfig['menu_icon'] ?? 'assets/icons/material/table_chart_view.svg',
                'hidden'   => !empty($viewsConfig['hidden']),
                'active'   => $currentPage === 'views.php' && $currentView === '',
                'children' => $viewChildren,
            ];
        }

        $printsConfig = menu_config_from_store('print');
        $printChildren = [];
        foreach ($printsConfig['prints'] ?? [] as $printName => $printConfig) {
            if (!empty($printConfig['hidden'])) {
                continue;
            }
            if (!user_can_access_print((string) $printName)) {
                continue;
            }
            $printName           = (string) $printName;
            $printChildren[] = [
                'type'   => 'print',
                'href'   => 'print.php?print=' . urlencode($printName),
                'name'   => $printConfig['menu_name'] ?? ($printConfig['display_name'] ?? $printName),
                'icon'   => $printConfig['icon'] ?? '',
                'hidden' => false,
                'active' => $currentPage === 'print.php' && $currentPrint === $printName,
            ];
        }
        if (!empty($printChildren)) {
            $menuCatalog['print'] = [
                'type'     => 'print',
                'href'     => 'print.php',
                'name'     => 'Print',
                'icon'     => 'assets/icons/picture_as_pdf.png',
                'hidden'   => false,
                'active'   => $currentPage === 'print.php' && $currentPrint === '',
                'children' => $printChildren,
            ];
        }

        foreach ($tables as $tableName => $tableConfig) {
            $isActive = false;
            if ($currentPage === 'index.php' && !$isWorkflows) {
                if ($currentTable === $tableName) {
                    $isActive = true;
                } elseif (empty($currentTable) && $tableName === array_key_first($tables)) {
                    $isActive = true;
                }
            }
            $menuCatalog[$tableName] = [
                'type'   => 'table',
                'href'   => 'index.php?table=' . urlencode($tableName),
                'name'   => $tableConfig['display_name'] ?? $tableName,
                'icon'   => $tableConfig['icon'] ?? '',
                'hidden' => !empty($tableConfig['hidden']),
                'active' => $isActive,
                'data-table' => $tableName,
            ];
        }

        $menuJson   = config_get('menu');
        $menuItems  = [];
        $menuPlaced = [];

        if ($menuJson !== null && isset($menuJson['items']) && is_array($menuJson['items'])) {
            foreach ($menuJson['items'] as $entry) {
                $key = $entry['key'] ?? '';
                if ($key === '' || !isset($menuCatalog[$key])) {
                    continue;
                }
                $item             = $menuCatalog[$key];

                $item['children'] = $item['children'] ?? [];
                foreach ($entry['children'] ?? [] as $configEntry) {
                    $configKey = $configEntry['key'] ?? '';
                    if ($configKey === '' || !isset($menuCatalog[$configKey])) {
                        continue;
                    }
                    $item['children'][] = $menuCatalog[$configKey];
                    $menuPlaced[$configKey]    = true;
                }
                $menuItems[]       = $item;
                $menuPlaced[$key]  = true;
            }
            foreach ($menuCatalog as $key => $entry) {
                if (!isset($menuPlaced[$key])) {
                    $entry['children'] = $entry['children'] ?? [];
                    $menuItems[]       = $entry;
                }
            }
        } else {
            foreach ($menuCatalog as $entry) {
                $entry['children'] = $entry['children'] ?? [];
                $menuItems[]       = $entry;
            }
        }

        return $menuItems;
    }
}
