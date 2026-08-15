<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    exit;
}

if ($action === 'demo_status') {
    $metaPath = realpath(__DIR__ . '/../../../config') . '/demo_meta.json';

    $snapEnv = getenv('RECORD_SNAPSHOTS_ENABLED');
    $snapshotsLockedByEnv = ($snapEnv !== false && $snapEnv !== '');
    if (file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        echo json_encode([
            'status'    => 'success',
            'installed' => true,
            'meta'      => $meta,
            'snapshots_locked_by_env' => $snapshotsLockedByEnv,
        ]);
    } else {
        echo json_encode([
            'status'    => 'success',
            'installed' => false,
            'snapshots_locked_by_env' => $snapshotsLockedByEnv,
        ]);
    }
    exit;
}

function demo_install_run(string $type, bool $withRagDocs = true, bool $withUsers = true, bool $withAudit = true): array
{
    try {
        require_once __DIR__ . '/../../../includes/db.php';
        $conn     = db_connect();
        $demoData = demo_get_definition($type, $conn);

        foreach ($demoData['ddl'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:ddl:{$type}");
            }
        }

        foreach ($demoData['seed_data'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:seed:{$type}");
            }
        }

        $demoUserPassword = 'test';
        $tUsers = sys_table('users');
        $demoUserIds = [];

        foreach (($withUsers ? $demoData['demo_users'] : []) as $i => $du) {
            $salt = bin2hex(random_bytes(32));
            $hash = password_hash($salt . $demoUserPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
            $res = pg_query_params($conn, "
                INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role, avatar_id)
                VALUES (\$1, \$2, \$3, 'argon2id', \$4, true, \$5, \$6)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash, salt = EXCLUDED.salt,
                    password_params = EXCLUDED.password_params, is_active = true, role = EXCLUDED.role,
                    avatar_id = EXCLUDED.avatar_id
                RETURNING id
            ", [$du['username'], $hash, $salt, json_encode(ARGON2_OPTIONS), $du['role'], $du['avatar_id']]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_users:{$type}");
            }
            $demoUserIds[$i] = (int) pg_fetch_result($res, 0, 'id');
        }

        $fallbackUserId = (int) ($_SESSION['user_id'] ?? 0);
        $authorId = static fn(int $i): int => $demoUserIds[$i] ?? $fallbackUserId;

        $tComments = sys_table('comments');
        foreach (($withUsers ? $demoData['demo_comments'] : []) as $c) {
            $res = pg_query_params($conn, "
                INSERT INTO $tComments (related_table, related_id, user_id, body) VALUES (\$1, \$2, \$3, \$4)
            ", [$c['related_table'], $c['related_id'], $demoUserIds[$c['author']], $c['body']]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_comments:{$type}");
            }
        }

        $tNotes = sys_table('notes');
        foreach (($withUsers ? $demoData['demo_notes'] : []) as $n) {
            $res = pg_query_params($conn, "
                INSERT INTO $tNotes (user_id, related_table, related_id, body, reminder_date) VALUES (\$1, \$2, \$3, \$4, \$5)
            ", [$demoUserIds[$n['author']], $n['related_table'], $n['related_id'], $n['body'], $n['reminder_date'] ?? null]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_notes:{$type}");
            }
        }

        $tOwners = sys_table('record_owners');
        $ownerChangedBy = (int) ($_SESSION['user_id'] ?? 0);
        foreach (($withUsers ? $demoData['demo_record_owners'] : []) as $o) {
            $res = pg_query_params($conn, "
                INSERT INTO $tOwners (table_name, record_id, owner_id, changed_by, is_current)
                VALUES (\$1, \$2, \$3, \$4, true)
            ", [$o['related_table'], $o['related_id'], $demoUserIds[$o['author']], $ownerChangedBy]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_record_owners:{$type}");
            }
        }

        $tNotifications = sys_table('users_notifications');
        $notifyDate = date('Y-m-d');
        foreach (($withUsers ? $demoData['demo_notifications'] : []) as $note) {
            $link = 'edit.php?table=' . rawurlencode((string) $note['related_table']) . '&id=' . (int) $note['related_id'];
            $res = pg_query_params($conn, "
                INSERT INTO $tNotifications (user_id, title, link, source_table, source_id, is_read, notify_date)
                VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)
                ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING
            ", [$demoUserIds[$note['author']], $note['title'], $link, $note['related_table'], $note['related_id'], $note['is_read'] ? 't' : 'f', $notifyDate]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_notifications:{$type}");
            }
        }

        $auditLogIds = [];
        if ($withAudit && $withUsers && !empty($demoData['demo_audit']) && is_array($demoData['demo_audit'])) {
            require_once __DIR__ . '/../../../includes/api_helpers.php';
            $tUsersLog = sys_table('users_log');
            $tSnapshots = sys_table('record_snapshots');
            $demoSchema = (string) $demoData['pg_schema'];
            $baseJson   = [];

            foreach ($demoData['demo_audit'] as $a) {
                $table    = (string) $a['table'];
                $recordId = (int) $a['record_id'];
                $cacheKey = $table . '#' . $recordId;
                if (!array_key_exists($cacheKey, $baseJson)) {
                    $raw = fetch_record_json($conn, $demoSchema, $table, $recordId);
                    $baseJson[$cacheKey] = is_string($raw) ? json_decode($raw, true) : null;
                }

                if (!is_array($baseJson[$cacheKey])) {
                    continue;
                }

                $at  = date('Y-m-d H:i:s', strtotime('-' . (int) $a['days_ago'] . ' days'));
                $res = pg_query_params($conn, "
                    INSERT INTO $tUsersLog (user_id, action, target_table, record_id, created_at)
                    VALUES (\$1, \$2, \$3, \$4, \$5) RETURNING id
                ", [$demoUserIds[$a['author']], $a['action'], $table, $recordId, $at]);
                if ($res === false) {
                    admin_db_fail($conn, "demo_install:demo_audit:{$type}");
                }
                $logId = (int) pg_fetch_result($res, 0, 'id');
                $auditLogIds[] = $logId;

                $snapshot = array_merge($baseJson[$cacheKey], $a['changes'] ?? []);
                $res = pg_query_params($conn, "
                    INSERT INTO $tSnapshots (log_id, table_name, record_id, snapshot, created_at)
                    VALUES (\$1, \$2, \$3, \$4, \$5)
                ", [$logId, $table, $recordId, json_encode($snapshot), $at]);
                if ($res === false) {
                    admin_db_fail($conn, "demo_install:demo_audit_snapshots:{$type}");
                }
            }
        }

        $configDir = realpath(__DIR__ . '/../../../config');

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

        $calCfg = config_get('calendar') ?? [];
        if (!isset($calCfg['sources']) || !is_array($calCfg['sources'])) {
            $calCfg['sources'] = [];
        }
        $demoTbls = array_keys($demoData['schema_tables']);
        $calCfg['sources'] = array_values(
            array_filter($calCfg['sources'], fn($s) => !in_array($s['table'] ?? '', $demoTbls, true))
        );

        $installerUid = (int)($_SESSION['user_id'] ?? 0);
        foreach ($demoData['calendar_sources'] as $s) {
            if ($installerUid > 0 && empty($s['notified_users'])) {
                $s['notified_users'] = [$installerUid];
            }
            $calCfg['sources'][] = $s;
        }
        config_save('calendar', $calCfg, null, $seedUserId);

        if (!empty($demoData['board']['boards']) && is_array($demoData['board']['boards'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $boardCfg = config_get('board') ?? [];
            if (!isset($boardCfg['boards']) || !is_array($boardCfg['boards'])) {
                $boardCfg['boards'] = [];
            }
            $boardCfg['boards'] = array_values(
                array_filter($boardCfg['boards'], fn($b) => !in_array($b['table'] ?? '', $demoTbls, true))
            );
            foreach ($demoData['board']['boards'] as $b) {
                $boardCfg['boards'][] = $b;
            }
            config_save('board', $boardCfg, null, $seedUserId);
        }

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
                'enabled'   => $anonCfg['enabled'],
                'frequency' => $anonCfg['frequency'],
            ];

            if ($anonCfg['dictionary']) {
                $anonCfgOrdered['dictionary'] = $anonCfg['dictionary'];
            }
            $anonCfgOrdered['rules'] = $anonCfg['rules'];
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('anonymization', $anonCfgOrdered, null, $seedUserId);
        }

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

        if (!isset($wfCfg['menu_name'])) {
            $wfCfg['menu_name'] = 'Workflows';
        }
        if (!isset($wfCfg['menu_icon'])) {
            $wfCfg['menu_icon'] = 'assets/icons/automation.png';
        }

        $wfCfgOrdered = ['workflows' => $wfCfg['workflows'], 'menu_name' => $wfCfg['menu_name'], 'menu_icon' => $wfCfg['menu_icon']];
        config_save('workflows', $wfCfgOrdered, null, $seedUserId);

        $viewsCfg = config_get('views') ?? [];
        if (!isset($viewsCfg['views']) || !is_array($viewsCfg['views'])) {
            $viewsCfg['views'] = [];
        }
        foreach ($demoData['views'] as $key => $def) {
            $viewsCfg['views'][$key] = $def;
        }
        config_save('views', $viewsCfg, null, $seedUserId);

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
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf',
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

        $demoFileIds   = [];
        $demoFilePaths = [];
        if (!empty($demoData['demo_files']) && is_array($demoData['demo_files'])) {
            $storagePath = trim((config_get('files') ?? [])['storage_path'] ?? 'storage/files/', '/');
            $repoRoot    = realpath(__DIR__ . '/../../../');
            $filesDir    = $repoRoot . '/' . $storagePath;
            if (!is_dir($filesDir)) {
                mkdir($filesDir, 0750, true);
                @file_put_contents($filesDir . '/.htaccess', "Require all denied\n");
            }
            $tFiles = sys_table('files');
            foreach ($demoData['demo_files'] as $f) {
                $physicalName = bin2hex(random_bytes(16)) . '.csv';
                $dbPath       = $storagePath . '/' . $physicalName;
                $res = pg_query_params($conn, "
                    INSERT INTO $tFiles
                        (name, display_name, type, mime_type, extension, size_bytes, storage_path, uploaded_by, related_table, related_id, description)
                    VALUES
                        (\$1, \$1, 'spreadsheet', 'text/csv', 'csv', \$2, \$3, \$4, \$5, \$6, \$7)
                    RETURNING id
                ", [
                    $f['filename'],
                    strlen($f['content']),
                    $dbPath,
                    $authorId((int) $f['author']),
                    $f['related_table'],
                    $f['related_id'],
                    $f['description'] ?? null,
                ]);
                if ($res === false) {
                    admin_db_fail($conn, "demo_install:demo_files:{$type}");
                }
                file_put_contents($filesDir . '/' . $physicalName, $f['content']);
                $demoFileIds[]   = (int) pg_fetch_result($res, 0, 'id');
                $demoFilePaths[] = $dbPath;
            }
        }

        $demoImageIds   = [];
        $demoImagePaths = [];
        if (!empty($demoData['demo_images']) && is_array($demoData['demo_images'])) {
            require_once __DIR__ . '/../../../includes/images.php';
            $storagePath = trim((config_get('files') ?? [])['storage_path'] ?? 'storage/files/', '/');
            $repoRoot    = realpath(__DIR__ . '/../../../');
            $filesDir    = $repoRoot . '/' . $storagePath;
            if (!is_dir($filesDir)) {
                mkdir($filesDir, 0750, true);
                @file_put_contents($filesDir . '/.htaccess', "Require all denied\n");
            }
            $assetsDir = __DIR__ . '/assets/images';
            $tFiles    = sys_table('files');
            foreach ($demoData['demo_images'] as $img) {
                $srcPath = $assetsDir . '/' . basename($img['source_file']);
                if (!is_file($srcPath)) {
                    continue;
                }
                $content      = file_get_contents($srcPath);
                $physicalName = bin2hex(random_bytes(16)) . '.png';
                $dbPath       = $storagePath . '/' . $physicalName;
                $res = pg_query_params($conn, "
                    INSERT INTO $tFiles
                        (name, display_name, type, mime_type, extension, size_bytes, storage_path, uploaded_by, related_table, related_id, related_field)
                    VALUES
                        (\$1, \$2, 'image', 'image/png', 'png', \$3, \$4, \$5, \$6, \$7, \$8)
                    RETURNING id
                ", [
                    basename($img['source_file']),
                    $img['display_name'] ?? basename($img['source_file']),
                    strlen($content),
                    $dbPath,
                    $authorId((int) $img['author']),
                    $img['related_table'],
                    $img['related_id'],
                    IMAGES_FIELD,
                ]);
                if ($res === false) {
                    admin_db_fail($conn, "demo_install:demo_images:{$type}");
                }
                file_put_contents($filesDir . '/' . $physicalName, $content);
                $demoImageIds[]   = (int) pg_fetch_result($res, 0, 'id');
                $demoImagePaths[] = $dbPath;
            }
        }

        $ragFileIds = [];
        if ($withRagDocs && !empty($demoData['rag_docs']) && is_array($demoData['rag_docs'])) {
            require_once __DIR__ . '/../../../includes/rag_helpers.php';
            $samplesDir = realpath(__DIR__ . '/../../../docs/rag-samples');
            $ragCfg     = rag_config();
            $tRagFiles  = sys_table('rag_files');
            $ragUserId  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            foreach ($demoData['rag_docs'] as $doc) {
                $name = (string) ($doc['file'] ?? '');

                if ($samplesDir === false || $name === '' || basename($name) !== $name) {
                    continue;
                }
                $srcPath = $samplesDir . '/' . $name;
                if (!is_file($srcPath)) {
                    continue;
                }
                $content = file_get_contents($srcPath);
                if ($content === false || trim($content) === '') {
                    continue;
                }
                $tag = trim((string) ($doc['tag'] ?? ''));
                $res = @pg_query_params(
                    $conn,
                    "INSERT INTO {$tRagFiles} (filename, content, tags, file_size, uploaded_by)
                     VALUES (\$1, \$2, \$3::text[], \$4, \$5) RETURNING id",
                    [
                        $name,
                        $content,
                        php_array_to_pg_text($tag !== '' ? [$tag] : []),
                        strlen($content),
                        $ragUserId,
                    ]
                );

                if ($res === false) {
                    error_log('demo_install: RAG doc insert failed for ' . $name . ' — ' . pg_last_error($conn));
                    continue;
                }
                $fileId = (int) pg_fetch_result($res, 0, 'id');
                if ((bool) ($ragCfg['use_chunks'] ?? true)) {
                    rag_store_chunks($conn, $fileId, $content, $ragCfg);
                }
                $ragFileIds[] = $fileId;
            }
        }

        if (!empty($demoData['rag_aggregate_views']) && is_array($demoData['rag_aggregate_views'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $ragViewCfg = config_get('rag') ?? [];
            $aggViews   = is_array($ragViewCfg['aggregate_views'] ?? null) ? $ragViewCfg['aggregate_views'] : [];
            foreach ($demoData['rag_aggregate_views'] as $aggTable => $aggView) {
                if (!empty($demoData['schema_tables'][$aggTable]['owner_restricted'])) {
                    continue;
                }
                $aggViews[$aggTable] = $aggView;
            }
            $ragViewCfg['aggregate_views'] = $aggViews;
            config_save('rag', $ragViewCfg, null, $seedUserId);
        }

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

        $snapshotsEnabledByDemo = false;
        $snapEnv = getenv('RECORD_SNAPSHOTS_ENABLED');
        if ($withAudit && $withUsers && ($snapEnv === false || $snapEnv === '')) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $setCfg = config_get('settings') ?? [];
            if (empty($setCfg['record_snapshots_enabled'])) {
                $setCfg['record_snapshots_enabled'] = true;
                $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                config_save('settings', $setCfg, null, $seedUserId);
                $snapshotsEnabledByDemo = true;
            }
        }

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
            'board_ids'      => array_column($demoData['board']['boards'] ?? [], 'id'),
            'demo_user_ids'  => $demoUserIds,
            'demo_usernames' => $withUsers ? array_column($demoData['demo_users'], 'username') : [],
            'audit_log_ids'  => $auditLogIds,
            'snapshots_enabled_by_demo' => $snapshotsEnabledByDemo,
            'demo_file_ids'  => $demoFileIds,
            'demo_file_paths' => $demoFilePaths,
            'demo_image_ids' => $demoImageIds,
            'demo_image_paths' => $demoImagePaths,
            'rag_file_ids'   => $ragFileIds,
        ];
        file_put_contents(
            $configDir . '/demo_meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_INSTALL', 'demo', null);
        return ['status' => 'success', 'meta' => $meta];
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

