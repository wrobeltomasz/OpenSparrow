# Environment Variables

All variables are optional and are read by `includes/config.php` on every request — the single source of configuration. If a variable is absent the documented default applies. There is no `.env` loader in the application: export variables in your shell, container, or web-server virtual-host config. Docker deployment scripts may load `.env` themselves.

## Database

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | PostgreSQL host. Falls back to `PGHOST`. |
| `DB_PORT` | `5432` | PostgreSQL port. Falls back to `PGPORT`. |
| `DB_CONNECT_TIMEOUT` | `5` | Seconds before connection attempt times out. |
| `DB_STATEMENT_TIMEOUT` | `30000` | Server-side cap on a single query, in milliseconds; `0` disables it. Applied per connection right after `SET TIME ZONE`, so a runaway JOIN cannot hold a connection open indefinitely — `MAX_LIST_ROWS` bounds the rows returned, not the time spent. **Web requests only:** CLI runs (cron, ETL, migrations) are left unlimited, because long batch jobs legitimately exceed any web-sized limit. |
| `APP_TIMEZONE` | `Europe/Warsaw` | IANA timezone applied per PostgreSQL session. |
| `DB_NAME` | — | PostgreSQL database name. Falls back to `PGDATABASE`. |
| `DB_USER` | — | PostgreSQL user. Falls back to `PGUSER`. |
| `DB_PASSWORD` | — | PostgreSQL password. Falls back to `PGPASSWORD`. |
| `DB_SCHEMA` | `app` | Schema for `spw_*` tables. Falls back to `PGSCHEMA`. Overridden by the `schema` key in `database.json`. |
| `DB_SSLMODE` | `prefer` | libpq TLS mode for the database connection: `disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`. `prefer` is what libpq negotiated on its own before this variable existed, so the default changes nothing; raise it to `require` or stronger whenever PostgreSQL is not on localhost. Falls back to `PGSSLMODE`. A value outside the list is refused and logged, and the connection falls back to `prefer`. Overridden by the `sslmode` key in `database.json`. |
| `DB_SSLROOTCERT` | *(empty)* | Path to the CA bundle used to verify the server certificate — required by `verify-ca` and `verify-full`, ignored by the weaker modes. Falls back to `PGSSLROOTCERT`. Overridden by the `sslrootcert` key in `database.json`. |

## Session and Cookies

| Variable | Default | Description |
|---|---|---|
| `SECURE_COOKIES` | `true` | Set `false` on plain HTTP (local dev). |
| `SESSION_SAMESITE` | `Lax` | Cookie SameSite policy. Do not change to `Strict` — it causes `ERR_TOO_MANY_REDIRECTS` on the login→admin redirect. |
| `SESSION_COOKIE_NAME` | `OSSESSID` | Name of the session cookie. The default used to be PHP's own `PHPSESSID`, which two applications sharing a hostname silently overwrite for each other — give every installation its own name when that applies. Accepts letters, digits and underscores (max 64 characters, not all digits); anything else is refused and logged, and `OSSESSID` is used. Changing the name logs every active user out once. |
| `SESSION_COOKIE_PATH` | `/` | Path attribute of the session cookie. Narrow it to the subdirectory of this installation (e.g. `/crm`) when unrelated applications share the hostname — with the default the cookie is sent to all of them. Must start with `/` and contain no whitespace, semicolon or comma; anything else is refused and logged, and `/` is used. Changing it logs every active user out once. |
| `SESSION_MAX_LIFETIME` | `28800` | Hard session expiry in seconds (8 h), counted from login. |
| `SESSION_IDLE_TIMEOUT` | `0` | Inactivity expiry in seconds — the session ends after this long without a request. `0` disables it; the hard expiry above always applies. |
| `SESSION_SAVE_PATH` | *(auto)* | Absolute path for PHP session storage. When unset, defaults to `storage/sessions/` inside the project root — overriding any server-level `session.save_path`, which on some shared hosts (e.g. home.pl) differs per subdirectory and may point to a system `/tmp` blocked by `open_basedir`. Set explicitly when your host requires a specific path or for shared storage across nodes. |
| `SYS_TEMP_DIR` | *(auto)* | Directory for application-created temporary files. When unset, `storage/tmp/` is used; PHP's upload temporary directory remains a server-level setting. |

## Authentication and Rate Limiting

