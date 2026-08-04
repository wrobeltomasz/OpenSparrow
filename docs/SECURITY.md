# Security Decisions & Audit Log

Architectural security decisions and audit results. Coding rules themselves live in
`CLAUDE.md` → "Security code practices" — that section is the authoritative checklist
for every PHP/JS change; this file records **why** those rules exist and what has
already been verified, so audits are not repeated from scratch.

## Standing decisions

- **SQL** — two independent injection vectors, both must be covered: values via
  `pg_query_params()`, identifiers via `pg_ident()`. Identifiers are a first-class
  vector here because table/column names come from the editable `schema` configuration.
- **Output encoding** — `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')`, not
  `htmlentities()` (over-encodes, double-encoding risk). PHP→JS values go through
  `json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- **CSRF** — single central check in `includes/bootstrap.php`; JS sends the token as
  `X-CSRF-Token` from the meta tag. Timestamps are **not** a CSRF defense; only the
  unpredictable session token is. GET never mutates state. Contract guarded by
  `cypress/e2e/api/api_contracts.cy.js`.
- **Auth material stays server-side** — PHP session cookie (HttpOnly, Secure,
  SameSite=Lax, `session_regenerate_id(true)` on login — `public/login.php`).
  `localStorage` is UI-preferences only, never tokens.
- **DOM building** — pattern is "clear with `innerHTML = ''`, build with
  `createElement`/`textContent`". `innerHTML` with data is allowed only through the
  vetted escaping helpers listed in the audit below.
- **Shared JS helpers (2026-07-09 consolidation)** — `assets/js/util/csrf.js`
  (`getCsrfToken()`: `window.CSRF_TOKEN` first — edit.php/files.php have no meta tag —
  then the `<meta name="csrf-token">`) and `assets/js/util/esc.js` (`escHtml()`:
  escapes `& < > " '`, null-safe). They replaced 17 per-file CSRF wrappers and 9
  per-file escape helpers; files keep their historical local names via import aliases.
  New code must import these, never redefine them. Likewise PHP endpoints use the
  shared `requireWrite()` in `includes/api_helpers.php` (editor+admin); the previous
  per-endpoint copies had divergent semantics (comments/files blocked admin — fixed).
- **Serialization** — external data is JSON only; `unserialize()` and `eval()` are
  banned and currently absent from the codebase (verified 2026-07-09).
- **`exec()` is deliberately kept** — the admin cron module (`includes/admin/cron.php`,
  dispatched by `public/admin/api.php`) uses `exec(PHP_BINARY . ' ' . escapeshellarg(...))`
  to trigger cron workers from the admin panel, with a graceful fallback when
  `disable_functions` blocks it. If
  hardening `php.ini`, disable `system`/`passthru`/`shell_exec`/`proc_open` but keep
  `exec`, or accept losing the manual-run buttons.
- **SSRF surface is admin-gated** — all outbound `curl_exec` targets (Ollama in
  `includes/rag_helpers.php`, workflow webhooks in `includes/automations.php`,
  connection tests in `includes/admin/rag.php`) come from admin-controlled config.
  **Condition:** if webhook/URL fields ever become editor-editable, add URL
  validation (block loopback/private ranges) in the same PR.
- **Log monitoring is an infrastructure task** — the app writes everything needed
  (`spw_login_attempts` brute-force throttling, `*_log` audit tables, RAG rate
  limits); alerting/pattern analysis (fail2ban, log aggregation) belongs to the
  deployment, not application code.
- **Menu icons: local `assets/` whitelist (2026-07-10)** — icon paths from
  admin-editable config JSON must match
  `#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i` and contain no `..`;
  the former `https://` branch was removed (offline/no-CDN policy). Two
  synchronized copies exist and **must stay identical**: `renderMenuIcon()` in
  `templates/menu.php` (render) and `$menuSanitizeIcon` in
  `includes/admin/config_files.php` (admin save/preview) — tightening only one
  makes admins save icons that silently render empty. Context for future
  audits: the value only ever lands in a browser-resolved `<img src>` (no
  server-side file read → no LFI/path traversal), and `config/` sits outside
  the `public/` docroot, so the old loose pattern was not exploitable — the
  tightening is defense-in-depth plus offline-policy enforcement, not a
  vulnerability fix.

## Audit: 3.1 hardening pass (2026-07/08)

Four gaps closed while building 3.1. All four share one root cause worth remembering:
a guard that lives in the *main* code path is not a guard — side-channel endpoints,
bulk operations and newly added actions each need it applied explicitly.

