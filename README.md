<div align="center">

<img width="100" height="100" alt="opensparrow-logo" src="https://github.com/user-attachments/assets/b4793826-edc3-4ede-99e1-bdbd9c12f0bb" />

  <h1>OpenSparrow</h1>

  <p><strong>Schema-driven PHP platform to build CRUD apps, dashboards, and calendars on PostgreSQL in minutes.</strong></p>

  <p>
    <a href="COPYING.LESSER"><img src="https://img.shields.io/badge/License-LGPL%20v3-blue.svg" alt="License: LGPL v3" /></a>
    <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+" /></a>
    <a href="https://www.postgresql.org/"><img src="https://img.shields.io/badge/PostgreSQL-14%2B-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL" /></a>
    <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript"><img src="https://img.shields.io/badge/JavaScript-ES6%2B-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript ES6+" /></a>
    <a href="#"><img src="https://img.shields.io/badge/dependencies-none-brightgreen" alt="No dependencies" /></a>
  </p>

  ![PHP Tests](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/php-tests.yml/badge.svg)
  ![Vanilla Check](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/vanilla-check.yml/badge.svg)
  ![CodeQL Analysis](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/codeql.yml/badge.svg)
  ![Docker Lint](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/docker-lint.yml/badge.svg)
  [![Release ZIP](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/release-zip.yml/badge.svg)](https://github.com/wrobeltomasz/open-sparrow/actions/workflows/release-zip.yml)

</div>

---

## Overview

OpenSparrow is a JSON schema-driven platform for building internal systems. Tables, forms, dashboards, and calendars are generated from configuration files, so business logic stays decoupled from infrastructure. Self-hosted on PostgreSQL — no vendor lock-in, full data ownership.

> **No Composer. No npm. No build step** — in production.  
> Drop the files, point to PostgreSQL, open `/admin`. That's it.  
> Composer is used **dev-only** for the PHPUnit test suite (`composer install` is never required to run the application).

Project website: https://opensparrow.org

Demo: https://demo.opensparrow.org

---

## Preview

<img width="1720" height="692" alt="20260420_banner" src="https://github.com/user-attachments/assets/0da4a0c6-667f-4559-87fc-1cb0a729473f" />

---

## Features

- **First-run setup wizard** — guided `setup.php` wizard appears automatically on first launch (no `database.json` present). Collects PostgreSQL credentials, tests the connection, creates the schema, initializes all system tables, and seeds the default admin account in one flow.
- **JSON-driven CRUD** — tables and forms generated from `schema.json` with nested relations, constraints, and enum color states.
- **Inline editing** — in-grid PATCH updates routed through a single `api.php` gateway.
- **Dashboard engine** — COUNT / SUM / AVG / MIN / MAX / GROUP BY widgets defined in `dashboard.json`.
- **Calendar & notifications** — date-based records on a calendar view, with scheduled reminders via cron.
- **Admin panel** — collapsible sidebar navigation with visual editors for schema, dashboards, calendar, workflows, files, and users at `/admin`. Unified login for all roles — no separate admin password.
- **Visual table builder** — create PostgreSQL tables from the admin UI with per-column type, NOT NULL, default value, index (btree/hash/unique), column comment (`COMMENT ON COLUMN`), and foreign key constraints. Timestamps preset adds `created_at`/`updated_at` automatically. Tables are registered in `schema.json` in the same step.
- **Audit logging & record snapshots** — every write is logged to `spw_users_log`; an optional record-snapshot module saves a full JSONB copy of each record after INSERT/UPDATE to `spw_record_snapshots`, toggled from the admin panel or via env var.
- **CSV export & pagination** — built-in grid utilities.
- **Workflows builder** — multi-step wizards linking parent/child records across tables.
- **File management** — per-record attachments with tagging and search, configurable via the admin panel.
- **WCAG 2.1 focus** — accessibility-oriented UI.
- **AI Knowledge Base (RAG)** — upload `.txt` documents to a local knowledge base, then query them through a built-in chat interface powered by a local [Ollama](https://ollama.com) model. Retrieval uses PostgreSQL full-text search. Available to all authenticated users; managed by admins from the **Knowledge Base** tab. No cloud API required.
- **Automations** — rule-based triggers on record create/update/delete with template variables, configured from the admin panel.
- **Record comments** — threaded comments per record (`spw_comments`) with audit trail, shown as a grid badge and an Edit-form tab.
- *(Planned)* REST API and webhook engine for n8n / Make / custom integrations.

---

## Project Structure

The **web document root is the `public/` directory**. Everything served
over HTTP lives under `public/` (entry PHP scripts, `public/admin/`,
`public/assets/`, `favicon.ico`); all backend code and data listed below sits at
the repository root, *outside* the document root, and cannot be reached over the web.

### Core directories
- **`src/`** — OOP application layer (PSR-4, no Composer). Namespaced under `App\`. Sub-directories: `Audit/`, `Csrf/`, `Domain/`, `Form/`, `Http/`, `Persistence/`, `Repository/`, `Support/`. Loaded via `includes/autoload.php`; wired in `includes/bootstrap.php`.
- **`public/admin/`** — management panel (schema editor, dashboards, calendar, workflows, users, files, system health). Web-served; self-authenticated (requires role `admin`).
- **`public/assets/`** — static frontend resources (`css/`, `js/`, `icons/`, `img/`).
- **`includes/`** — backend helpers. `config.php` centralizes env-driven configuration; `db.php` centralizes PostgreSQL access; `api_helpers.php` holds request/response helpers; `autoload.php` registers the PSR-4 class loader; `bootstrap.php` wires all OOP dependencies.
- **`config/`** — runtime JSON configuration files (`database.json`, `schema.json`, `menu.json`, `settings.json`, `dashboard.json`, `calendar.json`, `workflows.json`, `automations.json`, `files.json`, `rag.json`, `security.json`, `views.json`). All JSON in this folder is gitignored and, being outside the `public/` document root, is not web-reachable — except `migrations.json`, the distribution-tracked release manifest.
- **`cron/`** — scheduled workers (e.g. `cron_notifications.php`).
- **`templates/`** — layout wrappers (`template.php`).
- **`storage/files/`** — user-uploaded files.
- **`cypress/`** — E2E test suite (Cypress 13.x). Tests live in `e2e/`, shared helpers in `support/`.
- **`tests/`** — PHPUnit unit test suite. Mirrors `src/` namespace structure under `Tests\`. Run with `vendor/bin/phpunit`.

### Key files
All web-served files below live under `public/` (the document root).

- **`setup.php` / `setup_api.php`** — first-run setup wizard and its API backend. Active only when `config/database.json` is absent.
- **`api.php`** — main API gateway (GET / POST / PATCH / DELETE).
- **`index.php`** — default landing / data entry page.
- **`dashboard.php` / `calendar.php`** — user-facing visualization and scheduling modules.
- **`login.php` / `logout.php`** — session and authentication.
- **`create.php` / `edit.php`** — record create/update forms.
- **`api/schema.php`** — filtered schema endpoint for the frontend (hides backend-only structure).
- **`api/fk.php`** — proxy endpoint for foreign-key dropdowns (never exposes internal relations).
- **`api/rag.php`** — RAG knowledge base endpoint (`?action=tags` GET, `?action=query` POST).
- **`rag.php`** — user-facing AI chat page (queries the local knowledge base via Ollama).
- **`Dockerfile` / `docker-compose.yml`** — containerized deployment.
- **`phpcs.xml`** — PSR-12 ruleset.
- **`cypress.config.js`** — Cypress E2E test framework configuration.
- **`cypress/e2e/`** — end-to-end test suites (login, admin, grid, CRUD).
- **`cypress/support/e2e.js`** — shared test helpers and utilities.
- **`composer.json`** — dev-only dependency manifest (`phpunit/phpunit ^11`). Not required for production.
- **`phpunit.xml`** — PHPUnit configuration (bootstrap, test suite directory, coverage source).

---

## Testing

### PHPUnit — unit tests

Pure unit tests covering the OOP `src/` layer. No database required.

```bash
# Install dev dependencies (once)
composer install

# Run all tests
vendor/bin/phpunit

# Or via Docker
docker compose exec app composer install --no-interaction
docker compose exec app vendor/bin/phpunit
```

**87 tests, 129 assertions** across 14 files. Mirrors `src/` namespace under `Tests\`:

| Wave | Scope | Key classes |
|---|---|---|
| Wave 1 | Pure logic, no mocks | `ByteFormatter`, `BoundValue`, `RecordData`, all `Form/Type/*` fields |
| Wave 2 | Interface stubs (anonymous classes) | `ColumnConfig`, `TableConfig`, `FieldTypeRegistry`, `UpdateMapper`, `SessionCsrfTokenManager` |

CI runs on PHP 8.1, 8.2, 8.3 via `.github/workflows/php-tests.yml`.

---

### Cypress — E2E tests

OpenSparrow includes a **Cypress E2E test suite** covering authentication, admin panel, grid operations, and CRUD workflows. Tests use the `data-cy` attribute selector strategy with intelligent fallbacks for robustness. Session caching and polling patterns prevent flakiness.

### Prerequisites

- Node.js 16+ (for npm)
- A running OpenSparrow instance (default: `http://localhost:8080`)

### Installation

```bash
npm install
```

This installs Cypress and its dependencies (dev-only, not required for production).

### Running tests

#### Headless mode (CI/CD friendly)

```bash
npm run cy:run
```

Runs all tests against headless Electron and reports results to the terminal. Artifacts (screenshots, videos) are saved on failure.

#### Interactive mode (development)

```bash
npm run cy:open
```

Opens the Cypress UI (Test Runner). Select a test file, watch it run, and inspect failures in real-time. Browser reloads on file changes (watch mode).

#### Run specific test suite

```bash
npm run cy:run -- --spec "cypress/e2e/login.cy.js"
npm run cy:run -- --spec "cypress/e2e/admin.cy.js"
npm run cy:run -- --spec "cypress/e2e/grid.cy.js"
npm run cy:run -- --spec "cypress/e2e/crud.cy.js"
```

#### Use a different browser

```bash
npm run cy:run -- --browser edge
npm run cy:run -- --browser chrome
```

Available browsers: `electron` (default, headless), `edge`, `chrome`. If Chrome is not installed, Edge works well on Windows.

### Test coverage

| Suite | Covers |
|-------|--------|
| `login.cy.js` | Authentication, dashboard display, sidebar, logout, mobile |
| `admin.cy.js` | Schema/dashboard/calendar tabs, config export/import, user management, access control |
| `grid.cy.js` | Grid display, search/filter, export, pagination, row actions, mobile |
| `crud.cy.js` | Create/edit forms, delete, validation, required/enum/pattern fields, subtables |
| `calendar.cy.js` | Calendar view, event navigation, drag-and-drop |
| `dashboard.cy.js` | Widget rendering and aggregation display |
| `keyboard_shortcuts.cy.js` | Arrow navigation, selection, clipboard, help modal |
| `mass_edit.cy.js` | Mass edit/delete/duplicate operations |
| `data_cleanup.cy.js` | Find & replace with accent support |
| `files.cy.js` | File attachments, tagging, search |
| `comments.cy.js` | Record comment threads |
| `notifications.cy.js` | Notification bell and dropdown |
| `views.cy.js` | Saved grid views |
| `workflows.cy.js` | Multi-step workflow wizards |
| `rag.cy.js` | Knowledge-base chat interface |

### Shared test helpers

All suites use helpers from `cypress/support/e2e.js`:

- **`loginAsTestUser()`** — authenticates as test user (test/test), caches session via `cy.session()`.
- **`waitForGridOrEmpty()`** — polls for grid table or empty state, returns `{type: 'grid'|'empty'}`.
- **`waitForActions()`** — verifies action buttons exist (desktop or mobile).
- **`clickAddIfPresent()`** — safely clicks Add button only if `onclick` is attached.
- **`waitForPagination()`** — tolerant pagination check (returns true if present).
- **`TIMEOUTS`** — constants for explicit waits: `short` (5s), `medium` (8s), `long` (15s).

### Troubleshooting

#### Browser not found

If Chrome is not installed:
```bash
npm run cy:run -- --browser edge
```

Edge is available on Windows/Mac and works as well as Chrome for testing.

#### Sandbox/IPC errors

If you see `Terminating renderer for bad IPC message, reason 114`:
- Already handled in `cypress.config.js` via `--no-sandbox`, `--disable-dev-shm-usage`, `--disable-gpu`.
- Clear Cypress cache: `rm -rf .cypress-cache/` and retry.

#### Tests are flaky

Flakiness usually comes from hardcoded waits. Our helpers use **polling with explicit timeouts** instead. If a test fails intermittently:
1. Check the helper is being used (e.g. `waitForGridOrEmpty()` not `cy.wait(2000)`).
2. Increase `TIMEOUTS.medium` in `cypress/support/e2e.js` if network is slow.
3. Verify the server is running and responsive at `http://localhost:8080`.

### Best practices

See [TESTING_GUIDELINES.md](docs/TESTING_GUIDELINES.md) for comprehensive guidance:

- **Selector strategy:** prefer `data-cy` attributes, fallback to semantic HTML and role.
- **Helper patterns:** use shared helpers instead of inline Cypress chains.
- **Assertions:** use explicit, readable assertions (`should('be.visible')` not `should('exist')`).
- **Mobile testing:** `cy.viewport()` for responsive checks.
- **Conditional tests:** gracefully skip unavailable features (e.g. enum fields if not present).
- **Code review checklist:** before opening a PR with test changes.
- **Common pitfalls:** hardcoded waits, flaky selectors, visibility vs. existence confusion.

---

## Getting Started

### Prerequisites

- PHP 8.1+
- PostgreSQL 14+
- Apache, Nginx, or the PHP built-in server
- Git

### 1. Clone

```bash
git clone https://github.com/wrobeltomasz/open-sparrow.git
cd open-sparrow
```

### 2. Install via ZIP (FTP / shared hosting)

If you are deploying to shared hosting or any server without Docker, download the pre-built ZIP from the [Releases page](https://github.com/wrobeltomasz/open-sparrow/releases/latest) instead of cloning.

Each release ZIP is built automatically by GitHub Actions and includes:
- All PHP, JS, and CSS files ready to serve
- `includes/VERSION` stamped with the release tag (e.g. `2.8`) — used by the admin System Health panel to display the current version
- `config/database.json.example` — template for PostgreSQL connection configuration (see step 3 below)
- An empty `storage/files/` directory placeholder

**Steps:**

1. Download `opensparrow-X.Y.zip` from the Releases page.
2. Extract and upload the files to your server via FTP, then set your site's **document root** to the `public/` sub-directory (e.g. point your domain / `public_html` at `.../public/`). The backend folders (`includes/`, `config/`, `storage/`, …) stay above the document root and are never served over HTTP.
3. Make the `config/` and `storage/files/` directories writable by the web server (typically `chmod 755` or `775`, depending on your host).
4. Open your site root in a browser — you will be automatically redirected to `/setup.php`. The **setup wizard** guides you through:
   - Testing your PostgreSQL connection
   - Choosing a schema name (default: `app`)
   - Initializing all system tables and creating the `admin` account with a **randomly generated password shown once in the wizard** — copy it before leaving the page
5. Go to `/login`, sign in as `admin` with the generated password. You are redirected to `/admin` automatically.
6. Go to **System → Users → Change pwd** and set your own strong password.

> **Note:** The ZIP contains no pre-configured JSON files except `database.json.example`. Your `config/*.json` configuration files are created on first setup and are never overwritten during updates — existing configuration is always preserved.

### 3. Run with Docker (quick start)

```bash
# Create required directories
mkdir -p config storage/files

# Set permissions (82:82 is www-data in Alpine)
sudo chown -R 82:82 config/ storage/
sudo chmod -R 775 config/ storage/

# Start the stack (PHP + Nginx + PostgreSQL)
docker compose up -d --build
```

Available at **http://localhost:8080**.

### 4. Dependencies

**Production:** none. No Composer, no npm, no build step required to run the application.

**Development (optional):** `composer install` installs PHPUnit for the unit test suite. `npm install` installs Cypress for E2E tests. Neither is needed to serve the app.

### 5. Environment variables (optional)

All variables are read by `includes/config.php` on every request — the single source of configuration. If a variable is absent the documented default applies. There is no `.env` loader: export in your shell, container, or web-server virtual-host config.

**Docker dev shortcut:** `docker-compose.override.yml` sets `APP_ENV=development` and `SECURE_COOKIES=false` automatically when you run `docker compose up` locally.

#### Database

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | PostgreSQL host. Falls back to `PGHOST`. |
| `DB_PORT` | `5432` | PostgreSQL port. Falls back to `PGPORT`. |
| `DB_CONNECT_TIMEOUT` | `5` | Seconds before connection attempt times out. |
| `APP_TIMEZONE` | `Europe/Warsaw` | IANA timezone applied per PostgreSQL session. |
| `PGDATABASE` | — | PostgreSQL database name. |
| `PGUSER` | — | PostgreSQL user. |
| `PGPASSWORD` | — | PostgreSQL password. |
| `PGSCHEMA` | `app` | Schema for `spw_*` tables. Overridden by `schema` key in `database.json`. |

#### Session & cookies

| Variable | Default | Description |
|---|---|---|
| `SECURE_COOKIES` | `true` | Set `false` on plain HTTP (local dev). |
| `SESSION_SAMESITE` | `Lax` | Cookie SameSite policy. Do not change to `Strict` — it causes `ERR_TOO_MANY_REDIRECTS` on the login→admin redirect. |
| `SESSION_MAX_LIFETIME` | `28800` | Hard session expiry in seconds (8 h). |

#### Authentication & rate limiting

| Variable | Default | Description |
|---|---|---|
| `IP_HASH_SALT` | *(auto)* | HMAC secret for IP pseudonymisation in login rate-limiting. If unset, a 64-char random salt is generated on first request and persisted to `includes/.secret_salt` (chmod 0600, gitignored, web-denied). Set explicitly via env var for multi-server deployments where all nodes must share the same salt. |
| `LOGIN_MAX_ATTEMPTS_PER_IP` | `20` | Failed login threshold per IP before lockout. |
| `LOGIN_MAX_ATTEMPTS_PER_USERNAME` | `5` | Failed login threshold per username before lockout. |
| `LOGIN_LOCKOUT_MINUTES` | `15` | Lockout window in minutes. |

#### Application behaviour

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | Runtime environment. |
| `DEMO_MODE` | `false` | Set `true` to block all write operations in the admin API (safe for public demos). |
| `RECORD_SNAPSHOTS_ENABLED` | `false` | Enable record snapshot capture after every INSERT/UPDATE. Overrides the admin panel toggle in `config/settings.json`. |
| `FILES_MAX_SIZE_MB` | `20` | Default upload size limit when not set in `files.json`. |
| `THUMBNAIL_MAX_WIDTH` | `300` | Max thumbnail width in pixels. |
| `NOTIFICATIONS_DROPDOWN_LIMIT` | `10` | Max items in the bell notification dropdown. |
| `HSTS_MAX_AGE` | `31536000` | HSTS `max-age` in seconds (1 year). Set `0` to disable on plain HTTP. |

#### AI / RAG (Knowledge Base)

| Variable | Default | Description |
|---|---|---|
| `OLLAMA_URL` | `http://localhost:11434` | Base URL of the local Ollama instance. Used by `api/rag.php` and admin RAG actions. |
| `OLLAMA_MODEL` | `llama3` | Default Ollama model for RAG queries. Overridden by `config/rag.json` if present. |

### 6. First-run setup (Docker or bare server)

On a fresh installation — when `config/database.json` does not exist — any request to the application is automatically redirected to the **setup wizard** at `/setup.php`.

The wizard walks you through four steps:

1. **Welcome** — intro and requirements overview.
2. **Database Connection** — enter host, port, database name, username, and password. Click **Test Connection** to verify before proceeding.
3. **Schema** — choose the PostgreSQL schema name (default: `app`). Optionally tick *Create schema if not exists*.
4. **Review & Initialize** — confirm settings and click **Initialize System Tables**. The wizard creates all `spw_*` tables, seeds the `admin` account with a **randomly generated password displayed once on this screen**, and writes `config/database.json`.

After initialization you are redirected to `/login`. Sign in as `admin` with the password shown in the wizard, then go to **System → Users → Change pwd** and set your own password.

> Once `config/database.json` exists, the setup wizard is permanently inaccessible — all entry points redirect to `/login` instead.

### 7. User roles

All accounts are stored in `spw_users` and managed from **System → Users**. Three roles are available:

| Role | Admin panel | Frontend app |
|---|---|---|
| `admin` | ✅ Full access | ❌ Blocked |
| `editor` | ❌ Blocked | ✅ Full CRUD |
| `viewer` | ❌ Blocked | 👁 Read-only |

- **Password reset:** click **Change pwd** next to any user. For your own account the current password is required; for other accounts the admin can override without it.
- Re-run **Initialize System Tables** after every upgrade — it uses `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE … ADD COLUMN IF NOT EXISTS` and also migrates legacy roles (`full → editor`, `readonly → viewer`).

### 8. Run without Docker

*Skip this if you used Docker in step 3.*

**Option A** — serve via Apache/Nginx and open:
```
http://localhost/open-sparrow/
```

**Option B** — PHP built-in server:
```bash
php -S localhost:8000
```
Open **http://localhost:8000/admin**.

---

## Updating via FTP

1. Go to the [Releases page](https://github.com/wrobeltomasz/open-sparrow/releases/latest) and download the latest `opensparrow-X.Y.zip`.
2. **Before uploading** — export your configuration from the admin panel: **Configuration → Export config files**. Keep this backup safe.
3. Extract the ZIP and upload all files to your server via FTP, overwriting existing files.
4. Your `config/*.json` files are **not included** in the ZIP, so your database connection, schema, dashboards, and all other settings are preserved automatically.
5. Log in to `/admin` → **System Health** → **Initialize System Tables** to apply any new system table migrations.
6. Check **System Health** — the version shown should match the release tag you just uploaded.

---

## Security & Configuration

Configuration lives in `config/database.json`. The web document root is the `public/` directory, so `config/` (and every other backend folder) sits **outside** the web root and cannot be served over HTTP at all. Environment variables (see section 5) take precedence and are the recommended approach for containerized deployments.

- **Production:** serve only the `public/` directory — set your web server's document root to `public/` (the shipped `nginx.conf` / `nginx.standalone.conf` already do this). Backend folders (`includes/`, `config/`, `src/`, `vendor/`, `storage/`, …) live above it and are unreachable over HTTP. The per-folder `Deny from all` `.htaccess` files remain as defense-in-depth.
- **Cookies:** `SECURE_COOKIES=true` (default) enforces the `Secure` flag. Set to `false` only on plain HTTP environments.
- **Authentication:** all roles share a single login page (`/login`). The admin panel (`/admin`) requires role `admin`. Frontend pages require role `editor` or `viewer`. There is no separate admin password file — all accounts live in `spw_users`.
- **Session security:** sessions include a User-Agent fingerprint and an 8-hour absolute lifetime to guard against hijacking and stale sessions.
- **Reverse-proxy aware:** `includes/config.php` auto-detects HTTPS through CloudFlare / Nginx / load-balancer headers (`X-Forwarded-Proto`, `CF-Visitor`, `X-Forwarded-SSL`), resolves the real client IP via `CF-Connecting-IP` / `X-Real-IP`, and forces an absolute `session.save_path` so PHP-FPM `chdir` behaviour does not split sessions across script directories.
- **Session storage hardening:** session files are stored in `storage/sessions/` (resolved to an absolute path by `includes/config.php`), which lives outside the `public/` document root; a `.htaccess` denying HTTP access also ships as defense-in-depth.

---

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and sign the [Contributor License Agreement (CLA)](CLA.md) before opening a pull request.

---

## License

Copyright © 2024–2026 OpenSparrow Contributors. Licensed under the **GNU Lesser General Public License v3.0 (LGPL v3)**.

You may use OpenSparrow in open-source and closed-source commercial projects. Modifications to core OpenSparrow files must remain under the same license. The LGPL v3 is a set of additional permissions on top of the GPL v3: see [COPYING.LESSER](COPYING.LESSER) for the LGPL terms and [COPYING](COPYING) for the base GPL v3.
