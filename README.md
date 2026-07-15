<div align="center">

<img width="100" height="100" alt="opensparrow-logo" src="https://github.com/user-attachments/assets/b4793826-edc3-4ede-99e1-bdbd9c12f0bb" />

  <h1>OpenSparrow</h1>

  <p><strong>Schema-driven PHP platform to build CRUD apps, dashboards, and calendars on PostgreSQL in minutes.</strong></p>

  <p>
    <a href="COPYING.LESSER"><img src="https://img.shields.io/badge/License-LGPL%20v3-blue.svg" alt="License: LGPL v3" /></a>
    <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.4+" /></a>
    <a href="https://www.postgresql.org/"><img src="https://img.shields.io/badge/PostgreSQL-14%2B-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL" /></a>
    <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript"><img src="https://img.shields.io/badge/JavaScript-ES6%2B-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript ES6+" /></a>
    <a href="#"><img src="https://img.shields.io/badge/dependencies-none-brightgreen" alt="No dependencies" /></a>
  </p>

  ![PHP Tests](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/php-tests.yml/badge.svg)
  ![Vanilla Check](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/vanilla-check.yml/badge.svg)
  ![CodeQL Analysis](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/codeql.yml/badge.svg)
  ![Docker Lint](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/docker-lint.yml/badge.svg)
  [![Release ZIP](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/release-zip.yml/badge.svg)](https://github.com/wrobeltomasz/OpenSparrow/actions/workflows/release-zip.yml)

</div>

---

## Overview

OpenSparrow is a JSON schema-driven platform for building internal systems. Tables, forms, dashboards, and calendars are generated from configuration files, so business logic stays decoupled from infrastructure. Self-hosted on PostgreSQL — no vendor lock-in, full data ownership.

> **No Composer. No npm. No build step** — in production.  
> Drop the files, point to PostgreSQL, open your site — the **setup wizard** does the rest.  
> Composer is used **dev-only** for the PHPUnit test suite (`composer install` is never required to run the application).

Project website: https://opensparrow.org

Demo: https://demo.opensparrow.org

---

## Deploy in Minutes

Pick the path that matches your environment. Every path ends at the same place: the first-run **setup wizard** configures the database, creates all system tables, and seeds the admin account for you.

| Path | Best for | Time | Guide |
|---|---|---|---|
| **One-click cloud** | Trying it out with zero infrastructure (Render, Railway) | ~5 min | [Path A](#path-a--one-click-cloud-render--railway) |
| **Docker** | VPS, local machine, any server with Docker | ~5 min | [Path B](#path-b--docker) |
| **FTP / shared hosting** | Classic web hosting with PHP + PostgreSQL | ~10 min | [Path C](#path-c--ftp--shared-hosting-zip) |
| **Local development** | Hacking on the code with the PHP built-in server | ~5 min | [Path D](#path-d--local-development-no-docker) |

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

## Installation

### Prerequisites

- PHP 8.4+ and PostgreSQL 14+ (Paths C and D; Docker and cloud paths bring their own)
- Apache, Nginx, or the PHP built-in server

Each path below is self-contained — follow one from start to finish.

### Path A — One-click cloud (Render / Railway)

The fastest way to a running instance. Both configs build from `Dockerfile.standalone` (Nginx + PHP-FPM in one container).

**Render** — the repository ships a [`render.yaml`](render.yaml) Blueprint that creates a managed PostgreSQL database (free tier), the web service, and a cron worker for notifications:

1. Open https://render.com/deploy?repo=https://github.com/wrobeltomasz/OpenSparrow and confirm the Blueprint.
2. When the web service is live, open its URL — you are redirected to the **setup wizard**. Enter the database credentials shown in the Render dashboard (Database → Connections, use the *internal* hostname), then follow the [wizard steps](#first-run-setup-wizard).

> **Free-tier caveats:** the filesystem is ephemeral — `config/*.json` and uploaded files do not survive a redeploy or spin-down (the wizard will simply re-run; your data in PostgreSQL is safe). For real use, attach a persistent disk (see comments in `render.yaml`). The free database expires after 90 days.

**Railway** — the repository ships a [`railway.toml`](railway.toml) read automatically on deploy:

1. Create a new Railway project and add a **PostgreSQL** service (Dashboard → New → Database). Railway auto-injects `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`.
2. Deploy this repo as a service. Set the remaining env vars listed in the comments of `railway.toml` (`PGSCHEMA`, `APP_ENV`, `SECURE_COOKIES`, `IP_HASH_SALT`, `SESSION_MAX_LIFETIME`).
3. Open the generated URL — you are redirected to the **setup wizard**; follow the [wizard steps](#first-run-setup-wizard).
4. Optional cron notifications: add a second service on the same repo with start command `php /var/www/html/cron/cron_notifications.php` and a Cron trigger of `* * * * *`.

### Path B — Docker

The bundled [`docker-compose.yml`](docker-compose.yml) starts the full stack: PHP-FPM + Nginx + PostgreSQL.

```bash
git clone https://github.com/wrobeltomasz/OpenSparrow.git
cd OpenSparrow
docker compose up -d --build
```

On **Linux hosts**, make the mounted directories writable by the container's `www-data` user (UID 82 in Alpine) first:

```bash
sudo chown -R 82:82 config/ storage/
sudo chmod -R 775 config/ storage/
```

On **Windows and macOS** (Docker Desktop) this step is not needed — bind mounts are writable by default.

Open **http://localhost:8080** — you are redirected to the **setup wizard**; follow the [wizard steps](#first-run-setup-wizard). The wizard's database host is `db` (the compose service name).

> **Dev shortcut:** `docker-compose.override.yml` sets `APP_ENV=development` and `SECURE_COOKIES=false` automatically for local `docker compose up`.
>
> **Production:** use [`docker-compose-production.yml`](docker-compose-production.yml) instead — it adds PostgreSQL tuning, health checks, log rotation, and requires an explicit `POSTGRES_PASSWORD`. See [docs/PRODUCTION_SETUP.md](docs/PRODUCTION_SETUP.md).

### Path C — FTP / shared hosting (ZIP)

No Git, no Docker, no command line on the server — just files over FTP. You need a PostgreSQL database (most hosts provide one in their control panel; note its host, name, user, and password).

Each release ZIP is built automatically by GitHub Actions and includes all PHP, JS, and CSS files ready to serve, `includes/VERSION` stamped with the release tag, `config/database.json.example`, and an empty `storage/files/` placeholder.

1. Download `opensparrow-X.Y.zip` from the [Releases page](https://github.com/wrobeltomasz/OpenSparrow/releases/latest) and extract it locally.
2. Upload the files via FTP and set your site's **document root** to the `public/` sub-directory (e.g. point your domain / `public_html` at `.../public/`). Backend folders (`includes/`, `config/`, `storage/`, …) stay above the document root and are never served over HTTP.

   > **Host doesn't let you change the document root?** Upload the *contents* of `public/` into your web root (e.g. `public_html/`) and the remaining folders (`includes/`, `config/`, `src/`, `storage/`, `templates/`, `cron/`, `languages/`) one level **above** it. The code references them via relative paths (`../includes`), so this layout works out of the box. As a last resort, uploading the whole tree into the web root also works on Apache — every backend folder ships a `Deny from all` `.htaccess` — but keeping backend folders outside the web root is strongly preferred.

3. Make `config/` and `storage/files/` writable by the web server (typically `chmod 755` or `775`, depending on your host).
4. Open your site in a browser — you are redirected to the **setup wizard**; follow the [wizard steps](#first-run-setup-wizard).

> **Note:** The ZIP contains no pre-configured JSON files except `database.json.example`. Your `config/*.json` files are created on first setup and are never overwritten during updates — existing configuration is always preserved.

### Path D — Local development (no Docker)

```bash
git clone https://github.com/wrobeltomasz/OpenSparrow.git
cd OpenSparrow

# Serve the public/ directory (the document root) with the PHP built-in server
php -S localhost:8000 -t public
```

Open **http://localhost:8000** — you are redirected to the **setup wizard**. For plain-HTTP local work set `SECURE_COOKIES=false` in your environment first, otherwise the session cookie will not stick. If you plan to run the Cypress E2E suite against this server, also set `APP_ENV=development` — `cypress_seed.php` hard-404s unless it is set, since that endpoint is disabled in production.

Using Apache/Nginx instead: point the virtual host's document root at `public/` (the shipped `nginx.conf` already does this) and open your local URL.

---

## First-run setup wizard

On a fresh installation — whenever `config/database.json` does not exist — any request to the application is automatically redirected to the **setup wizard** at `/setup.php`.

The wizard walks you through four steps:

1. **Welcome** — intro and requirements overview.
2. **Database Connection** — enter host, port, database name, username, and password. Click **Test Connection** to verify before proceeding.
3. **Schema** — choose the PostgreSQL schema name (default: `app`). Optionally tick *Create schema if not exists*.
4. **Review & Initialize** — confirm settings and click **Initialize System Tables**. The wizard creates all `spw_*` tables, seeds the `admin` account with a **randomly generated password displayed once on this screen** — copy it before leaving the page — and writes `config/database.json`.

After initialization you are redirected to `/login`. Sign in as `admin` with the password shown in the wizard, then go to **System → Users → Change pwd** and set your own password.

> Once `config/database.json` exists, the setup wizard is permanently inaccessible — all entry points redirect to `/login` instead.

### User roles

All accounts are stored in `spw_users` and managed from **System → Users**. Three roles are available:

| Role | Admin panel | Frontend app |
|---|---|---|
| `admin` | Full access | Blocked |
| `editor` | Blocked | Full CRUD |
| `viewer` | Blocked | Read-only |

- **Password reset:** click **Change pwd** next to any user. For your own account the current password is required; for other accounts the admin can override without it.
- Re-run **Initialize System Tables** after every upgrade — it uses `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE … ADD COLUMN IF NOT EXISTS` and also migrates legacy roles (`full → editor`, `readonly → viewer`).

---

## Updating via FTP

1. Go to the [Releases page](https://github.com/wrobeltomasz/OpenSparrow/releases/latest) and download the latest `opensparrow-X.Y.zip`.
2. **Before uploading** — export your configuration from the admin panel: **Configuration → Export config files**. Keep this backup safe.
3. Extract the ZIP and upload all files to your server via FTP, overwriting existing files.
4. Your `config/*.json` files are **not included** in the ZIP, so your database connection, schema, dashboards, and all other settings are preserved automatically.
5. Log in to `/admin` → **System Health** → **Initialize System Tables** to apply any new system table migrations.
6. Check **System Health** — the version shown should match the release tag you just uploaded.

---

## Configuration

**Production dependencies: none.** No Composer, no npm, no build step required to run the application. Development tooling (`composer install` for PHPUnit, `npm install` for Cypress) is optional and never needed to serve the app.

### Environment variables (optional)

All variables are read by `includes/config.php` on every request — the single source of configuration. If a variable is absent the documented default applies. There is no `.env` loader: export in your shell, container, or web-server virtual-host config.

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
| `SESSION_SAVE_PATH` | *(auto)* | Absolute path for PHP session storage. When unset, defaults to `storage/sessions/` inside the project root — overriding any server-level `session.save_path`, which on some shared hosts (e.g. home.pl) differs per subdirectory and may point to a system `/tmp` blocked by `open_basedir`. Set explicitly when your host requires a specific path or for shared storage across nodes. |

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
- **`api/rag.php`** — RAG knowledge base endpoint (`?action=tags` GET, `?action=query` POST); consumed by the slide-in "Ask AI" panel (`assets/js/agent-panel.js`).
- **`Dockerfile` / `docker-compose.yml`** — containerized deployment (dev stack). **`Dockerfile.standalone`** — single-container image (Nginx + PHP-FPM) used by Render / Railway. **`docker-compose-production.yml`** — hardened production stack.
- **`render.yaml` / `railway.toml`** — one-click cloud deploy configs.
- **`phpcs.xml`** — PSR-12 ruleset.
- **`cypress.config.js`** — Cypress E2E test framework configuration.
- **`composer.json`** — dev-only dependency manifest (`phpunit/phpunit`). Not required for production.
- **`phpunit.xml`** — PHPUnit configuration (bootstrap, test suite directory, coverage source).

---

## Security & Configuration

Configuration lives in `config/database.json`. The web document root is the `public/` directory, so `config/` (and every other backend folder) sits **outside** the web root and cannot be served over HTTP at all. Environment variables (see [Configuration](#configuration)) take precedence and are the recommended approach for containerized deployments. For a production deployment checklist see [docs/PRODUCTION_SETUP.md](docs/PRODUCTION_SETUP.md).

- **Production:** serve only the `public/` directory — set your web server's document root to `public/` (the shipped `nginx.conf` / `nginx.standalone.conf` already do this). Backend folders (`includes/`, `config/`, `src/`, `vendor/`, `storage/`, …) live above it and are unreachable over HTTP. The per-folder `Deny from all` `.htaccess` files remain as defense-in-depth.
- **Cookies:** `SECURE_COOKIES=true` (default) enforces the `Secure` flag. Set to `false` only on plain HTTP environments.
- **Authentication:** all roles share a single login page (`/login`). The admin panel (`/admin`) requires role `admin`. Frontend pages require role `editor` or `viewer`. There is no separate admin password file — all accounts live in `spw_users`.
- **Session security:** sessions include a User-Agent fingerprint and an 8-hour absolute lifetime to guard against hijacking and stale sessions.
- **Reverse-proxy aware:** `includes/config.php` auto-detects HTTPS through CloudFlare / Nginx / load-balancer headers (`X-Forwarded-Proto`, `CF-Visitor`, `X-Forwarded-SSL`), resolves the real client IP via `CF-Connecting-IP` / `X-Real-IP`, and forces an absolute `session.save_path` so PHP-FPM `chdir` behaviour does not split sessions across script directories.
- **Session storage hardening:** session files are stored in `storage/sessions/` (resolved to an absolute path by `includes/config.php`), which lives outside the `public/` document root; a `.htaccess` denying HTTP access also ships as defense-in-depth.

---

## Testing

Testing tooling is **dev-only** — none of it is needed to run the application.

**PHPUnit — unit tests.** Pure unit tests covering the OOP `src/` layer; no database required. **87 tests, 129 assertions** across 14 files, mirroring the `src/` namespace under `Tests\`. CI runs on PHP 8.4, 8.5 via `.github/workflows/php-tests.yml`.

```bash
composer install          # once
vendor/bin/phpunit
```

**Cypress — E2E tests.** 15 suites covering authentication, admin panel, grid operations, CRUD workflows, calendar, files, comments, notifications, views, workflows, and the RAG chat. Requires Node.js 16+ and a running instance (default `http://localhost:8080`).

```bash
npm install               # once
npm run cy:run            # headless (CI-friendly)
npm run cy:open           # interactive Test Runner
npm run cy:run -- --spec "cypress/e2e/login.cy.js"   # single suite
npm run cy:run -- --browser edge                     # alternate browser
```

Shared helpers (`loginAsTestUser()`, `waitForGridOrEmpty()`, polling timeouts) live in `cypress/support/e2e.js`. For the selector strategy, helper patterns, troubleshooting (browser not found, sandbox/IPC errors, flakiness), and the PR checklist, see [docs/TESTING_GUIDELINES.md](docs/TESTING_GUIDELINES.md).

---

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and sign the [Contributor License Agreement (CLA)](CLA.md) before opening a pull request.

---

## License

Copyright © 2024–2026 OpenSparrow Contributors. Licensed under the **GNU Lesser General Public License v3.0 (LGPL v3)**.

You may use OpenSparrow in open-source and closed-source commercial projects. Modifications to core OpenSparrow files must remain under the same license. The LGPL v3 is a set of additional permissions on top of the GPL v3: see [COPYING.LESSER](COPYING.LESSER) for the LGPL terms and [COPYING](COPYING) for the base GPL v3.
