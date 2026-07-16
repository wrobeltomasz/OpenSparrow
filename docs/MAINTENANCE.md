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
  CLAUDE.md "Version bumps" steps 4–5). A third copy of the migration-key
  list lives in `includes/admin/overview.php` (`$knownMig`, drives the
  dashboard pending count) and must be kept in sync with those two. As of 3.0
  the pre-3.0 incremental migration history was collapsed into a single
  append-only `3.0_baseline` entry (3.0 is the first shipped version), and
  `config/migrations.json` was trimmed to only the `3.0` release entry. The
  baseline's DDL body itself lives in `includes/system_tables.php`
  (`system_tables_ddl()`), shared with the setup wizard (`public/setup_api.php`)
  so the two entry points that create `spw_*` tables cannot drift apart — they
  did, and fresh installs shipped without `spw_config` as a result. Only
  real code edit during the move:
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
  No `removed_files` entry is needed: this happened before 3.0, the first
  shipped version, so no installation ever had those files. Deliberately
  KEPT: `SessionInterface` (the CSRF unit test implements it as an in-memory
  fake), `RecordRepositoryInterface` (three implementations — real
  polymorphism), `FieldTypeInterface` (registry polymorphism), and
  `Identifier`/`MysqlIdentifier` (concrete utility classes, misclassified in
  the original review). Do not add new interfaces to `src/` unless at least two
  real (non-test) implementations exist.

- **Configuration store: `spw_config` is the single source of truth (implemented
  2026-07-16)** — all 14 application config keys (schema, menu, settings,
  dashboard, calendar, board, workflows, automations, views, files, print,
  anonymization, user_records, rag) live in the `spw_config` table, one JSONB row
  per key, with optimistic locking (`version`) and an audit trail in
  `spw_config_log`. Everything goes through `includes/config_store.php`
  (`config_get`/`config_get_row`/`config_save`/`config_delete`, per-request static
  + APCu cache); ~15 scattered `file_get_contents`/`file_put_contents` patterns
  are gone, and with them the last-write-wins race that affected every config
  except `menu.json`. There is **no file fallback**: 3.0 is the first shipped
  version, so no instance ever had file-based config to migrate. `database.json`
  (and `security.json`) stay files permanently — they are read before a database
  connection exists. Only `print` and `anonymization` plumb the version through
  their JS; the generic editor is still last-write-wins (open item).

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

## Search & filter UI standardization (2026-07-10)

All page-level search and filter controls were unified into a single
header-based standard and rolled out to every page: grid, views, board,
calendar, files, dashboard. The **binding rules** live in CLAUDE.md →
"Search & filter UI standard" (placement, shared classes, overflow, clear
button, stable IDs); this section records the reasoning and gotchas so they
are not re-derived or re-litigated.

### Decisions

- **Header placement ("variant A")** — controls render in the blue app header
  via `$headerControls` (pattern: `templates/template.php`, `board.php`,
  `calendar.php`, `dashboard.php`, `files.php`, `views.php`). Chosen over
  moving the grid's controls into the page body because the header placement
  carries the mobile search drawer (`sidebar.js` + `mobile.css`
  `.header-controls`), the Cypress selectors, and the `grid/keyboard.js`
  focus hook for free. Body toolbars for page-level filters were removed
  (`.board-toolbar`, calendar's in-body bar, files' search/type row).
- **One shared class family** — `filter-chip`/`off`/`filter-dot`,
  `filter-pill`/`filter-pill-remove`, `filter-range`/`num-filter` replaced the
  duplicated `board-filter-*`/`calendar-filter-*` CSS and all inline
  `style.cssText` filter styling in `app.js`. Removing inline styles is also a
  prerequisite for eventually dropping the grid page's `unsafe-style` CSP.
  Chips inside the header auto-adapt via `header .filter-chip` (translucent
  white on dark).
- **Calendar per-enum dropdown filters were deleted** (UX decision: too
  cluttered — one dropdown per enum column per source) in favor of a phrase
  search box + source visibility chips. Old localStorage `enumFilters` state
  is silently ignored. The now-unused i18n key `calendar.filter_all` was
  deliberately **left** in all 20 language files (removal is a 21-file churn
  gated by the parity test, zero user benefit).
- **Dashboard** — the period select is rendered server-side in the header
  (its `dashboard.filter_*` keys already existed in all 20 languages;
  `buildFilterBar()` was deleted from `dashboard/index.js`, which also removes
  the empty-bar flash before JS loads). New per-widget visibility chips are
  keyed by `widget.id` (stable across reorder/rename; the API passes full
  widget objects through), dot color = `widget.color`, state in localStorage
  `sparrow_dashboard_filters`, filtering applied centrally in
  `renderWidgets()`.
