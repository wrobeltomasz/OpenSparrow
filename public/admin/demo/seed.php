<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    return;
}

if ($action === 'demo_status') {
    $metaPath = realpath(__DIR__ . '/../../../config') . '/demo_meta.json';

    $snapshotEnvironment = getenv('RECORD_SNAPSHOTS_ENABLED');
    $snapshotsLockedByEnvironment = ($snapshotEnvironment !== false && $snapshotEnvironment !== '');
    if (file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        echo json_encode([
            'status'    => 'success',
            'installed' => true,
            'meta'      => $meta,
            'snapshots_locked_by_env' => $snapshotsLockedByEnvironment,
        ]);
    } else {
        echo json_encode([
            'status'    => 'success',
            'installed' => false,
            'snapshots_locked_by_env' => $snapshotsLockedByEnvironment,
        ]);
    }
    throw ResponseException::sent();
}

function demo_install_run(
    string $type,
    bool $withRagDocuments = true,
    bool $withUsers = true,
    bool $withAudit = true
): array {
    try {
        require_once __DIR__ . '/../../../includes/db.php';
        $conn     = db_connect();
        $demoData = demo_get_definition($type, $conn);

        foreach ($demoData['ddl'] as $sql) {
            $result = @pg_query($conn, $sql);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:ddl:{$type}");
            }
        }

        foreach ($demoData['seed_data'] as $sql) {
            $result = @pg_query($conn, $sql);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:seed:{$type}");
            }
        }

        $demoUserPassword = 'test';
        $usersTable = sys_table('users');
        $demoUserIds = [];

        foreach (($withUsers ? $demoData['demo_users'] : []) as $userIndex => $demoUser) {
            $salt = bin2hex(random_bytes(32));
            $hash = password_hash($salt . $demoUserPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
            $result = pg_query_params($conn, "
                INSERT INTO $usersTable (
                    username, password_hash, salt, password_algo, password_params, is_active, role, avatar_id
                )
                VALUES (\$1, \$2, \$3, 'argon2id', \$4, true, \$5, \$6)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash, salt = EXCLUDED.salt,
                    password_params = EXCLUDED.password_params, is_active = true, role = EXCLUDED.role,
                    avatar_id = EXCLUDED.avatar_id
                RETURNING id
            ", [
                $demoUser['username'],
                $hash,
                $salt,
                json_encode(ARGON2_OPTIONS),
                $demoUser['role'],
                $demoUser['avatar_id'],
            ]);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:demo_users:{$type}");
            }
            $demoUserIds[$userIndex] = (int) pg_fetch_result($result, 0, 'id');
        }

        $fallbackUserId = (int) ($_SESSION['user_id'] ?? 0);
        $authorId = static fn(int $userIndex): int => $demoUserIds[$userIndex] ?? $fallbackUserId;

        $commentsTable = sys_table('comments');
        foreach (($withUsers ? $demoData['demo_comments'] : []) as $comment) {
            $result = pg_query_params($conn, "
                INSERT INTO $commentsTable (related_table, related_id, user_id, body) VALUES (\$1, \$2, \$3, \$4)
            ", [$comment['related_table'], $comment['related_id'], $demoUserIds[$comment['author']], $comment['body']]);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:demo_comments:{$type}");
            }
        }

        $notesTable = sys_table('notes');
        foreach (($withUsers ? $demoData['demo_notes'] : []) as $demoNote) {
            $result = pg_query_params($conn, "
                INSERT INTO $notesTable (user_id, related_table, related_id, body, reminder_date)
                VALUES (\$1, \$2, \$3, \$4, \$5)
            ", [
                $demoUserIds[$demoNote['author']],
                $demoNote['related_table'],
                $demoNote['related_id'],
                $demoNote['body'],
                $demoNote['reminder_date'] ?? null,
            ]);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:demo_notes:{$type}");
            }
        }

        $recordOwnersTable = sys_table('record_owners');
        $ownerChangedBy = (int) ($_SESSION['user_id'] ?? 0);
        foreach (($withUsers ? $demoData['demo_record_owners'] : []) as $recordOwner) {
            $result = pg_query_params($conn, "
                INSERT INTO $recordOwnersTable (table_name, record_id, owner_id, changed_by, is_current)
                VALUES (\$1, \$2, \$3, \$4, true)
            ", [
                $recordOwner['related_table'],
                $recordOwner['related_id'],
                $demoUserIds[$recordOwner['author']],
                $ownerChangedBy,
            ]);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:demo_record_owners:{$type}");
            }
        }

        $notificationsTable = sys_table('users_notifications');
        $notifyDate = date('Y-m-d');
        foreach (($withUsers ? $demoData['demo_notifications'] : []) as $note) {
            $link = 'edit.php?table=' . rawurlencode((string) $note['related_table'])
                . '&id=' . (int) $note['related_id'];
            $result = pg_query_params($conn, "
                INSERT INTO $notificationsTable (user_id, title, link, source_table, source_id, is_read, notify_date)
                VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7)
                ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING
            ", [
                $demoUserIds[$note['author']],
                $note['title'],
                $link,
                $note['related_table'],
                $note['related_id'],
                $note['is_read'] ? 't' : 'f',
                $notifyDate,
            ]);
            if ($result === false) {
                admin_db_fail($conn, "demo_install:demo_notifications:{$type}");
            }
        }

        $auditLogIds = [];
        if ($withAudit && $withUsers && !empty($demoData['demo_audit']) && is_array($demoData['demo_audit'])) {
            require_once __DIR__ . '/../../../includes/api_helpers.php';
            $usersLogTable = sys_table('users_log');
            $recordSnapshotsTable = sys_table('record_snapshots');
            $demoSchema = (string) $demoData['pg_schema'];
            $baseJson   = [];

            foreach ($demoData['demo_audit'] as $auditEntry) {
                $table    = (string) $auditEntry['table'];
                $recordId = (int) $auditEntry['record_id'];
                $cacheKey = $table . '#' . $recordId;
                if (!array_key_exists($cacheKey, $baseJson)) {
                    $rawJson = fetch_record_json($conn, $demoSchema, $table, $recordId);
                    $baseJson[$cacheKey] = is_string($rawJson) ? json_decode($rawJson, true) : null;
                }

                if (!is_array($baseJson[$cacheKey])) {
                    continue;
                }

                $createdAt  = date('Y-m-d H:i:s', strtotime('-' . (int) $auditEntry['days_ago'] . ' days'));
                $result = pg_query_params($conn, "
                    INSERT INTO $usersLogTable (user_id, action, target_table, record_id, created_at)
                    VALUES (\$1, \$2, \$3, \$4, \$5) RETURNING id
                ", [$demoUserIds[$auditEntry['author']], $auditEntry['action'], $table, $recordId, $createdAt]);
                if ($result === false) {
                    admin_db_fail($conn, "demo_install:demo_audit:{$type}");
                }
                $logId = (int) pg_fetch_result($result, 0, 'id');
                $auditLogIds[] = $logId;

                $snapshot = array_merge($baseJson[$cacheKey], $auditEntry['changes'] ?? []);
                $result = pg_query_params($conn, "
                    INSERT INTO $recordSnapshotsTable (log_id, table_name, record_id, snapshot, created_at)
                    VALUES (\$1, \$2, \$3, \$4, \$5)
                ", [$logId, $table, $recordId, json_encode($snapshot), $createdAt]);
                if ($result === false) {
                    admin_db_fail($conn, "demo_install:demo_audit_snapshots:{$type}");
                }
            }
        }

        $configDirectory = realpath(__DIR__ . '/../../../config');

        require_once __DIR__ . '/../../../includes/config_store.php';
        $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $schemaConfig = config_get('schema') ?? [];
        if (!isset($schemaConfig['tables']) || !is_array($schemaConfig['tables'])) {
            $schemaConfig['tables'] = [];
        }
        foreach ($demoData['schema_tables'] as $key => $definition) {
            $schemaConfig['tables'][$key] = $definition;
        }
        config_save('schema', $schemaConfig, null, $seedUserId);

        $dashConfig = config_get('dashboard') ?? [];
        if (!isset($dashConfig['widgets']) || !is_array($dashConfig['widgets'])) {
            $dashConfig['widgets'] = [];
        }
        if (!isset($dashConfig['layout'])) {
            $dashConfig['layout'] = ['gap' => '20px'];
        }
        foreach ($demoData['dashboard_widgets'] as $widget) {
            $widgetId = $widget['id'];
            $dashConfig['widgets'] = array_values(
                array_filter($dashConfig['widgets'], fn($existingWidget) => ($existingWidget['id'] ?? '') !== $widgetId)
            );
            $dashConfig['widgets'][] = $widget;
        }

        $dashConfigOrdered = [
            'layout' => $dashConfig['layout'],
            'widgets' => $dashConfig['widgets'],
        ];
        if (isset($dashConfig['menu_name'])) {
            $dashConfigOrdered['menu_name'] = $dashConfig['menu_name'];
        }
        if (isset($dashConfig['menu_icon'])) {
            $dashConfigOrdered['menu_icon'] = $dashConfig['menu_icon'];
        }
        if (isset($dashConfig['hidden'])) {
            $dashConfigOrdered['hidden'] = $dashConfig['hidden'];
        }
        config_save('dashboard', $dashConfigOrdered, null, $seedUserId);

        $calendarConfig = config_get('calendar') ?? [];
        if (!isset($calendarConfig['sources']) || !is_array($calendarConfig['sources'])) {
            $calendarConfig['sources'] = [];
        }
        $demoTables = array_keys($demoData['schema_tables']);
        $calendarConfig['sources'] = array_values(
            array_filter(
                $calendarConfig['sources'],
                fn($calendarSource) => !in_array($calendarSource['table'] ?? '', $demoTables, true)
            )
        );

        $installerUserId = (int)($_SESSION['user_id'] ?? 0);
        foreach ($demoData['calendar_sources'] as $calendarSource) {
            if ($installerUserId > 0 && empty($calendarSource['notified_users'])) {
                $calendarSource['notified_users'] = [$installerUserId];
            }
            $calendarConfig['sources'][] = $calendarSource;
        }
        config_save('calendar', $calendarConfig, null, $seedUserId);

        if (!empty($demoData['board']['boards']) && is_array($demoData['board']['boards'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $boardConfig = config_get('board') ?? [];
            if (!isset($boardConfig['boards']) || !is_array($boardConfig['boards'])) {
                $boardConfig['boards'] = [];
            }
            $boardConfig['boards'] = array_values(
                array_filter($boardConfig['boards'], fn($board) => !in_array($board['table'] ?? '', $demoTables, true))
            );
            foreach ($demoData['board']['boards'] as $board) {
                $boardConfig['boards'][] = $board;
            }
            config_save('board', $boardConfig, null, $seedUserId);
        }

        if (!empty($demoData['anonymization']) && is_array($demoData['anonymization'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $anonymizationConfig  = config_get('anonymization') ?? [];
            $demoAnonymization = $demoData['anonymization'];
            $anonymizationConfig['enabled']   = $anonymizationConfig['enabled']
                ?? ($demoAnonymization['enabled'] ?? false);
            $anonymizationConfig['frequency'] = $anonymizationConfig['frequency']
                ?? ($demoAnonymization['frequency'] ?? 'manual');
            $anonymizationConfig['dictionary'] = (isset($anonymizationConfig['dictionary'])
                && is_array($anonymizationConfig['dictionary']))
                ? $anonymizationConfig['dictionary']
                : ($demoAnonymization['dictionary'] ?? []);
            $demoAnonymizationTables = array_keys($demoData['schema_tables']);
            $rules = is_array($anonymizationConfig['rules'] ?? null) ? $anonymizationConfig['rules'] : [];
            $rules = array_values(
                array_filter($rules, fn($rule) => !in_array($rule['table'] ?? '', $demoAnonymizationTables, true))
            );
            foreach ($demoAnonymization['rules'] ?? [] as $rule) {
                $rules[] = $rule;
            }
            $anonymizationConfig['rules'] = $rules;
            $anonymizationConfigOrdered = [
                'enabled'   => $anonymizationConfig['enabled'],
                'frequency' => $anonymizationConfig['frequency'],
            ];

            if ($anonymizationConfig['dictionary']) {
                $anonymizationConfigOrdered['dictionary'] = $anonymizationConfig['dictionary'];
            }
            $anonymizationConfigOrdered['rules'] = $anonymizationConfig['rules'];
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('anonymization', $anonymizationConfigOrdered, null, $seedUserId);
        }

        $workflowsConfig = config_get('workflows') ?? [];
        if (!isset($workflowsConfig['workflows']) || !is_array($workflowsConfig['workflows'])) {
            $workflowsConfig['workflows'] = [];
        }
        foreach ($demoData['workflows'] as $workflow) {
            $workflowId = $workflow['id'];
            $workflowsConfig['workflows'] = array_values(
                array_filter(
                    $workflowsConfig['workflows'],
                    fn($existingWorkflow) => ($existingWorkflow['id'] ?? '') !== $workflowId
                )
            );
            $workflowsConfig['workflows'][] = $workflow;
        }

        if (!isset($workflowsConfig['menu_name'])) {
            $workflowsConfig['menu_name'] = 'Workflows';
        }
        if (!isset($workflowsConfig['menu_icon'])) {
            $workflowsConfig['menu_icon'] = 'assets/icons/automation.png';
        }

        $workflowConfigOrdered = [
            'workflows' => $workflowsConfig['workflows'],
            'menu_name' => $workflowsConfig['menu_name'],
            'menu_icon' => $workflowsConfig['menu_icon'],
        ];
        config_save('workflows', $workflowConfigOrdered, null, $seedUserId);

        $viewsConfig = config_get('views') ?? [];
        if (!isset($viewsConfig['views']) || !is_array($viewsConfig['views'])) {
            $viewsConfig['views'] = [];
        }
        foreach ($demoData['views'] as $key => $definition) {
            $viewsConfig['views'][$key] = $definition;
        }
        config_save('views', $viewsConfig, null, $seedUserId);

        if (!empty($demoData['files_relations']) && is_array($demoData['files_relations'])) {
            $filesConfig = config_get('files') ?? [];
            if (!isset($filesConfig['menu_name'])) {
                $filesConfig['menu_name'] = 'Files';
            }
            if (!isset($filesConfig['menu_icon'])) {
                $filesConfig['menu_icon'] = 'assets/icons/upload.png';
            }
            if (!isset($filesConfig['max_file_size_mb'])) {
                $filesConfig['max_file_size_mb'] = 20;
            }
            if (!isset($filesConfig['storage_path'])) {
                $filesConfig['storage_path'] = 'storage/files/';
            }
            if (!isset($filesConfig['allowed_types']) || !is_array($filesConfig['allowed_types'])) {
                $filesConfig['allowed_types'] = ['image', 'spreadsheet', 'archive', 'other'];
            }
            if (!isset($filesConfig['allowed_extensions']) || !is_array($filesConfig['allowed_extensions'])) {
                $filesConfig['allowed_extensions'] = [
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf',
                    'doc', 'docx', 'odt', 'rtf',
                    'xls', 'xlsx', 'ods', 'csv',
                    'zip', 'tar', 'gz',
                ];
            }
            if (!isset($filesConfig['relations']) || !is_array($filesConfig['relations'])) {
                $filesConfig['relations'] = [];
            }
            $existingTables = array_column($filesConfig['relations'], 'table');
            foreach ($demoData['files_relations'] as $relativePath) {
                if (!in_array($relativePath['table'] ?? '', $existingTables, true)) {
                    $filesConfig['relations'][] = $relativePath;
                }
            }
            config_save('files', $filesConfig, null, $seedUserId);
        }

        $demoFileIds   = [];
        $demoFilePaths = [];
        if (!empty($demoData['demo_files']) && is_array($demoData['demo_files'])) {
            $storagePath = trim((config_get('files') ?? [])['storage_path'] ?? 'storage/files/', '/');
            $repositoryRoot    = realpath(__DIR__ . '/../../../');
            $filesDirectory    = $repositoryRoot . '/' . $storagePath;
            os_ensure_directory($filesDirectory, 0750);
            os_write_guard_file($filesDirectory . '/.htaccess', "Require all denied\n");
            $filesTable = sys_table('files');
            foreach ($demoData['demo_files'] as $demoFile) {
                $physicalName = bin2hex(random_bytes(16)) . '.csv';
                $dbPath       = $storagePath . '/' . $physicalName;
                $result = pg_query_params($conn, "
                    INSERT INTO $filesTable
                        (name, display_name, type, mime_type, extension, size_bytes, storage_path,
                         uploaded_by, related_table, related_id, description)
                    VALUES
                        (\$1, \$1, 'spreadsheet', 'text/csv', 'csv', \$2, \$3, \$4, \$5, \$6, \$7)
                    RETURNING id
                ", [
                    $demoFile['filename'],
                    strlen($demoFile['content']),
                    $dbPath,
                    $authorId((int) $demoFile['author']),
                    $demoFile['related_table'],
                    $demoFile['related_id'],
                    $demoFile['description'] ?? null,
                ]);
                if ($result === false) {
                    admin_db_fail($conn, "demo_install:demo_files:{$type}");
                }
                file_put_contents($filesDirectory . '/' . $physicalName, $demoFile['content']);
                $demoFileIds[]   = (int) pg_fetch_result($result, 0, 'id');
                $demoFilePaths[] = $dbPath;
            }
        }

        $demoImageIds   = [];
        $demoImagePaths = [];
        if (!empty($demoData['demo_images']) && is_array($demoData['demo_images'])) {
            require_once __DIR__ . '/../../../includes/images.php';
            $storagePath = trim((config_get('files') ?? [])['storage_path'] ?? 'storage/files/', '/');
            $repositoryRoot    = realpath(__DIR__ . '/../../../');
            $filesDirectory    = $repositoryRoot . '/' . $storagePath;
            os_ensure_directory($filesDirectory, 0750);
            os_write_guard_file($filesDirectory . '/.htaccess', "Require all denied\n");
            $assetsDirectory = __DIR__ . '/assets/images';
            $filesTable    = sys_table('files');
            foreach ($demoData['demo_images'] as $image) {
                $sourcePath = $assetsDirectory . '/' . basename($image['source_file']);
                if (!is_file($sourcePath)) {
                    continue;
                }
                $content      = file_get_contents($sourcePath);
                $physicalName = bin2hex(random_bytes(16)) . '.png';
                $dbPath       = $storagePath . '/' . $physicalName;
                $result = pg_query_params($conn, "
                    INSERT INTO $filesTable
                        (name, display_name, type, mime_type, extension, size_bytes, storage_path,
                         uploaded_by, related_table, related_id, related_field)
                    VALUES
                        (\$1, \$2, 'image', 'image/png', 'png', \$3, \$4, \$5, \$6, \$7, \$8)
                    RETURNING id
                ", [
                    basename($image['source_file']),
                    $image['display_name'] ?? basename($image['source_file']),
                    strlen($content),
                    $dbPath,
                    $authorId((int) $image['author']),
                    $image['related_table'],
                    $image['related_id'],
                    IMAGES_FIELD,
                ]);
                if ($result === false) {
                    admin_db_fail($conn, "demo_install:demo_images:{$type}");
                }
                file_put_contents($filesDirectory . '/' . $physicalName, $content);
                $demoImageIds[]   = (int) pg_fetch_result($result, 0, 'id');
                $demoImagePaths[] = $dbPath;
            }
        }

        $ragFileIds = [];
        if ($withRagDocuments && !empty($demoData['rag_docs']) && is_array($demoData['rag_docs'])) {
            require_once __DIR__ . '/../../../includes/rag_helpers.php';
            $samplesDirectory = realpath(__DIR__ . '/../../../docs/rag-samples');
            $ragConfig     = rag_config();
            $ragFilesTable  = sys_table('rag_files');
            $ragUserId  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            foreach ($demoData['rag_docs'] as $document) {
                $name = (string) ($document['file'] ?? '');

                if ($samplesDirectory === false || $name === '' || basename($name) !== $name) {
                    continue;
                }
                $sourcePath = $samplesDirectory . '/' . $name;
                if (!is_file($sourcePath)) {
                    continue;
                }
                $content = file_get_contents($sourcePath);
                if ($content === false || trim($content) === '') {
                    continue;
                }
                $tag = trim((string) ($document['tag'] ?? ''));
                $result = @pg_query_params(
                    $conn,
                    "INSERT INTO {$ragFilesTable} (filename, content, tags, file_size, uploaded_by)
                     VALUES (\$1, \$2, \$3::text[], \$4, \$5) RETURNING id",
                    [
                        $name,
                        $content,
                        php_array_to_pg_text($tag !== '' ? [$tag] : []),
                        strlen($content),
                        $ragUserId,
                    ]
                );

                if ($result === false) {
                    error_log('demo_install: RAG doc insert failed for ' . $name . ' — ' . pg_last_error($conn));
                    continue;
                }
                $fileId = (int) pg_fetch_result($result, 0, 'id');
                if ((bool) ($ragConfig['use_chunks'] ?? true)) {
                    rag_store_chunks($conn, $fileId, $content, $ragConfig);
                }
                $ragFileIds[] = $fileId;
            }
        }

        if (!empty($demoData['rag_aggregate_views']) && is_array($demoData['rag_aggregate_views'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $ragViewConfig = config_get('rag') ?? [];
            $aggViews   = is_array($ragViewConfig['aggregate_views'] ?? null) ? $ragViewConfig['aggregate_views'] : [];
            foreach ($demoData['rag_aggregate_views'] as $aggTable => $aggView) {
                if (!empty($demoData['schema_tables'][$aggTable]['owner_restricted'])) {
                    continue;
                }
                $aggViews[$aggTable] = $aggView;
            }
            $ragViewConfig['aggregate_views'] = $aggViews;
            config_save('rag', $ragViewConfig, null, $seedUserId);
        }

        $menuKeys = [];
        if (!empty($demoData['menu_items']) && is_array($demoData['menu_items'])) {
            $menuConfig = config_get('menu') ?? [];
            if (!isset($menuConfig['items']) || !is_array($menuConfig['items'])) {
                $menuConfig['items'] = [];
            }
            foreach ($demoData['menu_items'] as $entry) {
                $menuKey = $entry['key'] ?? '';
                if ($menuKey === '') {
                    continue;
                }
                $menuKeys[] = $menuKey;
                $menuConfig['items'] = array_values(
                    array_filter($menuConfig['items'], fn($menuItem) => ($menuItem['key'] ?? '') !== $menuKey)
                );
                $menuConfig['items'][] = $entry;
            }
            config_save('menu', $menuConfig, null, $seedUserId);
        }

        $automationIds = [];
        if (!empty($demoData['automations']) && is_array($demoData['automations'])) {
            $rawAuto = config_get('automations') ?? [];
            $rules   = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            foreach ($demoData['automations'] as $rule) {
                $ruleId = $rule['id'] ?? '';
                if ($ruleId === '') {
                    continue;
                }
                $automationIds[] = $ruleId;
                $rules = array_values(array_filter($rules, fn($rule) => ($rule['id'] ?? '') !== $ruleId));
                $rules[] = $rule;
            }
            config_save('automations', ['automations' => $rules], null, $seedUserId);
        }

        $printKeys = [];
        if (!empty($demoData['prints']) && is_array($demoData['prints'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $printConfig = config_get('print') ?? [];
            if (!isset($printConfig['prints']) || !is_array($printConfig['prints'])) {
                $printConfig['prints'] = [];
            }
            foreach ($demoData['prints'] as $key => $definition) {
                $printKeys[] = $key;
                $printConfig['prints'][$key] = $definition;
            }
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('print', $printConfig, null, $seedUserId);
        }

        if (!empty($demoData['user_records']) && is_array($demoData['user_records'])) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $userRecordsConfig = config_get('user_records') ?? [];
            if (!isset($userRecordsConfig['columns']) || !is_array($userRecordsConfig['columns'])) {
                $userRecordsConfig['columns'] = [];
            }
            if (!isset($userRecordsConfig['limit'])) {
                $userRecordsConfig['limit'] = 20;
            }
            foreach ($demoData['user_records'] as $tableName => $columns) {
                $userRecordsConfig['columns'][$tableName] = $columns;
            }
            $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            config_save('user_records', $userRecordsConfig, null, $seedUserId);
        }

        $snapshotsEnabledByDemo = false;
        $snapshotEnvironment = getenv('RECORD_SNAPSHOTS_ENABLED');
        if ($withAudit && $withUsers && ($snapshotEnvironment === false || $snapshotEnvironment === '')) {
            require_once __DIR__ . '/../../../includes/config_store.php';
            $settingsConfig = config_get('settings') ?? [];
            if (empty($settingsConfig['record_snapshots_enabled'])) {
                $settingsConfig['record_snapshots_enabled'] = true;
                $seedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                config_save('settings', $settingsConfig, null, $seedUserId);
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
            $configDirectory . '/demo_meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_INSTALL', 'demo', null);
        return ['status' => 'success', 'meta' => $meta];
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Exception $exception) {
        return ['status' => 'error', 'error' => $exception->getMessage()];
    }
}

if ($action === 'demo_install') {
    if ($isDemoMode) {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $type    = $body['type']    ?? '';
    $confirm = $body['confirm'] ?? '';

    $withRag   = !isset($body['rag_docs'])   || (bool) $body['rag_docs'];
    $withUsers = !isset($body['demo_users']) || (bool) $body['demo_users'];

    $withAudit = (!isset($body['audit_history']) || (bool) $body['audit_history']) && $withUsers;

    if ($type !== 'crm') {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'Invalid demo type.']);
    }
    if ($confirm !== 'CONFIRM') {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'Confirmation required.']);
    }

    throw ResponseException::encoded(demo_install_run($type, $withRag, $withUsers, $withAudit));
}

if ($action === 'demo_uninstall') {
    if ($isDemoMode) {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $confirm = $body['confirm'] ?? '';
    if ($confirm !== 'CONFIRM') {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'Confirmation required.']);
    }

    $configDirectory = realpath(__DIR__ . '/../../../config');
    $metaPath = $configDirectory . '/demo_meta.json';
    if (!file_exists($metaPath)) {
        throw ResponseException::encoded(['status' => 'error', 'error' => 'No demo installed.']);
    }

    $meta = json_decode(file_get_contents($metaPath), true) ?? [];

    try {
        require_once __DIR__ . '/../../../includes/db.php';
        $conn = db_connect();

        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema === 'spw_crm') {
            @pg_query($conn, 'DROP SCHEMA IF EXISTS ' . pg_ident($pgSchema) . ' CASCADE');
        }

        foreach ($meta['tables'] ?? [] as $tableName) {
            @pg_query_params(
                $conn,
                'DELETE FROM ' . sys_table('record_owners') . ' WHERE table_name = $1',
                [$tableName]
            );
        }

        $demoFileIds = $meta['demo_file_ids'] ?? [];
        if (!empty($demoFileIds)) {
            $fileIdList = '{' . implode(',', array_map('intval', $demoFileIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('files') . ' WHERE id = ANY($1::int[])', [$fileIdList]);
        }
        $repositoryRoot = realpath(__DIR__ . '/../../../');
        foreach ($meta['demo_file_paths'] ?? [] as $demoPath) {
            $full = $repositoryRoot . '/' . $demoPath;
            if (is_file($full)) {
                @unlink($full);
            }
        }

        $demoImageIds = $meta['demo_image_ids'] ?? [];
        if (!empty($demoImageIds)) {
            $imageIdList = '{' . implode(',', array_map('intval', $demoImageIds)) . '}';
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('files') . ' WHERE id = ANY($1::int[])', [$imageIdList]);
        }
        foreach ($meta['demo_image_paths'] ?? [] as $demoPath) {
            $full = $repositoryRoot . '/' . $demoPath;
            if (is_file($full)) {
                @unlink($full);
            }
        }

        $ragFileIds = $meta['rag_file_ids'] ?? [];
        if (!empty($ragFileIds)) {
            $ragIdList = '{' . implode(',', array_map('intval', $ragFileIds)) . '}';
            $ragFilesTable = sys_table('rag_files');
            @pg_query_params($conn, "DELETE FROM {$ragFilesTable} WHERE id = ANY(\$1::int[])", [$ragIdList]);
        }

        $auditLogIds = $meta['audit_log_ids'] ?? [];
        if (!empty($auditLogIds)) {
            $logIdList = '{' . implode(',', array_map('intval', $auditLogIds)) . '}';
            $usersLogTable = sys_table('users_log');
            @pg_query_params($conn, "DELETE FROM {$usersLogTable} WHERE id = ANY(\$1::int[])", [$logIdList]);
        }

        $demoUserIds = $meta['demo_user_ids'] ?? [];
        if (!empty($demoUserIds)) {
            $idList = '{' . implode(',', array_map('intval', $demoUserIds)) . '}';
            @pg_query_params(
                $conn,
                'DELETE FROM ' . sys_table('comments') . ' WHERE user_id = ANY($1::int[])',
                [$idList]
            );
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('notes') . ' WHERE user_id = ANY($1::int[])', [$idList]);
            @pg_query_params(
                $conn,
                'DELETE FROM ' . sys_table('users_notifications') . ' WHERE user_id = ANY($1::int[])',
                [$idList]
            );
            @pg_query_params($conn, 'DELETE FROM ' . sys_table('users') . ' WHERE id = ANY($1::int[])', [$idList]);
        }

        $demoSchema = $meta['schema'] ?? '';
        if ($demoSchema !== '') {
            foreach ($meta['view_names'] ?? [] as $viewName) {
                if (!preg_match('/^v_demo_[a-z_]+$/', $viewName)) {
                    continue;
                }
                @pg_query($conn, 'DROP VIEW IF EXISTS ' . pg_ident($demoSchema) . '.' . pg_ident($viewName));
            }
        }

        require_once __DIR__ . '/../../../includes/config_store.php';
        $cleanUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if (!empty($meta['snapshots_enabled_by_demo'])) {
            $settingsConfig = config_get('settings') ?? [];
            if (!empty($settingsConfig['record_snapshots_enabled'])) {
                $settingsConfig['record_snapshots_enabled'] = false;
                config_save('settings', $settingsConfig, null, $cleanUserId);
            }
        }

        $config = config_get('schema');
        if (is_array($config)) {
            $m2mJunctions = [];
            foreach ($meta['tables'] ?? [] as $tableName) {
                foreach ($config['tables'][$tableName]['many_to_many'] ?? [] as $m2m) {
                    $junctionTable = $m2m['junction_table'] ?? '';
                    if ($junctionTable && !empty($config['tables'][$junctionTable]['hidden'])) {
                        $m2mJunctions[] = $junctionTable;
                    }
                }
                unset($config['tables'][$tableName]);
            }

            foreach ($m2mJunctions as $junctionTable) {
                if (isset($config['tables'][$junctionTable])) {
                    $stillUsed = false;
                    foreach ($config['tables'] as $tableConfig) {
                        foreach ($tableConfig['many_to_many'] ?? [] as $m2mDefinition) {
                            if (($m2mDefinition['junction_table'] ?? '') === $junctionTable) {
                                $stillUsed = true;
                                break 2;
                            }
                        }
                    }
                    if (!$stillUsed) {
                        unset($config['tables'][$junctionTable]);
                    }
                }
            }
            if (empty($config['tables'])) {
                config_delete('schema', $cleanUserId);
            } else {
                config_save('schema', $config, null, $cleanUserId);
            }
        }

        $dashConfig = config_get('dashboard');
        if (is_array($dashConfig)) {
            $ids = $meta['widget_ids'] ?? [];
            $dashConfig['widgets'] = array_values(
                array_filter($dashConfig['widgets'] ?? [], fn($widget) => !in_array($widget['id'] ?? '', $ids, true))
            );
            if (empty($dashConfig['widgets'])) {
                config_delete('dashboard', $cleanUserId);
            } else {
                config_save('dashboard', $dashConfig, null, $cleanUserId);
            }
        }

        $calendarConfig = config_get('calendar');
        if (is_array($calendarConfig)) {
            $tables = $meta['tables'] ?? [];
            $calendarConfig['sources'] = array_values(
                array_filter(
                    $calendarConfig['sources'] ?? [],
                    fn($calendarSource) => !in_array($calendarSource['table'] ?? '', $tables, true)
                )
            );
            if (empty($calendarConfig['sources'])) {
                config_delete('calendar', $cleanUserId);
            } else {
                config_save('calendar', $calendarConfig, null, $cleanUserId);
            }
        }

        $boardConfig = config_get('board');
        if (is_array($boardConfig) && !empty($boardConfig['boards'])) {
            $tables = $meta['tables'] ?? [];
            $ids  = $meta['board_ids'] ?? [];
            $boardConfig['boards'] = array_values(array_filter(
                $boardConfig['boards'],
                fn($board) => !in_array($board['id'] ?? '', $ids, true)
                    && !in_array($board['table'] ?? '', $tables, true)
            ));
            if (empty($boardConfig['boards'])) {
                config_delete('board', $cleanUserId);
            } else {
                config_save('board', $boardConfig, null, $cleanUserId);
            }
        }

        $anonymizationConfig = config_get('anonymization');
        if (is_array($anonymizationConfig)) {
            $tables = $meta['tables'] ?? [];
            $anonymizationConfig['rules'] = array_values(
                array_filter(
                    $anonymizationConfig['rules'] ?? [],
                    fn($rule) => !in_array($rule['table'] ?? '', $tables, true)
                )
            );
            if (empty($anonymizationConfig['rules'])) {
                config_delete('anonymization', $cleanUserId);
            } else {
                config_save('anonymization', $anonymizationConfig, null, $cleanUserId);
            }
        }

        $workflowsConfig = config_get('workflows');
        if (is_array($workflowsConfig)) {
            $ids = $meta['workflow_ids'] ?? [];
            $workflowsConfig['workflows'] = array_values(
                array_filter(
                    $workflowsConfig['workflows'] ?? [],
                    fn($existingWorkflow) => !in_array($existingWorkflow['id'] ?? '', $ids, true)
                )
            );
            if (empty($workflowsConfig['workflows'])) {
                config_delete('workflows', $cleanUserId);
            } else {
                config_save('workflows', $workflowsConfig, null, $cleanUserId);
            }
        }

        $viewsConfig = config_get('views');
        if (is_array($viewsConfig)) {
            foreach ($meta['view_keys'] ?? [] as $viewKey) {
                unset($viewsConfig['views'][$viewKey]);
            }
            if (empty($viewsConfig['views'])) {
                config_delete('views', $cleanUserId);
            } else {
                config_save('views', $viewsConfig, null, $cleanUserId);
            }
        }

        $menuConfig = config_get('menu');
        if (is_array($menuConfig)) {
            $keys = $meta['menu_keys'] ?? [];
            if (!empty($keys) && isset($menuConfig['items']) && is_array($menuConfig['items'])) {
                $menuConfig['items'] = array_values(
                    array_filter($menuConfig['items'], fn($menuItem) => !in_array($menuItem['key'] ?? '', $keys, true))
                );
            }
            if (empty($menuConfig['items'])) {
                config_delete('menu', $cleanUserId);
            } else {
                config_save('menu', $menuConfig, null, $cleanUserId);
            }
        }

        $rawAuto = config_get('automations');
        if (is_array($rawAuto)) {
            $rules = is_array($rawAuto['automations'] ?? null) ? $rawAuto['automations'] : [];
            $ids   = $meta['automation_ids'] ?? [];
            if (!empty($ids)) {
                $rules = array_values(array_filter($rules, fn($rule) => !in_array($rule['id'] ?? '', $ids, true)));
                if (empty($rules)) {
                    config_delete('automations', $cleanUserId);
                } else {
                    config_save('automations', ['automations' => $rules], null, $cleanUserId);
                }
            }
        }

        require_once __DIR__ . '/../../../includes/config_store.php';
        $printConfig = config_get('print');
        if (is_array($printConfig)) {
            $keys = $meta['print_keys'] ?? [];
            foreach ($keys as $printKey) {
                unset($printConfig['prints'][$printKey]);
            }
            $cleanUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            if (empty($printConfig['prints'])) {
                config_delete('print', $cleanUserId);
            } else {
                config_save('print', $printConfig, null, $cleanUserId);
            }
        }

        $ragViewConfig = config_get('rag');
        if (is_array($ragViewConfig) && !empty($ragViewConfig['aggregate_views'])) {
            $tables = $meta['tables'] ?? [];
            foreach ($tables as $tableName) {
                unset($ragViewConfig['aggregate_views'][$tableName]);
            }
            if (empty($ragViewConfig['aggregate_views'])) {
                unset($ragViewConfig['aggregate_views']);
            }
            config_save('rag', $ragViewConfig, null, $cleanUserId);
        }

        $userRecordsConfig = config_get('user_records');
        if (is_array($userRecordsConfig)) {
            $tables = $meta['tables'] ?? [];
            foreach ($tables as $tableName) {
                unset($userRecordsConfig['columns'][$tableName]);
            }
            if (empty($userRecordsConfig['columns'])) {
                config_delete('user_records', $cleanUserId);
            } else {
                config_save('user_records', $userRecordsConfig, null, $cleanUserId);
            }
        }

        @unlink($metaPath);
        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_UNINSTALL', 'demo', null);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Exception $exception) {
        echo json_encode(['status' => 'error', 'error' => $exception->getMessage()]);
    }
    throw ResponseException::sent();
}

function demo_get_definition(string $type, $conn): array
{
    if ($type !== 'crm') {
        throw new \InvalidArgumentException("Unknown demo type: {$type}");
    }
    require_once __DIR__ . '/crm.php';
    return demo_def_crm($conn);
}
