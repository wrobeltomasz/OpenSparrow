<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Service\ApiConfigRepository;

if ($action === 'api_load') {
    require_once __DIR__ . '/../config_store.php';
    require_once __DIR__ . '/../autoload.php';
    $repository = new ApiConfigRepository();
    $loaded = $repository->load();
    admin_ok(['config' => $loaded['config'], 'version' => $loaded['version']]);
}

if ($action === 'api_save') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static function (): void {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            admin_err('Invalid JSON.');
        }
        require_once __DIR__ . '/../config_store.php';
        require_once __DIR__ . '/../crypto.php';
        require_once __DIR__ . '/../autoload.php';

        $repository = new ApiConfigRepository();
        $result = $repository->save($data, admin_expected_version($data), admin_user_id());
        if ($result['status'] === 'conflict') {
            admin_err('Config was modified by someone else — reload and retry.');
        }
        if ($result['status'] !== 'ok') {
            admin_err($result['error'] ?? 'Failed to save external API config.');
        }
        admin_ok([
            'version'        => $result['version'],
            'apis'           => $result['apis'],
            'generated_keys' => $result['generated_keys'],
        ]);
    });
}

if ($action === 'api_stats') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('external_api_log');
        admin_require_log_table($conn, $table);

        $totalResult = @pg_query(
            $conn,
            "SELECT COUNT(*) AS total, COALESCE(AVG(duration_ms), 0) AS avg_ms,
                    COALESCE(MAX(duration_ms), 0) AS max_ms
               FROM {$table}"
        );
        if (!$totalResult) {
            admin_db_fail($conn, 'api_stats:total');
        }
        $totalRow = pg_fetch_assoc($totalResult);

        $perApiResult = @pg_query(
            $conn,
            "SELECT api_id, api_name, table_name, COUNT(*) AS requests,
                    COALESCE(AVG(duration_ms), 0) AS avg_ms,
                    COALESCE(MAX(duration_ms), 0) AS max_ms,
                    COALESCE(SUM(rows_returned), 0) AS rows_total
               FROM {$table}
              GROUP BY api_id, api_name, table_name
              ORDER BY requests DESC, api_name ASC
              LIMIT 100"
        );
        if (!$perApiResult) {
            admin_db_fail($conn, 'api_stats:per_api');
        }

        admin_ok([
            'total'   => (int) ($totalRow['total'] ?? 0),
            'avg_ms'  => (int) round((float) ($totalRow['avg_ms'] ?? 0)),
            'max_ms'  => (int) ($totalRow['max_ms'] ?? 0),
            'per_api' => admin_fetch_all($perApiResult),
        ]);
    });
}

if ($action === 'api_log') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('external_api_log');
        admin_require_log_table($conn, $table);

        $api    = trim(os_request()->query('api'));
        $page   = min(10000, max(1, (int) (os_request()->queryAll()['page'] ?? 1)));
        $limit  = 100;

        $where  = [];
        $parameters = [];
        if ($api !== '') {
            $parameters[] = '%' . $api . '%';
            $where[]  = 'api_name ILIKE $' . count($parameters);
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$table}{$whereSql}",
            $parameters
        );
        if (!$countResult) {
            admin_db_fail($conn, 'api_log:count');
        }
        $total = (int) pg_fetch_result($countResult, 0, 0);

        $parameters[] = $limit;
        $parameters[] = ($page - 1) * $limit;
        $rowsResult = @pg_query_params(
            $conn,
            "SELECT id, api_id, api_name, table_name, status, rows_returned, duration_ms, created_at
               FROM {$table}
               {$whereSql}
              ORDER BY created_at DESC, id DESC
              LIMIT $" . (count($parameters) - 1) . " OFFSET $" . count($parameters),
            $parameters
        );
        if (!$rowsResult) {
            admin_db_fail($conn, 'api_log:rows');
        }

        admin_ok([
            'rows'  => admin_fetch_all($rowsResult),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    });
}

if ($action === 'api_purge_log') {
    require_not_demo();
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('external_api_log');

        admin_require_log_table($conn, $table);

        $scope = admin_purge_scope(admin_input());
        if (is_int($scope)) {
            admin_purge_older_than($table, $scope, 'api_purge_log', 'created_at');
        }

        $result = @pg_query($conn, "DELETE FROM {$table}");
        if (!$result) {
            admin_db_fail($conn, 'api_purge_log:all');
        }
        admin_ok(['deleted' => pg_affected_rows($result)]);
    });
}
