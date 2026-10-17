<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\ApiRequest;
use App\Service\AppContext;
use PgSql\Connection;

final class ChatController
{
    private const BODY_MAX_LENGTH = 4000;

    private const USERS_LIMIT = 50;

    private readonly Connection $conn;

    private readonly array $body;

    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context, private readonly ApiRequest $api)
    {
        $this->conn    = $context->connection();
        $this->body    = $api->bodyAll();
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        os_api_dispatch($this->api->action, [
            'list'      => fn() => $this->listConversations(),
            'thread'    => fn() => $this->threadMessages(),
            'send'      => fn() => $this->sendMessage(),
            'hide'      => fn() => $this->hideMessage(),
            'users'     => fn() => $this->searchUsers(),
            'user_info' => fn() => $this->userInfo(),
        ], 'api_chat');
    }

    private function listConversations(): void
    {
        requireLogin();
        $userId = $this->session->userId();

        $sql = "
        SELECT DISTINCT ON (peer_id) peer_id, peer_username, peer_avatar_id, body, created_at
        FROM (
            SELECT
                CASE WHEN m.sender_id = \$1 THEN m.recipient_id ELSE m.sender_id END AS peer_id,
                u.username AS peer_username,
                u.avatar_id AS peer_avatar_id,
                m.body,
                m.created_at
            FROM " . sys_table('chat_messages') . " m
            JOIN " . sys_table('users') . " u
              ON u.id = CASE WHEN m.sender_id = \$1 THEN m.recipient_id ELSE m.sender_id END
            WHERE m.is_visible AND (m.sender_id = \$1 OR m.recipient_id = \$1)
        ) t
        ORDER BY peer_id, created_at DESC
    ";

        $result = pg_query_params($this->conn, $sql, [$userId]);
        if (!$result) {
            error_log('api_chat chat_action_list failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $conversations = [];
        while ($row = pg_fetch_assoc($result)) {
            $conversations[] = [
                'peer_id'        => (int)$row['peer_id'],
                'peer_username'  => $row['peer_username'],
                'peer_avatar_id' => $row['peer_avatar_id'] !== null ? (int)$row['peer_avatar_id'] : null,
                'body'           => $row['body'],
                'created_at'     => $row['created_at'],
            ];
        }

        jsonSuccess(['conversations' => $conversations]);
    }

    private function threadMessages(): void
    {
        requireLogin();
        $userId = $this->session->userId();
        $peerId = (int)$this->request->query('peer_id', '0');
        if ($peerId <= 0) {
            jsonError('peer_id must be a positive integer.', 400);
        }

        $sql = "
        SELECT m.id, m.sender_id, m.recipient_id, m.body, m.created_at,
               su.username AS sender_username, su.avatar_id AS sender_avatar_id
        FROM " . sys_table('chat_messages') . " m
        LEFT JOIN " . sys_table('users') . " su ON su.id = m.sender_id
        WHERE m.is_visible
          AND ((m.sender_id = \$1 AND m.recipient_id = \$2) OR (m.sender_id = \$2 AND m.recipient_id = \$1))
        ORDER BY m.created_at ASC, m.id ASC
    ";

        $result = pg_query_params($this->conn, $sql, [$userId, $peerId]);
        if (!$result) {
            error_log('api_chat chat_action_thread failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $messages = [];
        while ($row = pg_fetch_assoc($result)) {
            $messages[] = [
                'id'                => (int)$row['id'],
                'sender_id'         => (int)$row['sender_id'],
                'recipient_id'      => (int)$row['recipient_id'],
                'body'              => $row['body'],
                'created_at'        => $row['created_at'],
                'sender_username'   => $row['sender_username'],
                'sender_avatar_id'  => $row['sender_avatar_id'] !== null ? (int)$row['sender_avatar_id'] : null,
            ];
        }

        jsonSuccess(['messages' => $messages]);
    }

    private function sendMessage(): void
    {
        $body = $this->body;

        requireWrite();
        $userId     = $this->session->userId();
        $recipientId = (int)($body['recipient_id'] ?? 0);
        $rawBody    = trim($body['body'] ?? '');

        if ($recipientId <= 0) {
            jsonError('recipient_id must be a positive integer.', 400);
        }
        if ($recipientId === $userId) {
            jsonError('You cannot send a message to yourself.', 400);
        }
        if ($rawBody === '') {
            jsonError('Message body cannot be empty.', 400);
        }
        if (mb_strlen($rawBody) > self::BODY_MAX_LENGTH) {
            jsonError('Message exceeds maximum length of ' . self::BODY_MAX_LENGTH . ' characters.', 400);
        }

        $recipientResult = pg_query_params(
            $this->conn,
            'SELECT id, username FROM ' . sys_table('users')
            . ' WHERE id = $1 AND is_active = TRUE',
            [$recipientId]
        );
        if (!$recipientResult || pg_num_rows($recipientResult) === 0) {
            jsonError('Recipient not found.', 400);
        }
        $recipient = pg_fetch_assoc($recipientResult);

        $senderResult = pg_query_params(
            $this->conn,
            'SELECT username, avatar_id FROM ' . sys_table('users') . ' WHERE id = $1',
            [$userId]
        );
        $senderRow = $senderResult ? pg_fetch_assoc($senderResult) : false;
        $senderUsername = (string)($senderRow['username'] ?? '');
        $senderAvatarId = isset($senderRow['avatar_id']) && $senderRow['avatar_id'] !== null
            ? (int)$senderRow['avatar_id']
            : null;

        $sql = "
        INSERT INTO " . sys_table('chat_messages') . "
            (sender_id, recipient_id, body)
        VALUES (\$1, \$2, \$3)
        RETURNING id, created_at
    ";
        $result = pg_query_params($this->conn, $sql, [$userId, $recipientId, $rawBody]);
        if (!$result) {
            error_log('api_chat chat_action_send failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $inserted = pg_fetch_assoc($result);
        $messageId = (int)$inserted['id'];

        log_user_action($this->conn, $userId, 'CHAT_SEND', 'chat_messages', $messageId);

        $this->notifyRecipient((int)$recipient['id'], $messageId, $senderUsername);

        jsonSuccess(['message' => [
            'id'               => $messageId,
            'sender_id'        => $userId,
            'recipient_id'     => $recipientId,
            'body'             => $rawBody,
            'created_at'       => $inserted['created_at'],
            'sender_username'  => $senderUsername,
            'sender_avatar_id' => $senderAvatarId,
        ]], 201);
    }

    private function hideMessage(): void
    {
        $body = $this->body;

        requireWrite();
        $userId = $this->session->userId();
        $id     = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            jsonError('id is required.', 400);
        }

        $fetchSql = 'SELECT sender_id, recipient_id FROM ' . sys_table('chat_messages')
            . ' WHERE id = $1 AND is_visible = TRUE';
        $fetchResult = pg_query_params($this->conn, $fetchSql, [$id]);
        if (!$fetchResult || pg_num_rows($fetchResult) === 0) {
            jsonError('Message not found.', 404);
        }

        $row = pg_fetch_assoc($fetchResult);
        if ((int)$row['sender_id'] !== $userId && (int)$row['recipient_id'] !== $userId) {
            jsonError('Forbidden: you can only hide messages in your own conversations.', 403);
        }

        $sql = 'UPDATE ' . sys_table('chat_messages') . ' SET is_visible = FALSE WHERE id = $1 AND is_visible = TRUE';
        $result = pg_query_params($this->conn, $sql, [$id]);
        if (!$result) {
            error_log('api_chat chat_action_hide failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        log_user_action($this->conn, $userId, 'CHAT_HIDE', 'chat_messages', $id);
        jsonSuccess(['hidden' => true]);
    }

    private function searchUsers(): void
    {
        requireLogin();

        $query  = trim($this->request->query('q', ''));
        $length = mb_strlen($query);
        if ($length < 2 || $length > 50) {
            jsonSuccess(['users' => []]);
        }

        $escaped  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $contains = '%' . $escaped . '%';
        $prefix   = $escaped . '%';
        $userId   = $this->session->userId();

        $sql = 'SELECT id, username, avatar_id, role FROM ' . sys_table('users')
            . ' WHERE is_active = TRUE AND id <> $1 AND username ILIKE $2'
            . ' ORDER BY (username ILIKE $3) DESC, username ASC'
            . ' LIMIT ' . self::USERS_LIMIT;

        $result = pg_query_params($this->conn, $sql, [$userId, $contains, $prefix]);
        if (!$result) {
            error_log('api_chat chat_action_users failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $users = [];
        while ($row = pg_fetch_assoc($result)) {
            $users[] = [
                'id'        => (int)$row['id'],
                'username'  => $row['username'],
                'avatar_id' => $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null,
                'role'      => $row['role'],
            ];
        }

        jsonSuccess(['users' => $users]);
    }

    private function userInfo(): void
    {
        requireLogin();

        $username = trim($this->request->query('username', ''));
        if ($username === '' || mb_strlen($username) > 50) {
            jsonSuccess(['user' => null]);
        }

        require_once __DIR__ . '/../../user_info.php';

        jsonSuccess(['user' => user_info_fetch($this->conn, $username)]);
    }

    private function notifyRecipient(int $recipientId, int $messageId, string $senderUsername): void
    {
        $title = t('chat.new_message', ['user' => $senderUsername]);
        $link  = '?chat=1';

        $sql = 'INSERT INTO ' . sys_table('users_notifications')
            . ' (user_id, title, link, source_table, source_id, notify_date)'
            . ' VALUES ($1, $2, $3, $4, $5, CURRENT_DATE)'
            . ' ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING';

        $result = pg_query_params($this->conn, $sql, [
            $recipientId,
            $title,
            $link,
            'chat',
            $messageId,
        ]);
        if (!$result) {
            error_log('[api_chat][notify] failed: ' . pg_last_error($this->conn));
        }
    }
}
