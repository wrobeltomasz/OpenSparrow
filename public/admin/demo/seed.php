<?php

declare(strict_types=1);

// admin/demo/seed.php — Demo sample-app handler (included at the end of admin/api.php, not called directly)
// Relies on $action, $isDemoMode and DEMO_MODE from the parent; aborts 403 if DEMO_MODE undefined
// actions: demo_status, demo_install, demo_uninstall — installs/removes the ready-made CRM schema + seed data
// Loads the schema definition from demo/crm.php; app config goes to spw_config via config_store;
// writes config/demo_meta.json; install blocked when running in read-only demo mode

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    exit;
}

/* ── Demo: status ────────────────────────────────────────────────── */
if ($action === 'demo_status') {
    header('Content-Type: application/json');
    $metaPath = realpath(__DIR__ . '/../../../config') . '/demo_meta.json';
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

    if ($type !== 'crm') {
        echo json_encode(['status' => 'error', 'error' => 'Invalid demo type.']);
        exit;
    }
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../../includes/db.php';
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

        $configDir = realpath(__DIR__ . '/../../../config');

        // schema config (spw_config key "schema")
        require_once __DIR__ . '/../../../includes/config_store.php';
        $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $schemaCfg = config_get('schema') ?? [];
        if (!isset($schemaCfg['tables']) || !is_array($schemaCfg['tables'])) {
            $schemaCfg['tables'] = [];
        }
        foreach ($demoData['schema_tables'] as $key => $def) {
            $schemaCfg['tables'][$key] = $def;
        }
        config_save('schema', $schemaCfg, null, $seedUserId);

        // dashboard config (spw_config key "dashboard")
        $dashCfg = config_get('dashboard') ?? [];
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
        config_save('dashboard', $dashCfgOrdered, null, $seedUserId);

        // calendar config (spw_config key "calendar")
        $calCfg = config_get('calendar') ?? [];
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
        config_save('calendar', $calCfg, null, $seedUserId);

        // board config — single-config Kanban board, written only if the demo
        // defines one. Mirrors the structure produced by the admin Board editor.
        if (!empty($demoData['board']) && is_array($demoData['board'])) {
            config_save('board', $demoData['board'], null, $seedUserId);
        }

        // anonymization config — merge demo GDPR rules if provided. Existing user
        // settings (enabled/frequency/dictionary) win; demo rules replace any
        // previous rules pointing at demo tables.
        if (!empty($demoData['anonymization']) && is_array($demoData['anonymization'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $anonCfg  = config_get('anonymization') ?? [];
            $demoAnon = $demoData['anonymization'];
            $anonCfg['enabled']    = $anonCfg['enabled']    ?? ($demoAnon['enabled']    ?? false);
            $anonCfg['frequency']  = $anonCfg['frequency']  ?? ($demoAnon['frequency']  ?? 'manual');
            $anonCfg['dictionary'] = (isset($anonCfg['dictionary']) && is_array($anonCfg['dictionary']))
                ? $anonCfg['dictionary']
                : ($demoAnon['dictionary'] ?? []);
            $demoTblsAnon = array_keys($demoData['schema_tables']);
            $rules = is_array($anonCfg['rules'] ?? null) ? $anonCfg['rules'] : [];
            $rules = array_values(array_filter($rules, fn($r) => !in_array($r['table'] ?? '', $demoTblsAnon, true)));
            foreach ($demoAnon['rules'] ?? [] as $r) {
                $rules[] = $r;
            }
            $anonCfg['rules'] = $rules;
            $anonCfgOrdered = [
                'enabled'    => $anonCfg['enabled'],
                'frequency'  => $anonCfg['frequency'],
                'dictionary' => $anonCfg['dictionary'],
                'rules'      => $anonCfg['rules'],
            ];
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('anonymization', $anonCfgOrdered, null, $seedUserId);
        }

        // workflows config (spw_config key "workflows")
        $wfCfg = config_get('workflows') ?? [];
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
        config_save('workflows', $wfCfgOrdered, null, $seedUserId);

        // views config (spw_config key "views")
        $viewsCfg = config_get('views') ?? [];
        if (!isset($viewsCfg['views']) || !is_array($viewsCfg['views'])) {
            $viewsCfg['views'] = [];
        }
        foreach ($demoData['views'] as $key => $def) {
            $viewsCfg['views'][$key] = $def;
        }
        config_save('views', $viewsCfg, null, $seedUserId);

        // files config (spw_config key "files") — merge demo relations if provided
        if (!empty($demoData['files_relations']) && is_array($demoData['files_relations'])) {
            $filesCfg = config_get('files') ?? [];
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
            if (!isset($filesCfg['relations']) || !is_array($filesCfg['relations'])) {
                $filesCfg['relations'] = [];
            }
            $existingTables = array_column($filesCfg['relations'], 'table');
            foreach ($demoData['files_relations'] as $rel) {
                if (!in_array($rel['table'] ?? '', $existingTables, true)) {
                    $filesCfg['relations'][] = $rel;
                }
            }
            config_save('files', $filesCfg, null, $seedUserId);
        }

        // menu config (spw_config key "menu") — apply nested menu layout from demo definition
        $menuKeys = [];
        if (!empty($demoData['menu_items']) && is_array($demoData['menu_items'])) {
            $menuCfg = config_get('menu') ?? [];
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
            config_save('menu', $menuCfg, null, $seedUserId);
        }

        // automations config (spw_config key "automations") — merge demo rules if provided
        $automationIds = [];
        if (!empty($demoData['automations']) && is_array($demoData['automations'])) {
            $rawAuto = config_get('automations') ?? [];
            $rules   = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            foreach ($demoData['automations'] as $rule) {
                $rid = $rule['id'] ?? '';
                if ($rid === '') {
                    continue;
                }
                $automationIds[] = $rid;
                $rules = array_values(array_filter($rules, fn($r) => ($r['id'] ?? '') !== $rid));
                $rules[] = $rule;
            }
            config_save('automations', ['automations' => $rules], null, $seedUserId);
        }

        // print config (spw_config key "print") — merge demo print templates if provided
        // (keyed by template name, same merge-by-key pattern as the views config above)
        $printKeys = [];
        if (!empty($demoData['prints']) && is_array($demoData['prints'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $printCfg = config_get('print') ?? [];
            if (!isset($printCfg['prints']) || !is_array($printCfg['prints'])) {
                $printCfg['prints'] = [];
            }
            foreach ($demoData['prints'] as $key => $def) {
                $printKeys[] = $key;
                $printCfg['prints'][$key] = $def;
            }
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('print', $printCfg, null, $seedUserId);
        }

        // user_records config — merge demo column-label mappings if provided (keyed by
        // table name; the global "limit" setting is a user preference and is left
        // untouched, only defaulted if the file didn't exist yet).
        if (!empty($demoData['user_records']) && is_array($demoData['user_records'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $urCfg = config_get('user_records') ?? [];
            if (!isset($urCfg['columns']) || !is_array($urCfg['columns'])) {
                $urCfg['columns'] = [];
            }
            if (!isset($urCfg['limit'])) {
                $urCfg['limit'] = 20;
            }
            foreach ($demoData['user_records'] as $tableName => $cols) {
                $urCfg['columns'][$tableName] = $cols;
            }
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('user_records', $urCfg, null, $seedUserId);
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
            'print_keys'     => $printKeys,
            'board_table'    => $demoData['board']['table'] ?? null,
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

    $configDir = realpath(__DIR__ . '/../../../config');
    $metaPath = $configDir . '/demo_meta.json';
    if (!file_exists($metaPath)) {
        echo json_encode(['status' => 'error', 'error' => 'No demo installed.']);
        exit;
    }

    $meta = json_decode(file_get_contents($metaPath), true) ?? [];

    try {
        require_once __DIR__ . '/../../../includes/db.php';
        $conn = db_connect();

        // Drop demo schema + all objects
        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema === 'spw_crm') {
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

        require_once __DIR__ . '/../../../includes/config_store.php';
        $cleanUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        // Clean schema config (delete the key + legacy file if no tables remain)
        $cfg = config_get('schema');
        if (is_array($cfg)) {
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
                config_delete('schema', $cleanUserId);
            } else {
                config_save('schema', $cfg, null, $cleanUserId);
            }
        }

        // Clean dashboard config (delete the key + legacy file if no widgets remain)
        $dashCfg = config_get('dashboard');
        if (is_array($dashCfg)) {
            $ids = $meta['widget_ids'] ?? [];
            $dashCfg['widgets'] = array_values(
                array_filter($dashCfg['widgets'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($dashCfg['widgets'])) {
                config_delete('dashboard', $cleanUserId);
            } else {
                config_save('dashboard', $dashCfg, null, $cleanUserId);
            }
        }

        // Clean calendar config (delete the key + legacy file if no sources remain)
        $calCfg = config_get('calendar');
        if (is_array($calCfg)) {
            $tbls = $meta['tables'] ?? [];
            $calCfg['sources'] = array_values(
                array_filter($calCfg['sources'] ?? [], fn($s) => !in_array($s['table'] ?? '', $tbls, true))
            );
            if (empty($calCfg['sources'])) {
                config_delete('calendar', $cleanUserId);
            } else {
                config_save('calendar', $calCfg, null, $cleanUserId);
            }
        }

        // Clean board config (remove only if it points at a demo table)
        $boardCfg = config_get('board');
        if (is_array($boardCfg)) {
            $tbls   = $meta['tables'] ?? [];
            $bTable = $boardCfg['table'] ?? ($meta['board_table'] ?? '');
            if ($bTable !== '' && in_array($bTable, $tbls, true)) {
                config_delete('board', $cleanUserId);
            }
        }

        // Clean anonymization config (drop rules pointing at demo tables; delete the
        // spw_config key — and the legacy file, so dual-read cannot resurrect it —
        // if no rules remain)
        $anonCfg = config_get('anonymization');
        if (is_array($anonCfg)) {
            $tbls = $meta['tables'] ?? [];
            $anonCfg['rules'] = array_values(
                array_filter($anonCfg['rules'] ?? [], fn($r) => !in_array($r['table'] ?? '', $tbls, true))
            );
            if (empty($anonCfg['rules'])) {
                config_delete('anonymization', $cleanUserId);
            } else {
                config_save('anonymization', $anonCfg, null, $cleanUserId);
            }
        }

        // Clean workflows config (delete the key + legacy file if none remain)
        $wfCfg = config_get('workflows');
        if (is_array($wfCfg)) {
            $ids = $meta['workflow_ids'] ?? [];
            $wfCfg['workflows'] = array_values(
                array_filter($wfCfg['workflows'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($wfCfg['workflows'])) {
                config_delete('workflows', $cleanUserId);
            } else {
                config_save('workflows', $wfCfg, null, $cleanUserId);
            }
        }

        // Clean views config (delete the key + legacy file if none remain)
        $viewsCfg = config_get('views');
        if (is_array($viewsCfg)) {
            foreach ($meta['view_keys'] ?? [] as $k) {
                unset($viewsCfg['views'][$k]);
            }
            if (empty($viewsCfg['views'])) {
                config_delete('views', $cleanUserId);
            } else {
                config_save('views', $viewsCfg, null, $cleanUserId);
            }
        }

        // Clean menu config (delete the key + legacy file if no items remain)
        $menuCfg = config_get('menu');
        if (is_array($menuCfg)) {
            $keys = $meta['menu_keys'] ?? [];
            if (!empty($keys) && isset($menuCfg['items']) && is_array($menuCfg['items'])) {
                $menuCfg['items'] = array_values(
                    array_filter($menuCfg['items'], fn($i) => !in_array($i['key'] ?? '', $keys, true))
                );
            }
            if (empty($menuCfg['items'])) {
                config_delete('menu', $cleanUserId);
            } else {
                config_save('menu', $menuCfg, null, $cleanUserId);
            }
        }

        // Clean automations config (delete the key + legacy file if no rules remain)
        $rawAuto = config_get('automations');
        if (is_array($rawAuto)) {
            $rules = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            $ids   = $meta['automation_ids'] ?? [];
            if (!empty($ids)) {
                $rules = array_values(array_filter($rules, fn($r) => !in_array($r['id'] ?? '', $ids, true)));
                if (empty($rules)) {
                    config_delete('automations', $cleanUserId);
                } else {
                    config_save('automations', ['automations' => $rules], null, $cleanUserId);
                }
            }
        }

        // Clean print config in the spw_config store (delete the key if no templates remain)
        require_once __DIR__ . '/../../../includes/config_store.php';
        $printCfg = config_get('print');
        if (is_array($printCfg)) {
            $keys = $meta['print_keys'] ?? [];
            foreach ($keys as $k) {
                unset($printCfg['prints'][$k]);
            }
            $cleanUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            if (empty($printCfg['prints'])) {
                config_delete('print', $cleanUserId);
                // Also drop the legacy file copy so the dual-read fallback cannot
                // resurrect the deleted demo templates from disk.
            } else {
                config_save('print', $printCfg, null, $cleanUserId);
            }
        }

        // Clean user_records config (drop column mappings for demo tables; delete the
        // spw_config key + legacy file if none remain)
        $urCfg = config_get('user_records');
        if (is_array($urCfg)) {
            $tbls = $meta['tables'] ?? [];
            foreach ($tbls as $t) {
                unset($urCfg['columns'][$t]);
            }
            if (empty($urCfg['columns'])) {
                config_delete('user_records', $cleanUserId);
            } else {
                config_save('user_records', $urCfg, null, $cleanUserId);
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
    if ($type !== 'crm') {
        throw new \InvalidArgumentException("Unknown demo type: {$type}");
    }
    require_once __DIR__ . '/crm.php';
    return demo_def_crm($conn);
}
