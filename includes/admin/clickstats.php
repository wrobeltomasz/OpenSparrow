<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

const CLICKSTATS_LOG_LIMIT = 100;

const CLICKSTATS_TOP_LIMIT = 20;

const CLICKSTATS_MAX_PAGE = 10000;

require_once __DIR__ . '/../clickstats.php';

if ($action === 'clickstats_load') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('clickstats');

        $exists = (bool) @pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0");
        $total  = null;
        if ($exists) {
            $result   = @pg_query($conn, "SELECT COUNT(*) FROM {$table}");
            $total = $result ? (int) pg_fetch_result($result, 0, 0) : null;
        }

        $row = config_get_row('clickstats');
        admin_ok([
            'config'       => clickstats_settings(),
            'version'      => $row['version'] ?? null,
            'table_exists' => $exists,
            'total'        => $total,
        ]);
    });
}

if ($action === 'clickstats_save') {
    require_not_demo();
    admin_try(static function (): void {
        $data = admin_input();
        admin_config_save_versioned(
            'clickstats',
            [
                'enabled'       => !empty($data['enabled']),
                'track_records' => !empty($data['track_records']),

                'retention_days' => clickstats_retention_days($data['retention_days'] ?? null),
            ],
            admin_expected_version($data),
            'Failed to save click statistics config.'
        );
    });
}

if ($action === 'clickstats_log') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('clickstats');
        $users = sys_table('users');
        admin_require_log_table($conn, $table);

        $element = trim(os_request()->query('element'));
        $user    = trim(os_request()->query('user'));
        $page    = min(CLICKSTATS_MAX_PAGE, max(1, (int) (os_request()->queryAll()['page'] ?? 1)));

        $where  = [];
        $params = [];
        if ($element !== '') {
            $params[] = '%' . $element . '%';
            $where[]  = 'c.element ILIKE $' . count($params);
        }
        if ($user !== '') {
            $params[] = '%' . $user . '%';
            $where[]  = 'u.username ILIKE $' . count($params);
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$table} c LEFT JOIN {$users} u ON u.id = c.user_id{$whereSql}",
            $params
        );
        if (!$countResult) {
            admin_db_fail($conn, 'clickstats_log:count');
        }
        $total = (int) pg_fetch_result($countResult, 0, 0);

        $params[] = CLICKSTATS_LOG_LIMIT;
        $params[] = ($page - 1) * CLICKSTATS_LOG_LIMIT;
        $rowsResult  = @pg_query_params(
            $conn,
            "SELECT c.id, u.username, c.element, c.page, c.table_name, c.record_id, c.created_at
               FROM {$table} c
               LEFT JOIN {$users} u ON u.id = c.user_id
               {$whereSql}
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT $" . (count($params) - 1) . " OFFSET $" . count($params),
            $params
        );
        if (!$rowsResult) {
            admin_db_fail($conn, 'clickstats_log:rows');
        }

        $topResult = @pg_query_params(
            $conn,
            "SELECT c.element, COUNT(*) AS clicks
               FROM {$table} c
               LEFT JOIN {$users} u ON u.id = c.user_id
               {$whereSql}
              GROUP BY c.element
              ORDER BY clicks DESC, c.element ASC
              LIMIT " . CLICKSTATS_TOP_LIMIT,
            array_slice($params, 0, count($params) - 2)
        );

        admin_ok([
            'rows'  => admin_fetch_all($rowsResult),
            'top'   => $topResult ? admin_fetch_all($topResult) : [],
            'total' => $total,
            'page'  => $page,
            'limit' => CLICKSTATS_LOG_LIMIT,
        ]);
    });
}

if ($action === 'clickstats_purge_log') {
    require_not_demo();
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('clickstats');

        admin_require_log_table($conn, $table);

        $scope = admin_purge_scope(admin_input());
        if (is_int($scope)) {
            admin_purge_older_than($table, $scope, 'clickstats_purge_log', 'created_at');
        }

        $result = @pg_query($conn, "DELETE FROM {$table}");
        if (!$result) {
            admin_db_fail($conn, 'clickstats_purge_log:all');
        }
        admin_ok(['deleted' => pg_affected_rows($result)]);
    });
}