- **Record ownership was enforced on writes but not on reads** (d369d48). The grid's
  side-channel endpoints (image thumbnails, subtable counts) take record ids as
  *input* rather than selecting rows themselves, so `owner_restriction_sql()` had
  nothing to hang its `NOT EXISTS` off — an id the grid never returned could still be
  probed directly. New `filter_visible_ids()` in `includes/api_helpers.php` narrows a
  client-supplied id list to the visible ones; it **fails closed** (a failed ownership
  lookup returns an empty list, never the input). It mirrors `can_access_record()`:
  unowned rows pass, rows owned by someone else are dropped — keep the three in sync.
- **`owner_restriction_sql()` degraded to a no-op on unqualified ids** (d369d48).
  The predicate is a correlated subquery over `spw_record_owners`, which has its own
  `id` column, so a bare `'id'` resolved to the *inner* `ro.id`, making the condition
  `ro.record_id = ro.id` — essentially never true, filter silently gone. The function
  now **throws** on an `$idExpr` without a dot: callers must alias the outer table
  (`_t.id`). A silent-degradation bug like this cannot be left to code review.
- **Admin CSRF / DEMO_MODE coverage was per-action and hand-maintained**
  (bc81a17, 4e7e4bd). Five admin write actions never called `require_not_demo()`, so a
  public demo instance accepted them. The admin modules now go through the shared
  wrappers in `includes/admin/helpers.php` instead of each re-implementing the checks.
  The structural risk documented in the DEMO_MODE design stands: there is **no central
  gate**, every write action must still guard itself — which is exactly what
  `tests/Admin/AdminApiGuardsTest.php` now asserts by scanning the sources. Treat that
  test the way `cypress/e2e/api/api_contracts.cy.js` is treated for CSRF: it is the
  regression net, so a new admin action that skips the guard fails CI rather than ship.
  Related gaps closed the same way: record duplication, mass file operations and the
  ETL runner all reused the main-path guard but not the bulk one.
- **Webhook credentials were stored and echoed in plaintext** (df3a61a). Automation
  webhook signing secrets and custom header values now live encrypted in
  `secret_enc` / `headers_enc` via `includes/crypto.php` and are **never returned to
  the browser** — the admin UI receives only a `*_configured` boolean, the same
  convention as `ollama_api_key_configured` in `includes/admin/rag.php`. Rules saved
  before 3.1 keep working from their plaintext fields and are re-encrypted on the next
  save. New secret-bearing config fields must follow this pattern: encrypt at rest,
  expose a boolean, never round-trip the value through the client.

The SSRF condition recorded above is unchanged and still holds: webhook URLs remain
admin-controlled. 3.1 widened the webhook feature (PATCH/DELETE methods, custom headers,
retries) but not who can edit the target URL — if that ever becomes editor-editable,
the URL validation called for above is due in the same PR.

## Audit: `innerHTML` usage in `public/assets/js/` (2026-07-09)

All 56 occurrences (16 files) were reviewed, including every helper the data flows
through. Verdict: **no XSS from API/user data**.

- ~38 are `innerHTML = ''` container clears followed by `createElement`/`textContent`.
- Data-carrying uses rely on verified escaping helpers:
  - `rag-render.js` (`renderAnswer`) — escapes `& < > " '` on every text fragment
    before inline formatting; record links only from `[View: table:id]` markers with
    a table whitelist, `\d+` ids, and `encodeURIComponent`; used by `agent-panel.js`
    and `rag.js` for LLM answers.
  - `comments.js formatBody` — escapes all five chars (quotes matter: autolink output
    lands in `href=""`), links only `https?://` (no `javascript:`). Covers the stored
    XSS vector of user comment bodies.
  - `data_cleanup.js highlightBefore/After` — output assembled solely from
    `esc()`-passed fragments plus static `<del>`/`<ins>` tags.
- `${I18n.t(...)}` template interpolations (user-menu, data_cleanup panel) are trusted:
  values come from repo-tracked `languages/*.json`.
- Fixes applied the same day: `avatar.js` fallback SVG rebuilt with `createElementNS`
  + `textContent` (was the only unescaped interpolation — a single username char, not
  exploitable but inconsistent); dead `esc()` removed from `views.js`.

New `innerHTML` uses with dynamic data must go through one of the helpers above or be
rebuilt with DOM APIs.

## Known cosmetic inconsistency

PHP sends `X-Frame-Options: DENY` (`includes/session.php`) while `nginx.conf` and
`public/.htaccess` set `SAMEORIGIN`. Dynamic responses get the PHP header; static
files get the server variant. Unify when next touching server configs.
