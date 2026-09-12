<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

const AUTOMATION_EMAILS_PAGE_LIMIT = 50;

const AUTOMATION_EMAILS_MAX_PAGE_LIMIT = 200;

const AUTOMATION_EMAILS_MAX_BULK = 500;

const AUTOMATION_EMAILS_STATUSES = ['pending', 'sent', 'error'];

function automation_emails_table(): string
{
    return sys_table('automation_emails');
}

function automation_emails_ids(array $body): array
{
    $rawIds = $body['ids'] ?? null;
    if (!is_array($rawIds) || $rawIds === []) {
        admin_err('No email IDs provided.');
    }
    $ids = array_values(array_unique(array_map('intval', $rawIds)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    if ($ids === [] || count($ids) > AUTOMATION_EMAILS_MAX_BULK) {
        admin_err('Provide between 1 and ' . AUTOMATION_EMAILS_MAX_BULK . ' valid email IDs.');
    }
    return $ids;
}

if ($action === 'automation_emails_list' && os_request()->method() === 'GET') {
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = automation_emails_table();
        admin_require_log_table($conn, $table);

        $status = trim(os_request()->query('status'));
        if ($status !== '' && !in_array($status, AUTOMATION_EMAILS_STATUSES, true)) {
            $status = '';
        }
        $page  = min(10000, max(1, (int) (os_request()->queryAll()['page'] ?? 1)));
        $limit = min(
            AUTOMATION_EMAILS_MAX_PAGE_LIMIT,
            max(1, (int) (os_request()->queryAll()['limit'] ?? AUTOMATION_EMAILS_PAGE_LIMIT))
        );

        $where  = '';
        $parameters = [];
        if ($status !== '') {
            $parameters[] = $status;
            $where = ' WHERE status = $1';
        }

        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$table}{$where}",
            $parameters
        );
        if (!$countResult) {
            admin_db_fail($conn, 'automation_emails_list:count');
        }
        $total = (int) pg_fetch_result($countResult, 0, 0);

        $parameters[] = $limit;
        $parameters[] = ($page - 1) * $limit;
        $rowsResult = @pg_query_params(
            $conn,
            "SELECT id, recipient, subject, status, attempts, error_msg, rule_id, source_table, record_id,
                    TO_CHAR(created_at, 'YYYY-MM-DD HH24:MI:SS') AS created_at,
                    TO_CHAR(sent_at, 'YYYY-MM-DD HH24:MI:SS') AS sent_at
               FROM {$table}
               {$where}
              ORDER BY id DESC
              LIMIT $" . (count($parameters) - 1) . " OFFSET $" . count($parameters),
            $parameters
        );
        if (!$rowsResult) {
            admin_db_fail($conn, 'automation_emails_list:rows');
        }

        $countsResult = @pg_query(
            $conn,
            "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status"
        );
        $counts = ['pending' => 0, 'sent' => 0, 'error' => 0];
        if ($countsResult) {
            while ($row = pg_fetch_assoc($countsResult)) {
                if (isset($counts[$row['status']])) {
                    $counts[$row['status']] = (int) $row['total'];
                }
            }
        }

        admin_ok([
            'rows'   => admin_fetch_all($rowsResult),
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'counts' => $counts,
        ]);
    });
}

if ($action === 'automation_emails_delete' && os_request()->method() === 'POST') {
    require_not_demo();
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = automation_emails_table();
        admin_require_log_table($conn, $table);

        $ids = automation_emails_ids(admin_input());
        $result = @pg_query_params(
            $conn,
            "DELETE FROM {$table} WHERE id = ANY(\$1::int[])",
            ['{' . implode(',', $ids) . '}']
        );
        if (!$result) {
            admin_db_fail($conn, 'automation_emails_delete');
        }
        admin_ok(['deleted' => pg_affected_rows($result)]);
    });
}

if ($action === 'automation_emails_requeue' && os_request()->method() === 'POST') {
    require_not_demo();
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = automation_emails_table();
        admin_require_log_table($conn, $table);

        $ids = automation_emails_ids(admin_input());
        $result = @pg_query_params(
            $conn,
            "UPDATE {$table} SET status = 'pending', attempts = 0, error_msg = NULL WHERE id = ANY(\$1::int[])",
            ['{' . implode(',', $ids) . '}']
        );
        if (!$result) {
            admin_db_fail($conn, 'automation_emails_requeue');
        }
        admin_ok(['requeued' => pg_affected_rows($result)]);
    });
}

if ($action === 'automation_emails_purge' && os_request()->method() === 'POST') {
    require_not_demo();
    admin_try(static function (): void {
        $conn  = admin_conn();
        $table = automation_emails_table();
        admin_require_log_table($conn, $table);

        $body   = admin_input();
        $status = trim((string) ($body['status'] ?? ''));
        if (!in_array($status, AUTOMATION_EMAILS_STATUSES, true)) {
            admin_err('Purge status must be one of: ' . implode(', ', AUTOMATION_EMAILS_STATUSES) . '.');
        }

        $days = null;
        if (array_key_exists('days', $body) && $body['days'] !== null && $body['days'] !== '') {
            $days = filter_var(
                $body['days'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => ADMIN_PURGE_MAX_DAYS]]
            );
            if ($days === false) {
                admin_err(
                    'Retention window must be a whole number of days between 1 and '
                    . ADMIN_PURGE_MAX_DAYS . '.'
                );
            }
        }

        $parameters = [$status];
        $sql = "DELETE FROM {$table} WHERE status = \$1";
        if ($days !== null) {
            $parameters[] = $days;
            $sql .= " AND created_at < NOW() - (\$2 || ' days')::interval";
        }

        $result = @pg_query_params($conn, $sql, $parameters);
        if (!$result) {
            admin_db_fail($conn, 'automation_emails_purge');
        }
        admin_ok(['deleted' => pg_affected_rows($result)]);
    });
}
