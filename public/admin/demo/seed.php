<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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
    $metaPath = realpath(__DIR__ . '/../../../config') . '/demo_meta.json';
    // The install form's "audit history" option needs to know whether the record
    // snapshot setting is pinned by the environment, so it can explain why it is
    // unavailable instead of silently doing nothing (same env gate as
    // includes/admin/settings.php's set_snapshot_setting).
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

/* ── Demo: install ───────────────────────────────────────────────── */
// demo_install_run() holds the actual install logic so it can be reused
// outside the admin/api.php request cycle (see public/setup_api.php, which
// calls it directly — after establishing a session for the freshly created
// admin account — to offer "install demo CRM" as part of the setup wizard).
//
// $withRagDocs loads the demo's sample knowledge-base documents into spw_rag_files.
// It defaults to true so the setup wizard gets them without a second checkbox; the
// admin Demo page passes the state of its own checkbox.
//
// $withUsers creates the demo user accounts and everything keyed to them: comments,
// personal notes, record ownership and notifications. It is a real opt-out, not a
// convenience one — the accounts share a fixed, publicly documented password, so an
// installation reachable from a network may not want them. With it off, file and
// image attachments are still installed but attributed to the installing admin.
//
// $withAudit backfills spw_users_log + spw_record_snapshots and turns the record
// snapshot setting on. It requires $withUsers (the log entries are attributed to the
// demo accounts) and is skipped when RECORD_SNAPSHOTS_ENABLED locks the setting.
function demo_install_run(string $type, bool $withRagDocs = true, bool $withUsers = true, bool $withAudit = true): array
{
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

        // Everything from here to the end of the notifications block is the demo's
        // collaboration layer, installed as a unit under $withUsers: the accounts plus
        // the comments, notes, ownership rows and notifications that carry their user_id.
        // $demoUserIds stays empty when it is skipped — see $authorId() below, which is
        // what the always-installed file/image attachments use to attribute an uploader.
        $demoUserPassword = 'test';
        $tUsers = sys_table('users');
        $demoUserIds = [];

        // Demo users — fixed password for all demo accounts, hashed the same way as
        // includes/admin/users.php's users_add (ARGON2_OPTIONS is defined in
        // includes/config.php, already loaded via the admin bootstrap chain).
        // ON CONFLICT ... RETURNING id makes this safe to re-run after a prior install.
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

        // Author resolver for the blocks that are installed regardless of $withUsers
        // (files, images): fall back to the installing admin when the demo accounts
        // were declined, so uploaded_by never lands on a non-existent user id.
        $fallbackUserId = (int) ($_SESSION['user_id'] ?? 0);
        $authorId = static fn(int $i): int => $demoUserIds[$i] ?? $fallbackUserId;

        // Demo comments — cross-user discussion threads on CRM records
        $tComments = sys_table('comments');
        foreach (($withUsers ? $demoData['demo_comments'] : []) as $c) {
            $res = pg_query_params($conn, "
                INSERT INTO $tComments (related_table, related_id, user_id, body) VALUES (\$1, \$2, \$3, \$4)
            ", [$c['related_table'], $c['related_id'], $demoUserIds[$c['author']], $c['body']]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_comments:{$type}");
            }
        }

        // Demo notes — per-user "My Notes"
        $tNotes = sys_table('notes');
        foreach (($withUsers ? $demoData['demo_notes'] : []) as $n) {
            $res = pg_query_params($conn, "
                INSERT INTO $tNotes (user_id, related_table, related_id, body, reminder_date) VALUES (\$1, \$2, \$3, \$4, \$5)
            ", [$demoUserIds[$n['author']], $n['related_table'], $n['related_id'], $n['body'], $n['reminder_date'] ?? null]);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:demo_notes:{$type}");
            }
        }

        // Demo record ownership ("My records" panel) — assigns each listed record to
        // its demo-user owner, mirroring api/owners.php's mass_set insert shape.
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

        // Demo notifications — pre-seeded bell-icon entries for the demo users
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

        // Demo audit trail — backdated spw_users_log entries with a matching
        // spw_record_snapshots row each, so Admin > Audit and the per-record history
        // have something to show before the first manual edit.
        //
        // Requires the demo accounts: the log rows are attributed to them, and the
        // caller already forces $withAudit off when $withUsers is off.
        //
        // Each snapshot is the record's CURRENT state (fetch_record_json) with the
        // definition's 'changes' overlay applied, so the historical rows track
        // seed_data automatically instead of duplicating it. The base JSON is fetched
        // once per record, not once per entry.
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
                // A record the seed data never created (or a definition typo) must not
                // sink the install — the CRM data is the point, the history is on top.
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

        // board config — a named list (boards[]), mirrors the structure produced
        // by the admin Board editor. Merge in any demo-defined boards without
        // disturbing boards the user already configured for their own tables.
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
                'enabled'   => $anonCfg['enabled'],
                'frequency' => $anonCfg['frequency'],
            ];
            // Persist the dictionary only when there is one. Writing an empty array
            // would beat the module defaults, since anonymization_load merges the
            // stored value over them.
            if ($anonCfg['dictionary']) {
                $anonCfgOrdered['dictionary'] = $anonCfg['dictionary'];
            }
            $anonCfgOrdered['rules'] = $anonCfg['rules'];
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
                // No 'svg': it is script-bearing markup, and file_download.php can
                // serve it inline. Keeping it off the allowlist means the refusal no
                // longer depends on the finfo content sniff, which is itself guarded
                // by class_exists('finfo') and absent on some builds.
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

        // Demo files — small CSV attachments on CRM records, written both to disk
        // (mirrors public/api/files.php's upload flow) and to spw_files. Reads the
        // files config fresh (rather than reusing $filesCfg above, which is only set
        // when files_relations is non-empty) so storage_path is always available.
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

        // Demo images — record image gallery entries (spw_files rows tagged
        // related_field = IMAGES_FIELD), sourced from static PNGs checked into
        // admin/demo/assets/images/ and copied into storage/files/, same convention
        // as demo_files above.
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

        // RAG knowledge base — load the demo's sample documents into spw_rag_files so the
        // "Ask AI" panel can retrieve them straight after install. Opt-out via $withRagDocs.
        //
        // This deliberately writes the same rows admin/rag.php's rag_upload would, rather
        // than calling that action: the upload handler is guarded by require_not_demo()
        // and reads $_FILES/$_SESSION, neither of which applies on the setup-wizard path.
        //
        // No network call happens here. Retrieval is PostgreSQL full-text search over
        // to_tsvector(content), so ingest is pure SQL — Ollama is needed only later, when
        // a question is actually answered. Installing on a host without Ollama is fine.
        $ragFileIds = [];
        if ($withRagDocs && !empty($demoData['rag_docs']) && is_array($demoData['rag_docs'])) {
            require_once __DIR__ . '/../../../includes/rag_helpers.php';
            $samplesDir = realpath(__DIR__ . '/../../../docs/rag-samples');
            $ragCfg     = rag_config();
            $tRagFiles  = sys_table('rag_files');
            $ragUserId  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            foreach ($demoData['rag_docs'] as $doc) {
                $name = (string) ($doc['file'] ?? '');
                // Definition-supplied name: keep it a plain basename inside the samples
                // directory — no separators, no traversal, no absolute path.
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
                // A missing knowledge base must not sink the whole demo install — the
                // CRM data is the point, the documents are a convenience on top.
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

        // RAG aggregate views (spw_config key "rag", section aggregate_views) — attach the
        // demo's aggregate views to their tables, exactly as Admin → RAG → Aggregate Views
        // would. Written whether or not the sample documents were requested: this is a
        // config mapping over the demo's own data, not a knowledge-base document. The
        // views themselves are created by the DDL above, so no existence check is needed.
        // Owner-restricted tables are skipped for the same reason the admin action rejects
        // them — a plain view has no session user to filter rows by.
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

        // settings config — turn record snapshots on so the history keeps growing past
        // the seeded entries. Only when the demo actually flipped it: an installation
        // that already had it on must not have it switched off again on uninstall, and
        // RECORD_SNAPSHOTS_ENABLED takes precedence over the stored value anyway
        // (includes/admin/settings.php), so writing it there would be a silent no-op.
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
    // Checkboxes on the admin Demo page; an absent body key means "yes", matching the
    // defaults the setup wizard gets.
    $withRag   = !isset($body['rag_docs'])   || (bool) $body['rag_docs'];
    $withUsers = !isset($body['demo_users']) || (bool) $body['demo_users'];
    // Audit history is attributed to the demo accounts, so it cannot outlive them —
    // enforced here as well as in the UI, which disables the checkbox.
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

/* ── Demo: uninstall ─────────────────────────────────────────────── */
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

        // Drop demo schema + all objects
        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema === 'spw_crm') {
            @pg_query($conn, 'DROP SCHEMA IF EXISTS ' . pg_ident($pgSchema) . ' CASCADE');
        }

        // Remove demo record ownership seeded on the (about to be dropped) demo CRM tables
        foreach ($meta['tables'] ?? [] as $t) {
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('record_owners') . ' WHERE table_name = $1', [$t]);
        }

        // Remove demo files — DB rows plus the physical files written under storage/files/
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

        // Remove demo images — DB rows plus the physical files
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

        // Remove the demo's RAG documents. Only the ids this install recorded are
        // touched, so a knowledge base the user built themselves survives. Chunks go
        // with them: spw_rag_chunks.file_id is ON DELETE CASCADE.
        $ragFileIds = $meta['rag_file_ids'] ?? [];
        if (!empty($ragFileIds)) {
            $ragIdList = '{' . implode(',', array_map('intval', $ragFileIds)) . '}';
            $tRagFiles = sys_table('rag_files');
            @pg_query_params($conn, "DELETE FROM {$tRagFiles} WHERE id = ANY(\$1::int[])", [$ragIdList]);
        }

        // Remove the demo's seeded audit history. Only the log ids this install
        // recorded are touched, so real audit rows survive; the snapshots go with them
        // via spw_record_snapshots.log_id ON DELETE CASCADE. This runs before the demo
        // users are deleted, but the order does not matter — spw_users_log.user_id has
        // no FK to spw_users, precisely so audit rows outlive the accounts.
        $auditLogIds = $meta['audit_log_ids'] ?? [];
        if (!empty($auditLogIds)) {
            $logIdList = '{' . implode(',', array_map('intval', $auditLogIds)) . '}';
            $tUsersLog = sys_table('users_log');
            @pg_query_params($conn, "DELETE FROM {$tUsersLog} WHERE id = ANY(\$1::int[])", [$logIdList]);
        }

        // Remove demo comments/notes/notifications/users seeded for the demo accounts
        $demoUserIds = $meta['demo_user_ids'] ?? [];
        if (!empty($demoUserIds)) {
            $idList = '{' . implode(',', array_map('intval', $demoUserIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('comments') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('notes') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('users_notifications') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('users') . ' WHERE id = ANY($1::int[])', [$idList]);
        }

        // Drop views. The DROP SCHEMA ... CASCADE above already takes them when the demo
        // owns its own schema; this stays for the case where pg_schema is the app schema,
        // where CASCADE never runs. The name pattern keeps the drop to demo views only.
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

        // Revert the record-snapshot setting only if the demo is the one that turned it
        // on — an install that found it already enabled leaves it that way.
        if (!empty($meta['snapshots_enabled_by_demo'])) {
            $setCfg = config_get('settings') ?? [];
            if (!empty($setCfg['record_snapshots_enabled'])) {
                $setCfg['record_snapshots_enabled'] = false;
                config_save('settings', $setCfg, null, $cleanUserId);
            }
        }

        // Clean schema config (delete the key if no tables remain)
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

        // Clean dashboard config (delete the key if no widgets remain)
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

        // Clean calendar config (delete the key if no sources remain)
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

        // Clean board config (remove only the demo-added board entries, keeping
        // any boards the user configured for their own tables)
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

        // Clean anonymization config (drop rules pointing at demo tables; delete the
        // spw_config key if no rules remain)
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

        // Clean workflows config (delete the key if none remain)
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

        // Clean views config (delete the key if none remain)
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

        // Clean menu config (delete the key if no items remain)
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

        // Clean automations config (delete the key if no rules remain)
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
            } else {
                config_save('print', $printCfg, null, $cleanUserId);
            }
        }

        // Clean RAG aggregate views (drop mappings pointing at demo tables). The "rag"
        // key itself is never deleted — unlike the module configs above it also holds the
        // user's global RAG settings (model, chunking, limits), which the demo never owned.
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

        // Clean user_records config (drop column mappings for demo tables; delete the
        // spw_config key if none remain)
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
