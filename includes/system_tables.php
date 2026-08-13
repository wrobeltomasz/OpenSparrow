<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// system_tables.php — the spw_* system-table DDL, shared by the two entry points that
// create them: the admin init_db action (includes/admin/migrations.php, as the
// 3.0_baseline migration) and the setup wizard (public/setup_api.php, which runs before
// includes/config.php exists and therefore builds its own identifiers).
//
// Keep this file free of config.php/db.php dependencies — callers pass an $ident callback
// that maps a short table name ("users") to a fully quoted identifier ("app"."spw_users").
//
// system_tables_ddl() IS the 3.0_baseline migration body: append-only.
// (The functions below are migration bodies too — system_tables_comments_ddl() is
// 3.1_table_comments, system_tables_user_contact_ddl() is 3.3_user_contact and
// system_tables_clickstats_ddl() is 3.3_clickstats; see their docblocks. A migration
// whose body lands here must be run AND recorded by both callers, or a fresh install
// drifts from an upgraded one.) Every statement uses IF NOT EXISTS
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
    $tNotes            = $ident('notes');
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
        // spw_notes: private user notepad, optionally linked to a record, with an optional reminder date
        "CREATE TABLE IF NOT EXISTS $tNotes ( id serial4 NOT NULL, user_id int4 NOT NULL, related_table varchar(100) NULL, related_id int4 NULL, body text NOT NULL, reminder_date date NULL, created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp NULL, deleted_at timestamp NULL, CONSTRAINT spw_notes_pkey PRIMARY KEY (id), CONSTRAINT spw_notes_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_notes_user_id_fkey FOREIGN KEY (user_id) REFERENCES $tUsers(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_notes_user_id ON $tNotes USING btree (user_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_notes_reminder ON $tNotes USING btree (reminder_date) WHERE (reminder_date IS NOT NULL)",
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

/**
 * COMMENT ON TABLE / COMMENT ON COLUMN for every spw_* table and column.
 *
 * Metadata only — idempotent and safe to re-run. This IS the body of the
 * 3.1_table_comments migration (includes/admin/migrations.php); the setup wizard
 * (public/setup_api.php) runs the same list so a fresh install is created with the
 * descriptions already in place. Keep in sync with docs/DATABASE.md and with the
 * standalone copy in docs/sql/spw_comments.sql.
 *
 * Covers spw_migrations too: that table is created by the bootstrap block rather than
 * by system_tables_ddl(), but it exists by the time these statements run.
 *
 * @param callable(string): string $ident Short table name → quoted identifier.
 * @return string[]
 */
