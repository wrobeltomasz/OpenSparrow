<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\AppContext;
use PgSql\Connection;
use Throwable;

final class NotificationsController
{
    private readonly Connection $conn;

    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context)
    {
        $this->conn    = $context->connection();
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        $userId = $this->session->userId();
        $action = $this->request->query('action', 'get_count');

        try {
            if ($action === 'get_count') {
                $this->unreadCount($userId);
            }

            if ($action === 'get_list') {
                $this->recentList($userId);
            }

            if ($action === 'mark_read') {
                $this->markRead($userId);
            }

            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
        }
    }

    private function unreadCount(int $userId): void
    {
        $sql = 'SELECT COUNT(*) FROM ' . sys_table('users_notifications')
            . ' WHERE user_id = $1 AND is_read = FALSE';
        $result = pg_query_params($this->conn, $sql, [$userId]);
        $count = pg_fetch_result($result, 0, 0);
        throw ResponseException::encoded(['status' => 'success', 'count' => (int)$count]);
    }

    private function recentList(int $userId): void
    {
        $sql = 'SELECT * FROM ' . sys_table('users_notifications')
            . ' WHERE user_id = $1 ORDER BY is_read ASC, created_at DESC LIMIT ' . NOTIFICATIONS_DROPDOWN_LIMIT;
        $result = pg_query_params($this->conn, $sql, [$userId]);
        $notifications = pg_fetch_all($result) ?: [];
        throw ResponseException::encoded(['status' => 'success', 'notifications' => $notifications]);
    }

    private function markRead(int $userId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($data['id'] ?? 0);
        if ($notifId > 0) {
            $sql = 'UPDATE ' . sys_table('users_notifications')
                . ' SET is_read = TRUE WHERE id = $1 AND user_id = $2';
            pg_query_params($this->conn, $sql, [$notifId, $userId]);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
        throw ResponseException::sent();
    }
}
