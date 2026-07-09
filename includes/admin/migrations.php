<?php

declare(strict_types=1);

// includes/admin/migrations.php — admin api.php module: system-table migrations (init_db, migrations_list). The
// $migrations registry and
// the $known list MUST stay in this single file and match exactly — the release
// process (CLAUDE.md "Version bumps") appends to both.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Initialize database tables and migrations
if ($action === 'init_db') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaIdent      = '"' . str_replace('"', '""', sys_schema()) . '"';
        $tUsers           = sys_table('users');
        $tUsersLog        = sys_table('users_log');
        $tLoginAttempts   = sys_table('login_attempts');
        $tNotifications   = sys_table('users_notifications');
        $tCronLog         = sys_table('users_notifications_log');
        $tFiles           = sys_table('files');
        $tComments        = sys_table('comments');
        $tRecordSnapshots = sys_table('record_snapshots');
        $tRecordOwners    = sys_table('record_owners');
        $tMigrations      = sys_table('migrations');
        $tRelMigrations   = sys_table('release_migrations');
        $tImports         = sys_table('imports');
        $tImportRowsLog   = sys_table('import_rows_log');
        $tRagFiles        = sys_table('rag_files');
        $tRagChunks        = sys_table('rag_chunks');
        $tRagQueries       = sys_table('rag_queries');
        $tRagQuerySources  = sys_table('rag_query_sources');
        $tAutomationRuns   = sys_table('automation_runs');
        $tAutomationEmails = sys_table('automation_emails');
        $tAnonLog          = sys_table('anonymization_log');
        $tAnonReport       = sys_table('anonymization_report');

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

        // Rename legacy migration names in DB (versioning restructure, idempotent).
        $legacyRenames = [
            '2.7.0_automations'         => '2.7_automations',
            '2.7.0_automation_runs'     => '2.7_automation_runs',
            '2.9.0_rag_chunks'          => '2.7_rag_chunks',
            '2.10.0_rag_queries_prompt' => '2.7_rag_queries_prompt',
            '2.10.0_rag_query_sources'  => '2.7_rag_query_sources',
        ];
        foreach ($legacyRenames as $oldName => $newName) {
            @pg_query_params($conn, "UPDATE $tMigrations SET name = \$1 WHERE name = \$2", [$newName, $oldName]);
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

        // Migration registry — append only, never edit existing entries.
        // Each key is a unique migration name; value is an array of SQL statements.
        $migrations = [

            '2.0_baseline' => [
                // spw_users
                "CREATE TABLE IF NOT EXISTS $tUsers ( id serial4 NOT NULL, username varchar(50) NOT NULL, password_hash varchar(255) NOT NULL, salt varchar(64), password_algo varchar(32) DEFAULT 'argon2id' NOT NULL, password_params jsonb DEFAULT '{}'::jsonb, is_active bool DEFAULT true, role varchar(20) DEFAULT 'editor' NOT NULL, CONSTRAINT spw_users_pkey PRIMARY KEY (id), CONSTRAINT spw_users_username_key UNIQUE (username) )",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS is_active bool DEFAULT true",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS role varchar(20) DEFAULT 'editor' NOT NULL",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS salt varchar(64)",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_algo varchar(32) DEFAULT 'argon2id' NOT NULL",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS password_params jsonb DEFAULT '{}'::jsonb",
                "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS avatar_id smallint",
                "UPDATE $tUsers SET role = 'editor' WHERE role = 'full'",
                "UPDATE $tUsers SET role = 'viewer' WHERE role = 'readonly'",
                // spw_users_log
                "CREATE TABLE IF NOT EXISTS $tUsersLog ( id serial4 NOT NULL, user_id int4 NOT NULL, action varchar(50) NOT NULL, target_table varchar(100), record_id int4, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_log_pkey PRIMARY KEY (id) )",
                // spw_login_attempts
                "CREATE TABLE IF NOT EXISTS $tLoginAttempts ( id serial4 NOT NULL, username varchar(50) NOT NULL, ip_hash varchar(64) NOT NULL, attempted_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_login_attempts_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_username ON $tLoginAttempts USING btree (username, attempted_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_ip ON $tLoginAttempts USING btree (ip_hash, attempted_at)",
                // spw_users_notifications + spw_users_notifications_log
                "CREATE TABLE IF NOT EXISTS $tNotifications ( id serial4 NOT NULL, user_id int8 NOT NULL, title varchar(255) NOT NULL, link varchar(255), source_table varchar(100), source_id int8, is_read bool DEFAULT false, notify_date date NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_notifications_pkey PRIMARY KEY (id), CONSTRAINT spw_users_notifications_user_id_source_table_source_id_notify_d_key UNIQUE (user_id, source_table, source_id, notify_date) )",
                "CREATE TABLE IF NOT EXISTS $tCronLog ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', sources_processed int4 NULL, notifications_created int4 NULL, error_message text NULL, CONSTRAINT spw_users_notifications_log_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_cron_log_started_at ON $tCronLog USING btree (started_at)",
                // spw_files
                "CREATE TABLE IF NOT EXISTS $tFiles ( id serial4 NOT NULL, uuid uuid DEFAULT gen_random_uuid() NOT NULL, name varchar(255) NOT NULL, display_name varchar(255) NULL, type varchar(50) NOT NULL, mime_type varchar(100) NOT NULL, extension varchar(20) NOT NULL, size_bytes int8 DEFAULT 0 NOT NULL, storage_path text NOT NULL, related_table varchar(100) NULL, related_id int4 NULL, related_field varchar(100) NULL, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, description text NULL, tags _text NULL, metadata jsonb NULL, CONSTRAINT spw_files_pkey PRIMARY KEY (id), CONSTRAINT spw_files_uuid_key UNIQUE (uuid), CONSTRAINT spw_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_deleted_at ON $tFiles USING btree (deleted_at) WHERE (deleted_at IS NULL)",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_metadata ON $tFiles USING gin (metadata)",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_related ON $tFiles USING btree (related_table, related_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_tags ON $tFiles USING gin (tags)",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_type ON $tFiles USING btree (type)",
                "CREATE INDEX IF NOT EXISTS idx_spw_files_uploaded_by ON $tFiles USING btree (uploaded_by)",
                // spw_comments
                "CREATE TABLE IF NOT EXISTS $tComments ( id serial4 NOT NULL, related_table varchar(100) NOT NULL, related_id int4 NOT NULL, user_id int4 NOT NULL, body text NOT NULL, created_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, CONSTRAINT spw_comments_pkey PRIMARY KEY (id), CONSTRAINT spw_comments_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_comments_related ON $tComments USING btree (related_table, related_id, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_comments_user_id ON $tComments USING btree (user_id)",
                // spw_record_snapshots
                "CREATE TABLE IF NOT EXISTS $tRecordSnapshots ( id serial4 NOT NULL, log_id int4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, snapshot jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_record_snapshots_pkey PRIMARY KEY (id), CONSTRAINT spw_record_snapshots_log_id_fkey FOREIGN KEY (log_id) REFERENCES $tUsersLog(id) ON DELETE CASCADE )",
                "ALTER TABLE $tRecordSnapshots DROP COLUMN IF EXISTS snapshot_type",
                "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_log_id ON $tRecordSnapshots USING btree (log_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_table_record ON $tRecordSnapshots USING btree (table_name, record_id)",
                // spw_record_owners
                "CREATE TABLE IF NOT EXISTS $tRecordOwners ( id serial4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, owner_id int4 NULL, changed_by int4 NULL, changed_at timestamp DEFAULT now() NOT NULL, is_current bool NOT NULL DEFAULT false, CONSTRAINT spw_record_owners_pkey PRIMARY KEY (id), CONSTRAINT spw_record_owners_owner_fkey FOREIGN KEY (owner_id) REFERENCES $tUsers(id) ON DELETE SET NULL, CONSTRAINT spw_record_owners_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "ALTER TABLE $tRecordOwners ADD COLUMN IF NOT EXISTS changed_by int4 NULL",
                "ALTER TABLE $tRecordOwners ADD COLUMN IF NOT EXISTS is_current bool NOT NULL DEFAULT false",
                "ALTER TABLE $tRecordOwners DROP CONSTRAINT IF EXISTS spw_record_owners_unique",
                "CREATE INDEX IF NOT EXISTS idx_spw_record_owners_current ON $tRecordOwners USING btree (table_name, record_id, is_current)",
            ],

            '2.0_record_owners_changed_at' => [
                // Rename created_at → changed_at if the old name still exists.
                "DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'spw_record_owners' AND column_name = 'created_at') THEN ALTER TABLE $tRecordOwners RENAME COLUMN created_at TO changed_at; END IF; END \$\$",
            ],

            '2.3.1_csv_import_tables' => [
                // spw_imports: audit trail of each CSV import run
                "CREATE TABLE IF NOT EXISTS $tImports ( id serial4 NOT NULL, user_id int4 NULL, filename varchar(255) NOT NULL, target_table varchar(100) NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', total_rows int4 NOT NULL DEFAULT 0, imported_rows int4 NOT NULL DEFAULT 0, skipped_rows int4 NOT NULL DEFAULT 0, column_mapping jsonb NULL, conflict_column varchar(100) NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_imports_pkey PRIMARY KEY (id), CONSTRAINT spw_imports_user_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_imports_started_at ON $tImports USING btree (started_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_imports_user_id ON $tImports USING btree (user_id)",
                // spw_import_rows_log: per-row errors for skipped rows
                "CREATE TABLE IF NOT EXISTS $tImportRowsLog ( id bigserial NOT NULL, import_id int4 NOT NULL, row_number int4 NOT NULL, raw_data jsonb NULL, error_message text NOT NULL, logged_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_import_rows_log_pkey PRIMARY KEY (id), CONSTRAINT spw_import_rows_log_import_fkey FOREIGN KEY (import_id) REFERENCES $tImports(id) ON DELETE CASCADE )",
                "CREATE INDEX IF NOT EXISTS idx_spw_import_rows_log_import_id ON $tImportRowsLog USING btree (import_id)",
            ],

            '2.4.0_release_migrations_table' => [
                "CREATE TABLE IF NOT EXISTS $tRelMigrations ( id serial4 NOT NULL, version varchar(20) NOT NULL, applied_at timestamp NOT NULL DEFAULT now(), applied_by int4 REFERENCES $tUsers(id) ON DELETE SET NULL, actions jsonb NOT NULL DEFAULT '[]', CONSTRAINT spw_release_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_release_migrations_version_key UNIQUE (version) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_release_migrations_version ON $tRelMigrations USING btree (version)",
            ],

            '2.6.0_rag_files' => [
                "CREATE TABLE IF NOT EXISTS $tRagFiles ( id serial4 NOT NULL, filename varchar(255) NOT NULL, content text NOT NULL, tags text[] NOT NULL DEFAULT '{}', file_size int4 NOT NULL DEFAULT 0, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_rag_files_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_tags ON $tRagFiles USING gin (tags)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_content_fts ON $tRagFiles USING gin (to_tsvector('simple', content))",
            ],

            '2.6.0_rag_queries' => [
                "CREATE TABLE IF NOT EXISTS $tRagQueries ( id serial4 NOT NULL, query text NOT NULL, tags text[] NOT NULL DEFAULT '{}', matched_files int4 NOT NULL DEFAULT 0, prompt_tokens int4 NOT NULL DEFAULT 0, completion_tokens int4 NOT NULL DEFAULT 0, total_ms int4 NOT NULL DEFAULT 0, model varchar(255) NOT NULL DEFAULT '', user_id int4 NULL, created_at timestamp NOT NULL DEFAULT now(), CONSTRAINT spw_rag_queries_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_queries_user_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_created_at ON $tRagQueries USING btree (created_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_user_id ON $tRagQueries USING btree (user_id)",
            ],

            '2.7_automations' => [],

            '2.7_automation_runs' => [
                "CREATE TABLE IF NOT EXISTS $tAutomationRuns ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', rule_name varchar(255) NOT NULL DEFAULT '', table_name varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, event varchar(20) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'ok', error_msg text NULL, executed_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_automation_runs_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_rule_id ON $tAutomationRuns USING btree (rule_id, executed_at DESC)",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_executed_at ON $tAutomationRuns USING btree (executed_at DESC)",
            ],

            '2.7_rag_chunks' => [
                "CREATE TABLE IF NOT EXISTS $tRagChunks ( id serial4 NOT NULL, file_id int4 NOT NULL, chunk_index int4 NOT NULL, content text NOT NULL, CONSTRAINT spw_rag_chunks_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_chunks_file_fkey FOREIGN KEY (file_id) REFERENCES $tRagFiles(id) ON DELETE CASCADE, CONSTRAINT spw_rag_chunks_file_chunk_key UNIQUE (file_id, chunk_index) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_file_id ON $tRagChunks USING btree (file_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_content_fts ON $tRagChunks USING gin (to_tsvector('simple', content))",
            ],

            '2.7_rag_queries_prompt' => [
                "ALTER TABLE {$tRagQueries} ADD COLUMN IF NOT EXISTS prompt_snapshot text",
            ],

            '2.7_rag_query_sources' => [
                "CREATE TABLE IF NOT EXISTS {$tRagQuerySources} ( id serial4 NOT NULL, query_id int4 NOT NULL, file_id int4 NOT NULL, chunk_id int4 NULL, chunk_index int4 NOT NULL DEFAULT -1, filename varchar(255) NOT NULL, snippet text NOT NULL DEFAULT '', source_type varchar(10) NOT NULL DEFAULT 'file', rank_position int4 NOT NULL DEFAULT 0, CONSTRAINT spw_rag_query_sources_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_query_sources_query_fkey FOREIGN KEY (query_id) REFERENCES {$tRagQueries}(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_file_fkey FOREIGN KEY (file_id) REFERENCES {$tRagFiles}(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_chunk_fkey FOREIGN KEY (chunk_id) REFERENCES {$tRagChunks}(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_query_id ON {$tRagQuerySources} USING btree (query_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_file_id ON {$tRagQuerySources} USING btree (file_id)",
            ],

            '2.7_rag_chunks_embedding' => [],

            '2.7_rag_fts_english' => [
                "DROP INDEX IF EXISTS idx_spw_rag_files_content_fts",
                "DROP INDEX IF EXISTS idx_spw_rag_chunks_content_fts",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_content_fts ON $tRagFiles USING gin (to_tsvector('english', content))",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_content_fts ON $tRagChunks USING gin (to_tsvector('english', content))",
            ],

            '2.9_anonymization_log' => [
                "CREATE TABLE IF NOT EXISTS $tAnonLog ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', rules_processed int4 NULL, rows_anonymized int4 NULL, error_message text NULL, CONSTRAINT spw_anonymization_log_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_log_started_at ON $tAnonLog USING btree (started_at DESC)",
            ],

            '2.9_anonymization_report' => [
                "CREATE TABLE IF NOT EXISTS $tAnonReport ( id serial4 NOT NULL, log_id int4 NULL, report_id varchar(64) NOT NULL, triggered_by varchar(20) NULL, status varchar(20) NULL, rows_affected int4 NULL, report jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_anonymization_report_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_log_id ON $tAnonReport USING btree (log_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_created_at ON $tAnonReport USING btree (created_at DESC)",
            ],

            '2.9_automation_emails' => [
                "CREATE TABLE IF NOT EXISTS $tAutomationEmails ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', recipient varchar(255) NOT NULL, subject varchar(255) NOT NULL, body text NOT NULL DEFAULT '', source_table varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, created_by int4 NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'pending', attempts int4 NOT NULL DEFAULT 0, error_msg text NULL, created_at timestamp DEFAULT now() NOT NULL, sent_at timestamp NULL, CONSTRAINT spw_automation_emails_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_status ON $tAutomationEmails USING btree (status, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_rule_id ON $tAutomationEmails USING btree (rule_id, created_at DESC)",
            ],

            // Add future migrations below — never modify entries above.

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
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'success',
            'message' => "Migrations: {$applied_count} applied, {$skipped} already up to date.",
        ]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// List all migrations: known registry vs applied in DB
if ($action === 'migrations_list') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tMigrations = sys_table('migrations');

        // Must match keys in init_db $migrations registry — append only.
        $known = [
            '2.0_baseline',
            '2.0_record_owners_changed_at',
            '2.3.1_csv_import_tables',
            '2.4.0_release_migrations_table',
            '2.6.0_rag_files',
            '2.6.0_rag_queries',
            '2.7_automations',
            '2.7_automation_runs',
            '2.7_rag_chunks',
            '2.7_rag_queries_prompt',
            '2.7_rag_query_sources',
            '2.7_rag_fts_english',
            '2.9_anonymization_log',
            '2.9_anonymization_report',
            '2.9_automation_emails',
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
