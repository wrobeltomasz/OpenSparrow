# OpenSparrow system tables (`spw_*`)

Reference for every system-owned table. All of them live in the PostgreSQL schema configured in
`config/database.json` → `schema` (default `app`) and are prefixed `spw_`.

**Sources of truth**

- `includes/system_tables.php` → `system_tables_ddl()` — the whole schema; it *is* the body of the
  `3.0_baseline` migration and is also what the setup wizard (`public/setup_api.php`) runs.
- `includes/admin/migrations.php` → `$bootstrap` — `CREATE SCHEMA` + `spw_migrations` only; created
  before the migration registry can be consulted, so it is not part of `system_tables_ddl()`.

**Version status**

| | |
|---|---|
| Initial version | **3.0** — single migration `3.0_baseline` (the pre-3.0 incremental history was collapsed into it) |
| Current version | **3.1** (`includes/version.php`, `includes/VERSION`) |
| Migrations after the baseline | **`3.1_table_comments`** — `COMMENT ON TABLE` / `COMMENT ON COLUMN` only |

The `$migrations` registry, the `$known` list (`includes/admin/migrations.php`) and `$knownMig`
(`includes/admin/overview.php`) contain the same two keys: `3.0_baseline` and `3.1_table_comments`.
`config/migrations.json` has a `3.1` entry with empty `removed_files` / `removed_config_keys`.

**The 3.1 table layout is identical to the 3.0 initial layout** — `3.1_table_comments` adds
descriptions (catalog metadata), not columns, constraints or indexes. 3.1's
feature (per-table record image galleries) reuses `spw_files` with no schema change: gallery images
are ordinary `spw_files` rows tagged `related_field = '__image'` (`IMAGES_FIELD`,
`includes/images.php`). Every "3.1" column below is therefore also a "3.0" column; the *Changed in
3.1* column of each table is empty by construction and is not repeated per table.

27 tables total: 26 from `system_tables_ddl()` + `spw_migrations` from the bootstrap.

**Where the descriptions live in the database**

The same texts are applied as `COMMENT ON TABLE` / `COMMENT ON COLUMN`, so they show up in
`psql \d+`, DBeaver, pgAdmin and anything else that reads `obj_description()`.

- `includes/system_tables.php` → `system_tables_comments_ddl()` — the authoritative list, and the
  body of the `3.1_table_comments` migration. Applied automatically by **both** entry points: the
  setup wizard (fresh install) and Admin → Migrations → Initialize System Tables (existing install).
  Metadata only, so it is idempotent and safe to re-run.
- `docs/sql/spw_comments.sql` — a standalone copy of the same statements for running by hand
  (`psql -d opensparrow -f docs/sql/spw_comments.sql`), written against the default schema `app`.
  Not needed if the migration has been applied. Keep it in sync when editing the PHP list.

---

## 1. Bootstrap

### `spw_migrations` — applied DB migrations tracker

Created by the bootstrap block, *not* by `system_tables_ddl()`. One row per applied migration key.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `name` | varchar(100) NOT NULL, UNIQUE | Migration key, e.g. `3.0_baseline`. Presence of the row means "already applied" — `init_db` skips it on re-run. |
| `applied_at` | timestamp NOT NULL DEFAULT now() | When the migration ran. |

---

## 2. Users, auth, audit

### `spw_users` — application accounts

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | User id; referenced by most other tables. |
| `username` | varchar(50) NOT NULL, UNIQUE | Login name. |
| `password_hash` | varchar(255) NOT NULL | Hash produced by `password_hash()` (Argon2id by default). Never a plaintext or reversible value. |
| `salt` | varchar(64) NULL | Legacy/optional extra salt column; modern Argon2id hashes carry their salt inside `password_hash`. |
| `password_algo` | varchar(32) NOT NULL DEFAULT `'argon2id'` | Algorithm the hash was produced with; drives rehash-on-login decisions. |
| `password_params` | jsonb DEFAULT `'{}'` | Cost parameters used for the hash (memory/time/threads), for rehash comparison. |
| `is_active` | bool DEFAULT true | Soft disable — inactive users cannot log in but their audit rows survive. |
| `role` | varchar(20) NOT NULL DEFAULT `'editor'` | Authorization level (`admin` / `editor` / read-only roles). Enforced server-side in `api_bootstrap.php` / `requireWrite()`. |
| `avatar_id` | smallint NULL | Avatar colour: 1-based index into the avatar palette (OS_AVATAR_COLORS). NULL = default colour. The avatar itself is the initial of the username. |