| Variable | Default | Description |
|---|---|---|
| `IP_HASH_SALT` | *(auto)* | HMAC secret for IP pseudonymisation in login rate-limiting. If unset, a 64-char random salt is generated on first request and persisted to `includes/.secret_salt` (chmod 0600, gitignored, web-denied). Set explicitly via env var for multi-server deployments where all nodes must share the same salt. |
| `APP_ENCRYPTION_KEY` | *(auto)* | Secret used to encrypt stored integration credentials. If unset, a random key is generated and persisted to `includes/.secret_key`; set it explicitly when several nodes must share encrypted data, and keep it stable to preserve access to existing secrets. |
| `LOGIN_MAX_ATTEMPTS_PER_IP` | `20` | Failed login threshold per IP before lockout. |
| `LOGIN_MAX_ATTEMPTS_PER_USERNAME` | `5` | Failed login threshold per username before lockout. |
| `LOGIN_LOCKOUT_MINUTES` | `15` | How long a lockout lasts once it triggers, in minutes. |
| `LOGIN_RATE_LIMIT_WINDOW_MINUTES` | `15` | How far back failed attempts are counted towards the two thresholds above. A lockout fires when that many failures land inside this window, and then lasts `LOGIN_LOCKOUT_MINUTES` from the last of them. |
| `PASSWORD_MIN_LENGTH` | `12` | Shortest password accepted anywhere in the system — admin user creation, admin password resets and the self-service change in the profile page. The **Users → Global User Settings** policy may raise it, never lower it. Values below `8` are ignored. |
| `TRUST_PROXY_HEADERS` | `false` | Resolve the client IP from forwarding headers instead of `REMOTE_ADDR`. Off by default so a spoofed header cannot dodge the rate limiter; turn it on only behind a proxy you control. HTTPS auto-detection through `X-Forwarded-Proto` is independent of this switch. |
| `TRUSTED_PROXY_IPS` | *(empty)* | Comma-separated IPs or CIDR ranges (IPv4 and IPv6) allowed to set those headers. Empty means every client is trusted, so set it together with `TRUST_PROXY_HEADERS=true` — the resolved IP drives `LOGIN_MAX_ATTEMPTS_PER_IP`. |
| `FORWARDED_HEADER_PRIORITY` | `cf,x-real-ip` | Order in which forwarding headers are consulted once they are trusted. Supported values: `cf` (`CF-Connecting-IP`, only alongside `CF-Ray`), `x-real-ip`, `x-forwarded-for`. Unknown names are dropped, and every candidate must parse as an IP address. |
| `ARGON2_MEMORY_COST` | `131072` | Password hashing memory in KiB (128 MB). Lower it on constrained hosts; the floor is `8192`. |
| `ARGON2_TIME_COST` | `4` | Password hashing iterations. |
| `ARGON2_THREADS` | `1` | Password hashing parallelism. |
| `API_RATE_LIMIT_PER_MIN` | `120` | Requests per minute allowed per logged-in user, counted separately for each API endpoint so one busy endpoint cannot starve the others. Over the budget the endpoint answers `429` with `Retry-After: 60`. The click-statistics collector gets its own `600`/min budget, because `navigator.sendBeacon` fires on every click. `0` disables the limit everywhere, including that collector. Applies to the endpoints booted through `os_api_bootstrap()`; the admin API boots its own way and is not covered. When the throttle state under `STORAGE_PATH/ratelimit` cannot be written or locked the request is **allowed** and the failure logged — an unwritable directory must not take the whole API down. RAG stays fail-closed there, because a single unmetered answer costs far more than a refused one. |

