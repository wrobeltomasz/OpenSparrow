<?php

declare(strict_types=1);

// includes/admin/cron.php — admin api.php module: cron worker actions (run_cron_notifications, cron_log, cron_stats,
// cron_purge_log).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Run cron_notifications.php ad-hoc and return captured output
if ($action === 'run_cron_notifications') {
    require_not_demo('Demo mode — writes disabled.');
    admin_run_cron_script(__DIR__ . '/../../cron/cron_notifications.php', 'Cron script not found.');
}

if ($action === 'cron_log') {
    admin_try(static function (): void {
        $conn = admin_conn();
        $tLog = sys_table('users_notifications_log');
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $res = @pg_query($conn, "
            SELECT id,
                   TO_CHAR(started_at,  'YYYY-MM-DD HH24:MI:SS') AS started_at,
                   TO_CHAR(finished_at, 'YYYY-MM-DD HH24:MI:SS') AS finished_at,
                   status, triggered_by, sources_processed, notifications_created, error_message,
                   CASE WHEN finished_at IS NOT NULL
                        THEN ROUND(EXTRACT(EPOCH FROM (finished_at - started_at))::numeric, 1)
                        ELSE NULL END AS duration_sec
            FROM {$tLog}
            ORDER BY started_at DESC
            LIMIT {$limit}
        ");
        if (!$res) {
            admin_db_fail($conn, 'cron_log');
        }
        admin_ok(['rows' => admin_fetch_all($res)]);
    });
}

if ($action === 'cron_stats') {
    admin_try(static function (): void {
        $conn = admin_conn();
        $tN = sys_table('users_notifications');
        $tU = sys_table('users');

        $totRes = @pg_query($conn, "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE is_read = false) AS unread,
                COUNT(*) FILTER (WHERE is_read = false AND notify_date >= CURRENT_DATE) AS upcoming_unread,
                COUNT(*) FILTER (WHERE notify_date = CURRENT_DATE AND is_read = false) AS due_today
            FROM {$tN}
        ");
        if (!$totRes) {
            admin_db_fail($conn, 'cron_stats_total');
        }
        $totals = pg_fetch_assoc($totRes);

        $perUserRes = @pg_query($conn, "
            SELECT u.username, COUNT(n.id) AS unread_count
            FROM {$tN} n
            JOIN {$tU} u ON u.id = n.user_id
            WHERE n.is_read = false
            GROUP BY u.username
            ORDER BY unread_count DESC
            LIMIT 10
        ");
        if (!$perUserRes) {
            admin_db_fail($conn, 'cron_stats_per_user');
        }
        $perUser = admin_fetch_all($perUserRes);

        $lastRunRes = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI:SS') AS last_run,
                   status, notifications_created
            FROM " . sys_table('users_notifications_log') . "
            ORDER BY started_at DESC LIMIT 1
        ");
        $lastRun = ($lastRunRes && $r = pg_fetch_assoc($lastRunRes)) ? $r : null;

        admin_ok(['totals' => $totals, 'per_user' => $perUser, 'last_run' => $lastRun]);
    });
}

if ($action === 'cron_purge_log') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static fn() => admin_purge_log(sys_table('users_notifications_log'), 30, 'cron_purge_log'));
}
