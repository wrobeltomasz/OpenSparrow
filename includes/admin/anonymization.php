<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/anonymization.php — admin api.php module: GDPR anonymization (anonymization_load/save,
// run_anonymization, preview_anonymization,
// anonymization_log, anonymization_purge_log).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'anonymization_load') {
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'    => false,
        'frequency'  => 'daily',
        'dictionary' => ['pesel', 'nip', 'email', 'phone', 'address', 'imie', 'nazwisko', 'name'],
        'rules'      => [],
    ];
    $row    = config_get_row('anonymization');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    echo json_encode(['status' => 'success', 'config' => $config, 'version' => $row['version'] ?? 0]);
    exit;
}

if ($action === 'anonymization_save') {
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON.']);
        exit;
    }
    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];
    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true)
            ? $data['frequency']
            : 'daily',
        'dictionary' => array_values(array_filter(
            array_map('trim', (array)($data['dictionary'] ?? []))
        )),
        'rules'     => [],
    ];
    foreach ((array)($data['rules'] ?? []) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $t  = trim((string)($rule['table']       ?? ''));
        $c  = trim((string)($rule['column']      ?? ''));
        $dc = trim((string)($rule['date_column'] ?? ''));
        $d  = (int)($rule['days'] ?? 0);
        $r  = (string)($rule['replacement'] ?? '');
        if ($t === '' || $c === '' || $dc === '' || $d < 1) {
            continue;
        }
        $config['rules'][] = [
            'table'       => $t,
            'date_column' => $dc,
            'days'        => $d,
            'column'      => $c,
            'replacement' => $r,
        ];
    }
    // Optimistic lock: the editor echoes back the version it loaded (the field is
    // stripped here — the whitelist rebuild above never copies it into $config).
    admin_config_save_versioned('anonymization', $config, admin_expected_version($data));
}

if ($action === 'run_anonymization') {
    require_not_demo('Demo mode — writes disabled.');
    admin_run_cron_script(
        __DIR__ . '/../../cron/cron_anonymization.php',
        'Anonymization cron script not found.'
    );
}

if ($action === 'preview_anonymization') {
    // Dry run — read-only COUNT(*), modifies no data, so it is allowed even in
    // demo mode. It still spawns a process, so it is POST-only ($postActions).
    admin_run_cron_script(
        __DIR__ . '/../../cron/cron_anonymization.php',
        'Anonymization cron script not found.',
        '',
        ['dry']
    );
}

if ($action === 'anonymization_log') {
    admin_try(static function (): void {
        $conn    = admin_conn();
        $tLog    = sys_table('anonymization_log');
        $tReport = sys_table('anonymization_report');
        admin_require_log_table($conn, $tLog);
        $cols = 'l.id, l.started_at, l.finished_at, l.status, l.triggered_by, l.rules_processed, '
              . 'l.rows_anonymized, l.error_message';
        $dur  = 'EXTRACT(EPOCH FROM (COALESCE(l.finished_at, now()) - l.started_at)) AS duration_sec';
        // Reports live in their own table (spw_anonymization_report); join the latest one per run.
        $res  = @pg_query(
            $conn,
            "SELECT {$cols}, r.report, {$dur}
             FROM {$tLog} l
             LEFT JOIN {$tReport} r ON r.log_id = l.id
             ORDER BY l.started_at DESC LIMIT 50"
        );
        if (!$res) {
            // Backward compatibility: report table not yet created (migration 2.9_anonymization_report).
            $res = @pg_query(
                $conn,
                "SELECT {$cols}, {$dur} FROM {$tLog} l ORDER BY l.started_at DESC LIMIT 50"
            );
        }
        if (!$res) {
            admin_db_fail($conn, 'anonymization_log');
        }
        admin_ok(['rows' => admin_fetch_all($res)]);
    });
}

if ($action === 'anonymization_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(
        sys_table('anonymization_log'),
        90,
        'anonymization_purge_log',
        'started_at',
        // Keep the report table in sync (best-effort: it may not exist on older installs).
        static function (\PgSql\Connection $conn, int $days): void {
            @pg_query_params(
                $conn,
                'DELETE FROM ' . sys_table('anonymization_report')
                    . " WHERE created_at < NOW() - ($1 || ' days')::interval",
                [$days]
            );
        }
    ));
}

// ── RAG Knowledge Base ────────────────────────────────────────────────────────
