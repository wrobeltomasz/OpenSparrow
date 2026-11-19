<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/user_custom_fields.php';

function user_info_fetch(PgSql\Connection $conn, string $username): ?array
{
    $hasCustom = user_custom_column_present($conn);
    $columns = 'id, username, avatar_id, role, first_name, last_name, email, phone'
        . ($hasCustom ? ', custom_fields' : '');
    $sql = 'SELECT ' . $columns
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

    $user = [
        'id'         => (int)$row['id'],
        'username'   => $row['username'],
        'avatar_id'  => $row['avatar_id'] !== null ? (int)$row['avatar_id'] : null,
        'role'       => $row['role'],
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
        'email'      => $row['email'],
        'phone'      => $row['phone'],
    ];
    $user['custom_fields'] = $hasCustom
        ? user_custom_fields_resolve($row['custom_fields'] ?? null, user_custom_field_definitions())
        : [];

    return $user;
}
