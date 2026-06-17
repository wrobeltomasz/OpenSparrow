<?php

declare(strict_types=1);

// admin/api.php — Main admin-panel REST API (large action-based endpoint)
// Auth gate: session + role === 'admin' (403 otherwise); CSRF on POST; DEMO_MODE disables writes
// ~60 actions by $action: init_db, users_* , create_table/add_column/schema_add_table, health, export/import backup, menu_config, get/save config files, performance_*, cron_*, list/create/delete_m2m, rag_* (knowledge base), automations_*, overview, snapshot/language/chat_bubble settings
// admin_db_fail() logs raw pg errors and returns a generic message (never leaks schema/constraint details); requires demo/seed.php at the end

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

start_session();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized access. Log in first.']);
    exit;
}

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$isDemoMode = DEMO_MODE;

// Never leak raw Postgres errors (schema names, constraint names, column lists)
// into the HTTP response. Details go to the PHP error log; the client gets a
// stable, generic message so the operator knows to check the server logs.
function admin_db_fail($conn, string $context): void
{
    $raw = $conn !== null ? pg_last_error($conn) : 'no connection';
    error_log('[admin_api][' . $context . '] ' . $raw);
    throw new RuntimeException('Database operation failed. Check server logs for details.');
}

function admin_mysql_bt(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}

function admin_mysql_pdo(): ?\PDO
{
    if (MYSQL_HOST === '' || MYSQL_DB === '' || MYSQL_USER === '') {
        return null;
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=5',
            MYSQL_HOST,
            MYSQL_PORT,
            MYSQL_DB
        );
        return new \PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    } catch (\PDOException $e) {
        error_log('[admin][mysql] ' . $e->getMessage());
        return null;
    }
}
// CSRF Protection for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'CSRF token mismatch.']);
        exit;
    }
}

// Ensure state-changing actions use POST method to prevent CSRF via GET
$postActions = ['save', 'import', 'init_db', 'users_add', 'users_toggle', 'users_update_role', 'users_change_password', 'create_table', 'add_column', 'schema_add_table', 'run_cron_notifications', 'backup_tables', 'set_snapshot_setting', 'cron_purge_log', 'create_m2m', 'delete_m2m', 'rag_upload', 'rag_delete', 'rag_rechunk', 'rag_rechunk_all', 'rag_settings_save', 'rag_test_query', 'rag_ollama_check', 'automations_save', 'automations_delete'];
if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// Run cron_notifications.php ad-hoc and return captured output
if ($action === 'run_cron_notifications') {
    header('Content-Type: application/json');
    $cronScript = realpath(__DIR__ . '/../../cron/cron_notifications.php');
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => 'Cron script not found.']);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $lines = [];
    $returnCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' admin 2>&1', $lines, $returnCode);
    echo json_encode(['status' => 'success', 'output' => implode("\n", $lines)]);
    exit;
}

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
        $argonOpts      = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $firstAdminHash = password_hash($firstAdminSalt . $tmpPassword, PASSWORD_ARGON2ID, $argonOpts);
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
                json_encode($argonOpts),
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
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
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
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch list of all system users
if ($action === 'users_list') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sql = "SELECT id, username, is_active, role FROM " . sys_table('users') . " ORDER BY id ASC";
        $res = @pg_query($conn, $sql);
        if (!$res) {
            $err = pg_last_error($conn);
            if (str_contains($err, 'is_active') || str_contains($err, 'does not exist')) {
                throw new Exception("Database schema is outdated or missing. Please initialize tables.");
            }
            admin_db_fail($conn, 'users_list');
        }

        $users = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true);
            $users[] = $row;
        }

        echo json_encode(['status' => 'success', 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Add a new user securely
if ($action === 'users_add') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role = in_array($data['role'] ?? '', ['admin', 'editor', 'viewer'], true) ? $data['role'] : 'editor';

    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'error' => 'Username and password are required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $opts    = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, $opts);
        $sql     = 'INSERT INTO ' . sys_table('users')
            . ' (username, password_hash, salt, password_algo, password_params, is_active, role)'
            . ' VALUES ($1, $2, $3, $4, $5, true, $6) RETURNING id';
        $res = @pg_query_params($conn, $sql, [
            $username, $hash, $newSalt, 'argon2id',
            json_encode($opts), $role,
        ]);
        if (!$res) {
            admin_db_fail($conn, 'users_add');
        }
        $newRow = pg_fetch_assoc($res);
        $newUserId = (int)($newRow['id'] ?? 0);
        log_user_action($conn, 0, 'ADD_USER', 'users', $newUserId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Toggle user activation status
if ($action === 'users_toggle') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

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
        log_user_action($conn, 0, 'TOGGLE_USER', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle user role update
if ($action === 'users_update_role') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['id'] ?? 0);
    $role = in_array($data['role'] ?? '', ['admin', 'editor', 'viewer'], true) ? $data['role'] : 'editor';

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
        log_user_action($conn, 0, 'UPDATE_ROLE', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Change a user's password (admin action — no current-password check required)
if ($action === 'users_change_password') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true);
    $userId   = (int)($data['id'] ?? 0);
    $password = $data['password'] ?? '';

    if ($userId <= 0 || $password === '') {
        echo json_encode(['status' => 'error', 'error' => 'User ID and password are required.']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['status' => 'error', 'error' => 'Password must be at least 8 characters.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        $conn    = db_connect();
        $newSalt = bin2hex(random_bytes(32));
        $opts    = ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1];
        $hash    = password_hash($newSalt . $password, PASSWORD_ARGON2ID, $opts);
        $sql     = 'UPDATE ' . sys_table('users')
            . ' SET password_hash = $1, salt = $2, password_algo = $3, password_params = $4 WHERE id = $5';
        $res = @pg_query_params($conn, $sql, [
            $hash, $newSalt, 'argon2id',
            json_encode(['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 1]),
            $userId,
        ]);
        if (!$res) {
            admin_db_fail($conn, 'users_change_password');
        }
        log_user_action($conn, 0, 'CHANGE_PASSWORD', 'users', $userId);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle table creation
if ($action === 'create_table') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // Sanitize schema and table variables
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? 'public'));
    $tableName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table'] ?? ''));

    if (empty($tableName) || empty($schemaName)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid schema or table name.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // Prepare schema-prefixed identifiers
        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable = pg_escape_identifier($conn, $tableName);

        // Execute table creation query
        $sql = "CREATE TABLE " . $safeSchema . "." . $safeTable . " (id serial4 NOT NULL PRIMARY KEY)";
        $res = @pg_query($conn, $sql);

        if (!$res) {
            admin_db_fail($conn, 'create_table');
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_column') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // Strict input sanitization
    $schemaName = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema'] ?? ''));
    $tableName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']  ?? ''));
    $colName    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['column'] ?? ''));
    $colType    = $input['type'] ?? 'varchar(255)';
    $comment    = isset($input['comment']) ? trim((string)$input['comment']) : '';
    $fkTable    = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_table']  ?? ''));
    $fkCol      = preg_replace('/[^a-z0-9_]/', '', strtolower($input['fk_column'] ?? ''));
    $indexType  = $input['index'] ?? '';
    $notNull    = !empty($input['not_null']);
    $default    = trim((string)($input['default'] ?? ''));

    if (empty($tableName) || empty($colName)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid table or column name.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        if ($schemaName === '') {
            $schemaName = sys_schema();
        }
        $safeSchema = pg_escape_identifier($conn, $schemaName);
        $safeTable  = pg_escape_identifier($conn, $tableName);
        $safeCol    = pg_escape_identifier($conn, $colName);

        $allowedTypes = ['varchar(255)', 'int4', 'int8', 'boolean', 'text', 'date', 'timestamp', 'timestamptz'];
        if (!in_array($colType, $allowedTypes, true)) {
            throw new Exception('Invalid data type provided.');
        }

        $sql = "ALTER TABLE " . $safeSchema . "." . $safeTable . " ADD COLUMN " . $safeCol . " " . $colType;

        if ($default !== '') {
            $safeExpressions = ['now()', 'current_timestamp', 'current_date', 'current_time', 'true', 'false', 'null'];
            if (in_array(strtolower($default), $safeExpressions, true)) {
                $sql .= ' DEFAULT ' . strtolower($default);
            } elseif (preg_match('/^\-?\d+(\.\d+)?$/', $default)) {
                $sql .= ' DEFAULT ' . $default;
            } else {
                $sql .= ' DEFAULT ' . pg_escape_literal($conn, $default);
            }
        }

        if ($notNull) {
            $sql .= ' NOT NULL';
        }

        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'add_column');
        }

        if ($comment !== '') {
            $safeComment = pg_escape_literal($conn, $comment);
            $sqlComment = "COMMENT ON COLUMN " . $safeSchema . "." . $safeTable . "." . $safeCol . " IS " . $safeComment;
            @pg_query($conn, $sqlComment);
        }

        if ($fkTable !== '' && $fkCol !== '') {
            $safeFkTable  = pg_escape_identifier($conn, $fkTable);
            $safeFkCol    = pg_escape_identifier($conn, $fkCol);
            $constraintName = pg_escape_identifier($conn, 'fk_' . $tableName . '_' . $colName);
            $sqlFk = "ALTER TABLE " . $safeSchema . "." . $safeTable
                . " ADD CONSTRAINT " . $constraintName
                . " FOREIGN KEY (" . $safeCol . ")"
                . " REFERENCES " . $safeSchema . "." . $safeFkTable . " (" . $safeFkCol . ")";
            $resFk = @pg_query($conn, $sqlFk);
            if (!$resFk) {
                admin_db_fail($conn, 'add_column_fk');
            }
        }

        $allowedIndexTypes = ['btree', 'hash', 'unique'];
        if (in_array($indexType, $allowedIndexTypes, true)) {
            $idxName = pg_escape_identifier($conn, 'idx_' . $tableName . '_' . $colName);
            $unique  = $indexType === 'unique' ? 'UNIQUE ' : '';
            $using   = $indexType === 'hash' ? 'HASH' : 'BTREE';
            $sqlIdx  = "CREATE {$unique}INDEX {$idxName} ON " . $safeSchema . "." . $safeTable
                . " USING {$using} (" . $safeCol . ")";
            $resIdx  = @pg_query($conn, $sqlIdx);
            if (!$resIdx) {
                admin_db_fail($conn, 'add_column_index');
            }
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Register a newly created table in schema.json
if ($action === 'schema_add_table') {
    header('Content-Type: application/json');

    if ($isDemoMode) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Disabled in Demo Mode.']);
        exit;
    }

    $input       = json_decode(file_get_contents('php://input'), true);
    $tableName   = preg_replace('/[^a-z0-9_]/', '', strtolower($input['table']   ?? ''));
    $schemaName  = preg_replace('/[^a-z0-9_]/', '', strtolower($input['schema']  ?? 'public'));
    $displayName = trim(strip_tags((string)($input['display_name'] ?? '')));
    $columns     = is_array($input['columns'] ?? null) ? $input['columns'] : [];

    if (empty($tableName)) {
        echo json_encode(['status' => 'error', 'error' => 'Table name is required.']);
        exit;
    }

    if ($displayName === '') {
        $displayName = ucwords(str_replace('_', ' ', $tableName));
    }

    $typeMap = [
        'varchar(255)' => 'text',
        'text'         => 'text',
        'int4'         => 'number',
        'int8'         => 'number',
        'boolean'      => 'boolean',
        'date'         => 'date',
        'timestamp'    => 'datetime',
    ];

    $colsObj = [
        'id' => ['display_name' => 'ID', 'type' => 'number', 'not_null' => true, 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true],
    ];

    foreach ($columns as $col) {
        $cName = preg_replace('/[^a-z0-9_]/', '', strtolower($col['name'] ?? ''));
        $cType = $col['type'] ?? 'varchar(255)';
        if ($cName === '' || !isset($typeMap[$cType])) {
            continue;
        }
        $cDisplay = trim(strip_tags((string)($col['display_name'] ?? '')));
        if ($cDisplay === '') {
            $cDisplay = ucwords(str_replace('_', ' ', $cName));
        }
        $entry = [
            'display_name' => $cDisplay,
            'type'         => $typeMap[$cType],
            'not_null'     => !empty($col['not_null']),
            'show_in_grid' => true,
            'show_in_edit' => true,
            'readonly'     => false,
        ];
        if (!empty($col['description'])) {
            $entry['description'] = trim(strip_tags((string)$col['description']));
        }
        if (!empty($col['fk_table']) && !empty($col['fk_column'])) {
            $entry['fk_table']  = preg_replace('/[^a-z0-9_]/', '', strtolower($col['fk_table']));
            $entry['fk_column'] = preg_replace('/[^a-z0-9_]/', '', strtolower($col['fk_column']));
        }
        $colsObj[$cName] = $entry;
    }

    $schemaFile = __DIR__ . '/../../config/schema.json';
    $schemaData = [];
    if (file_exists($schemaFile)) {
        $schemaData = json_decode(file_get_contents($schemaFile), true) ?? [];
    }
    if (!isset($schemaData['tables'])) {
        $schemaData['tables'] = [];
    }

    $schemaData['tables'][$tableName] = [
        'display_name' => $displayName,
        'schema'       => $schemaName,
        'columns'      => $colsObj,
        'foreign_keys' => [],
        'subtables'    => [],
        'hidden'       => false,
        'icon'         => '',
    ];

    $encoded = json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (strlen($encoded) > CONFIG_FILE_MAX_BYTES) {
        echo json_encode(['status' => 'error', 'error' => 'schema.json would exceed maximum allowed size.']);
        exit;
    }

    file_put_contents($schemaFile, $encoded);
    echo json_encode(['status' => 'success']);
    exit;
}

// Check database connection and system health
if ($action === 'health') {
    $db_connected = false;
    $db_error = 'Unknown error';
    $pg_version = null;
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        if ($conn) {
            $db_connected = true;
            $db_error = '';
            $vr = @pg_query($conn, 'SELECT version()');
            if ($vr) {
                $row = pg_fetch_row($vr);
                // Extract short version number from the verbose string e.g. "PostgreSQL 14.11 on ..."
                if (preg_match('/PostgreSQL\s+([\d.]+)/i', $row[0] ?? '', $m)) {
                    $pg_version = $m[1];
                }
            }
                        pg_close($conn);
        }
    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }

    $versionFile = __DIR__ . '/../../includes/VERSION';
    $appVersion = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

    $displayErrors = ini_get('display_errors');

    $data = [
        'app_version'      => $appVersion,

        // PHP environment
        'php_version'      => PHP_VERSION,
        'php_version_ok'   => version_compare(PHP_VERSION, '8.1.0', '>='),
        'memory_limit'     => ini_get('memory_limit'),
        'memory_limit_ok'  => (int) ini_get('memory_limit') >= 64 || ini_get('memory_limit') === '-1',
        'upload_max_filesize'    => ini_get('upload_max_filesize'),
        'upload_max_filesize_ok' => (int) ini_get('upload_max_filesize') >= 8,
        'display_errors_off'     => $displayErrors === '' || $displayErrors == '0' || strtolower((string) $displayErrors) === 'off',

        // Extensions
        'pgsql_ok'     => extension_loaded('pgsql') || extension_loaded('pdo_pgsql'),
        'json_ok'      => extension_loaded('json'),
        'session_ok'   => extension_loaded('session'),
        'mbstring_ok'  => extension_loaded('mbstring'),
        'fileinfo_ok'  => extension_loaded('fileinfo'),
        'openssl_ok'   => extension_loaded('openssl'),

        // Security functions
        'argon2id_ok'      => defined('PASSWORD_ARGON2ID'),
        'random_bytes_ok'  => function_exists('random_bytes'),
        'hash_equals_ok'   => function_exists('hash_equals'),
        'bin2hex_ok'       => function_exists('bin2hex'),

        // Database
        'db_connected'       => $db_connected,
        'db_error'           => $db_error,
        'pg_version'         => $pg_version,

        // Filesystem
        'dir_writable'          => is_writable(__DIR__ . '/../../config'),
        'storage_writable'      => is_dir(__DIR__ . '/../../storage') && is_writable(__DIR__ . '/../../storage'),
        'storage_files_writable' => is_dir(__DIR__ . '/../../storage/files') && is_writable(__DIR__ . '/../../storage/files'),

        // Config files
        'database_json_ok' => (static function () {
            $f = __DIR__ . '/../../config/database.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
        'schema_json_ok' => (static function () {
            $f = __DIR__ . '/../../config/schema.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
        'security_json_ok' => (static function () {
            $f = __DIR__ . '/../../config/security.json';
            return file_exists($f) && is_array(@json_decode(@file_get_contents($f), true));
        })(),
    ];
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Export JSON configurations as a ZIP file
if ($action === 'export') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    // Random suffix prevents temp file enumeration attacks
    $zipFile = sys_get_temp_dir() . '/sparrow_config_' . bin2hex(random_bytes(8)) . '.zip';
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $configDir = __DIR__ . '/../../config/';
        // database.json excluded — contains plaintext DB credentials
        $filesToBackup = ['schema.json', 'dashboard.json', 'calendar.json', 'board.json', 'security.json', 'workflows.json', 'files.json'];
        foreach ($filesToBackup as $f) {
            if (file_exists($configDir . $f)) {
                $zip->addFile($configDir . $f, $f);
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="sparrow_backup.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cannot create zip file']);
        exit;
    }
}

// Import JSON configurations from a ZIP file safely
if ($action === 'import' && isset($_FILES['backup_file'])) {
    if ($isDemoMode) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Import is disabled in Demo Mode.']);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP ZIP extension is disabled. Enable extension=zip in php.ini.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup_file']['tmp_name']) === true) {
        $extractPath = __DIR__ . '/../../config/';
        $importAllowed = ['schema', 'dashboard', 'calendar', 'board', 'database', 'security', 'workflows', 'files'];
        $validFiles = [];

        // Validate each file inside the archive
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $basename = substr($filename, 0, -5); // strip .json suffix
            // Reject any path separator (blocks subdirs and traversal), non-.json, or unknown config name
            if (
                str_contains($filename, '/') || str_contains($filename, '\\')
                || substr($filename, -5) !== '.json'
                || !in_array($basename, $importAllowed, true)
            ) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid file detected. Only known config .json files are allowed.']);
                exit;
            }
            $validFiles[] = $filename;
        }

        if (empty($validFiles)) {
            $zip->close();
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Archive contains no recognised config files.']);
            exit;
        }

        // Validate JSON content before writing. 512 KB per file is far above any real
        // config; the cap prevents zip-bomb decompression from exhausting memory.
        $maxFileBytes = 524288;
        foreach ($validFiles as $file) {
            $jsonContent = $zip->getFromName($file);
            if ($jsonContent === false) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Could not read file from archive: ' . $file]);
                exit;
            }
            if (strlen($jsonContent) > $maxFileBytes) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'File exceeds maximum allowed size: ' . $file]);
                exit;
            }
            json_decode($jsonContent);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid JSON content in archive: ' . $file]);
                exit;
            }
            $zip->extractTo($extractPath, $file);
        }

        $zip->close();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid zip file']);
    exit;
}

