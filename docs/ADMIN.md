# OpenSparrow - Admin Panel Documentation

> Generated from `public/admin/js/docs-strings.js` (English version). The admin-panel documentation page is the source of truth — regenerate this file after changing the strings.

Configure your frontend application, manage database connections, and build dynamic dashboards, calendars and workflows without writing a single line of code.

### 0. First-Run Setup

> **Fresh installation?** When `config/database.json` does not exist, every page (including `/admin`) automatically redirects to the first-run **setup wizard** at `/setup.php`. Follow these steps exactly:

1. **Welcome** — intro and requirements overview.
2. **Database Connection** — enter host, port, database name, user and password, then click **Test Connection** to verify before proceeding.
3. **Schema** — choose the PostgreSQL schema name (default: `app`). Optionally tick *Create schema if not exists*. The third checkbox, *Drop and recreate this schema*, is destructive (`DROP SCHEMA … CASCADE`) — leave it unticked unless you are deliberately wiping a scratch installation.
4. **Review & Initialize** — click **Initialize System Tables**. The wizard creates all `spw_*` tables, seeds the `admin` account with a **randomly generated password displayed once on this screen** (also written to the server error log) — copy it before leaving the page — and writes `config/database.json`.
5. You are redirected to `/login`. Log in as `admin` with the password from the wizard, then go to **System → Users**, find the *admin* row and click **Change pwd** to set your own password.

Once `config/database.json` exists, the setup wizard is permanently inaccessible — all entry points redirect to `/login` instead. A second, independent lock exists via the `SETUP_ENABLED` environment variable. If the file exists but the `spw_users` table is missing (e.g. the database was reset), `/admin` opens in a yellow-banner first-run mode instead: save the connection in **System → Settings → Database**, then run **System → Migrations → Apply Pending Migrations** — the first `admin` account is then created with a random password written to the server error log.

### 0b. Admin Panel Layout

The admin panel uses a collapsible left sidebar with five sections (Overview, Data Management, Workflows, AI, System). The header provides global actions.

- **Data Management:** Board, Calendar, Roadmap, CSV Import, Dashboard, ETL, Files, Printouts, Schema, User Records, Views. Add Table, Menu Preview, Schema Map and M2M Builder are inner tabs of Schema, not separate sidebar items.
- **Workflows:** Automations, Workflow Manager.
- **AI:** Centrum AI.
- **System:** Anonymization, API (External API), Backup Tables, Click Statistics, Cron Notifications, Demo Systems, Health Check, Migrations, Performance, Settings, Sharing, Users. Database and Audit & Snapshots are inner tabs of Settings.
- **Automatic saving:** Config-editing tabs (Schema, Dashboard, Calendar, Board, Roadmap, Workflows, Views, User Records, Files, Printouts) save automatically — about a second after your last edit, when switching tabs and when the page is hidden. Each save is versioned: a concurrent edit by another admin is rejected with a conflict pill instead of silently overwriting their changes. A validation error (e.g. an unfinished workflow) blocks the save and shows an error pill — the edit stays in the form until fixed. `database` and `security` stay files on disk with their own Save button (edited from **System → Settings → Database**).
- **Unsaved-changes guard:** Shows a confirmation prompt only when a change could not be saved (validation error, conflict or network failure) or when a save is still in flight while switching tabs. Tabs that save immediately via API (Users, Database, Health, Backup) never trigger this warning.
- **Debug FE mode:** Toggle in the header. When enabled, the frontend exposes a `#debug` panel with raw payloads for schema/API responses — useful when building new tables or troubleshooting grids.
- **Docs icon (book):** Opens this documentation page.

### 1. Technical Requirements & Database Structure

Before configuring OpenSparrow, make sure your PostgreSQL database meets these core requirements:

- **Primary keys (mandatory):** Every table **must** have a primary key column named `id` (typically `SERIAL` or `BIGSERIAL`). OpenSparrow relies on this exact column name to edit, delete, and view specific records.
- **Foreign keys (relationships):** Use standard PostgreSQL foreign keys to link tables. The UI detects them automatically. Recommended naming convention: `table_name_id` (e.g. `company_id`).
- **ENUM types:** Custom PostgreSQL ENUM types are fully supported and rendered as `<select>` menus in the frontend.
- **Boolean types:** Boolean columns render as switch toggles in edit forms and as dropdown filters in data grids.
- **System schema:** OpenSparrow stores its internal tables (`spw_*`) in a dedicated PostgreSQL schema (default: `app`). The schema is resolved in this order: `schema` key in `config/database.json`, then the `PGSCHEMA` environment variable, then the `app` fallback.

#### System tables (`spw_*` prefix)

OpenSparrow reserves a set of internal tables, all prefixed with `spw_` and created inside the configured system schema:

- `spw_users` — frontend user accounts (id, username, password hash, role, active flag, optional contact details).
- `spw_users_log` — audit trail of user actions (LOGIN, LOGOUT, CRUD operations).
- `spw_users_notifications` — per-user in-app notifications, produced by the cron runner.
- `spw_users_notifications_log` — execution log for the notifications cron: start/end time, status (`running` / `success` / `error`), trigger source (`cron` or `admin`), number of sources processed and notifications created, and error message on failure.
- `spw_files` — metadata for files uploaded through the Files module.
- `spw_login_attempts` — rolling log used by the DB-backed rate limiter on `login.php` (IP-hash and username counters).
- `spw_comments` — user comments attached to any record. Each row links to a specific record via `related_table` + `related_id`, stores the author (`user_id`), body text (max 4000 chars), and a soft-delete timestamp.
- `spw_record_snapshots` — JSONB snapshots of records captured after every INSERT or UPDATE. Each row is linked to the corresponding `spw_users_log` entry via `log_id` (CASCADE DELETE). Only active when the Record Snapshots module is enabled (see section 9b).
- `spw_record_owners` — append-only ownership log. Each row records who owns a specific record (`table_name` + `record_id`) and who made the change (`changed_by`). The current owner is the row where `is_current = true`; all previous rows form the full ownership history. Created automatically on INSERT; reassignable by editors via the Record Owner panel in the edit view (see section 9c).
- `spw_migrations` — migration tracker. One row per applied migration name + timestamp. Bootstrapped automatically by the first run of *Apply Pending Migrations*. Used by *System → Migrations* to determine which schema changes have already been applied.
- `spw_release_migrations` — release migrations already applied from `config/migrations.json` — one row per version.
- `spw_config` — the application configuration store — one JSONB row per key (schema, menu, settings, dashboard, calendar, board, workflows, automations, views, files, print, anonymization, user_records, user_policy, user_table_access, rag, etl, etl_flows, clickstats), versioned with optimistic locking.
- `spw_config_log` — full change history of the configuration store: the previous value of each key, who changed it and when.
- `spw_notes` — private user notes, optionally linked to a record and carrying a reminder date delivered by the notifications cron.
- `spw_rag_*` — knowledge base storage: documents (`spw_rag_files`), their full-text chunks (`spw_rag_chunks`) and the query history (`spw_rag_queries`, `spw_rag_query_sources`).
- `spw_automation_*` — execution history of automation rules (`spw_automation_runs`) and the outgoing e-mail queue (`spw_automation_emails`).
- `spw_imports` — CSV import runs (`spw_imports`) with per-row detail in `spw_import_rows_log`.
- `spw_etl_*` — ETL job run history (`spw_etl_log`) plus flow runs and their per-step detail (`spw_etl_flow_run_log`, `spw_etl_flow_step_log`).
- `spw_anonymization_*` — anonymization run history (`spw_anonymization_log`) and the generated GDPR/EDPB compliance reports (`spw_anonymization_report`).
- `spw_clickstats` — recorded UI clicks (user, time, element, and optionally the table and record in context). Only written while the Click Statistics module is enabled.

> **Note:** Tables starting with `spw_` are treated as system tables. They are **filtered out** from the *Sync DB Tables* list in the Schema tab and will not appear in your application schema, even if they live in the same PostgreSQL schema as your business tables.

### 2. Schema & Grid Configuration

The **Schema** tab is the core of your configuration. It maps your database tables to frontend grids and forms.

#### Add Table (Data Management → Add Table)

The dedicated **Add Table** tab creates a new physical PostgreSQL table and optionally registers it in `spw_config.schema` — no manual sync step required.

- **Table Name & Database Schema:** Lowercase identifiers only. The `id serial PRIMARY KEY` column is always added automatically.
- **Display Name:** Auto-filled from the table name (underscores → spaces, title-case). Used as the label in menus and headings when registered in `spw_config.schema`.
- **Column Presets:** Check *Timestamps* to automatically add `created_at` and `updated_at` columns (`timestamp DEFAULT now() NOT NULL`).
- **Per-column options** — `varchar(255)`, `text`, `int4`, `int8`, `boolean`, `date`, `timestamp`. Not Null adds a `NOT NULL` constraint. Index: `btree`, `hash`, or `unique`. Comment stored as `COMMENT ON COLUMN`.
- **Register in app schema** (checked by default): After the table and all columns are created in the database, the table entry is written to `spw_config.schema` automatically.