- **Files** — search (`type="search"`) + type select moved to the header;
  **Refresh List stays in the body** — it is an action, not a filter,
  mirroring the grid's body action buttons. The page remains hardcoded
  English (it has no i18n at all); one stray `t()` call was not introduced
  for the placeholder.
- **Header overflow** — chip containers are `flex-wrap: nowrap; min-width: 0;
  overflow-x: auto` with a thin translucent scrollbar, so the fixed-height
  (`--header-h`) bar never grows with many chips; the mobile drawer rules
  (higher specificity, `.header-controls .x`) restore wrapping where the
  layout is vertical.
- **Clear-filters button on every page** — `#clearFilters`, label/title from
  the pre-existing `grid.clear_filters` key, last header control, hidden
  unless a filter/search is active; one click resets the page to defaults
  (dashboard also resets the period to `all` and reloads only if it changed).
- **Out of scope** — the "Ask AI" panel (`agent-panel.js`) tag checkboxes are
  module-internal filtering, not page-level; they intentionally do not follow
  this standard.

### Gotchas verified during rollout

- **CSP blocks inline `style=` attributes** on `no-connect`/default-CSP pages
  (`style-src 'self' 'nonce-…'`, no `unsafe-inline`) — the clear button there
  toggles the HTML `hidden` attribute instead of `style.display`. That needs
  the author rule `header #clearFilters[hidden] { display: none }`, because
  the global `button { display: inline-flex }` (author origin) beats the UA
  `[hidden]` style regardless of specificity. The grid page alone keeps the
  historical inline-style approach (its CSP is `unsafe-style`).
- **`board.cy.js` asserts `#boardSearch` has `type="search"`** — search inputs
  across pages are `type="search"` (native clear ×, shared
  `header input[type="search"]` styling); keep ids page-specific but stable
  (full contract list in CLAUDE.md).
- `templates/layout.php` includes `header.php` in the same scope, so any page
  can define `$headerControls` before the include — no template changes
  needed per page.

## Menu / stylesheet review verification (2026-07-10)

A 7-point external review of `templates/menu.php` + `public/assets/css/styles.css`
was verified point-by-point. Most claims were unfounded (the reviewer did not have
the JS files); the real findings were adjacent to the reported ones. Recorded so
the same nitpicks are not re-litigated.

### Implemented the same day

- **Submenu `<summary>` accessible name** — `aria-label` built from the new i18n
  key `header.toggle_submenu` (`"Toggle submenu: {name}"`, added to all 20
  `languages/*.json`); the `▾` glyph is `aria-hidden="true"`. The
  `<details>`/`<summary>` disclosure pattern itself **stays** — deliberate no-JS
  progressive enhancement; do not replace it with `button` + `aria-expanded`.
- **`aria-current="page"`** on the active nav link (`renderMenuLink`), alongside
  the existing `active` class.
- **Collapsed-nav tooltip keyboard support** — `sidebar.js` shows `#nav-tip` on
  `focusin` (mirroring `mouseover`) and hides on `focusout` only when focus
  leaves the sidebar, so Tab users can read collapsed menu labels.
- **Menu icon whitelist tightened** in both copies — decision and rationale in
  `SECURITY.md` → "Menu icons: local `assets/` whitelist".

### Verified fine — do NOT "fix"

- **`.th-label { pointer-events: none }` is functional**, not leftover: it
  guarantees `e.target` is the `th` for column sorting and drag-and-drop
  (`grid/header/dnd.js`). Known harmless side effect: the `cursor: help` set in
  `grid/header/render.js` on described columns never applies (the `title`
  tooltip sits on the `th` and still works).
- **Class naming is consistent**: kebab-case with short module prefixes
  (`dash-`, `kg-`, `f-`) per CLAUDE.md — these are not "camelCase" violations.
  camelCase appears only in IDs serving as JS hooks (`#sidebarToggle`,
  `#dateFiltersContainer`, ~14 selectors) — a deliberate ID-vs-class split;
  renaming would touch many JS files for zero gain.
- **No global margin reset** (`h1, p, ul { margin: 0 }`) — the ~3.3k-line
  stylesheet zeroes margins point-wise (~30 places, e.g. `.dash-title`); adding
  a global reset late risks visual regressions with no benefit.
- **The 12 `!important` uses** are mostly deliberate state overrides
  (`cell-error`/`cell-success`, `tr:hover td`); only the pair in
  `#dateFiltersContainer` (ID selector already wins) is removable cleanup when
  that block is next touched.
