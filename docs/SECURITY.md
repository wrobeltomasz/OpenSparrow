# Security Decisions & Audit Log

Architectural security decisions and audit results. Coding rules themselves live in
`CLAUDE.md` → "Security code practices" — that section is the authoritative checklist
for every PHP/JS change; this file records **why** those rules exist and what has
already been verified, so audits are not repeated from scratch.

## Standing decisions

- **SQL** — two independent injection vectors, both must be covered: values via
  `pg_query_params()`, identifiers via `pg_ident()`. Identifiers are a first-class
  vector here because table/column names come from editable `config/schema.json`.
- **Output encoding** — `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')`, not
  `htmlentities()` (over-encodes, double-encoding risk). PHP→JS values go through
  `json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- **CSRF** — single central check in `includes/bootstrap.php`; JS sends the token as
  `X-CSRF-Token` from the meta tag. Timestamps are **not** a CSRF defense; only the
  unpredictable session token is. GET never mutates state. Contract guarded by
  `cypress/e2e/api_contracts.cy.js`.
- **Auth material stays server-side** — PHP session cookie (HttpOnly, Secure,
  SameSite=Lax, `session_regenerate_id(true)` on login — `public/login.php`).
  `localStorage` is UI-preferences only, never tokens.
- **DOM building** — pattern is "clear with `innerHTML = ''`, build with
  `createElement`/`textContent`". `innerHTML` with data is allowed only through the
  vetted escaping helpers listed in the audit below.
- **Serialization** — external data is JSON only; `unserialize()` and `eval()` are
  banned and currently absent from the codebase (verified 2026-07-09).
- **`exec()` is deliberately kept** — `public/admin/api.php` uses
  `exec(PHP_BINARY . ' ' . escapeshellarg(...))` to trigger cron workers from the
  admin panel, with a graceful fallback when `disable_functions` blocks it. If
  hardening `php.ini`, disable `system`/`passthru`/`shell_exec`/`proc_open` but keep
  `exec`, or accept losing the manual-run buttons.
- **SSRF surface is admin-gated** — all outbound `curl_exec` targets (Ollama in
  `includes/rag_helpers.php`, workflow webhooks in `includes/automations.php`,
  connection tests in `public/admin/api.php`) come from admin-controlled config.
  **Condition:** if webhook/URL fields ever become editor-editable, add URL
  validation (block loopback/private ranges) in the same PR.
- **Log monitoring is an infrastructure task** — the app writes everything needed
  (`spw_login_attempts` brute-force throttling, `*_log` audit tables, RAG rate
  limits); alerting/pattern analysis (fail2ban, log aggregation) belongs to the
  deployment, not application code.

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
