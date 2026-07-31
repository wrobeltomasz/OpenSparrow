-- OpenSparrow — COMMENT ON statements for all spw_* system tables and columns.
--
-- Scope:   26 tables from includes/system_tables.php (system_tables_ddl) + spw_migrations
--          from the init_db bootstrap block = 27 tables.
-- Version: valid for the initial layout (3.0_baseline) AND for 3.1 — no DDL migration was
--          added after 3.0_baseline, so the two layouts are identical.
--
-- Schema:  statements below use "app", the default from config/database.json -> schema.
--          If your installation uses a different schema, run the script after:
--              SET search_path TO your_schema;
--          and strip the app. prefix, or sed-replace app. with your schema name.
--
-- Comments are metadata only: this script is idempotent, re-runnable, and changes no data.
-- Keep it in sync with docs/DATABASE.md and includes/system_tables.php.

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Bootstrap
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_migrations IS 'Tracker of applied database migrations. Created by the init_db bootstrap block, not by system_tables_ddl(), because it must exist before the migration registry can be consulted.';
COMMENT ON COLUMN app.spw_migrations.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_migrations.name IS 'Migration key, e.g. 3.0_baseline. Presence of the row means already applied - init_db skips it on re-run. UNIQUE.';
COMMENT ON COLUMN app.spw_migrations.applied_at IS 'When the migration ran.';

-- ---------------------------------------------------------------------------
-- 2. Users, auth, audit
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_users IS 'Application accounts. Referenced by most other system tables.';
COMMENT ON COLUMN app.spw_users.id IS 'User id; referenced by most other system tables.';
COMMENT ON COLUMN app.spw_users.username IS 'Login name. UNIQUE.';
COMMENT ON COLUMN app.spw_users.password_hash IS 'Hash produced by PHP password_hash() (Argon2id by default). Never a plaintext or reversible value.';
COMMENT ON COLUMN app.spw_users.salt IS 'Legacy/optional extra salt. Modern Argon2id hashes carry their salt inside password_hash.';
COMMENT ON COLUMN app.spw_users.password_algo IS 'Algorithm the hash was produced with; drives rehash-on-login decisions. Default argon2id.';
COMMENT ON COLUMN app.spw_users.password_params IS 'Cost parameters used for the hash (memory/time/threads), for rehash comparison.';
COMMENT ON COLUMN app.spw_users.is_active IS 'Soft disable - inactive users cannot log in but their audit rows survive.';
COMMENT ON COLUMN app.spw_users.role IS 'Authorization level (admin / editor / read-only roles). Enforced server-side in api_bootstrap.php and requireWrite().';
COMMENT ON COLUMN app.spw_users.avatar_id IS 'Avatar colour: 1-based index into the avatar palette (OS_AVATAR_COLORS). NULL = default colour. The avatar itself is the initial of the username.';

COMMENT ON TABLE  app.spw_users_log IS 'User action audit trail. Written by log_user_action() (includes/api_helpers.php); every mutation must produce a row.';
COMMENT ON COLUMN app.spw_users_log.id IS 'Log entry id; referenced by spw_record_snapshots.log_id.';
COMMENT ON COLUMN app.spw_users_log.user_id IS 'Acting user (spw_users.id). No foreign key, so history survives user deletion.';
COMMENT ON COLUMN app.spw_users_log.action IS 'Action name, e.g. insert, update, delete, login.';
COMMENT ON COLUMN app.spw_users_log.target_table IS 'Table the action touched.';
COMMENT ON COLUMN app.spw_users_log.record_id IS 'Affected record primary key.';
COMMENT ON COLUMN app.spw_users_log.created_at IS 'When the action happened.';

COMMENT ON TABLE  app.spw_login_attempts IS 'Login attempt journal backing the rate limiter. Pruned by cron/cron_notifications.php.';
COMMENT ON COLUMN app.spw_login_attempts.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_login_attempts.username IS 'Username the attempt was made for, recorded even if it does not exist.';
COMMENT ON COLUMN app.spw_login_attempts.ip_hash IS 'Salted hash of the client IP (IP_HASH_SALT). The raw IP is never stored.';
COMMENT ON COLUMN app.spw_login_attempts.attempted_at IS 'Attempt timestamp; the sliding window for the throttle.';