## Application Behaviour

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | Runtime environment. |
| `APP_URL` | *(empty)* | Absolute base URL of this installation, e.g. `https://crm.example.com` (a trailing slash is trimmed). Exposed to automations as the `{{ app_url }}` placeholder, so e-mail bodies and links queued from cron — where there is no request to derive a host from — can carry working absolute URLs. A value that is not an absolute `http`/`https` URL is ignored and logged. |
| `STORAGE_PATH` | *(project root)*`/storage` | Absolute path of the writable data directory holding `sessions/`, `tmp/`, `files/`, `ratelimit/` and migration backups. Point it at a mounted volume to keep the code tree read-only, or at shared storage across nodes. Paths already recorded in `spw_files.storage_path` keep resolving, so no migration is needed. `SESSION_SAVE_PATH` still wins for sessions alone. |
| `ERROR_LOG_PATH` | *(auto)* | Destination of every `error_log()` message — the app's only diagnostic channel, since `display_errors` is forced off outside development. When unset it resolves to `storage/logs/app.log`, created on first run alongside a `.htaccess` denying HTTP access. Set it explicitly to log outside `STORAGE_PATH`; if the target directory is missing or not writable the value is refused and logged, and the `php.ini` destination is kept. |
| `DEMO_MODE` | `false` | Set `true` to block all write operations in the admin panel API (safe for public demos). Frontend record edits — grid, comments, files, chat — stay enabled so visitors can try the app. |
| `SETUP_ENABLED` | `true` | Second, independent lock on the installation wizard. Set `false` once the installation is finished: `setup.php` then redirects to the login page and `setup_api.php` answers 403, regardless of whether `config/database.json` exists. That file is otherwise the *only* thing standing between the public internet and a wizard that creates an admin account. Fail-closed — any value other than `true` disables the wizard. |
| `CRON_SECRET` | *(empty)* | Secret token that unlocks the notification cron over HTTP at `cron.php`. Empty (the default) keeps the endpoint disabled — every request answers 403. Set it to a long random value (e.g. `openssl rand -hex 32`) and schedule `https://crm.example.com/cron.php?token=<secret>` from any URL-based cron service (cPanel, cron-job.org, EasyCron, UptimeRobot) or a host-specific launcher (see [shared-hosting cron setup](../README.md#scheduling-the-notification-cron-shared-hosting)). The token is compared in constant time. |
| `RECORD_SNAPSHOTS_ENABLED` | `false` | Enable record snapshot capture after every INSERT/UPDATE. Overrides the admin panel toggle stored in the `settings` configuration. |
| `FILES_MAX_SIZE_MB` | `20` | Default upload size limit when not set in the `files` configuration. |
| `FILES_PAGE_LIMIT` | `25` | Default number of files per page. |
| `FILES_PAGE_LIMIT_MAX` | `100` | Maximum allowed files-per-page value; never lower than `FILES_PAGE_LIMIT`. |
| `THUMBNAIL_MAX_WIDTH` | `300` | Max thumbnail width in pixels. |
| `FILE_CACHE_MAX_AGE` | `3600` | Browser cache lifetime for downloaded files, in seconds; `0` disables caching. |
| `THUMBNAIL_CACHE_MAX_AGE` | `86400` | Browser cache lifetime for thumbnails, in seconds; `0` disables caching. |
| `MAX_LIST_ROWS` | `1000` | Default server-side row cap for grid list requests; per-table settings can override it. |
| `COMMENTS_PAGE_LIMIT_MAX` | `50` | Maximum comments page size. |
| `COMMENTS_MINE_LIMIT` | `50` | Maximum rows returned by the personal comments view. |
| `MENTION_USER_LIMIT` | `8` | Maximum user suggestions returned for comment mentions. |
| `NOTIFICATIONS_DROPDOWN_LIMIT` | `10` | Max items in the bell notification dropdown. |
| `CONFIG_FILE_MAX_BYTES` | `524288` | Largest configuration payload accepted by admin import, in bytes; minimum `1024`. |
| `HSTS_MAX_AGE` | `31536000` | HSTS `max-age` in seconds (1 year). Set `0` to disable on plain HTTP. |
| `CSP_REPORT_URI` | *(empty)* | Same-origin path collecting Content-Security-Policy violation reports, appended to every policy the app sends. Empty disables reporting. Must start with a single `/` — an absolute or protocol-relative URL would hand violation reports, including the URLs they name, to a third party, so it is refused and logged. Values containing whitespace, `;` or `,` are rejected the same way. |
| `CSP_REPORT_ONLY` | `false` | Send the policy as `Content-Security-Policy-Report-Only`, so violations are logged instead of blocked. Requires `CSP_REPORT_URI`, and never applies to file downloads — those keep their enforced `default-src 'none'`. |
| `ADMIN_PURGE_MAX_DAYS` | `3650` | Longest retention window an admin log purge will accept, in days. |
| `HTTP_CLIENT_TIMEOUT` | `30` | Timeout in seconds for outbound requests — automation webhooks and admin Ollama calls. RAG answer generation keeps its own longer limit. |
| `HTTP_CLIENT_CONNECT_TIMEOUT` | `10` | Connect timeout in seconds for the same outbound requests. |
| `SMTP_TIMEOUT` | `10` | Connect and read timeout in seconds for SMTP delivery — used by the notification cron when sending queued e-mails and by the **Test connection** button in admin Settings. A relay that stops responding otherwise stalls the whole queue run. |
| `AUTOMATION_EMAIL_FROM` | *(settings)* | Sender address for queued automation and notification emails; when unset, the admin Settings value is used. |
| `AUTOMATION_EMAIL_BATCH_LIMIT` | `50` | Maximum queued emails processed in one notification-cron run. |
| `AUTOMATION_EMAIL_MAX_ATTEMPTS` | `3` | Maximum delivery attempts per queued email. |

## AI / RAG (Knowledge Base)

| Variable | Default | Description |
|---|---|---|
| `OLLAMA_URL` | `http://localhost:11434` | Base URL of the Ollama endpoint — either a local instance (the default) or **Ollama Cloud** (`https://api.ollama.com`) for the hosted tier. Used by `api/rag.php` and admin RAG actions. The Ollama Cloud API key is not an env var: set it in **AI → Centrum AI → Global Settings**, where it is stored encrypted (`ollama_api_key_enc`) and never returned to the browser. |
| `OLLAMA_MODEL` | `llama3` | Default Ollama model for RAG queries. Overridden by the `rag` configuration if present. |
| `CHAT_BUBBLE_ENABLED` | *(settings)* | Set `true` or `false` to override the admin setting for the floating Ask AI button. |
| `RAG_RATE_LIMIT_PER_MIN` | `10` | RAG queries allowed per user per minute; `0` disables this limit. |
| `RAG_MAX_CONCURRENT` | `2` | Maximum simultaneously running RAG answer generations. |
| `RAG_PAGE_CONTEXT_MAX_CHARS` | `12000` | Maximum characters of current-page context sent with a RAG query; `0` disables page context. |