// Scan directories for available icons
if ($action === 'list_icons') {
    $icons = [];
    $dirsToScan = [
        'assets/icons' => __DIR__ . '/../assets/icons',
        'assets/img' => __DIR__ . '/../assets/img'
    ];
    foreach ($dirsToScan as $prefix => $dirPath) {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'gif'])) {
                        $icons[] = $prefix . '/' . $file;
                    }
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'icons' => array_values(array_unique($icons))]);
    exit;
}

// List spw_* system tables from the database for the backup page
if ($action === 'list_system_tables') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sysSchema = sys_schema();
        $sql = "SELECT table_name, table_schema FROM information_schema.tables
                WHERE table_schema = \$1 AND table_name LIKE 'spw\\_%' ESCAPE '\\'
                AND table_type = 'BASE TABLE' ORDER BY table_name";
        $res = @pg_query_params($conn, $sql, [$sysSchema]);
        if (!$res) {
            admin_db_fail($conn, 'list_system_tables');
        }
        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $tables[] = ['name' => $row['table_name'], 'schema' => $row['table_schema']];
        }
        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// GET: return current record-snapshot setting and whether it is locked by env var
if ($action === 'get_snapshot_setting') {
    header('Content-Type: application/json');
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    $lockedByEnv = ($envVal !== false && $envVal !== '');
    $enabled = false;
    if ($lockedByEnv) {
        $enabled = ($envVal === 'true');
    } else {
        $settingsFile = __DIR__ . '/../../config/settings.json';
        if (is_file($settingsFile)) {
            $raw = @file_get_contents($settingsFile);
            if ($raw !== false) {
                $s = @json_decode($raw, true);
                $enabled = (bool) ($s['record_snapshots_enabled'] ?? false);
            }
        }
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = @db_connect();
        $tSnap = sys_table('record_snapshots');
        $countRes = $conn ? @pg_query($conn, "SELECT COUNT(*) FROM $tSnap") : false;
        $snapshotCount = ($countRes && ($cr = pg_fetch_row($countRes))) ? (int) $cr[0] : null;
        $tableExists = ($countRes !== false);
    } catch (Exception $e) {
        $snapshotCount = null;
        $tableExists = false;
    }

    echo json_encode([
        'enabled'        => $enabled,
        'locked_by_env'  => $lockedByEnv,
        'table_exists'   => $tableExists,
        'snapshot_count' => $snapshotCount,
    ]);
    exit;
}

