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

- **Admin API split (implemented 2026-07-09)** — `public/admin/api.php` went from
  ~3.6k lines to a ~215-line front controller (auth gate, CSRF, POST-method list,
  shared `auto_cfg_*` helpers, `$adminModules` dispatch map). The 66 action blocks
  moved verbatim into 14 per-domain modules under `includes/admin/` (outside the
  docroot): cron, migrations, users, schema, health, backup, settings,
  config_files, performance, m2m, anonymization, rag, automations, overview.
  Modules run in the front controller's scope (`$action`/`$file`/`$isDemoMode` +
  helper functions); every block still sets its own Content-Type and exits, and
  unmatched actions fall through to `demo/seed.php` — URLs and JSON contracts
  unchanged. The `$migrations`/`$known` registries stay together in
  `includes/admin/migrations.php` (release process depends on them — see
  CLAUDE.md "Version bumps" steps 4–5). Only real code edit during the move:
  `__DIR__ . '/../assets/...'` paths in the settings module became
  `__DIR__ . '/../../public/assets/...'`. New admin actions: add the block in the
  right module **and** register the action in the `$adminModules` map.

- **`src/` (App\) layer: FROZEN (decided 2026-07-09)** — the OOP layer stays
  scoped to what it serves today: the form pages' object graph (`os_boot_app()`
  in `includes/bootstrap.php` — create.php/edit.php) and record routing
  (Pg/Mysql/RoutingRecordRepository used by the MySQL gateway). It will not be
  extended to the rest of the codebase; **new backend code goes into the
  procedural `includes/` helper layer**. Six single-implementation interfaces
  with no consumer were deleted, and their type hints inlined to the concrete
  classes (`AuditLoggerInterface`→`DbAuditLogger` usage, `ConnectionInterface`→
  `PgConnection`, `SchemaRepositoryInterface`→`JsonSchemaRepository`,
  `CsrfTokenManagerInterface`, `RequestInterface`, `FileRepositoryInterface`;
  matching `#[\Override]` attributes removed — PHP 8.3+ fatals otherwise).
  Recorded in `config/migrations.json` → `2.9.removed_files`. Deliberately
  KEPT: `SessionInterface` (the CSRF unit test implements it as an in-memory
  fake), `RecordRepositoryInterface` (three implementations — real
  polymorphism), `FieldTypeInterface` (registry polymorphism), and
  `Identifier`/`MysqlIdentifier` (concrete utility classes, misclassified in
  the original review). Do not add new interfaces to `src/` unless at least two
  real (non-test) implementations exist.

### Open items, in recommended order

1. **`admin/js/docs-strings.js` (~2.7k lines)** — six languages of admin docs
   hardcoded in one JS file, parallel to the `languages/*.json` system. Options:
   per-language files loaded on demand, or reduce built-in docs to en+pl.
2. **20-language hard key parity** (`tests/I18n/LanguageFilesTest.php`) — every
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

## Repository & Docker Hub rename (2026-07-09)

The GitHub repository moved to `wrobeltomasz/OpenSparrow` (previously
`wrobeltomasz/open-sparrow`); GitHub keeps the old slug redirecting, but all
hardcoded references were updated anyway rather than relying on the redirect:
`README.md` (badges, clone/cd instructions, Releases links, Render deploy
link), `CONTRIBUTING.md` (clone/cd, local URL), `SECURITY.md` (vulnerability
report link), `public/login.php` (footer GitHub link).

The Docker Hub image was renamed at the same time to `wrobeltom/opensparrow`
(dropped the hyphen; **Docker Hub account stays `wrobeltom`, not
`wrobeltomasz`** — the GitHub and Docker Hub accounts are deliberately
different namespaces, don't conflate them). Updated in `docker-compose.yml`,
`docker-compose-production.yml`, `.github/workflows/docker-hub-build.yml`,
`.github/workflows/deploy-smoke.yml`, `docs/PRODUCTION_SETUP.md`,
`.env.example`. Because the image name changed, the first CI push creates a
*new* Docker Hub repository under `wrobeltom` — it does not inherit tags,
pulls, or stars from the old `wrobeltom/open-sparrow` repo. This was judged
acceptable only because no production deployment existed yet at rename time;
had one existed, the old tag would need to keep working (alias or dual-push)
until deployments migrated.

**Still open:** the local `git remote origin` URL was not changed by the
assistant (a `git remote set-url` was denied in-session) — update it manually:
`git remote set-url origin https://github.com/wrobeltomasz/OpenSparrow.git`.
