<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/users.php — admin api.php module: user management (users_list, users_add, users_toggle,
// users_update_role, users_change_password, users_stats, user_policy_get, user_policy_save,
// user_tables_get, user_tables_save).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

const USER_ROLES = ['admin', 'editor', 'viewer'];

// The admin performing the action. Every user-management mutation is audited
// against this id — writing a hardcoded 0 would make the *_log trail anonymous.
$adminActorId = (int) ($_SESSION['user_id'] ?? 0);

/**
 * Reads the 'user_policy' spw_config key, filling in defaults for unset fields.
 */
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

// Fetch list of all system users
if ($action === 'users_list') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sql = "SELECT id, username, is_active, role FROM " . sys_table('users') . " ORDER BY id ASC";
        $res = @pg_query($conn, $sql);
        if (!$res) {
            $err = pg_last_error($conn);
            if (str_contains($err, 'is_active') || str_contains($err, 'does not exist')) {
                throw new AdminApiMessage("Database schema is outdated or missing. Please initialize tables.");
            }
            admin_db_fail($conn, 'users_list');
        }

        $users = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true);
            $users[] = $row;
        }

        echo json_encode(['status' => 'success', 'users' => $users]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Add a new user securely
if ($action === 'users_add') {
    require_not_demo();

    $policy = admin_user_policy();
    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role = in_array($data['role'] ?? '', USER_ROLES, true) ? $data['role'] : $policy['default_role'];

    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'error' => 'Username and password are required.']);
        exit;
    }
    if (strlen($password) < $policy['min_password_length']) {
        echo json_encode([
            'status' => 'error',
            'error' => "Password must be at least {$policy['min_password_length']} characters.",
        ]);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        $sql     = 'INSERT INTO ' . sys_table('users')
            . ' (username, password_hash, salt, password_algo, password_params, is_active, role)'
            . ' VALUES ($1, $2, $3, $4, $5, true, $6) RETURNING id';
        $res = @pg_query_params($conn, $sql, [
            $username, $hash, $newSalt, 'argon2id',
            json_encode(ARGON2_OPTIONS), $role,
        ]);
        if (!$res) {
            admin_db_fail($conn, 'users_add');
        }
        $newRow = pg_fetch_assoc($res);
        $newUserId = (int)($newRow['id'] ?? 0);
        log_user_action($conn, $adminActorId, 'ADD_USER', 'users', $newUserId);
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Toggle user activation status
if ($action === 'users_toggle') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $isActive = (bool)($data['is_active'] ?? false);
    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET is_active = $1 WHERE id = $2";
        $res = @pg_query_params($conn, $sql, [$isActive ? 'true' : 'false', $userId]);
        if (!$res) {
            admin_db_fail($conn, 'users_toggle');
        }
        log_user_action($conn, $adminActorId, 'TOGGLE_USER', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Handle user role update
if ($action === 'users_update_role') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $role = in_array($data['role'] ?? '', USER_ROLES, true) ? $data['role'] : admin_user_policy()['default_role'];

    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();
        $sql = "UPDATE " . sys_table('users') . " SET role = $1 WHERE id = $2";
        $res = @pg_query_params($conn, $sql, [$role, $userId]);
        if (!$res) {
            admin_db_fail($conn, 'users_update_role');
        }
        log_user_action($conn, $adminActorId, 'UPDATE_ROLE', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Change a user's password (admin action — no current-password check required)
if ($action === 'users_change_password') {
    require_not_demo();

    $data     = json_decode(file_get_contents('php://input'), true);
    $userId   = (int)($data['id'] ?? 0);
    $password = $data['password'] ?? '';

    if ($userId <= 0 || $password === '') {
        echo json_encode(['status' => 'error', 'error' => 'User ID and password are required.']);
        exit;
    }
    $minLen = admin_user_policy()['min_password_length'];
    if (strlen($password) < $minLen) {
        echo json_encode(['status' => 'error', 'error' => "Password must be at least {$minLen} characters."]);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        $sql     = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $res = @pg_query_params($conn, $sql, [
            $hash, $newSalt, 'argon2id',
            json_encode(ARGON2_OPTIONS),
            $userId,
        ]);
        if (!$res) {
            admin_db_fail($conn, 'users_change_password');
        }
        log_user_action($conn, $adminActorId, 'CHANGE_PASSWORD', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// User counts (total/active/inactive/by-role) plus recent user-related audit activity
if ($action === 'users_stats') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn    = db_connect();
        $tUsers  = sys_table('users');
        $tLog    = sys_table('users_log');

        $cRes = @pg_query($conn, "SELECT role, is_active, COUNT(*) AS c FROM {$tUsers} GROUP BY role, is_active");
        if (!$cRes) {
            admin_db_fail($conn, 'users_stats');
        }

        $total = 0;
        $active = 0;
        $byRole = ['admin' => 0, 'editor' => 0, 'viewer' => 0];
        while ($row = pg_fetch_assoc($cRes)) {
            $count = (int) $row['c'];
            $total += $count;
            if ($row['is_active'] === 't' || $row['is_active'] === true) {
                $active += $count;
            }
            $role = in_array($row['role'] ?? '', USER_ROLES, true) ? $row['role'] : 'editor';
            $byRole[$role] += $count;
        }

        $rRes = @pg_query($conn, "
            SELECT ul.action, ul.target_table,
                   TO_CHAR(ul.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   u.username
            FROM {$tLog} ul
            LEFT JOIN {$tUsers} u ON u.id = ul.user_id
            WHERE ul.target_table = 'users'
            ORDER BY ul.created_at DESC
            LIMIT 10
        ");
        $recent = [];
        if ($rRes) {
            while ($row = pg_fetch_assoc($rRes)) {
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// Fetch the global user policy (min password length, default role for new users)
if ($action === 'user_policy_get') {
    echo json_encode(['status' => 'success'] + admin_user_policy());
    exit;
}

// Save the global user policy
if ($action === 'user_policy_save') {
    require_not_demo();

    $data = json_decode(file_get_contents('php://input'), true);
    $minLen = (int) ($data['min_password_length'] ?? 0);
    $defaultRole = $data['default_role'] ?? '';

    if ($minLen < 6) {
        echo json_encode(['status' => 'error', 'error' => 'Minimum password length must be at least 6.']);
        exit;
    }
    if (!in_array($defaultRole, USER_ROLES, true)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid default role.']);
        exit;
    }

    $result = config_save('user_policy', [
        'min_password_length' => $minLen,
        'default_role' => $defaultRole,
    ], null, $adminActorId ?: null);
    if ($result['status'] !== 'ok') {
        echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'Save failed.']);
        exit;
    }

    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/api_helpers.php';
    log_user_action(db_connect(), $adminActorId, 'UPDATE_USER_POLICY', 'users', 0);
    echo json_encode(['status' => 'success']);
    exit;
}

// ── Per-user frontend access ─────────────────────────────────────────────────
// Reads/writes the 'user_table_access' spw_config key, shaped as
//   {"users": {"<id>": {"tables": [], "views": [], "prints": [], "boards": [],
//                       "workflows": []}}}
//
// The scope list is NOT repeated here: every loop below walks USER_ACCESS_SCOPES from
// includes/api_helpers.php, and the pick lists come from access_scope_items(). That is
// deliberate — a scope added to that registry has to show up in this tab without any
// edit to this file, and a hand-kept copy is exactly how the two drift apart.
//
// An ABSENT OR EMPTY list means UNRESTRICTED for that scope, not "no access" — the
// same contract user_allowed_items() enforces. Do not diverge here: that helper is the
// single decision point, this module only edits the document. "No access at all" is
// expressed by deactivating the account.
//
// The key has no foreign key to spw_users (it is a config document, not a table),
// so every save prunes entries whose user no longer exists.

require_once __DIR__ . '/../api_helpers.php';

/**
 * The 'users' map of the user_table_access config, keyed by user id as string.
 */
function admin_user_table_access(): array
{
    $cfg = config_get('user_table_access') ?? [];
    return is_array($cfg['users'] ?? null) ? $cfg['users'] : [];
}

/**
 * One user's stored entry, normalised to one list per registered scope. A bare list is
 * the pre-scopes format and means tables only — mirrors user_allowed_items().
 *
 * @return array<string, list<string>>
 */
function admin_user_access_entry(int $userId): array
{
    $entry = admin_user_table_access()[(string) $userId] ?? null;
    if (is_array($entry) && array_is_list($entry)) {
        $entry = ['tables' => $entry];
    }
    $out = [];
    foreach (array_keys(USER_ACCESS_SCOPES) as $scope) {
        $list        = is_array($entry[$scope] ?? null) ? $entry[$scope] : [];
        $out[$scope] = array_values(array_filter($list, 'is_string'));
    }
    return $out;
}

/**
 * Name => display name for everything an admin may grant, per scope. Reads the same
 * registry the frontend gates read, so the picker can never offer something the gates
 * do not recognise, nor miss something they do.
 *
 * @return array<string, array<string,string>>
 */
function admin_assignable_items(): array
{
    $out = [];
    foreach (array_keys(USER_ACCESS_SCOPES) as $scope) {
        $out[$scope] = access_scope_items($scope);
    }
    return $out;
}

/**
 * Grantable table => the hidden subtables ticking it drags in, transitively.
 *
 * A hidden table has no menu entry and no grid, so it is not offered in the picker;
 * user_allowed_items() closes over it instead, or a restricted user would lose every
 * hidden subtable tab with no way for an admin to give it back. Displaying the
 * consequence is the whole point of this map — a closure nobody can see is a closure
 * nobody can reason about. Only the entries that actually drag something in are
 * returned, so the tab renders a note per ticked parent and nothing for the rest.
 *
 * @param  list<string> $tables
 * @return array<string, list<string>>
 */
function admin_hidden_children_map(array $tables): array
{
    require_once __DIR__ . '/../api_helpers.php';

    $map = [];
    foreach ($tables as $table) {
        // The closure includes the table itself; only what it adds is interesting here.
        $extra = array_values(array_diff(with_hidden_subtables([$table]), [$table]));
        if ($extra !== []) {
            $map[$table] = $extra;
        }
    }
    return $map;
}

// One user's allow-lists plus the pick lists and section metadata for the UI. The
// response is keyed by scope rather than naming each one, so the Access tab renders
// whatever the registry defines and needs no scope list of its own.
if ($action === 'user_tables_get') {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    $entry      = admin_user_access_entry($userId);
    $assignable = admin_assignable_items();

    $scopes = [];
    $items  = [];
    foreach (USER_ACCESS_SCOPES as $scope => $def) {
        $scopes[] = [
            'key'   => $scope,
            'title' => $def['title'],
            // Plural: the tab reads "Restricted to 2 of 5 tables".
            'noun'  => $def['plural'],
            'empty' => $def['empty'],
        ];
        // (object) casts matter: an empty PHP array reaches JS as [] and every string
        // key would vanish on the client.
        $items[$scope] = (object) $assignable[$scope];
    }

    echo json_encode([
        'status'   => 'success',
        'user_id'  => $userId,
        'scopes'   => $scopes,
        'selected' => (object) $entry,
        'items'    => (object) $items,
        // What ticking each table drags along, so the tab can say it out loud rather
        // than leaving the closure invisible. Not an input to the save.
        'hidden_children' => (object) admin_hidden_children_map(array_keys($assignable['tables'])),
    ]);
    exit;
}

// Save the allow-lists for one user
if ($action === 'user_tables_save') {
    require_not_demo();

    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int) ($data['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid user ID.']);
        exit;
    }

    // Only real, non-hidden entries may be granted. A name that is not configured is
    // rejected outright rather than silently dropped: a typo that quietly shrinks
    // someone's access is worse than a visible error. The noun in that message comes
    // from the registry, so a new scope needs no wording added here.
    // Only the scopes the payload actually carries are validated here. A scope it
    // omits is not "cleared to unrestricted" — merge_user_access_selection() below
    // carries the stored value over, so a caller that knows nothing about a scope
    // cannot widen it by silence.
    $assignable = admin_assignable_items();
    $clean      = [];
    foreach (USER_ACCESS_SCOPES as $scope => $def) {
        if (!is_array($data[$scope] ?? null)) {
            continue;
        }
        $seen = [];
        foreach ($data[$scope] as $name) {
            if (!is_string($name) || !isset($assignable[$scope][$name])) {
                echo json_encode([
                    'status' => 'error',
                    'error'  => 'Unknown ' . $def['noun'] . ' in selection.',
                ]);
                exit;
            }
            $seen[$name] = true;
        }
        $clean[$scope] = array_keys($seen);
    }
    $clean = merge_user_access_selection($clean, admin_user_access_entry($userId));

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn = db_connect();

        $uRes = @pg_query_params(
            $conn,
            'SELECT id, role FROM ' . sys_table('users') . ' WHERE id = $1',
            [$userId]
        );
        if (!$uRes) {
            admin_db_fail($conn, 'user_tables_save');
        }
        $target = pg_fetch_assoc($uRes);
        if (!$target) {
            echo json_encode(['status' => 'error', 'error' => 'User not found.']);
            exit;
        }
        // Admins work in the admin panel and must keep seeing the whole schema to
        // configure it; storing a restriction for them would be a silent no-op.
        if (($target['role'] ?? '') === 'admin') {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Table access does not apply to admin accounts.',
            ]);
            exit;
        }

        $row   = config_get_row('user_table_access');
        $users = is_array($row['value']['users'] ?? null) ? $row['value']['users'] : [];

        // Drop empty scopes so an unrestricted user leaves no entry behind at all —
        // an entry of three empty lists and no entry mean exactly the same thing,
        // and the shorter document is the one worth storing.
        $entry = array_filter($clean, static fn(array $list): bool => $list !== []);
        if ($entry === []) {
            unset($users[(string) $userId]);
        } else {
            $users[(string) $userId] = $entry;
        }

        // Prune entries for users that no longer exist — nothing else garbage-collects
        // this document, and a recycled serial id would otherwise inherit them.
        $idRes = @pg_query($conn, 'SELECT id FROM ' . sys_table('users'));
        if ($idRes) {
            $live = [];
            while ($r = pg_fetch_assoc($idRes)) {
                $live[(string) (int) $r['id']] = true;
            }
            $users = array_intersect_key($users, $live);
        }

        // Empty map must serialise as a JSON object, not [] — PHP's empty array
        // reaches the client as an Array and every string key vanishes.
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
            exit;
        }
        if ($result['status'] !== 'ok') {
            echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'Save failed.']);
            exit;
        }

        log_user_action($conn, $adminActorId, 'UPDATE_USER_TABLES', 'users', $userId);
        echo json_encode(['status' => 'success'] + $clean);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
