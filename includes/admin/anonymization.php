<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

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
    admin_ok(['config' => $config, 'version' => $row['version'] ?? 0]);
}

if ($action === 'anonymization_save') {
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        admin_err('Invalid JSON.');
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
        $tableName  = trim((string)($rule['table']       ?? ''));
        $column  = trim((string)($rule['column']      ?? ''));
        $dateColumn = trim((string)($rule['date_column'] ?? ''));
        $days  = (int)($rule['days'] ?? 0);
        $replacement  = (string)($rule['replacement'] ?? '');
        if ($tableName === '' || $column === '' || $dateColumn === '' || $days < 1) {
            continue;
        }
        $config['rules'][] = [
            'table'       => $tableName,
            'date_column' => $dateColumn,
            'days'        => $days,
            'column'      => $column,
            'replacement' => $replacement,
        ];
    }

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
        $anonymizationLogTable    = sys_table('anonymization_log');
        $anonymizationReportTable = sys_table('anonymization_report');
        admin_require_log_table($conn, $anonymizationLogTable);
        $columns = 'l.id, l.started_at, l.finished_at, l.status, l.triggered_by, l.rules_processed, '
              . 'l.rows_anonymized, l.error_message';
        $durationExpression  = 'EXTRACT(EPOCH FROM (COALESCE(l.finished_at, now()) - l.started_at)) AS duration_sec';

        $result  = @pg_query(
            $conn,
            "SELECT {$columns}, r.report, {$durationExpression}
             FROM {$anonymizationLogTable} l
             LEFT JOIN {$anonymizationReportTable} r ON r.log_id = l.id
             ORDER BY l.started_at DESC LIMIT 50"
        );
        if (!$result) {
            $result = @pg_query(
                $conn,
                "SELECT {$columns}, {$durationExpression} FROM {$anonymizationLogTable} l ORDER BY l.started_at DESC LIMIT 50"
            );
        }
        if (!$result) {
            admin_db_fail($conn, 'anonymization_log');
        }
        admin_ok(['rows' => admin_fetch_all($result)]);
    });
}

if ($action === 'anonymization_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(
        sys_table('anonymization_log'),
        90,
        'anonymization_purge_log',
        'started_at',
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
