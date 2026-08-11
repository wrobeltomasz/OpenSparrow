# Maintainability Decisions & Code-Size Review

Findings and standing decisions from codebase reviews, so they are not re-derived
from scratch. Security-specific decisions live in `SECURITY.md`; **binding coding
and UI rules live in this document** — see "Where binding rules live" at the end.

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
  `includes/admin/migrations.php` (the release process depends on both being
  updated together during a version bump). A third copy of the migration-key
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
  in `includes/bootstrap.php` — create.php/edit.php) and PostgreSQL record
  persistence (`PgRecordRepository`). It will not be
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
  fake), `RecordRepositoryInterface` (implemented by `PgRecordRepository`),
  `FieldTypeInterface` (registry polymorphism), and `Identifier` (concrete
  utility class, misclassified in the original review). Do not add new
  interfaces to `src/` unless at least two real (non-test) implementations exist.

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

1. ~~**`admin/js/docs-strings.js` (~2.7k lines)** — six languages of admin docs
   hardcoded in one JS file.~~ **Resolved 2026-07-29 (851df26)** by taking the
   second option: built-in admin docs reduced to **en + pl**, the de/fr/it/es and
   placeholder locales dropped. The app UI keeps all 20 languages — only the admin
   Docs tab is bilingual. Do not add doc locales back; the maintenance cost was the
   whole reason for the trim.
2. **20-language hard key parity** (`tests/I18n/LanguageFilesTest.php`) — every
   new key costs 20 edits. Considered alternative: fallback-to-en with parity
   enforced only for en+pl. Product decision, not taken yet. (The docs-strings trim
   above is precedent for the narrower en+pl scope, but the app UI is a different
   surface — untranslated *interface* strings are more visible than untranslated
   documentation, so the decision does not carry over automatically.)

### Deliberately NOT simplified

- `public/api/*.php` specialized endpoints, the `includes/` shared-helper layer
  (June 2026 DRY refactor), and the `assets/js/grid/` + `dashboard/` module
  splits — these are the patterns to extend, not merge.
- No composer/npm at runtime — deliberate deployment/licensing decision; do not
  introduce runtime dependencies "to simplify".
- The two `apiFetch()` functions (`user-menu.js`, `views.js`) have genuinely
  different semantics and stay separate.

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
calendar, files, dashboard.

The **binding rules** are the Decisions below — treat them as normative for any
new or touched filter UI, not merely as history: controls render in the blue app
header via `$headerControls`; they use the one shared class family
(`filter-chip`, `filter-pill`, `filter-range`/`num-filter`) with no inline
styles; chip containers never wrap (`nowrap` + `overflow-x: auto`) so the
fixed-height header cannot grow; every page carries a `#clearFilters` control;
and search input ids stay page-specific but stable. The rest of this section
records the reasoning and gotchas so they are not re-derived or re-litigated.

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
  `header input[type="search"]` styling); keep ids page-specific but stable —
  they are Cypress selectors, so renaming one breaks its spec.
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
- **Class naming is consistent**: the convention is kebab-case with short module
  prefixes (`dash-`, `kg-`, `f-`) — these are not "camelCase" violations.
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

## 3.1 refactors (2026-07/08)

Three structural changes landed during 3.1. All three exist because a pattern that
worked at 2.x scale stopped working — record them so the pattern is extended, not
re-litigated.

### Admin module helper layer (`includes/admin/helpers.php`, 4e7e4bd)