- **Add new columns (Schema tab):** Inside an existing table's configuration, click *+ Add Column* to append a physical column to the database.
- **Sync DB Tables:** Fetches all tables from the connected database and merges them into your schema configuration. System tables with the `spw_` prefix are skipped automatically.
- **Sync Columns from DB:** Inside a table's configuration, fetches all columns for that table and adds any that are missing. Also reads PostgreSQL `COMMENT ON COLUMN` values for descriptions.
- **Column Description (tooltip):** Appears as a native browser tooltip when hovering over column headers in the data grid.
- **Live sidebar preview:** Updates in real time when editing Display Name, Icon, or Hide flag.
- **Icon picker:** *Browse* opens a searchable picker holding the project icons from `assets/icons/` plus the full Material Symbols Outlined set from `assets/icons/material/`. Search matches icon names and the Google tag list, understands common Polish words (`faktura`, `magazyn`, `wózek`), and category chips narrow the list further. Recently picked icons are remembered in the browser. The Material set is optional: it is fetched by `scripts/fetch-material-icons.php` and the picker simply omits that section when the folder is absent.
- **Smart type mapping:** Native PostgreSQL types are mapped to clean frontend types (Text, Number, Date, Timestamp, Boolean, Enum); a column defined as an enum in the database also imports its option list automatically.
- **Virtual (computed) columns:** A column can be declared *virtual* — it exists only in the configuration, not in the database. It computes a value from other non-virtual columns of the same record: sum, subtract, multiply, divide, average, or text concatenation (with a custom separator). Virtual columns can be shown in the grid but never in the edit form.
- **Enum colors:** Each enum option can carry a color. These colors drive lane tints in the Board module, event chips and any other enum-driven UI — define them once in the Schema editor and every module picks them up.
- **Highlight rules:** Per-table rules that color an entire grid row when the chosen column matches a condition (`==`, `!=`, `>`, `>=`, `<`, `<=`, `contains`). Rules are evaluated in order and the first match wins; a rule sets both the condition and the row background color.
- **Required id column:** Every table needs a column named `id` (serial primary key). The editor shows a red warning with the exact SQL to fix it when a configured table is missing one, and marks the column read-only automatically.
- **Remove tables:** Removes a table from JSON configuration only — does not drop the physical table.
- **Foreign-key search & display:** Assign multiple display columns to a foreign key. The frontend renders them as searchable inputs.
- **Visibility & ordering:** Toggle per-column visibility in the grid and reorder with Up/Down arrows.
- **Validation rules (regex):** Enforce strict formats with regular expressions.

  - **Email:** `^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$`
  - **Phone (9-15 digits, optional +):** `^\+?[0-9]{9,15}$`
  - **Postal code (XX-XXX):** `^[0-9]{2}-[0-9]{3}$`
  - **URL (http/https):** `^https?:\/\/.*$`
  - **Username (3-16 chars):** `^[a-zA-Z0-9_]{3,16}$`
  - **Price / decimal:** `^\d+(\.\d{1,2})?$`
  - **Date (YYYY-MM-DD):** `^\d{4}-\d{2}-\d{2}$`
  - **Strong password:** `^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$`

#### Subtables (one-to-many relationships)

**+ Add Subtable** displays related child records inside the parent record's detail view.

- Open the Schema editor of the parent table and click *+ Add Subtable*.
- **Target table:** the child table to display.
- **Foreign-key column:** the column in the child table referencing the parent's `id`.

#### Record images

Per-table image galleries, enabled per table on the Schema tab. Each record can hold multiple photos, uploaded from its edit form and previewed as thumbnails in the grid.

- **Enable:** toggle images on for the table; the config is stored alongside the table's schema (`tables[table].images`).
- **Max per record:** limit between 1 and 50 images per record.
- **Show in grid:** when on, the grid shows a thumbnail column (up to 4 previews) with a hover popup for larger previews; click opens the full image in a new tab.
- **Upload:** images are uploaded and deleted from an Images tab on the record's edit form (not available on create — save the record first); a workflow step whose table has a gallery also offers an upload field, attaching the photo once that step's record is saved. They use the same storage and size/extension limits as the Files module.

### 3. Dashboard Builder

The **Dashboard** tab composes analytical views. The layout uses a fixed **3-column grid**; each widget occupies 1, 2, or 3 columns.

#### Widget types

- **Stat Card:** Colored tile showing a single aggregate number (COUNT, SUM, or AVG) of a selected table/column.
- **Bar Chart (Horizontal / Vertical):** Groups rows by a chosen column. Each bar is clickable.
- **Pie Chart:** Same group/aggregate model, rendered as a conic-gradient pie. Each slice is clickable.
- **Line Chart (Time Series):** Plots a value over time instead of grouping by a category. Configure a time axis column (X), a granularity (Day / Week / Month / Year), an aggregation column (Y) and its function (Count / Sum / Average). An optional *Fill area under line* checkbox shades the area below the line.
- **Data List:** Top-N rows ordered by a selected column.

#### Widget proportions

- **Width:** `1/3`, `2/3`, `3/3`.
- **Height:** Small (140 px), Medium (280 px), Large (440 px).

On mobile screens all widgets collapse to a single column.

#### Filter Conditions (WHERE)

Every widget supports structured filter conditions combined with AND/OR. Column names are validated against the schema; values are escaped.

- Available operators: `=`, `!=`, ``, `=`, `LIKE`, `ILIKE`, `IS NULL`, `IS NOT NULL`.

#### Global period filter and trends

The dashboard header offers a period selector (All time / Today / Last 7 days / Last 30 days / This month). It filters every widget by its first date column; count, sum and average cards additionally show a percentage change versus the equally long previous period.

Users with export permission see a CSV button on each widget (on hover) that downloads the currently displayed data — no server round-trip, respects the active period filter.

- **Drill-down:** Clickable bars, slices and list rows open the source table's grid pre-filtered to the clicked value (or, on time-series points, to the bucket's date range), so every chart doubles as a navigation shortcut.
- **Calculate (real data):** Next to the mock Live Preview, the editor has a *Calculate (real data)* button that runs the widget's actual query against the current table and prints the raw result as JSON — the fastest way to verify conditions and aggregation before saving.
- **Per-widget visibility:** Widgets are bound to their source table's per-user `tables` grants: a user without access to a table simply does not get that widget, and no data from it is sent to their browser.

#### Live Preview

A **Live Preview** panel renders the actual widget with sample data and updates automatically. Uses fixed mock data — no database connection required.

#### Global settings

Controls dashboard menu entry and grid gap. Defaults to `20px`.

### 4. Calendar Module

The **Calendar** tab binds date columns from your database to a visual calendar. Each source maps one table's date column to events; configuration lives in `spw_config.calendar` and the data is served by the frontend API (`api.php?api=calendar`).

- **Data sources:** Overlay multiple tables on a single calendar. Each source picks the table, the date column (required), the title column and an optional subtitle column shown next to the event title. Only sources with both a table and a date column reach the frontend.
- **Color coding:** Assign a color per source, plus an optional event icon (an image path or a single character rendered before the title).
- **Row context:** The full database row is attached to each calendar event — it powers the hover tooltip and the search, which matches the title, id and every column value. Foreign-key values appear as their display label.
- **Month view:** The frontend renders one month at a time. Editors can drag an event to another day (which updates the date column, validated and audit-logged as `CALENDAR_MOVE`), delete an event straight from the calendar (deletes the record, with a confirmation), and use the `+` button on a day to start a new record in the chosen source with the date prefilled. Clicking an event opens it in the record editor. Sources can be toggled on and off with per-table filter chips persisted in `localStorage`. Owner-restricted tables show only the user's own records, and moves respect record ownership.
- **Reminders:** A source can notify selected users N days ahead of the date (*Notify Before*). The notifications cron scans for records whose date falls exactly N days from today and inserts in-app notifications under the bell icon for each configured (active) user — deduplicated, so one record produces at most one notification per user per day. Requires the cron to be scheduled.
- **Notification link:** The *URL Template* is the link attached to a reminder notification; `{id}` is replaced with the record id (e.g. `edit.php?table=invoices&id={id}`).
- **Access:** There is no separate `calendar` grant scope — who sees which events follows the per-user `tables` grants of each source's table: a user without access to a table never sees its events, and an owner-restricted table contributes only records the user owns.

### 4b. Board (Kanban) Module

The **Board** tab turns a table into a Kanban board: records become cards laid out in lanes, one lane per value of a status column. Dragging a card to another lane instantly updates that column.

- **Multiple boards:** The tab holds a named list of boards (`spw_config.board`, the `boards` array), not a single one. Each board is configured independently and gets its own frontend menu entry, so one installation can run several boards over different tables.
- **Source table:** Per board, select the one table whose records are shown as cards.
- **Status column:** The most important setting — its values become the board lanes. An `enum` column is recommended so lanes and their colors come from `enum_colors`; for non-enum columns lanes are derived from the existing distinct values.
- **Card content:** Choose the title column and optional detail fields shown on every card; foreign keys appear as their display label.
- **Drag & drop:** Available to the editor role. Moves are validated against the allowed value set, respect record ownership, and are audit-logged (`BOARD_MOVE`).
- **Per-user visibility:** Boards follow the per-user `boards` grants: a user restricted in the Users module only sees the boards they were granted, and opening a board's page or data endpoint re-checks access independently. Granting a board does not grant its table — the source table needs its own `tables` grant, and without it the board renders empty. An empty grant list means unrestricted.
- **Owner-restricted tables:** On tables with the owner restriction enabled, each user only gets cards of records they own in both directions: lanes are derived from their own rows' statuses, and moving a card is additionally verified against record ownership before the update.
- **The board page:** The frontend board offers a text search (title, id, detail fields), per-lane show/hide chips persisted in `localStorage`, a card tooltip with the full record, and clicking a card opens it in the record editor. Moves are applied optimistically and rolled back on failure. Records whose status value is no longer among the lanes fall into an "Uncategorized" lane instead of disappearing.

### 4c. Roadmap (Gantt) Module

The **Roadmap** tab turns a table into a Gantt-style timeline: records become bars laid out over monthly columns, with a today marker, quarter shading and a color legend. Each roadmap is a read-only view — clicking a bar opens the record for editing.

- **Multiple roadmaps:** The tab holds a named list of roadmaps (`spw_config.roadmap`, the `roadmaps` array), not a single one. Each roadmap is configured independently and gets its own frontend menu entry, so one installation can run several roadmaps over different tables.
- **Source table:** Per roadmap, select the one table whose records are shown as bars, plus the task title column and optional detail fields shown in the bar tooltip.
- **Date columns:** The start and end date columns drive the bar placement. Only `date` and `timestamp` columns are offered; both are required before the roadmap appears in the sidebar.
- **Category column:** Optional grouping that colors the bars. An `enum` column is recommended so bar colors come from `enum_colors`; for non-enum columns all bars use the configured default color, and enum values seen in the data are added to the legend automatically.
- **Progress column:** Optional numeric column (0–100) rendered as an overlay inside each bar. Values are clamped to the 0–100 range; a record without a value shows a plain bar.
- **Milestones:** Automatic — a record whose start and end dates are equal renders as a milestone diamond instead of a bar. No extra column is needed.
- **Read-only view:** Bars are not draggable — the roadmap is a planning overview, not an editor. Per-user access follows the same model as boards: a roadmap grant (`roadmaps` scope in Users → Access) is independent of its table grant, and the binding is hidden from users who cannot open the table.

