<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function system_tables_ddl(callable $ident): array
{
    $usersTable            = $ident('users');
    $usersLogTable         = $ident('users_log');
    $loginAttemptsTable    = $ident('login_attempts');
    $notificationsTable    = $ident('users_notifications');
    $cronLogTable          = $ident('users_notifications_log');
    $filesTable            = $ident('files');
    $commentsTable         = $ident('comments');
    $notesTable            = $ident('notes');
    $recordSnapshotsTable  = $ident('record_snapshots');
    $recordOwnersTable     = $ident('record_owners');
    $releaseMigrationsTable    = $ident('release_migrations');
    $importsTable          = $ident('imports');
    $importRowsLogTable    = $ident('import_rows_log');
    $ragFilesTable         = $ident('rag_files');
    $ragChunksTable        = $ident('rag_chunks');
    $ragQueriesTable       = $ident('rag_queries');
    $ragQuerySourcesTable  = $ident('rag_query_sources');
    $automationRunsTable   = $ident('automation_runs');
    $automationEmailsTable = $ident('automation_emails');
    $anonymizationLogTable          = $ident('anonymization_log');
    $anonymizationReportTable       = $ident('anonymization_report');
    $configTable           = $ident('config');
    $configLogTable        = $ident('config_log');
    $etlLogTable           = $ident('etl_log');
    $etlFlowRunLogTable    = $ident('etl_flow_run_log');
    $etlFlowStepLogTable   = $ident('etl_flow_step_log');

    return [

        "CREATE TABLE IF NOT EXISTS $usersTable ( id serial4 NOT NULL, username varchar(50) NOT NULL, password_hash varchar(255) NOT NULL, salt varchar(64), password_algo varchar(32) DEFAULT 'argon2id' NOT NULL, password_params jsonb DEFAULT '{}'::jsonb, is_active bool DEFAULT true, role varchar(20) DEFAULT 'editor' NOT NULL, avatar_id smallint, CONSTRAINT spw_users_pkey PRIMARY KEY (id), CONSTRAINT spw_users_username_key UNIQUE (username) )",

        "CREATE TABLE IF NOT EXISTS $usersLogTable ( id serial4 NOT NULL, user_id int4 NOT NULL, action varchar(50) NOT NULL, target_table varchar(100), record_id int4, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_log_pkey PRIMARY KEY (id) )",

        "CREATE TABLE IF NOT EXISTS $loginAttemptsTable ( id serial4 NOT NULL, username varchar(50) NOT NULL, ip_hash varchar(64) NOT NULL, attempted_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_login_attempts_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_username ON $loginAttemptsTable USING btree (username, attempted_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_login_attempts_ip ON $loginAttemptsTable USING btree (ip_hash, attempted_at)",

        "CREATE TABLE IF NOT EXISTS $notificationsTable ( id serial4 NOT NULL, user_id int8 NOT NULL, title varchar(255) NOT NULL, link varchar(255), source_table varchar(100), source_id int8, is_read bool DEFAULT false, notify_date date NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_users_notifications_pkey PRIMARY KEY (id), CONSTRAINT spw_users_notifications_user_id_source_table_source_id_notify_d_key UNIQUE (user_id, source_table, source_id, notify_date) )",
        "CREATE TABLE IF NOT EXISTS $cronLogTable ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', sources_processed int4 NULL, notifications_created int4 NULL, error_message text NULL, CONSTRAINT spw_users_notifications_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_cron_log_started_at ON $cronLogTable USING btree (started_at)",

        "CREATE TABLE IF NOT EXISTS $filesTable ( id serial4 NOT NULL, uuid uuid DEFAULT gen_random_uuid() NOT NULL, name varchar(255) NOT NULL, display_name varchar(255) NULL, type varchar(50) NOT NULL, mime_type varchar(100) NOT NULL, extension varchar(20) NOT NULL, size_bytes int8 DEFAULT 0 NOT NULL, storage_path text NOT NULL, related_table varchar(100) NULL, related_id int4 NULL, related_field varchar(100) NULL, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, description text NULL, tags _text NULL, metadata jsonb NULL, CONSTRAINT spw_files_pkey PRIMARY KEY (id), CONSTRAINT spw_files_uuid_key UNIQUE (uuid), CONSTRAINT spw_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_deleted_at ON $filesTable USING btree (deleted_at) WHERE (deleted_at IS NULL)",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_metadata ON $filesTable USING gin (metadata)",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_related ON $filesTable USING btree (related_table, related_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_tags ON $filesTable USING gin (tags)",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_type ON $filesTable USING btree (type)",
        "CREATE INDEX IF NOT EXISTS idx_spw_files_uploaded_by ON $filesTable USING btree (uploaded_by)",

        "CREATE TABLE IF NOT EXISTS $commentsTable ( id serial4 NOT NULL, related_table varchar(100) NOT NULL, related_id int4 NOT NULL, user_id int4 NOT NULL, body text NOT NULL, created_at timestamp DEFAULT now() NOT NULL, deleted_at timestamp NULL, CONSTRAINT spw_comments_pkey PRIMARY KEY (id), CONSTRAINT spw_comments_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_comments_user_id_fkey FOREIGN KEY (user_id) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_comments_related ON $commentsTable USING btree (related_table, related_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_comments_user_id ON $commentsTable USING btree (user_id)",

        "CREATE TABLE IF NOT EXISTS $notesTable ( id serial4 NOT NULL, user_id int4 NOT NULL, related_table varchar(100) NULL, related_id int4 NULL, body text NOT NULL, reminder_date date NULL, created_at timestamp DEFAULT now() NOT NULL, updated_at timestamp NULL, deleted_at timestamp NULL, CONSTRAINT spw_notes_pkey PRIMARY KEY (id), CONSTRAINT spw_notes_body_len CHECK (char_length(body) <= 4000), CONSTRAINT spw_notes_user_id_fkey FOREIGN KEY (user_id) REFERENCES $usersTable(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_notes_user_id ON $notesTable USING btree (user_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_notes_reminder ON $notesTable USING btree (reminder_date) WHERE (reminder_date IS NOT NULL)",

        "CREATE TABLE IF NOT EXISTS $recordSnapshotsTable ( id serial4 NOT NULL, log_id int4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, snapshot jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP, CONSTRAINT spw_record_snapshots_pkey PRIMARY KEY (id), CONSTRAINT spw_record_snapshots_log_id_fkey FOREIGN KEY (log_id) REFERENCES $usersLogTable(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_log_id ON $recordSnapshotsTable USING btree (log_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_record_snapshots_table_record ON $recordSnapshotsTable USING btree (table_name, record_id)",

        "CREATE TABLE IF NOT EXISTS $recordOwnersTable ( id serial4 NOT NULL, table_name varchar(100) NOT NULL, record_id int4 NOT NULL, owner_id int4 NULL, changed_by int4 NULL, changed_at timestamp DEFAULT now() NOT NULL, is_current bool NOT NULL DEFAULT false, CONSTRAINT spw_record_owners_pkey PRIMARY KEY (id), CONSTRAINT spw_record_owners_owner_fkey FOREIGN KEY (owner_id) REFERENCES $usersTable(id) ON DELETE SET NULL, CONSTRAINT spw_record_owners_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_record_owners_current ON $recordOwnersTable USING btree (table_name, record_id, is_current)",

        "CREATE TABLE IF NOT EXISTS $importsTable ( id serial4 NOT NULL, user_id int4 NULL, filename varchar(255) NOT NULL, target_table varchar(100) NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', total_rows int4 NOT NULL DEFAULT 0, imported_rows int4 NOT NULL DEFAULT 0, skipped_rows int4 NOT NULL DEFAULT 0, column_mapping jsonb NULL, conflict_column varchar(100) NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_imports_pkey PRIMARY KEY (id), CONSTRAINT spw_imports_user_fkey FOREIGN KEY (user_id) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_imports_started_at ON $importsTable USING btree (started_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_imports_user_id ON $importsTable USING btree (user_id)",

        "CREATE TABLE IF NOT EXISTS $importRowsLogTable ( id bigserial NOT NULL, import_id int4 NOT NULL, row_number int4 NOT NULL, raw_data jsonb NULL, error_message text NOT NULL, logged_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_import_rows_log_pkey PRIMARY KEY (id), CONSTRAINT spw_import_rows_log_import_fkey FOREIGN KEY (import_id) REFERENCES $importsTable(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_import_rows_log_import_id ON $importRowsLogTable USING btree (import_id)",

        "CREATE TABLE IF NOT EXISTS $releaseMigrationsTable ( id serial4 NOT NULL, version varchar(20) NOT NULL, applied_at timestamp NOT NULL DEFAULT now(), applied_by int4 REFERENCES $usersTable(id) ON DELETE SET NULL, actions jsonb NOT NULL DEFAULT '[]', CONSTRAINT spw_release_migrations_pkey PRIMARY KEY (id), CONSTRAINT spw_release_migrations_version_key UNIQUE (version) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_release_migrations_version ON $releaseMigrationsTable USING btree (version)",

        "CREATE TABLE IF NOT EXISTS $ragFilesTable ( id serial4 NOT NULL, filename varchar(255) NOT NULL, content text NOT NULL, tags text[] NOT NULL DEFAULT '{}', file_size int4 NOT NULL DEFAULT 0, uploaded_by int4 NULL, created_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_rag_files_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_files_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_tags ON $ragFilesTable USING gin (tags)",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_files_content_fts ON $ragFilesTable USING gin (to_tsvector('english', content))",

        "CREATE TABLE IF NOT EXISTS $ragQueriesTable ( id serial4 NOT NULL, query text NOT NULL, tags text[] NOT NULL DEFAULT '{}', matched_files int4 NOT NULL DEFAULT 0, prompt_tokens int4 NOT NULL DEFAULT 0, completion_tokens int4 NOT NULL DEFAULT 0, total_ms int4 NOT NULL DEFAULT 0, model varchar(255) NOT NULL DEFAULT '', user_id int4 NULL, created_at timestamp NOT NULL DEFAULT now(), prompt_snapshot text, CONSTRAINT spw_rag_queries_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_queries_user_fkey FOREIGN KEY (user_id) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_created_at ON $ragQueriesTable USING btree (created_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_queries_user_id ON $ragQueriesTable USING btree (user_id)",

        "CREATE TABLE IF NOT EXISTS $automationRunsTable ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', rule_name varchar(255) NOT NULL DEFAULT '', table_name varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, event varchar(20) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'ok', error_msg text NULL, executed_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_automation_runs_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_rule_id ON $automationRunsTable USING btree (rule_id, executed_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_spw_automation_runs_executed_at ON $automationRunsTable USING btree (executed_at DESC)",

        "CREATE TABLE IF NOT EXISTS $ragChunksTable ( id serial4 NOT NULL, file_id int4 NOT NULL, chunk_index int4 NOT NULL, content text NOT NULL, CONSTRAINT spw_rag_chunks_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_chunks_file_fkey FOREIGN KEY (file_id) REFERENCES $ragFilesTable(id) ON DELETE CASCADE, CONSTRAINT spw_rag_chunks_file_chunk_key UNIQUE (file_id, chunk_index) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_file_id ON $ragChunksTable USING btree (file_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_chunks_content_fts ON $ragChunksTable USING gin (to_tsvector('english', content))",

        "CREATE TABLE IF NOT EXISTS $ragQuerySourcesTable ( id serial4 NOT NULL, query_id int4 NOT NULL, file_id int4 NOT NULL, chunk_id int4 NULL, chunk_index int4 NOT NULL DEFAULT -1, filename varchar(255) NOT NULL, snippet text NOT NULL DEFAULT '', source_type varchar(10) NOT NULL DEFAULT 'file', rank_position int4 NOT NULL DEFAULT 0, CONSTRAINT spw_rag_query_sources_pkey PRIMARY KEY (id), CONSTRAINT spw_rag_query_sources_query_fkey FOREIGN KEY (query_id) REFERENCES $ragQueriesTable(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_file_fkey FOREIGN KEY (file_id) REFERENCES $ragFilesTable(id) ON DELETE CASCADE, CONSTRAINT spw_rag_query_sources_chunk_fkey FOREIGN KEY (chunk_id) REFERENCES $ragChunksTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_query_id ON $ragQuerySourcesTable USING btree (query_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_rag_query_sources_file_id ON $ragQuerySourcesTable USING btree (file_id)",

        "CREATE TABLE IF NOT EXISTS $anonymizationLogTable ( id serial4 NOT NULL, started_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, finished_at timestamp NULL, status varchar(20) NOT NULL DEFAULT 'running', triggered_by varchar(20) NOT NULL DEFAULT 'cron', rules_processed int4 NULL, rows_anonymized int4 NULL, error_message text NULL, CONSTRAINT spw_anonymization_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_log_started_at ON $anonymizationLogTable USING btree (started_at DESC)",

        "CREATE TABLE IF NOT EXISTS $anonymizationReportTable ( id serial4 NOT NULL, log_id int4 NULL, report_id varchar(64) NOT NULL, triggered_by varchar(20) NULL, status varchar(20) NULL, rows_affected int4 NULL, report jsonb NOT NULL, created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL, CONSTRAINT spw_anonymization_report_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_log_id ON $anonymizationReportTable USING btree (log_id)",
        "CREATE INDEX IF NOT EXISTS idx_spw_anonymization_report_created_at ON $anonymizationReportTable USING btree (created_at DESC)",

        "CREATE TABLE IF NOT EXISTS $automationEmailsTable ( id serial4 NOT NULL, rule_id varchar(50) NOT NULL DEFAULT '', recipient varchar(255) NOT NULL, subject varchar(255) NOT NULL, body text NOT NULL DEFAULT '', source_table varchar(100) NOT NULL DEFAULT '', record_id int4 NOT NULL DEFAULT 0, created_by int4 NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'pending', attempts int4 NOT NULL DEFAULT 0, error_msg text NULL, created_at timestamp DEFAULT now() NOT NULL, sent_at timestamp NULL, CONSTRAINT spw_automation_emails_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_status ON $automationEmailsTable USING btree (status, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_spw_automation_emails_rule_id ON $automationEmailsTable USING btree (rule_id, created_at DESC)",

        "CREATE TABLE IF NOT EXISTS $configTable ( config_key varchar(64) NOT NULL, value jsonb NOT NULL, version int4 DEFAULT 1 NOT NULL, updated_by int4 NULL, updated_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_config_pkey PRIMARY KEY (config_key), CONSTRAINT spw_config_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES $usersTable(id) ON DELETE SET NULL )",

        "CREATE TABLE IF NOT EXISTS $configLogTable ( id bigserial NOT NULL, config_key varchar(64) NOT NULL, old_value jsonb NULL, new_value jsonb NULL, changed_by int4 NULL, changed_at timestamp DEFAULT now() NOT NULL, CONSTRAINT spw_config_log_pkey PRIMARY KEY (id), CONSTRAINT spw_config_log_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES $usersTable(id) ON DELETE SET NULL )",
        "CREATE INDEX IF NOT EXISTS idx_spw_config_log_key ON $configLogTable USING btree (config_key, changed_at DESC)",

        "CREATE TABLE IF NOT EXISTS $etlLogTable ( id serial4 NOT NULL, job_id varchar(64) NOT NULL DEFAULT '', job_name varchar(255) NOT NULL DEFAULT '', triggered_by varchar(20) NOT NULL DEFAULT 'cron', status varchar(20) NOT NULL DEFAULT 'running', rows_read int4 NULL, rows_written int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_log_started_at ON $etlLogTable USING btree (started_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_log_job ON $etlLogTable USING btree (job_id, triggered_by, status, started_at)",

        "CREATE TABLE IF NOT EXISTS $etlFlowRunLogTable ( id serial4 NOT NULL, flow_id varchar(64) NOT NULL DEFAULT '', flow_name varchar(255) NOT NULL DEFAULT '', triggered_by varchar(20) NOT NULL DEFAULT 'cron', status varchar(20) NOT NULL DEFAULT 'running', failed_step_index int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_flow_run_log_pkey PRIMARY KEY (id) )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_flow_run_log_flow ON $etlFlowRunLogTable USING btree (flow_id, status, started_at)",

        "CREATE TABLE IF NOT EXISTS $etlFlowStepLogTable ( id serial4 NOT NULL, flow_run_id int4 NULL, flow_id varchar(64) NOT NULL DEFAULT '', step_index int4 NOT NULL DEFAULT 0, job_id varchar(64) NOT NULL DEFAULT '', job_name varchar(255) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'running', rows_read int4 NULL, rows_written int4 NULL, error_message text NULL, started_at timestamp DEFAULT now() NOT NULL, finished_at timestamp NULL, CONSTRAINT spw_etl_flow_step_log_pkey PRIMARY KEY (id), CONSTRAINT spw_etl_flow_step_log_run_fkey FOREIGN KEY (flow_run_id) REFERENCES $etlFlowRunLogTable(id) ON DELETE CASCADE )",
        "CREATE INDEX IF NOT EXISTS idx_spw_etl_flow_step_log_run_id ON $etlFlowStepLogTable USING btree (flow_run_id)",
    ];
}

