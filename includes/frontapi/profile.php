<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/profile.php — frontend API route module: the self-service account
// actions (?action=update_avatar, ?action=change_password).
//
// These run EARLIER than every other route in this API, and deliberately so: they are
// permitted for every authenticated user regardless of role, so they sit ahead of the
// admin block and the viewer read-only block in public/api.php. That ordering is the
// whole point of the branch and must be preserved — pinned by
// tests/Security/FrontApiGuardsTest.
//
// They take no FrontApiContext: the schema has not been loaded at that point, and
// neither action touches it. They act on the caller's own row in spw_users only, so
// the record id never comes from the request.

/**
 * Handles whichever self-service action was named, then exits.
 *
 * $action is already known to be one of the two; anything else never reaches here.
 * A non-POST request to either answers 405, as before the split.
 */
function frontapi_profile(\PgSql\Connection $conn, string $method, string $action, int $userId): never
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // POST: save the chosen avatar colour (palette index) or clear it (null = default colour)
    if ($action === 'update_avatar' && $method === 'POST') {
        frontapi_profile_update_avatar($conn, $body, $userId);
    }

    // POST: change own password — verify current, enforce minimum length, rehash
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
    // Bound by the palette itself (includes/page_helpers.php) so the two cannot drift.
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

    // A live session whose user row has since been deleted returns no row here.
    // Guard before password_verify(): under strict_types a null hash is a TypeError,
    // and this block runs outside the front controller's try/catch, so it would be a
    // blank 500.
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