### 5. Workflows Builder

The **Workflows** tab composes multi-step wizards guiding users through structured data entry across related tables.

- **Steps setup:** Add sequential steps and select a target table per step.
- **Relational linking:** Link child records to parents by selecting the foreign-key column, and — with *Link to ID from Step* — which earlier step supplies the parent id. At save time the id of the record created in that earlier step is written into the foreign-key column automatically.
- **Multiple records:** Enable *Allow adding multiple records* per step.
- **Access:** Workflows follow the per-user `workflows` grants, but that is not enough on its own: every step's table must also be within the user's `tables` grants. A workflow with even one step on a table the user cannot access is hidden entirely — steps cannot leak records from restricted tables.

#### Step Validation

A workflow cannot be saved if any step is missing a **name** or **target table**, or if the workflow has no steps at all. Incomplete steps are highlighted with a red left border and "— incomplete" label in the editor. The Save button is blocked until all steps are complete.

#### Stored Procedure on Next Step

Any step can call a PostgreSQL **procedure** when the user clicks *Next step*. Pick the schema and procedure from a dropdown listing the database's stored procedures; one row then appears per declared `IN` parameter, each fed either from a workflow field (choose the source step and column — the current step or any earlier one) or from a fixed value typed in by hand. The procedure runs **before** the wizard advances and receives only form values, never record ids — nothing is written to the database until the final review screen. If the procedure raises an exception, its message is shown to the user and the wizard stays on the current step, so a procedure can act as a server-side validation gate. Configuration is stored per step under `steps[].procedure`.

### 6. Users Management

The **Users** tab (*System → Users*) manages all accounts across four inner tabs: *Manage Users* (list, roles, activate/deactivate, per-user password change, optional contact details), *Access* (per-user frontend grants — see section 9o), *Statistics* (total/active/inactive counts, per-role breakdown, last 10 account-related audit entries) and *Global Settings* (org-wide password policy and default role).

- **Roles:**

  - **Admin** — access to this admin panel only. Cannot log in to the frontend.
  - **Editor** — full CRUD access to the frontend. Cannot access the admin panel.
  - **Viewer** — read-only access to the frontend. Cannot access the admin panel.
- **Change password:** Own account requires current password. Other accounts: admin override, no current password needed. The minimum length comes from the Global Settings tab and can never drop below the `PASSWORD_MIN_LENGTH` environment variable.
- **Active status:** Toggle Active / Inactive to revoke or restore login access.

### 7. Database Configuration

Manage the core PostgreSQL connection from **System → Settings → Database** (an inner tab of Settings, not a separate sidebar item).

- **System Schema:** Sets the PostgreSQL schema used for all `spw_*` tables. Defaults to `app`.
- **Test Saved Connection:** Always click *Save configuration* first — the test reads persisted `database.json`, not in-form values.
- **Login protection:** DB-backed rate limiter, CSRF tokens, session fingerprinting, 8-hour session lifetime, `SameSite=Lax` / `HttpOnly` cookies.

### 8. Backup Tables

**System → Backup Tables** creates timestamped copies of selected tables directly in PostgreSQL, in the same schema as the original. Tables are grouped into three tabs: *Application Tables* (from your schema configuration), *System Tables (spw_*)* (discovered live from the database) and *Global Settings* (`spw_config` + `spw_config_log`, the configuration store and its history). Each tab has *Select all* / *Deselect all* and its own *Backup selected tables* button.

- **Backup name format:** `YYYYMMDDHHII_tablename`.
- **Shared timestamp:** All tables backed up in one run share the same *minute-precision* prefix, so a multi-table snapshot is easy to recognise later.
- **What is copied:** Column structure and all data rows. Indexes and constraints are **not** copied. The backup runs per table: a failure on one table does not stop the others, and each result is reported individually (backup name + row count, or the error message). There is no restore function in the panel — backups stay in the same schema as regular tables, so a restore is a manual `INSERT ... SELECT` or a copy back with your database tool of choice.

### 9. System Health, Cron & Config

- **Database Migrations:** *System → Migrations*. Click **Apply Pending Migrations** to run all unapplied schema changes. Each migration runs once and is recorded in `spw_migrations`; the button is safe to repeat. The run also prunes obsolete migration rows and, when the users table is empty, creates the first `admin` account with a random password written to the server error log — change it immediately after login.
- **System diagnostics:** Two tabs: **Environment** — live checks for the hosting server: PHP version (≥ 8.4), `memory_limit` (≥ 64M), `upload_max_filesize` (≥ 8M), `display_errors` off, required PHP extensions (pgsql, json, session, mbstring, fileinfo, openssl), security functions (`PASSWORD_ARGON2ID`, `random_bytes()`, `hash_equals()`), DB connectivity with the PostgreSQL version, write permissions (`config/`, `storage/`, `storage/files/`) and config files (`database.json`, schema config); and **Production Readiness** — `APP_ENV`, DEMO_MODE, SECURE_COOKIES, HTTPS detection (direct or via proxy headers), `PASSWORD_MIN_LENGTH` (≥ 12), rate limiting, `SESSION_SAMESITE`, trusted proxy IPs, and pending database migrations.
- **Run Notifications Cron:** Executes `cron/cron_notifications.php` ad-hoc. Each run is recorded in `spw_users_notifications_log`.

### 9b. Audit & Record Snapshots

**Settings → Audit & Snapshots** (an inner tab of the Settings tab, not a separate sidebar item) controls the record snapshot module — captures full record state after every write.

- **How it works:** Every INSERT and UPDATE saves a JSONB copy to `spw_record_snapshots`, linked to `spw_users_log` via `log_id`.
- **Toggle:** Writes to `spw_config.settings`. Takes effect on the next request. Overridden by `RECORD_SNAPSHOTS_ENABLED` env var.
- **Storage growth:** Monitor table size and implement a retention policy for high-volume installations.

### 9c. Database Migrations

**System → Migrations** has two tabs: *Database Migrations* applies and tracks schema changes to `spw_*` system tables (registry pinned by tests in four places), and *Release Migrations* applies the file/config cleanup tasks from `config/migrations.json` after an upgrade — see section 17.

- Applied migrations are recorded in `spw_migrations`. Re-running is always safe.
- **Adding migrations (developers):** Append to the `$migrations` array in `includes/admin/migrations.php` (`init_db` action) and mirror the new key in the `$known` list in the same file, in `$knownMigrations` in `includes/admin/overview.php`, and as an `INSERT` row in `public/setup_api.php` — same order everywhere, and `3.0_baseline` must stay first. Never modify existing entries.

### 9d. Record Ownership

Every record can have an **owner** tracked in `spw_record_owners`.

- **Auto-assignment:** Creator is automatically set as owner on INSERT.
- **Changing the owner:** Editor/Admin role: select user and click *Change Owner* in the Record History tab.
- **Full history:** Every change appends a row. No data is deleted.

### 9o. Per-User Frontend Access

The **Users → Access** tab restricts a frontend user to a subset of the schema tables, the configured views, the print templates, the boards, the roadmaps and the workflows. The groups are independent, and all of it is independent of the role: a Viewer limited to two tables still sees those two read-only.

- **Where it is stored:** The `user_table_access` key in `spw_config`, shaped as `{"users": {"<id>": {"tables": [], "views": [], "prints": [], "boards": [], "roadmaps": [], "workflows": []}}}`. Views and printouts are granted by name, boards, roadmaps and workflows by their id — none of those configs binds to a single table the way a grid page does.
- **Empty selection:** In any group it means **no restriction** for that group, not "no access" — every existing account keeps full access until you tick entries. To cut a user off entirely, deactivate the account instead.
- **Admin accounts:** Never restricted and not listed in the tab — they work in this panel and must see the whole schema.
- **What it covers:** Menu, grid, create/edit pages, mass edit, data cleanup, files, RAG context, dashboard widgets, calendar sources, boards, roadmaps, the Views page and the Printouts page. Subtable tabs in `edit.php` are filtered too, because a tab shows whole rows of the child table. Enforcement is server-side on every endpoint; hiding menu entries is only cosmetic.
- **Hidden helper tables:** Tables marked *hidden* have no menu entry and no grid of their own, so they are not listed in the tab. They are granted automatically together with the table whose subtable they are, transitively — otherwise restricting a user would silently remove subtable tabs nobody could give back. The Tables group names them under the list as you tick parents. Visible subtables are never granted automatically: those stay your explicit choice.
- **Boards, roadmaps and workflows still need their tables:** Granting a board, a roadmap or a workflow does not grant the tables it uses — the two are ticked separately and both have to hold. A board or roadmap whose table you did not grant is not listed, and a workflow is dropped when any of its steps targets a table the user cannot reach. So when you grant one of these, grant its tables as well, or the entry simply will not appear. This is on purpose: a board, a roadmap or a workflow is a way of looking at data, never a way around who may see it.
- **Views and printouts read past the boundary:** A view or printout a user may open can select from tables they have no access to. The query lives in the configuration and is not derived from the table grants, so granting the view grants everything that view reads — table access does not filter inside it. Treat the two as separate decisions: before granting a view or a print template, check what it actually selects. If one mixes data meant for different audiences, split it into per-audience views rather than expecting table access to mask part of it.
- **Known limits:** Foreign-key labels still resolve across the boundary — a permitted table may display a name from a restricted one. That is the line: a foreign key exposes a name, a subtable exposes rows, which is why subtables are filtered and FK labels are not.

### 9p. User Contact Details

Each account can carry a first name, last name, email address and phone number. The data is informational only — it is not used for login, notifications or any frontend display.

- **Where:** Set them in **Users → Manage Users**, either in the "Add New User" form or via the "Edit Details" button on a row. They are shown in the Name / Email / Phone columns.
- **Optional:** All four fields may stay empty (an empty cell renders as a dash). The email is only checked for a valid format and is not required to be unique.
- **Scope:** Admin panel only. Nothing on the frontend reads these columns, and they are never exposed through the public API.