function system_tables_comments_ddl(callable $ident): array
{
    $migrationsTable        = $ident('migrations');
    $usersTable             = $ident('users');
    $usersLogTable          = $ident('users_log');
    $loginAttemptsTable     = $ident('login_attempts');
    $recordSnapshotsTable   = $ident('record_snapshots');
    $recordOwnersTable      = $ident('record_owners');
    $configTable            = $ident('config');
    $configLogTable         = $ident('config_log');
    $releaseMigrationsTable     = $ident('release_migrations');
    $filesTable             = $ident('files');
    $commentsTable          = $ident('comments');
    $notesTable             = $ident('notes');
    $notificationsTable     = $ident('users_notifications');
    $cronLogTable           = $ident('users_notifications_log');
    $automationRunsTable    = $ident('automation_runs');
    $automationEmailsTable  = $ident('automation_emails');
    $importsTable           = $ident('imports');
    $importRowsLogTable     = $ident('import_rows_log');
    $ragFilesTable          = $ident('rag_files');
    $ragChunksTable         = $ident('rag_chunks');
    $ragQueriesTable        = $ident('rag_queries');
    $ragQuerySourcesTable   = $ident('rag_query_sources');
    $anonymizationLogTable           = $ident('anonymization_log');
    $anonymizationReportTable        = $ident('anonymization_report');
    $etlLogTable            = $ident('etl_log');
    $etlFlowRunLogTable     = $ident('etl_flow_run_log');
    $etlFlowStepLogTable    = $ident('etl_flow_step_log');

    return [

        "COMMENT ON TABLE $migrationsTable IS 'Tracker of applied database migrations. Created by the init_db bootstrap block, not by system_tables_ddl(), because it must exist before the migration registry can be consulted.'",
        "COMMENT ON COLUMN $migrationsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $migrationsTable.name IS 'Migration key, e.g. 3.0_baseline. Presence of the row means already applied - init_db skips it on re-run. UNIQUE.'",
        "COMMENT ON COLUMN $migrationsTable.applied_at IS 'When the migration ran.'",

        "COMMENT ON TABLE $usersTable IS 'Application accounts. Referenced by most other system tables.'",
        "COMMENT ON COLUMN $usersTable.id IS 'User id; referenced by most other system tables.'",
        "COMMENT ON COLUMN $usersTable.username IS 'Login name. UNIQUE.'",
        "COMMENT ON COLUMN $usersTable.password_hash IS 'Hash produced by PHP password_hash() (Argon2id by default). Never a plaintext or reversible value.'",
        "COMMENT ON COLUMN $usersTable.salt IS 'Legacy/optional extra salt. Modern Argon2id hashes carry their salt inside password_hash.'",
        "COMMENT ON COLUMN $usersTable.password_algo IS 'Algorithm the hash was produced with; drives rehash-on-login decisions. Default argon2id.'",
        "COMMENT ON COLUMN $usersTable.password_params IS 'Cost parameters used for the hash (memory/time/threads), for rehash comparison.'",
        "COMMENT ON COLUMN $usersTable.is_active IS 'Soft disable - inactive users cannot log in but their audit rows survive.'",
        "COMMENT ON COLUMN $usersTable.role IS 'Authorization level (admin / editor / read-only roles). Enforced server-side in api_bootstrap.php and requireWrite().'",
        "COMMENT ON COLUMN $usersTable.avatar_id IS 'Avatar colour: 1-based index into the avatar palette (OS_AVATAR_COLORS). NULL = default colour. The avatar itself is the initial of the username.'",

        "COMMENT ON TABLE $usersLogTable IS 'User action audit trail. Written by log_user_action() (includes/api_helpers.php); every mutation must produce a row.'",
        "COMMENT ON COLUMN $usersLogTable.id IS 'Log entry id; referenced by spw_record_snapshots.log_id.'",
        "COMMENT ON COLUMN $usersLogTable.user_id IS 'Acting user (spw_users.id). No foreign key, so history survives user deletion.'",
        "COMMENT ON COLUMN $usersLogTable.action IS 'Action name, e.g. insert, update, delete, login.'",
        "COMMENT ON COLUMN $usersLogTable.target_table IS 'Table the action touched.'",
        "COMMENT ON COLUMN $usersLogTable.record_id IS 'Affected record primary key.'",
        "COMMENT ON COLUMN $usersLogTable.created_at IS 'When the action happened.'",

        "COMMENT ON TABLE $loginAttemptsTable IS 'Login attempt journal backing the rate limiter. Pruned by cron/cron_notifications.php.'",
        "COMMENT ON COLUMN $loginAttemptsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $loginAttemptsTable.username IS 'Username the attempt was made for, recorded even if it does not exist.'",
        "COMMENT ON COLUMN $loginAttemptsTable.ip_hash IS 'Salted hash of the client IP (IP_HASH_SALT). The raw IP is never stored.'",
        "COMMENT ON COLUMN $loginAttemptsTable.attempted_at IS 'Attempt timestamp; the sliding window for the throttle.'",

        "COMMENT ON TABLE $recordSnapshotsTable IS 'Full JSON row snapshots taken at the moment of a change. Retention configurable in Admin - Settings.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.log_id IS 'Audit entry this snapshot belongs to. FK to spw_users_log(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.table_name IS 'Snapshotted table.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.record_id IS 'Snapshotted record.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.snapshot IS 'Full row content as JSON at the moment of the change.'",
        "COMMENT ON COLUMN $recordSnapshotsTable.created_at IS 'Snapshot time.'",

        "COMMENT ON TABLE $recordOwnersTable IS 'Record ownership history. The row flagged is_current drives owner_restriction_sql() row filtering.'",
        "COMMENT ON COLUMN $recordOwnersTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $recordOwnersTable.table_name IS 'Owned table.'",
        "COMMENT ON COLUMN $recordOwnersTable.record_id IS 'Owned record.'",
        "COMMENT ON COLUMN $recordOwnersTable.owner_id IS 'Current or former owner. FK to spw_users(id) ON DELETE SET NULL; NULL when the user is deleted or ownership is cleared.'",
        "COMMENT ON COLUMN $recordOwnersTable.changed_by IS 'Who performed the ownership change. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $recordOwnersTable.changed_at IS 'When ownership changed.'",
        "COMMENT ON COLUMN $recordOwnersTable.is_current IS 'Marks the one active row per record; older rows stay as history.'",

        "COMMENT ON TABLE $configTable IS 'DB-backed application configuration: one JSONB row per key (schema, menu, settings, dashboard, calendar, board, workflows, automations, views, files, print, anonymization, user_records, rag, ...). Accessed only through includes/config_store.php.'",
        "COMMENT ON COLUMN $configTable.config_key IS 'Configuration key name (what used to be a config/*.json filename, without the extension). Primary key.'",
        "COMMENT ON COLUMN $configTable.value IS 'The whole configuration document for that key.'",
        "COMMENT ON COLUMN $configTable.version IS 'Optimistic-lock counter; a save with a stale version is rejected instead of overwriting a concurrent edit.'",
        "COMMENT ON COLUMN $configTable.updated_by IS 'Last editor. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $configTable.updated_at IS 'Last save time.'",

        "COMMENT ON TABLE $configLogTable IS 'Audit trail of configuration changes, with old/new document snapshots.'",
        "COMMENT ON COLUMN $configLogTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $configLogTable.config_key IS 'Configuration key that changed.'",
        "COMMENT ON COLUMN $configLogTable.old_value IS 'Document before the change; NULL on first insert.'",
        "COMMENT ON COLUMN $configLogTable.new_value IS 'Document after the change; NULL on delete.'",
        "COMMENT ON COLUMN $configLogTable.changed_by IS 'Editor. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $configLogTable.changed_at IS 'Change time.'",

        "COMMENT ON TABLE $releaseMigrationsTable IS 'Applied release migrations - the file and config-key cleanups from config/migrations.json, applied via public/admin/api_migrations.php. Distinct from spw_migrations, which tracks database DDL.'",
        "COMMENT ON COLUMN $releaseMigrationsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $releaseMigrationsTable.version IS 'Release version processed, e.g. 3.1. UNIQUE.'",
        "COMMENT ON COLUMN $releaseMigrationsTable.applied_at IS 'When the release migration was applied.'",
        "COMMENT ON COLUMN $releaseMigrationsTable.applied_by IS 'Admin who ran it. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $releaseMigrationsTable.actions IS 'Log of what was actually done: files deleted, config keys stripped, skips.'",

        "COMMENT ON TABLE $filesTable IS 'Uploaded files, record attachments and record image galleries. Served only through the files.php / api/files.php / file_download.php proxies, never by direct path.'",
        "COMMENT ON COLUMN $filesTable.id IS 'Internal id. Never exposed in URLs.'",
        "COMMENT ON COLUMN $filesTable.uuid IS 'Public handle used by files.php, api/files.php and file_download.php. UNIQUE, defaults to gen_random_uuid().'",
        "COMMENT ON COLUMN $filesTable.name IS 'Server-generated storage filename - never the client-supplied name.'",
        "COMMENT ON COLUMN $filesTable.display_name IS 'Human-facing label shown in the UI.'",
        "COMMENT ON COLUMN $filesTable.type IS 'Coarse category (image, document, ...) used for filtering and icons.'",
        "COMMENT ON COLUMN $filesTable.mime_type IS 'MIME type detected from file content with finfo, not from the upload header.'",
        "COMMENT ON COLUMN $filesTable.extension IS 'Whitelisted file extension.'",
        "COMMENT ON COLUMN $filesTable.size_bytes IS 'File size in bytes.'",
        "COMMENT ON COLUMN $filesTable.storage_path IS 'Path under storage/files/, outside the document root and outside any execution path.'",
        "COMMENT ON COLUMN $filesTable.related_table IS 'Table the file is attached to; NULL for standalone library files.'",
        "COMMENT ON COLUMN $filesTable.related_id IS 'Record the file is attached to.'",
        "COMMENT ON COLUMN $filesTable.related_field IS 'Field the attachment belongs to. The sentinel __image (IMAGES_FIELD, includes/images.php) marks a record gallery image (feature added in 3.1, no schema change).'",
        "COMMENT ON COLUMN $filesTable.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $filesTable.created_at IS 'Upload time. Gallery ordering uses id, which follows this.'",
        "COMMENT ON COLUMN $filesTable.updated_at IS 'Last metadata change.'",
        "COMMENT ON COLUMN $filesTable.deleted_at IS 'Soft delete marker - all read paths filter deleted_at IS NULL.'",
        "COMMENT ON COLUMN $filesTable.description IS 'Free-text description.'",
        "COMMENT ON COLUMN $filesTable.tags IS 'Tag array used for filtering (GIN indexed).'",
        "COMMENT ON COLUMN $filesTable.metadata IS 'Extra attributes: image dimensions, thumbnail info, and similar (GIN indexed).'",

        "COMMENT ON TABLE $commentsTable IS 'Per-record discussion threads, visible to all users who can see the record.'",
        "COMMENT ON COLUMN $commentsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $commentsTable.related_table IS 'Commented table.'",
        "COMMENT ON COLUMN $commentsTable.related_id IS 'Commented record.'",
        "COMMENT ON COLUMN $commentsTable.user_id IS 'Author. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $commentsTable.body IS 'Comment text. CHECK char_length(body) <= 4000.'",
        "COMMENT ON COLUMN $commentsTable.created_at IS 'Post time.'",
        "COMMENT ON COLUMN $commentsTable.deleted_at IS 'Soft delete marker.'",

        "COMMENT ON TABLE $notesTable IS 'Private user notepad. Unlike comments, notes are visible only to their author; they may be free-floating or attached to a record, and may carry a reminder date.'",
        "COMMENT ON COLUMN $notesTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $notesTable.user_id IS 'Owner. FK to spw_users(id) ON DELETE CASCADE - notes die with the account.'",
        "COMMENT ON COLUMN $notesTable.related_table IS 'Optional linked table.'",
        "COMMENT ON COLUMN $notesTable.related_id IS 'Optional linked record.'",
        "COMMENT ON COLUMN $notesTable.body IS 'Note text. CHECK char_length(body) <= 4000.'",
        "COMMENT ON COLUMN $notesTable.reminder_date IS 'If set, cron/cron_notifications.php raises a notification once that date and time has passed.'",
        "COMMENT ON COLUMN $notesTable.created_at IS 'Creation time.'",
        "COMMENT ON COLUMN $notesTable.updated_at IS 'Last edit; NULL if never edited.'",
        "COMMENT ON COLUMN $notesTable.deleted_at IS 'Soft delete marker.'",

        "COMMENT ON TABLE $notificationsTable IS 'Per-user notification inbox. UNIQUE (user_id, source_table, source_id, notify_date) is the deduplication key that makes the cron worker idempotent when it re-runs on the same day.'",
        "COMMENT ON COLUMN $notificationsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $notificationsTable.user_id IS 'Recipient (spw_users.id).'",
        "COMMENT ON COLUMN $notificationsTable.title IS 'Notification headline.'",
        "COMMENT ON COLUMN $notificationsTable.link IS 'Target URL to open on click.'",
        "COMMENT ON COLUMN $notificationsTable.source_table IS 'Table that triggered the notification.'",
        "COMMENT ON COLUMN $notificationsTable.source_id IS 'Record that triggered the notification.'",
        "COMMENT ON COLUMN $notificationsTable.is_read IS 'Read flag, toggled from api/notifications.php.'",
        "COMMENT ON COLUMN $notificationsTable.notify_date IS 'Date the notification is for; part of the deduplication key.'",
        "COMMENT ON COLUMN $notificationsTable.created_at IS 'Generation time.'",

        "COMMENT ON TABLE $cronLogTable IS 'Run history of the notification cron worker (cron/cron_notifications.php).'",
        "COMMENT ON COLUMN $cronLogTable.id IS 'Run id.'",
        "COMMENT ON COLUMN $cronLogTable.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $cronLogTable.finished_at IS 'Run end; NULL while running or after a crash.'",
        "COMMENT ON COLUMN $cronLogTable.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $cronLogTable.triggered_by IS 'cron, or a manual admin trigger.'",
        "COMMENT ON COLUMN $cronLogTable.sources_processed IS 'Number of configured notification sources scanned.'",
        "COMMENT ON COLUMN $cronLogTable.notifications_created IS 'Rows actually inserted into spw_users_notifications.'",
        "COMMENT ON COLUMN $cronLogTable.error_message IS 'Failure detail when status = error.'",

        "COMMENT ON TABLE $automationRunsTable IS 'Execution log of automation rules (includes/automations.php), one row per rule firing.'",
        "COMMENT ON COLUMN $automationRunsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $automationRunsTable.rule_id IS 'Rule identifier from the automations config key.'",
        "COMMENT ON COLUMN $automationRunsTable.rule_name IS 'Rule label captured at run time; survives later renames.'",
        "COMMENT ON COLUMN $automationRunsTable.table_name IS 'Table whose change fired the rule.'",
        "COMMENT ON COLUMN $automationRunsTable.record_id IS 'Triggering record.'",
        "COMMENT ON COLUMN $automationRunsTable.event IS 'Trigger event: insert / update / delete.'",
        "COMMENT ON COLUMN $automationRunsTable.status IS 'ok or error.'",
        "COMMENT ON COLUMN $automationRunsTable.error_msg IS 'Failure detail.'",
        "COMMENT ON COLUMN $automationRunsTable.executed_at IS 'Execution time.'",

        "COMMENT ON TABLE $automationEmailsTable IS 'Outbound e-mail queue. Rows are enqueued by the automation engine and delivered by cron/cron_notifications.php.'",
        "COMMENT ON COLUMN $automationEmailsTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $automationEmailsTable.rule_id IS 'Automation rule that queued the message.'",
        "COMMENT ON COLUMN $automationEmailsTable.recipient IS 'Destination e-mail address.'",
        "COMMENT ON COLUMN $automationEmailsTable.subject IS 'Rendered subject line.'",
        "COMMENT ON COLUMN $automationEmailsTable.body IS 'Rendered message body.'",
        "COMMENT ON COLUMN $automationEmailsTable.source_table IS 'Table of the triggering record.'",
        "COMMENT ON COLUMN $automationEmailsTable.record_id IS 'Triggering record.'",
        "COMMENT ON COLUMN $automationEmailsTable.created_by IS 'User whose action queued the message; 0 for system/cron.'",
        "COMMENT ON COLUMN $automationEmailsTable.status IS 'pending / sent / error.'",
        "COMMENT ON COLUMN $automationEmailsTable.attempts IS 'Delivery attempts, for retry back-off.'",
        "COMMENT ON COLUMN $automationEmailsTable.error_msg IS 'Last delivery error.'",
        "COMMENT ON COLUMN $automationEmailsTable.created_at IS 'Enqueue time.'",
        "COMMENT ON COLUMN $automationEmailsTable.sent_at IS 'Successful delivery time.'",

        "COMMENT ON TABLE $importsTable IS 'One row per CSV import run (public/admin/api_csv_import.php).'",
        "COMMENT ON COLUMN $importsTable.id IS 'Import run id.'",
        "COMMENT ON COLUMN $importsTable.user_id IS 'Who ran the import. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $importsTable.filename IS 'Uploaded file name as shown to the user.'",
        "COMMENT ON COLUMN $importsTable.target_table IS 'Destination table.'",
        "COMMENT ON COLUMN $importsTable.status IS 'pending / running / done / error.'",
        "COMMENT ON COLUMN $importsTable.total_rows IS 'Data rows found in the file.'",
        "COMMENT ON COLUMN $importsTable.imported_rows IS 'Rows inserted or updated.'",
        "COMMENT ON COLUMN $importsTable.skipped_rows IS 'Rows rejected; each one detailed in spw_import_rows_log.'",
        "COMMENT ON COLUMN $importsTable.column_mapping IS 'CSV column to table column map used for this run.'",
        "COMMENT ON COLUMN $importsTable.conflict_column IS 'Column used for upsert matching; NULL for insert-only runs.'",
        "COMMENT ON COLUMN $importsTable.error_message IS 'Run-level failure detail.'",
        "COMMENT ON COLUMN $importsTable.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $importsTable.finished_at IS 'Run end.'",

        "COMMENT ON TABLE $importRowsLogTable IS 'Per-row rejection detail for a CSV import run.'",
        "COMMENT ON COLUMN $importRowsLogTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $importRowsLogTable.import_id IS 'Parent run. FK to spw_imports(id) ON DELETE CASCADE - detail dies with the run.'",
        "COMMENT ON COLUMN $importRowsLogTable.row_number IS 'One-based row number in the source file.'",
        "COMMENT ON COLUMN $importRowsLogTable.raw_data IS 'The offending row as parsed, for diagnosis.'",
        "COMMENT ON COLUMN $importRowsLogTable.error_message IS 'Why the row was skipped.'",
        "COMMENT ON COLUMN $importRowsLogTable.logged_at IS 'Log time.'",

        "COMMENT ON TABLE $ragFilesTable IS 'Knowledge-base documents for the AI assistant. Full-text indexed on content.'",
        "COMMENT ON COLUMN $ragFilesTable.id IS 'Document id.'",
        "COMMENT ON COLUMN $ragFilesTable.filename IS 'Document name shown in the admin RAG tab and cited in answers.'",
        "COMMENT ON COLUMN $ragFilesTable.content IS 'Full plain-text content; GIN full-text indexed with to_tsvector(english, content).'",
        "COMMENT ON COLUMN $ragFilesTable.tags IS 'Tags used to scope retrieval (GIN indexed).'",
        "COMMENT ON COLUMN $ragFilesTable.file_size IS 'Original size in bytes.'",
        "COMMENT ON COLUMN $ragFilesTable.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $ragFilesTable.created_at IS 'Upload time.'",

        "COMMENT ON TABLE $ragChunksTable IS 'Retrieval units: documents split into chunks. This is what is actually retrieved and put into the prompt.'",
        "COMMENT ON COLUMN $ragChunksTable.id IS 'Chunk id.'",
        "COMMENT ON COLUMN $ragChunksTable.file_id IS 'Parent document. FK to spw_rag_files(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $ragChunksTable.chunk_index IS 'Zero-based position within the document. UNIQUE together with file_id, so re-chunking cannot duplicate.'",
        "COMMENT ON COLUMN $ragChunksTable.content IS 'Chunk text; GIN full-text indexed with to_tsvector(english, content).'",

        "COMMENT ON TABLE $ragQueriesTable IS 'Question log and usage metering for the AI assistant.'",
        "COMMENT ON COLUMN $ragQueriesTable.id IS 'Query id.'",
        "COMMENT ON COLUMN $ragQueriesTable.query IS 'The user question.'",
        "COMMENT ON COLUMN $ragQueriesTable.tags IS 'Tag filter applied to retrieval.'",
        "COMMENT ON COLUMN $ragQueriesTable.matched_files IS 'Number of documents that matched.'",
        "COMMENT ON COLUMN $ragQueriesTable.prompt_tokens IS 'Tokens sent to the model.'",
        "COMMENT ON COLUMN $ragQueriesTable.completion_tokens IS 'Tokens returned by the model.'",
        "COMMENT ON COLUMN $ragQueriesTable.total_ms IS 'End-to-end duration in milliseconds.'",
        "COMMENT ON COLUMN $ragQueriesTable.model IS 'Model identifier used.'",
        "COMMENT ON COLUMN $ragQueriesTable.user_id IS 'Asker. FK to spw_users(id) ON DELETE SET NULL.'",
        "COMMENT ON COLUMN $ragQueriesTable.created_at IS 'Ask time.'",
        "COMMENT ON COLUMN $ragQueriesTable.prompt_snapshot IS 'The fully assembled prompt, for debugging and reproducibility.'",

        "COMMENT ON TABLE $ragQuerySourcesTable IS 'Citations produced for one RAG query, in relevance order.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.query_id IS 'Parent query. FK to spw_rag_queries(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.file_id IS 'Cited document. FK to spw_rag_files(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.chunk_id IS 'Cited chunk. FK to spw_rag_chunks(id) ON DELETE SET NULL; NULL for whole-document citations or when the chunk was later deleted.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.chunk_index IS 'Chunk position captured at query time; -1 means a document-level citation.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.filename IS 'Document name captured at query time; survives renames and deletes.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.snippet IS 'The excerpt actually shown to the user.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.source_type IS 'file or chunk - which retrieval path produced the hit.'",
        "COMMENT ON COLUMN $ragQuerySourcesTable.rank_position IS 'Zero-based relevance rank within this query results.'",

        "COMMENT ON TABLE $anonymizationLogTable IS 'Run history of the anonymization worker (cron/cron_anonymization.php).'",
        "COMMENT ON COLUMN $anonymizationLogTable.id IS 'Run id; loosely referenced by spw_anonymization_report.log_id.'",
        "COMMENT ON COLUMN $anonymizationLogTable.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $anonymizationLogTable.finished_at IS 'Run end.'",
        "COMMENT ON COLUMN $anonymizationLogTable.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $anonymizationLogTable.triggered_by IS 'cron, or a manual admin run.'",
        "COMMENT ON COLUMN $anonymizationLogTable.rules_processed IS 'Anonymization rules evaluated.'",
        "COMMENT ON COLUMN $anonymizationLogTable.rows_anonymized IS 'Rows whose columns were scrubbed.'",
        "COMMENT ON COLUMN $anonymizationLogTable.error_message IS 'Failure detail.'",

        "COMMENT ON TABLE $anonymizationReportTable IS 'Detailed per-run anonymization report, downloadable from the admin panel.'",
        "COMMENT ON COLUMN $anonymizationReportTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $anonymizationReportTable.log_id IS 'Matching spw_anonymization_log.id. No foreign key - reports may be kept after log pruning.'",
        "COMMENT ON COLUMN $anonymizationReportTable.report_id IS 'Stable external identifier used to fetch or download the report.'",
        "COMMENT ON COLUMN $anonymizationReportTable.triggered_by IS 'Copied from the run for standalone readability.'",
        "COMMENT ON COLUMN $anonymizationReportTable.status IS 'Copied from the run.'",
        "COMMENT ON COLUMN $anonymizationReportTable.rows_affected IS 'Total rows scrubbed in this run.'",
        "COMMENT ON COLUMN $anonymizationReportTable.report IS 'Full breakdown: per table/column/rule counts, dry-run flag, timings.'",
        "COMMENT ON COLUMN $anonymizationReportTable.created_at IS 'Report creation time.'",

        "COMMENT ON TABLE $etlLogTable IS 'Single-job ETL run history, written by cron/cron_etl.php; also the per-step target of flow runs.'",
        "COMMENT ON COLUMN $etlLogTable.id IS 'Run id.'",
        "COMMENT ON COLUMN $etlLogTable.job_id IS 'Job identifier from the ETL config.'",
        "COMMENT ON COLUMN $etlLogTable.job_name IS 'Job label captured at run time.'",
        "COMMENT ON COLUMN $etlLogTable.triggered_by IS 'cron, a manual admin run, or a flow.'",
        "COMMENT ON COLUMN $etlLogTable.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $etlLogTable.rows_read IS 'Rows read from the source (MySQL or PostgreSQL).'",
        "COMMENT ON COLUMN $etlLogTable.rows_written IS 'Rows written to the PostgreSQL target.'",
        "COMMENT ON COLUMN $etlLogTable.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $etlLogTable.started_at IS 'Run start.'",
        "COMMENT ON COLUMN $etlLogTable.finished_at IS 'Run end.'",

        "COMMENT ON TABLE $etlFlowRunLogTable IS 'Run history of ETL flows - multi-step job chains (cron/cron_etl_flow.php).'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.id IS 'Flow run id; parent of spw_etl_flow_step_log.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.flow_id IS 'Flow identifier from the ETL config.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.flow_name IS 'Flow label captured at run time.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.triggered_by IS 'cron, or a manual admin run.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.failed_step_index IS 'Zero-based index of the step that aborted the flow; NULL on success.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.started_at IS 'Flow start.'",
        "COMMENT ON COLUMN $etlFlowRunLogTable.finished_at IS 'Flow end.'",

        "COMMENT ON TABLE $etlFlowStepLogTable IS 'Per-step detail of an ETL flow run; cascades with its parent run.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.id IS 'Surrogate primary key.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.flow_run_id IS 'Parent flow run. FK to spw_etl_flow_run_log(id) ON DELETE CASCADE.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.flow_id IS 'Denormalized flow id for direct querying.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.step_index IS 'Zero-based position of the step in the flow.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.job_id IS 'ETL job executed by this step.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.job_name IS 'Job label captured at run time.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.status IS 'running / ok / error.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.rows_read IS 'Rows read by the step.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.rows_written IS 'Rows written by the step.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.error_message IS 'Failure detail.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.started_at IS 'Step start.'",
        "COMMENT ON COLUMN $etlFlowStepLogTable.finished_at IS 'Step end.'",
    ];
}

