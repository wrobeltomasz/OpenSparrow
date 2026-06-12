<?php

declare(strict_types=1);

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    exit;
}

/* ── Demo: status ────────────────────────────────────────────────── */
if ($action === 'demo_status') {
    header('Content-Type: application/json');
    $metaPath = realpath(__DIR__ . '/../config') . '/demo_meta.json';
    if (file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        echo json_encode(['status' => 'success', 'installed' => true, 'meta' => $meta]);
    } else {
        echo json_encode(['status' => 'success', 'installed' => false]);
    }
    exit;
}

/* ── Demo: install ───────────────────────────────────────────────── */
if ($action === 'demo_install') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $type    = $body['type']    ?? '';
    $confirm = $body['confirm'] ?? '';

    if (!in_array($type, ['crm', 'wms', 'tasks'], true)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid demo type.']);
        exit;
    }
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn     = db_connect();
        $demoData = demo_get_definition($type, $conn);

        // Run DDL
        foreach ($demoData['ddl'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:ddl:{$type}");
            }
        }

        // Seed data
        foreach ($demoData['seed_data'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:seed:{$type}");
            }
        }

        $configDir = realpath(__DIR__ . '/../config');

        // schema.json
        $schemaPath = $configDir . '/schema.json';
        $schemaCfg  = file_exists($schemaPath) ? (json_decode(file_get_contents($schemaPath), true) ?? []) : [];
        if (!isset($schemaCfg['tables']) || !is_array($schemaCfg['tables'])) {
            $schemaCfg['tables'] = [];
        }
        foreach ($demoData['schema_tables'] as $key => $def) {
            $schemaCfg['tables'][$key] = $def;
        }
        file_put_contents($schemaPath, json_encode($schemaCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // dashboard.json
        $dashPath = $configDir . '/dashboard.json';
        $dashCfg  = file_exists($dashPath) ? (json_decode(file_get_contents($dashPath), true) ?? []) : [];
        if (!isset($dashCfg['widgets']) || !is_array($dashCfg['widgets'])) {
            $dashCfg['widgets'] = [];
        }
        if (!isset($dashCfg['layout'])) {
            $dashCfg['layout'] = ['gap' => '20px'];
        }
        foreach ($demoData['dashboard_widgets'] as $w) {
            $wid = $w['id'];
            $dashCfg['widgets'] = array_values(
                array_filter($dashCfg['widgets'], fn($x) => ($x['id'] ?? '') !== $wid)
            );
            $dashCfg['widgets'][] = $w;
        }
        // Rebuild in correct order: layout, widgets, menu fields
        $dashCfgOrdered = [
            'layout' => $dashCfg['layout'],
            'widgets' => $dashCfg['widgets'],
        ];
        if (isset($dashCfg['menu_name'])) {
            $dashCfgOrdered['menu_name'] = $dashCfg['menu_name'];
        }
        if (isset($dashCfg['menu_icon'])) {
            $dashCfgOrdered['menu_icon'] = $dashCfg['menu_icon'];
        }
        if (isset($dashCfg['hidden'])) {
            $dashCfgOrdered['hidden'] = $dashCfg['hidden'];
        }
        file_put_contents($dashPath, json_encode($dashCfgOrdered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // calendar.json
        $calPath  = $configDir . '/calendar.json';
        $calCfg   = file_exists($calPath) ? (json_decode(file_get_contents($calPath), true) ?? []) : [];
        if (!isset($calCfg['sources']) || !is_array($calCfg['sources'])) {
            $calCfg['sources'] = [];
        }
        $demoTbls = array_keys($demoData['schema_tables']);
        $calCfg['sources'] = array_values(
            array_filter($calCfg['sources'], fn($s) => !in_array($s['table'] ?? '', $demoTbls, true))
        );
        // Subscribe the installing admin to due-date reminders so the cron
        // notification worker has a recipient out of the box.
        $installerUid = (int)($_SESSION['user_id'] ?? 0);
        foreach ($demoData['calendar_sources'] as $s) {
            if ($installerUid > 0 && empty($s['notified_users'])) {
                $s['notified_users'] = [$installerUid];
            }
            $calCfg['sources'][] = $s;
        }
        file_put_contents($calPath, json_encode($calCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // workflows.json
        $wfPath = $configDir . '/workflows.json';
        $wfCfg  = file_exists($wfPath) ? (json_decode(file_get_contents($wfPath), true) ?? []) : [];
        if (!isset($wfCfg['workflows']) || !is_array($wfCfg['workflows'])) {
            $wfCfg['workflows'] = [];
        }
        foreach ($demoData['workflows'] as $wf) {
            $wid = $wf['id'];
            $wfCfg['workflows'] = array_values(
                array_filter($wfCfg['workflows'], fn($w) => ($w['id'] ?? '') !== $wid)
            );
            $wfCfg['workflows'][] = $wf;
        }
        // Preserve/add menu fields
        if (!isset($wfCfg['menu_name'])) {
            $wfCfg['menu_name'] = 'Workflows';
        }
        if (!isset($wfCfg['menu_icon'])) {
            $wfCfg['menu_icon'] = 'assets/icons/automation.png';
        }
        // Rebuild in correct order: workflows, menu_name, menu_icon
        $wfCfgOrdered = ['workflows' => $wfCfg['workflows'], 'menu_name' => $wfCfg['menu_name'], 'menu_icon' => $wfCfg['menu_icon']];
        file_put_contents($wfPath, json_encode($wfCfgOrdered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // views.json
        $viewsPath = $configDir . '/views.json';
        $viewsCfg  = file_exists($viewsPath) ? (json_decode(file_get_contents($viewsPath), true) ?? []) : [];
        if (!isset($viewsCfg['views']) || !is_array($viewsCfg['views'])) {
            $viewsCfg['views'] = [];
        }
        foreach ($demoData['views'] as $key => $def) {
            $viewsCfg['views'][$key] = $def;
        }
        file_put_contents($viewsPath, json_encode($viewsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // files.json — merge demo relations if provided
        if (!empty($demoData['files_relations']) && is_array($demoData['files_relations'])) {
            $filesPath = $configDir . '/files.json';
            $filesCfg  = file_exists($filesPath) ? (json_decode(file_get_contents($filesPath), true) ?? []) : [];
            if (!isset($filesCfg['menu_name'])) {
                $filesCfg['menu_name'] = 'Files';
            }
            if (!isset($filesCfg['menu_icon'])) {
                $filesCfg['menu_icon'] = 'assets/icons/upload.png';
            }
            if (!isset($filesCfg['max_file_size_mb'])) {
                $filesCfg['max_file_size_mb'] = 20;
            }
            if (!isset($filesCfg['storage_path'])) {
                $filesCfg['storage_path'] = 'storage/files/';
            }
            if (!isset($filesCfg['allowed_types']) || !is_array($filesCfg['allowed_types'])) {
                $filesCfg['allowed_types'] = ['image', 'spreadsheet', 'archive', 'other'];
            }
            if (!isset($filesCfg['allowed_extensions']) || !is_array($filesCfg['allowed_extensions'])) {
                $filesCfg['allowed_extensions'] = [
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf',
                    'doc', 'docx', 'odt', 'rtf',
                    'xls', 'xlsx', 'ods', 'csv',
                    'zip', 'tar', 'gz',
                ];
            }
            if (!isset($filesCfg['public_access'])) {
                $filesCfg['public_access'] = false;
            }
            if (!isset($filesCfg['virus_scan'])) {
                $filesCfg['virus_scan'] = false;
            }
            if (!isset($filesCfg['relations']) || !is_array($filesCfg['relations'])) {
                $filesCfg['relations'] = [];
            }
            $existingTables = array_column($filesCfg['relations'], 'table');
            foreach ($demoData['files_relations'] as $rel) {
                if (!in_array($rel['table'] ?? '', $existingTables, true)) {
                    $filesCfg['relations'][] = $rel;
                }
            }
            file_put_contents($filesPath, json_encode($filesCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // menu.json — apply nested menu layout from demo definition
        $menuKeys = [];
        if (!empty($demoData['menu_items']) && is_array($demoData['menu_items'])) {
            $menuPath = $configDir . '/menu.json';
            $menuCfg  = file_exists($menuPath) ? (json_decode(file_get_contents($menuPath), true) ?? []) : [];
            if (!isset($menuCfg['items']) || !is_array($menuCfg['items'])) {
                $menuCfg['items'] = [];
            }
            foreach ($demoData['menu_items'] as $entry) {
                $k = $entry['key'] ?? '';
                if ($k === '') {
                    continue;
                }
                $menuKeys[] = $k;
                $menuCfg['items'] = array_values(
                    array_filter($menuCfg['items'], fn($i) => ($i['key'] ?? '') !== $k)
                );
                $menuCfg['items'][] = $entry;
            }
            file_put_contents($menuPath, json_encode($menuCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // automations.json — merge demo automation rules if provided
        $automationIds = [];
        if (!empty($demoData['automations']) && is_array($demoData['automations'])) {
            $autoPath = $configDir . '/automations.json';
            $rawAuto  = file_exists($autoPath) ? (json_decode(file_get_contents($autoPath), true) ?? []) : [];
            $rules    = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            foreach ($demoData['automations'] as $rule) {
                $rid = $rule['id'] ?? '';
                if ($rid === '') {
                    continue;
                }
                $automationIds[] = $rid;
                $rules = array_values(array_filter($rules, fn($r) => ($r['id'] ?? '') !== $rid));
                $rules[] = $rule;
            }
            file_put_contents($autoPath, json_encode(['automations' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // demo_meta.json
        $meta = [
            'type'           => $type,
            'schema'         => $demoData['pg_schema'],
            'installed_at'   => date('Y-m-d H:i:s'),
            'tables'         => array_keys($demoData['schema_tables']),
            'widget_ids'     => array_column($demoData['dashboard_widgets'], 'id'),
            'workflow_ids'   => array_column($demoData['workflows'], 'id'),
            'view_keys'      => array_keys($demoData['views']),
            'view_names'     => $demoData['view_names'],
            'menu_keys'      => $menuKeys,
            'automation_ids' => $automationIds,
        ];
        file_put_contents(
            $configDir . '/demo_meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_INSTALL', 'demo', null);
        echo json_encode(['status' => 'success', 'meta' => $meta]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Demo: uninstall ─────────────────────────────────────────────── */
if ($action === 'demo_uninstall') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $confirm = $body['confirm'] ?? '';
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    $configDir = realpath(__DIR__ . '/../config');
    $metaPath = $configDir . '/demo_meta.json';
    if (!file_exists($metaPath)) {
        echo json_encode(['status' => 'error', 'error' => 'No demo installed.']);
        exit;
    }

    $meta = json_decode(file_get_contents($metaPath), true) ?? [];

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();

        // Drop demo schema + all objects
        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema && preg_match('/^spw_(crm|wms|tasks)$/', $pgSchema)) {
            @pg_query($conn, 'DROP SCHEMA IF EXISTS ' . pg_ident($pgSchema) . ' CASCADE');
        }

        // Drop views — try both the demo pg_schema and the app schema (backward compat)
        $appSchema  = sys_schema();
        $demoSchema = $meta['schema'] ?? '';
        foreach ($meta['view_names'] ?? [] as $vName) {
            if (!preg_match('/^v_demo_[a-z_]+$/', $vName)) {
                continue;
            }
            if ($demoSchema !== '' && $demoSchema !== $appSchema) {
                @pg_query($conn, 'DROP VIEW IF EXISTS ' . pg_ident($demoSchema) . '.' . pg_ident($vName));
            }
            @pg_query($conn, 'DROP VIEW IF EXISTS ' . pg_ident($appSchema) . '.' . pg_ident($vName));
        }

        // Clean schema.json (delete if empty)
        $schemaPath = $configDir . '/schema.json';
        if (file_exists($schemaPath)) {
            $cfg = json_decode(file_get_contents($schemaPath), true) ?? [];
            // Collect hidden junction tables referenced by demo tables before removing them
            $m2mJunctions = [];
            foreach ($meta['tables'] ?? [] as $t) {
                foreach ($cfg['tables'][$t]['many_to_many'] ?? [] as $m2m) {
                    $jt = $m2m['junction_table'] ?? '';
                    if ($jt && !empty($cfg['tables'][$jt]['hidden'])) {
                        $m2mJunctions[] = $jt;
                    }
                }
                unset($cfg['tables'][$t]);
            }
            // Remove orphaned hidden junction tables (not tracked in meta, added via M2M Builder)
            foreach ($m2mJunctions as $jt) {
                if (isset($cfg['tables'][$jt])) {
                    $stillUsed = false;
                    foreach ($cfg['tables'] as $tCfg) {
                        foreach ($tCfg['many_to_many'] ?? [] as $m) {
                            if (($m['junction_table'] ?? '') === $jt) {
                                $stillUsed = true;
                                break 2;
                            }
                        }
                    }
                    if (!$stillUsed) {
                        unset($cfg['tables'][$jt]);
                    }
                }
            }
            if (empty($cfg['tables'])) {
                @unlink($schemaPath);
            } else {
                file_put_contents($schemaPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean dashboard.json (delete if empty)
        $dashPath = $configDir . '/dashboard.json';
        if (file_exists($dashPath)) {
            $cfg = json_decode(file_get_contents($dashPath), true) ?? [];
            $ids = $meta['widget_ids'] ?? [];
            $cfg['widgets'] = array_values(
                array_filter($cfg['widgets'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($cfg['widgets'])) {
                @unlink($dashPath);
            } else {
                file_put_contents($dashPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean calendar.json (delete if empty)
        $calPath = $configDir . '/calendar.json';
        if (file_exists($calPath)) {
            $cfg  = json_decode(file_get_contents($calPath), true) ?? [];
            $tbls = $meta['tables'] ?? [];
            $cfg['sources'] = array_values(
                array_filter($cfg['sources'] ?? [], fn($s) => !in_array($s['table'] ?? '', $tbls, true))
            );
            if (empty($cfg['sources'])) {
                @unlink($calPath);
            } else {
                file_put_contents($calPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean workflows.json (delete if empty)
        $wfPath = $configDir . '/workflows.json';
        if (file_exists($wfPath)) {
            $cfg = json_decode(file_get_contents($wfPath), true) ?? [];
            $ids = $meta['workflow_ids'] ?? [];
            $cfg['workflows'] = array_values(
                array_filter($cfg['workflows'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($cfg['workflows'])) {
                @unlink($wfPath);
            } else {
                file_put_contents($wfPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean views.json (delete if empty)
        $viewsPath = $configDir . '/views.json';
        if (file_exists($viewsPath)) {
            $cfg = json_decode(file_get_contents($viewsPath), true) ?? [];
            foreach ($meta['view_keys'] ?? [] as $k) {
                unset($cfg['views'][$k]);
            }
            if (empty($cfg['views'])) {
                @unlink($viewsPath);
            } else {
                file_put_contents($viewsPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean menu.json (delete if empty)
        $menuPath = $configDir . '/menu.json';
        if (file_exists($menuPath)) {
            $cfg  = json_decode(file_get_contents($menuPath), true) ?? [];
            $keys = $meta['menu_keys'] ?? [];
            if (!empty($keys) && isset($cfg['items']) && is_array($cfg['items'])) {
                $cfg['items'] = array_values(
                    array_filter($cfg['items'], fn($i) => !in_array($i['key'] ?? '', $keys, true))
                );
            }
            if (empty($cfg['items'])) {
                @unlink($menuPath);
            } else {
                file_put_contents($menuPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean automations.json (delete if empty)
        $autoPath = $configDir . '/automations.json';
        if (file_exists($autoPath)) {
            $rawAuto = json_decode(file_get_contents($autoPath), true) ?? [];
            $rules   = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            $ids     = $meta['automation_ids'] ?? [];
            if (!empty($ids)) {
                $rules = array_values(array_filter($rules, fn($r) => !in_array($r['id'] ?? '', $ids, true)));
                if (empty($rules)) {
                    @unlink($autoPath);
                } else {
                    file_put_contents($autoPath, json_encode(['automations' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        @unlink($metaPath);
        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_UNINSTALL', 'demo', null);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}


/* -- Demo: definition helper ----------------------------------------- */
function demo_get_definition(string $type, $conn): array
{
    switch ($type) {
        case 'crm':
            require_once __DIR__ . '/api_demo_crm.php';
            return demo_def_crm($conn);
        case 'wms':
            require_once __DIR__ . '/api_demo_wms.php';
            return demo_def_wms($conn);
        case 'tasks':
            require_once __DIR__ . '/api_demo_tasks.php';
            return demo_def_tasks($conn);
        default:
            throw new \InvalidArgumentException("Unknown demo type: {$type}");
    }
}