### 9e. Grid Default Sort & Load Limit

- **Default Sort Order:** One or more sort rules (column + ASC/DESC). Fallback is `id DESC`.
- **Initial Load Limit:** Max rows on first load. `0` means unlimited.
- **Stored in:** `spw_config.schema` as `"default_sort"` and `"initial_limit"`.

### 9f. Grid Drilldown — Quick Add

Subtable block headers show a **+** button that navigates to `create.php` pre-filling the foreign key. Visible to Editor and Admin only.

### 9f2. Grid Action Buttons

The last grid column holds a `material/more_vert.svg` overflow button; clicking it opens a small panel with the row actions. Opening one row's panel closes any other.

- **Edit** — `material/edit_square.svg` icon, navigates to `edit.php`.
- **Duplicate** — `material/content_copy.svg` icon, clones the row via `mass_duplicate` and reloads the grid. No confirmation.
- **Delete** — `delete.png` icon (red hover), requires confirmation.
- The whole actions column is rendered only for roles that may write — in practice the **Editor** role, since Admin accounts are redirected from the frontend to the admin panel. Viewers get a read-only grid with no actions column.

### 9g. Performance Tab

**Admin → System → Performance** — read-only diagnostic panel with six independent sections, each with a *Scan* button, plus *Run All* to fire every scan at once. Recommended fixes come with *Copy SQL* buttons (per suggestion, per table, or all at once) — nothing is executed for you.

- **1. Missing Index Advisor:** Finds columns lacking indexes. Candidates come from your configuration: foreign key columns, subtable join columns, default sort columns, and widget filters / ORDER BY / GROUP BY. High priority for FK and subtable joins, medium for the rest. Generates ready-to-run `CREATE INDEX` SQL.
- **2. Unused Indexes:** Indexes with `idx_scan = 0` — candidates for removal to speed up writes. UNIQUE indexes and primary keys are excluded, so suggested drops never break integrity constraints. Generates `DROP INDEX` SQL; verify before dropping.
- **3. Slow Query Analyzer:** Top 15 queries by average execution time from `pg_stat_statements` (the tab shows the `CREATE EXTENSION` command when the extension is missing). Averages over 500 ms are highlighted red, over 100 ms muted.
- **4. Table Statistics & Bloat:** Dead rows, bloat %, scan counts, last vacuum timestamps.
- **5. Database Health:** Cache hit ratio, connections, deadlocks, DB size, PG version.
- **6. Schema Configuration Warnings:** Configuration issues ranked by severity: tables >20 columns (medium), >5000 rows with no Initial Load Limit (high), >1000 rows with no Default Sort (low), subtables without columns_to_show (medium), and list widgets with no row limit on large tables (medium).

### 9h. Cron Notifications Tab

**Admin → System → Cron Notifications** — seven-section management interface for the notification cron.

- **1. Manual Run:** Execute `cron/cron_notifications.php` immediately. One run does more than calendar notifications: it also delivers due note reminders, flushes the automation email queue and performs retention housekeeping (expired click statistics, login attempts older than 30 days).
- **2. Run History:** Last 50 entries from `spw_users_notifications_log`.
- **3. Notification Stats:** Total, unread, due today, upcoming. Top 10 users by unread count.
- **4. Cron Setup:** Ready-to-copy commands for Linux/macOS, Windows Task Scheduler, Docker.
- **5. Log Cleanup:** Purge `spw_users_notifications_log` rows older than N days.
- **6. Email:** "From" address for queued automation emails, plus optional authenticated SMTP delivery (host, port, encryption: STARTTLS / SSL / none, username, password) with a "Test SMTP Connection" button. The password is stored encrypted and never shown back in the UI. When the `AUTOMATION_EMAIL_FROM` environment variable is set, it takes precedence and the "From" field is disabled in the UI. Email delivery only happens when that address is configured (by env or here) — otherwise the cron skips sending and queued emails stay `pending`.
- **7. Email Queue:** Listing of outgoing automation emails stored in `spw_automation_emails`. The list shows recipient, subject, status (`pending` / `sent` / `error`), attempts, error message and timestamps, filterable by status, with per-status counts above the table. The cron picks up pending emails in batches (50 per run by default) and gives up on a message after 3 failed attempts, marking it `error`. Individual rows or a bulk selection can be **Requeue**d (status back to `pending`, attempts reset to 0, error cleared) or **Delete**d. The *Purge Queue* card below the list deletes all emails of a chosen status, optionally only those older than a number of days — useful for clearing stale `sent`/`error` entries once the retention window has passed.

### 9i. Grid Page Size

- **Admin default:** Schema tab → *Global Grid Settings* → *Default Page Size* (10 / 25 / 50 / 100).
- **User override:** *Rows per page* selector in the pagination bar, saved to `localStorage`.
- **Priority:** `localStorage` → `schema.default_page_size` → fallback 25.

### 9j. Many-to-Many Relationships

M2M relationships link records across tables via a **junction table**. Rendered as a checkbox panel in edit/create forms.

#### How it works end-to-end

1. **Create the junction table in PostgreSQL:** with two FK columns and a UNIQUE constraint.
2. **Configure the relationship:** Schema → parent table → *Many-to-Many Relationships* → **+ Add Many-to-Many**.
3. **Automatic save:** — the checkbox panel appears automatically about a second later.

#### Admin configuration fields

- **Display Label, Junction Table, Self FK, Other FK, Other Table, Display Column.**

#### Runtime behaviour

- On Save: all existing junction rows deleted and new rows inserted atomically in a single PostgreSQL transaction.
- Viewer role sees checkboxes in disabled state.

### 9k. Schema Map (ERD)

**Data Management → Schema → Schema Map** renders an interactive ERD from `spw_config.schema`. Uses force-directed auto-layout. No external libraries.

- **Connection types:** Foreign key (solid grey), Subtable (dashed navy), Many-to-many (dotted grey) — matching the on-canvas legend. A counter above the canvas reports how many tables and how many links of each kind were drawn.
- **Controls:** Pan, zoom, drag tables, click to highlight, search, show/hide hidden tables, Fit View, Export PNG.

### 9l. Mass Edit & Quick Data Cleanup

Two bulk tools available to the **editor** role directly from the data grid. Both always show a preview before anything is written, and every applied change is recorded in `spw_users_log`.

- **Mass Edit:** Select rows in the grid, pick one column and a new value, preview the affected rows, then apply. Backed by `api/mass_edit.php` (`mass_edit_preview` / `mass_edit_apply`); the table and column are validated against `spw_config.schema` and the update runs as a parameterized query.
- **Mass Duplicate / Mass Delete:** The same endpoint also serves `mass_duplicate` (clone the selected records) and `mass_delete` (remove them), both scoped strictly to the explicitly selected record IDs.
- **Quick Data Cleanup:** Find-and-replace across a single column of one table (`api/data_cleanup.php`). Options: case-insensitive, whole word only, and ignore accents. Preview reports the number of matching rows and sample values before *Apply* rewrites them.
- **Permissions:** Both endpoints require the `editor` role and a valid CSRF token; viewers cannot reach them.

### 9m. Data Anonymization (GDPR)

**System → Anonymization** — automates replacing PII column values with a configured replacement string. Truly anonymised data falls outside EU data protection law (GDPR). Config stored in `spw_config.anonymization`; run history in `spw_anonymization_log`. Every run writes a GDPR/EDPB compliance report (JSON, stored in `spw_anonymization_report`) that can be expanded inline in the history and downloaded.

- **Rules tab:** Define rules per column: select the table, a date/timestamp column and an age threshold (anonymize records older than N days), the PII column, and the replacement value (e.g. `***ANONYMIZED***`) — the *Add Rule* card sits below the rules list. The *Preview (dry run)* button counts how many rows each rule would anonymize without modifying any data — look before you run.
- **Schedule tab:** Enable/disable the module and set frequency: *Manual only*, *Daily*, *Weekly*, or *Monthly*. The cron script (`cron/cron_anonymization.php`) enforces the window by checking the last successful run. *Run Now* triggers immediately, bypassing the frequency check — but it still requires the module to be enabled; only *Preview (dry run)* works with the module off. Cron Setup Guide provides ready-to-copy commands for Linux, Windows and Docker.
- **Suggestions tab:** *Scan Schema* cross-references your schema with the dictionary keywords — matching both column names and display names, case-insensitively — and lists potential PII columns with matched keywords. Click *+ Add Rule* next to any match to create a rule directly.
- **Dictionary tab:** Comma-separated PII keywords (e.g. `PESEL, NIP, email, phone, address, name`). Used by Suggestions for case-insensitive substring matching against column and display names. Log Cleanup section purges entries older than N days from `spw_anonymization_log` and also deletes the matching `spw_anonymization_report` rows older than N days.
- **History tab:** The last 50 executions from `spw_anonymization_log`, one row per run with its status and duration. Each run writes its GDPR/EDPB compliance report to `spw_anonymization_report`; expand an entry to view that report inline and download it as JSON. Dry runs do not create log entries or reports.

### 9n. Custom Logo

**Settings → Branding** — replace the default OpenSparrow logo shown in the frontend header with your own image, and set the application name shown on the login page (1–60 characters). Uploading a logo automatically enables it.

- **Upload:** PNG, JPEG or WEBP, up to 2 MB. The file is validated server-side (MIME sniffing, not just the extension) and stored under `public/assets/img/uploads/` with a random filename.
- **Storage:** The active logo path is saved to `spw_config.settings` (`custom_logo_path`); the previous file is deleted automatically when a new logo is uploaded.
- **Remove:** Click Remove logo to delete the file and settings key and revert to the default OpenSparrow logo.
- **Enable/disable:** Independent "Show logo in header" checkbox — uncheck and Save to hide the logo entirely and return to the plain header (no logo), without deleting the uploaded file.

### 9q. Click Statistics

**System → Click Statistics** records which page elements users actually click. Config in `spw_config.clickstats`; recorded clicks in `spw_clickstats`, written by `public/api/clickstats.php`. Requires the `3.3_clickstats` migration (Migrations → Apply Pending Migrations).

