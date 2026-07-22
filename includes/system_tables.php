<?php

declare(strict_types=1);

// system_tables.php — the spw_* system-table DDL, shared by the two entry points that
// create them: the admin init_db action (includes/admin/migrations.php, as the
// 3.0_baseline migration) and the setup wizard (public/setup_api.php, which runs before
// includes/config.php exists and therefore builds its own identifiers).
//
// Keep this file free of config.php/db.php dependencies — callers pass an $ident callback
// that maps a short table name ("users") to a fully quoted identifier ("app"."spw_users").
//
// This IS the 3.0_baseline migration body: append-only. Every statement uses IF NOT EXISTS
// so re-running is safe. Statement order matters — referenced tables come first.
// The spw_migrations tracker and CREATE SCHEMA are NOT here: both callers bootstrap those
// themselves before they can consult the migration registry.

/**
 * All spw_* system-table DDL statements.
 *
 * @param callable(string): string $ident Short table name → quoted identifier.
 * @return string[]
 */
function system_tables_ddl(callable $ident): array
{
    $tUsers            = $ident('users');
    $tUsersLog         = $ident('users_log');
    $tLoginAttempts    = $ident('login_attempts');
    $tNotifications    = $ident('users_notifications');
    $tCronLog          = $ident('users_notifications_log');
    $tFiles            = $ident('files');
    $tComments         = $ident('comments');
    $tRecordSnapshots  = $ident('record_snapshots');
    $tRecordOwners     = $ident('record_owners');
    $tRelMigrations    = $ident('release_migrations');
    $tImports          = $ident('imports');
    $tImportRowsLog    = $ident('import_rows_log');
    $tRagFiles         = $ident('rag_files');
    $tRagChunks        = $ident('rag_chunks');
    $tRagQueries       = $ident('rag_queries');
    $tRagQuerySources  = $ident('rag_query_sources');
    $tAutomationRuns   = $ident('automation_runs');
    $tAutomationEmails = $ident('automation_emails');
    $tAnonLog          = $ident('anonymization_log');
    $tAnonReport       = $ident('anonymization_report');
    $tConfig           = $ident('config');
    $tConfigLog        = $ident('config_log');
    $tEtlLog           = $ident('etl_log');
    $tEtlFlowRunLog    = $ident('etl_flow_run_log');
    $tEtlFlowStepLog   = $ident('etl_flow_step_log');

    return [
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
        "CREATE TABLE IF NOT EXISTS $tRagQuerySources ( id serial4 NOT NULL, query_id int4 NOT NULL, file_id int4 NOT NULL, chunk_id int4 NULL, chunk_index int4 NOT NULL DEFAULT -1, filename varchar(255) NOT NULL, snippet text NOT NULL DEFAULT '', source_type varchar(10) NOT NULL DEFAULT 'file', rank_position int4 NOT NULL DEFAULT 0, CONSTRAINT spw_rag_query_sources_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_query_sources_query_fkey FOREIGN KEY (query_id) REFERENCES $tRagQueries(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_file_fkey FOREIGN KEY (file_id) REFERENCES $tRagFiles(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_chunk_fkey FOREIGN KEY (chunk_id) REFERENCES $tRagChunks(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_query_id ON $tRagQuerySources USING btree (query_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_file_id ON $tRagQuerySources USING btree (file_id)",
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
        // spw_etl_log — per-job ETL run history (cron/cron_etl.php)
        "CREATE TABLE IF NOT EXISTS $tEtlLog ( id serial4 NOT NULL, job_id varchar(64) NOT NULL DEFAULT '', job_name varchar(255) NOT NULL DEFAULT '', triggered_by varchar(20) NOT NULL DEFAULT 'cron', status varchar(20) NOT NULL DEFAULT 'running', rows_read int4 NULL, rows_written int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_log_started_at ON $tEtlLog USING btree (started_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_log_job ON $tEtlLog USING btree (job_id, triggered_by, status, started_at)",
        // spw_etl_flow_run_log — per-flow ETL run history (cron/cron_etl_flow.php)
        "CREATE TABLE IF NOT EXISTS $tEtlFlowRunLog ( id serial4 NOT NULL, flow_id varchar(64) NOT NULL DEFAULT '', flow_name varchar(255) NOT NULL DEFAULT '', triggered_by varchar(20) NOT NULL DEFAULT 'cron', status varchar(20) NOT NULL DEFAULT 'running', failed_step_index int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_flow_run_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_flow_run_log_flow ON $tEtlFlowRunLog USING btree (flow_id, status, started_at)",
        // spw_etl_flow_step_log — per-step detail of a flow run; cascades with its parent run
        "CREATE TABLE IF NOT EXISTS $tEtlFlowStepLog ( id serial4 NOT NULL, flow_run_id int4 NULL, flow_id varchar(64) NOT NULL DEFAULT '', step_index int4 NOT NULL DEFAULT 0, job_id varchar(64) NOT NULL DEFAULT '', job_name varchar(255) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'running', rows_read int4 NULL, rows_written int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_flow_step_log_pkey PRIMARY KEY (id), CONSTRAINT spw_etl_flow_step_log_run_fkey FOREIGN KEY (flow_run_id) REFERENCES $tEtlFlowRunLog(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_flow_step_log_run_id ON $tEtlFlowStepLog USING btree (flow_run_id)",
    ];
}