### `spw_users_log` — user action audit trail

Written by `log_user_action()` (`includes/api_helpers.php`); every mutation must produce a row.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Log entry id; referenced by `spw_record_snapshots.log_id`. |
| `user_id` | int4 NOT NULL | Acting user (`spw_users.id`; no FK, so history survives user deletion). |
| `action` | varchar(50) NOT NULL | Action name, e.g. `insert`, `update`, `delete`, `login`. |
| `target_table` | varchar(100) NULL | Table the action touched. |
| `record_id` | int4 NULL | Affected record's primary key. |
| `created_at` | timestamp DEFAULT CURRENT_TIMESTAMP | When it happened. |

### `spw_login_attempts` — login rate limiting

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `username` | varchar(50) NOT NULL | Username the attempt was made for (recorded even if it does not exist). |
| `ip_hash` | varchar(64) NOT NULL | Salted hash of the client IP (`IP_HASH_SALT`) — the raw IP is never stored. |
| `attempted_at` | timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP | Attempt timestamp; the sliding window for the throttle. |

Indexes: `(username, attempted_at)`, `(ip_hash, attempted_at)` — the two throttle lookups. Pruned by `cron/cron_notifications.php`.

### `spw_record_snapshots` — before/after row snapshots

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `log_id` | int4 NOT NULL → `spw_users_log(id)` ON DELETE CASCADE | The audit entry this snapshot belongs to. |
| `table_name` | varchar(100) NOT NULL | Snapshotted table. |
| `record_id` | int4 NOT NULL | Snapshotted record. |
| `snapshot` | jsonb NOT NULL | Full row content as JSON at the moment of the change. |
| `created_at` | timestamp DEFAULT CURRENT_TIMESTAMP | Snapshot time. |

Indexes: `(log_id)`, `(table_name, record_id)`. Retention is configurable in Admin → Settings (`includes/admin/settings.php`).