function system_tables_comments_ddl(callable $ident): array
{
    $tMigrations        = $ident('migrations');
    $tUsers             = $ident('users');
    $tUsersLog          = $ident('users_log');
    $tLoginAttempts     = $ident('login_attempts');
    $tRecordSnapshots   = $ident('record_snapshots');
    $tRecordOwners      = $ident('record_owners');
    $tConfig            = $ident('config');
    $tConfigLog         = $ident('config_log');
    $tRelMigrations     = $ident('release_migrations');
    $tFiles             = $ident('files');
    $tComments          = $ident('comments');
    $tNotes             = $ident('notes');
    $tNotifications     = $ident('users_notifications');
    $tCronLog           = $ident('users_notifications_log');
    $tAutomationRuns    = $ident('automation_runs');
    $tAutomationEmails  = $ident('automation_emails');
    $tImports           = $ident('imports');
    $tImportRowsLog     = $ident('import_rows_log');
    $tRagFiles          = $ident('rag_files');
    $tRagChunks         = $ident('rag_chunks');
    $tRagQueries        = $ident('rag_queries');
    $tRagQuerySources   = $ident('rag_query_sources');
    $tAnonLog           = $ident('anonymization_log');
    $tAnonReport        = $ident('anonymization_report');
    $tEtlLog            = $ident('etl_log');
    $tEtlFlowRunLog     = $ident('etl_flow_run_log');
    $tEtlFlowStepLog    = $ident('etl_flow_step_log');

    return [
        // 1. Bootstrap

        "COMMENT ON TABLE $tMigrations IS 'Tracker of applied database migrations. Created by the init_db bootstrap block, not by system_tables_ddl(), because it must exist before the migration registry can be consulted.'",
        "COMMENT ON COLUMN $tMigrations.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tMigrations.name IS 'Migration key, e.g. 3.0_baseline. Presence of the row means already applied - init_db skips it on re-run. UNIQUE.'",
        "COMMENT ON COLUMN $tMigrations.applied_at IS 'When the migration ran.'",

        // 2. Users, auth, audit

        "COMMENT ON TABLE $tUsers IS 'Application accounts. Referenced by most other system tables.'",
        "COMMENT ON COLUMN $tUsers.id IS 'User id; referenced by most other system tables.'",
        "COMMENT ON COLUMN $tUsers.username IS 'Login name. UNIQUE.'",
        "COMMENT ON COLUMN $tUsers.password_hash IS 'Hash produced by PHP password_hash() (Argon2id by default). Never a plaintext or reversible value.'",
        "COMMENT ON COLUMN $tUsers.salt IS 'Legacy/optional extra salt. Modern Argon2id hashes carry their salt inside password_hash.'",
        "COMMENT ON COLUMN $tUsers.password_algo IS 'Algorithm the hash was produced with; drives rehash-on-login decisions. Default argon2id.'",
        "COMMENT ON COLUMN $tUsers.password_params IS 'Cost parameters used for the hash (memory/time/threads), for rehash comparison.'",
        "COMMENT ON COLUMN $tUsers.is_active IS 'Soft disable - inactive users cannot log in but their audit rows survive.'",
        "COMMENT ON COLUMN $tUsers.role IS 'Authorization level (admin / editor / read-only roles). Enforced server-side in api_bootstrap.php and requireWrite().'",
        "COMMENT ON COLUMN $tUsers.avatar_id IS 'Avatar colour: 1-based index into the avatar palette (OS_AVATAR_COLORS). NULL = default colour. The avatar itself is the initial of the username.'",

        "COMMENT ON TABLE $tUsersLog IS 'User action audit trail. Written by log_user_action() (includes/api_helpers.php); every mutation must produce a row.'",
        "COMMENT ON COLUMN $tUsersLog.id IS 'Log entry id; referenced by spw_record_snapshots.log_id.'",
        "COMMENT ON COLUMN $tUsersLog.user_id IS 'Acting user (spw_users.id). No foreign key, so history survives user deletion.'",
        "COMMENT ON COLUMN $tUsersLog.action IS 'Action name, e.g. insert, update, delete, login.'",
        "COMMENT ON COLUMN $tUsersLog.target_table IS 'Table the action touched.'",
        "COMMENT ON COLUMN $tUsersLog.record_id IS 'Affected record primary key.'",
        "COMMENT ON COLUMN $tUsersLog.created_at IS 'When the action happened.'",

        "COMMENT ON TABLE $tLoginAttempts IS 'Login attempt journal backing the rate limiter. Pruned by cron/cron_notifications.php.'",
        "COMMENT ON COLUMN $tLoginAttempts.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tLoginAttempts.username IS 'Username the attempt was made for, recorded even if it does not exist.'",
        "COMMENT ON COLUMN $tLoginAttempts.ip_hash IS 'Salted hash of the client IP (IP_HASH_SALT). The raw IP is never stored.'",
        "COMMENT ON COLUMN $tLoginAttempts.attempted_at IS 'Attempt timestamp; the sliding window for the throttle.'",

        "COMMENT ON TABLE $tRecordSnapshots IS 'Full JSON row snapshots taken at the moment of a change. Retention configurable in Admin - Settings.'",
        "COMMENT ON COLUMN $tRecordSnapshots.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tRecordSnapshots.log_id IS 'Audit entry this snapshot belongs to. FK to spw_users_log(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $tRecordSnapshots.table_name IS 'Snapshotted table.'",
        "COMMENT ON COLUMN $tRecordSnapshots.record_id IS 'Snapshotted record.'",
        "COMMENT ON COLUMN $tRecordSnapshots.snapshot IS 'Full row content as JSON at the moment of the change.'",
        "COMMENT ON COLUMN $tRecordSnapshots.created_at IS 'Snapshot time.'",

        "COMMENT ON TABLE $tRecordOwners IS 'Record ownership history. The row flagged is_current drives owner_restriction_sql() row filtering.'",
        "COMMENT ON COLUMN $tRecordOwners.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tRecordOwners.table_name IS 'Owned table.'",
        "COMMENT ON COLUMN $tRecordOwners.record_id IS 'Owned record.'",
        "COMMENT ON COLUMN $tRecordOwners.owner_id IS 'Current or former owner. FK to spw_users(id) ON DELETE SET NULL; NULL when the user is deleted or ownership is cleared.'",
        "COMMENT ON COLUMN $tRecordOwners.changed_by IS 'Who performed the ownership change. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tRecordOwners.changed_at IS 'When ownership changed.'",
        "COMMENT ON COLUMN $tRecordOwners.is_current IS 'Marks the one active row per record; older rows stay as history.'",

        // 3. Configuration store

        "COMMENT ON TABLE $tConfig IS 'DB-backed application configuration: one JSONB row per key (schema, menu, settings, dashboard, calendar, board, workflows, automations, views, files, print, anonymization, user_records, rag, ...). Accessed only through includes/config_store.php.'",
        "COMMENT ON COLUMN $tConfig.config_key IS 'Configuration key name (what used to be a config/*.json filename, without the extension). Primary key.'",
        "COMMENT ON COLUMN $tConfig.value IS 'The whole configuration document for that key.'",
        "COMMENT ON COLUMN $tConfig.version IS 'Optimistic-lock counter; a save with a stale version is rejected instead of overwriting a concurrent edit.'",
        "COMMENT ON COLUMN $tConfig.updated_by IS 'Last editor. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tConfig.updated_at IS 'Last save time.'",

        "COMMENT ON TABLE $tConfigLog IS 'Audit trail of configuration changes, with old/new document snapshots.'",
        "COMMENT ON COLUMN $tConfigLog.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tConfigLog.config_key IS 'Configuration key that changed.'",
        "COMMENT ON COLUMN $tConfigLog.old_value IS 'Document before the change; NULL on first insert.'",
        "COMMENT ON COLUMN $tConfigLog.new_value IS 'Document after the change; NULL on delete.'",
        "COMMENT ON COLUMN $tConfigLog.changed_by IS 'Editor. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tConfigLog.changed_at IS 'Change time.'",

        "COMMENT ON TABLE $tRelMigrations IS 'Applied release migrations - the file and config-key cleanups from config/migrations.json, applied via public/admin/api_migrations.php. Distinct from spw_migrations, which tracks database DDL.'",
        "COMMENT ON COLUMN $tRelMigrations.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tRelMigrations.version IS 'Release version processed, e.g. 3.1. UNIQUE.'",
        "COMMENT ON COLUMN $tRelMigrations.applied_at IS 'When the release migration was applied.'",
        "COMMENT ON COLUMN $tRelMigrations.applied_by IS 'Admin who ran it. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tRelMigrations.actions IS 'Log of what was actually done: files deleted, config keys stripped, skips.'",

        // 4. Content attached to records

        "COMMENT ON TABLE $tFiles IS 'Uploaded files, record attachments and record image galleries. Served only through the files.php / api/files.php / file_download.php proxies, never by direct path.'",
        "COMMENT ON COLUMN $tFiles.id IS 'Internal id. Never exposed in URLs.'",
        "COMMENT ON COLUMN $tFiles.uuid IS 'Public handle used by files.php, api/files.php and file_download.php. UNIQUE, defaults to gen_random_uuid().'",
        "COMMENT ON COLUMN $tFiles.name IS 'Server-generated storage filename - never the client-supplied name.'",
        "COMMENT ON COLUMN $tFiles.display_name IS 'Human-facing label shown in the UI.'",
        "COMMENT ON COLUMN $tFiles.type IS 'Coarse category (image, document, ...) used for filtering and icons.'",
        "COMMENT ON COLUMN $tFiles.mime_type IS 'MIME type detected from file content with finfo, not from the upload header.'",
        "COMMENT ON COLUMN $tFiles.extension IS 'Whitelisted file extension.'",
        "COMMENT ON COLUMN $tFiles.size_bytes IS 'File size in bytes.'",
        "COMMENT ON COLUMN $tFiles.storage_path IS 'Path under storage/files/, outside the document root and outside any execution path.'",
        "COMMENT ON COLUMN $tFiles.related_table IS 'Table the file is attached to; NULL for standalone library files.'",
        "COMMENT ON COLUMN $tFiles.related_id IS 'Record the file is attached to.'",
        "COMMENT ON COLUMN $tFiles.related_field IS 'Field the attachment belongs to. The sentinel __image (IMAGES_FIELD, includes/images.php) marks a record gallery image (feature added in 3.1, no schema change).'",
        "COMMENT ON COLUMN $tFiles.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tFiles.created_at IS 'Upload time. Gallery ordering uses id, which follows this.'",
        "COMMENT ON COLUMN $tFiles.updated_at IS 'Last metadata change.'",
        "COMMENT ON COLUMN $tFiles.deleted_at IS 'Soft delete marker - all read paths filter deleted_at IS NULL.'",
        "COMMENT ON COLUMN $tFiles.description IS 'Free-text description.'",
        "COMMENT ON COLUMN $tFiles.tags IS 'Tag array used for filtering (GIN indexed).'",
        "COMMENT ON COLUMN $tFiles.metadata IS 'Extra attributes: image dimensions, thumbnail info, and similar (GIN indexed).'",

        "COMMENT ON TABLE $tComments IS 'Per-record discussion threads, visible to all users who can see the record.'",
        "COMMENT ON COLUMN $tComments.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tComments.related_table IS 'Commented table.'",
        "COMMENT ON COLUMN $tComments.related_id IS 'Commented record.'",
        "COMMENT ON COLUMN $tComments.user_id IS 'Author. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tComments.body IS 'Comment text. CHECK char_length(body) <= 4000.'",
        "COMMENT ON COLUMN $tComments.created_at IS 'Post time.'",
        "COMMENT ON COLUMN $tComments.deleted_at IS 'Soft delete marker.'",

        "COMMENT ON TABLE $tNotes IS 'Private user notepad. Unlike comments, notes are visible only to their author; they may be free-floating or attached to a record, and may carry a reminder date.'",
        "COMMENT ON COLUMN $tNotes.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tNotes.user_id IS 'Owner. FK to spw_users(id) ON DELETE CASCADE - notes die with the account.'",
        "COMMENT ON COLUMN $tNotes.related_table IS 'Optional linked table.'",
        "COMMENT ON COLUMN $tNotes.related_id IS 'Optional linked record.'",
        "COMMENT ON COLUMN $tNotes.body IS 'Note text. CHECK char_length(body) <= 4000.'",
        "COMMENT ON COLUMN $tNotes.reminder_date IS 'If set, cron/cron_notifications.php raises a notification once that date and time has passed.'",
        "COMMENT ON COLUMN $tNotes.created_at IS 'Creation time.'",
        "COMMENT ON COLUMN $tNotes.updated_at IS 'Last edit; NULL if never edited.'",
        "COMMENT ON COLUMN $tNotes.deleted_at IS 'Soft delete marker.'",

        // 5. Notifications

        "COMMENT ON TABLE $tNotifications IS 'Per-user notification inbox. UNIQUE (user_id, source_table, source_id, notify_date) is the deduplication key that makes the cron worker idempotent when it re-runs on the same day.'",
        "COMMENT ON COLUMN $tNotifications.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tNotifications.user_id IS 'Recipient (spw_users.id).'",
        "COMMENT ON COLUMN $tNotifications.title IS 'Notification headline.'",
        "COMMENT ON COLUMN $tNotifications.link IS 'Target URL to open on click.'",
        "COMMENT ON COLUMN $tNotifications.source_table IS 'Table that triggered the notification.'",
        "COMMENT ON COLUMN $tNotifications.source_id IS 'Record that triggered the notification.'",
        "COMMENT ON COLUMN $tNotifications.is_read IS 'Read flag, toggled from api/notifications.php.'",
        "COMMENT ON COLUMN $tNotifications.notify_date IS 'Date the notification is for; part of the deduplication key.'",
        "COMMENT ON COLUMN $tNotifications.created_at IS 'Generation time.'",

        "COMMENT ON TABLE $tCronLog IS 'Run history of the notification cron worker (cron/cron_notifications.php).'",
        "COMMENT ON COLUMN $tCronLog.id IS 'Run id.'",
        "COMMENT ON COLUMN $tCronLog.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $tCronLog.finished_at IS 'Run end; NULL while running or after a crash.'",
        "COMMENT ON COLUMN $tCronLog.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $tCronLog.triggered_by IS 'cron, or a manual admin trigger.'",
        "COMMENT ON COLUMN $tCronLog.sources_processed IS 'Number of configured notification sources scanned.'",
        "COMMENT ON COLUMN $tCronLog.notifications_created IS 'Rows actually inserted into spw_users_notifications.'",
        "COMMENT ON COLUMN $tCronLog.error_message IS 'Failure detail when status = error.'",

        // 6. Automations

        "COMMENT ON TABLE $tAutomationRuns IS 'Execution log of automation rules (includes/automations.php), one row per rule firing.'",
        "COMMENT ON COLUMN $tAutomationRuns.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tAutomationRuns.rule_id IS 'Rule identifier from the automations config key.'",
        "COMMENT ON COLUMN $tAutomationRuns.rule_name IS 'Rule label captured at run time; survives later renames.'",
        "COMMENT ON COLUMN $tAutomationRuns.table_name IS 'Table whose change fired the rule.'",
        "COMMENT ON COLUMN $tAutomationRuns.record_id IS 'Triggering record.'",
        "COMMENT ON COLUMN $tAutomationRuns.event IS 'Trigger event: insert / update / delete.'",
        "COMMENT ON COLUMN $tAutomationRuns.status IS 'ok or error.'",
        "COMMENT ON COLUMN $tAutomationRuns.error_msg IS 'Failure detail.'",
        "COMMENT ON COLUMN $tAutomationRuns.executed_at IS 'Execution time.'",

        "COMMENT ON TABLE $tAutomationEmails IS 'Outbound e-mail queue. Rows are enqueued by the automation engine and delivered by cron/cron_notifications.php.'",
        "COMMENT ON COLUMN $tAutomationEmails.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tAutomationEmails.rule_id IS 'Automation rule that queued the message.'",
        "COMMENT ON COLUMN $tAutomationEmails.recipient IS 'Destination e-mail address.'",
        "COMMENT ON COLUMN $tAutomationEmails.subject IS 'Rendered subject line.'",
        "COMMENT ON COLUMN $tAutomationEmails.body IS 'Rendered message body.'",
        "COMMENT ON COLUMN $tAutomationEmails.source_table IS 'Table of the triggering record.'",
        "COMMENT ON COLUMN $tAutomationEmails.record_id IS 'Triggering record.'",
        "COMMENT ON COLUMN $tAutomationEmails.created_by IS 'User whose action queued the message; 0 for system/cron.'",
        "COMMENT ON COLUMN $tAutomationEmails.status IS 'pending / sent / error.'",
        "COMMENT ON COLUMN $tAutomationEmails.attempts IS 'Delivery attempts, for retry back-off.'",
        "COMMENT ON COLUMN $tAutomationEmails.error_msg IS 'Last delivery error.'",
        "COMMENT ON COLUMN $tAutomationEmails.created_at IS 'Enqueue time.'",
        "COMMENT ON COLUMN $tAutomationEmails.sent_at IS 'Successful delivery time.'",

        // 7. CSV import

        "COMMENT ON TABLE $tImports IS 'One row per CSV import run (public/admin/api_csv_import.php).'",
        "COMMENT ON COLUMN $tImports.id IS 'Import run id.'",
        "COMMENT ON COLUMN $tImports.user_id IS 'Who ran the import. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tImports.filename IS 'Uploaded file name as shown to the user.'",
        "COMMENT ON COLUMN $tImports.target_table IS 'Destination table.'",
        "COMMENT ON COLUMN $tImports.status IS 'pending / running / done / error.'",
        "COMMENT ON COLUMN $tImports.total_rows IS 'Data rows found in the file.'",
        "COMMENT ON COLUMN $tImports.imported_rows IS 'Rows inserted or updated.'",
        "COMMENT ON COLUMN $tImports.skipped_rows IS 'Rows rejected; each one detailed in spw_import_rows_log.'",
        "COMMENT ON COLUMN $tImports.column_mapping IS 'CSV column to table column map used for this run.'",
        "COMMENT ON COLUMN $tImports.conflict_column IS 'Column used for upsert matching; NULL for insert-only runs.'",
        "COMMENT ON COLUMN $tImports.error_message IS 'Run-level failure detail.'",
        "COMMENT ON COLUMN $tImports.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $tImports.finished_at IS 'Run end.'",

        "COMMENT ON TABLE $tImportRowsLog IS 'Per-row rejection detail for a CSV import run.'",
        "COMMENT ON COLUMN $tImportRowsLog.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tImportRowsLog.import_id IS 'Parent run. FK to spw_imports(id) ON DELETE CASCADE - detail dies with the run.'",
        "COMMENT ON COLUMN $tImportRowsLog.row_number IS 'One-based row number in the source file.'",
        "COMMENT ON COLUMN $tImportRowsLog.raw_data IS 'The offending row as parsed, for diagnosis.'",
        "COMMENT ON COLUMN $tImportRowsLog.error_message IS 'Why the row was skipped.'",
        "COMMENT ON COLUMN $tImportRowsLog.logged_at IS 'Log time.'",

        // 8. RAG knowledge base

        "COMMENT ON TABLE $tRagFiles IS 'Knowledge-base documents for the AI assistant. Full-text indexed on content.'",
        "COMMENT ON COLUMN $tRagFiles.id IS 'Document id.'",
        "COMMENT ON COLUMN $tRagFiles.filename IS 'Document name shown in the admin RAG tab and cited in answers.'",
        "COMMENT ON COLUMN $tRagFiles.content IS 'Full plain-text content; GIN full-text indexed with to_tsvector(english, content).'",
        "COMMENT ON COLUMN $tRagFiles.tags IS 'Tags used to scope retrieval (GIN indexed).'",
        "COMMENT ON COLUMN $tRagFiles.file_size IS 'Original size in bytes.'",
        "COMMENT ON COLUMN $tRagFiles.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tRagFiles.created_at IS 'Upload time.'",

        "COMMENT ON TABLE $tRagChunks IS 'Retrieval units: documents split into chunks. This is what is actually retrieved and put into the prompt.'",
        "COMMENT ON COLUMN $tRagChunks.id IS 'Chunk id.'",
        "COMMENT ON COLUMN $tRagChunks.file_id IS 'Parent document. FK to spw_rag_files(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $tRagChunks.chunk_index IS 'Zero-based position within the document. UNIQUE together with file_id, so re-chunking cannot duplicate.'",
        "COMMENT ON COLUMN $tRagChunks.content IS 'Chunk text; GIN full-text indexed with to_tsvector(english, content).'",

        "COMMENT ON TABLE $tRagQueries IS 'Question log and usage metering for the AI assistant.'",
        "COMMENT ON COLUMN $tRagQueries.id IS 'Query id.'",
        "COMMENT ON COLUMN $tRagQueries.query IS 'The user question.'",
        "COMMENT ON COLUMN $tRagQueries.tags IS 'Tag filter applied to retrieval.'",
        "COMMENT ON COLUMN $tRagQueries.matched_files IS 'Number of documents that matched.'",
        "COMMENT ON COLUMN $tRagQueries.prompt_tokens IS 'Tokens sent to the model.'",
        "COMMENT ON COLUMN $tRagQueries.completion_tokens IS 'Tokens returned by the model.'",
        "COMMENT ON COLUMN $tRagQueries.total_ms IS 'End-to-end duration in milliseconds.'",
        "COMMENT ON COLUMN $tRagQueries.model IS 'Model identifier used.'",
        "COMMENT ON COLUMN $tRagQueries.user_id IS 'Asker. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $tRagQueries.created_at IS 'Ask time.'",
        "COMMENT ON COLUMN $tRagQueries.prompt_snapshot IS 'The fully assembled prompt, for debugging and reproducibility.'",

        "COMMENT ON TABLE $tRagQuerySources IS 'Citations produced for one RAG query, in relevance order.'",
        "COMMENT ON COLUMN $tRagQuerySources.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tRagQuerySources.query_id IS 'Parent query. FK to spw_rag_queries(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $tRagQuerySources.file_id IS 'Cited document. FK to spw_rag_files(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $tRagQuerySources.chunk_id IS 'Cited chunk. FK to spw_rag_chunks(id) ON DELETE SET NULL; NULL for whole-document citations or when the chunk was later deleted.'",
        "COMMENT ON COLUMN $tRagQuerySources.chunk_index IS 'Chunk position captured at query time; -1 means a document-level citation.'",
        "COMMENT ON COLUMN $tRagQuerySources.filename IS 'Document name captured at query time; survives renames and deletes.'",
        "COMMENT ON COLUMN $tRagQuerySources.snippet IS 'The excerpt actually shown to the user.'",
        "COMMENT ON COLUMN $tRagQuerySources.source_type IS 'file or chunk - which retrieval path produced the hit.'",
        "COMMENT ON COLUMN $tRagQuerySources.rank_position IS 'Zero-based relevance rank within this query results.'",

        // 9. Data anonymization (GDPR)

        "COMMENT ON TABLE $tAnonLog IS 'Run history of the anonymization worker (cron/cron_anonymization.php).'",
        "COMMENT ON COLUMN $tAnonLog.id IS 'Run id; loosely referenced by spw_anonymization_report.log_id.'",
        "COMMENT ON COLUMN $tAnonLog.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $tAnonLog.finished_at IS 'Run end.'",
        "COMMENT ON COLUMN $tAnonLog.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $tAnonLog.triggered_by IS 'cron, or a manual admin run.'",
        "COMMENT ON COLUMN $tAnonLog.rules_processed IS 'Anonymization rules evaluated.'",
        "COMMENT ON COLUMN $tAnonLog.rows_anonymized IS 'Rows whose columns were scrubbed.'",
        "COMMENT ON COLUMN $tAnonLog.error_message IS 'Failure detail.'",

        "COMMENT ON TABLE $tAnonReport IS 'Detailed per-run anonymization report, downloadable from the admin panel.'",
        "COMMENT ON COLUMN $tAnonReport.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tAnonReport.log_id IS 'Matching spw_anonymization_log.id. No foreign key - reports may be kept after log pruning.'",
        "COMMENT ON COLUMN $tAnonReport.report_id IS 'Stable external identifier used to fetch or download the report.'",
        "COMMENT ON COLUMN $tAnonReport.triggered_by IS 'Copied from the run for standalone readability.'",
        "COMMENT ON COLUMN $tAnonReport.status IS 'Copied from the run.'",
        "COMMENT ON COLUMN $tAnonReport.rows_affected IS 'Total rows scrubbed in this run.'",
        "COMMENT ON COLUMN $tAnonReport.report IS 'Full breakdown: per table/column/rule counts, dry-run flag, timings.'",
        "COMMENT ON COLUMN $tAnonReport.created_at IS 'Report creation time.'",

        // 10. ETL

        "COMMENT ON TABLE $tEtlLog IS 'Single-job ETL run history, written by cron/cron_etl.php; also the per-step target of flow runs.'",
        "COMMENT ON COLUMN $tEtlLog.id IS 'Run id.'",
        "COMMENT ON COLUMN $tEtlLog.job_id IS 'Job identifier from the ETL config.'",
        "COMMENT ON COLUMN $tEtlLog.job_name IS 'Job label captured at run time.'",
        "COMMENT ON COLUMN $tEtlLog.triggered_by IS 'cron, a manual admin run, or a flow.'",
        "COMMENT ON COLUMN $tEtlLog.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $tEtlLog.rows_read IS 'Rows read from the source (MySQL or PostgreSQL).'",
        "COMMENT ON COLUMN $tEtlLog.rows_written IS 'Rows written to the PostgreSQL target.'",
        "COMMENT ON COLUMN $tEtlLog.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $tEtlLog.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $tEtlLog.finished_at IS 'Run end.'",

        "COMMENT ON TABLE $tEtlFlowRunLog IS 'Run history of ETL flows - multi-step job chains (cron/cron_etl_flow.php).'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.id IS 'Flow run id; parent of spw_etl_flow_step_log.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.flow_id IS 'Flow identifier from the ETL config.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.flow_name IS 'Flow label captured at run time.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.triggered_by IS 'cron, or a manual admin run.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.failed_step_index IS 'Zero-based index of the step that aborted the flow; NULL on success.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.started_at IS 'Flow start.'",
        "COMMENT ON COLUMN $tEtlFlowRunLog.finished_at IS 'Flow end.'",

        "COMMENT ON TABLE $tEtlFlowStepLog IS 'Per-step detail of an ETL flow run; cascades with its parent run.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.flow_run_id IS 'Parent flow run. FK to spw_etl_flow_run_log(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.flow_id IS 'Denormalized flow id for direct querying.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.step_index IS 'Zero-based position of the step in the flow.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.job_id IS 'ETL job executed by this step.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.job_name IS 'Job label captured at run time.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.rows_read IS 'Rows read by the step.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.rows_written IS 'Rows written by the step.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.started_at IS 'Step start.'",
        "COMMENT ON COLUMN $tEtlFlowStepLog.finished_at IS 'Step end.'",
    ];
}