COMMENT ON TABLE  app.spw_record_snapshots IS 'Full JSON row snapshots taken at the moment of a change. Retention configurable in Admin - Settings.';
COMMENT ON COLUMN app.spw_record_snapshots.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_record_snapshots.log_id IS 'Audit entry this snapshot belongs to. FK to spw_users_log(id) ON DELETE CASCADE.';
COMMENT ON COLUMN app.spw_record_snapshots.table_name IS 'Snapshotted table.';
COMMENT ON COLUMN app.spw_record_snapshots.record_id IS 'Snapshotted record.';
COMMENT ON COLUMN app.spw_record_snapshots.snapshot IS 'Full row content as JSON at the moment of the change.';
COMMENT ON COLUMN app.spw_record_snapshots.created_at IS 'Snapshot time.';

COMMENT ON TABLE  app.spw_record_owners IS 'Record ownership history. The row flagged is_current drives owner_restriction_sql() row filtering.';
COMMENT ON COLUMN app.spw_record_owners.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_record_owners.table_name IS 'Owned table.';
COMMENT ON COLUMN app.spw_record_owners.record_id IS 'Owned record.';
COMMENT ON COLUMN app.spw_record_owners.owner_id IS 'Current or former owner. FK to spw_users(id) ON DELETE SET NULL; NULL when the user is deleted or ownership is cleared.';
COMMENT ON COLUMN app.spw_record_owners.changed_by IS 'Who performed the ownership change. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_record_owners.changed_at IS 'When ownership changed.';
COMMENT ON COLUMN app.spw_record_owners.is_current IS 'Marks the one active row per record; older rows stay as history.';

-- ---------------------------------------------------------------------------
-- 3. Configuration store
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_config IS 'DB-backed application configuration: one JSONB row per key (schema, menu, settings, dashboard, calendar, board, workflows, automations, views, files, print, anonymization, user_records, rag, ...). Accessed only through includes/config_store.php.';
COMMENT ON COLUMN app.spw_config.config_key IS 'Configuration key name (what used to be a config/*.json filename, without the extension). Primary key.';
COMMENT ON COLUMN app.spw_config.value IS 'The whole configuration document for that key.';
COMMENT ON COLUMN app.spw_config.version IS 'Optimistic-lock counter; a save with a stale version is rejected instead of overwriting a concurrent edit.';
COMMENT ON COLUMN app.spw_config.updated_by IS 'Last editor. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_config.updated_at IS 'Last save time.';

COMMENT ON TABLE  app.spw_config_log IS 'Audit trail of configuration changes, with old/new document snapshots.';
COMMENT ON COLUMN app.spw_config_log.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_config_log.config_key IS 'Configuration key that changed.';
COMMENT ON COLUMN app.spw_config_log.old_value IS 'Document before the change; NULL on first insert.';
COMMENT ON COLUMN app.spw_config_log.new_value IS 'Document after the change; NULL on delete.';
COMMENT ON COLUMN app.spw_config_log.changed_by IS 'Editor. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_config_log.changed_at IS 'Change time.';

COMMENT ON TABLE  app.spw_release_migrations IS 'Applied release migrations - the file and config-key cleanups from config/migrations.json, applied via public/admin/api_migrations.php. Distinct from spw_migrations, which tracks database DDL.';
COMMENT ON COLUMN app.spw_release_migrations.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_release_migrations.version IS 'Release version processed, e.g. 3.1. UNIQUE.';
COMMENT ON COLUMN app.spw_release_migrations.applied_at IS 'When the release migration was applied.';
COMMENT ON COLUMN app.spw_release_migrations.applied_by IS 'Admin who ran it. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_release_migrations.actions IS 'Log of what was actually done: files deleted, config keys stripped, skips.';

