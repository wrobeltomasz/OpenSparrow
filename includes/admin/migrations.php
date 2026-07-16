<?php

declare(strict_types=1);

// includes/admin/migrations.php — admin api.php module: system-table migrations (init_db, migrations_list). The
// $migrations registry and
// the $known list MUST stay in this single file and match exactly — the release
// process (CLAUDE.md "Version bumps") appends to both. A third copy of the key list lives in
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
        $tConfig           = sys_table('config');
        $tConfigLog        = sys_table('config_log');

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
        // Statement order matters: tables must be created before the tables that reference them.
        $migrations = [

            '3.0_baseline' => [
                // spw_users
                "CREATE TABLE IF NOT EXISTS $tUsers ( id serial4 NOT NULL, username varchar(50) NOT NULL, password_hash varchar(255) NOT NULL, salt varchar(64), password_algo varchar(32) DEFAULT 'argon2id' NOT NULL, password_params jsonb DEFAULT '{}'::jsonb, is_active bool DEFAULT true, role varchar(20) DEFAULT 'editor' NOT NULL, avatar_id smallint, CONSTRAINT spw_users_pkey PRIMARY KEY (id), CONSTRAINT spw_users_username_key UNIQUE (username) )",
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
                "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_log_id ON $tRecordSnapshots USING btree (log_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_table_record ON $tRecordSnapshots USING btree (table_name, record_id)",
                // spw_record_owners
                "CREATE TABLE IF NOT EXISTS $tRecordOwners ( id serial4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, owner_id int4 NULL, changed_by int4 NULL, changed_at timestamp DEFAULT now() NOT NULL, is_current bool NOT NULL DEFAULT false, CONSTRAINT spw_record_owners_pkey PRIMARY KEY (id), CONSTRAINT spw_record_owners_owner_fkey FOREIGN KEY (owner_id) REFERENCES $tUsers(id) ON DELETE SET NULL, CONSTRAINT spw_record_owners_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_record_owners_current ON $tRecordOwners USING btree (table_name, record_id, is_current)",
                // spw_imports: audit trail of each CSV import run
                "CREATE TABLE IF NOT EXISTS $tImports ( id serial4 NOT NULL, user_id int4 NULL, filename varchar(255) NOT NULL, target_table varchar(100) NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', total_rows int4 NOT NULL DEFAULT 0, imported_rows int4 NOT NULL DEFAULT 0, skipped_rows int4 NOT NULL DEFAULT 0, column_mapping jsonb NULL, conflict_column varchar(100) NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_imports_pkey PRIMARY KEY (id), CONSTRAINT spw_imports_user_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_imports_started_at ON $tImports USING btree (started_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_imports_user_id ON $tImports USING btree (user_id)",
                // spw_import_rows_log: per-row errors for skipped rows
                "CREATE TABLE IF NOT EXISTS $tImportRowsLog ( id bigserial NOT NULL, import_id int4 NOT NULL, row_number int4 NOT NULL, raw_data jsonb NULL, error_message text NOT NULL, logged_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_import_rows_log_pkey PRIMARY KEY (id), CONSTRAINT spw_import_rows_log_import_fkey FOREIGN KEY (import_id) REFERENCES $tImports(id) ON DELETE CASCADE )",
                "CREATE INDEX IF NOT EXISTS idx_spw_import_rows_log_import_id ON $tImportRowsLog USING btree (import_id)",
                // spw_release_migrations
                "CREATE TABLE IF NOT EXISTS $tRelMigrations ( id serial4 NOT NULL, version varchar(20) NOT NULL, applied_at timestamp NOT NULL DEFAULT now(), applied_by int4 REFERENCES $tUsers(id) ON DELETE SET NULL, actions jsonb NOT NULL DEFAULT '[]', CONSTRAINT spw_release_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_release_migrations_version_key UNIQUE (version) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_release_migrations_version ON $tRelMigrations USING btree (version)",
                // spw_rag_files
                "CREATE TABLE IF NOT EXISTS $tRagFiles ( id serial4 NOT NULL, filename varchar(255) NOT NULL, content text NOT NULL, tags text[] NOT NULL DEFAULT '{}', file_size int4 NOT NULL DEFAULT 0, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_rag_files_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_tags ON $tRagFiles USING gin (tags)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_content_fts ON $tRagFiles USING gin (to_tsvector('english', content))",
                // spw_rag_queries
                "CREATE TABLE IF NOT EXISTS $tRagQueries ( id serial4 NOT NULL, query text NOT NULL, tags text[] NOT NULL DEFAULT '{}', matched_files int4 NOT NULL DEFAULT 0, prompt_tokens int4 NOT NULL DEFAULT 0, completion_tokens int4 NOT NULL DEFAULT 0, total_ms int4 NOT NULL DEFAULT 0, model varchar(255) NOT NULL DEFAULT '', user_id int4 NULL, created_at timestamp NOT NULL DEFAULT now(), prompt_snapshot text, CONSTRAINT spw_rag_queries_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_queries_user_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_created_at ON $tRagQueries USING btree (created_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_user_id ON $tRagQueries USING btree (user_id)",
                // spw_automation_runs
                "CREATE TABLE IF NOT EXISTS $tAutomationRuns ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', rule_name varchar(255) NOT NULL DEFAULT '', table_name varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, event varchar(20) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'ok', error_msg text NULL, executed_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_automation_runs_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_rule_id ON $tAutomationRuns USING btree (rule_id, executed_at DESC)",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_executed_at ON $tAutomationRuns USING btree (executed_at DESC)",
                // spw_rag_chunks
                "CREATE TABLE IF NOT EXISTS $tRagChunks ( id serial4 NOT NULL, file_id int4 NOT NULL, chunk_index int4 NOT NULL, content text NOT NULL, CONSTRAINT spw_rag_chunks_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_chunks_file_fkey FOREIGN KEY (file_id) REFERENCES $tRagFiles(id) ON DELETE CASCADE, CONSTRAINT spw_rag_chunks_file_chunk_key UNIQUE (file_id, chunk_index) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_file_id ON $tRagChunks USING btree (file_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_content_fts ON $tRagChunks USING gin (to_tsvector('english', content))",
                // spw_rag_query_sources
                "CREATE TABLE IF NOT EXISTS {$tRagQuerySources} ( id serial4 NOT NULL, query_id int4 NOT NULL, file_id int4 NOT NULL, chunk_id int4 NULL, chunk_index int4 NOT NULL DEFAULT -1, filename varchar(255) NOT NULL, snippet text NOT NULL DEFAULT '', source_type varchar(10) NOT NULL DEFAULT 'file', rank_position int4 NOT NULL DEFAULT 0, CONSTRAINT spw_rag_query_sources_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_query_sources_query_fkey FOREIGN KEY (query_id) REFERENCES {$tRagQueries}(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_file_fkey FOREIGN KEY (file_id) REFERENCES {$tRagFiles}(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_chunk_fkey FOREIGN KEY (chunk_id) REFERENCES {$tRagChunks}(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_query_id ON {$tRagQuerySources} USING btree (query_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_file_id ON {$tRagQuerySources} USING btree (file_id)",
                // spw_anonymization_log
                "CREATE TABLE IF NOT EXISTS $tAnonLog ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', rules_processed int4 NULL, rows_anonymized int4 NULL, error_message text NULL, CONSTRAINT spw_anonymization_log_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_log_started_at ON $tAnonLog USING btree (started_at DESC)",
                // spw_anonymization_report
                "CREATE TABLE IF NOT EXISTS $tAnonReport ( id serial4 NOT NULL, log_id int4 NULL, report_id varchar(64) NOT NULL, triggered_by varchar(20) NULL, status varchar(20) NULL, rows_affected int4 NULL, report jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_anonymization_report_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_log_id ON $tAnonReport USING btree (log_id)",
                "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_created_at ON $tAnonReport USING btree (created_at DESC)",
                // spw_automation_emails
                "CREATE TABLE IF NOT EXISTS $tAutomationEmails ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', recipient varchar(255) NOT NULL, subject varchar(255) NOT NULL, body text NOT NULL DEFAULT '', source_table varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, created_by int4 NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'pending', attempts int4 NOT NULL DEFAULT 0, error_msg text NULL, created_at timestamp DEFAULT now() NOT NULL, sent_at timestamp NULL, CONSTRAINT spw_automation_emails_pkey PRIMARY KEY (id) )",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_status ON $tAutomationEmails USING btree (status, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_rule_id ON $tAutomationEmails USING btree (rule_id, created_at DESC)",
                // spw_config — DB-backed configuration store (see includes/config_store.php)
                "CREATE TABLE IF NOT EXISTS $tConfig ( config_key varchar(64) NOT NULL, value jsonb NOT NULL, version int4 DEFAULT 1 NOT NULL, updated_by int4 NULL, updated_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_config_pkey PRIMARY KEY (config_key), CONSTRAINT spw_config_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                // spw_config_log — audit trail of config changes (old/new snapshots)
                "CREATE TABLE IF NOT EXISTS $tConfigLog ( id bigserial NOT NULL, config_key varchar(64) NOT NULL, old_value jsonb NULL, new_value jsonb NULL, changed_by int4 NULL, changed_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_config_log_pkey PRIMARY KEY (id), CONSTRAINT spw_config_log_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES $tUsers(id) ON DELETE SET NULL )",
                "CREATE INDEX IF NOT EXISTS idx_spw_config_log_key ON $tConfigLog USING btree (config_key, changed_at DESC)",
            ],

            // Add future migrations below — never modify the 3.0_baseline entry above.

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

        // One-time import of file-based configs into spw_config (idempotent: ON CONFLICT
        // DO NOTHING — an existing DB row always wins over the legacy file copy).
        // Append further keys here as modules move over to the config store.
        $importKeys = [
            'print', 'anonymization', 'user_records', 'board', 'calendar', 'dashboard',
            'views', 'automations', 'workflows', 'files', 'settings', 'rag',
            'schema', 'menu',
        ];
        foreach ($importKeys as $cfgKey) {
            $cfgPath = __DIR__ . '/../../config/' . $cfgKey . '.json';
            if (!file_exists($cfgPath)) {
                continue;
            }
            $cfgDecoded = json_decode((string) @file_get_contents($cfgPath), true);
            if (!is_array($cfgDecoded)) {
                continue;
            }
            $resImport = @pg_query_params(
                $conn,
                "INSERT INTO $tConfig (config_key, value) VALUES (\$1, \$2::jsonb) ON CONFLICT (config_key) DO NOTHING",
                [$cfgKey, json_encode($cfgDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
            if (!$resImport) {
                admin_db_fail($conn, "init_db:import_config:{$cfgKey}");
            }
            // Drop any cached file-fallback copy so readers pick up the DB row at once.
            if (function_exists('apcu_delete')) {
                apcu_delete('spw_cfg:' . sys_schema() . ':' . $cfgKey);
            }
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
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'success',
            'message' => $message,
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

        // Must match keys in init_db $migrations registry — append only below 3.0_baseline.
        $known = [
            '3.0_baseline',
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
