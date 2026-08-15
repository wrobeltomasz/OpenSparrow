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

        $schemaIdent = '"' . str_replace('"', '""', sys_schema()) . '"';
        $tMigrations = sys_table('migrations');
        $tUsers      = sys_table('users');
        $tNotes      = sys_table('notes');

        $bootstrap = [
            "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
            "CREATE TABLE IF NOT EXISTS $tMigrations ( id serial4 NOT NULL, name varchar(100) NOT NULL,"
                . " applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id),"
                . " CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
        ];
        foreach ($bootstrap as $q) {
            if (!@pg_query($conn, $q)) {
                admin_db_fail($conn, 'init_db:bootstrap');
            }
        }

        $appliedRes = pg_query($conn, "SELECT name FROM $tMigrations");
        if (!$appliedRes) {
            admin_db_fail($conn, 'init_db:load_migrations');
        }
        $applied = [];
        while ($row = pg_fetch_row($appliedRes)) {
            $applied[$row[0]] = true;
        }

        require_once __DIR__ . '/../system_tables.php';
        $migrations = [

            '3.0_baseline' => system_tables_ddl(static fn(string $n): string => sys_table($n)),

            '3.1_table_comments' => system_tables_comments_ddl(
                static fn(string $n): string => sys_table($n)
            ),

            '3.1_notes_reminder_time' => [
                "ALTER TABLE $tNotes ALTER COLUMN reminder_date TYPE timestamp",
            ],

            '3.3_user_contact' => system_tables_user_contact_ddl(
                static fn(string $n): string => sys_table($n)
            ),

            '3.3_clickstats' => system_tables_clickstats_ddl(
                static fn(string $n): string => sys_table($n)
            ),

        ];

        $applied_count = 0;
        foreach ($migrations as $name => $queries) {
            if (isset($applied[$name])) {
                continue;
            }
            foreach ($queries as $q) {
                if (!@pg_query($conn, $q)) {
                    admin_db_fail($conn, "init_db:migration:{$name}");
                }
            }
            $res = @pg_query_params($conn, "INSERT INTO $tMigrations (name) VALUES (\$1)", [$name]);
            if (!$res) {
                admin_db_fail($conn, "init_db:record_migration:{$name}");
            }
            $applied_count++;
        }

        $registryNames = array_keys($migrations);
        $prunePlaceholders = implode(', ', array_map(
            static fn(int $i): string => '$' . ($i + 1),
            array_keys($registryNames)
        ));
        $pruneRes = @pg_query_params(
            $conn,
            "DELETE FROM $tMigrations WHERE name NOT IN ($prunePlaceholders)",
            $registryNames
        );
        if (!$pruneRes) {
            admin_db_fail($conn, 'init_db:prune_migrations');
        }
        $pruned_count = pg_affected_rows($pruneRes);

        $tmpPassword    = bin2hex(random_bytes(12));
        $firstAdminSalt = bin2hex(random_bytes(32));
        $firstAdminHash = password_hash($firstAdminSalt . $tmpPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
        error_log('[OpenSparrow] First-run admin password: ' . $tmpPassword . ' — change immediately after login!');
        $resAdmin = @pg_query_params(
            $conn,
            "INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role)
             SELECT 'admin', \$1, \$2, \$3, \$4, true, 'admin'
             WHERE NOT EXISTS (SELECT 1 FROM $tUsers LIMIT 1)",
            [
                $firstAdminHash,
                $firstAdminSalt,
                'argon2id',
                json_encode(ARGON2_OPTIONS),
            ]
        );
        if (!$resAdmin) {
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}

if ($action === 'migrations_list') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tMigrations = sys_table('migrations');

        $known = [
            '3.0_baseline',
            '3.1_table_comments',
            '3.1_notes_reminder_time',
            '3.3_user_contact',
            '3.3_clickstats',
        ];

        $appliedRes = @pg_query($conn, "SELECT name, applied_at FROM $tMigrations ORDER BY applied_at ASC");
        $applied = [];
        if ($appliedRes) {
            while ($row = pg_fetch_assoc($appliedRes)) {
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
        foreach ($applied as $name => $at) {
            if (!in_array($name, $known, true)) {
                $list[] = ['name' => $name, 'status' => 'applied', 'applied_at' => $at];
            }
        }

        echo json_encode(['status' => 'success', 'migrations' => $list]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}