The 2026-07-09 admin API split moved 66 action blocks out of `public/admin/api.php`
into per-domain modules under `includes/admin/`, but each module then re-implemented
the same request/response boilerplate — and drifted. `helpers.php` (`admin_try`,
`admin_ok`, `admin_conn`, …) now carries it, and `includes/admin_api_errors.php`
carries the shared error vocabulary. The measurable win was not line count but
correctness: five admin actions had lost their `require_not_demo()` call in the
copy-paste, which is what prompted the extraction (see `SECURITY.md` → "3.1
hardening pass"). New admin modules use these wrappers; do not hand-roll the
try/catch/JSON envelope again.

**Standing constraint, unchanged:** the admin endpoint still has no central write
gate. `$postActions` in the front controller and the per-action `require_not_demo()`
calls are hand-maintained lists, now pinned by `tests/Admin/AdminApiGuardsTest.php`.
The helper layer makes the guards convenient — it does not make them automatic.

### Import map versions every ES module (5dfc3d8, df3a61a)

Cache busting used to stamp `?v=` on entry scripts only, so after an upgrade the
browser refetched `app.js` but kept every module it imports. Any fix in a non-entry
module stayed invisible until a manual hard refresh — a support burden with no
visible cause. Both trees (admin and frontend) now declare an import map that gives
**every** module the same version.

The trap this creates, already hit once: the `?v=` on a module's `<script src>` and
its bare specifier in the import map must agree, or the module is instantiated twice
under two URLs and one instance's state (e.g. an unsaved config edit) is silently
discarded. When bumping the version, change both or neither.

### Feature modules added under `includes/` (f633145, df3a61a, 99cdd52)

`images.php` (record image galleries), `crypto.php` (encryption of stored secrets)
and `admin/procedures.php` (workflow stored-procedure steps) follow the June 2026
DRY convention: shared backend logic goes in `includes/`, not `src/`. `src/` remains
frozen — it is the existing OOP layer, not the destination for new backend code.

## Demo install options (2026-08-08)

The CRM demo installs a lot more than sample rows — it seeds or merges fourteen
spw_config keys and writes to several system tables. Three parts of that are now
opt-out checkboxes on Admin > Demo (`public/admin/js/demo.js`), passed to
`demo_install_run()` in `public/admin/demo/seed.php` as `$withRagDocs`,
`$withUsers` and `$withAudit`. All three default to true.

### Why these three are separable and the rest are not

Everything else the demo installs lives inside the demo's own schema or under
demo-owned config keys, so it is inert to the host installation. These three
reach outside it:

- **RAG knowledge base** writes to `spw_rag_files`, shared with any knowledge base
  the user built themselves.
- **Demo users** creates real accounts in `spw_users`. All three share the fixed
  password `test`, documented in the repository. This is a genuine security
  opt-out, not a convenience one: an installation reachable from a network may
  legitimately refuse them. The comments, notes, ownership rows and notifications
  carry their `user_id` and are installed or skipped with them.
- **Audit history** writes to `spw_users_log` / `spw_record_snapshots` and flips
  the global `record_snapshots_enabled` setting.

### Binding constraints

- **A bare feature toggle is not a demo feature.** Enabling record snapshots alone
  leaves Admin > Audit and the per-record history empty until the first manual
  edit — the demo looked broken for exactly the reason that justified the RAG
  checkbox. The option therefore backfills dated `spw_users_log` rows with matching
  snapshots. Apply the same test to any future toggle the demo offers to flip.
- **Snapshots are derived, never duplicated.** `demo_audit` in `crm.php` lists only
  the columns that differed at that point in time; `seed.php` fetches the record's
  current state with `fetch_record_json()` and applies the entry as an overlay. Do
  not paste whole historical rows into the definition — they would silently drift
  from `seed_data`.
- **Anything written outside the demo schema must be recorded in
  `config/demo_meta.json` and removed by id on uninstall.** `audit_log_ids` follows
  the existing `rag_file_ids` / `demo_file_ids` pattern so a user's own audit rows
  survive; the snapshots go with them through
  `spw_record_snapshots.log_id ON DELETE CASCADE`.
- **A setting the demo flipped is reverted only if the demo flipped it.**
  `snapshots_enabled_by_demo` in the meta file guards this — an installation that
  already had record snapshots on must not have them switched off by an uninstall.
- **Option dependencies are enforced server-side, not only in the UI.** Audit
  entries are attributed to the demo accounts, so `demo_install` recomputes
  `$withAudit && $withUsers` regardless of what the request body claims.
- **`RECORD_SNAPSHOTS_ENABLED` wins.** When the env var pins the setting,
  `demo_status` reports `snapshots_locked_by_env` and the form disables the option
  with an explanation. Writing the stored value there would be a silent no-op,
  because `includes/admin/settings.php` reads the env var first.

### The setup wizard deliberately has no per-part control

`public/setup_api.php` calls `demo_install_run('crm')` with all defaults, so the
wizard picks up new parts automatically and stays a single checkbox — it is meant
to be short, and every choice is reversible from Admin > Demo a minute later. The
one thing it must not do is stay silent about the accounts: `setup.help_install_demo`
names them, states the fixed password and points at Admin > Users. That string was
updated in `en` and `pl` only, so the other 18 locales still carry the older text;
the EN fallback covers missing keys, not stale ones.

## Per-user frontend access (2026-08-11)

Admins can restrict a frontend user to a subset of the schema tables, the
configured views and the print templates, from **Users → Access**. The rules below
are binding.

### Storage and semantics

- All three scopes live in one `user_table_access` key of `spw_config`, shaped as
  `{"users": {"<id>": {"tables": [...], "views": [...], "prints": [...]}}}`.
  No new table, no migration.
- Views and printouts are granted **by name**, not derived from a table. Neither
  config carries a table binding — both are backed by PostgreSQL views — so there
  is nothing to derive from.
- **An absent or empty list means UNRESTRICTED for that scope, not "no access."**
  This is what makes the feature safe to ship: every pre-existing account keeps
  working until an admin deliberately ticks entries. Flipping this to "empty means
  denied" without first writing full lists for every existing user takes the whole
  frontend down for everyone. `tests/Security/TableAccessTest.php` pins it.
- **The scopes are independent.** Restricting tables leaves views and printouts
  untouched. A shared code path that collapses them is the first thing to break.
- A bare list (`{"users": {"7": ["orders"]}}`) is the pre-scopes shape and is read
  as tables only. Keep that branch: dropping it would silently widen access on
  upgrade, because an unreadable entry resolves to "unrestricted".
- "No access at all" is expressed by deactivating the account, not by an empty list.
- The `admin` role is never restricted and is not offered in the tab.
- The resolved lists are **never cached in `$_SESSION`** — an admin revoking access
  must take effect on the user's next request, not on their next login. The static
  cache in `user_allowed_items()` is per-request only.

### Where the decision is made

`includes/api_helpers.php` owns it, and nothing else may re-implement it:

- `user_allowed_items($scope)` — the list, or `null` for unrestricted. Unknown
  scope throws, so a typo is a hard error rather than a silent "unrestricted"
- `user_can_access($scope, $name)` — the predicate; `user_can_access_table()` /
  `_view()` / `_print()` are thin readability wrappers
- `require_access()` — API gate, **403** (not 400: "unknown" and "not yours" are
  different answers); same three wrappers
- `filter_by_user_access($scope, $items)` — narrow a name-keyed map
- `os_require_access()` in `includes/page_helpers.php` — page gate, redirects
  instead of emitting a JSON envelope

`validatedTable()` calls the gate itself, so `api/owners.php`, `api/notes.php` and
`api/comments.php` are covered without per-endpoint code.

### The rule that keeps foreign keys working

**Gate request-supplied table names only — never config-supplied ones.** The gate
must not go inside `safe_table()`: that function also resolves FK reference tables
and subtables, and filtering there breaks label lookups inside tables the user is
fully entitled to. Config-supplied bindings (dashboard widgets, calendar sources,
boards) are *skipped* rather than rejected, so one out-of-scope widget cannot blank
a whole page.

**Subtables are the deliberate exception to that rule.** A subtable tab in
`edit.php` renders whole *rows* of the child table, not a single label, so leaving
it unfiltered would hand a user the full contents of a table they may not open. The
filter sits in two places, and they must stay in step:

- `public/edit.php` — drops out-of-scope entries from `$subtablesData` right after
  `$records->subtables()`. Filtered at the call site, not in
  `PgRecordRepository::subtables()`, because `src/` is frozen.
- `public/api.php` — the `subtable_counts` loop skips the same tables; a count is
  still a fact about a table the user may not open.

The distinction to keep in mind: FK exposes a **name**, a subtable exposes **rows**.
That is the line, and it is why one is exempt and the other is not.

`api/fk.php` is the sharp edge: it rewrites `$_GET['table']` to the reference table
and re-enters `public/api.php`. It gates its own request-supplied source table, then
defines `OS_TABLE_ACCESS_DELEGATED` so the `list` branch skips the gate for the
delegated name. Remove that constant and every FK pointing outside a user's tables
returns 403.

### Accepted gaps — do not "fix" without a design change

- FK labels still resolve across the boundary: a permitted table may display a name
  from a restricted one. That is a name leak, not a data leak, and closing it would
  break the dropdowns.
- A view or printout the user may open can read data from a table they may not. The
  binding is config-supplied, so it follows the same rule as FK references — grant
  the view only to users who should see its contents. Table access is not a
  substitute for reviewing what a view selects.

### When adding a new endpoint that takes a table, view or print name

Add `require_table_access()` / `require_view_access()` / `require_print_access()`
right after the existing "unknown/not found" check, and add the action to
`$postActions` in `public/admin/api.php` if it mutates. Both lists are hand-kept;
`tests/Admin/AdminApiGuardsTest.php` guards the second one.

## Where binding rules live

This document is the authoritative, version-controlled home for binding UI and
architecture decisions.

Several sections used to delegate their "binding rules" to an untracked local
developer note instead of stating them. Those pointers had been dangling for
months — the file they named was not in the repository, so the rules could not be
reviewed, released or recovered, and one set of them was lost outright. The
pointers were removed on 2026-08-07 and the rules written out in place.

When a rule must hold for everyone, write it **here**, in full. Never delegate a
binding rule to a file that is not tracked.
