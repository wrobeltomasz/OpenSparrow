<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap();

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? 'get_count';

try {
    if ($action === 'get_count') {
        $sql = 'SELECT COUNT(*) FROM ' . sys_table('users_notifications') . ' WHERE user_id = $1 AND is_read = FALSE';
        $res = pg_query_params($conn, $sql, [$userId]);
        $count = pg_fetch_result($res, 0, 0);
        echo json_encode(['status' => 'success', 'count' => (int)$count]);
        exit;
    }

    if ($action === 'get_list') {
        $sql = 'SELECT * FROM ' . sys_table('users_notifications') . ' WHERE user_id = $1 ORDER BY is_read ASC, created_at DESC LIMIT ' . NOTIFICATIONS_DROPDOWN_LIMIT;
        $res = pg_query_params($conn, $sql, [$userId]);
        $notifications = pg_fetch_all($res) ?: [];
        echo json_encode(['status' => 'success', 'notifications' => $notifications]);
        exit;
    }

    if ($action === 'mark_read') {
        $data = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($data['id'] ?? 0);
        if ($notifId > 0) {
            $sql = 'UPDATE ' . sys_table('users_notifications') . ' SET is_read = TRUE WHERE id = $1 AND user_id = $2';
            pg_query_params($conn, $sql, [$notifId, $userId]);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