-- ---------------------------------------------------------------------------
-- 4. Content attached to records
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_files IS 'Uploaded files, record attachments and record image galleries. Served only through the files.php / api/files.php / file_download.php proxies, never by direct path.';
COMMENT ON COLUMN app.spw_files.id IS 'Internal id. Never exposed in URLs.';
COMMENT ON COLUMN app.spw_files.uuid IS 'Public handle used by files.php, api/files.php and file_download.php. UNIQUE, defaults to gen_random_uuid().';
COMMENT ON COLUMN app.spw_files.name IS 'Server-generated storage filename - never the client-supplied name.';
COMMENT ON COLUMN app.spw_files.display_name IS 'Human-facing label shown in the UI.';
COMMENT ON COLUMN app.spw_files.type IS 'Coarse category (image, document, ...) used for filtering and icons.';
COMMENT ON COLUMN app.spw_files.mime_type IS 'MIME type detected from file content with finfo, not from the upload header.';
COMMENT ON COLUMN app.spw_files.extension IS 'Whitelisted file extension.';
COMMENT ON COLUMN app.spw_files.size_bytes IS 'File size in bytes.';
COMMENT ON COLUMN app.spw_files.storage_path IS 'Path under storage/files/, outside the document root and outside any execution path.';
COMMENT ON COLUMN app.spw_files.related_table IS 'Table the file is attached to; NULL for standalone library files.';
COMMENT ON COLUMN app.spw_files.related_id IS 'Record the file is attached to.';
COMMENT ON COLUMN app.spw_files.related_field IS 'Field the attachment belongs to. The sentinel __image (IMAGES_FIELD, includes/images.php) marks a record gallery image (feature added in 3.1, no schema change).';
COMMENT ON COLUMN app.spw_files.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_files.created_at IS 'Upload time. Gallery ordering uses id, which follows this.';
COMMENT ON COLUMN app.spw_files.updated_at IS 'Last metadata change.';
COMMENT ON COLUMN app.spw_files.deleted_at IS 'Soft delete marker - all read paths filter deleted_at IS NULL.';
COMMENT ON COLUMN app.spw_files.description IS 'Free-text description.';
COMMENT ON COLUMN app.spw_files.tags IS 'Tag array used for filtering (GIN indexed).';
COMMENT ON COLUMN app.spw_files.metadata IS 'Extra attributes: image dimensions, thumbnail info, and similar (GIN indexed).';

COMMENT ON TABLE  app.spw_comments IS 'Per-record discussion threads, visible to all users who can see the record.';
COMMENT ON COLUMN app.spw_comments.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_comments.related_table IS 'Commented table.';
COMMENT ON COLUMN app.spw_comments.related_id IS 'Commented record.';
COMMENT ON COLUMN app.spw_comments.user_id IS 'Author. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_comments.body IS 'Comment text. CHECK char_length(body) <= 4000.';
COMMENT ON COLUMN app.spw_comments.created_at IS 'Post time.';
COMMENT ON COLUMN app.spw_comments.deleted_at IS 'Soft delete marker.';

COMMENT ON TABLE  app.spw_notes IS 'Private user notepad. Unlike comments, notes are visible only to their author; they may be free-floating or attached to a record, and may carry a reminder date.';
COMMENT ON COLUMN app.spw_notes.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_notes.user_id IS 'Owner. FK to spw_users(id) ON DELETE CASCADE - notes die with the account.';
COMMENT ON COLUMN app.spw_notes.related_table IS 'Optional linked table.';
COMMENT ON COLUMN app.spw_notes.related_id IS 'Optional linked record.';
COMMENT ON COLUMN app.spw_notes.body IS 'Note text. CHECK char_length(body) <= 4000.';
COMMENT ON COLUMN app.spw_notes.reminder_date IS 'If set, cron/cron_notifications.php raises a notification on that date.';
COMMENT ON COLUMN app.spw_notes.created_at IS 'Creation time.';
COMMENT ON COLUMN app.spw_notes.updated_at IS 'Last edit; NULL if never edited.';
COMMENT ON COLUMN app.spw_notes.deleted_at IS 'Soft delete marker.';

