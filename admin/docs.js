// admin/docs.js

export function renderDocumentation(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const content = document.createElement('div');
    content.style.cssText = 'max-width:900px; padding:30px; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); color:#334155; line-height:1.6; margin-bottom:40px;';
    content.innerHTML = `
        <div>
            <h2 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; color: #0f172a;">
                OpenSparrow - Admin Panel Documentation
            </h2>
            <p style="font-size: 15px; color: #64748b; margin-bottom: 30px;">
                Configure your frontend application, manage database connections, and build dynamic dashboards, calendars and workflows without writing a single line of code.
            </p>

            <h3 id="doc-0" style="color: #2563eb; margin-top: 30px;">0. First-Run Setup</h3>
            <p style="background:#fef3c7;padding:10px 14px;border-left:3px solid #f59e0b;border-radius:4px;font-size:14px;">
                <strong>Fresh installation?</strong> The admin panel automatically detects that the database is not yet configured (no connection or missing <code>spw_users</code> table) and opens without requiring a login. Follow these steps exactly:
            </p>
            <ol style="padding-left: 20px;">
                <li>Open <code>/admin</code> in your browser — the panel loads in setup mode with a yellow banner.</li>
                <li>Go to <strong>System → Database</strong>, enter host, port, database name, user and password, then click <strong>Save config</strong>.</li>
                <li>Go to <strong>System → Migrations</strong> and click <strong>Apply Pending Migrations</strong>. This creates all <code>spw_*</code> tables and inserts a default admin account: username <code>admin</code>, password <code>admin</code>.</li>
                <li>Go to <code>/login</code>, log in as <code>admin</code> / <code>admin</code>. You are redirected to <code>/admin</code> automatically.</li>
                <li>Go to <strong>System → Users</strong>, find the <em>admin</em> row and click <strong>Change pwd</strong>. Enter your current password (<code>admin</code>) and set a strong new one.</li>
            </ol>
            <p>From that point the admin panel requires a valid session — the setup bypass is permanently closed once <code>spw_users</code> exists in the database.</p>

            <h3 id="doc-0b" style="color: #2563eb; margin-top: 30px;">0b. Admin Panel Layout</h3>
            <p>The admin panel uses a collapsible left sidebar with four sections. The header provides global actions.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Data Management:</strong> Schema, Dashboard, Calendar, Files, Menu Preview, Add Table.</li>
                <li><strong>Workflows:</strong> Workflow Manager.</li>
                <li><strong>System:</strong> Database, Users, Health Check, Backup Tables, Audit &amp; Snapshots, Migrations, Performance, Cron Notifications.</li>
                <li><strong>Configuration:</strong> Export Config, Import Config, Run Notifications Cron.</li>
                <li><strong>Save config:</strong> Persists the currently edited JSON file to <code>includes/</code>. After a successful save a green status pill appears next to the button confirming which file was written. Error pills stay visible for 6 seconds so they are not missed.</li>
                <li><strong>Unsaved-changes guard:</strong> Tracks pending changes in config-editing tabs (Schema, Dashboard, Calendar, etc.) and shows a confirmation prompt before discarding them. Tabs that save immediately via API (Users, Database, Health, Backup) never trigger this warning.</li>
                <li><strong>Debug FE mode:</strong> Toggle in the header. When enabled, the frontend exposes a <code>#debug</code> panel with raw payloads for schema/API responses — useful when building new tables or troubleshooting grids.</li>
                <li><strong>Docs icon (book):</strong> Opens this documentation page.</li>
            </ul>

            <h3 id="doc-1" style="color: #2563eb; margin-top: 30px;">1. Technical Requirements & Database Structure</h3>
            <p>Before configuring OpenSparrow, make sure your PostgreSQL database meets these core requirements:</p>
            <ul style="padding-left: 20px;">
                <li><strong>Primary keys (mandatory):</strong> Every table <strong>must</strong> have a primary key column named <code>id</code> (typically <code>SERIAL</code> or <code>BIGSERIAL</code>). OpenSparrow relies on this exact column name to edit, delete, and view specific records.</li>
                <li><strong>Foreign keys (relationships):</strong> Use standard PostgreSQL foreign keys to link tables. The UI detects them automatically. Recommended naming convention: <code>table_name_id</code> (e.g. <code>company_id</code>).</li>
                <li><strong>ENUM types:</strong> Custom PostgreSQL ENUM types are fully supported and rendered as <code>&lt;select&gt;</code> menus in the frontend.</li>
                <li><strong>Boolean types:</strong> Boolean columns render as switch toggles in edit forms and as dropdown filters in data grids.</li>
                <li><strong>System schema:</strong> OpenSparrow stores its internal tables (<code>spw_*</code>) in a dedicated PostgreSQL schema (default: <code>app</code>). The schema is resolved in this order: <code>schema</code> key in <code>includes/database.json</code>, then the <code>PGSCHEMA</code> environment variable, then the <code>app</code> fallback.</li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">System tables (<code>spw_*</code> prefix)</h4>
            <p>OpenSparrow reserves a small set of internal tables, all prefixed with <code>spw_</code> and created inside the configured system schema:</p>
            <ul style="padding-left: 20px;">
                <li><code>spw_users</code> — frontend user accounts (id, username, password hash, role, active flag).</li>
                <li><code>spw_users_log</code> — audit trail of user actions (LOGIN, LOGOUT, CRUD operations).</li>
                <li><code>spw_users_notifications</code> — per-user in-app notifications, produced by the cron runner.</li>
                <li><code>spw_users_notifications_log</code> — execution log for the notifications cron: start/end time, status (<code>running</code> / <code>success</code> / <code>error</code>), trigger source (<code>cron</code> or <code>admin</code>), number of sources processed and notifications created, and error message on failure.</li>
                <li><code>spw_files</code> — metadata for files uploaded through the Files module.</li>
                <li><code>spw_login_attempts</code> — rolling log used by the DB-backed rate limiter on <code>login.php</code> (IP-hash and username counters).</li>
                <li><code>spw_comments</code> — user comments attached to any record. Each row links to a specific record via <code>related_table</code> + <code>related_id</code>, stores the author (<code>user_id</code>), body text (max 4000 chars), and a soft-delete timestamp.</li>
                <li><code>spw_record_snapshots</code> — JSONB snapshots of records captured after every INSERT or UPDATE. Each row is linked to the corresponding <code>spw_users_log</code> entry via <code>log_id</code> (CASCADE DELETE). Only active when the Record Snapshots module is enabled (see section 9b).</li>
                <li><code>spw_record_owners</code> — append-only ownership log. Each row records who owns a specific record (<code>table_name</code> + <code>record_id</code>) and who made the change (<code>changed_by</code>). The current owner is the row where <code>is_current = true</code>; all previous rows form the full ownership history. Created automatically on INSERT; reassignable by editors via the Record Owner panel in the edit view (see section 9c).</li>
                <li><code>spw_migrations</code> — migration tracker. One row per applied migration name + timestamp. Bootstrapped automatically by the first run of <em>Apply Pending Migrations</em>. Used by <em>System → Migrations</em> to determine which schema changes have already been applied.</li>
            </ul>
            <p style="background: #fef3c7; padding: 10px 14px; border-left: 3px solid #f59e0b; border-radius: 4px; font-size: 14px;">
                <strong>Note:</strong> Tables starting with <code>spw_</code> are treated as system tables. They are <strong>filtered out</strong> from the <em>Sync DB Tables</em> list in the Schema tab and will not appear in your application schema, even if they live in the same PostgreSQL schema as your business tables.
            </p>

            <h3 id="doc-2" style="color: #2563eb; margin-top: 30px;">2. Schema & Grid Configuration</h3>
            <p>The <strong>Schema</strong> tab is the core of your configuration. It maps your database tables to frontend grids and forms.</p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Add Table (Data Management → Add Table)</h4>
            <p>The dedicated <strong>Add Table</strong> tab creates a new physical PostgreSQL table and optionally registers it in <code>schema.json</code> — no manual sync step required.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Table Name &amp; Database Schema:</strong> Lowercase identifiers only. The <code>id serial PRIMARY KEY</code> column is always added automatically.</li>
                <li><strong>Display Name:</strong> Auto-filled from the table name (underscores → spaces, title-case). Used as the label in menus and headings when registered in <code>schema.json</code>.</li>
                <li><strong>Column Presets:</strong> Check <em>Timestamps</em> to automatically add <code>created_at</code> and <code>updated_at</code> columns (<code>timestamp DEFAULT now() NOT NULL</code>).</li>
                <li><strong>Per-column options</strong> — each column block exposes:
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li><strong>Name &amp; Type:</strong> <code>varchar(255)</code>, <code>text</code>, <code>int4</code>, <code>int8</code>, <code>boolean</code>, <code>date</code>, <code>timestamp</code>.</li>
                        <li><strong>Not Null:</strong> Adds a <code>NOT NULL</code> constraint. Requires a Default value if the table already has rows.</li>
                        <li><strong>Default:</strong> Accepts safe SQL expressions (<code>now()</code>, <code>current_timestamp</code>, <code>true</code>, <code>false</code>, <code>null</code>), plain integers, or arbitrary strings (escaped as a literal).</li>
                        <li><strong>Index:</strong> <code>btree</code> (standard equality/range), <code>hash</code> (equality only), or <code>unique</code> (enforces uniqueness). Creates a <code>idx_tablename_colname</code> index.</li>
                        <li><strong>Comment:</strong> Stored as <code>COMMENT ON COLUMN</code> in PostgreSQL. Visible via <em>Sync Columns from DB</em> and as a tooltip in the grid.</li>
                        <li><strong>Foreign Key:</strong> Adds an <code>FK</code> constraint referencing any table and column already registered in <code>schema.json</code>.</li>
                    </ul>
                </li>
                <li><strong>Register in schema.json</strong> (checked by default): After the table and all columns are created in the database, the table entry — including display name, column types, NOT NULL flags, and FK references — is written to <code>includes/schema.json</code> automatically. The table appears in the admin panel and frontend navigation on the next page load without any manual sync.</li>
            </ul>

            <ul style="padding-left: 20px;">
                <li><strong>Add new columns (Schema tab):</strong> Inside an existing table's configuration, click <em>+ Add Column</em> to append a physical column to the database. Specify name and native SQL type (e.g. <code>varchar(255)</code>, <code>boolean</code>, <code>int4</code>).</li>
                <li><strong>Sync DB Tables:</strong> Fetches all tables from the connected database and merges them into your schema configuration. System tables with the <code>spw_</code> prefix are skipped automatically.</li>
                <li><strong>Sync Columns from DB:</strong> Inside a table's configuration, fetches all columns for that table and adds any that are missing. For each column it also reads the PostgreSQL <code>COMMENT ON COLUMN</code> value — new columns get the comment as their description, and existing columns have their description updated if one is found. Type mapping (text, number, boolean, date, enum) is applied automatically on import.</li>
                <li><strong>Column Description (tooltip):</strong> Each column has an optional <em>Column Description</em> field. Fill it manually or populate it via <em>Sync Columns from DB</em> if the table has <code>COMMENT ON COLUMN</code> defined. The description appears as a native browser tooltip when the user hovers over the column header in the data grid — the header label also gains a dotted underline to indicate that a tooltip is available.</li>
                <li><strong>Live sidebar preview:</strong> When editing a table's Display Name, Icon or the <em>Hide from Sidebar Menu</em> flag, a small dark preview strip updates in real time showing exactly how the entry will appear in the frontend navigation — including the icon image, the display name, and a red <em>HIDDEN</em> badge when the table is hidden.</li>
                <li><strong>Smart type mapping:</strong> Native PostgreSQL types (<code>int4</code>, <code>varchar</code>, <code>boolean</code>, etc.) are mapped to clean frontend types (Text, Number, Date, Boolean, Enum). You can override the mapping with the provided dropdowns.</li>
                <li><strong>Remove tables:</strong> The red <em>Delete Table</em> button removes a table from your JSON configuration <strong>only</strong> — it does not drop the physical table from PostgreSQL.</li>
                <li><strong>Foreign-key search &amp; display:</strong> Assign multiple display columns to a foreign key (e.g. <code>first_name, last_name</code>). The frontend grid renders them as searchable inputs — practical across thousands of records.</li>
                <li><strong>Visibility &amp; ordering:</strong> Toggle per-column visibility in the grid and reorder with the Up/Down arrows.</li>
                <li><strong>Validation rules (regex):</strong> Enforce strict formats with regular expressions. When user input does not match, your custom error message is shown. Common patterns:
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li><strong>Email:</strong> <code>^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$</code></li>
                        <li><strong>Phone (9-15 digits, optional +):</strong> <code>^\+?[0-9]{9,15}$</code></li>
                        <li><strong>Postal code (XX-XXX):</strong> <code>^[0-9]{2}-[0-9]{3}$</code></li>
                        <li><strong>URL (http/https):</strong> <code>^https?:\/\/.*$</code></li>
                        <li><strong>Username (3-16 chars, letters/digits/_ ):</strong> <code>^[a-zA-Z0-9_]{3,16}$</code></li>
                        <li><strong>Price / decimal (up to 2 places):</strong> <code>^\d+(\.\d{1,2})?$</code></li>
                        <li><strong>Date (YYYY-MM-DD):</strong> <code>^\d{4}-\d{2}-\d{2}$</code></li>
                        <li><strong>Time (HH:MM, 24h):</strong> <code>^([01]\\d|2[0-3]):[0-5]\\d$</code></li>
                        <li><strong>Strong password (min 8 chars, upper, lower, digit):</strong> <code>^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d).{8,}$</code></li>
                        <li><strong>IPv4 address:</strong> <code>^(?:[0-9]{1,3}\\.){3}[0-9]{1,3}$</code></li>
                    </ul>
                </li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Subtables (one-to-many relationships)</h4>
            <p><strong>+ Add Subtable</strong> lets you display related child records directly inside the parent record's detail view (e.g. all <em>Invoices</em> under a <em>Client</em>).</p>
            <ul style="padding-left: 20px;">
                <li>Open the Schema editor of the parent table and click <em>+ Add Subtable</em>.</li>
                <li><strong>Target table:</strong> the child table to display (e.g. <code>invoices</code>).</li>
                <li><strong>Foreign-key column:</strong> the column in the child table that references the parent's <code>id</code> (e.g. <code>client_id</code>).</li>
            </ul>

            <h3 id="doc-3" style="color: #2563eb; margin-top: 30px;">3. Dashboard Builder</h3>
            <p>The <strong>Dashboard</strong> tab composes analytical views from your database. The layout uses a fixed <strong>3-column grid</strong>; each widget occupies 1, 2, or 3 columns and a small, medium, or large height.</p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Widget types</h4>
            <ul style="padding-left: 20px;">
                <li><strong>Stat Card:</strong> Colored tile showing the total row count of a selected table. Clicking navigates to the filtered table view.</li>
                <li><strong>KPI Card:</strong> Single aggregate number (COUNT, SUM, or AVG) with an optional icon and left-border accent color.</li>
                <li><strong>Bar Chart (Horizontal / Vertical):</strong> Groups rows by a chosen column and aggregates on another column. Each bar is clickable — clicking navigates to the table filtered by that group value.</li>
                <li><strong>Pie Chart:</strong> Same group/aggregate model as bar charts, rendered as a conic-gradient pie with a color legend. Each slice is clickable.</li>
                <li><strong>Data List:</strong> Top-N rows ordered by a selected column, with configurable display columns.</li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Widget proportions</h4>
            <p>Every widget has two independent size controls:</p>
            <ul style="padding-left: 20px;">
                <li><strong>Width</strong> — how many of the 3 grid columns the widget occupies:
                    <ul style="padding-left: 20px; margin-top: 4px;">
                        <li><code>1/3</code> — one column (narrow tile, good for stat/KPI cards).</li>
                        <li><code>2/3</code> — two columns (medium, good for charts).</li>
                        <li><code>3/3 (full)</code> — spans the entire row (good for lists and wide charts).</li>
                    </ul>
                </li>
                <li><strong>Height</strong> — minimum vertical size:
                    <ul style="padding-left: 20px; margin-top: 4px;">
                        <li><code>Small</code> — 140 px minimum.</li>
                        <li><code>Medium</code> — 280 px minimum.</li>
                        <li><code>Large</code> — 440 px minimum.</li>
                    </ul>
                </li>
            </ul>
            <p>On mobile screens all widgets collapse to a single column regardless of their configured width.</p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Filter Conditions (WHERE)</h4>
            <p>Every widget supports structured filter conditions that restrict which rows are counted or grouped. Conditions are combined with <strong>AND</strong> or <strong>OR</strong> and applied as a SQL WHERE clause on the backend. Column names are validated against the schema; values are escaped — no raw SQL is accepted.</p>
            <ul style="padding-left: 20px;">
                <li>Click <strong>+ Add condition</strong> to add a row.</li>
                <li>Each row has: <strong>column selector</strong> (from the widget's source table), <strong>operator</strong>, and a <strong>value</strong> field.</li>
                <li>Available operators: <code>=</code>, <code>!=</code>, <code>&lt;</code>, <code>&gt;</code>, <code>&lt;=</code>, <code>&gt;=</code>, <code>LIKE</code>, <code>ILIKE</code>, <code>IS NULL</code>, <code>IS NOT NULL</code>.</li>
                <li>For <code>IS NULL</code> / <code>IS NOT NULL</code> no value field is shown.</li>
                <li>From the second condition onwards a logic selector (<strong>AND</strong> / <strong>OR</strong>) appears to the left.</li>
                <li>The first condition always starts with <em>WHERE</em>.</li>
                <li>Example: <em>status = active</em> AND <em>amount &gt; 100</em> — counts only active records with amount above 100.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Note:</strong> Filter conditions affect <em>data fetching only</em> (what the widget counts or groups). Drill-down navigation (clicking a stat/KPI card) uses the first <code>=</code> condition to pre-filter the linked table view.
            </p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Live Preview</h4>
            <p>A <strong>Live Preview</strong> panel is displayed to the right of the widget editor. It renders the actual widget component with sample data and updates automatically as you change the type, title, color, width, height, or display columns — no save required.</p>
            <ul style="padding-left: 20px;">
                <li>Preview uses fixed mock data (not real database rows) so it is always fast and works without a database connection.</li>
                <li>The preview respects the widget's color and title settings.</li>
                <li>Click interactions inside the preview are disabled (<code>pointer-events: none</code>).</li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Global settings</h4>
            <p>The <em>Global Settings</em> sidebar item controls the dashboard menu entry and grid gap. A live sidebar preview reflects the menu name and icon immediately.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Grid Gap (CSS):</strong> Controls the gap between widgets (e.g. <code>20px</code>, <code>1rem</code>). Defaults to <code>20px</code>.</li>
                <li><strong>Menu Display Name / Icon / Hidden:</strong> Same controls as other sections — reflected in the live sidebar preview.</li>
            </ul>

            <h3 id="doc-4" style="color: #2563eb; margin-top: 30px;">4. Calendar Module</h3>
            <p>The <strong>Calendar</strong> tab binds date columns from your database directly to a visual calendar.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Global settings:</strong> The <em>Global Settings</em> sidebar item lets you set the menu name, icon and visibility of the Calendar entry in the frontend navigation. A live sidebar preview reflects changes immediately.</li>
                <li><strong>Data sources:</strong> Overlay multiple tables on a single calendar. For each source select the table, the date column and the title column.</li>
                <li><strong>Color coding:</strong> Assign a color per source to distinguish events at a glance.</li>
                <li><strong>Row context:</strong> The full database row is attached to each calendar event, enabling click-through modals or custom actions.</li>
            </ul>

            <h3 id="doc-5" style="color: #2563eb; margin-top: 30px;">5. Workflows Builder</h3>
            <p>The <strong>Workflows</strong> tab composes multi-step wizards that guide users through structured data entry across related tables.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Global settings:</strong> Click <em>Global Settings</em> in the sidebar to set the menu name, icon and visibility of the Workflows entry. A live sidebar preview shows the result immediately, including a <em>HIDDEN</em> badge when the section is hidden.</li>
                <li><strong>Workflow details:</strong> Each workflow requires Title, short description and icon — rendered as a card in the frontend workflows grid.</li>
                <li><strong>Steps setup:</strong> Add sequential steps and select a target table per step. Each step can have its own description.</li>
                <li><strong>Relational linking:</strong> Link child records to parents by selecting the foreign-key column in the current step and mapping it to a previously completed step.</li>
                <li><strong>Multiple records:</strong> Enable <em>Allow adding multiple records</em> to let users submit several entries in a single step (e.g. several employees for a company) before moving on.</li>
            </ul>

            <h3 id="doc-6" style="color: #2563eb; margin-top: 30px;">6. Users Management</h3>
            <p>The <strong>Users</strong> tab (<em>System → Users</em>) is the single place to manage all accounts — including the admin's own password.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Create users:</strong> Provide a username, password and role. Passwords are hashed with Argon2id before being stored. A live strength meter ranks passwords from <em>Weak</em> to <em>Strong</em>.</li>
                <li><strong>Roles</strong> — three levels, stored in <code>spw_users.role</code>:
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li><strong>Admin</strong> — access to this admin panel only. Cannot log in to the frontend application. Use this role for operators who manage the schema and configuration.</li>
                        <li><strong>Editor</strong> — full CRUD access to the frontend application (was <em>Full Access</em> in older versions). Cannot access the admin panel.</li>
                        <li><strong>Viewer</strong> — read-only access to the frontend (was <em>Read Only</em>). Cannot modify records. Cannot access the admin panel.</li>
                    </ul>
                </li>
                <li><strong>Change password:</strong> Click <strong>Change pwd</strong> next to any user row to open a password-change modal.
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li>For <strong>your own account</strong> — the modal requires the current password first (self-verification).</li>
                        <li>For <strong>other accounts</strong> — no current password needed (admin override). Use this to reset a forgotten password.</li>
                    </ul>
                </li>
                <li><strong>Active status:</strong> Toggle Active / Inactive to revoke or restore login access without deleting the user's historical records.</li>
                <li><strong>Audit:</strong> Login events and data changes are written to <code>spw_users_log</code>.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Tip:</strong> There is no separate "admin password" file anymore. All accounts — including admin — live in <code>spw_users</code> and are managed from this tab.
            </p>

            <h3 id="doc-7" style="color: #2563eb; margin-top: 30px;">7. Database Configuration</h3>
            <p>Manage the core PostgreSQL connection from <strong>System → Database</strong>.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Database configuration:</strong> Update host, port, database name, username and password. Settings are written to <code>includes/database.json</code> and take effect immediately.</li>
                <li><strong>System Schema:</strong> The <em>System Schema</em> field sets the PostgreSQL schema used for all <code>spw_*</code> tables. Defaults to <code>app</code>. This value is read by <code>sys_schema()</code> in <code>includes/db.php</code> and used to qualify every system-table query (<code>sys_table('users')</code>, <code>sys_table('files')</code>, …).</li>
                <li><strong>Test Saved Connection:</strong> Always click <em>Save config</em> first — the button reads the persisted <code>database.json</code>, not the in-form values.</li>
                <li><strong>Login protection:</strong> <code>login.php</code> applies a DB-backed rate limiter (IP-hash: 20 attempts / 15 min, username: 5 attempts / 15 min, configurable via env) plus CSRF tokens, session fingerprinting (User-Agent hash), an 8-hour absolute session lifetime, and <code>SameSite=Lax</code> / <code>HttpOnly</code> cookies. All thresholds are tunable via environment variables — see <em>Deployment Notes</em> below.</li>
            </ul>

            <h3 id="doc-8" style="color: #2563eb; margin-top: 30px;">8. Backup Tables</h3>
            <p><strong>System → Backup Tables</strong> creates timestamped copies of selected tables directly in PostgreSQL.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Table selection:</strong> The page lists all tables from <code>schema.json</code> (Application Tables) and all <code>spw_*</code> system tables fetched live from the database. Use <em>Select all</em> / <em>Deselect all</em> or tick individual tables.</li>
                <li><strong>Backup name format:</strong> <code>YYYYMMDDHHII_tablename</code> — e.g. <code>202604211709_contact_log</code>. The prefix is the server time at the moment of execution.</li>
                <li><strong>What is copied:</strong> Column structure and all data rows (<code>CREATE TABLE … AS SELECT * FROM …</code>). Indexes, primary keys, foreign keys, and constraints are <strong>not</strong> copied — the backup is a plain data snapshot, not a full schema clone.</li>
                <li><strong>Schema:</strong> Each backup table is created in the same PostgreSQL schema as its source (e.g. <code>app</code> for system tables, <code>public</code> for application tables).</li>
                <li><strong>Results:</strong> After each run the page shows a per-table result — backup name and row count on success, or an error message if the operation failed (e.g. table already exists for that minute).</li>
            </ul>
            <p style="background:#fef3c7;padding:10px 14px;border-left:3px solid #f59e0b;border-radius:4px;font-size:14px;">
                <strong>Tip:</strong> Run <em>Backup Tables</em> before applying schema changes or upgrading OpenSparrow. Pair it with a <code>pg_dump</code> snapshot and a config ZIP export for a complete backup.
            </p>

            <h3 id="doc-9" style="color: #2563eb; margin-top: 30px;">9. System Health, Cron &amp; Config</h3>
            <p>Keep the environment healthy and configuration portable.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Database Migrations:</strong> Moved to <em>System → Migrations</em>. Click <strong>Apply Pending Migrations</strong> to run all unapplied schema changes. See section 9c for details.</li>
                <li><strong>System diagnostics:</strong> Live checks for PHP version, ZIP / pgsql extensions, write permissions on <code>includes/</code>, and database connectivity.</li>
                <li><strong>Run Notifications Cron</strong> (System drop-down): Executes <code>cron/cron_notifications.php</code> ad-hoc from the admin panel without waiting for the scheduled task. A modal displays the full execution log in real time. Each run (whether triggered here or by the system scheduler) is recorded in <code>spw_users_notifications_log</code> with timestamp, status, trigger source (<code>admin</code> vs <code>cron</code>), sources processed, notifications created, and any error message. Only users that exist and are active in <code>spw_users</code> receive notifications — stale IDs in the calendar source configuration are silently skipped.</li>
                <li><strong>Scheduling the cron automatically:</strong> Add a system cron job (e.g. daily at 07:00) to run <code>php /path/to/cron/cron_notifications.php</code>. The script sets the <code>triggered_by</code> flag to <code>cron</code> automatically when called from the command line.</li>
                <li><strong>Export / Import config:</strong> The <em>Configuration</em> drop-down downloads or uploads a ZIP of all JSON settings. Recommended for backups and migrations to production.</li>
            </ul>

            <h3 id="doc-9b" style="color: #2563eb; margin-top: 30px;">9b. Audit &amp; Record Snapshots</h3>
            <p>
                <strong>System → Audit &amp; Snapshots</strong> controls the record snapshot module — an optional extension of the audit trail that captures the full state of a record after every write operation.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>How it works:</strong> When enabled, every INSERT (via <code>create.php</code> or the grid) and every UPDATE (via <code>edit.php</code> or the inline grid PATCH) saves a JSONB copy of the record's current state to <code>spw_record_snapshots</code>. Each snapshot is linked to the corresponding row in <code>spw_users_log</code> via <code>log_id</code>. DELETE operations are logged to <code>spw_users_log</code> but do not produce a snapshot.</li>
                <li><strong>Toggle:</strong> The on/off switch writes to <code>includes/settings.json</code>. The setting takes effect on the next request — no server restart required. If the <code>RECORD_SNAPSHOTS_ENABLED</code> environment variable is set, the toggle is read-only (the env var wins).</li>
                <li><strong>Prerequisite:</strong> Run <em>Initialize System Tables</em> (System → System Health) at least once after upgrading to create the <code>spw_record_snapshots</code> table. The panel shows the table status and current snapshot count.</li>
                <li><strong>Schema of <code>spw_record_snapshots</code>:</strong>
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li><code>id</code> — serial primary key.</li>
                        <li><code>log_id</code> — FK to <code>spw_users_log.id</code> (CASCADE DELETE).</li>
                        <li><code>table_name</code> — name of the affected table.</li>
                        <li><code>record_id</code> — primary key of the affected record.</li>
                        <li><code>snapshot</code> — JSONB: full record state captured with <code>row_to_json()</code> after the write.</li>
                        <li><code>created_at</code> — timestamp of the snapshot.</li>
                    </ul>
                </li>
                <li><strong>Storage growth:</strong> Every update to a frequently-changed table produces one row in <code>spw_record_snapshots</code>. Monitor table size and implement a retention policy (e.g. a cron job deleting rows older than N days) for high-volume installations.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Tip:</strong> Use <code>SELECT s.snapshot FROM spw_record_snapshots s JOIN spw_users_log l ON l.id = s.log_id WHERE l.target_table = 'your_table' AND s.record_id = 42 ORDER BY s.created_at DESC</code> to retrieve the full change history of a specific record.
            </p>

            <h3 id="doc-9c" style="color: #2563eb; margin-top: 30px;">9c. Database Migrations</h3>
            <p>
                <strong>System → Migrations</strong> is the single place to apply and track all schema changes to <code>spw_*</code> system tables.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>How it works:</strong> Each schema change is registered as a named migration (e.g. <code>2.0_baseline</code>). Applied migrations are recorded in <code>spw_migrations</code>. On each run, only migrations not yet recorded are executed — already-applied ones are skipped. Re-running is always safe.</li>
                <li><strong>Status table:</strong> Shows every known migration with a green <em>APPLIED</em> or yellow <em>PENDING</em> badge, and the exact timestamp when it was applied.</li>
                <li><strong>Apply Pending Migrations:</strong> Runs all pending migrations in order and records them. Also creates the default admin account on a fresh install. The response message reports how many migrations were applied vs skipped.</li>
                <li><strong>Adding migrations (developers):</strong> Append a new key/value entry to the <code>$migrations</code> array in <code>admin/api.php</code> <code>init_db</code>, and add the same key to the <code>$known</code> array in the <code>migrations_list</code> action. Never modify existing entries — they are immutable history.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Tip:</strong> Always run <em>Apply Pending Migrations</em> after upgrading OpenSparrow to pick up any new system tables or columns added in the new version.
            </p>

            <h3 id="doc-9d" style="color: #2563eb; margin-top: 30px;">9d. Record Ownership</h3>
            <p>
                Every record can have an <strong>owner</strong> — the user responsible for it. Ownership is tracked in <code>spw_record_owners</code> and visible in the <em>Record History</em> tab of the edit view.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>Auto-assignment:</strong> When a new record is created the creator is automatically set as owner.</li>
                <li><strong>Record History tab:</strong> Open any record in <code>edit.php</code> and switch to the <em>Record History</em> tab. The top section shows the current owner and (for Editor/Admin users) a dropdown to reassign ownership. Below it the full <em>Assignment History</em> table lists every change — owner, changed-by, and timestamp.</li>
                <li><strong>Changing the owner:</strong> Users with <strong>Editor</strong> or <strong>Admin</strong> role see the reassignment dropdown. Select a user and click <em>Change Owner</em>. The change is saved immediately and the history table refreshes.</li>
                <li><strong>Full history:</strong> Every change appends a row to <code>spw_record_owners</code>. Previous rows have <code>is_current = false</code>. No data is deleted.</li>
                <li><strong>Viewer access:</strong> Can see current owner but cannot reassign.</li>
                <li><strong>API:</strong> <code>GET api_owners.php?action=history&amp;table=X&amp;id=Y</code> returns the full assignment log (owner, changed_by, timestamp).</li>
            </ul>
            <p style="background:#fef3c7;padding:10px 14px;border-left:3px solid #f59e0b;border-radius:4px;font-size:14px;">
                <strong>Prerequisite:</strong> Run <em>Apply Pending Migrations</em> (System → Migrations) to create the <code>spw_record_owners</code> table. If the table was created before migration <code>2.0_record_owners_changed_at</code> was added, that migration renames the legacy <code>created_at</code> column to <code>changed_at</code> automatically.
            </p>

            <h3 id="doc-9e" style="color: #2563eb; margin-top: 30px;">9e. Grid Default Sort &amp; Load Limit</h3>
            <p>
                Each table can have a <strong>server-side default sort order</strong> and an <strong>initial load limit</strong> configured in Schema → Table Properties. These settings control what the API returns before the user applies any interactive filter or sort.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>Default Sort Order:</strong> Add one or more sort rules (column + ASC/DESC). The column dropdown lists all columns defined in the schema. Rules are applied left-to-right as a multi-column <code>ORDER BY</code>. If no rules are configured, the fallback is <code>id DESC</code>. The first rule also pre-selects the column header highlight in the grid.</li>
                <li><strong>Initial Load Limit:</strong> Enter the maximum number of rows to fetch on first load (e.g. <code>500</code>). <code>0</code> means unlimited. When the result is truncated an info toast appears: <em>"Showing first N records (limit set in Schema settings)"</em>. Useful for large tables where the full fetch causes slow page loads.</li>
                <li><strong>Where to configure:</strong> Admin → Schema → select table → <em>Table Properties</em> section → <em>Default Sort Order</em> and <em>Initial Load Limit</em> fields → Save File.</li>
                <li><strong>Stored in:</strong> <code>includes/schema.json</code> under the table object as <code>"default_sort": [{"column": "name", "dir": "asc"}]</code> and <code>"initial_limit": 500</code>.</li>
            </ul>

            <h3 id="doc-9f" style="color: #2563eb; margin-top: 30px;">9f. Grid Drilldown — Quick Add</h3>
            <p>
                When a row in the grid is expanded to show related (subtable) records, each subtable block header contains a <strong>+</strong> button. Clicking it navigates directly to <code>create.php</code> for that subtable, pre-filling the foreign key with the parent record's ID — so the new record is immediately linked to the correct parent.
            </p>
            <ul style="padding-left: 20px;">
                <li>Only visible to <strong>Editor</strong> and <strong>Admin</strong> roles.</li>
                <li>The foreign key column used is the one defined in <code>subtables[].foreign_key</code> in <code>schema.json</code>.</li>
                <li>URL format: <code>create.php?table=&lt;child_table&gt;&amp;&lt;fk_column&gt;=&lt;parent_id&gt;</code>.</li>
            </ul>

            <h3 id="doc-9g" style="color: #2563eb; margin-top: 30px;">9g. Performance Tab</h3>
            <p>
                <strong>Admin → System → Performance</strong> — a read-only diagnostic panel that scans <code>schema.json</code>, <code>dashboard.json</code>, and live PostgreSQL statistics to identify bottlenecks. Contains six independent sections; each has its own <em>Scan</em> button. The <strong>Run Full Analysis</strong> button at the top fires all six in parallel.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>1. Missing Index Advisor:</strong> Reads <code>schema.json</code> and <code>dashboard.json</code>, then queries <code>pg_indexes</code> to find columns that lack indexes. Checks: foreign key columns, subtable join columns (<code>foreign_key</code> field), default sort columns, widget <code>ORDER BY</code> / <code>GROUP BY</code> / filter condition columns. Suggestions are priority-tagged (HIGH = FK/join, MEDIUM = sort/filter) and include ready-to-run <code>CREATE INDEX IF NOT EXISTS …</code> SQL with copy buttons.</li>
                <li><strong>2. Unused Indexes:</strong> Queries <code>pg_stat_user_indexes</code> for indexes with <code>idx_scan = 0</code> (excludes primary keys and unique constraints). Displays index size and generates <code>DROP INDEX IF EXISTS …</code> SQL. Unused indexes waste storage and slow INSERT/UPDATE.</li>
                <li><strong>3. Slow Query Analyzer:</strong> Reads <code>pg_stat_statements</code> (top 15 by mean execution time). Highlights queries over 100 ms in amber, over 500 ms in red. Requires the <code>pg_stat_statements</code> extension — displays installation instructions if missing (<code>CREATE EXTENSION pg_stat_statements;</code>).</li>
                <li><strong>4. Table Statistics &amp; Bloat:</strong> Reads <code>pg_stat_user_tables</code> for every table in <code>schema.json</code>. Shows estimated rows, dead-row count, bloat %, sequential vs index scan counts, table size, and last autovacuum/autoanalyze timestamps. Dead % &gt; 10 shows a <em>VACUUM</em> copy button.</li>
                <li><strong>5. Database Health:</strong> KPI cards from <code>pg_stat_database</code> — cache hit ratio (target &gt; 99 %), active vs max connections, deadlock count, committed transactions, DB size, and PostgreSQL version string.</li>
                <li><strong>6. Schema Configuration Warnings:</strong> Pure analysis of <code>schema.json</code> and <code>dashboard.json</code> combined with <code>pg_class.reltuples</code> row estimates. Flags: tables with &gt; 20 columns, tables with &gt; 5 000 rows and no <code>initial_limit</code>, tables with &gt; 1 000 rows and no <code>default_sort</code>, subtables missing <code>columns_to_show</code>, list widgets without a row cap on large tables.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Note:</strong> The Performance tab is read-only — it generates SQL suggestions but never executes them. Copy the SQL and run it in your PostgreSQL client or via a migration entry in <em>System → Migrations</em>.
            </p>

            <h3 id="doc-9h" style="color: #2563eb; margin-top: 30px;">9h. Cron Notifications Tab</h3>
            <p>
                <strong>Admin → System → Cron Notifications</strong> — a dedicated management page for the notification dispatch cron job. Replaces the simple run-modal that was in the <em>Configuration</em> dropdown with a full five-section interface.
            </p>
            <ul style="padding-left: 20px;">
                <li><strong>1. Manual Run:</strong> Click <em>Run Cron Now</em> to execute <code>cron/cron_notifications.php</code> immediately via the server. Full stdout output is displayed inline. The run is recorded in <code>spw_users_notifications_log</code> with <code>triggered_by = admin</code>.</li>
                <li><strong>2. Run History:</strong> Click <em>Load History</em> to fetch the last 50 entries from <code>spw_users_notifications_log</code>. The table shows: run ID, status badge (SUCCESS / ERROR / RUNNING), trigger source (<code>admin</code> or <code>cron</code>), start time, duration in seconds, sources processed, notifications created, and any error message.</li>
                <li><strong>3. Notification Stats:</strong> Click <em>Load Stats</em> for a live snapshot of <code>spw_users_notifications</code>:
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li><strong>KPI cards:</strong> Total notifications, Unread, Due Today (unread), Upcoming Unread.</li>
                        <li><strong>Last run summary:</strong> Start time and status from the most recent cron log entry.</li>
                        <li><strong>Top Unread per User:</strong> Table of the 10 users with the most unread notifications.</li>
                    </ul>
                </li>
                <li><strong>4. Cron Setup:</strong> Static guide with ready-to-copy scheduler commands for four environments:
                    <ul style="padding-left: 20px; margin-top: 5px;">
                        <li>Linux/macOS crontab — every 15 minutes or every hour.</li>
                        <li>Windows Task Scheduler — <code>schtasks</code> command.</li>
                        <li>Docker — a sidecar container with a sleep loop.</li>
                    </ul>
                </li>
                <li><strong>5. Log Cleanup:</strong> Enter a retention period in days and click <em>Purge Old Logs</em>. Deletes all <code>spw_users_notifications_log</code> rows older than N days (DELETE with a parameterised interval — never executes arbitrary SQL). A confirmation dialog is required before deletion. Reports the number of rows deleted.</li>
            </ul>
            <p style="background:#f0f9ff;padding:10px 14px;border-left:3px solid #38bdf8;border-radius:4px;font-size:14px;">
                <strong>Note:</strong> The quick <em>Run Notifications Cron</em> button in the <em>Configuration</em> section of the left nav still exists as a shortcut modal. The Cron Notifications tab provides the full history, stats, and cleanup tools.
            </p>

            <h3 id="doc-10" style="color: #2563eb; margin-top: 30px;">10. Files Module</h3>
            <p>The <strong>Files</strong> tab is a central repository for documents and media, backed by the <code>spw_files</code> table.</p>
            <ul style="padding-left: 20px;">
                <li><strong>Global settings:</strong> The <em>Global Settings</em> sidebar item lets you set the menu name, icon and visibility of the Files entry. A live sidebar preview updates as you change these values.</li>
                <li><strong>Configuration:</strong> Set maximum file size (MB), allowed file types (images, PDFs, docs, spreadsheets, archives) and the storage path. The storage path must <strong>not</strong> be web-accessible — downloads are streamed through <code>file_download.php</code>.</li>
                <li><strong>Record relations:</strong> Optionally link uploads to specific rows in a target table, so files appear attached to business records (e.g. contracts on a client).</li>
                <li><strong>File library:</strong> Upload, search, filter by type, preview images and delete files from the admin UI. Deletions are logged to the audit trail.</li>
            </ul>

            <h3 id="doc-11" style="color: #2563eb; margin-top: 30px;">11. Menu Preview &amp; Navigation Editor</h3>
            <p>
                The <strong>Menu Preview</strong> tab renders the frontend sidebar exactly as users see it and lets you
                rearrange or nest items by dragging — no code required. Every change is saved automatically.
            </p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">How the menu is stored</h4>
            <p>
                Menu order and nesting are stored in <code>includes/menu.json</code>, separately from the individual
                config files (<code>dashboard.json</code>, <code>calendar.json</code>, <code>schema.json</code>, etc.).
                Display data — name, icon, hidden flag — always comes from those files. <code>menu.json</code> stores
                only the <em>structure</em>:
            </p>
            <pre style="background:#f1f5f9; padding:12px 16px; border-radius:6px; font-size:13px; overflow-x:auto;">{
  "items": [
    { "type": "dashboard", "key": "dashboard", "children": [] },
    { "type": "table",     "key": "clients",   "children": [
      { "type": "table", "key": "orders", "children": [] }
    ]},
    { "type": "calendar",  "key": "calendar",  "children": [] }
  ]
}</pre>
            <p>
                If <code>menu.json</code> does not exist the frontend falls back to the flat default order (Dashboard →
                Calendar → Files → tables in schema order). New tables added to the schema after the last menu save are
                automatically appended at the end.
            </p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Drag &amp; drop controls</h4>
            <ul style="padding-left: 20px;">
                <li>
                    <strong>Reorder:</strong> Drag any item and drop it above or below another item.
                    A blue line with a dot indicates where the item will land.
                </li>
                <li>
                    <strong>Nest (create submenu):</strong> Drag a top-level item and drop it onto the
                    <em>middle zone</em> of another top-level item — the target highlights with a dashed blue outline.
                    The dragged item becomes a child. Maximum depth is <strong>1 level</strong>.
                </li>
                <li>
                    <strong>Un-nest:</strong> Drag a child item and drop it above or below any top-level item to
                    promote it back to the top level.
                </li>
                <li>
                    <strong>Reorder within a submenu:</strong> Child items can be dragged within their parent's submenu
                    to change their relative order.
                </li>
                <li>
                    <strong>Auto-save:</strong> Every drop triggers a save to <code>includes/menu.json</code> after a
                    short debounce (350 ms). The frontend reflects changes on the next page load.
                </li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Hidden items</h4>
            <p>
                Items marked as hidden in their own config (Schema → <em>Hide from Sidebar Menu</em>, or the
                <em>Hidden</em> toggle in Dashboard / Calendar / Files global settings) are still displayed in the
                Menu Preview with a red <strong>HIDDEN</strong> badge and reduced opacity. They are excluded from the
                live frontend sidebar but remain available for reordering and nesting so their position is preserved
                if you un-hide them later.
            </p>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Frontend rendering</h4>
            <p>
                On the frontend, items with children are rendered as
                <code>&lt;details&gt;</code> / <code>&lt;summary&gt;</code> elements — they expand and collapse on
                click with no JavaScript required and are fully keyboard-accessible. The submenu opens automatically
                when the current page matches a child item. Child links are indented visually to reflect the hierarchy.
            </p>
            <p style="background: #f0f9ff; padding: 10px 14px; border-left: 3px solid #38bdf8; border-radius: 4px; font-size: 14px;">
                <strong>Tip:</strong> Changes to display name, icon or hidden status are made in Schema / Dashboard /
                Calendar / Files tabs and saved there. The Menu Preview tab controls <em>order and nesting only</em>.
                Both sets of changes take effect on the frontend simultaneously.
            </p>

            <h3 id="doc-12" style="color: #2563eb; margin-top: 30px;">12. Deployment Notes</h3>
            <ul style="padding-left: 20px;">
                <li><strong>Deny public access to <code>includes/</code>:</strong> Configure your web server so <code>database.json</code>, <code>schema.json</code> and other JSON config files cannot be fetched directly. An <code>.htaccess</code> rule blocking <code>*.json</code> is included by default.</li>
                <li><strong>Storage permissions:</strong> Under Docker, <code>includes/</code> and <code>storage/</code> must be writable by the web-server user (UID/GID <code>82:82</code> for musl-based slim PHP images).</li>
                <li><strong>Backups:</strong> Export the config ZIP before every upgrade and keep regular <code>pg_dump</code> snapshots of both application and <code>spw_*</code> tables.</li>
                <li><strong>Demo mode:</strong> Set <code>DEMO_MODE=true</code> to block all write operations in the admin API — safe for public demonstrations.</li>
            </ul>

            <h4 style="color: #475569; margin-top: 20px; border-left: 3px solid #cbd5e1; padding-left: 15px;">Environment variables</h4>
            <p>All configuration lives in <code>includes/config.php</code> and is fully overridable via environment variables. No <code>.env</code> loader — export in your shell, container, or virtual-host config.</p>
            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:10px;">
                <thead><tr style="background:#f1f5f9;">
                    <th style="text-align:left;padding:6px 10px;border:1px solid #e2e8f0;">Variable</th>
                    <th style="text-align:left;padding:6px 10px;border:1px solid #e2e8f0;">Default</th>
                    <th style="text-align:left;padding:6px 10px;border:1px solid #e2e8f0;">Description</th>
                </tr></thead>
                <tbody>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>APP_ENV</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>production</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Runtime environment.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>DB_HOST</code> / <code>PGHOST</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>localhost</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">PostgreSQL host.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>DB_PORT</code> / <code>PGPORT</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>5432</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">PostgreSQL port.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>DB_CONNECT_TIMEOUT</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>5</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Seconds before connection attempt times out.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>APP_TIMEZONE</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>Europe/Warsaw</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">IANA timezone applied to every PostgreSQL session.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>SECURE_COOKIES</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>true</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Set <code>false</code> on plain HTTP (local dev).</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>SESSION_SAMESITE</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>Lax</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Cookie SameSite policy. Do not set to <code>Strict</code> — it breaks the login→admin redirect flow.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>SESSION_MAX_LIFETIME</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>28800</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Hard session expiry in seconds (default 8 h).</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>IP_HASH_SALT</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><em>none</em></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><strong>Required in production.</strong> HMAC secret for IP pseudonymisation in login rate-limiting.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>LOGIN_MAX_ATTEMPTS_PER_IP</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>20</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Failed login threshold per IP before lockout.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>LOGIN_MAX_ATTEMPTS_PER_USERNAME</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>5</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Failed login threshold per username before lockout.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>LOGIN_LOCKOUT_MINUTES</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>15</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Lockout window duration in minutes.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>DEMO_MODE</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>false</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Set <code>true</code> to block all write operations in the admin API.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>FILES_MAX_SIZE_MB</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>20</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Default upload size limit when not set in <code>files.json</code>.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>THUMBNAIL_MAX_WIDTH</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>300</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Max thumbnail width in pixels.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>NOTIFICATIONS_DROPDOWN_LIMIT</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>10</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Max items shown in the bell notification dropdown.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>HSTS_MAX_AGE</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>31536000</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">HSTS max-age in seconds (1 year). Set <code>0</code> to disable on plain HTTP.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>RECORD_SNAPSHOTS_ENABLED</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>false</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Enable record snapshots system-wide. When set, overrides the admin panel toggle in <code>includes/settings.json</code>.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>PGDATABASE</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">—</td><td style="padding:5px 10px;border:1px solid #e2e8f0;">PostgreSQL database name.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>PGUSER</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">—</td><td style="padding:5px 10px;border:1px solid #e2e8f0;">PostgreSQL user.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>PGPASSWORD</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">—</td><td style="padding:5px 10px;border:1px solid #e2e8f0;">PostgreSQL password.</td></tr>
                    <tr><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>PGSCHEMA</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;"><code>app</code></td><td style="padding:5px 10px;border:1px solid #e2e8f0;">Schema for <code>spw_*</code> system tables. Overridden by <code>schema</code> key in <code>database.json</code>.</td></tr>
                </tbody>
            </table>
        </div>
    `;

    workspaceEl.appendChild(content);

    // Populate admin sidebar with section links
    const itemListEl = document.getElementById('itemList');
    const sidebarTitle = document.getElementById('sidebarTitle');
    if (sidebarTitle) sidebarTitle.textContent = 'Contents';

    if (itemListEl) {
        itemListEl.innerHTML = '';
        content.querySelectorAll('h3[id]').forEach(h => {
            const li = document.createElement('li');
            li.textContent = h.textContent.trim();
            li.style.cssText = 'cursor:pointer; font-size:12px; line-height:1.4; padding:5px 8px; border-radius:4px; color:#475569;';
            li.addEventListener('mouseover', () => { li.style.background = '#e2e8f0'; li.style.color = '#0f172a'; });
            li.addEventListener('mouseout',  () => { li.style.background = '';        li.style.color = '#475569'; });
            li.addEventListener('click', () => {
                h.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            itemListEl.appendChild(li);
        });
    }
}
