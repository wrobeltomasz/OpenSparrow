<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

const USER_ROLES = ['admin', 'editor', 'viewer'];

$adminActorId = admin_user_id() ?? 0;

function admin_user_policy(): array
{
    $policy = config_get('user_policy') ?? [];
    return [
        'min_password_length' => (int) ($policy['min_password_length'] ?? 8),
        'default_role' => in_array($policy['default_role'] ?? '', USER_ROLES, true)
            ? $policy['default_role']
            : 'editor',
    ];
}

function admin_user_contact_input(array $data): array
{
    foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
        if (isset($data[$field]) && !is_scalar($data[$field])) {
            return [[], 'Contact details must be text values.'];
        }
    }

    $first = trim((string)($data['first_name'] ?? ''));
    $last  = trim((string)($data['last_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
        return [[], 'First name and last name must be at most 100 characters.'];
    }
    if (mb_strlen($email) > 255) {
        return [[], 'Email must be at most 255 characters.'];
    }
    if (mb_strlen($phone) > 32) {
        return [[], 'Phone must be at most 32 characters.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [[], 'Invalid email address.'];
    }

    return [[
        $first !== '' ? $first : null,
        $last  !== '' ? $last  : null,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
    ], null];
}

function admin_user_schema_guard(string $error): void
{
    if (str_contains($error, 'is_active') || str_contains($error, 'does not exist')) {
        error_log('[admin_api][users:schema_guard] ' . $error);
        throw new AdminApiMessage(
            'Database schema is outdated or missing. Please initialize tables '
            . '(Migrations → Initialize System Tables).'
        );
    }
}

function admin_user_contact_columns(\PgSql\Connection $conn): bool
{
    static $present = null;
    if ($present === null) {
        $present = (bool) @pg_query(
            $conn,
            'SELECT first_name, last_name, email, phone FROM ' . sys_table('users') . ' LIMIT 0'
        );
    }
    return $present;
}

if ($action === 'users_list') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $hasContact = admin_user_contact_columns($conn);
        $columns = 'id, username, is_active, role'
            . ($hasContact ? ', first_name, last_name, email, phone' : '');
        $queryResult = @pg_query($conn, "SELECT {$columns} FROM " . sys_table('users') . ' ORDER BY id ASC');
        if (!$queryResult) {
            admin_user_schema_guard(pg_last_error($conn));
            admin_db_fail($conn, 'users_list');
        }

        $users = [];
        while ($row = pg_fetch_assoc($queryResult)) {
            $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true);
            $users[] = $row;
        }

        echo json_encode([
            'status'          => 'success',
            'users'           => $users,
            'contact_columns' => $hasContact,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_add') {
    require_not_demo();

    $policy = admin_user_policy();
    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role = in_array($data['role'] ?? '', USER_ROLES, true) ? $data['role'] : $policy['default_role'];

    if (empty($username) || empty($password)) {
        admin_err('Username and password are required.');
    }
    if (strlen($password) < $policy['min_password_length']) {
        echo json_encode([
            'status' => 'error',
            'error' => "Password must be at least {$policy['min_password_length']} characters.",
        ]);
        throw ResponseException::sent();
    }
    [$contact, $contactError] = admin_user_contact_input(is_array($data) ? $data : []);
    if ($contactError !== null) {
        admin_err($contactError);
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();

        $hasContact = admin_user_contact_columns($conn);
        if (!$hasContact && array_filter($contact, static fn($value) => $value !== null) !== []) {
            throw new AdminApiMessage(
                'Contact details need the 3.3_user_contact migration '
                . '(Migrations → Initialize System Tables). Leave them empty to create '
                . 'the user without them.'
            );
        }

        $newSalt = bin2hex(random_bytes(32));
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        $columns    = 'username, password_hash, salt, password_algo, password_params, is_active, role';
        $vals    = '$1, $2, $3, $4, $5, true, $6';
        $params  = [$username, $hash, $newSalt, 'argon2id', json_encode(ARGON2_OPTIONS), $role];
        if ($hasContact) {
            $columns  .= ', first_name, last_name, email, phone';
            $vals  .= ', $7, $8, $9, $10';
            $params = array_merge($params, $contact);
        }
        $sql = 'INSERT INTO ' . sys_table('users') . " ({$columns}) VALUES ({$vals}) RETURNING id";
        $queryResult = @pg_query_params($conn, $sql, $params);
        if (!$queryResult) {
            admin_user_schema_guard(pg_last_error($conn));
            admin_db_fail($conn, 'users_add');
        }
        $newRow = pg_fetch_assoc($queryResult);
        $newUserId = (int)($newRow['id'] ?? 0);
        log_user_action($conn, $adminActorId, 'ADD_USER', 'users', $newUserId);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_toggle') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $isActive = (bool)($data['is_active'] ?? false);
    if ($userId <= 0) {
        admin_err('Invalid user ID.');
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET is_active = $1 WHERE id = $2";
        $queryResult = @pg_query_params($conn, $sql, [$isActive ? 'true' : 'false', $userId]);
        if (!$queryResult) {
            admin_db_fail($conn, 'users_toggle');
        }
        log_user_action($conn, $adminActorId, 'TOGGLE_USER', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_update_role') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $role = in_array($data['role'] ?? '', USER_ROLES, true) ? $data['role'] : admin_user_policy()['default_role'];

    if ($userId <= 0) {
        admin_err('Invalid user ID.');
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET role = $1 WHERE id = $2";
        $queryResult = @pg_query_params($conn, $sql, [$role, $userId]);
        if (!$queryResult) {
            admin_db_fail($conn, 'users_update_role');
        }
        log_user_action($conn, $adminActorId, 'UPDATE_ROLE', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_update_contact') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);

    if ($userId <= 0) {
        admin_err('Invalid user ID.');
    }
    [$contact, $contactError] = admin_user_contact_input(is_array($data) ? $data : []);
    if ($contactError !== null) {
        admin_err($contactError);
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();

        if (!admin_user_contact_columns($conn)) {
            throw new AdminApiMessage(
                'Contact details need the 3.3_user_contact migration '
                . '(Migrations → Initialize System Tables).'
            );
        }
        $sql = 'UPDATE ' . sys_table('users')
            . ' SET first_name = $1, last_name = $2, email = $3, phone = $4 WHERE id = $5';
        $queryResult = @pg_query_params($conn, $sql, array_merge($contact, [$userId]));
        if (!$queryResult) {
            admin_user_schema_guard(pg_last_error($conn));
            admin_db_fail($conn, 'users_update_contact');
        }

        if (pg_affected_rows($queryResult) === 0) {
            admin_err('User not found.');
        }
        log_user_action($conn, $adminActorId, 'UPDATE_USER_CONTACT', 'users', $userId);
        echo json_encode([
            'status' => 'success',
            'user' => [
                'id' => $userId,
                'first_name' => $contact[0],
                'last_name' => $contact[1],
                'email' => $contact[2],
                'phone' => $contact[3],
            ],
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_change_password') {
    require_not_demo();

    $data     = json_decode(file_get_contents('php://input'), true);
    $userId   = (int)($data['id'] ?? 0);
    $password = $data['password'] ?? '';

    if ($userId <= 0 || $password === '') {
        admin_err('User ID and password are required.');
    }
    $minimumLength = admin_user_policy()['min_password_length'];
    if (strlen($password) < $minimumLength) {
        admin_err("Password must be at least {$minimumLength} characters.");
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        $sql     = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $queryResult = @pg_query_params($conn, $sql, [
            $hash, $newSalt, 'argon2id',
            json_encode(ARGON2_OPTIONS),
            $userId,
        ]);
        if (!$queryResult) {
            admin_db_fail($conn, 'users_change_password');
        }
        log_user_action($conn, $adminActorId, 'CHANGE_PASSWORD', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'users_stats') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn    = db_connect();
        $usersTable  = sys_table('users');
        $usersLogTable    = sys_table('users_log');

        $countResult = @pg_query(
            $conn,
            "SELECT role, is_active, COUNT(*) AS c FROM {$usersTable} GROUP BY role, is_active"
        );
        if (!$countResult) {
            admin_db_fail($conn, 'users_stats');
        }

        $total = 0;
        $active = 0;
        $byRole = ['admin' => 0, 'editor' => 0, 'viewer' => 0];
        while ($row = pg_fetch_assoc($countResult)) {
            $count = (int) $row['c'];
            $total += $count;
            if ($row['is_active'] === 't' || $row['is_active'] === true) {
                $active += $count;
            }
            $role = in_array($row['role'] ?? '', USER_ROLES, true) ? $row['role'] : 'editor';
            $byRole[$role] += $count;
        }

        $ragCountResult = @pg_query($conn, "
            SELECT ul.action, ul.target_table,
                   TO_CHAR(ul.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   u.username
            FROM {$usersLogTable} ul
            LEFT JOIN {$usersTable} u ON u.id = ul.user_id
            WHERE ul.target_table = 'users'
            ORDER BY ul.created_at DESC
            LIMIT 10
        ");
        $recent = [];
        if ($ragCountResult) {
            while ($row = pg_fetch_assoc($ragCountResult)) {
                $recent[] = $row;
            }
        }

        echo json_encode([
            'status' => 'success',
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'by_role' => $byRole,
            'recent' => $recent,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'user_policy_get') {
    throw ResponseException::encoded(['status' => 'success'] + admin_user_policy());
}

if ($action === 'user_policy_save') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $minimumLength = (int) ($data['min_password_length'] ?? 0);
    $defaultRole = $data['default_role'] ?? '';

    if ($minimumLength < 6) {
        admin_err('Minimum password length must be at least 6.');
    }
    if (!in_array($defaultRole, USER_ROLES, true)) {
        admin_err('Invalid default role.');
    }

    $result = config_save('user_policy', [
        'min_password_length' => $minimumLength,
        'default_role' => $defaultRole,
    ], null, $adminActorId ?: null);
    if ($result['status'] !== 'ok') {
        admin_err($result['error'] ?? 'Save failed.');
    }

    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/api_helpers.php';
    log_user_action(db_connect(), $adminActorId, 'UPDATE_USER_POLICY', 'users', 0);
    admin_ok();
}

require_once __DIR__ . '/../api_helpers.php';

function admin_user_table_access(): array
{
    $cfg = config_get('user_table_access') ?? [];
    return is_array($cfg['users'] ?? null) ? $cfg['users'] : [];
}

function admin_user_access_entry(int $userId): array
{
    $entry = admin_user_table_access()[(string) $userId] ?? null;
    if (is_array($entry) && array_is_list($entry)) {
        $entry = ['tables' => $entry];
    }
    $output = [];
    foreach (array_keys(USER_ACCESS_SCOPES) as $scope) {
        $list        = is_array($entry[$scope] ?? null) ? $entry[$scope] : [];
        $output[$scope] = array_values(array_filter($list, 'is_string'));
    }
    return $output;
}

function admin_assignable_items(): array
{
    $output = [];
    foreach (array_keys(USER_ACCESS_SCOPES) as $scope) {
        $output[$scope] = access_scope_items($scope);
    }
    return $output;
}

function admin_hidden_children_map(array $tables): array
{
    require_once __DIR__ . '/../api_helpers.php';

    $map = [];
    foreach ($tables as $table) {
        $extra = array_values(array_diff(with_hidden_subtables([$table]), [$table]));
        if ($extra !== []) {
            $map[$table] = $extra;
        }
    }
    return $map;
}

if ($action === 'user_tables_get') {
    $userId = (int) (os_request()->queryAll()['user_id'] ?? 0);
    if ($userId <= 0) {
        admin_err('Invalid user ID.');
    }

    $entry      = admin_user_access_entry($userId);
    $assignable = admin_assignable_items();

    $scopes = [];
    $items  = [];
    foreach (USER_ACCESS_SCOPES as $scope => $definition) {
        $scopes[] = [
            'key'   => $scope,
            'title' => $definition['title'],

            'noun'  => $definition['plural'],
            'empty' => $definition['empty'],
        ];

        $items[$scope] = (object) $assignable[$scope];
    }

    echo json_encode([
        'status'   => 'success',
        'user_id'  => $userId,
        'scopes'   => $scopes,
        'selected' => (object) $entry,
        'items'    => (object) $items,

        'hidden_children' => (object) admin_hidden_children_map(array_keys($assignable['tables'])),
    ]);
    throw ResponseException::sent();
}

if ($action === 'user_tables_save') {
    require_not_demo();

    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int) ($data['user_id'] ?? 0);

    if ($userId <= 0) {
        admin_err('Invalid user ID.');
    }

    $assignable = admin_assignable_items();
    $clean      = [];
    foreach (USER_ACCESS_SCOPES as $scope => $definition) {
        if (!is_array($data[$scope] ?? null)) {
            continue;
        }
        $seen = [];
        foreach ($data[$scope] as $name) {
            if (!is_string($name) || !isset($assignable[$scope][$name])) {
                echo json_encode([
                    'status' => 'error',
                    'error'  => 'Unknown ' . $definition['noun'] . ' in selection.',
                ]);
                throw ResponseException::sent();
            }
            $seen[$name] = true;
        }

        $clean[$scope] = array_map('strval', array_keys($seen));
    }
    $clean = merge_user_access_selection($clean, admin_user_access_entry($userId));

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();

        $usersResult = @pg_query_params(
            $conn,
            'SELECT id, role FROM ' . sys_table('users') . ' WHERE id = $1',
            [$userId]
        );
        if (!$usersResult) {
            admin_db_fail($conn, 'user_tables_save');
        }
        $target = pg_fetch_assoc($usersResult);
        if (!$target) {
            admin_err('User not found.');
        }

        if (($target['role'] ?? '') === 'admin') {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Table access does not apply to admin accounts.',
            ]);
            throw ResponseException::sent();
        }

        $row   = config_get_row('user_table_access');
        $users = is_array($row['value']['users'] ?? null) ? $row['value']['users'] : [];

        $entry = array_filter($clean, static fn(array $list): bool => $list !== []);
        if ($entry === []) {
            unset($users[(string) $userId]);
        } else {
            $users[(string) $userId] = $entry;
        }

        $idsResult = @pg_query($conn, 'SELECT id FROM ' . sys_table('users'));
        if ($idsResult) {
            $live = [];
            while ($row = pg_fetch_assoc($idsResult)) {
                $live[(string) (int) $row['id']] = true;
            }
            $users = array_intersect_key($users, $live);
        }

        $result = config_save(
            'user_table_access',
            ['users' => (object) $users],
            $row['version'] ?? null,
            $adminActorId ?: null
        );
        if ($result['status'] === 'conflict') {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Someone else changed table access meanwhile. Reload and try again.',
            ]);
            throw ResponseException::sent();
        }
        if ($result['status'] !== 'ok') {
            admin_err($result['error'] ?? 'Save failed.');
        }

        log_user_action($conn, $adminActorId, 'UPDATE_USER_TABLES', 'users', $userId);
        echo json_encode(['status' => 'success'] + $clean);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