-- ---------------------------------------------------------------------------
-- 5. Notifications
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_users_notifications IS 'Per-user notification inbox. UNIQUE (user_id, source_table, source_id, notify_date) is the deduplication key that makes the cron worker idempotent when it re-runs on the same day.';
COMMENT ON COLUMN app.spw_users_notifications.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_users_notifications.user_id IS 'Recipient (spw_users.id).';
COMMENT ON COLUMN app.spw_users_notifications.title IS 'Notification headline.';
COMMENT ON COLUMN app.spw_users_notifications.link IS 'Target URL to open on click.';
COMMENT ON COLUMN app.spw_users_notifications.source_table IS 'Table that triggered the notification.';
COMMENT ON COLUMN app.spw_users_notifications.source_id IS 'Record that triggered the notification.';
COMMENT ON COLUMN app.spw_users_notifications.is_read IS 'Read flag, toggled from api/notifications.php.';
COMMENT ON COLUMN app.spw_users_notifications.notify_date IS 'Date the notification is for; part of the deduplication key.';
COMMENT ON COLUMN app.spw_users_notifications.created_at IS 'Generation time.';

COMMENT ON TABLE  app.spw_users_notifications_log IS 'Run history of the notification cron worker (cron/cron_notifications.php).';
COMMENT ON COLUMN app.spw_users_notifications_log.id IS 'Run id.';
COMMENT ON COLUMN app.spw_users_notifications_log.started_at IS 'Run start.';
COMMENT ON COLUMN app.spw_users_notifications_log.finished_at IS 'Run end; NULL while running or after a crash.';
COMMENT ON COLUMN app.spw_users_notifications_log.status IS 'running / ok / error.';
COMMENT ON COLUMN app.spw_users_notifications_log.triggered_by IS 'cron, or a manual admin trigger.';
COMMENT ON COLUMN app.spw_users_notifications_log.sources_processed IS 'Number of configured notification sources scanned.';
COMMENT ON COLUMN app.spw_users_notifications_log.notifications_created IS 'Rows actually inserted into spw_users_notifications.';
COMMENT ON COLUMN app.spw_users_notifications_log.error_message IS 'Failure detail when status = error.';

-- ---------------------------------------------------------------------------
-- 6. Automations
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_automation_runs IS 'Execution log of automation rules (includes/automations.php), one row per rule firing.';
COMMENT ON COLUMN app.spw_automation_runs.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_automation_runs.rule_id IS 'Rule identifier from the automations config key.';
COMMENT ON COLUMN app.spw_automation_runs.rule_name IS 'Rule label captured at run time; survives later renames.';
COMMENT ON COLUMN app.spw_automation_runs.table_name IS 'Table whose change fired the rule.';
COMMENT ON COLUMN app.spw_automation_runs.record_id IS 'Triggering record.';
COMMENT ON COLUMN app.spw_automation_runs.event IS 'Trigger event: insert / update / delete.';
COMMENT ON COLUMN app.spw_automation_runs.status IS 'ok or error.';
COMMENT ON COLUMN app.spw_automation_runs.error_msg IS 'Failure detail.';
COMMENT ON COLUMN app.spw_automation_runs.executed_at IS 'Execution time.';

COMMENT ON TABLE  app.spw_automation_emails IS 'Outbound e-mail queue. Rows are enqueued by the automation engine and delivered by cron/cron_notifications.php.';
COMMENT ON COLUMN app.spw_automation_emails.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_automation_emails.rule_id IS 'Automation rule that queued the message.';
COMMENT ON COLUMN app.spw_automation_emails.recipient IS 'Destination e-mail address.';
COMMENT ON COLUMN app.spw_automation_emails.subject IS 'Rendered subject line.';
COMMENT ON COLUMN app.spw_automation_emails.body IS 'Rendered message body.';
COMMENT ON COLUMN app.spw_automation_emails.source_table IS 'Table of the triggering record.';
COMMENT ON COLUMN app.spw_automation_emails.record_id IS 'Triggering record.';
COMMENT ON COLUMN app.spw_automation_emails.created_by IS 'User whose action queued the message; 0 for system/cron.';
COMMENT ON COLUMN app.spw_automation_emails.status IS 'pending / sent / error.';
COMMENT ON COLUMN app.spw_automation_emails.attempts IS 'Delivery attempts, for retry back-off.';
COMMENT ON COLUMN app.spw_automation_emails.error_msg IS 'Last delivery error.';
COMMENT ON COLUMN app.spw_automation_emails.created_at IS 'Enqueue time.';
COMMENT ON COLUMN app.spw_automation_emails.sent_at IS 'Successful delivery time.';

