<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/clickstats.php — admin api.php module: click statistics
// (clickstats_load, clickstats_save, clickstats_log, clickstats_purge_log).
// Config lives in the spw_config key "clickstats"; the recorded clicks live in
// sys_table('clickstats'), written by public/api/clickstats.php.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Every action block emits its own JSON response and exits.

// Rows returned by one page of the log.
const CLICKSTATS_LOG_LIMIT = 100;

// Entries in the "Top Elements" rollup.
const CLICKSTATS_TOP_LIMIT = 20;

// Highest page number the log will page to (a million rows deep). The pager never
// asks for more, but ?page= is a query parameter: without a ceiling a large enough
// value overflows the OFFSET multiplication into a float, which Postgres rejects —
// a 500 where an empty page is the honest answer.
const CLICKSTATS_MAX_PAGE = 10000;

// clickstats_settings() / clickstats_retention_days() and the retention constants.
// Shared with the collector endpoint and the cron sweep so all three agree on what
// the stored config means.
require_once __DIR__ . '/../clickstats.php';

if ($action === 'clickstats_load') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = sys_table('clickstats');

        // A fresh install has the config but not the table yet — report that
        // instead of failing, so the tab explains what to run.
        $exists = (bool) @pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0");
        $total  = null;
        if ($exists) {
            $res   = @pg_query($conn, "SELECT COUNT(*) FROM {$table}");
            $total = $res ? (int) pg_fetch_result($res, 0, 0) : null;
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
                // Normalised on the way in, so the cron never reads a window it
                // cannot use. Unlike the manual purge this one does not reject a
                // bad value: a config save must not fail over a field the form
                // bounds anyway, and the fallback here is the safe direction
                // (the default window, never "keep everything").
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

        // Both filters are optional free text matched case-insensitively. They are
        // bound as parameters — never concatenated — so they cannot reach the SQL.
        $element = trim((string) ($_GET['element'] ?? ''));
        $user    = trim((string) ($_GET['user'] ?? ''));
        $page    = min(CLICKSTATS_MAX_PAGE, max(1, (int) ($_GET['page'] ?? 1)));

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

        $countRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$table} c LEFT JOIN {$users} u ON u.id = c.user_id{$whereSql}",
            $params
        );
        if (!$countRes) {
            admin_db_fail($conn, 'clickstats_log:count');
        }
        $total = (int) pg_fetch_result($countRes, 0, 0);

        $params[] = CLICKSTATS_LOG_LIMIT;
        $params[] = ($page - 1) * CLICKSTATS_LOG_LIMIT;
        $rowsRes  = @pg_query_params(
            $conn,
            "SELECT c.id, u.username, c.element, c.page, c.table_name, c.record_id, c.created_at
               FROM {$table} c
               LEFT JOIN {$users} u ON u.id = c.user_id
               {$whereSql}
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT $" . (count($params) - 1) . " OFFSET $" . count($params),
            $params
        );
        if (!$rowsRes) {
            admin_db_fail($conn, 'clickstats_log:rows');
        }

        // The rollup answers the actual question ("which elements are used?") and is
        // deliberately unfiltered by paging — it summarises the whole filtered set.
        $topRes = @pg_query_params(
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
            'rows'  => admin_fetch_all($rowsRes),
            'top'   => $topRes ? admin_fetch_all($topRes) : [],
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
        // Before the migration there is nothing to purge; answer with the same hint
        // the Log tab gives rather than failing on a missing relation.
        admin_require_log_table($conn, $table);

        // On-demand purge, separate from the automatic window the notifications cron
        // applies (clickstats_purge_expired()): "days" trims to a window, {"all": true}
        // clears the log entirely — which is what Clear Log sends.
        //
        // Nothing here is implied. This is the one purge whose fallback branch deletes
        // every row, so it is never reached by a field being absent, misspelled or
        // unusable: admin_purge_scope() refuses a request that names neither, names
        // both, or names a window it cannot read. The refusal is an AdminApiMessage,
        // which admin_try() turns into the standard error envelope — nothing reaches
        // the DELETE below.
        // admin_purge_older_than(), not admin_purge_log(): the window is already
        // resolved above, and the wrapper would read and validate the body a second
        // time to arrive at the same number.
        $scope = admin_purge_scope(admin_input());
        if (is_int($scope)) {
            admin_purge_older_than($table, $scope, 'clickstats_purge_log', 'created_at');
        }

        $res = @pg_query($conn, "DELETE FROM {$table}");
        if (!$res) {
            admin_db_fail($conn, 'clickstats_purge_log:all');
        }
        admin_ok(['deleted' => pg_affected_rows($res)]);
    });
}
