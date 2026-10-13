<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function user_info_fetch(PgSql\Connection $conn, string $username): ?array
{
    $sql = 'SELECT id, username, avatar_id, role, first_name, last_name, email, phone'
        . ' FROM ' . sys_table('users')
        . ' WHERE username = $1';
    $result = pg_query_params($conn, $sql, [$username]);
    if (!$result) {
        error_log('[user_info] lookup failed: ' . pg_last_error($conn));
        return null;
    }

    $row = pg_fetch_assoc($result);
    if (!$row) {
        return null;
    }

    return [
        'id'         => (int)$row['id'],
        'username'   => $row['username'],
        'avatar_id'  => $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null,
        'role'       => $row['role'],
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
        'email'      => $row['email'],
        'phone'      => $row['phone'],
    ];
}