- **Settings tab:** The last tab on the right. Two checkboxes and the retention window. *Enable Click Statistics* switches collection on; *Record Table And Record* additionally stores which table and record was open, and unchecking it reduces every entry to user, time and element.
- **Automatic retention:** *Delete Automatically After N days* (default 90) is applied by the notifications cron, and is what bounds the table without anyone remembering to purge it. Set it to 0 to keep every click until you clear the log by hand. An unusable value falls back to the default rather than to "keep everything", so a bad entry can never quietly switch the bound off.
- **Log tab:** The recorded clicks, newest first, 100 per page, filterable by element and by user. On-demand purging, on top of the automatic window: *Delete Older Than* trims the log to a rolling window of N days and *Clear Log* empties `spw_clickstats` entirely.
- **Top Elements tab:** A rollup of the 20 most-clicked elements, aggregated from all recorded clicks, with the same element and user filters as the Log tab.
- **When disabled:** Off means absent. With the module off no collector script is emitted into any page, so nothing is downloaded, no listener is installed and no request is ever sent. The endpoint independently re-checks the flag and answers 204 without writing, so a page left open when the module is switched off stops recording immediately.
- **What is collected:** A click on a button, link or element carrying `data-stat` stores the element label (the `data-stat` value when present, otherwise a derived id/class/text label), the page script name, and the user id. Input values, query strings and form contents are never recorded. Clicks are buffered in the browser and delivered in batches via `navigator.sendBeacon`, so collection costs a handful of requests per session rather than one per click. A table name from a client is stored only if that user has access to it.
- **Write limits:** The browser buffers clicks and flushes at most every 5 seconds (and on page navigation), with at most 50 events per batch. The endpoint keeps its own per-session budget of 300 rows per minute — a burst beyond that is silently dropped rather than recorded, so statistics can never be used to flood the table. Each batch is written as a single multi-row `INSERT`.
- **Labels can contain record data:** When an element carries neither `data-stat` nor an id, the label falls back to the element's own visible text (up to 40 characters) — and in a list of records that text is record content: a file name on the Files page, a record title in a row link. Unchecking *Record Table And Record* does not change this; that switch only governs the separate table and record columns. Add `data-stat="…"` to an element to pin exactly what is stored for it.
- **User column:** `spw_clickstats.user_id` references `spw_users` with `ON DELETE SET NULL`: deleting a user keeps their clicks, only the name in the Log tab goes blank.

### 9r. External API

**System → API** exposes table data to external services through read-only API keys. Definitions live in `spw_config.external_api`; the endpoint is `public/api/external.php`.

- **What one API is:** Each API binds one table, a chosen set of columns, fixed filters and a row limit. The endpoint accepts nothing from the client except the key — table, columns, filters and limit all come from the configuration, so an external service cannot ask for anything it was not granted. Hidden tables, owner-restricted tables and system tables cannot be exposed. There is a cap of 100 APIs.
- **API keys:** Keys are generated server-side (64 hex characters) and shown exactly once, in a modal after saving; they are stored encrypted (`key_enc`) and never returned to the browser again. A brand-new API with an empty key field gets a generated key on save; a submitted key is encrypted and stored; an empty field keeps the stored key; *Regenerate key on save* replaces it. Send the key as `Authorization: Bearer <key>` — the `?key=` query form is deliberately not supported, so keys never leak into access logs.
- **Fixed filters:** Filters are applied server-side and cannot be overridden by the caller. Operators: `equals`, `not equals`, `greater than`, `greater than or equal`, `less than`, `less than or equal`, `contains`.
- **Response:** JSON `{ "data": [...], "total": n }` — at most `limit` rows (1–1000, default 100), ordered by `id DESC`. Read-only: there is no write path. Every successful request is logged to `spw_external_api_log` (API, table, rows returned, duration) — create the table with *Apply Pending Migrations* if the Usage tab reports it missing.
- **Error codes:** `401` missing or invalid key, `403` API disabled, `404` the configured table or columns no longer exist, `429` rate limit — 60 requests per minute per key plus a global 300 per minute per IP enforced before the key is even resolved, `500` database error.
- **Usage tab:** Aggregated metrics from `spw_external_api_log`: total requests, average and slowest duration, per-API request counts with timings and returned rows, plus a paginated request log filterable by API name. *Clear Log* deletes every recorded request (or only entries older than N days when a retention window is given).
- **Scope warning:** A key is a full read of the configured columns of its table — grant keys only to services that should see that data. Per-user access scoping does not apply to this endpoint.

### 9s. Sharing

**System → Sharing** exposes a single table through a public read-only link — anyone with the link can view the table grid without logging in, like sharing a spreadsheet on OneDrive. Config lives in `spw_config.shared_tables`; the page is `public/share.php`, the data endpoint `public/api/share.php`.

- **What a shared link is:** Each shareable table can have one link. The URL carries only a token (`share.php?t=…`) — the table name never appears in it, and the client cannot choose which table is served. Opening the link shows the table grid read-only: sorting, filtering, search and CSV export of the loaded rows work; adding, editing, deleting, comments, files and related tables do not.
- **Link tokens:** Tokens are generated server-side (64 hex characters) and stored only as an HMAC hash — shown exactly once, in a modal after generating or regenerating, with a copy button; they cannot be retrieved later. Regenerating a link immediately invalidates the previous token. A disabled link keeps its token, so re-enabling restores the same URL.
- **What cannot be shared:** Hidden tables, owner-restricted tables and system tables are excluded — on save and re-checked on every request (defense in depth for hand-edited config).
- **Rate limits:** The endpoint is sessionless and GET-only. 300 requests per minute per IP (before the token is resolved) and 60 per minute per token; exceeding either answers `429`.
- **Treat a link like a password:** Anyone who obtains the link can read the table until it is revoked or regenerated. The page sends `noindex` headers so the URL is not indexed, but it is still a secret — share it only with people who should see that data.

### 10. Files Module

Central repository for documents and media backed by `spw_files`. The tab is both a file browser and the module's configuration screen.

- **Browsing:** Paginated list (25 rows per page by default, `FILES_PAGE_LIMIT`) with full-text search over name, display name and tags, a file-type filter, and click-to-sort on type, name, display name, tags, size, related record and upload date.
- **Per-file metadata:** Display name and tags are editable in place. Deletion is a **soft delete** — the row keeps its `deleted_at` stamp and drops out of every listing rather than being removed.
- **Bulk actions:** Select several files to delete them in one call or to apply tags to all of them at once; a single request is capped at 500 files.
- **Configuration:** Max file size, allowed types, allowed extensions, storage path (server-enforced under `storage/`; downloads streamed through `file_download.php`). Configuration changes save automatically — about a second after your last edit. The admin panel saves this config verbatim, so the extension list is intersected server-side with the set of extensions the uploader can actually content-verify — an entry that is not verifiable is rejected at upload no matter what the config says.
- **Content verification:** Beyond the extension whitelist, every upload is content-sniffed with `finfo`: the detected MIME type must match the extension's expected set, otherwise the file is rejected (HTTP 415). A renamed executable never passes as an image or a document. Files are stored under a random UUID name, never under the original one.
- **Record relations:** Define which tables uploads may be linked to (target table plus one or two label columns used when picking the record). A `related_table` sent by the client is only honoured when that table is on this list and the user has access to it — otherwise the file is stored unlinked. Record access gates the file too: deleting or retagging a file requires access to its linked record.
- **Who sees which file:** The file list follows the per-user `tables` grants: a user restricted in the Users module only sees files linked to tables they were granted (plus unlinked ones). On owner-restricted tables, non-admins additionally see only files whose linked record they own. These filters apply to every listing, including search and type filtering.

### 10b. Views Editor

**Data Management → Views** exposes read-only PostgreSQL views as browsable grid pages, alongside regular tables. Both ordinary and **materialized** views are discovered and configured identically.

- **Materialized views:** A materialized view serves the rows stored at its last refresh, not live data — OpenSparrow only reads it and never refreshes it for you. Keep it current with `REFRESH MATERIALIZED VIEW schema.name` from a database job or a cron entry, or the page will quietly show stale figures. Sync lists only relations the application's database user may `SELECT`, so a view it cannot read is never offered.
- **Sync:** "↻ Sync PostgreSQL Views" discovers views in the searched schema(s) and their column metadata; discovered views are merged into `spw_config.views` without discarding existing per-view settings (icon, menu name, drill-down).
- **Schemas tab:** By default sync only searches the application schema (`config/database.json` → `schema` / `PGSCHEMA`, default `app`). Check additional schemas (queried from `information_schema.schemata`) to include views living elsewhere, e.g. a demo schema. The selection is saved with the views configuration.
- **Per-view configuration:** Display name, menu name, description, icon, visibility, and drill-down levels. Materialized views carry a dedicated badge on their card so they are easy to tell apart at a glance. Removing a view from the configuration only hides it — the view reappears on the next sync as long as it still exists in the database.
- **Per-column configuration:** Each column of a synced view can have its own display name, a **summary** shown in the grid footer (SUM, AVG, COUNT, MIN or MAX, optionally restricted with a SUMIF/COUNTIF-style condition on another column), and **color rules** that paint matching values as colored chips (`>`, `>=`, `<`, `<=`, `==` against a numeric value).
- **Read-only:** Views cannot be edited or deleted through the grid — they are read-only, like native SQL views.

### 10c. User Records

**Data Management → User Records** configures the "My records" panel that every user opens from the avatar menu — the list of records currently assigned to them across tables. Stored in `spw_config.user_records`.

- **Column Mapping tab:** Per table, tick the columns that are concatenated into each assigned record's label, joined with " - " (e.g. first name + last name → "Jane - Doe"). Virtual columns are never offered. A table left unchecked falls back automatically to its first text-type grid column — or the first grid column of any type, and finally `id`, when no text column is shown in the grid.
- **Global Settings tab:** How many most-recently-assigned records are shown per table (default 20, 0 = unlimited). Ownership itself comes from `spw_record_owners` (see section 9d). In the panel the entries are then sorted globally by assignment date, newest first — and a user only ever sees records from tables they can access: hidden tables and tables outside the user's `tables` grants are filtered out.

### 10d. Record Comments

