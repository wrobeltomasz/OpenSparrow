<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if (APP_ENV === 'production') {
    http_response_code(404);
    exit;
}

$expectedToken = getenv('CYPRESS_SEED_TOKEN') ?: 'cypress-dev-seed';
$providedToken = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid seed token']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/config_store.php';

header('Content-Type: application/json');

function cypress_first_text_column(array $tableCfg): ?string
{
    foreach ($tableCfg['columns'] ?? [] as $colName => $colCfg) {
        if ($colName === 'id') {
            continue;
        }
        $type = strtolower($colCfg['type'] ?? '');
        if (in_array($type, ['text', 'varchar', 'character varying', 'string', ''], true)) {
            return $colName;
        }
    }
    return null;
}

try {
    $conn  = db_connect();
    $tUsers = sys_table('users');
    $action = $_POST['action'] ?? $_GET['action'] ?? 'seed';
    $results = [];

    if ($action === 'seed' || $action === 'users') {
        $argonOpts = ['memory_cost' => 1 << 16, 'time_cost' => 2, 'threads' => 1];
        $optsJson  = json_encode($argonOpts);

        foreach (
            [
            ['test',      'test',      'editor'],
            ['test2',     'test2',     'editor'],
            ['testadmin', 'testadmin', 'admin'],
            ] as [$username, $password, $role]
        ) {
            $salt = bin2hex(random_bytes(32));
            $hash = password_hash($salt . $password, PASSWORD_ARGON2ID, $argonOpts);

            $res = pg_query_params($conn, "
                INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role)
                VALUES (\$1, \$2, \$3, 'argon2id', \$4, true, \$5)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash,
                    salt          = EXCLUDED.salt,
                    is_active     = true,
                    role          = EXCLUDED.role
            ", [$username, $hash, $salt, $optsJson, $role]);

            $results[$username] = $res ? 'ok' : pg_last_error($conn);
        }
    }

    if ($action === 'seed' || $action === 'cleanup') {
        require_once __DIR__ . '/../includes/config_store.php';
        config_delete('anonymization');
    }

    if ($action === 'seed' || $action === 'cleanup') {
        $schema = config_get('schema');
        if (is_array($schema)) {
            $appSchema = pg_ident(sys_schema());
            $cleaned = [];

            $tOwners = sys_table('record_owners');

            foreach ($schema['tables'] ?? [] as $tableName => $tableCfg) {
                $textCol = cypress_first_text_column($tableCfg);

                if ($textCol === null) {
                    continue;
                }

                $pgTable = $appSchema . '.' . pg_ident($tableName);
                $pgCol   = pg_ident($textCol);

                $res = @pg_query(
                    $conn,
                    "DELETE FROM $pgTable WHERE $pgCol ILIKE 'cypress%' OR $pgCol ILIKE 'cy-%' RETURNING id"
                );
                if ($res) {
                    $deletedIds = array_map('intval', array_column(pg_fetch_all($res) ?: [], 'id'));
                    if ($deletedIds !== []) {
                        $cleaned[$tableName] = count($deletedIds);
                        @pg_query_params(
                            $conn,
                            "DELETE FROM $tOwners WHERE table_name = \$1 AND record_id = ANY(\$2::int[])",
                            [$tableName, '{' . implode(',', $deletedIds) . '}']
                        );
                    }
                }
            }

            $results['cleaned'] = $cleaned;
        }
    }

    if ($action === 'login_reset') {
        $res = pg_query($conn, 'DELETE FROM ' . sys_table('login_attempts'));
        $results['login_attempts_cleared'] = $res ? pg_affected_rows($res) : 0;
    }

    if ($action === 'own') {
        $schema = config_get('schema');
        $table  = (string) ($_POST['table'] ?? $_GET['table'] ?? '');

        if ($table === '') {
            foreach ($schema['tables'] ?? [] as $name => $cfg) {
                if (cypress_first_text_column($cfg) !== null) {
                    $table = (string) $name;
                    break;
                }
            }
        }

        $tableCfg = $schema['tables'][$table] ?? null;
        $textCol  = is_array($tableCfg) ? cypress_first_text_column($tableCfg) : null;

        if ($textCol === null) {
            echo json_encode(['status' => 'ok', 'results' => ['skipped' => true]]);
            exit;
        }

        $userIds = [];
        foreach (['test', 'test2'] as $u) {
            $userRes = pg_query_params($conn, "SELECT id FROM $tUsers WHERE username = \$1", [$u]);
            if (!$userRes || pg_num_rows($userRes) === 0) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'error' => "Missing user $u — run action=seed first"]);
                exit;
            }
            $userIds[$u] = (int) pg_fetch_result($userRes, 0, 'id');
        }

        $pgTable = pg_ident($tableCfg['schema'] ?? 'public') . '.' . pg_ident($table);
        $pgCol   = pg_ident($textCol);
        $tOwners = sys_table('record_owners');

        $old = @pg_query($conn, "DELETE FROM $pgTable WHERE $pgCol LIKE 'cypress-idor-%' RETURNING id");
        if ($old) {
            $oldIds = array_map('intval', array_column(pg_fetch_all($old) ?: [], 'id'));
            if ($oldIds !== []) {
                @pg_query_params(
                    $conn,
                    "DELETE FROM $tOwners WHERE table_name = \$1 AND record_id = ANY(\$2::int[])",
                    [$table, '{' . implode(',', $oldIds) . '}']
                );
            }
        }

        $ids = [];
        foreach (['a' => 'test', 'b' => 'test2'] as $slot => $owner) {
            $res = pg_query_params(
                $conn,
                "INSERT INTO $pgTable ($pgCol) VALUES (\$1) RETURNING id",
                ["cypress-idor-$slot"]
            );
            if (!$res) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'error' => 'Insert failed: ' . pg_last_error($conn)]);
                exit;
            }
            $recordId = (int) pg_fetch_result($res, 0, 'id');
            pg_query_params(
                $conn,
                "INSERT INTO $tOwners (table_name, record_id, owner_id, changed_by, is_current) "
                    . "VALUES (\$1, \$2, \$3, \$3, true)",
                [$table, $recordId, $userIds[$owner]]
            );
            $ids[$slot] = $recordId;
        }

        $wasRestricted = !empty($tableCfg['owner_restricted']);
        if (!$wasRestricted) {
            $schema['tables'][$table]['owner_restricted'] = true;
            $saved = config_save('schema', $schema);
            if (($saved['status'] ?? '') !== 'ok') {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error'  => 'Could not enable owner_restricted: ' . ($saved['error'] ?? $saved['status']),
                ]);
                exit;
            }
        }

        $results = [
            'table'          => $table,
            'column'         => $textCol,
            'was_restricted' => $wasRestricted,
            'id_a'           => $ids['a'],
            'id_b'           => $ids['b'],
            'owner_a'        => $userIds['test'],
            'owner_b'        => $userIds['test2'],
        ];
    }

    if ($action === 'own_reset') {
        $schema   = config_get('schema');
        $table    = (string) ($_POST['table'] ?? $_GET['table'] ?? '');
        $tableCfg = $schema['tables'][$table] ?? null;

        if (!is_array($tableCfg)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'error' => 'Unknown table']);
            exit;
        }

        $textCol = cypress_first_text_column($tableCfg);
        if ($textCol !== null) {
            $pgTable = pg_ident($tableCfg['schema'] ?? 'public') . '.' . pg_ident($table);
            $res = @pg_query(
                $conn,
                "DELETE FROM $pgTable WHERE " . pg_ident($textCol) . " LIKE 'cypress-idor-%' RETURNING id"
            );
            if ($res) {
                $ids = array_map('intval', array_column(pg_fetch_all($res) ?: [], 'id'));
                if ($ids !== []) {
                    @pg_query_params(
                        $conn,
                        'DELETE FROM ' . sys_table('record_owners')
                            . " WHERE table_name = \$1 AND record_id = ANY(\$2::int[])",
                        [$table, '{' . implode(',', $ids) . '}']
                    );
                }
            }
        }

        $wasRestricted = ($_POST['was_restricted'] ?? $_GET['was_restricted'] ?? '0') === '1';
        if (!$wasRestricted && !empty($schema['tables'][$table]['owner_restricted'])) {
            unset($schema['tables'][$table]['owner_restricted']);
            config_save('schema', $schema);
        }

        $results['restored'] = $table;
    }

    if ($action === 'count') {
        $table  = (string) ($_POST['table'] ?? $_GET['table'] ?? '');
        $schema = config_get('schema');
        $tableCfg = $schema['tables'][$table] ?? null;

        if (!is_array($tableCfg)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'error' => 'Unknown table']);
            exit;
        }

        $pgTable = pg_ident($tableCfg['schema'] ?? 'public') . '.' . pg_ident($table);
        $res = pg_query($conn, "SELECT COUNT(*) AS c FROM $pgTable");
        if (!$res) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'error' => pg_last_error($conn)]);
            exit;
        }

        $results['table'] = $table;
        $results['count'] = (int) pg_fetch_result($res, 0, 'c');
    }

    echo json_encode(['status' => 'ok', 'results' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
