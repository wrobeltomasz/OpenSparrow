<div align="center">

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

OpenSparrow is a JSON schema-driven platform for building internal systems. Tables, forms, dashboards, and calendars are generated from configuration stored in PostgreSQL, so business logic stays decoupled from infrastructure. Self-hosted — no vendor lock-in, full data ownership.

> **No Composer. No npm. No build step** — in production.  
> Drop the files, point to PostgreSQL, open your site — the **setup wizard** does the rest.  
> Composer is used **dev-only** for the PHPUnit test suite (`composer install` is never required to run the application).

Project website: https://opensparrow.org

Demo: https://demo.opensparrow.org

---

## Quick Start

With Docker installed (on Linux, apply the [ownership step](#path-b--docker) before starting):

```bash
git clone https://github.com/wrobeltomasz/OpenSparrow.git
cd OpenSparrow
docker compose up -d --build
```

Open **http://localhost:8080** and follow the setup wizard. For cloud, shared-hosting and local PHP options, use the deployment paths below.

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

<img width="2028" height="1086" alt="OpenSparrow grid interface" src="https://github.com/user-attachments/assets/1e322af2-1615-41ec-9589-85a9c64dde63" />

### See it in action

Module configuration is visual and requires no file edits. Browse the guides and screenshots for [RAG](docs/ADMIN.md#15-rag-retrieval-augmented-generation) ([screenshot](https://github.com/user-attachments/assets/10228ffb-27f9-413b-a645-9c5f59b75c80)), [Calendar](docs/ADMIN.md#4-calendar-module) ([screenshot](https://github.com/user-attachments/assets/868f7144-7b71-4819-aa2c-9b7b5eb9159f)), [Board](docs/ADMIN.md#4b-board-kanban-module) ([screenshot](https://github.com/user-attachments/assets/7cc2ec05-ceb7-498c-994c-71a9747249f7)) and [personal modules](docs/ADMIN.md#10c-user-records) ([screenshot](https://github.com/user-attachments/assets/1509c524-4bbc-44f4-8263-f944ce5b78cf)), or try the [live demo](https://demo.opensparrow.org).

---

## Features

- **Config-driven CRUD** — schema-generated tables and forms with inline editing, relations, image galleries, row highlighting, CSV export and bulk operations.
- **Visual administration** — configure modules and build PostgreSQL tables, columns, indexes and foreign keys from the browser.
- **Dashboards and planning views** — charts, calendars with reminders, Kanban boards, Gantt roadmaps, saved views and printable reports.
- **Access control and security** — three roles, per-user scopes, per-table write permissions, viewer column masking, optional email 2FA and audit snapshots.
- **Collaboration** — record attachments and comments, private notes, ownership history, notifications and one-to-one user chat.
- **Workflows and automations** — multi-step record wizards, conditional triggers, notifications, emails and signed outbound webhooks for n8n, Make or custom receivers.
- **AI knowledge base (RAG)** — searchable documents and database views with answers from local or cloud [Ollama](https://ollama.com) models.
- **External API** — encrypted, key-authenticated read access to selected table or view columns, with fixed filters, limits and usage statistics.
- **Data integration** — CSV import plus scheduled ETL from MySQL, MariaDB, PostgreSQL, SQLite and FTP/FTPS CSV sources.
- **Privacy and analytics** — optional GDPR anonymization, record snapshots and click statistics with configurable retention.
- **First-run wizard and demo CRM** — initialize the database and admin account, then optionally install a ready-made example application.
- **Accessible, dependency-free frontend** — WCAG 2.1-oriented UI built with vanilla JavaScript and CSS.

See the [admin guide](docs/ADMIN.md), [security notes](docs/SECURITY.md) and [database reference](docs/DATABASE.md) for module details.

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

> **Free-tier caveats:** the filesystem is ephemeral — `config/database.json` and uploaded files do not survive a redeploy or spin-down (the wizard will simply re-run; your data and application configuration live in PostgreSQL and are safe). For real use, attach a persistent disk (see comments in `render.yaml`). The free database expires after 90 days.

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

3. Default permissions: files `644`, directories `755`. Make `config/`, `storage/` and `public/assets/img/uploads/` writable by the web server — `755` works on most shared hosts (PHP runs as your account user), otherwise use `775`. Avoid `777`; if your host requires it, apply it only to those writable directories, never to PHP files or `config/*.json`.
4. Open your site in a browser — you are redirected to the **setup wizard**; follow the [wizard steps](#first-run-setup-wizard).

> **Note:** The ZIP contains no pre-configured JSON files except `database.json.example`. Your `config/database.json` is created on first setup and is never overwritten during updates; all other configuration lives in the database (`spw_config`) — existing configuration is always preserved.

#### Scheduling the notification cron (shared hosting)

The notification worker (`cron/cron_notifications.php`) is **CLI-only** and lives outside the web root, so a shared host that cannot run a real cron daemon cannot invoke it directly. Instead, OpenSparrow ships a token-protected HTTP trigger at `cron.php` (part of the `public/` tree, so it is web-accessible in either of the layouts above).

1. Set the `CRON_SECRET` environment variable (or export it in your host's PHP settings) to a long random value — `openssl rand -hex 32`. Empty disables the endpoint.
2. Schedule a request to it from whatever scheduler your host offers:

   - **cPanel / Plesk "cron job"** with `wget`/`curl`:
     ```
     wget -q -O /dev/null "https://crm.example.com/cron.php?token=YOUR_SECRET"
     curl -f -s "https://crm.example.com/cron.php?token=YOUR_SECRET"
     ```
   - **External URL cron** (cron-job.org, EasyCron, UptimeRobot, n8n): point a GET at `https://crm.example.com/cron.php?token=YOUR_SECRET` on the desired interval.

The endpoint returns plain text of the run and re-uses the same deduplication as the CLI worker, so overlapping runs are harmless. On a VPS or Docker you can keep using the CLI worker directly (`php …/cron/cron_notifications.php`).

### Path D — Local development (no Docker)

```bash
git clone https://github.com/wrobeltomasz/OpenSparrow.git
cd OpenSparrow

# Serve the public/ directory (the document root) with the PHP built-in server
php -S localhost:8000 -t public
```

Open **http://localhost:8000** — you are redirected to the **setup wizard**. For plain-HTTP local work set `SECURE_COOKIES=false` in your environment first, otherwise the session cookie will not stick.

Using Apache/Nginx instead: point the virtual host's document root at `public/` (the shipped `nginx.conf` already does this) and open your local URL.

---

## First-run setup wizard

On a fresh installation — whenever `config/database.json` does not exist — any request to the application is automatically redirected to the **setup wizard** at `/setup.php`.

The wizard walks you through four steps:

1. **Welcome** — intro and requirements overview.
2. **Database Connection** — enter host, port, database name, username, and password. Click **Test Connection** to verify before proceeding. Only the host (`localhost`) and port (`5432`) are prefilled — the database name and username start empty.
3. **Schema** — choose the PostgreSQL schema name (e.g. `app`). Optionally tick *Create schema if not exists*.

   > **Destructive option:** the third checkbox, *Drop and recreate this schema*, runs `DROP SCHEMA … CASCADE`. Everything in that schema is permanently deleted, including tables the wizard did not create. Leave it unticked unless you are deliberately wiping a scratch installation.
4. **Review & Initialize** — confirm settings and click **Initialize System Tables**. The wizard creates all `spw_*` tables, seeds the `admin` account with a **randomly generated password displayed once on this screen** — copy it before leaving the page — and writes `config/database.json`.

After initialization you are redirected to `/login`. Sign in as `admin` with the password shown in the wizard, then go to **System → Users → Change pwd** and set your own password. If you want a ready-made example application (companies, contacts, deals, activities, dashboards, board, roadmap, printouts) to explore, install the CRM demo afterwards from **System → Demo Systems** — each part (demo users, RAG documents, seeded audit history) is optional there.

> Once `config/database.json` exists, the setup wizard is permanently inaccessible — all entry points redirect to `/login` instead.

### User roles

All accounts are stored in `spw_users` and managed from **System → Users**. Three roles are available:

| Role | Admin panel | Frontend app |
|---|---|---|
| `admin` | Full access | Blocked |
| `editor` | Blocked | Full CRUD |
| `viewer` | Blocked | Read-only |

Roles decide *what* an account may do. The **Access** tab decides *where* it may do it: pick a user and tick the tables, views, printouts, boards, roadmaps and workflows they may reach on the frontend. The groups are independent, and leaving a group empty means **no restriction** for that group — not "no access". To cut an account off entirely, deactivate it rather than clearing its checkboxes. Admin accounts are never restricted and are not listed there. The selection is stored in the `user_table_access` configuration key and takes effect on the user's next request, without them logging out.

The table allow-list is the *read* grant. Each ticked table also carries three operation checkboxes — **Create**, **Edit**, **Delete** — that narrow what an editor may write to it. Leaving all three ticked (the default) keeps full CRUD; unticking one refuses that operation server-side while read stays granted; a table with none ticked is read-only. The full set is never persisted (it is the same as "unrestricted"), so the stored mask only ever records narrowed tables.

Granting a board, a roadmap or a workflow does not grant the tables it uses — both ticks have to hold, so a board or roadmap whose table you did not grant is not listed, and a workflow is dropped when any of its steps targets a table the user cannot reach. Hidden tables are the one automatic case: they have no menu entry and no grid, so they cannot be ticked and are granted along with the table whose subtable they are.

- **Password reset:** click **Change pwd** next to any user. For your own account the current password is required; for other accounts the admin can override without it.
- Re-run **System → Migrations → Apply Pending Migrations** after every upgrade — it uses `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE … ADD COLUMN IF NOT EXISTS`. (Older docs and some in-app messages still call this "Initialize System Tables" — it is the same `init_db` action.)

---

## Updating via FTP

1. Go to the [Releases page](https://github.com/wrobeltomasz/OpenSparrow/releases/latest) and download the latest `opensparrow-X.Y.zip`.
2. **Before uploading** — export your configuration from the admin panel: **Configuration → Export config**. Keep this backup safe.
3. Extract the ZIP and upload all files to your server via FTP, overwriting existing files.
4. Your `config/database.json` is **not included** in the ZIP and your schema, dashboards, and all other settings live in the database — configuration is preserved automatically.
5. Log in to `/admin` → **System → Migrations** → **Apply Pending Migrations**. See `config/migrations.json` for the per-release history.
6. Check **System → Health Check** — the version shown should match the release tag you just uploaded.

---

## Configuration

**Production dependencies: none.** No Composer, no npm, no build step required to run the application. Development tooling (`composer install` for PHPUnit) is optional and never needed to serve the app.

### Environment variables (optional)

Most installations need only database credentials and, for plain-HTTP local development, `SECURE_COOKIES=false`. Variables are read by `includes/config.php`; export them in your shell, container or web-server configuration.

| Variable | Default | Purpose |
|---|---|---|
| `DB_HOST` | `localhost` | PostgreSQL host (`DB_PORT` defaults to `5432`). |
| `DB_NAME` | — | PostgreSQL database name. |
| `DB_USER` / `DB_PASSWORD` | — | PostgreSQL credentials. |
| `APP_ENV` | `production` | Runtime environment. |
| `SECURE_COOKIES` | `true` | Set `false` for local plain HTTP. |
| `CRON_SECRET` | *(empty)* | Enables the token-protected HTTP cron endpoint when set. |

See the [complete environment variable reference](docs/ENV.md) for session, security, storage, rate-limiting, SMTP and AI settings.

---

## Project Structure

The **web document root is `public/`**; backend code and writable data stay outside it and cannot be reached over HTTP.

- **`public/`** — entry pages, the admin panel, static assets and module API endpoints.
- **`includes/`** — request helpers, controllers, services, admin modules and autoloading.
- **`src/`** — PSR-4 OOP application layer under the `App\` namespace.
- **`config/`** — bootstrap files; application configuration lives in PostgreSQL (`spw_config`).
- **`templates/`, `cron/` and `storage/`** — layouts, scheduled workers and writable data.
- **`tests/`** — the PHPUnit suite; Docker and cloud deployment files sit at the repository root.

See [maintenance decisions](docs/MAINTENANCE.md) for architecture and conventions, and [production setup](docs/PRODUCTION_SETUP.md) for deployment hardening.

---

## Security & Configuration

Configuration lives in `config/database.json`. The web document root is the `public/` directory, so `config/` (and every other backend folder) sits **outside** the web root and cannot be served over HTTP at all. Environment variables (see [Configuration](#configuration)) take precedence and are the recommended approach for containerized deployments. For a production deployment checklist see [docs/PRODUCTION_SETUP.md](docs/PRODUCTION_SETUP.md).

- **Production:** serve only the `public/` directory — set your web server's document root to `public/` (the shipped `nginx.conf` / `nginx.standalone.conf` already do this). Backend folders (`includes/`, `config/`, `src/`, `vendor/`, `storage/`, …) live above it and are unreachable over HTTP. The per-folder `Deny from all` `.htaccess` files remain as defense-in-depth.
- **Cookies:** `SECURE_COOKIES=true` (default) enforces the `Secure` flag. Set to `false` only on plain HTTP environments.
- **Setup wizard lockout:** the installation wizard refuses to run once `config/database.json` exists. Set `SETUP_ENABLED=false` after installing to add a second, independent lock that does not depend on that file surviving — `setup.php` then redirects to the login page and `setup_api.php` answers 403.
- **Authentication:** all roles share a single login page (`/login`). The admin panel (`/admin`) requires role `admin`. Frontend pages require role `editor` or `viewer`. There is no separate admin password file — all accounts live in `spw_users`.
- **Session security:** sessions include a User-Agent fingerprint and an 8-hour absolute lifetime to guard against hijacking and stale sessions.
- **Reverse-proxy aware:** `includes/config.php` auto-detects HTTPS through CloudFlare / Nginx / load-balancer headers (`X-Forwarded-Proto`, `CF-Visitor`, `X-Forwarded-SSL`) and forces an absolute `session.save_path` so PHP-FPM `chdir` behaviour does not split sessions across script directories. Client-IP headers are a separate, opt-in decision: set `TRUST_PROXY_HEADERS=true` and list the proxy in `TRUSTED_PROXY_IPS` before `CF-Connecting-IP` / `X-Real-IP` / `X-Forwarded-For` are believed.
- **Session storage hardening:** session files are stored in `storage/sessions/` (resolved to an absolute path by `includes/config.php`, relocatable with `STORAGE_PATH`), which lives outside the `public/` document root; a `.htaccess` denying HTTP access also ships as defense-in-depth.
- **Database TLS:** `DB_SSLMODE` sets the libpq TLS mode on every connection. The default `prefer` matches what libpq negotiated before the setting existed; use `require` or `verify-full` (with `DB_SSLROOTCERT`) whenever PostgreSQL is reached over a network you do not own.
- **API rate limiting:** authenticated API endpoints are capped per user per endpoint by `API_RATE_LIMIT_PER_MIN` (default 120/min, `429` with `Retry-After` when exceeded), independently of the login lockout thresholds.

---

## Testing

Testing tooling is **dev-only** — none of it is needed to run the application.

**PHPUnit — unit tests.** Pure unit tests covering the OOP `src/` layer, the admin API guards (CSRF / demo-mode / migration registry) and the language-file contract; no database required. **471 tests, 1180 assertions** across 40 files under `tests/`. CI runs on PHP 8.4, 8.5 via `.github/workflows/php-tests.yml`.

```bash
composer install          # once
vendor/bin/phpunit
```

Behaviour changes are verified manually against a local instance (`php -S localhost:8080 -t public`) — the legacy Cypress E2E suite is no longer part of development (see [docs/TESTING_GUIDELINES.md](docs/TESTING_GUIDELINES.md) for the test policy).

---

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and sign the [Contributor License Agreement (CLA)](CLA.md) before opening a pull request.

---

## License

Copyright © 2024–2026 OpenSparrow Contributors. Licensed under the **GNU Lesser General Public License v3.0 (LGPL v3)**.

You may use OpenSparrow in open-source and closed-source commercial projects. Modifications to core OpenSparrow files must remain under the same license. 
The LGPL v3 is a set of additional permissions on top of the GPL v3: see [COPYING.LESSER](COPYING.LESSER) for the LGPL terms and [COPYING](COPYING) for the base GPL v3.
See also: [LICENSE-EXCEPTION](LICENSE-EXCEPTION)

### Third-party assets

The icons in `public/assets/icons/` are Google **Material Symbols / Material Icons** ([fonts.google.com/icons](https://fonts.google.com/icons)), © Google LLC, licensed under the **Apache License 2.0** — see [NOTICE](NOTICE) for the attribution and [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt) for the full license text.

No third-party source code is bundled: the frontend is vanilla JS/CSS with no libraries or CDN dependencies, and the only Composer package is the dev-time test tooling (PHPUnit), which is not distributed.
