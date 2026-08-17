<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/exception_handler.php';
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

os_register_exception_handler('json');

if (APP_ENV === 'production') {
    throw new NotFoundException('Seeding is disabled in production.');
}

$request = os_request();

$expectedToken = getenv('CYPRESS_SEED_TOKEN') ?: 'cypress-dev-seed';
$providedToken = (string) $request->post('token', $request->query('token'));
if (!hash_equals($expectedToken, $providedToken)) {
    throw new ForbiddenException('Invalid seed token');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/config_store.php';

header('Content-Type: application/json');

function cypress_first_text_column(array $tableConfig): ?string
{
    foreach ($tableConfig['columns'] ?? [] as $columnName => $columnConfig) {
        if ($columnName === 'id') {
            continue;
        }
        $type = strtolower($columnConfig['type'] ?? '');
        if (in_array($type, ['text', 'varchar', 'character varying', 'string', ''], true)) {
            return $columnName;
        }
    }
    return null;
}

try {
    $conn  = db_connect();
    $usersTable = sys_table('users');
    $action = $request->post('action', $request->query('action', 'seed'));
    $results = [];

    if ($action === 'seed' || $action === 'users') {
        $argonOptions = ['memory_cost' => 1 << 16, 'time_cost' => 2, 'threads' => 1];
        $optionsJson  = json_encode($argonOptions);

        foreach (
            [
            ['test',      'test',      'editor'],
            ['test2',     'test2',     'editor'],
            ['testadmin', 'testadmin', 'admin'],
            ] as [$username, $password, $role]
        ) {
            $salt = bin2hex(random_bytes(32));
            $hash = password_hash($salt . $password, PASSWORD_ARGON2ID, $argonOptions);

            $result = pg_query_params($conn, "
                INSERT INTO $usersTable (username, password_hash, salt, password_algo, password_params, is_active, role)
                VALUES (\$1, \$2, \$3, 'argon2id', \$4, true, \$5)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash,
                    salt          = EXCLUDED.salt,
                    is_active     = true,
                    role          = EXCLUDED.role
            ", [$username, $hash, $salt, $optionsJson, $role]);

            $results[$username] = $result ? 'ok' : pg_last_error($conn);
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

            $recordOwnersTable = sys_table('record_owners');

            foreach ($schema['tables'] ?? [] as $tableName => $tableConfig) {
                $textColumn = cypress_first_text_column($tableConfig);

                if ($textColumn === null) {
                    continue;
                }

                $pgTable = $appSchema . '.' . pg_ident($tableName);
                $pgColumn   = pg_ident($textColumn);

                $result = @pg_query(
                    $conn,
                    "DELETE FROM $pgTable WHERE $pgColumn ILIKE 'cypress%' OR $pgColumn ILIKE 'cy-%' RETURNING id"
                );
                if ($result) {
                    $deletedIds = array_map('intval', array_column(pg_fetch_all($result) ?: [], 'id'));
                    if ($deletedIds !== []) {
                        $cleaned[$tableName] = count($deletedIds);
                        @pg_query_params(
                            $conn,
                            "DELETE FROM $recordOwnersTable WHERE table_name = \$1 AND record_id = ANY(\$2::int[])",
                            [$tableName, '{' . implode(',', $deletedIds) . '}']
                        );
                    }
                }
            }

            $results['cleaned'] = $cleaned;
        }
    }

    if ($action === 'login_reset') {
        $result = pg_query($conn, 'DELETE FROM ' . sys_table('login_attempts'));
        $results['login_attempts_cleared'] = $result ? pg_affected_rows($result) : 0;
    }

    if ($action === 'own') {
        $schema = config_get('schema');
        $table  = (string) $request->post('table', $request->query('table'));

        if ($table === '') {
            foreach ($schema['tables'] ?? [] as $name => $config) {
                if (cypress_first_text_column($config) !== null) {
                    $table = (string) $name;
                    break;
                }
            }
        }

        $tableConfig = $schema['tables'][$table] ?? null;
        $textColumn  = is_array($tableConfig) ? cypress_first_text_column($tableConfig) : null;

        if ($textColumn === null) {
            throw ResponseException::encoded(['status' => 'ok', 'results' => ['skipped' => true]]);
        }

        $userIds = [];
        foreach (['test', 'test2'] as $username) {
            $userResult = pg_query_params($conn, "SELECT id FROM $usersTable WHERE username = \$1", [$username]);
            if (!$userResult || pg_num_rows($userResult) === 0) {
                throw new ServerErrorException("Missing user $username — run action=seed first", [
                    'status' => 'error',
                    'error'  => "Missing user $username — run action=seed first",
                ]);
            }
            $userIds[$username] = (int) pg_fetch_result($userResult, 0, 'id');
        }

        $pgTable = pg_ident($tableConfig['schema'] ?? 'public') . '.' . pg_ident($table);
        $pgColumn   = pg_ident($textColumn);
        $recordOwnersTable = sys_table('record_owners');

        $old = @pg_query($conn, "DELETE FROM $pgTable WHERE $pgColumn LIKE 'cypress-idor-%' RETURNING id");
        if ($old) {
            $oldIds = array_map('intval', array_column(pg_fetch_all($old) ?: [], 'id'));
            if ($oldIds !== []) {
                @pg_query_params(
                    $conn,
                    "DELETE FROM $recordOwnersTable WHERE table_name = \$1 AND record_id = ANY(\$2::int[])",
                    [$table, '{' . implode(',', $oldIds) . '}']
                );
            }
        }

        $ids = [];
        foreach (['a' => 'test', 'b' => 'test2'] as $slot => $owner) {
            $result = pg_query_params(
                $conn,
                "INSERT INTO $pgTable ($pgColumn) VALUES (\$1) RETURNING id",
                ["cypress-idor-$slot"]
            );
            if (!$result) {
                throw new ServerErrorException('Insert failed: ' . pg_last_error($conn), [
                    'status' => 'error',
                    'error'  => 'Insert failed: ' . pg_last_error($conn),
                ]);
            }
            $recordId = (int) pg_fetch_result($result, 0, 'id');
            pg_query_params(
                $conn,
                "INSERT INTO $recordOwnersTable (table_name, record_id, owner_id, changed_by, is_current) "
                    . "VALUES (\$1, \$2, \$3, \$3, true)",
                [$table, $recordId, $userIds[$owner]]
            );
            $ids[$slot] = $recordId;
        }

        $wasRestricted = !empty($tableConfig['owner_restricted']);
        if (!$wasRestricted) {
            $schema['tables'][$table]['owner_restricted'] = true;
            $saved = config_save('schema', $schema);
            if (($saved['status'] ?? '') !== 'ok') {
                http_response_code(500);
                throw ResponseException::encoded([
                    'status' => 'error',
                    'error'  => 'Could not enable owner_restricted: ' . ($saved['error'] ?? $saved['status']),
                ]);
            }
        }

        $results = [
            'table'          => $table,
            'column'         => $textColumn,
            'was_restricted' => $wasRestricted,
            'id_a'           => $ids['a'],
            'id_b'           => $ids['b'],
            'owner_a'        => $userIds['test'],
            'owner_b'        => $userIds['test2'],
        ];
    }

    if ($action === 'own_reset') {
        $schema   = config_get('schema');
        $table    = (string) $request->post('table', $request->query('table'));
        $tableConfig = $schema['tables'][$table] ?? null;

        if (!is_array($tableConfig)) {
            throw new BadRequestException('Unknown table', ['status' => 'error', 'error' => 'Unknown table']);
        }

        $textColumn = cypress_first_text_column($tableConfig);
        if ($textColumn !== null) {
            $pgTable = pg_ident($tableConfig['schema'] ?? 'public') . '.' . pg_ident($table);
            $result = @pg_query(
                $conn,
                "DELETE FROM $pgTable WHERE " . pg_ident($textColumn) . " LIKE 'cypress-idor-%' RETURNING id"
            );
            if ($result) {
                $ids = array_map('intval', array_column(pg_fetch_all($result) ?: [], 'id'));
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

        $wasRestricted = $request->post('was_restricted', $request->query('was_restricted', '0')) === '1';
        if (!$wasRestricted && !empty($schema['tables'][$table]['owner_restricted'])) {
            unset($schema['tables'][$table]['owner_restricted']);
            config_save('schema', $schema);
        }

        $results['restored'] = $table;
    }

    if ($action === 'count') {
        $table  = (string) $request->post('table', $request->query('table'));
        $schema = config_get('schema');
        $tableConfig = $schema['tables'][$table] ?? null;

        if (!is_array($tableConfig)) {
            throw new BadRequestException('Unknown table', ['status' => 'error', 'error' => 'Unknown table']);
        }

        $pgTable = pg_ident($tableConfig['schema'] ?? 'public') . '.' . pg_ident($table);
        $result = pg_query($conn, "SELECT COUNT(*) AS c FROM $pgTable");
        if (!$result) {
            throw new ServerErrorException(pg_last_error($conn), [
                'status' => 'error',
                'error'  => pg_last_error($conn),
            ]);
        }

        $results['table'] = $table;
        $results['count'] = (int) pg_fetch_result($result, 0, 'c');
    }

    throw ResponseException::encoded(['status' => 'ok', 'results' => $results]);
} catch (ControlFlowException $signal) {
    throw $signal;
} catch (Exception $exception) {
    throw new ServerErrorException(
        $exception->getMessage(),
        ['status' => 'error', 'error' => $exception->getMessage()]
    );
}