// POST: toggle record-snapshot setting in config/settings.json
if ($action === 'set_snapshot_setting') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    $envVal = getenv('RECORD_SNAPSHOTS_ENABLED');
    if ($envVal !== false && $envVal !== '') {
        echo json_encode(['status' => 'error', 'error' => 'Controlled by RECORD_SNAPSHOTS_ENABLED environment variable — cannot override from admin panel.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = (bool) ($body['enabled'] ?? false);
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }
    $settings['record_snapshots_enabled'] = $enabled;
    $written = @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json. Check directory permissions.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'enabled' => $enabled]);
    exit;
}

// GET: return language settings and all available locales from languages/*.json
if ($action === 'get_language_setting') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }

    $defaultLanguage    = is_string($settings['default_language'] ?? null) ? $settings['default_language'] : 'en';
    $availableLanguages = is_array($settings['available_languages'] ?? null) ? $settings['available_languages'] : null;

    $langDir    = __DIR__ . '/../../languages/';
    $allLocales = [];
    foreach (glob($langDir . '*.json') ?: [] as $f) {
        $code = basename($f, '.json');
        $data = @json_decode((string)@file_get_contents($f), true) ?? [];
        $allLocales[] = [
            'code' => $code,
            'name' => is_string($data['_meta']['name'] ?? null) ? $data['_meta']['name'] : $code,
        ];
    }

    if ($availableLanguages === null) {
        $availableLanguages = array_column($allLocales, 'code');
    }

    echo json_encode([
        'default_language'    => $defaultLanguage,
        'available_languages' => $availableLanguages,
        'all_locales'         => $allLocales,
    ]);
    exit;
}

// POST: save language settings to config/settings.json
if ($action === 'set_language_setting') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }

    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $defaultLang = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', (string)($body['default_language'] ?? ''))
        ? (string)$body['default_language']
        : 'en';

    $available = array_values(array_filter(
        array_map('strval', (array)($body['available_languages'] ?? [])),
        static fn(string $l): bool => (bool)preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $l)
    ));

    if (empty($available)) {
        echo json_encode(['status' => 'error', 'error' => 'At least one language must be available.']);
        exit;
    }
    if (!in_array($defaultLang, $available, true)) {
        echo json_encode(['status' => 'error', 'error' => 'Default language must be in the available languages list.']);
        exit;
    }

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }
    if (($settings['default_language'] ?? null) !== $defaultLang) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    if (!isset($settings['locale_version'])) {
        $settings['locale_version'] = bin2hex(random_bytes(8));
    }
    $settings['default_language']    = $defaultLang;
    $settings['available_languages'] = $available;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json. Check directory permissions.']);
        exit;
    }

    echo json_encode([
        'status'             => 'success',
        'default_language'   => $defaultLang,
        'available_languages' => $available,
    ]);
    exit;
}

// GET: return AI chat bubble setting
if ($action === 'get_chat_bubble_setting') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }
    echo json_encode(['chat_bubble_enabled' => (bool) ($settings['chat_bubble_enabled'] ?? false)]);
    exit;
}