Threaded comments attached to any record, stored in `spw_comments` and served by `api/comments.php`. No admin tab is required — comments are available on every table in the schema.

- **Where they appear:** As a Comments tab inside the edit form and as a counter badge in the data grid. The avatar menu also exposes "My comments" — everything the current user wrote, newest first, each entry linking back to its record.
- **Rules:** Body text is capped at 4000 characters. Deleting a comment is a soft delete (timestamp), so the audit trail stays intact; viewers can read comments but not add them.

### 10e. Private Notes

A per-user notepad, stored in `spw_notes` and served by `api/notes.php`. Opened from the **Notes** entry in the avatar menu. Like comments, it needs no admin configuration.

- **Strictly private:** Every query is scoped to the calling user — notes are never shared, and no role can read another account's notes. This is the difference from comments, which are attached to a record and visible to everyone who can open it.
- **Record link:** A note may optionally reference a record (table + id), so it can be opened from the note straight back to what it is about. It can equally stand alone.
- **Reminders:** A note can carry a **reminder date**. `cron/cron_notifications.php` picks up notes that are due or overdue and delivers them as in-app notifications under the bell icon, so a reminder set for a time earlier in the day still fires on that day's run. Body text is capped at 4000 characters and deletion is a soft delete, exactly as for comments.

### 11. Menu Preview & Navigation Editor

Renders the frontend sidebar and lets you rearrange or nest items by dragging. Every change is saved automatically.

#### Drag & drop controls

- **Reorder:** Drag above or below another item.
- **Nest:** Drop onto the middle zone of a top-level item. Maximum depth: **1 level**.
- **Un-nest:** Drag a child item to the top level.
- **Auto-save:** Every drop triggers a save to `spw_config.menu` after 350 ms debounce.

### 11b. Demo Systems (Quick-Start Templates)

**System → Demo Systems** provides a pre-built demo application: CRM.

- **What gets installed:** PostgreSQL schema, seed data, app schema entries, dashboard widgets, calendar sources, workflows, SQL views.
- **Safety:** Both install and uninstall require typing `CONFIRM`.
- **Cleanup on uninstall:** Demo schema dropped (CASCADE). Configuration entries cleaned if they contain only demo content.

#### Demo 1: CRM

Companies, contacts, deals, quotes, invoices, assets, activities. FK subtables, deal stage color coding, computed columns with conditional icons (deal size, activity kind), revenue drill-down by year → month, Kanban deals board, automations (incl. welcome email), GDPR anonymization rules for stale leads.

### 11c. CSV Import

Data Management → CSV Import — bulk-imports rows from a .csv file into any schema-configured table. A memory-safe PHP generator reads the file line-by-line; inserts are batched in transactions of up to 1 000 rows.

- **Step 1 — Select table & upload:** Pick the target table from the dropdown, then drag & drop (or click) to upload a .csv file (max 500 MB). The file is validated server-side by real MIME type, not just extension.
- **Delimiter & encoding:** Delimiter: comma, semicolon, tab (TSV) or pipe. Encoding: UTF-8, Windows-1250, Windows-1252, ISO-8859-1, ISO-8859-2 or Windows-1251. Both are remembered in `localStorage` between imports, and the local preview re-parses with them.
- **Create new table from CSV:** Instead of picking an existing table, tick *Create new table from CSV*: column types are proposed from the file's sample values, you name the table and pick its schema, and the table is created and registered before the rows are loaded. The typed name is normalized to lowercase with non-alphanumerics folded to underscores.
- **Import modes:** **Normal mode** — batched INSERT in transactions of up to 1 000 rows; tracks per-row errors and supports upsert. **Fast COPY mode** — streams the file straight to PostgreSQL via `COPY FROM STDIN`, 10-60x faster on large files, but there is **no per-row error tracking** (one type mismatch rolls back the whole import) and **no upsert** — the conflict column is hidden while it is selected.
- **Step 2 — Map columns:** Each CSV header is listed with sample values. Use the dropdown to map it to a database column, or leave "Skip" to ignore it. Auto-mapping pre-selects columns whose names match case-insensitively.
- **Upsert / conflict handling:** Select a conflict column (e.g. `id`). On conflict the matched row is updated instead of rejected. The column must have a UNIQUE constraint and must be included in the column mapping.
- **Type casting:** Values are cast before insert: dates accept `dd.mm.yyyy`, `dd/mm/yyyy`, `yyyy-mm-dd`; booleans accept `true/false/1/0/yes/y/t`; decimal numbers accept a comma as the decimal point (converted to a dot). Values that fail the cast are stored as NULL, not rejected.
- **Error handling:** Row-level cast failures and DB batch rollbacks are both caught. Skipped rows are written to `spw_import_rows_log`; the import continues. Status is `failed` only if every row was skipped.
- **System tables:** `spw_imports` — one row per import run (user, file, table, status, counters). `spw_import_rows_log` — per-skipped-row detail (row number, raw JSON, error message). Activated by *Apply Pending Migrations*.
- **Import history:** The bottom of the page lists the last 100 imports. Click *Log* on any row with skipped records to expand the per-row error table inline.
- **Upload handling:** Uploaded files are stored under `storage/files/imports/` under a random name, in a directory denied by `.htaccess`, and are deleted right after the import finishes (or fails). The execute step re-validates the token against the `^[a-f0-9]{32}\.csv$` pattern, so a stored file can only be imported by the flow that uploaded it. Import and table creation are disabled in demo mode and audit-logged as `CSV_IMPORT`.

### 11d. ETL Import

**Data Management → ETL** — scheduled or on-demand batch import from an external source database (MySQL, MariaDB, PostgreSQL, SQLite) or a CSV file fetched over FTP/FTPS, into a local PostgreSQL target table. Independent of the removed MySQL Gateway feature; connects via a driver-based PDO layer, extensible to Oracle/DB2/SQL Server.

- **Sources:** Define one or more named sources. Database sources (MySQL, MariaDB, PostgreSQL) take host, port, database, user and password; SQLite takes a readable file path instead of a host; the *CSV file (FTP/FTPS)* source takes protocol, host, remote directory, file name, delimiter, header flag and passive mode. *Test connection* verifies reachability before saving. Stored in `spw_config.etl`; the password is masked (`********`) once saved and only replaced when re-entered.
- **Jobs:** Each job picks which source it reads from, and defines a target table plus an optional column map (source → target; auto-matched by name when omitted). Database-source jobs also carry a source query (a single read-only `SELECT`/`WITH` — DML/DDL and multiple statements are rejected); CSV/FTP-source jobs have no query and import the whole file.
- **Load modes:** **Full refresh** (truncate + insert), **Append** (insert only), **Upsert** (insert with `ON CONFLICT` update on one or more key columns).
- **Incremental loading:** Set an incremental column and use the `{{watermark}}` placeholder in the source query; the engine substitutes the last-seen value (or the configured initial value) and stores the new high-water mark after each successful run.
- **Preview:** Runs the source query against the configured connection and shows up to 20 sample rows and columns before saving a job.
- **Schedule:** *Manual*, *Daily*, *Weekly* or *Monthly*, enforced by `cron/cron_etl.php`. A scheduled run skips a job that has already succeeded within the current frequency window, and runs multiple jobs in parallel (up to 4 worker processes). *Run Now* on a job's card saves the config and runs that single job immediately, bypassing both the schedule window and the job's enabled flag.
- **Load safety:** Every load runs in a single transaction — on any error the whole batch is rolled back, so a failed run writes no partial data. *Full refresh* additionally refuses to truncate the target when the source returns zero rows, so an empty or broken source cannot wipe the table. Transient connection problems (drops, lock timeouts, deadlocks) are retried automatically with short delays before a run is declared failed. Empty strings are stored as NULL in non-text target columns. Rows are inserted in configurable batches (50-5000 rows per INSERT).
- **Run history:** The last 50 runs from `spw_etl_log` show rows read/written, duration and any error. Log Cleanup purges entries older than N days.

### 11e. ETL Flows

**Data Management → ETL → Flows** tab — chains existing ETL jobs into an ordered sequence (start, job, job, ..., end tiles). Steps reference job ids from the Jobs tab, so editing a job also changes what any flow referencing it does. Stored in `spw_config.etl_flows`, separate from the `etl` key.

