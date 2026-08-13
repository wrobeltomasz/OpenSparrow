<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/migrations.php — admin api.php module: system-table migrations (init_db, migrations_list). The
// $migrations registry and
// the $known list MUST stay in this single file and match exactly — the release
// process appends to both during a version bump. A third copy of the key list lives in
// includes/admin/overview.php ($knownMig, for the dashboard pending count) and must be kept in
// sync too. 3.0_baseline is the append-only floor: the pre-3.0 incremental history was collapsed
// into it (3.0 is the first shipped version); append future releases as new keys, never edit it.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Initialize database tables and migrations
if ($action === 'init_db') {
    require_not_demo('Disabled in Demo Mode.', 403);
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaIdent = '"' . str_replace('"', '""', sys_schema()) . '"';
        $tMigrations = sys_table('migrations');
        $tUsers      = sys_table('users');
        $tNotes      = sys_table('notes');

        // Bootstrap: schema + migrations tracker must exist before anything else.
        $bootstrap = [
            "CREATE SCHEMA IF NOT EXISTS $schemaIdent",
            "CREATE TABLE IF NOT EXISTS $tMigrations ( id serial4 NOT NULL, name varchar(100) NOT NULL, applied_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_migrations_name_key UNIQUE (name) )",
        ];
        foreach ($bootstrap as $q) {
            if (!@pg_query($conn, $q)) {
                admin_db_fail($conn, 'init_db:bootstrap');
            }
        }

        // Load already-applied migration names.
        $appliedRes = pg_query($conn, "SELECT name FROM $tMigrations");
        if (!$appliedRes) {
            admin_db_fail($conn, 'init_db:load_migrations');
        }
        $applied = [];
        while ($r = pg_fetch_row($appliedRes)) {
            $applied[$r[0]] = true;
        }

        // Migration registry — 3.0_baseline is the append-only floor. Everything before
        // OpenSparrow 3.0 was collapsed into this single idempotent baseline (3.0 is the first
        // shipped version; no earlier database exists). Append future releases as new keys below
        // 3.0_baseline — never edit this entry. All DDL uses IF NOT EXISTS so re-running is safe.
        // The baseline body lives in includes/system_tables.php because the setup wizard creates
        // the same tables and must not drift from this list.
        require_once __DIR__ . '/../system_tables.php';
        $migrations = [

            '3.0_baseline' => system_tables_ddl(static fn(string $n): string => sys_table($n)),

            // Add future migrations below — never modify the 3.0_baseline entry above.

            // 3.1 — table/column descriptions (COMMENT ON). Metadata only, re-runnable.
            '3.1_table_comments' => system_tables_comments_ddl(
                static fn(string $n): string => sys_table($n)
            ),

            // 3.1 — note reminders gained a time component (User menu > Notes).
            // date -> timestamp; existing rows keep their day at 00:00.
            '3.1_notes_reminder_time' => [
                "ALTER TABLE $tNotes ALTER COLUMN reminder_date TYPE timestamp",
            ],

            // 3.3 — optional contact details on user accounts (admin panel only,
            // informational). All nullable, no UNIQUE: nothing authenticates on them.
            '3.3_user_contact' => [
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS first_name varchar(100)",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS last_name varchar(100)",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS email varchar(255)",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS phone varchar(32)",
                "COMMENT ON COLUMN $tUsers.first_name IS 'Optional given name, admin panel only. Informational - not used for login or notifications.'",
                "COMMENT ON COLUMN $tUsers.last_name IS 'Optional surname, admin panel only. Informational - not used for login or notifications.'",
                "COMMENT ON COLUMN $tUsers.email IS 'Optional contact email, admin panel only. Format-checked, not unique, not used for login or notifications.'",
                "COMMENT ON COLUMN $tUsers.phone IS 'Optional contact phone, admin panel only. Informational.'",
            ],

        ];

        // Run each migration that has not been applied yet.
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

        // Prune migration rows no longer in the registry. The pre-3.0 incremental history was
        // collapsed into 3.0_baseline, so stale 2.x rows on already-migrated databases are removed
        // here to keep the Database Migrations list honest (a fresh install never had them).
        // spw_migrations is a state tracker, not an audit log; this runs inside the sanctioned
        // Initialize System Tables flow. Generic — always keeps only the current registry keys.
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

        // Create default admin account for a clean installation (only when no users exist at all).
        // Generates a random temporary password logged to PHP error_log — must be changed immediately.
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// List all migrations: known registry vs applied in DB
if ($action === 'migrations_list') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tMigrations = sys_table('migrations');

        // Must match keys in init_db $migrations registry — append only below 3.0_baseline.
        $known = [
            '3.0_baseline',
            '3.1_table_comments',
            '3.1_notes_reminder_time',
            '3.3_user_contact',
        ];

        $appliedRes = @pg_query($conn, "SELECT name, applied_at FROM $tMigrations ORDER BY applied_at ASC");
        $applied = [];
        if ($appliedRes) {
            while ($r = pg_fetch_assoc($appliedRes)) {
                $applied[$r['name']] = $r['applied_at'];
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
