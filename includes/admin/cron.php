<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if ($action === 'run_cron_notifications') {
    require_not_demo('Demo mode — writes disabled.');
    admin_run_cron_script(__DIR__ . '/../../cron/cron_notifications.php', 'Cron script not found.');
}

if ($action === 'cron_log') {
    admin_try(static function (): void {
        $conn = admin_conn();
        $notificationsLogTable = sys_table('users_notifications_log');
        $limit = min(100, max(1, (int) (os_request()->queryAll()['limit'] ?? 50)));
        $result = @pg_query($conn, "
            SELECT id,
                   TO_CHAR(started_at,  'YYYY-MM-DD HH24:MI:SS') AS started_at,
                   TO_CHAR(finished_at, 'YYYY-MM-DD HH24:MI:SS') AS finished_at,
                   status, triggered_by, sources_processed, notifications_created, error_message,
                   CASE WHEN finished_at IS NOT NULL
                        THEN ROUND(EXTRACT(EPOCH FROM (finished_at - started_at))::numeric, 1)
                        ELSE NULL END AS duration_sec
            FROM {$notificationsLogTable}
            ORDER BY started_at DESC
            LIMIT {$limit}
        ");
        if (!$result) {
            admin_db_fail($conn, 'cron_log');
        }
        admin_ok(['rows' => admin_fetch_all($result)]);
    });
}

if ($action === 'cron_stats') {
    admin_try(static function (): void {
        $conn = admin_conn();
        $notificationsTable = sys_table('users_notifications');
        $usersTable = sys_table('users');

        $totalResult = @pg_query($conn, "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE is_read = false) AS unread,
                COUNT(*) FILTER (WHERE is_read = false AND notify_date >= CURRENT_DATE) AS upcoming_unread,
                COUNT(*) FILTER (WHERE notify_date = CURRENT_DATE AND is_read = false) AS due_today
            FROM {$notificationsTable}
        ");
        if (!$totalResult) {
            admin_db_fail($conn, 'cron_stats_total');
        }
        $totals = pg_fetch_assoc($totalResult);

        $perUserResult = @pg_query($conn, "
            SELECT u.username, COUNT(n.id) AS unread_count
            FROM {$notificationsTable} n
            JOIN {$usersTable} u ON u.id = n.user_id
            WHERE n.is_read = false
            GROUP BY u.username
            ORDER BY unread_count DESC
            LIMIT 10
        ");
        if (!$perUserResult) {
            admin_db_fail($conn, 'cron_stats_per_user');
        }
        $perUser = admin_fetch_all($perUserResult);

        $lastRunResult = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI:SS') AS last_run,
                   status, notifications_created
            FROM " . sys_table('users_notifications_log') . "
            ORDER BY started_at DESC LIMIT 1
        ");
        $lastRun = ($lastRunResult && $row = pg_fetch_assoc($lastRunResult)) ? $row : null;

        admin_ok(['totals' => $totals, 'per_user' => $perUser, 'last_run' => $lastRun]);
    });
}

if ($action === 'cron_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(sys_table('users_notifications_log'), 30, 'cron_purge_log'));
}