-- ---------------------------------------------------------------------------
-- 7. CSV import
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_imports IS 'One row per CSV import run (public/admin/api_csv_import.php).';
COMMENT ON COLUMN app.spw_imports.id IS 'Import run id.';
COMMENT ON COLUMN app.spw_imports.user_id IS 'Who ran the import. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_imports.filename IS 'Uploaded file name as shown to the user.';
COMMENT ON COLUMN app.spw_imports.target_table IS 'Destination table.';
COMMENT ON COLUMN app.spw_imports.status IS 'pending / running / done / error.';
COMMENT ON COLUMN app.spw_imports.total_rows IS 'Data rows found in the file.';
COMMENT ON COLUMN app.spw_imports.imported_rows IS 'Rows inserted or updated.';
COMMENT ON COLUMN app.spw_imports.skipped_rows IS 'Rows rejected; each one detailed in spw_import_rows_log.';
COMMENT ON COLUMN app.spw_imports.column_mapping IS 'CSV column to table column map used for this run.';
COMMENT ON COLUMN app.spw_imports.conflict_column IS 'Column used for upsert matching; NULL for insert-only runs.';
COMMENT ON COLUMN app.spw_imports.error_message IS 'Run-level failure detail.';
COMMENT ON COLUMN app.spw_imports.started_at IS 'Run start.';
COMMENT ON COLUMN app.spw_imports.finished_at IS 'Run end.';

COMMENT ON TABLE  app.spw_import_rows_log IS 'Per-row rejection detail for a CSV import run.';
COMMENT ON COLUMN app.spw_import_rows_log.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_import_rows_log.import_id IS 'Parent run. FK to spw_imports(id) ON DELETE CASCADE - detail dies with the run.';
COMMENT ON COLUMN app.spw_import_rows_log.row_number IS 'One-based row number in the source file.';
COMMENT ON COLUMN app.spw_import_rows_log.raw_data IS 'The offending row as parsed, for diagnosis.';
COMMENT ON COLUMN app.spw_import_rows_log.error_message IS 'Why the row was skipped.';
COMMENT ON COLUMN app.spw_import_rows_log.logged_at IS 'Log time.';

-- ---------------------------------------------------------------------------
-- 8. RAG knowledge base
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_rag_files IS 'Knowledge-base documents for the AI assistant. Full-text indexed on content.';
COMMENT ON COLUMN app.spw_rag_files.id IS 'Document id.';
COMMENT ON COLUMN app.spw_rag_files.filename IS 'Document name shown in the admin RAG tab and cited in answers.';
COMMENT ON COLUMN app.spw_rag_files.content IS 'Full plain-text content; GIN full-text indexed with to_tsvector(english, content).';
COMMENT ON COLUMN app.spw_rag_files.tags IS 'Tags used to scope retrieval (GIN indexed).';
COMMENT ON COLUMN app.spw_rag_files.file_size IS 'Original size in bytes.';
COMMENT ON COLUMN app.spw_rag_files.uploaded_by IS 'Uploader. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_rag_files.created_at IS 'Upload time.';