if ($action === 'demo_install') {
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $type    = $body['type']    ?? '';
    $confirm = $body['confirm'] ?? '';

    $withRag   = !isset($body['rag_docs'])   || (bool) $body['rag_docs'];
    $withUsers = !isset($body['demo_users']) || (bool) $body['demo_users'];

    $withAudit = (!isset($body['audit_history']) || (bool) $body['audit_history']) && $withUsers;

    if ($type !== 'crm') {
        echo json_encode(['status' => 'error', 'error' => 'Invalid demo type.']);
        exit;
    }
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    echo json_encode(demo_install_run($type, $withRag, $withUsers, $withAudit));
    exit;
}

if ($action === 'demo_uninstall') {
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

        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema === 'spw_crm') {
            @pg_query($conn, 'DROP SCHEMA IF EXISTS ' . pg_ident($pgSchema) . ' CASCADE');
        }

        foreach ($meta['tables'] ?? [] as $t) {
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('record_owners') . ' WHERE table_name = $1', [$t]);
        }

        $demoFileIds = $meta['demo_file_ids'] ?? [];
        if (!empty($demoFileIds)) {
            $fileIdList = '{' . implode(',', array_map('intval', $demoFileIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('files') . ' WHERE id = ANY($1::int[])', [$fileIdList]);
        }
        $repoRoot = realpath(__DIR__ . '/../../../');
        foreach ($meta['demo_file_paths'] ?? [] as $p) {
            $full = $repoRoot . '/' . $p;
            if (is_file($full)) {
                @unlink($full);
            }
        }

        $demoImageIds = $meta['demo_image_ids'] ?? [];
        if (!empty($demoImageIds)) {
            $imgIdList = '{' . implode(',', array_map('intval', $demoImageIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('files') . ' WHERE id = ANY($1::int[])', [$imgIdList]);
        }
        foreach ($meta['demo_image_paths'] ?? [] as $p) {
            $full = $repoRoot . '/' . $p;
            if (is_file($full)) {
                @unlink($full);
            }
        }

        $ragFileIds = $meta['rag_file_ids'] ?? [];
        if (!empty($ragFileIds)) {
            $ragIdList = '{' . implode(',', array_map('intval', $ragFileIds)) . '}';
            $tRagFiles = sys_table('rag_files');
            @pg_query_params($conn, "DELETE FROM {$tRagFiles} WHERE id = ANY(\$1::int[])", [$ragIdList]);
        }

        $auditLogIds = $meta['audit_log_ids'] ?? [];
        if (!empty($auditLogIds)) {
            $logIdList = '{' . implode(',', array_map('intval', $auditLogIds)) . '}';
            $tUsersLog = sys_table('users_log');
            @pg_query_params($conn, "DELETE FROM {$tUsersLog} WHERE id = ANY(\$1::int[])", [$logIdList]);
        }

        $demoUserIds = $meta['demo_user_ids'] ?? [];
        if (!empty($demoUserIds)) {
            $idList = '{' . implode(',', array_map('intval', $demoUserIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('comments') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('notes') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('users_notifications') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('users') . ' WHERE id = ANY($1::int[])', [$idList]);
        }

        $demoSchema = $meta['schema'] ?? '';
        if ($demoSchema !== '') {
            foreach ($meta['view_names'] ?? [] as $vName) {
                if (!preg_match('/^v_demo_[a-z_]+$/', $vName)) {
                    continue;
                }
                @pg_query($conn, 'DROP VIEW IF EXISTS ' . pg_ident($demoSchema) . '.' . pg_ident($vName));
            }
        }

        require_once __DIR__ . '/../../../includes/config_store.php';
        $cleanUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if (!empty($meta['snapshots_enabled_by_demo'])) {
            $setCfg = config_get('settings') ?? [];
            if (!empty($setCfg['record_snapshots_enabled'])) {
                $setCfg['record_snapshots_enabled'] = false;
                config_save('settings', $setCfg, null, $cleanUserId);
            }
        }

        $cfg = config_get('schema');
        if (is_array($cfg)) {
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

        $boardCfg = config_get('board');
        if (is_array($boardCfg) && !empty($boardCfg['boards'])) {
            $tbls = $meta['tables'] ?? [];
            $ids  = $meta['board_ids'] ?? [];
            $boardCfg['boards'] = array_values(array_filter(
                $boardCfg['boards'],
                fn($b) => !in_array($b['id'] ?? '', $ids, true)
                    && !in_array($b['table'] ?? '', $tbls, true)
            ));
            if (empty($boardCfg['boards'])) {
                config_delete('board', $cleanUserId);
            } else {
                config_save('board', $boardCfg, null, $cleanUserId);
            }
        }

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
            } else {
                config_save('print', $printCfg, null, $cleanUserId);
            }
        }

        $ragViewCfg = config_get('rag');
        if (is_array($ragViewCfg) && !empty($ragViewCfg['aggregate_views'])) {
            $tbls = $meta['tables'] ?? [];
            foreach ($tbls as $t) {
                unset($ragViewCfg['aggregate_views'][$t]);
            }
            if (empty($ragViewCfg['aggregate_views'])) {
                unset($ragViewCfg['aggregate_views']);
            }
            config_save('rag', $ragViewCfg, null, $cleanUserId);
        }

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

function demo_get_definition(string $type, $conn): array
{
    if ($type !== 'crm') {
        throw new \InvalidArgumentException("Unknown demo type: {$type}");
    }
    require_once __DIR__ . '/crm.php';
    return demo_def_crm($conn);
}