/**
 * Optional contact details on user accounts (admin panel only, informational).
 * All nullable, no UNIQUE: nothing authenticates on them.
 *
 * This IS the body of the 3.3_user_contact migration (includes/admin/migrations.php);
 * the setup wizard (public/setup_api.php) runs the same list so a fresh install is
 * created with the columns already in place. Idempotent — safe to re-run.
 *
 * @param callable(string): string $ident Short table name → quoted identifier.
 * @return string[]
 */
function system_tables_user_contact_ddl(callable $ident): array
{
    $tUsers = $ident('users');

    return [
        "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS first_name varchar(100)",
        "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS last_name varchar(100)",
        "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS email varchar(255)",
        "ALTER TABLE $tUsers ADD COLUMN IF NOT EXISTS phone varchar(32)",
        "COMMENT ON COLUMN $tUsers.first_name IS 'Optional given name, admin panel only. Informational - not used for login or notifications.'",
        "COMMENT ON COLUMN $tUsers.last_name IS 'Optional surname, admin panel only. Informational - not used for login or notifications.'",
        "COMMENT ON COLUMN $tUsers.email IS 'Optional contact email, admin panel only. Format-checked, not unique, not used for login or notifications.'",
        "COMMENT ON COLUMN $tUsers.phone IS 'Optional contact phone, admin panel only. Informational.'",
    ];
}