### `spw_record_owners` — record ownership history

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `table_name` | varchar(100) NOT NULL | Owned table. |
| `record_id` | int4 NOT NULL | Owned record. |
| `owner_id` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Current/former owner; NULL after the user is deleted or when ownership is cleared. |
| `changed_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Who performed the ownership change. |
| `changed_at` | timestamp NOT NULL DEFAULT now() | When ownership changed. |
| `is_current` | bool NOT NULL DEFAULT false | Marks the one active row per record; older rows stay as history. Drives `owner_restriction_sql()` row filtering. |

Index: `(table_name, record_id, is_current)`.

---

## 3. Configuration store

### `spw_config` — DB-backed application configuration

One JSONB row per configuration key (`schema`, `menu`, `settings`, `dashboard`, `calendar`, `board`,
`workflows`, `automations`, `views`, `files`, `print`, `anonymization`, `user_records`, `rag`, …).
Accessed only through `includes/config_store.php`.

| Column | Type | Description |
|---|---|---|
| `config_key` | varchar(64) PK | Configuration key name (what used to be a `config/*.json` filename, without the extension). |
| `value` | jsonb NOT NULL | The whole configuration document for that key. |
| `version` | int4 NOT NULL DEFAULT 1 | Optimistic-lock counter; a save with a stale version is rejected instead of overwriting a concurrent edit. |
| `updated_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Last editor. |
| `updated_at` | timestamp NOT NULL DEFAULT now() | Last save time. |

### `spw_config_log` — configuration audit trail

| Column | Type | Description |
|---|---|---|
| `id` | bigserial PK | Surrogate key. |
| `config_key` | varchar(64) NOT NULL | Key that changed. |
| `old_value` | jsonb NULL | Document before the change; NULL on first insert. |
| `new_value` | jsonb NULL | Document after the change; NULL on delete. |
| `changed_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Editor. |
| `changed_at` | timestamp NOT NULL DEFAULT now() | Change time. |

Index: `(config_key, changed_at DESC)`.

### `spw_release_migrations` — applied *release* migrations

Distinct from `spw_migrations`: this tracks the file/config-key cleanups from
`config/migrations.json`, applied via `public/admin/api_migrations.php`.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `version` | varchar(20) NOT NULL, UNIQUE | Release version processed, e.g. `3.1`. |
| `applied_at` | timestamp NOT NULL DEFAULT now() | When it was applied. |
| `applied_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Admin who ran it. |
| `actions` | jsonb NOT NULL DEFAULT `'[]'` | Log of what was actually done (files deleted, config keys stripped, skips). |

Index: `(version)`.

---

## 4. Content attached to records

### `spw_files` — uploaded files, attachments and record image galleries

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Internal id. Never exposed in URLs. |
| `uuid` | uuid NOT NULL, UNIQUE, DEFAULT `gen_random_uuid()` | Public handle used by `files.php` / `api/files.php` / `file_download.php`. |
| `name` | varchar(255) NOT NULL | Server-generated storage filename — never the client-supplied name. |
| `display_name` | varchar(255) NULL | Human-facing label shown in the UI. |
| `type` | varchar(50) NOT NULL | Coarse category (image, document, …) used for filtering and icons. |
| `mime_type` | varchar(100) NOT NULL | MIME type detected from file content with finfo, not from the upload header. |
| `extension` | varchar(20) NOT NULL | Whitelisted extension. |
| `size_bytes` | int8 NOT NULL DEFAULT 0 | File size. |
| `storage_path` | text NOT NULL | Path under `storage/files/`, outside the document root and outside any execution path. |
| `related_table` | varchar(100) NULL | Table the file is attached to; NULL for standalone library files. |
| `related_id` | int4 NULL | Record the file is attached to. |
| `related_field` | varchar(100) NULL | Field the attachment belongs to. The sentinel `'__image'` (`IMAGES_FIELD`) marks a **3.1 record gallery image**. |
| `uploaded_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Uploader. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Upload time; also the gallery ordering key (via `id`). |
| `updated_at` | timestamp NOT NULL DEFAULT now() | Last metadata change. |
| `deleted_at` | timestamp NULL | Soft delete — all read paths filter `deleted_at IS NULL`. |
| `description` | text NULL | Free-text description. |
| `tags` | text[] NULL | Tag array for filtering. |
| `metadata` | jsonb NULL | Extra attributes (image dimensions, thumbnail info, …). |

Indexes: partial `(deleted_at) WHERE deleted_at IS NULL`, GIN on `metadata`, GIN on `tags`, btree `(related_table, related_id)`, `(type)`, `(uploaded_by)`.

### `spw_comments` — per-record discussion

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `related_table` | varchar(100) NOT NULL | Commented table. |
| `related_id` | int4 NOT NULL | Commented record. |
| `user_id` | int4 NOT NULL → `spw_users(id)` ON DELETE SET NULL | Author. |
| `body` | text NOT NULL | Comment text; CHECK `char_length(body) <= 4000`. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Post time. |
| `deleted_at` | timestamp NULL | Soft delete. |

Indexes: `(related_table, related_id, created_at)`, `(user_id)`.

### `spw_notes` — private user notepad

Unlike comments, notes are visible only to their author; they may be free-floating or attached to a record, and may carry a reminder.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `user_id` | int4 NOT NULL → `spw_users(id)` ON DELETE CASCADE | Owner — notes die with the account. |
| `related_table` | varchar(100) NULL | Optional linked table. |
| `related_id` | int4 NULL | Optional linked record. |
| `body` | text NOT NULL | Note text; CHECK `char_length(body) <= 4000`. |
| `reminder_date` | timestamp NULL | If set, `cron/cron_notifications.php` raises a notification once that date and time has passed. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Creation time. |
| `updated_at` | timestamp NULL | Last edit; NULL if never edited. |
| `deleted_at` | timestamp NULL | Soft delete. |

Indexes: `(user_id, created_at)`, partial `(reminder_date) WHERE reminder_date IS NOT NULL`.

---

## 5. Notifications

### `spw_users_notifications` — per-user notification inbox

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `user_id` | int8 NOT NULL | Recipient (`spw_users.id`). |
| `title` | varchar(255) NOT NULL | Notification headline. |
| `link` | varchar(255) NULL | Target URL to open on click. |
| `source_table` | varchar(100) NULL | Table that triggered it. |
| `source_id` | int8 NULL | Record that triggered it. |
| `is_read` | bool DEFAULT false | Read flag, toggled from `api/notifications.php`. |
| `notify_date` | date NOT NULL | Date the notification is *for*. |
| `created_at` | timestamp DEFAULT CURRENT_TIMESTAMP | Generation time. |

UNIQUE `(user_id, source_table, source_id, notify_date)` — the deduplication key that makes the cron worker idempotent when it re-runs on the same day.

### `spw_users_notifications_log` — notification cron run history

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Run id. |
| `started_at` | timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP | Run start. |
| `finished_at` | timestamp NULL | Run end; NULL while running or after a crash. |
| `status` | varchar(20) NOT NULL DEFAULT `'running'` | `running` / `ok` / `error`. |
| `triggered_by` | varchar(20) NOT NULL DEFAULT `'cron'` | `cron` or a manual admin trigger. |
| `sources_processed` | int4 NULL | Number of configured notification sources scanned. |
| `notifications_created` | int4 NULL | Rows actually inserted into `spw_users_notifications`. |
| `error_message` | text NULL | Failure detail when `status = 'error'`. |

Index: `(started_at)`.

---

## 6. Automations

### `spw_automation_runs` — automation rule execution log

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `rule_id` | varchar(50) NOT NULL DEFAULT `''` | Rule identifier from the `automations` config key. |
| `rule_name` | varchar(255) NOT NULL DEFAULT `''` | Rule label captured at run time (survives later renames). |
| `table_name` | varchar(100) NOT NULL DEFAULT `''` | Table whose change fired the rule. |
| `record_id` | int4 NOT NULL DEFAULT 0 | Triggering record. |
| `event` | varchar(20) NOT NULL DEFAULT `''` | Trigger event — `insert` / `update` / `delete`. |
| `status` | varchar(20) NOT NULL DEFAULT `'ok'` | `ok` or `error`. |
| `error_msg` | text NULL | Failure detail. |
| `executed_at` | timestamp NOT NULL DEFAULT now() | Execution time. |

Indexes: `(rule_id, executed_at DESC)`, `(executed_at DESC)`.

### `spw_automation_emails` — outbound e-mail queue

Rows are enqueued by the automation engine (`includes/automations.php`) and delivered by `cron/cron_notifications.php`.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `rule_id` | varchar(50) NOT NULL DEFAULT `''` | Automation rule that queued the message. |
| `recipient` | varchar(255) NOT NULL | Destination address. |
| `subject` | varchar(255) NOT NULL | Rendered subject. |
| `body` | text NOT NULL DEFAULT `''` | Rendered body. |
| `source_table` | varchar(100) NOT NULL DEFAULT `''` | Table of the triggering record. |
| `record_id` | int4 NOT NULL DEFAULT 0 | Triggering record. |
| `created_by` | int4 NOT NULL DEFAULT 0 | User whose action queued it; `0` for system/cron. |
| `status` | varchar(20) NOT NULL DEFAULT `'pending'` | `pending` / `sent` / `error`. |
| `attempts` | int4 NOT NULL DEFAULT 0 | Delivery attempts, for retry back-off. |
| `error_msg` | text NULL | Last delivery error. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Enqueue time. |
| `sent_at` | timestamp NULL | Successful delivery time. |

Indexes: `(status, created_at)` (queue drain), `(rule_id, created_at DESC)`.

---

## 7. CSV import

### `spw_imports` — one row per CSV import run

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Import run id. |
| `user_id` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Who ran the import. |
| `filename` | varchar(255) NOT NULL | Uploaded file name as shown to the user. |
| `target_table` | varchar(100) NOT NULL | Destination table. |
| `status` | varchar(20) NOT NULL DEFAULT `'pending'` | `pending` / `running` / `done` / `error`. |
| `total_rows` | int4 NOT NULL DEFAULT 0 | Data rows found in the file. |
| `imported_rows` | int4 NOT NULL DEFAULT 0 | Rows inserted/updated. |
| `skipped_rows` | int4 NOT NULL DEFAULT 0 | Rows rejected — each detailed in `spw_import_rows_log`. |
| `column_mapping` | jsonb NULL | CSV column → table column map used for this run. |
| `conflict_column` | varchar(100) NULL | Column used for upsert matching; NULL for insert-only. |
| `error_message` | text NULL | Run-level failure detail. |
| `started_at` | timestamp NOT NULL DEFAULT now() | Start time. |
| `finished_at` | timestamp NULL | End time. |

Indexes: `(started_at)`, `(user_id)`.

### `spw_import_rows_log` — per-row rejection detail

| Column | Type | Description |
|---|---|---|
| `id` | bigserial PK | Surrogate key. |
| `import_id` | int4 NOT NULL → `spw_imports(id)` ON DELETE CASCADE | Parent run; detail dies with the run. |
| `row_number` | int4 NOT NULL | 1-based row number in the source file. |
| `raw_data` | jsonb NULL | The offending row as parsed, for diagnosis. |
| `error_message` | text NOT NULL | Why the row was skipped. |
| `logged_at` | timestamp NOT NULL DEFAULT now() | Log time. |

Index: `(import_id)`.

---

## 8. RAG knowledge base

### `spw_rag_files` — knowledge-base documents

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Document id. |
| `filename` | varchar(255) NOT NULL | Document name shown in the admin RAG tab and cited in answers. |
| `content` | text NOT NULL | Full plain-text content. |
| `tags` | text[] NOT NULL DEFAULT `'{}'` | Tags used to scope retrieval. |
| `file_size` | int4 NOT NULL DEFAULT 0 | Original size in bytes. |
| `uploaded_by` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Uploader. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Upload time. |

Indexes: GIN on `tags`; GIN full-text on `to_tsvector('english', content)` — the whole-document search path.

### `spw_rag_chunks` — retrieval units

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Chunk id. |
| `file_id` | int4 NOT NULL → `spw_rag_files(id)` ON DELETE CASCADE | Parent document. |
| `chunk_index` | int4 NOT NULL | 0-based position within the document; UNIQUE together with `file_id`, so re-chunking cannot duplicate. |
| `content` | text NOT NULL | Chunk text — this is what is actually retrieved and put into the prompt. |

Indexes: `(file_id)`; GIN full-text on `to_tsvector('english', content)`.

### `spw_rag_queries` — question log and usage metering

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Query id. |
| `query` | text NOT NULL | The user's question. |
| `tags` | text[] NOT NULL DEFAULT `'{}'` | Tag filter applied to retrieval. |
| `matched_files` | int4 NOT NULL DEFAULT 0 | Number of documents that matched. |
| `prompt_tokens` | int4 NOT NULL DEFAULT 0 | Tokens sent to the model. |
| `completion_tokens` | int4 NOT NULL DEFAULT 0 | Tokens returned. |
| `total_ms` | int4 NOT NULL DEFAULT 0 | End-to-end duration in milliseconds. |
| `model` | varchar(255) NOT NULL DEFAULT `''` | Model identifier used. |
| `user_id` | int4 NULL → `spw_users(id)` ON DELETE SET NULL | Asker. |
| `created_at` | timestamp NOT NULL DEFAULT now() | Ask time. |
| `prompt_snapshot` | text NULL | The fully assembled prompt, for debugging and reproducibility. |

Indexes: `(created_at)`, `(user_id)`.

### `spw_rag_query_sources` — citations for one query

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `query_id` | int4 NOT NULL → `spw_rag_queries(id)` ON DELETE CASCADE | Parent query. |
| `file_id` | int4 NOT NULL → `spw_rag_files(id)` ON DELETE CASCADE | Cited document. |
| `chunk_id` | int4 NULL → `spw_rag_chunks(id)` ON DELETE SET NULL | Cited chunk; NULL when the citation is whole-document or the chunk was later deleted. |
| `chunk_index` | int4 NOT NULL DEFAULT -1 | Chunk position captured at query time; `-1` means document-level citation. |
| `filename` | varchar(255) NOT NULL | Document name captured at query time (survives renames/deletes). |
| `snippet` | text NOT NULL DEFAULT `''` | The excerpt actually shown to the user. |
| `source_type` | varchar(10) NOT NULL DEFAULT `'file'` | `file` or `chunk` — which retrieval path produced the hit. |
| `rank_position` | int4 NOT NULL DEFAULT 0 | 0-based relevance rank within this query's results. |

Indexes: `(query_id)`, `(file_id)`.

---

## 9. Data anonymization (GDPR)

### `spw_anonymization_log` — anonymization run history

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Run id, referenced (loosely) by `spw_anonymization_report.log_id`. |
| `started_at` | timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP | Run start. |
| `finished_at` | timestamp NULL | Run end. |
| `status` | varchar(20) NOT NULL DEFAULT `'running'` | `running` / `ok` / `error`. |
| `triggered_by` | varchar(20) NOT NULL DEFAULT `'cron'` | `cron` or manual admin run. |
| `rules_processed` | int4 NULL | Anonymization rules evaluated. |
| `rows_anonymized` | int4 NULL | Rows whose columns were scrubbed. |
| `error_message` | text NULL | Failure detail. |

Index: `(started_at DESC)`.

### `spw_anonymization_report` — detailed per-run report

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `log_id` | int4 NULL | Matching `spw_anonymization_log.id` (no FK — reports may be kept after log pruning). |
| `report_id` | varchar(64) NOT NULL | Stable external identifier used to fetch/download the report. |
| `triggered_by` | varchar(20) NULL | Copied from the run for standalone readability. |
| `status` | varchar(20) NULL | Copied from the run. |
| `rows_affected` | int4 NULL | Total rows scrubbed in this run. |
| `report` | jsonb NOT NULL | Full breakdown: per table/column/rule counts, dry-run flag, timings. |
| `created_at` | timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP | Report creation time. |

Indexes: `(log_id)`, `(created_at DESC)`.

---

## 10. ETL

### `spw_etl_log` — single-job ETL run history

Written by `cron/cron_etl.php`; also the per-step target of flow runs.

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Run id. |
| `job_id` | varchar(64) NOT NULL DEFAULT `''` | Job identifier from the ETL config. |
| `job_name` | varchar(255) NOT NULL DEFAULT `''` | Job label captured at run time. |
| `triggered_by` | varchar(20) NOT NULL DEFAULT `'cron'` | `cron`, manual admin run, or a flow. |
| `status` | varchar(20) NOT NULL DEFAULT `'running'` | `running` / `ok` / `error`. |
| `rows_read` | int4 NULL | Rows read from the source (MySQL/PostgreSQL). |
| `rows_written` | int4 NULL | Rows written to the PostgreSQL target. |
| `error_message` | text NULL | Failure detail. |
| `started_at` | timestamp NOT NULL DEFAULT now() | Start time. |
| `finished_at` | timestamp NULL | End time. |

Indexes: `(started_at DESC)`, `(job_id, triggered_by, status, started_at)`.

### `spw_etl_flow_run_log` — flow (multi-step job chain) run history

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Flow run id; parent of `spw_etl_flow_step_log`. |
| `flow_id` | varchar(64) NOT NULL DEFAULT `''` | Flow identifier from the ETL config. |
| `flow_name` | varchar(255) NOT NULL DEFAULT `''` | Flow label captured at run time. |
| `triggered_by` | varchar(20) NOT NULL DEFAULT `'cron'` | `cron` or manual admin run. |
| `status` | varchar(20) NOT NULL DEFAULT `'running'` | `running` / `ok` / `error`. |
| `failed_step_index` | int4 NULL | 0-based index of the step that aborted the flow; NULL on success. |
| `error_message` | text NULL | Failure detail. |
| `started_at` | timestamp NOT NULL DEFAULT now() | Start time. |
| `finished_at` | timestamp NULL | End time. |

Index: `(flow_id, status, started_at)`.

### `spw_etl_flow_step_log` — per-step detail of a flow run

| Column | Type | Description |
|---|---|---|
| `id` | serial4 PK | Surrogate key. |
| `flow_run_id` | int4 NULL → `spw_etl_flow_run_log(id)` ON DELETE CASCADE | Parent flow run; step detail dies with it. |
| `flow_id` | varchar(64) NOT NULL DEFAULT `''` | Denormalized flow id for direct querying. |
| `step_index` | int4 NOT NULL DEFAULT 0 | 0-based position of the step in the flow. |
| `job_id` | varchar(64) NOT NULL DEFAULT `''` | ETL job executed by this step. |
| `job_name` | varchar(255) NOT NULL DEFAULT `''` | Job label captured at run time. |
| `status` | varchar(20) NOT NULL DEFAULT `'running'` | `running` / `ok` / `error`. |
| `rows_read` | int4 NULL | Rows read by the step. |
| `rows_written` | int4 NULL | Rows written by the step. |
| `error_message` | text NULL | Failure detail. |
| `started_at` | timestamp NOT NULL DEFAULT now() | Step start. |
| `finished_at` | timestamp NULL | Step end. |

Index: `(flow_run_id)`.