function system_tables_user_contact_ddl(callable $ident): array
{
    $usersTable = $ident('users');

    return [
        "ALTER TABLE $usersTable ADD COLUMN IF NOT EXISTS first_name varchar(100)",
        "ALTER TABLE $usersTable ADD COLUMN IF NOT EXISTS last_name varchar(100)",
        "ALTER TABLE $usersTable ADD COLUMN IF NOT EXISTS email varchar(255)",
        "ALTER TABLE $usersTable ADD COLUMN IF NOT EXISTS phone varchar(32)",
        "COMMENT ON COLUMN $usersTable.first_name IS 'Optional given name, admin panel only. Informational - not used for login or notifications.'",
        "COMMENT ON COLUMN $usersTable.last_name IS 'Optional surname, admin panel only. Informational - not used for login or notifications.'",
        "COMMENT ON COLUMN $usersTable.email IS 'Optional contact email, admin panel only. Format-checked, not unique, not used for login or notifications.'",
        "COMMENT ON COLUMN $usersTable.phone IS 'Optional contact phone, admin panel only. Informational.'",
    ];
}

function system_tables_clickstats_ddl(callable $ident): array
{
    $clickstatsTable = $ident('clickstats');
    $usersTable      = $ident('users');

    return [
        "CREATE TABLE IF NOT EXISTS $clickstatsTable (
            id bigserial NOT NULL,
            user_id int4 NULL,
            element varchar(120) NOT NULL,
            page varchar(120) NULL,
            table_name varchar(100) NULL,
            record_id int4 NULL,
            created_at timestamp DEFAULT now() NOT NULL,
            CONSTRAINT spw_clickstats_pkey PRIMARY KEY (id),
            CONSTRAINT spw_clickstats_user_fk FOREIGN KEY (user_id)
                REFERENCES $usersTable(id) ON DELETE SET NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_spw_clickstats_created ON $clickstatsTable (created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_spw_clickstats_element ON $clickstatsTable (element)",
        "COMMENT ON TABLE $clickstatsTable IS 'Click statistics. Written only while Admin -> Click Statistics is enabled; one row per UI element click.'",
        "COMMENT ON COLUMN $clickstatsTable.element IS 'Element label: the data-stat attribute when present, otherwise a derived id/class/text label.'",
        "COMMENT ON COLUMN $clickstatsTable.page IS 'Page script the click happened on, e.g. index.php.'",
        "COMMENT ON COLUMN $clickstatsTable.table_name IS 'Table in context when the click happened, or NULL.'",
        "COMMENT ON COLUMN $clickstatsTable.record_id IS 'Record in context when the click happened, or NULL.'",
    ];
}