/**
 * Click statistics storage (Admin -> System -> Click Statistics). One row per
 * recorded click: who, when, which element, and optionally which record.
 * user_id is nullable + ON DELETE SET NULL so removing an account does not
 * delete the usage history it produced.
 *
 * This IS the body of the 3.3_clickstats migration (includes/admin/migrations.php);
 * the setup wizard (public/setup_api.php) runs the same list so a fresh install is
 * created with the table already in place. Nothing writes to it until the module is
 * enabled. Idempotent — safe to re-run.
 *
 * @param callable(string): string $ident Short table name → quoted identifier.
 * @return string[]
 */
function system_tables_clickstats_ddl(callable $ident): array
{
    $tClickstats = $ident('clickstats');
    $tUsers      = $ident('users');

    return [
        "CREATE TABLE IF NOT EXISTS $tClickstats (
            id bigserial NOT NULL,
            user_id int4 NULL,
            element varchar(120) NOT NULL,
            page varchar(120) NULL,
            table_name varchar(100) NULL,
            record_id int4 NULL,
            created_at timestamp DEFAULT now() NOT NULL,
            CONSTRAINT spw_clickstats_pkey PRIMARY KEY (id),
            CONSTRAINT spw_clickstats_user_fk FOREIGN KEY (user_id)
                REFERENCES $tUsers(id) ON DELETE SET NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_spw_clickstats_created ON $tClickstats (created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_spw_clickstats_element ON $tClickstats (element)",
        "COMMENT ON TABLE $tClickstats IS 'Click statistics. Written only while Admin -> Click Statistics is enabled; one row per UI element click.'",
        "COMMENT ON COLUMN $tClickstats.element IS 'Element label: the data-stat attribute when present, otherwise a derived id/class/text label.'",
        "COMMENT ON COLUMN $tClickstats.page IS 'Page script the click happened on, e.g. index.php.'",
        "COMMENT ON COLUMN $tClickstats.table_name IS 'Table in context when the click happened, or NULL.'",
        "COMMENT ON COLUMN $tClickstats.record_id IS 'Record in context when the click happened, or NULL.'",
    ];
}
