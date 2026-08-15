<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_profile(\PgSql\Connection $conn, string $method, string $action, int $userId): never
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'update_avatar' && $method === 'POST') {
        frontapi_profile_update_avatar($conn, $body, $userId);
    }

    if ($action === 'change_password' && $method === 'POST') {
        frontapi_profile_change_password($conn, $body, $userId);
    }

    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

function frontapi_profile_update_avatar(\PgSql\Connection $conn, array $body, int $userId): never
{
    $avatarId = array_key_exists('avatar_id', $body) ? $body['avatar_id'] : false;
    if ($avatarId === false) {
        http_response_code(400);
        exit(json_encode(['error' => 'avatar_id required']));
    }

    $avatarMax = count(OS_AVATAR_COLORS);
    if ($avatarId !== null && (!is_int($avatarId) || $avatarId < 1 || $avatarId > $avatarMax)) {
        http_response_code(400);
        exit(json_encode(['error' => "avatar_id must be 1-$avatarMax or null"]));
    }

    $sql = 'UPDATE ' . sys_table('users') . ' SET avatar_id = $1 WHERE id = $2';
    $res = @pg_query_params($conn, $sql, [$avatarId, $userId]);
    if (!$res) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database error']));
    }

    $_SESSION['avatar_id'] = $avatarId;
    exit(json_encode(['ok' => true]));
}

function frontapi_profile_change_password(\PgSql\Connection $conn, array $body, int $userId): never
{
    $current = $body['current_password'] ?? '';
    $new     = $body['new_password'] ?? '';
    if ($current === '' || $new === '') {
        http_response_code(400);
        exit(json_encode(['error' => 'Both passwords are required.']));
    }
    if (strlen($new) < 8) {
        http_response_code(422);
        exit(json_encode(['error' => 'New password must be at least 8 characters.']));
    }

    $sqlFetch = 'SELECT password_hash, salt FROM ' . sys_table('users') . ' WHERE id = $1';
    $resFetch = @pg_query_params($conn, $sqlFetch, [$userId]);
    if (!$resFetch) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database error']));
    }

    $row = pg_fetch_assoc($resFetch);
    if (!is_array($row) || ($row['password_hash'] ?? null) === null) {
        http_response_code(401);
        exit(json_encode(['error' => 'Account no longer exists.']));
    }

    $salt     = $row['salt'] ?? '';
    $toVerify = $salt !== '' ? $salt . $current : $current;
    if (!password_verify($toVerify, $row['password_hash'])) {
        http_response_code(422);
        exit(json_encode(['error' => 'Current password is incorrect.']));
    }

    $newSalt    = bin2hex(random_bytes(32));
    $newHash    = password_hash($newSalt . $new, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $sqlUpd = 'UPDATE ' . sys_table('users')
        . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
    $params = [
        $newHash,
        $newSalt,
        'argon2id',
        json_encode(ARGON2_OPTIONS),
        $userId,
    ];
    $resUpd = @pg_query_params($conn, $sqlUpd, $params);
    if (!$resUpd) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database error']));
    }

    log_user_action($conn, $userId, 'CHANGE_PASSWORD');
    exit(json_encode(['ok' => true]));
}