- **Execution:** Steps run strictly in order and the flow stops immediately at the first failing step — remaining steps do not run. *Run Now* executes a flow immediately, bypassing its schedule.
- **Schedule:** Same *Manual*/*Daily*/*Weekly*/*Monthly* model as jobs, enforced by `cron/cron_etl_flow.php`, guarded per flow (not module-wide).
- **Run history:** Each flow shows its own run history: one row per flow run in `spw_etl_flow_run_log` (overall status, which step failed) plus per-step detail in `spw_etl_flow_step_log`.

### 12. Deployment Notes

- **Serve only `public/`:** set the web server document root to the `public/` directory. All backend code, configuration and storage live above it and cannot be reached over HTTP; the per-folder `.htaccess` `Deny from all` rules ship as defense-in-depth.
- **Storage permissions:** `config/` and `storage/` must be writable by the web-server user.
- **Backups:** Keep regular `pg_dump` snapshots — they cover configuration too, since it lives in `spw_config`.
- **Demo mode:** Set `DEMO_MODE=true` to block all write operations in the admin API.

#### Environment variables

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | Runtime environment. |
| `DB_HOST` / `PGHOST` | `localhost` | PostgreSQL host. |
| `DB_PORT` / `PGPORT` | `5432` | PostgreSQL port. |
| `APP_TIMEZONE` | `Europe/Warsaw` | IANA timezone for every PostgreSQL session. |
| `SECURE_COOKIES` | `true` | Set `false` on plain HTTP. |
| `SESSION_MAX_LIFETIME` | `28800` | Hard session expiry in seconds (8 h). |
| `IP_HASH_SALT` | *Auto-generated* | HMAC secret for IP pseudonymisation. When unset, a 64-char random salt is generated on first request and persisted to `includes/.secret_salt`. Set it explicitly via env var for multi-server deployments where all nodes must share the same salt. |
| `LOGIN_MAX_ATTEMPTS_PER_IP` | `20` | Failed login threshold per IP. |
| `LOGIN_MAX_ATTEMPTS_PER_USERNAME` | `5` | Failed login threshold per username. |
| `LOGIN_LOCKOUT_MINUTES` | `15` | Lockout window in minutes. |
| `LOGIN_RATE_LIMIT_WINDOW_MINUTES` | `15` | How far back failed logins are counted towards the threshold. The lockout that follows lasts `LOGIN_LOCKOUT_MINUTES`. |
| `PASSWORD_MIN_LENGTH` | `12` | Shortest password the system will accept anywhere. The Users policy may raise it, never lower it. |
| `ADMIN_PURGE_MAX_DAYS` | `3650` | Longest retention window an admin log purge will accept, in days. |
| `DEMO_MODE` | `false` | Block all write operations in admin API. |
| `FILES_MAX_SIZE_MB` | `20` | Default upload size limit. |
| `RECORD_SNAPSHOTS_ENABLED` | `false` | Enable record snapshots system-wide. |
| `PGSCHEMA` | `app` | Schema for `spw_*` tables. |
| `APP_ENCRYPTION_KEY` | *auto-generated* | Symmetric key encrypting secrets at rest in `spw_config` (webhook signing secrets, SMTP password, Ollama Cloud API key). When unset, a random key is generated once and persisted to `includes/.secret_key`. **Set it explicitly on any deployment where that file is not stable** — containers or multiple app nodes each generating their own key cannot decrypt each other's stored secrets. |
| `TRUST_PROXY_HEADERS` | `false` | Whether forwarding headers are trusted when resolving the client IP for login throttling. Off by default, so a spoofed header cannot dodge the rate limiter; turn it on only behind a proxy you control and list that proxy in `TRUSTED_PROXY_IPS`. |
| `FORWARDED_HEADER_PRIORITY` | `cf,x-real-ip` | Order in which forwarding headers are consulted once they are trusted. Supported values: `cf`, `x-real-ip`, `x-forwarded-for`. |
| `SESSION_SAMESITE` | `Lax` | SameSite policy for the session cookie. |
| `SESSION_SAVE_PATH` | *none* | Session storage directory. Falls back to the project's `storage/sessions` when unset. |
| `HSTS_MAX_AGE` | `31536000` | Max-age of the HSTS header, in seconds. |
| `CSP_REPORT_URI` | *none* | Same-origin path collecting Content-Security-Policy violation reports. Must start with a single `/`; an external URL is refused and logged. Empty means no reporting. |
| `CSP_REPORT_ONLY` | `false` | Sends the policy as report-only, so violations are logged instead of blocked. Requires `CSP_REPORT_URI` and never applies to file downloads. |
| `AUTOMATION_EMAIL_FROM` | *none* | "From" address for queued automation emails; also settable from the Cron Notifications tab. Email delivery stays disabled until one of the two is set. |

This table covers the variables that matter most when deploying. Other tunables exist (page-size caps, cache max-ages, RAG rate limits, thumbnail sizing); each is read through `get_env()` in `includes/config.php`, which is the authoritative list.

### 13. Multilingual / i18n Module

OpenSparrow supports multiple UI languages via flat JSON translation files. The active language is resolved per session; both PHP templates and all JS modules share the same bundle.

#### Configuration — spw_config.settings

- `"default_language": "pl"` — locale used when no session preference is stored.
- The available locales are discovered automatically by scanning `languages/*.json` — there is no language list to maintain in the config; dropping a new file into `languages/` makes it appear in the switcher and in Settings → Language.

#### Translation files

Each locale has one file: `languages/{locale}.json` (e.g. `languages/pl.json`). Structure:

- Top-level keys are namespaces: `common`, `grid`, `form`, `auth`, `header`, `admin`, `pagination`, `filter`, `files`, `images`, `notifications`, `notes`, `dashboard`, `workflow`, `comments`, `owners`, `views`, `calendar`, `board`, `print`, `data_cleanup`, `mass_edit`, `mass_owner`, `mass_duplicate`, `mass_delete`, `shortcuts`, `agent`, `setup`. One extra top-level key, `_meta`, is not a namespace: it carries the locale's own `name` and text `dir`.
- Plural values are objects: `{"one": "...", "few": "...", "many": "..."}` for Polish; `{"one": "...", "other": "..."}` for English.
- Variable placeholders use `{name}` syntax: e.g. `"showing": "Showing {from}–{to} of {total} records"`.
- **Critical:** All double-quote characters inside JSON string values must be escaped as `\"`. An unescaped `"` silently breaks `json_decode`, causing the entire locale to fall back to English with no error shown.

#### PHP API

- `includes/i18n.php` — loaded via `includes/session.php`, available everywhere.
- `I18n::locale()` — returns active locale string (e.g. `'pl'`). Use in `<html lang="...">`.
- `t($key, $vars = [], $count = null)` — translates a dot-notation key; replaces `{name}` placeholders; selects plural form when `$count` is provided.
- Bundle served to JS via `api.php?action=i18n_bundle` as a flat key→value JSON object.

#### JavaScript API

- `assets/js/i18n.js` — ES module singleton. Import: `import { I18n } from './i18n.js';`
- Always call `await I18n.load()` before rendering any translated text — widgets that render before `load()` resolves will show raw key strings.
- `I18n.t('common.save')` — basic lookup.
- `I18n.t('grid.showing', { from: 1, to: 10, total: 42 })` — with variable substitution.
- `I18n.t('files.count', { count: 3 }, 3)` — with plural selection.
- Build DOM nodes with `el.textContent = I18n.t(...)` — never inject translations via `innerHTML`.

#### Adding a new language

1. Copy `languages/en.json` → `languages/{locale}.json`.
2. Translate all values; update plural forms to match the target language rules.
3. No registration needed — locales are discovered from the `languages/` directory, so the file itself is all it takes.
4. Validate JSON before deploying: `node -e "JSON.parse(require('fs').readFileSync('languages/{locale}.json','utf8'))"`.

**Documentation Languages:** The application UI is translated into all locales present in `languages/`, but this admin documentation is maintained in English and Polish only — the language bar at the top of this page offers EN and PL. Do not add further documentation locales; keep both versions in sync when a section changes.

### 14. Automations

Rule-based automation engine. Define triggers, conditions, and actions that execute server-side whenever a record is created, updated or deleted. Rules can be duplicated (creates a disabled copy) and enabled/disabled directly from the list by clicking the status badge.

#### Triggers

Three events: **After create** (INSERT), **After update** (PATCH) and **After delete** (DELETE). Delete rules see the row as it was immediately before deletion; the **Update fields** action is unavailable there because the row no longer exists.

#### Conditions (nested AND / OR)

Build multi-level condition trees. Each group has an AND / OR type toggle. Click **+ Group** to nest a sub-group. Supported operators: `equals`, `not equals`, `contains`, `not contains`, `is empty`, `is not empty`, comparisons `>`, `<`, `>=`, `<=` (numeric first, then date, then text), plus change detection for update events: `changed`, `not changed`, `changed from`, `changed to` — they compare the value before and after the write. On create events the old value does not exist, so `changed` matches and `changed from` never does.

#### Action types

**Update fields** — set any column on the triggering record. **Send notification** — insert an in-app notification for one or more users (select from a checklist; "Current user" = the user who triggered the event). **Create record** — insert a new row in any table in your schema. **Send webhook** — send a JSON payload (rule ID, event, table, record ID, mapped fields — or the full record when the mapping is empty, plus `old_data` on update and delete events) to an external http(s) endpoint using POST, PUT, PATCH or DELETE. This is the integration path for n8n, Make and custom receivers: point the URL at an n8n Webhook node. An optional secret adds an `X-Sparrow-Signature` header (HMAC SHA-256 of the body) so the receiver can verify the origin; it is stored encrypted and never shown back — leave the field blank to keep the saved value or press **Clear secret** to remove it. Custom **headers** carry the receiver's own auth (e.g. an n8n Header Auth credential). Header names stay visible but their values are stored encrypted and never sent back to the browser — leave a value blank to keep the saved one, remove the row to delete it; renaming a header clears its value. `Content-Type`, `User-Agent`, `Host` and `X-Sparrow-Signature` are reserved. **On failure** you can enable 1 or 2 retries with backoff — they apply only to timeouts and 5xx/429 responses, never to a 4xx, and because sending is synchronous each retry delays the user's save. **Send email** — queue an email (recipients, subject and message support templates) in `spw_automation_emails`; delivery runs via `cron/cron_notifications.php` and requires `AUTOMATION_EMAIL_FROM`. Webhook and email respect record ownership on `owner_restricted` tables and are written to the audit log. All values support template variables.

#### Template variables

`{{ current_user.id }}` — ID of the user who triggered the event. `{{ record.FIELD }}` — value of any column from the triggering record. `{{ old_record.FIELD }}` — the column value before the update (empty on create events). `{{ today }}` — current date (YYYY-MM-DD); also works in condition values, e.g. deadline `<=` `{{ today }}`. `{{ app_url }}` — the configured application URL, handy for building links in notifications and emails.

#### Connecting to n8n (or Make / any HTTP receiver)

The **Send webhook** action is the outbound integration path. OpenSparrow pushes record events to the receiver; no extra service or plugin is needed on either side.

1. In n8n, add a **Webhook** node, set the method to `POST` and copy its production URL.
2. In OpenSparrow, create a rule on the table you care about, pick the trigger event (**After create**, **After update** or **After delete**) and add any conditions.
3. Add a **Send webhook** action, paste the n8n URL and leave the payload mapping empty to receive the whole record — or map individual JSON keys to templates.
4. For authentication choose one: set a **Secret** and verify `X-Sparrow-Signature` in n8n (a Crypto node computing HMAC SHA-256 over the raw body, compared with an IF node), or add a **header** matching an n8n *Header Auth* credential. Both can be used together.
5. Save, edit a record, then open **History** — status `ok` means the receiver answered with a 2xx.

Body shape: `{ rule_id, event, table, record_id, triggered_by, data }`, plus `old_data` with the pre-change row on update and delete events — enough to diff without n8n keeping its own copy.

> **Loop risk:** if the n8n workflow writes back into the same table through the API, that write fires the rules again. Scope the rule with a condition (for example a status field the workflow does not touch) so it cannot re-trigger itself.

#### Run History

Click **History** on any rule to see the last 100 execution logs. Each row shows: time, table, record ID, event, status (`ok` / `error` / `skipped`), and error message. Status `skipped` means conditions were not met. Logs are stored in `spw_automation_runs`.

> **Note:** Automations run synchronously on every matching write. Keep action chains short to avoid slowing down the API response — a webhook waits up to 10 s to connect and 30 s for a response (the `HTTP_CLIENT_CONNECT_TIMEOUT` / `HTTP_CLIENT_TIMEOUT` defaults), and each enabled retry adds another such attempt plus its backoff. Failing actions are logged but do not roll back the original record change.

### 15. RAG (Retrieval-Augmented Generation)

Local knowledge base powered by Ollama. Upload .txt documents and query them with AI-generated answers grounded in your data.

- **Upload Documents:** Navigate to **Admin → Centrum AI → Documents**. Upload .txt files, tag them by topic (e.g. *legal*, *faq*), and optionally assign a language. Documents are stored in `spw_rag_files` and indexed for full-text search. Uploads are validated server-side: UTF-8 encoding is required and files that look binary are rejected. When chunking is enabled (Settings), the text is split into overlapping chunks at upload; *Re-chunk* (one document) and *Re-chunk All* rebuild that index after you change the splitting rules, and *Preview* opens the stored content in a read-only modal.
- **Configure Ollama:** Go to **Settings tab**. Choose your Ollama setup: 

  - **Local Ollama:** URL `http://localhost:11434` (default). Pull and run models on your machine.
  - **Ollama Cloud:** URL `https://api.ollama.com`. Requires an API key from your Ollama Cloud account. Paste it in the API Key field. Select a model from the list. Click **Test & load models** to verify connection and display available models.
- **Test Queries:** Use the **Test tab** to send questions against your knowledge base. Optionally filter by tag or select a response language. The result shows the generated answer and which documents were matched.
- **Statistics:** View query history, token counts, and response times in the **Statistics tab**. Track Ollama performance and document relevance over time.
- **Multilingual Support:** Queries automatically respond in the user's current UI language. Optionally tag documents with language codes (e.g. *lang:pl*, *lang:en*) for organization. No schema migration required.
- **Conversation Memory:** Set **Conversation memory (turns)** in the **Settings tab** (0–10, default `0` = off). The front-end Ask AI panel sends back the previous question and the previous answer, so follow-up questions ("and for last month?") keep their context. Only the last turn is sent — never the whole thread — and the server trims it to the configured limit. The model is told to use it for one purpose only: working out what an elliptical question refers to, never as a source of facts. A refusal is never remembered (feeding "not in the context" back in only primes the next refusal), and the *Clear* button drops the memory outright.
- **Aggregate Views:** Attach a SQL view to a table so the assistant can answer count/sum/average questions with exact totals over the full table, not just the visible grid page — configured in the **Settings tab**.

#### Aggregate Views

The grid page context sent to the assistant is limited to the current page (max 50 rows). Its first line states how much of the table it covers: when the page happens to hold every matching record the assistant is told so and may count and total those rows itself, but on a partial page it is instructed to never compute totals from it. To answer "how many" or "what's the total" questions correctly, write a SQL view with the exact aggregates you want (e.g. `public.v_companies_aggr`) directly in the database, then attach it to a table in **Centrum AI → Settings → Aggregate Views**, always giving the fully qualified `schema.view` reference. The assistant queries the view read-only and gets back plain numbers — it never sees or writes SQL, and never chooses which columns to aggregate.

- **Use full, descriptive column names** — `total_companies`, `avg_contract_value_pln`, not `cnt` or `val`. The column name is the only label the model has for the number next to it.
- **Spell out units or currency in the name** when not obvious — `_pln`, `_days`, `_hours`.
- **Give every aggregate a unique, specific name** — avoid generic `sum1`/`sum2` when a view computes several sums.
- **Use snake_case, English identifiers**, consistent with the rest of the table schema.
- **If the view groups rows** (`GROUP BY` status, month, …), include the group value as its own column so multiple returned rows stay distinguishable in the answer.
- **Keep the view read-only** — it is queried unparameterized (`SELECT * FROM view LIMIT n`) on every matching question, so it must contain no side effects and no expensive joins that would slow every query down. The row cap `n` comes from **Aggregate view rows** in the global RAG settings (default 100); if the view returns more rows than that the block is cut off and the assistant will answer "not in the context" for the rows that were dropped.
- **Always give the fully qualified reference** — `schema.view` (e.g. `public.v_companies_aggr`, `spw_crm.v_deals_aggr`). There is no implicit default schema; a bare view name is rejected.
- **A materialized view may be attached too** — the picker lists it with a *materialized* hint. It answers faster, because the aggregate is precomputed, but it returns the figures from its last `REFRESH MATERIALIZED VIEW`; OpenSparrow never refreshes it. Attach one only if something else keeps it current, otherwise the assistant will state stale totals with full confidence. Only relations the application's database user may `SELECT` are offered.

#### Subtotals (ROLLUPS)

A view grouped by two things at once (company *and* stage) holds the answer to "what is the total for stage X" only as several separate rows — and the assistant is forbidden to add figures up itself, so it would correctly but uselessly answer "not in the context". The server therefore rolls the view up before the question is sent: it groups the rows by each low-cardinality column on its own and appends the exact subtotals as a `ROLLUPS` block the model only has to quote. No configuration is needed; it applies to every attached view.

- **Only additive columns are rolled up** — a column is summed when every value is numeric and its name does not announce a non-additive statistic (`avg_`, `min_`, `max_`, `first_`, `last_`, `_pct`, `_ratio`, `id`). An average of averages is never computed; where the view has exactly one count column, an exact `derived_avg_…` is emitted instead.
- **Coarse groupings win** — the roll-up uses at most three grouping columns with 2-25 distinct values, coarsest first (a 5-value stage column before a 24-value company column). Columns whose values are longer than 80 characters (an aggregated contact or note blob) are never used as a grouping, and a column with a distinct value on almost every row is skipped as well.
- **The block is size-capped** — roughly 6 000 characters and 45 lines, applied per grouping so a list is never cut in half. A grouping that does not fit is dropped whole; its rows are still in the view above. Subtotals are also skipped when the view itself was truncated by **Aggregate view rows**, since they would then cover only part of the data.

> **Ownership restriction:** Only tables without row-level owner restriction can be paired with a view — a plain SQL view has no session or `user_id` to filter by. Attaching one to an owner-restricted table is rejected both in this UI and on the server.

### 16. Print Templates

**System → Printouts** — build printable report templates from simple blocks (header, text, table), each bound to a PostgreSQL view from the Views module. Admin configuration in `System → Printouts`; reports are rendered by `public/print.php` and the `api/print.php` endpoint.

- **Data source:** A template is bound to exactly one PostgreSQL view registered in the Views module (only views with `source=postgres` are offered). The view's columns become the available `{variables}` used in header and text blocks. A report always prints whole rows of the view — there is no per-record printing from a table grid.
- **Layout:** Blocks in a configured order, up to 50 per template: **header** (H1–H3, text with `{variables}`), **text** (a paragraph, values substituted from the first row), and **table** (all rows; per-column width in % and left/center/right alignment, headers centered). A template with no view selected and no blocks still renders — useful for fixed text pages.
- **Report parameters:** Optional filters (up to 20) shown above the report before it is generated. Each parameter filters one column of the view; its chosen value travels as `p_<key>` in the URL, so a filtered report can be linked or bookmarked. Dropdown options come from a separate lookup view (value + label columns) or, without one, from the distinct values of the filter column itself (max 500 options). *Required* hides the "— all —" option. Values are matched with strict equality (`=`) and bound as prepared parameters — never interpolated into SQL.
- **Pagination:** The browser splits the rendered report into A4 pages (`.pr-page`), each with a "Page X of Y" footer (`.pr-page-footer`). Tables break across pages row by row with the header row repeated on every page. Data is capped at 1000 rows per report.
- **Access:** The selector at `print.php` lists only visible templates the current user can access — print visibility follows the per-user `prints` grants (an empty grant list means unrestricted). Access is enforced twice: when opening the page and again in the data endpoint.
- **Configuration:** Templates are stored in `spw_config.print` (versioned; concurrent edits are rejected with a conflict). On save the server re-validates everything: a template naming a view that no longer exists is rejected outright rather than silently saved.

### 17. Upgrading OpenSparrow

After pulling a new version, use **System → Migrations** to check for pending release migrations. The admin header displays a yellow upgrade notice when action is required.

#### Release workflow

1. Pull the new release: `git pull`
2. Open Admin → **System → Migrations**.
3. Run **Apply Pending Migrations** first (database schema changes).
4. Scroll to the **Release Migrations** section. Select the actions you want to apply and click **Apply selected**.
5. Verify the upgrade notice in the header has disappeared.

#### What Release Migrations do

- **Remove file** — moves an obsolete file to `storage/migrations_backup/<version>/` then deletes it from the working tree.
- **Remove config key** — removes a deprecated field from a configuration key in the `spw_config` store; the previous value is written to `spw_config_log` first. Skipped if an admin saved that config while the migration ran.
- **Deprecated (info only)** — no action is taken and the file is never deleted; the entry only documents that the file is obsolete and no longer part of the release. Such files can be removed manually at any time.

#### Backups

Removed files are copied to `storage/migrations_backup/<version>/` before deletion. Config keys are DB-backed, so their pre-change value is kept in the `spw_config_log` audit trail instead. The applied history in the Migrations tab shows the backup location for each action.

#### Adding a migration entry (for contributors)

Every pull request that removes a file or a configuration key must add an entry to `config/migrations.json` in the same PR. In `removed_config_keys`, `file` names the `spw_config` key — the legacy `schema.json` spelling is still accepted and maps to key `schema`. Example:

```json
"3.1": {
  "removed_files": ["admin/old_feature.php"],
  "deprecated_files": [],
  "removed_config_keys": [
    { "file": "schema.json", "path": "$.tables[*].legacy_flag" }
  ],
  "notes": "Removed old feature, replaced by new system."
}
```