// POST: save AI chat bubble setting
if ($action === 'set_chat_bubble_setting') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = !empty($body['chat_bubble_enabled']);

    $settingsFile = __DIR__ . '/../../config/settings.json';
    $settings     = [];
    if (is_file($settingsFile)) {
        $raw = @file_get_contents($settingsFile);
        if ($raw !== false) {
            $settings = @json_decode($raw, true) ?? [];
        }
    }
    $settings['chat_bubble_enabled'] = $enabled;

    $written = @file_put_contents(
        $settingsFile,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) {
        echo json_encode(['status' => 'error', 'error' => 'Could not write config/settings.json.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'chat_bubble_enabled' => $enabled]);
    exit;
}

// Create a timestamped copy of selected tables (structure + data, no indexes/constraints)
if ($action === 'backup_tables') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $tables = $input['tables'] ?? [];
    if (empty($tables) || !is_array($tables)) {
        echo json_encode(['status' => 'error', 'error' => 'No tables provided.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $prefix = date('YmdHi');
        $results = [];
        foreach ($tables as $t) {
            $tableName  = $t['name']   ?? '';
            $schemaName = $t['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $res = @pg_query($conn, $sql);
            if ($res) {
                $rows = pg_affected_rows($res);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
            } else {
                error_log('[admin_api][backup_tables] ' . pg_last_error($conn));
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Database error. Check server logs.'];
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Shared helpers for menu_config GET and POST
$menuMaxBytes = CONFIG_FILE_MAX_BYTES;
$menuSafeReadJson = static function (string $path) use ($menuMaxBytes): ?array {
    if (!file_exists($path) || filesize($path) > $menuMaxBytes) {
        return null;
    }
    $content = file_get_contents($path, false, null, 0, $menuMaxBytes);
    if ($content === false) {
        return null;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
};
$menuSanitizeIcon = static function (string $icon): string {
    if ($icon === '') {
        return '';
    }
    if (preg_match('#^(https://[^\s<>"\']+|assets/[^\s<>"\']*)$#i', $icon)) {
        return $icon;
    }
    return '';
};

// GET: return structured (possibly nested) menu item list for the admin Menu Preview tab
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $inc = __DIR__ . '/../../config';

    // Build catalog: key → full display entry
    $catalog = [];

    $dashRaw = $menuSafeReadJson($inc . '/dashboard.json') ?? [];
    $catalog['dashboard'] = [
        'type' => 'dashboard', 'key' => 'dashboard',
        'name'   => $dashRaw['menu_name'] ?? 'Dashboard',
        'icon'   => $menuSanitizeIcon((string)($dashRaw['menu_icon'] ?? 'assets/icons/dashboard.png')),
        'hidden' => !empty($dashRaw['hidden']),
        'children' => [],
    ];

    $calRaw = $menuSafeReadJson($inc . '/calendar.json') ?? [];
    $catalog['calendar'] = [
        'type' => 'calendar', 'key' => 'calendar',
        'name'   => $calRaw['menu_name'] ?? 'Calendar',
        'icon'   => $menuSanitizeIcon((string)($calRaw['menu_icon'] ?? 'assets/icons/calendar.png')),
        'hidden' => !empty($calRaw['hidden']),
        'children' => [],
    ];

    $boardRaw = $menuSafeReadJson($inc . '/board.json') ?? [];
    if (!empty($boardRaw['table']) && !empty($boardRaw['status_column'])) {
        $catalog['board'] = [
            'type' => 'board', 'key' => 'board',
            'name'   => $boardRaw['menu_name'] ?? 'Board',
            'icon'   => $menuSanitizeIcon((string)($boardRaw['menu_icon'] ?? 'assets/icons/account_tree.png')),
            'hidden' => !empty($boardRaw['hidden']),
            'children' => [],
        ];
    }

    $filesRaw = $menuSafeReadJson($inc . '/files.json') ?? [];
    $catalog['files'] = [
        'type' => 'files', 'key' => 'files',
        'name'   => $filesRaw['menu_name'] ?? 'Files',
        'icon'   => $menuSanitizeIcon((string)($filesRaw['menu_icon'] ?? 'assets/icons/folder_open.png')),
        'hidden' => !empty($filesRaw['hidden']),
        'children' => [],
    ];

    $schemaRaw = $menuSafeReadJson($inc . '/schema.json') ?? [];
    foreach ($schemaRaw['tables'] ?? [] as $tName => $tConfig) {
        $catalog[$tName] = [
            'type' => 'table', 'key' => $tName,
            'name'   => $tConfig['display_name'] ?? $tName,
            'icon'   => $menuSanitizeIcon((string)($tConfig['icon'] ?? '')),
            'hidden' => !empty($tConfig['hidden']),
            'children' => [],
        ];
    }

    $menuRaw = $menuSafeReadJson($inc . '/menu.json');
    $items   = [];
    $placed  = [];

    if ($menuRaw !== null && isset($menuRaw['items']) && is_array($menuRaw['items'])) {
        foreach ($menuRaw['items'] as $entry) {
            $key = $entry['key'] ?? '';
            if ($key === '' || !isset($catalog[$key])) {
                continue;
            }
            $item = $catalog[$key];
            $item['children'] = [];
            foreach ($entry['children'] ?? [] as $ce) {
                $ck = $ce['key'] ?? '';
                if ($ck === '' || !isset($catalog[$ck])) {
                    continue;
                }
                $child = $catalog[$ck];
                $child['children'] = [];
                $item['children'][] = $child;
                $placed[$ck] = true;
            }
            $items[]      = $item;
            $placed[$key] = true;
        }
        // Append items added after menu.json was last saved
        foreach ($catalog as $key => $entry) {
            if (!isset($placed[$key])) {
                $items[] = $entry;
            }
        }
    } else {
        $items = array_values($catalog);
    }

    echo json_encode(['items' => $items]);
    exit;
}

// POST: save menu structure (order + nesting) to config/menu.json
if ($action === 'menu_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Invalid payload']);
        exit;
    }

    $inc       = __DIR__ . '/../../config';
    $schemaRaw = $menuSafeReadJson($inc . '/schema.json') ?? [];
    $validKeys  = array_merge(['dashboard', 'calendar', 'board', 'files'], array_keys($schemaRaw['tables'] ?? []));
    $validTypes = ['dashboard', 'calendar', 'board', 'files', 'table'];

    $sanitized = [];
    foreach ($body['items'] as $entry) {
        $key  = $entry['key']  ?? '';
        $type = $entry['type'] ?? '';
        if (!in_array($key, $validKeys, true) || !in_array($type, $validTypes, true)) {
            continue;
        }
        $children = [];
        foreach ($entry['children'] ?? [] as $child) {
            $ck = $child['key']  ?? '';
            $ct = $child['type'] ?? '';
            if (!in_array($ck, $validKeys, true) || !in_array($ct, $validTypes, true)) {
                continue;
            }
            $children[] = ['type' => $ct, 'key' => $ck, 'children' => []];
        }
        $sanitized[] = ['type' => $type, 'key' => $key, 'children' => $children];
    }

    $payload  = json_encode(['items' => $sanitized], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $filePath = $inc . '/menu.json';
    $tmp      = $filePath . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Write failed']);
        exit;
    }
    @rename($tmp, $filePath);
    echo json_encode(['status' => 'success']);
    exit;
}

// Allowed config files for read and write operations
// Dodałem 'files' do autoryzowanych konfiguracji
$allowedFiles = ['schema', 'dashboard', 'calendar', 'board', 'database', 'security', 'workflows', 'files', 'views', 'automations'];

// Get content of a JSON config file
if ($action === 'get' && in_array($file, $allowedFiles, true)) {
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    header('Content-Type: application/json');
    if (file_exists($filePath)) {
        $fileContent = file_get_contents($filePath);
        // Mask sensitive data in Demo Mode
        if ($isDemoMode && $file === 'database') {
            $dbData = json_decode($fileContent, true);
            $dbData['host'] = 'hidden-for-demo.postgres.database.azure.com';
            $dbData['user'] = 'demo_user_hidden';
            $dbData['password'] = '********';
            $dbData['dbname'] = 'demo_db';
            echo json_encode($dbData);
        } else {
            echo $fileContent;
        }
    } else {
        echo json_encode(new stdClass());
    }
    exit;
}

// Save content to a JSON config file
if ($action === 'save' && in_array($file, $allowedFiles, true)) {
    // Block saving sensitive files in Demo Mode
    if ($isDemoMode && in_array($file, ['database', 'security'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Saving ' . $file . ' configuration is disabled in Demo Mode.']);
        exit;
    }

    $data = file_get_contents('php://input');
    $filePath = __DIR__ . '/../../config/' . $file . '.json';
    header('Content-Type: application/json');
    $parsedData = json_decode($data, true);
    if ($parsedData !== null) {
        if (!is_dir(__DIR__ . '/../../config/')) {
            mkdir(__DIR__ . '/../../config/', 0755, true);
        }
        file_put_contents($filePath, json_encode($parsedData, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON']);
    }
    exit;
}

// Fetch all tables from a specific database schema
// Parameters are accepted via POST JSON body (preferred — avoids WAF/ModSecurity
// rules that flag SQL-looking GET query strings on shared hosting) with a GET
// fallback for backward compatibility.
if ($action === 'sync_schema') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $schemaName = $body['schema_name'] ?? $_POST['schema_name'] ?? $_GET['schema_name'] ?? 'public';
        // Exclude OpenSparrow system tables (spw_*) so they cannot be imported as user tables.
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = $1 AND table_type = 'BASE TABLE' AND table_name NOT LIKE 'spw\\_%' ESCAPE '\\'";
        $res = @pg_query_params($conn, $sql, [$schemaName]);
        if (!$res) {
            admin_db_fail($conn, 'sync_schema');
        }

        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $tables[] = $row['table_name'];
        }

        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch all columns and their data types for a specific table
// Parameters are accepted via POST JSON body (preferred — avoids WAF/ModSecurity
// rules that flag SQL-looking GET query strings on shared hosting) with a GET
// fallback for backward compatibility.
if ($action === 'get_db_columns') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $tableName = $body['table'] ?? $_POST['table'] ?? $_GET['table'] ?? '';
        $schemaName = $body['schema_name'] ?? $_POST['schema_name'] ?? $_GET['schema_name'] ?? 'public';
        $sql = "
            SELECT
                c.column_name,
                c.data_type,
                c.is_nullable,
                c.udt_name,
                c.ordinal_position,
                pgd.description
            FROM information_schema.columns c
            LEFT JOIN pg_catalog.pg_statio_all_tables st
                ON st.schemaname = c.table_schema AND st.relname = c.table_name
            LEFT JOIN pg_catalog.pg_description pgd
                ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
            WHERE c.table_schema = \$1 AND c.table_name = \$2
            ORDER BY c.ordinal_position
        ";
        $res = @pg_query_params($conn, $sql, [$schemaName, $tableName]);
        if (!$res) {
            admin_db_fail($conn, 'get_db_columns');
        }

        $columns = [];
        while ($row = pg_fetch_assoc($res)) {
            $colName = $row['column_name'];
            $dataType = $row['data_type'];
            $udtName = $row['udt_name'];
            $enumValues = null;
            // Fetch ENUM values only for user-defined types safely using pg_escape_identifier
            if ($dataType === 'USER-DEFINED') {
                $safeSchema = pg_escape_identifier($conn, $schemaName);
                $safeUdt = pg_escape_identifier($conn, $udtName);
                $enumSql = "SELECT unnest(enum_range(NULL::$safeSchema.$safeUdt))::varchar AS enum_value";
                $enumRes = @pg_query($conn, $enumSql);
                if ($enumRes) {
                    $enumValues = [];
                    while ($e = pg_fetch_assoc($enumRes)) {
                        $enumValues[] = $e['enum_value'];
                    }
                }
            }

            // Create standard array and append column name
            $colData = [
                'column_name' => $colName,
                'type' => $dataType,
                'not_null' => ($row['is_nullable'] === 'NO'),
                'display_name' => ucfirst(str_replace('_', ' ', $colName))
            ];
            if (!empty($row['description'])) {
                $colData['description'] = $row['description'];
            }
            if ($enumValues !== null) {
                $colData['enum_values'] = $enumValues;
            }

            // Append element to array to force PHP to output JSON Array
            $columns[] = $colData;
        }

        echo json_encode(['status' => 'success', 'columns' => $columns]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_check') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaJson   = @file_get_contents(__DIR__ . '/../../config/schema.json');
        $dashJson     = @file_get_contents(__DIR__ . '/../../config/dashboard.json');
        $schemaCfg    = $schemaJson  ? (json_decode($schemaJson, true) ?? []) : [];
        $dashCfg      = $dashJson    ? (json_decode($dashJson, true) ?? []) : [];
        $tables       = $schemaCfg['tables'] ?? [];
        $widgets      = $dashCfg['widgets']  ?? [];

        // Collect [pgSchema][tableName][column] = [reasons]
        $needed = [];

        foreach ($tables as $tableName => $tableCfg) {
            $pgSchema = $tableCfg['schema'] ?? 'app';

            // FK columns on this table (child side of a relation)
            foreach (($tableCfg['foreign_keys'] ?? []) as $fkCol => $fkDef) {
                if (!is_string($fkCol)) {
                    continue;
                }
                $needed[$pgSchema][$tableName][$fkCol][] = 'Foreign key column';
            }

            // Subtables: FK column lives on child table
            foreach (($tableCfg['subtables'] ?? []) as $sub) {
                $child   = $sub['table']       ?? '';
                $fkCol   = $sub['foreign_key'] ?? '';
                if ($child === '' || $fkCol === '') {
                    continue;
                }
                $childSchema = $tables[$child]['schema'] ?? 'app';
                $needed[$childSchema][$child][$fkCol][] = "Subtable join from {$tableName}";
            }

            // Default sort columns
            foreach (($tableCfg['default_sort'] ?? []) as $rule) {
                $col = $rule['column'] ?? '';
                if ($col !== '' && $col !== 'id') {
                    $needed[$pgSchema][$tableName][$col][] = 'Default sort column';
                }
            }
        }

        // Dashboard widget columns
        foreach ($widgets as $widget) {
            $wTable = $widget['table'] ?? '';
            if ($wTable === '' || !isset($tables[$wTable])) {
                continue;
            }
            $wSchema = $tables[$wTable]['schema'] ?? 'app';
            $wTitle  = $widget['title'] ?? ($widget['id'] ?? 'widget');
            $query   = $widget['query'] ?? [];

            foreach (($query['conditions'] ?? []) as $cond) {
                $col = $cond['col'] ?? '';
                if ($col !== '' && $col !== 'id') {
                    $needed[$wSchema][$wTable][$col][] = "Widget filter: \"{$wTitle}\"";
                }
            }
            $orderBy  = $query['order_by']      ?? '';
            $groupCol = $query['group_column']   ?? '';
            $aggCol   = $query['agg_column']     ?? '';
            if ($orderBy  !== '' && $orderBy  !== 'id') {
                $needed[$wSchema][$wTable][$orderBy][]  = "Widget ORDER BY: \"{$wTitle}\"";
            }
            if ($groupCol !== '' && $groupCol !== 'id') {
                $needed[$wSchema][$wTable][$groupCol][] = "Widget GROUP BY: \"{$wTitle}\"";
            }
        }

        // For each table fetch existing pg_indexes, build set of already-indexed leading columns
        $suggestions = [];

        foreach ($needed as $pgSchema => $schemaTables) {
            foreach ($schemaTables as $tableName => $columns) {
                $res = @pg_query_params(
                    $conn,
                    "SELECT indexdef FROM pg_indexes WHERE schemaname = \$1 AND tablename = \$2",
                    [$pgSchema, $tableName]
                );
                $indexedCols = [];
                if ($res) {
                    while ($row = pg_fetch_row($res)) {
                        // Extract column list from indexdef: "... ON schema.table USING btree (col1, col2)"
                        if (preg_match('/\(([^)]+)\)/', $row[0], $m)) {
                            foreach (explode(',', $m[1]) as $ic) {
                                $ic = trim(preg_replace('/\s+(ASC|DESC|NULLS\s+(FIRST|LAST))\s*$/i', '', trim($ic)));
                                $indexedCols[] = $ic;
                            }
                        }
                    }
                }

                foreach ($columns as $col => $reasons) {
                    if (in_array($col, $indexedCols, true)) {
                        continue;
                    }

                    $priority = 'medium';
                    foreach ($reasons as $r) {
                        if (str_contains($r, 'Foreign key') || str_contains($r, 'Subtable join')) {
                            $priority = 'high';
                            break;
                        }
                    }

                    $indexName    = 'idx_' . $tableName . '_' . $col;
                    $suggestions[] = [
                        'schema'   => $pgSchema,
                        'table'    => $tableName,
                        'column'   => $col,
                        'reasons'  => array_values(array_unique($reasons)),
                        'priority' => $priority,
                        'sql'      => "CREATE INDEX IF NOT EXISTS {$indexName} ON \"{$pgSchema}\".\"{$tableName}\" ({$col});",
                    ];
                }
            }
        }

        // High priority first, then alpha by table+column
        usort($suggestions, static function ($a, $b) {
            $pa = $a['priority'] === 'high' ? 0 : 1;
            $pb = $b['priority'] === 'high' ? 0 : 1;
            if ($pa !== $pb) {
                return $pa - $pb;
            }
            $ta = $a['table'] . '.' . $a['column'];
            $tb = $b['table'] . '.' . $b['column'];
            return strcmp($ta, $tb);
        });

        echo json_encode(['status' => 'success', 'suggestions' => $suggestions, 'total' => count($suggestions)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_slow_queries') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // Check extension available
        $extRes = @pg_query($conn, "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
        if (!$extRes || pg_num_rows($extRes) === 0) {
            echo json_encode(['status' => 'unavailable', 'message' => 'pg_stat_statements extension is not installed. Run: CREATE EXTENSION pg_stat_statements;']);
            exit;
        }

        $sql = "
            SELECT query,
                   calls,
                   ROUND(mean_exec_time::numeric, 2)  AS mean_ms,
                   ROUND(total_exec_time::numeric, 2) AS total_ms,
                   ROUND(stddev_exec_time::numeric, 2) AS stddev_ms,
                   rows
            FROM pg_stat_statements
            WHERE query NOT LIKE '%pg_stat_statements%'
            ORDER BY mean_exec_time DESC
            LIMIT 15
        ";
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'slow_queries');
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_table_stats') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaJson = @file_get_contents(__DIR__ . '/../../config/schema.json');
        $schemaCfg  = $schemaJson ? (json_decode($schemaJson, true) ?? []) : [];
        $tables     = $schemaCfg['tables'] ?? [];

        // Build set of (pgSchema, tableName) pairs from schema.json
        $tracked = [];
        foreach ($tables as $tableName => $cfg) {
            $tracked[] = [$cfg['schema'] ?? 'app', $tableName];
        }

        if (empty($tracked)) {
            echo json_encode(['status' => 'success', 'rows' => []]);
            exit;
        }

        $sql = "
            SELECT s.schemaname,
                   s.relname AS tablename,
                   s.n_live_tup,
                   s.n_dead_tup,
                   CASE WHEN s.n_live_tup + s.n_dead_tup > 0
                        THEN ROUND(100.0 * s.n_dead_tup / (s.n_live_tup + s.n_dead_tup), 1)
                        ELSE 0 END AS dead_pct,
                   s.seq_scan,
                   s.idx_scan,
                   TO_CHAR(s.last_vacuum,      'YYYY-MM-DD HH24:MI') AS last_vacuum,
                   TO_CHAR(s.last_autovacuum,  'YYYY-MM-DD HH24:MI') AS last_autovacuum,
                   TO_CHAR(s.last_analyze,     'YYYY-MM-DD HH24:MI') AS last_analyze,
                   TO_CHAR(s.last_autoanalyze, 'YYYY-MM-DD HH24:MI') AS last_autoanalyze,
                   pg_size_pretty(pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))) AS total_size,
                   c.reltuples::bigint AS estimated_rows
            FROM pg_stat_user_tables s
            JOIN pg_class c ON c.relname = s.relname
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
            WHERE (s.schemaname, s.relname) = ANY(\$1::text[][])
            ORDER BY s.n_dead_tup DESC, s.seq_scan DESC
        ";

        $pairs = '{' . implode(',', array_map(fn($p) => '{"' . $p[0] . '","' . $p[1] . '"}', $tracked)) . '}';
        $res = @pg_query_params($conn, $sql, [$pairs]);
        if (!$res) {
            // Fallback: query per table if array-of-arrays not supported
            $rows = [];
            foreach ($tracked as [$pgSchema, $tableName]) {
                $r2 = @pg_query_params($conn, "
                    SELECT s.schemaname, s.relname AS tablename,
                           s.n_live_tup, s.n_dead_tup,
                           CASE WHEN s.n_live_tup + s.n_dead_tup > 0
                                THEN ROUND(100.0 * s.n_dead_tup / (s.n_live_tup + s.n_dead_tup), 1)
                                ELSE 0 END AS dead_pct,
                           s.seq_scan, s.idx_scan,
                           TO_CHAR(s.last_autovacuum,  'YYYY-MM-DD HH24:MI') AS last_autovacuum,
                           TO_CHAR(s.last_autoanalyze, 'YYYY-MM-DD HH24:MI') AS last_autoanalyze,
                           pg_size_pretty(pg_total_relation_size(quote_ident(s.schemaname) || '.' || quote_ident(s.relname))) AS total_size,
                           c.reltuples::bigint AS estimated_rows
                    FROM pg_stat_user_tables s
                    JOIN pg_class c ON c.relname = s.relname
                    JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = s.schemaname
                    WHERE s.schemaname = \$1 AND s.relname = \$2
                ", [$pgSchema, $tableName]);
                if ($r2 && $row = pg_fetch_assoc($r2)) {
                    $rows[] = $row;
                }
            }
            echo json_encode(['status' => 'success', 'rows' => $rows]);
            exit;
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_db_health') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $dbRes = @pg_query($conn, "
            SELECT datname,
                   blks_hit, blks_read,
                   CASE WHEN blks_hit + blks_read > 0
                        THEN ROUND(100.0 * blks_hit / (blks_hit + blks_read), 2)
                        ELSE 100 END AS cache_hit_ratio,
                   numbackends,
                   xact_commit, xact_rollback, deadlocks,
                   pg_size_pretty(pg_database_size(current_database())) AS db_size
            FROM pg_stat_database
            WHERE datname = current_database()
        ");
        if (!$dbRes) {
            admin_db_fail($conn, 'db_health_stat');
        }
        $db = pg_fetch_assoc($dbRes);

        $maxConnRes = @pg_query($conn, "SELECT setting FROM pg_settings WHERE name = 'max_connections'");
        $maxConn = $maxConnRes ? (int)(pg_fetch_row($maxConnRes)[0] ?? 100) : 100;

        $verRes = @pg_query($conn, "SELECT version()");
        $version = $verRes ? (pg_fetch_row($verRes)[0] ?? '') : '';

        $activeRes = @pg_query($conn, "SELECT count(*) FROM pg_stat_activity WHERE state = 'active'");
        $activeConn = $activeRes ? (int)(pg_fetch_row($activeRes)[0] ?? 0) : 0;

        echo json_encode([
            'status'       => 'success',
            'db'           => $db,
            'max_conn'     => $maxConn,
            'active_conn'  => $activeConn,
            'pg_version'   => $version,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_unused_indexes') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $sql = "
            SELECT s.schemaname, s.relname AS tablename, s.indexrelname AS indexname,
                   s.idx_scan,
                   pg_size_pretty(pg_relation_size(s.indexrelid)) AS index_size,
                   pg_relation_size(s.indexrelid) AS index_bytes,
                   i.indexdef
            FROM pg_stat_user_indexes s
            JOIN pg_indexes i ON i.schemaname = s.schemaname
                              AND i.tablename  = s.relname
                              AND i.indexname  = s.indexrelname
            WHERE s.idx_scan = 0
              AND i.indexdef NOT LIKE '%UNIQUE%'
              AND s.indexrelname NOT LIKE '%_pkey'
            ORDER BY pg_relation_size(s.indexrelid) DESC
        ";
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'unused_indexes');
        }

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $r['drop_sql'] = 'DROP INDEX IF EXISTS ' . pg_escape_identifier($conn, $r['schemaname']) . '.' . pg_escape_identifier($conn, $r['indexname']) . ';';
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'performance_schema_warnings') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $schemaJson  = @file_get_contents(__DIR__ . '/../../config/schema.json');
        $dashJson    = @file_get_contents(__DIR__ . '/../../config/dashboard.json');
        $schemaCfg   = $schemaJson ? (json_decode($schemaJson, true) ?? []) : [];
        $dashCfg     = $dashJson   ? (json_decode($dashJson, true) ?? []) : [];
        $tables      = $schemaCfg['tables'] ?? [];
        $widgets     = $dashCfg['widgets']  ?? [];

        $warnings = [];

        // Get estimated row counts from pg_class
        $rowCounts = [];
        foreach ($tables as $tableName => $cfg) {
            $pgSchema = $cfg['schema'] ?? 'app';
            $r = @pg_query_params(
                $conn,
                "SELECT c.reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = \$1 AND c.relname = \$2",
                [$pgSchema, $tableName]
            );
            if ($r && $row = pg_fetch_row($r)) {
                $rowCounts[$tableName] = (int)$row[0];
            }
        }

        foreach ($tables as $tableName => $cfg) {
            $cols     = $cfg['columns'] ?? [];
            $colCount = count($cols);
            $estRows  = $rowCounts[$tableName] ?? 0;
            $display  = $cfg['display_name'] ?? $tableName;

            // Too many columns
            if ($colCount > 20) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Schema complexity',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "{$colCount} columns defined — consider splitting or hiding non-essential columns (show_in_grid: false).",
                ];
            }

            // Large table without initial_limit
            if ($estRows > 5000 && empty($cfg['initial_limit'])) {
                $warnings[] = [
                    'severity' => 'high',
                    'category' => 'Load performance',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows) . " rows, no Initial Load Limit set — full table fetched on grid load. Set initial_limit in Schema → Table Properties.",
                ];
            }

            // Large table without default_sort
            if ($estRows > 1000 && empty($cfg['default_sort'])) {
                $warnings[] = [
                    'severity' => 'low',
                    'category' => 'UX / sort',
                    'table'    => $tableName,
                    'display'  => $display,
                    'message'  => "~" . number_format($estRows) . " rows, no Default Sort configured — falls back to id DESC. Define default_sort in Schema → Table Properties.",
                ];
            }

            // Subtables without columns_to_show
            foreach (($cfg['subtables'] ?? []) as $sub) {
                if (empty($sub['columns_to_show'])) {
                    $warnings[] = [
                        'severity' => 'medium',
                        'category' => 'Subtable config',
                        'table'    => $tableName,
                        'display'  => $display,
                        'message'  => "Subtable \"{$sub['table']}\" has no columns_to_show — all columns fetched in drilldown. Specify columns_to_show in Schema.",
                    ];
                }
            }
        }

        // Widgets without table or on large tables without limit
        foreach ($widgets as $widget) {
            $wTable = $widget['table'] ?? '';
            $wTitle = $widget['title'] ?? ($widget['id'] ?? 'widget');
            if ($wTable === '' || !isset($tables[$wTable])) {
                continue;
            }
            $estRows = $rowCounts[$wTable] ?? 0;

            if ($widget['type'] === 'list' && empty($widget['query']['limit']) && $estRows > 1000) {
                $warnings[] = [
                    'severity' => 'medium',
                    'category' => 'Widget config',
                    'table'    => $wTable,
                    'display'  => $tables[$wTable]['display_name'] ?? $wTable,
                    'message'  => "List widget \"{$wTitle}\" has no row limit on a table with ~" . number_format($estRows) . " rows — set query.limit in Dashboard editor.",
                ];
            }
        }

        // Sort: high → medium → low
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($warnings, fn($a, $b) => ($order[$a['severity']] ?? 9) - ($order[$b['severity']] ?? 9));

        echo json_encode(['status' => 'success', 'warnings' => $warnings, 'total' => count($warnings)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'cron_log') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tLog = sys_table('users_notifications_log');
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $res = @pg_query($conn, "
            SELECT id,
                   TO_CHAR(started_at,  'YYYY-MM-DD HH24:MI:SS') AS started_at,
                   TO_CHAR(finished_at, 'YYYY-MM-DD HH24:MI:SS') AS finished_at,
                   status, triggered_by, sources_processed, notifications_created, error_message,
                   CASE WHEN finished_at IS NOT NULL
                        THEN ROUND(EXTRACT(EPOCH FROM (finished_at - started_at))::numeric, 1)
                        ELSE NULL END AS duration_sec
            FROM {$tLog}
            ORDER BY started_at DESC
            LIMIT {$limit}
        ");
        if (!$res) {
            admin_db_fail($conn, 'cron_log');
        }
        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $rows[] = $r;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'cron_stats') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tN = sys_table('users_notifications');
        $tU = sys_table('users');

        $totRes = @pg_query($conn, "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE is_read = false) AS unread,
                COUNT(*) FILTER (WHERE is_read = false AND notify_date >= CURRENT_DATE) AS upcoming_unread,
                COUNT(*) FILTER (WHERE notify_date = CURRENT_DATE AND is_read = false) AS due_today
            FROM {$tN}
        ");
        if (!$totRes) {
            admin_db_fail($conn, 'cron_stats_total');
        }
        $totals = pg_fetch_assoc($totRes);

        $perUserRes = @pg_query($conn, "
            SELECT u.username, COUNT(n.id) AS unread_count
            FROM {$tN} n
            JOIN {$tU} u ON u.id = n.user_id
            WHERE n.is_read = false
            GROUP BY u.username
            ORDER BY unread_count DESC
            LIMIT 10
        ");
        if (!$perUserRes) {
            admin_db_fail($conn, 'cron_stats_per_user');
        }
        $perUser = [];
        while ($r = pg_fetch_assoc($perUserRes)) {
            $perUser[] = $r;
        }

        $lastRunRes = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI:SS') AS last_run,
                   status, notifications_created
            FROM " . sys_table('users_notifications_log') . "
            ORDER BY started_at DESC LIMIT 1
        ");
        $lastRun = ($lastRunRes && $r = pg_fetch_assoc($lastRunRes)) ? $r : null;

        echo json_encode(['status' => 'success', 'totals' => $totals, 'per_user' => $perUser, 'last_run' => $lastRun]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Many-to-Many Relationship Builder ─────────────────────────────────────────

if ($action === 'list_m2m') {
    header('Content-Type: application/json');
    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    if (!$schemaPath) {
        echo json_encode(['tables' => [], 'relationships' => []]);
        exit;
    }
    $schema = json_decode(file_get_contents($schemaPath), true);
    if (!is_array($schema['tables'] ?? null)) {
        echo json_encode(['tables' => [], 'relationships' => []]);
        exit;
    }

    $tables = [];
    $relationships = [];
    foreach ($schema['tables'] as $tName => $tCfg) {
        if (!empty($tCfg['hidden'])) {
            continue;
        }
        $tables[] = [
            'name'         => $tName,
            'display_name' => $tCfg['display_name'] ?? $tName,
            'columns'      => array_keys($tCfg['columns'] ?? []),
        ];
        foreach ($tCfg['many_to_many'] ?? [] as $i => $m2m) {
            $otherTable = $m2m['other_table'] ?? '';
            $relationships[] = [
                'table_a'         => $tName,
                'table_a_display' => $tCfg['display_name'] ?? $tName,
                'table_b'         => $otherTable,
                'table_b_display' => $schema['tables'][$otherTable]['display_name'] ?? $otherTable,
                'junction_table'  => $m2m['junction_table']  ?? '',
                'label'           => $m2m['label']           ?? '',
                'display_column'  => $m2m['display_column']  ?? '',
                'm2m_index'       => $i,
            ];
        }
    }
    echo json_encode(['tables' => $tables, 'relationships' => $relationships]);
    exit;
}

if ($action === 'create_m2m') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA     = $body['table_a']       ?? '';
    $tableB     = $body['table_b']       ?? '';
    $jt         = $body['junction_table'] ?? '';
    $selfFk     = $body['self_fk']       ?? '';
    $otherFk    = $body['other_fk']      ?? '';
    $label      = $body['label']         ?? '';
    $displayCol = $body['display_column'] ?? 'name';

    // Validate identifiers: only a-z, 0-9, underscore
    $identRe = '/^[a-z][a-z0-9_]*$/';
    foreach (['tableA' => $tableA, 'tableB' => $tableB, 'jt' => $jt, 'selfFk' => $selfFk, 'otherFk' => $otherFk] as $field => $val) {
        if (!preg_match($identRe, $val)) {
            echo json_encode(['status' => 'error', 'error' => "Invalid identifier: $val"]);
            exit;
        }
    }
    if ($tableA === $tableB) {
        echo json_encode(['status' => 'error', 'error' => 'Tables must be different.']);
        exit;
    }

    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    $schema     = json_decode(file_get_contents($schemaPath), true);
    if (!isset($schema['tables'][$tableA]) || !isset($schema['tables'][$tableB])) {
        echo json_encode(['status' => 'error', 'error' => 'One or both tables not found in schema.']);
        exit;
    }

    // Check for duplicate M2M entry
    foreach ($schema['tables'][$tableA]['many_to_many'] ?? [] as $existing) {
        if (($existing['junction_table'] ?? '') === $jt) {
            echo json_encode(['status' => 'error', 'error' => "M2M via $jt already exists on $tableA."]);
            exit;
        }
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $pgSchema = $schema['tables'][$tableA]['schema'] ?? 'public';

        // Create junction table in PostgreSQL
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS "%s"."%s" (
                id         SERIAL PRIMARY KEY,
                %s         INT NOT NULL REFERENCES "%s"."%s"(id) ON DELETE CASCADE,
                %s         INT NOT NULL REFERENCES "%s"."%s"(id) ON DELETE CASCADE,
                UNIQUE(%s, %s)
            )',
            $pgSchema,
            $jt,
            pg_ident($selfFk),
            $pgSchema,
            $tableA,
            pg_ident($otherFk),
            $pgSchema,
            $tableB,
            pg_ident($selfFk),
            pg_ident($otherFk)
        );
        $res = @pg_query($conn, $sql);
        if (!$res) {
            $err = pg_last_error($conn);
            echo json_encode(['status' => 'error', 'error' => 'PostgreSQL: ' . $err]);
            exit;
        }

        // Add hidden junction table entry to schema.json (if not exists)
        if (!isset($schema['tables'][$jt])) {
            $schema['tables'][$jt] = [
                'display_name' => str_replace('_', '–', $jt),
                'schema'       => $pgSchema,
                'hidden'       => true,
                'columns'      => [
                    'id'      => ['display_name' => 'ID',   'type' => 'number', 'not_null' => true, 'readonly' => true, 'show_in_grid' => true, 'show_in_edit' => true],
                    $selfFk   => ['display_name' => ucfirst(str_replace('_', ' ', $selfFk)),  'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                    $otherFk  => ['display_name' => ucfirst(str_replace('_', ' ', $otherFk)), 'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                ],
                'foreign_keys' => [
                    $selfFk  => ['reference_table' => $tableA, 'reference_column' => 'id', 'display_column' => 'id'],
                    $otherFk => ['reference_table' => $tableB, 'reference_column' => 'id', 'display_column' => $displayCol],
                ],
                'subtables' => [],
            ];
        }

        // Add many_to_many entry to table_a
        if (!isset($schema['tables'][$tableA]['many_to_many']) || !is_array($schema['tables'][$tableA]['many_to_many'])) {
            $schema['tables'][$tableA]['many_to_many'] = [];
        }
        $schema['tables'][$tableA]['many_to_many'][] = [
            'label'          => $label ?: ucfirst($tableB),
            'junction_table' => $jt,
            'self_fk'        => $selfFk,
            'other_fk'       => $otherFk,
            'other_table'    => $tableB,
            'display_column' => $displayCol,
        ];

        // Save schema.json
        $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($schemaPath, $encoded) === false) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to write schema.json.']);
            exit;
        }

        echo json_encode(['status' => 'success', 'junction_table' => $jt]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_m2m') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA        = $body['table_a']      ?? '';
    $m2mIndex      = (int)($body['m2m_index'] ?? -1);
    $junctionTable = $body['junction_table'] ?? '';
    $dropTable     = !empty($body['drop_table']);

    if (!preg_match('/^[a-z][a-z0-9_]*$/', $tableA)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid table_a.']);
        exit;
    }

    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    $schema     = json_decode(file_get_contents($schemaPath), true);

    if (!isset($schema['tables'][$tableA]['many_to_many'][$m2mIndex])) {
        echo json_encode(['status' => 'error', 'error' => 'M2M entry not found.']);
        exit;
    }

    // Remove the M2M entry
    array_splice($schema['tables'][$tableA]['many_to_many'], $m2mIndex, 1);
    if (empty($schema['tables'][$tableA]['many_to_many'])) {
        unset($schema['tables'][$tableA]['many_to_many']);
    }

    try {
        if ($dropTable && preg_match('/^[a-z][a-z0-9_]*$/', $junctionTable)) {
            require_once __DIR__ . '/../../includes/db.php';
            $conn     = db_connect();
            $pgSchema = $schema['tables'][$junctionTable]['schema'] ?? 'public';
            @pg_query($conn, sprintf('DROP TABLE IF EXISTS %s.%s', pg_ident($pgSchema), pg_ident($junctionTable)));
        }

        // Remove hidden junction table entry from schema.json
        if ($junctionTable && isset($schema['tables'][$junctionTable]['hidden']) && $schema['tables'][$junctionTable]['hidden'] === true) {
            // Only remove if no other table's M2M still references this junction
            $stillUsed = false;
            foreach ($schema['tables'] as $tCfg) {
                foreach ($tCfg['many_to_many'] ?? [] as $m) {
                    if (($m['junction_table'] ?? '') === $junctionTable) {
                        $stillUsed = true;
                        break 2;
                    }
                }
            }
            if (!$stillUsed) {
                unset($schema['tables'][$junctionTable]);
            }
        }

        $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($schemaPath, $encoded) === false) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to write schema.json.']);
            exit;
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'cron_purge_log') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $days = max(1, (int)(json_decode(file_get_contents('php://input'), true)['days'] ?? 30));
        $tLog = sys_table('users_notifications_log');
        $res = @pg_query_params(
            $conn,
            "DELETE FROM {$tLog} WHERE started_at < NOW() - (\$1 || ' days')::interval",
            [$days]
        );
        if (!$res) {
            admin_db_fail($conn, 'cron_purge_log');
        }
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}
// ── RAG Knowledge Base ────────────────────────────────────────────────────────

if ($action === 'rag_list') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn       = db_connect();
        $tRag       = sys_table('rag_files');
        $tRagChunks = sys_table('rag_chunks');
        $cChk       = (bool) @pg_query($conn, "SELECT 1 FROM {$tRagChunks} LIMIT 0");
        $chunkExpr  = $cChk
            ? "(SELECT COUNT(*) FROM {$tRagChunks} c WHERE c.file_id = f.id) AS chunk_count"
            : '0 AS chunk_count';
        $res        = @pg_query($conn, "SELECT f.id, f.filename, f.tags, f.file_size, f.uploaded_by, f.created_at, {$chunkExpr} FROM {$tRag} f ORDER BY f.created_at DESC");
        if (!$res) {
            admin_db_fail($conn, 'rag_list');
        }
        $files = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['chunk_count'] = (int) ($row['chunk_count'] ?? 0);
            $files[] = $row;
        }
        echo json_encode(['status' => 'success', 'files' => $files]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_upload') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['file']['error'] ?? -1;
            throw new RuntimeException('File upload error (code ' . $code . ').');
        }

        $uploadedName = (string) ($_FILES['file']['name'] ?? '');
        $tmpPath      = (string) ($_FILES['file']['tmp_name'] ?? '');
        $fileSize     = (int)   ($_FILES['file']['size'] ?? 0);

        $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            throw new RuntimeException('Only .txt files are accepted.');
        }

        $rawTagsJson = $_POST['tags'] ?? '[]';
        $tags = @json_decode($rawTagsJson, true);
        if (!is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_filter(array_map('trim', $tags), fn($t) => $t !== ''));

        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $ragCfg     = rag_config();
        $maxMb      = (int) ($ragCfg['max_file_size_mb'] ?? 10);
        $maxBytes   = $maxMb * 1024 * 1024;

        if ($fileSize > $maxBytes) {
            throw new RuntimeException("File too large. Maximum size is {$maxMb} MB.");
        }

        $content = file_get_contents($tmpPath);
        if ($content === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }

        // Reject non-UTF-8 or binary content
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('File is not valid UTF-8 text.');
        }
        // Reject files with high density of non-printable bytes (binary detection)
        $nonPrintable = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', $content);
        if ($nonPrintable > 0 && ($nonPrintable / max(1, strlen($content))) > 0.05) {
            throw new RuntimeException('File appears to contain binary content and was rejected.');
        }

        $tagLiteral  = php_array_to_pg_text($tags);
        $filename    = basename($uploadedName);
        $uploadedBy  = (int) ($_SESSION['user_id'] ?? 0);

        $res = @pg_query_params(
            $conn,
            "INSERT INTO {$tRag} (filename, content, tags, file_size, uploaded_by) VALUES (\$1, \$2, \$3::text[], \$4, \$5) RETURNING id",
            [$filename, $content, $tagLiteral, $fileSize, $uploadedBy]
        );
        if (!$res) {
            admin_db_fail($conn, 'rag_upload');
        }
        $row    = pg_fetch_assoc($res);
        $fileId = (int) $row['id'];
        if ((bool) ($ragCfg['use_chunks'] ?? true)) {
            rag_store_chunks($conn, $fileId, $content, $ragCfg);
        }
        echo json_encode(['status' => 'success', 'id' => $fileId]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_delete') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid document ID.');
        }
        $res = @pg_query_params($conn, "DELETE FROM {$tRag} WHERE id = \$1", [$id]);
        if (!$res) {
            admin_db_fail($conn, 'rag_delete');
        }
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_rechunk') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid document ID.');
        }
        $res = @pg_query_params($conn, "SELECT content FROM {$tRag} WHERE id = \$1", [$id]);
        if (!$res) {
            admin_db_fail($conn, 'rag_rechunk');
        }
        $row = pg_fetch_assoc($res);
        if (!$row) {
            throw new RuntimeException('Document not found.');
        }
        $cfg    = rag_config();
        $stored = rag_store_chunks($conn, $id, (string) $row['content'], $cfg);
        echo json_encode(['status' => 'success', 'chunks' => $stored]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_rechunk_all') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $cfg  = rag_config();

        $res = @pg_query($conn, "SELECT id, content FROM {$tRag} ORDER BY id ASC");
        if (!$res) {
            admin_db_fail($conn, 'rag_rechunk_all');
        }

        $processed = 0;
        while ($row = pg_fetch_assoc($res)) {
            rag_store_chunks($conn, (int) $row['id'], (string) $row['content'], $cfg);
            $processed++;
        }

        echo json_encode(['status' => 'success', 'processed' => $processed]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_settings') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $cfg = rag_config();
        unset($cfg['__cached']);
        echo json_encode(['status' => 'success', 'settings' => $cfg]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_settings_save') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Action disabled in Demo Mode.']);
        exit;
    }
    try {
        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $ollamaUrl        = trim((string) ($body['ollama_url'] ?? ''));
        $model            = trim((string) ($body['ollama_model'] ?? ''));
        $maxCtx           = max(1, min(20, (int) ($body['max_context_files'] ?? 3)));
        $maxSizeMb        = max(1, min(100, (int) ($body['max_file_size_mb'] ?? 10)));
        $timeout          = max(10, min(600, (int) ($body['ollama_timeout'] ?? 120)));
        $sslVerify        = isset($body['ssl_verify']) ? (bool) $body['ssl_verify'] : true;
        $useChunks        = isset($body['use_chunks']) ? (bool) $body['use_chunks'] : true;
        $convTurns        = max(0, min(10, (int) ($body['conversation_turns'] ?? 0)));

        if ($ollamaUrl === '' || $model === '') {
            throw new RuntimeException('ollama_url and ollama_model are required.');
        }
        if (!filter_var($ollamaUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('ollama_url must be a valid URL.');
        }

        $configDir  = __DIR__ . '/../../config';
        $configPath = $configDir . '/rag.json';

        // Preserve keys not exposed in the UI (chunk_size, chunk_overlap, etc.)
        $existingCfg = [];
        if (is_file($configPath)) {
            $raw = @json_decode((string) @file_get_contents($configPath), true);
            if (is_array($raw)) {
                $existingCfg = $raw;
            }
        }

        $cfg = array_merge($existingCfg, [
            'ollama_url'         => $ollamaUrl,
            'ollama_model'       => $model,
            'max_context_files'  => $maxCtx,
            'max_file_size_mb'   => $maxSizeMb,
            'ollama_timeout'     => $timeout,
            'ollama_ssl_verify'  => $sslVerify,
            'use_chunks'         => $useChunks,
            'conversation_turns' => $convTurns,
        ]);
        if (!is_dir($configDir)) {
            throw new RuntimeException('Config directory not found.');
        }
        $written = file_put_contents($configPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($written === false) {
            throw new RuntimeException('Could not write config/rag.json.');
        }
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rag_test_query') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $query    = trim((string) ($body['query'] ?? ''));
        $tags     = array_values(array_filter(array_map('trim', (array) ($body['tags'] ?? [])), fn($t) => $t !== ''));
        $language = mb_substr(trim((string) ($body['language'] ?? '')), 0, 10);

        if ($query === '') {
            throw new RuntimeException('Query is required.');
        }

        $cfg   = rag_config();
        $conn  = db_connect();
        $limit = (int) ($cfg['max_context_files'] ?? 3);
        $files = rag_retrieve($conn, $query, $tags, $limit);
        $prompt = rag_build_prompt($query, $files, '', $language);

        if (DEMO_MODE) {
            $ollamaResult = ['response' => '[Demo mode] Ollama disabled. Matched ' . count($files) . ' document(s).', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_ms' => 0];
        } else {
            $ollamaResult = rag_call_ollama(
                (string) $cfg['ollama_url'],
                (string) $cfg['ollama_model'],
                $prompt,
                (int) ($cfg['ollama_timeout'] ?? 120),
                (bool) ($cfg['ollama_ssl_verify'] ?? true)
            );
        }

        $sources = array_map(fn($f) => [
            'filename' => $f['filename'],
            'tags'     => pg_text_array_to_php($f['tags'] ?? '{}'),
        ], $files);

        $parsed = rag_extract_suggestions($ollamaResult['response']);
        $resp   = ['status' => 'success', 'answer' => $parsed['answer'], 'sources' => $sources];
        if (!empty($body['return_prompt'])) {
            $resp['prompt'] = $prompt;
        }
        echo json_encode($resp);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// POST: proxy to Ollama /api/tags — returns available local models + version
if ($action === 'rag_ollama_check') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $cfg       = rag_config();
        $ollamaUrl = trim((string) ($body['ollama_url'] ?? $cfg['ollama_url'] ?? 'http://localhost:11434'));
        $sslVerify = isset($body['ssl_verify'])
            ? (bool) $body['ssl_verify']
            : (bool) ($cfg['ollama_ssl_verify'] ?? true);

        if ($ollamaUrl === '') {
            throw new RuntimeException('ollama_url is required.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension required.');
        }

        // Fetch model list
        $tagsUrl = rtrim($ollamaUrl, '/') . '/api/tags';
        $ch      = curl_init($tagsUrl);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new RuntimeException('Cannot reach Ollama: ' . ($curlErr ?: 'no response'));
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('Ollama returned HTTP ' . $httpCode . '.');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Unexpected response from Ollama.');
        }

        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $models[] = [
                'name'     => (string) ($m['name'] ?? ''),
                'size'     => (int)    ($m['size'] ?? 0),
                'modified' => (string) ($m['modified_at'] ?? $m['modified'] ?? ''),
            ];
        }

        // Also try /api/version for server info
        $version = '';
        $vCh = curl_init(rtrim($ollamaUrl, '/') . '/api/version');
        if ($vCh !== false) {
            curl_setopt_array($vCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ]);
            $vResp = curl_exec($vCh);
            curl_close($vCh);
            if ($vResp !== false) {
                $vData = @json_decode($vResp, true);
                $version = (string) ($vData['version'] ?? '');
            }
        }

        echo json_encode(['status' => 'success', 'models' => $models, 'version' => $version]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// GET: RAG query statistics summary + recent queries with source attribution
if ($action === 'rag_stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn             = db_connect();
        $tRagQueries      = sys_table('rag_queries');
        $tRagQuerySources = sys_table('rag_query_sources');

        $summaryRes = @pg_query(
            $conn,
            "SELECT COUNT(*) AS total_queries,
                    COALESCE(ROUND(AVG(total_ms)), 0) AS avg_ms,
                    COALESCE(ROUND(AVG(prompt_tokens)), 0) AS avg_prompt_tokens,
                    COALESCE(ROUND(AVG(completion_tokens)), 0) AS avg_completion_tokens
             FROM {$tRagQueries}"
        );
        $summary = $summaryRes ? (pg_fetch_assoc($summaryRes) ?: []) : [];

        $hasPromptCol = false;
        $colChk = @pg_query($conn, "SELECT 1 FROM information_schema.columns WHERE table_name = 'spw_rag_queries' AND column_name = 'prompt_snapshot' LIMIT 1");
        if ($colChk && pg_num_rows($colChk) > 0) {
            $hasPromptCol = true;
        }
        $promptSelect = $hasPromptCol ? ', prompt_snapshot' : ', NULL AS prompt_snapshot';

        $recentRes = @pg_query(
            $conn,
            "SELECT id, query, tags, matched_files, model, prompt_tokens, completion_tokens, total_ms, created_at{$promptSelect}
             FROM {$tRagQueries}
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $recent = [];
        $ids    = [];
        if ($recentRes) {
            while ($r = pg_fetch_assoc($recentRes)) {
                $recent[] = $r;
                $ids[]    = (int) $r['id'];
            }
        }

        $sourcesByQuery = [];
        if (!empty($ids)) {
            $srcChk = @pg_query($conn, "SELECT 1 FROM {$tRagQuerySources} LIMIT 0");
            if ($srcChk !== false) {
                $idsList = implode(',', $ids);
                $srcRes  = @pg_query(
                    $conn,
                    "SELECT query_id, file_id, chunk_id, chunk_index, filename, snippet, source_type, rank_position
                     FROM {$tRagQuerySources}
                     WHERE query_id IN ({$idsList})
                     ORDER BY query_id, rank_position ASC"
                );
                if ($srcRes) {
                    while ($s = pg_fetch_assoc($srcRes)) {
                        $qid = (int) $s['query_id'];
                        if (!isset($sourcesByQuery[$qid])) {
                            $sourcesByQuery[$qid] = [];
                        }
                        $sourcesByQuery[$qid][] = $s;
                    }
                }
            }
        }

        foreach ($recent as &$row) {
            $row['sources'] = $sourcesByQuery[(int) $row['id']] ?? [];
        }
        unset($row);

        echo json_encode(['status' => 'success', 'summary' => $summary, 'recent' => $recent]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// GET: list automation run history
if ($action === 'automations_runs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn  = db_connect();
        $tRuns = sys_table('automation_runs');
        $ruleId = trim((string) ($_GET['rule_id'] ?? ''));

        if ($ruleId !== '') {
            $res = @pg_query_params(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $tRuns WHERE rule_id = \$1 ORDER BY executed_at DESC LIMIT 100",
                [$ruleId]
            );
        } else {
            $res = @pg_query(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $tRuns ORDER BY executed_at DESC LIMIT 200"
            );
        }

        $runs = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $runs[] = $row;
            }
        }
        echo json_encode(['ok' => true, 'runs' => $runs]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Automations CRUD (JSON-backed) ───────────────────────────────────────────

function auto_cfg_path(): string
{
    return __DIR__ . '/../../config/automations.json';
}

function auto_cfg_read(): array
{
    $path = auto_cfg_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return $data['automations'] ?? [];
}

function auto_cfg_write(array $automations): void
{
    $path    = auto_cfg_path();
    $json    = json_encode(['automations' => array_values($automations)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmpPath, $json, LOCK_EX);
    rename($tmpPath, $path);
}

// GET: list all automation rules
if ($action === 'automations_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    try {
        echo json_encode(['ok' => true, 'automations' => auto_cfg_read()]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// POST: create or update automation rule
if ($action === 'automations_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $name         = trim((string) ($body['name'] ?? ''));
        $enabled      = !empty($body['enabled']);
        $triggerTable = trim((string) ($body['trigger_table'] ?? ''));
        $triggerEvent = trim((string) ($body['trigger_event'] ?? ''));
        $conditions   = $body['conditions'] ?? ['type' => 'AND', 'rules' => []];
        $actions      = $body['actions'] ?? [];
        $id           = isset($body['id']) && $body['id'] !== null && $body['id'] !== ''
            ? (string) $body['id']
            : null;

        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Name is required.']);
            exit;
        }
        if (!in_array($triggerEvent, ['create', 'update', 'delete'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid trigger_event.']);
            exit;
        }

        $list  = auto_cfg_read();
        $found = false;

        $entry = [
            'id'            => $id ?? ('auto_' . bin2hex(random_bytes(6))),
            'name'          => $name,
            'enabled'       => $enabled,
            'trigger_table' => $triggerTable,
            'trigger_event' => $triggerEvent,
            'conditions'    => $conditions,
            'actions'       => $actions,
        ];

        if ($id) {
            foreach ($list as &$item) {
                if (($item['id'] ?? '') === $id) {
                    $item  = $entry;
                    $found = true;
                    break;
                }
            }
            unset($item);
        }

        if (!$found) {
            $list[] = $entry;
        }

        auto_cfg_write($list);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// POST: delete automation rule
if ($action === 'automations_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (string) ($body['id'] ?? '');
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Invalid id.']);
            exit;
        }

        $list    = auto_cfg_read();
        $filtered = array_filter($list, static fn(array $item) => ($item['id'] ?? '') !== $id);
        auto_cfg_write($filtered);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// GET: admin overview dashboard data
if ($action === 'overview') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // -- Users --
        $tUsers  = sys_table('users');
        $uRes    = @pg_query($conn, "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active FROM {$tUsers}");
        $uRow    = $uRes ? pg_fetch_assoc($uRes) : ['total' => 0, 'active' => 0];

        // -- Schema tables + per-table record counts --
        $schemaPath  = __DIR__ . '/../../config/schema.json';
        $schemaObj   = file_exists($schemaPath) ? @json_decode((string) file_get_contents($schemaPath), true) : null;
        $schemaTables = (is_array($schemaObj) && is_array($schemaObj['tables'] ?? null)) ? $schemaObj['tables'] : [];

        $mgOvPath   = __DIR__ . '/../../config/mysql_gateway.json';
        $mgOvTables = [];
        if (file_exists($mgOvPath)) {
            $mgOvRaw    = json_decode((string) file_get_contents($mgOvPath), true);
            $mgOvTables = is_array($mgOvRaw) ? ($mgOvRaw['mysql_tables'] ?? []) : [];
        }
        $mysqlPdoOv = null;

        $tables   = [];
        $totalRec = 0;
        foreach ($schemaTables as $tableName => $tableDef) {
            $tableSchema = $tableDef['schema'] ?? 'public';
            if (in_array($tableName, $mgOvTables, true)) {
                if ($mysqlPdoOv === null) {
                    $mysqlPdoOv = admin_mysql_pdo();
                }
                $count = 0;
                if ($mysqlPdoOv !== null) {
                    try {
                        $stmtOv = $mysqlPdoOv->query(
                            'SELECT COUNT(*) FROM '
                            . admin_mysql_bt(MYSQL_DB) . '.' . admin_mysql_bt((string) $tableName)
                        );
                        $count = $stmtOv ? (int) $stmtOv->fetchColumn() : 0;
                    } catch (\PDOException $e) {
                        error_log('[admin][overview][mysql] ' . $e->getMessage());
                    }
                }
            } else {
                $safeTable = sprintf('%s.%s', pg_ident($tableSchema), pg_ident((string) $tableName));
                $cRes  = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$safeTable}");
                $count = $cRes ? (int) pg_fetch_result($cRes, 0, 0) : 0;
            }
            $totalRec += $count;
            $tables[] = [
                'name'  => $tableName,
                'label' => $tableDef['display_name'] ?? $tableName,
                'count' => $count,
            ];
        }
        usort($tables, static fn($a, $b) => $b['count'] - $a['count']);

        // -- Files --
        $tFiles = sys_table('files');
        $fRes   = @pg_query($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS total_bytes FROM {$tFiles} WHERE deleted_at IS NULL");
        $fRow   = $fRes ? pg_fetch_assoc($fRes) : ['n' => 0, 'total_bytes' => 0];

        // -- RAG documents (table has no deleted_at column) --
        $tRag   = sys_table('rag_files');
        $rRes   = @pg_query($conn, "SELECT COUNT(*) AS n FROM {$tRag}");
        $ragCount = ($rRes && pg_num_rows($rRes) > 0) ? (int) pg_fetch_result($rRes, 0, 0) : 0;

        // -- Views (config-driven) --
        $viewsPath = __DIR__ . '/../../config/views.json';
        $viewsObj  = file_exists($viewsPath) ? @json_decode((string) file_get_contents($viewsPath), true) : null;
        $viewCount = (is_array($viewsObj) && is_array($viewsObj['views'] ?? null)) ? count($viewsObj['views']) : 0;

        // -- Automations (config-driven) --
        $autoCount = count(auto_cfg_read());

        // -- Cron recent runs (last 5) --
        $tCronLog = sys_table('users_notifications_log');
        $cLogRes  = @pg_query($conn, "
            SELECT TO_CHAR(started_at, 'YYYY-MM-DD HH24:MI') AS started_at,
                   status, triggered_by,
                   COALESCE(notifications_created, 0) AS sent
            FROM {$tCronLog}
            ORDER BY started_at DESC
            LIMIT 5
        ");
        $cronRecent  = [];
        $lastCronRun = null;
        if ($cLogRes) {
            while ($r = pg_fetch_assoc($cLogRes)) {
                if ($lastCronRun === null) {
                    $lastCronRun = $r['started_at'];
                }
                $cronRecent[] = $r;
            }
        }

        // -- Audit log recent (last 8) --
        $tLog  = sys_table('users_log');
        $aRes  = @pg_query($conn, "
            SELECT ul.action, ul.target_table,
                   TO_CHAR(ul.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   u.username
            FROM {$tLog} ul
            LEFT JOIN {$tUsers} u ON u.id = ul.user_id
            ORDER BY ul.created_at DESC
            LIMIT 8
        ");
        $auditRecent = [];
        if ($aRes) {
            while ($r = pg_fetch_assoc($aRes)) {
                $auditRecent[] = $r;
            }
        }

        // -- Database size --
        $dbSizeRes  = @pg_query($conn, 'SELECT pg_database_size(current_database()) AS sz');
        $dbSizeBytes = ($dbSizeRes) ? (int) pg_fetch_result($dbSizeRes, 0, 0) : 0;

        // -- Pending system migrations --
        $tMig   = sys_table('migrations');
        $mRes   = @pg_query($conn, "SELECT name FROM {$tMig}");
        $applied = [];
        if ($mRes) {
            while ($r = pg_fetch_row($mRes)) {
                $applied[$r[0]] = true;
            }
        }
        $knownMig = [
            '2.0_baseline', '2.0_record_owners_changed_at', '2.3.1_csv_import_tables',
            '2.4.0_release_migrations_table', '2.6.0_rag_files', '2.6.0_rag_queries',
            '2.7_automations', '2.7_automation_runs',
            '2.7_rag_chunks', '2.7_rag_queries_prompt', '2.7_rag_query_sources',
            '2.7_rag_fts_english',
        ];
        $pendingMig = count(array_filter($knownMig, static fn($n) => !isset($applied[$n])));

        // -- System quick status --
        $versionFile  = __DIR__ . '/../../includes/VERSION';
        $appVersion   = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';
        $pgVerRes     = @pg_query($conn, 'SELECT version()');
        $pgVersionRaw = $pgVerRes ? (string) pg_fetch_result($pgVerRes, 0, 0) : '';
        $pgVersion    = '';
        if (preg_match('/PostgreSQL\s+([\d.]+)/i', $pgVersionRaw, $m)) {
            $pgVersion = $m[1];
        }
        $displayErrors = ini_get('display_errors');

        echo json_encode([
            'status'            => 'success',
            'app_version'       => $appVersion,
            'user_total'        => (int) $uRow['total'],
            'user_active'       => (int) $uRow['active'],
            'table_count'       => count($tables),
            'tables'            => $tables,
            'total_records'     => $totalRec,
            'file_count'        => (int) $fRow['n'],
            'file_size_bytes'   => (int) $fRow['total_bytes'],
            'rag_count'         => $ragCount,
            'view_count'        => $viewCount,
            'automation_count'  => $autoCount,
            'last_cron_run'     => $lastCronRun,
            'cron_recent'       => $cronRecent,
            'audit_recent'      => $auditRecent,
            'db_size_bytes'     => $dbSizeBytes,
            'pg_version'        => $pgVersion,
            'php_version'       => PHP_VERSION,
            'php_ok'            => version_compare(PHP_VERSION, '8.1.0', '>='),
            'display_errors_ok' => ($displayErrors === '' || $displayErrors == '0' || strtolower((string) $displayErrors) === 'off'),
            'pending_migrations' => $pendingMig,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/demo/seed.php';