COMMENT ON TABLE  app.spw_rag_chunks IS 'Retrieval units: documents split into chunks. This is what is actually retrieved and put into the prompt.';
COMMENT ON COLUMN app.spw_rag_chunks.id IS 'Chunk id.';
COMMENT ON COLUMN app.spw_rag_chunks.file_id IS 'Parent document. FK to spw_rag_files(id) ON DELETE CASCADE.';
COMMENT ON COLUMN app.spw_rag_chunks.chunk_index IS 'Zero-based position within the document. UNIQUE together with file_id, so re-chunking cannot duplicate.';
COMMENT ON COLUMN app.spw_rag_chunks.content IS 'Chunk text; GIN full-text indexed with to_tsvector(english, content).';

COMMENT ON TABLE  app.spw_rag_queries IS 'Question log and usage metering for the AI assistant.';
COMMENT ON COLUMN app.spw_rag_queries.id IS 'Query id.';
COMMENT ON COLUMN app.spw_rag_queries.query IS 'The user question.';
COMMENT ON COLUMN app.spw_rag_queries.tags IS 'Tag filter applied to retrieval.';
COMMENT ON COLUMN app.spw_rag_queries.matched_files IS 'Number of documents that matched.';
COMMENT ON COLUMN app.spw_rag_queries.prompt_tokens IS 'Tokens sent to the model.';
COMMENT ON COLUMN app.spw_rag_queries.completion_tokens IS 'Tokens returned by the model.';
COMMENT ON COLUMN app.spw_rag_queries.total_ms IS 'End-to-end duration in milliseconds.';
COMMENT ON COLUMN app.spw_rag_queries.model IS 'Model identifier used.';
COMMENT ON COLUMN app.spw_rag_queries.user_id IS 'Asker. FK to spw_users(id) ON DELETE SET NULL.';
COMMENT ON COLUMN app.spw_rag_queries.created_at IS 'Ask time.';
COMMENT ON COLUMN app.spw_rag_queries.prompt_snapshot IS 'The fully assembled prompt, for debugging and reproducibility.';

COMMENT ON TABLE  app.spw_rag_query_sources IS 'Citations produced for one RAG query, in relevance order.';
COMMENT ON COLUMN app.spw_rag_query_sources.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_rag_query_sources.query_id IS 'Parent query. FK to spw_rag_queries(id) ON DELETE CASCADE.';
COMMENT ON COLUMN app.spw_rag_query_sources.file_id IS 'Cited document. FK to spw_rag_files(id) ON DELETE CASCADE.';
COMMENT ON COLUMN app.spw_rag_query_sources.chunk_id IS 'Cited chunk. FK to spw_rag_chunks(id) ON DELETE SET NULL; NULL for whole-document citations or when the chunk was later deleted.';
COMMENT ON COLUMN app.spw_rag_query_sources.chunk_index IS 'Chunk position captured at query time; -1 means a document-level citation.';
COMMENT ON COLUMN app.spw_rag_query_sources.filename IS 'Document name captured at query time; survives renames and deletes.';
COMMENT ON COLUMN app.spw_rag_query_sources.snippet IS 'The excerpt actually shown to the user.';
COMMENT ON COLUMN app.spw_rag_query_sources.source_type IS 'file or chunk - which retrieval path produced the hit.';
COMMENT ON COLUMN app.spw_rag_query_sources.rank_position IS 'Zero-based relevance rank within this query results.';

-- ---------------------------------------------------------------------------
-- 9. Data anonymization (GDPR)
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_anonymization_log IS 'Run history of the anonymization worker (cron/cron_anonymization.php).';
COMMENT ON COLUMN app.spw_anonymization_log.id IS 'Run id; loosely referenced by spw_anonymization_report.log_id.';
COMMENT ON COLUMN app.spw_anonymization_log.started_at IS 'Run start.';
COMMENT ON COLUMN app.spw_anonymization_log.finished_at IS 'Run end.';
COMMENT ON COLUMN app.spw_anonymization_log.status IS 'running / ok / error.';
COMMENT ON COLUMN app.spw_anonymization_log.triggered_by IS 'cron, or a manual admin run.';
COMMENT ON COLUMN app.spw_anonymization_log.rules_processed IS 'Anonymization rules evaluated.';
COMMENT ON COLUMN app.spw_anonymization_log.rows_anonymized IS 'Rows whose columns were scrubbed.';
COMMENT ON COLUMN app.spw_anonymization_log.error_message IS 'Failure detail.';

