# Maintainability Decisions & Code-Size Review

Findings and standing decisions from codebase reviews, so they are not re-derived
from scratch. Security-specific decisions live in `SECURITY.md`; binding coding
rules live in `CLAUDE.md`.

## Code-size review (2026-07-09)

Own code at review time: PHP ~21.4k lines (app 19.6k + `src/` 1.8k), JS ~27.7k
(admin 18.2k + user 9.6k), CSS ~6k, tests 1.5k PHPUnit + 21 Cypress specs.
Verdict: the codebase is not oversized for its feature scope; the cost is
concentrated in a few places listed below.

### Implemented the same day

- **Shared `requireWrite()`** in `includes/api_helpers.php` (editor+admin by
  default) replaced three divergent per-endpoint copies (`api/comments.php`,
  `api/owners.php`, `api/files.php` `requireEditor`). The old comments/files
  variants accidentally blocked admin writes; editor+admin matches the
  `role IN ('editor','admin')` queries in owners.php. Endpoints must use the
  shared gate — never define their own.
- **Shared JS helpers** `assets/js/util/csrf.js` (`getCsrfToken()`) and
  `assets/js/util/esc.js` (`escHtml()`) replaced 17 per-file CSRF wrappers and
  9 per-file HTML escapers. Files import with a local alias where they used a
  historical name (`esc`, `escapeHtml`, `csrfToken`, …). See `SECURITY.md` for
  the exact token-source order and escape semantics.

### Open items, in recommended order

1. **Split `public/admin/api.php` (~3.6k lines, ~60 `$action` branches)** into
   per-domain modules (users, schema, backup, rag, cron, …) behind one shared
   admin gate — mirroring the `public/api/*.php` pattern, which is the target
   shape. Constraint: the `$migrations`/`$known` registries must stay in a
   single module; the release-migration system depends on them.
2. **Decide the fate of the `src/` (App\) OOP layer** — 40 files/1.8k lines,
   consumed only by `includes/bootstrap.php` (form pages) and the FDW gateway.
   Either finish migrating toward it or freeze it; in both cases drop the eight
   single-implementation interfaces with no external consumer
   (`AuditLoggerInterface`, `CsrfTokenManagerInterface`, `RequestInterface`,
   `SessionInterface`, `ConnectionInterface`, `SchemaRepositoryInterface`,
   `FileRepositoryInterface`, `Identifier`). Until decided, do not add new code
   to *either* style without checking which side it belongs to.
3. **`admin/js/docs-strings.js` (~2.7k lines)** — six languages of admin docs
   hardcoded in one JS file, parallel to the `languages/*.json` system. Options:
   per-language files loaded on demand, or reduce built-in docs to en+pl.
4. **20-language hard key parity** (`tests/I18n/LanguageFilesTest.php`) — every
   new key costs 20 edits. Considered alternative: fallback-to-en with parity
   enforced only for en+pl. Product decision, not taken yet.

### Deliberately NOT simplified

- `public/api/*.php` specialized endpoints, the `includes/` shared-helper layer
  (June 2026 DRY refactor), and the `assets/js/grid/` + `dashboard/` module
  splits — these are the patterns to extend, not merge.
- No composer/npm at runtime — deliberate deployment/licensing decision; do not
  introduce runtime dependencies "to simplify".
- The two `apiFetch()` functions (`user-menu.js`, `views.js`) have genuinely
  different semantics and stay separate.

### Local housekeeping note

`.claude/worktrees/` may accumulate orphaned agent worktrees (git-ignored,
stale pre-restructure copies) that pollute repo-wide searches — safe to delete.
