<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Exception\UnauthorizedException;

function frontapi_profile(\PgSql\Connection $conn, string $method, string $action, int $userId): never
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'update_avatar' && $method === 'POST') {
        frontapi_profile_update_avatar($conn, $body, $userId);
    }

    if ($action === 'change_password' && $method === 'POST') {
        frontapi_profile_change_password($conn, $body, $userId);
    }

    throw HttpException::fromStatus(405, 'Method not allowed');
}

function frontapi_profile_update_avatar(\PgSql\Connection $conn, array $body, int $userId): never
{
    $avatarId = array_key_exists('avatar_id', $body) ? $body['avatar_id'] : false;
    if ($avatarId === false) {
        throw new BadRequestException('avatar_id required');
    }

    $avatarColorCount = count(OS_AVATAR_COLORS);
    if ($avatarId !== null && (!is_int($avatarId) || $avatarId < 1 || $avatarId > $avatarColorCount)) {
        throw new BadRequestException("avatar_id must be 1-$avatarColorCount or null");
    }

    $sql = 'UPDATE ' . sys_table('users') . ' SET avatar_id = $1 WHERE id = $2';
    $result = @pg_query_params($conn, $sql, [$avatarId, $userId]);
    if (!$result) {
        throw new ServerErrorException('Database error');
    }

    $_SESSION['avatar_id'] = $avatarId;
    throw ResponseException::encoded(['ok' => true]);
}

function frontapi_profile_change_password(\PgSql\Connection $conn, array $body, int $userId): never
{
    $current = $body['current_password'] ?? '';
    $new     = $body['new_password'] ?? '';
    if ($current === '' || $new === '') {
        throw new BadRequestException('Both passwords are required.');
    }
    if (strlen($new) < PASSWORD_MIN_LENGTH) {
        throw HttpException::fromStatus(
            422,
            'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'
        );
    }

    $sqlFetch = 'SELECT password_hash, salt FROM ' . sys_table('users') . ' WHERE id = $1';
    $fetchResult = @pg_query_params($conn, $sqlFetch, [$userId]);
    if (!$fetchResult) {
        throw new ServerErrorException('Database error');
    }

    $row = pg_fetch_assoc($fetchResult);
    if (!is_array($row) || ($row['password_hash'] ?? null) === null) {
        throw new UnauthorizedException('Account no longer exists.');
    }

    $salt     = $row['salt'] ?? '';
    $toVerify = $salt !== '' ? $salt . $current : $current;
    if (!password_verify($toVerify, $row['password_hash'])) {
        throw HttpException::fromStatus(422, 'Current password is incorrect.');
    }

    $newSalt    = bin2hex(random_bytes(32));
    $newHash    = password_hash($newSalt . $new, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $sqlUpdate = 'UPDATE ' . sys_table('users')
        . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
    $parameters = [
        $newHash,
        $newSalt,
        'argon2id',
        json_encode(ARGON2_OPTIONS),
        $userId,
    ];
    $updateResult = @pg_query_params($conn, $sqlUpdate, $parameters);
    if (!$updateResult) {
        throw new ServerErrorException('Database error');
    }

    log_user_action($conn, $userId, 'CHANGE_PASSWORD');
    throw ResponseException::encoded(['ok' => true]);
}
