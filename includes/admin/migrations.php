<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'init_db') {
    require_not_demo('Disabled in Demo Mode.', 403);
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaIdentifier = '"' . str_replace('"', '""', sys_schema()) . '"';
        $migrationsTable = sys_table('migrations');
        $usersTable      = sys_table('users');
        $notesTable      = sys_table('notes');

        $bootstrap = [
            "CREATE SCHEMA IF NOT EXISTS $schemaIdentifier",
            "CREATE TABLE IF NOT EXISTS $migrationsTable ( id serial4 NOT NULL, name varchar(100) NOT NULL,"
                . " applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id),"
                . " CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
        ];
        foreach ($bootstrap as $query) {
            if (!@pg_query($conn, $query)) {
                admin_db_fail($conn, 'init_db:bootstrap');
            }
        }

        $appliedResult = pg_query($conn, "SELECT name FROM $migrationsTable");
        if (!$appliedResult) {
            admin_db_fail($conn, 'init_db:load_migrations');
        }
        $applied = [];
        while ($row = pg_fetch_row($appliedResult)) {
            $applied[$row[0]] = true;
        }

        require_once __DIR__ . '/../system_tables.php';
        $migrations = [

            '3.0_baseline' => system_tables_ddl(static fn(string $tableName): string => sys_table($tableName)),

            '3.1_table_comments' => system_tables_comments_ddl(
                static fn(string $tableName): string => sys_table($tableName)
            ),

            '3.1_notes_reminder_time' => [
                "ALTER TABLE $notesTable ALTER COLUMN reminder_date TYPE timestamp",
            ],

            '3.3_user_contact' => system_tables_user_contact_ddl(
                static fn(string $tableName): string => sys_table($tableName)
            ),

            '3.3_clickstats' => system_tables_clickstats_ddl(
                static fn(string $tableName): string => sys_table($tableName)
            ),

        ];

        $applied_count = 0;
        foreach ($migrations as $name => $queries) {
            if (isset($applied[$name])) {
                continue;
            }
            foreach ($queries as $query) {
                if (!@pg_query($conn, $query)) {
                    admin_db_fail($conn, "init_db:migration:{$name}");
                }
            }
            $result = @pg_query_params($conn, "INSERT INTO $migrationsTable (name) VALUES (\$1)", [$name]);
            if (!$result) {
                admin_db_fail($conn, "init_db:record_migration:{$name}");
            }
            $applied_count++;
        }

        $registryNames = array_keys($migrations);
        $prunePlaceholders = implode(', ', array_map(
            static fn(int $placeholderIndex): string => '$' . ($placeholderIndex + 1),
            array_keys($registryNames)
        ));
        $pruneResult = @pg_query_params(
            $conn,
            "DELETE FROM $migrationsTable WHERE name NOT IN ($prunePlaceholders)",
            $registryNames
        );
        if (!$pruneResult) {
            admin_db_fail($conn, 'init_db:prune_migrations');
        }
        $pruned_count = pg_affected_rows($pruneResult);

        $temporaryPassword    = bin2hex(random_bytes(12));
        $firstAdminSalt = bin2hex(random_bytes(32));
        $firstAdminHash = password_hash($firstAdminSalt . $temporaryPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        error_log(
            '[OpenSparrow] First-run admin password: ' . $temporaryPassword . ' — change immediately after login!'
        );
        $adminResult = @pg_query_params(
            $conn,
            "INSERT INTO $usersTable (username, password_hash, salt, password_algo, password_params, is_active, role)
             SELECT 'admin', \$1, \$2, \$3, \$4, true, 'admin'
             WHERE NOT EXISTS (SELECT 1 FROM $usersTable LIMIT 1)",
            [
                $firstAdminHash,
                $firstAdminSalt,
                'argon2id',
                json_encode(ARGON2_OPTIONS),
            ]
        );
        if (!$adminResult) {
            admin_db_fail($conn, 'init_db:first_admin');
        }

        $total = count($migrations);
        $skipped = $total - $applied_count;
        $message = "Migrations: {$applied_count} applied, {$skipped} already up to date.";
        if ($pruned_count > 0) {
            $message .= " Pruned {$pruned_count} obsolete pre-3.0 migration row(s).";
        }
        echo json_encode([
            'status'  => 'success',
            'message' => $message,
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'migrations_list') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $migrationsTable = sys_table('migrations');

        $known = [
            '3.0_baseline',
            '3.1_table_comments',
            '3.1_notes_reminder_time',
            '3.3_user_contact',
            '3.3_clickstats',
        ];

        $appliedResult = @pg_query($conn, "SELECT name, applied_at FROM $migrationsTable ORDER BY applied_at ASC");
        $applied = [];
        if ($appliedResult) {
            while ($row = pg_fetch_assoc($appliedResult)) {
                $applied[$row['name']] = $row['applied_at'];
            }
        }

        $list = [];
        foreach ($known as $name) {
            $list[] = [
                'name'       => $name,
                'status'     => isset($applied[$name]) ? 'applied' : 'pending',
                'applied_at' => $applied[$name] ?? null,
            ];
        }
        foreach ($applied as $name => $createdAt) {
            if (!in_array($name, $known, true)) {
                $list[] = ['name' => $name, 'status' => 'applied', 'applied_at' => $createdAt];
            }
        }

        echo json_encode(['status' => 'success', 'migrations' => $list]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