COMMENT ON TABLE  app.spw_anonymization_report IS 'Detailed per-run anonymization report, downloadable from the admin panel.';
COMMENT ON COLUMN app.spw_anonymization_report.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_anonymization_report.log_id IS 'Matching spw_anonymization_log.id. No foreign key - reports may be kept after log pruning.';
COMMENT ON COLUMN app.spw_anonymization_report.report_id IS 'Stable external identifier used to fetch or download the report.';
COMMENT ON COLUMN app.spw_anonymization_report.triggered_by IS 'Copied from the run for standalone readability.';
COMMENT ON COLUMN app.spw_anonymization_report.status IS 'Copied from the run.';
COMMENT ON COLUMN app.spw_anonymization_report.rows_affected IS 'Total rows scrubbed in this run.';
COMMENT ON COLUMN app.spw_anonymization_report.report IS 'Full breakdown: per table/column/rule counts, dry-run flag, timings.';
COMMENT ON COLUMN app.spw_anonymization_report.created_at IS 'Report creation time.';

-- ---------------------------------------------------------------------------
-- 10. ETL
-- ---------------------------------------------------------------------------

COMMENT ON TABLE  app.spw_etl_log IS 'Single-job ETL run history, written by cron/cron_etl.php; also the per-step target of flow runs.';
COMMENT ON COLUMN app.spw_etl_log.id IS 'Run id.';
COMMENT ON COLUMN app.spw_etl_log.job_id IS 'Job identifier from the ETL config.';
COMMENT ON COLUMN app.spw_etl_log.job_name IS 'Job label captured at run time.';
COMMENT ON COLUMN app.spw_etl_log.triggered_by IS 'cron, a manual admin run, or a flow.';
COMMENT ON COLUMN app.spw_etl_log.status IS 'running / ok / error.';
COMMENT ON COLUMN app.spw_etl_log.rows_read IS 'Rows read from the source (MySQL or PostgreSQL).';
COMMENT ON COLUMN app.spw_etl_log.rows_written IS 'Rows written to the PostgreSQL target.';
COMMENT ON COLUMN app.spw_etl_log.error_message IS 'Failure detail.';
COMMENT ON COLUMN app.spw_etl_log.started_at IS 'Run start.';
COMMENT ON COLUMN app.spw_etl_log.finished_at IS 'Run end.';

COMMENT ON TABLE  app.spw_etl_flow_run_log IS 'Run history of ETL flows - multi-step job chains (cron/cron_etl_flow.php).';
COMMENT ON COLUMN app.spw_etl_flow_run_log.id IS 'Flow run id; parent of spw_etl_flow_step_log.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.flow_id IS 'Flow identifier from the ETL config.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.flow_name IS 'Flow label captured at run time.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.triggered_by IS 'cron, or a manual admin run.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.status IS 'running / ok / error.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.failed_step_index IS 'Zero-based index of the step that aborted the flow; NULL on success.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.error_message IS 'Failure detail.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.started_at IS 'Flow start.';
COMMENT ON COLUMN app.spw_etl_flow_run_log.finished_at IS 'Flow end.';

COMMENT ON TABLE  app.spw_etl_flow_step_log IS 'Per-step detail of an ETL flow run; cascades with its parent run.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.id IS 'Surrogate primary key.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.flow_run_id IS 'Parent flow run. FK to spw_etl_flow_run_log(id) ON DELETE CASCADE.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.flow_id IS 'Denormalized flow id for direct querying.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.step_index IS 'Zero-based position of the step in the flow.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.job_id IS 'ETL job executed by this step.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.job_name IS 'Job label captured at run time.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.status IS 'running / ok / error.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.rows_read IS 'Rows read by the step.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.rows_written IS 'Rows written by the step.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.error_message IS 'Failure detail.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.started_at IS 'Step start.';
COMMENT ON COLUMN app.spw_etl_flow_step_log.finished_at IS 'Step end.';

COMMIT;
