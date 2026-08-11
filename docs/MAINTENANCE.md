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
configured views, the print templates, the boards and the workflows, from
**Users → Access**. The rules below are binding.

### One registry, no second list anywhere

`USER_ACCESS_SCOPES` in `includes/api_helpers.php` is the single definition of what
a scope is: which config document holds its items, whether that document is a
name-keyed map or a list of objects, which field carries the id and the label, the
noun for its 403, and the section heading for the admin tab. The resolver, the gates,
the filters, the admin picker and the Access tab all read it.

**Nothing else may enumerate scopes.** Not the admin module, not `users.js`, not a
test. Adding a scope is one row here plus the gate at whatever endpoint serves it;
every hand-kept copy of the list is a future drift between the picker and the gates,
and `AccessScopeEndpointGuardTest` fails the build if `users.js` grows one back. That
is also why `user_tables_get` answers with `scopes`/`items`/`selected` keyed by scope
instead of naming each one — the tab renders whatever the registry describes.

### Storage and semantics

- All scopes live in one `user_table_access` key of `spw_config`, shaped as
  `{"users": {"<id>": {"tables": [], "views": [], "prints": [], "boards": [],
  "workflows": []}}}`. No new table, no migration; an unknown key is ignored and a
  missing one means unrestricted, so the document upgrades itself.
- Views, printouts, boards and workflows are granted **by name**, not derived from a
  table — boards and workflows by their stable `id` (`brd_…` / `wf_…`, assigned once
  at creation in `admin/js/app.js` and independent of the title).
- **Granting a board or a workflow does not grant its tables.** Both still check the
  tables they touch: a board with an out-of-scope table is not listed, and a workflow
  is dropped when any step targets a table the user cannot reach. The two ticks are
  independent and both must hold — see "Files, boards and workflows" below.
- **A save must never widen a scope it was not asked about.** The document holds one
  entry per user and a save rewrites that entry whole, so a scope missing from the
  payload used to land as `[]` — which means unrestricted. `user_tables_save` now
  validates only the scopes it actually received and hands them to
  `merge_user_access_selection()`, which carries the stored value over for the rest.
  An explicit `[]` still clears a scope, and all-empty still deletes the entry; a
  submitted value that is not a list is treated as absent, because malformed input
  must not resolve in the widening direction. Anything else that ever writes this
  document goes through the same helper.
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

- `access_scope($scope)` — one scope's definition. Throws on an unknown name, so a
  typo is a hard error rather than a silent "unrestricted"
- `access_scope_items($scope)` — `name => label` for everything grantable, with the
  map/list difference already normalised away and hidden entries dropped
- `user_allowed_items($scope)` — the allow-list, or `null` for unrestricted
- `user_can_access($scope, $name)` — the predicate; `user_can_access_table()` /
  `_view()` / `_print()` are thin readability wrappers kept for the ~30 table call
  sites. **Do not add a wrapper per new scope** — call the scoped form
- `require_access($scope, $name)` — API gate, **403** (not 400: "unknown" and "not
  yours" are different answers). The noun comes from the registry
- `filter_by_user_access($scope, $items)` — narrows a collection and returns it in
  the shape it arrived in: a map stays a keyed map, a list stays a list
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

### Hidden tables follow their parent

Filtering subtables has a consequence that has to be handled with it: a **hidden**
table is excluded from `admin_assignable_items()`, so it can never be ticked. Left
alone, ticking any table at all would cost a user every hidden subtable tab, the
matching drill-down counts and the `create.php`/`edit.php` links those tabs point at
— permanently, with no admin action able to restore them. (`checklisty` under
`zadania` in the shipped example schema is exactly this case.)

`with_hidden_subtables()` closes the resolved table list over the hidden subtables
reachable from it, transitively, inside `user_allowed_items()` — so every call site
agrees without a single one of them knowing about it. The rule:

- **Hidden children are pulled in.** They have no menu entry and no grid, exist only
  as part of a parent record, and cannot be granted any other way. Access to them is
  a consequence, not a decision.
- **Visible children are not.** Those an admin grants by ticking them, and a missing
  tick hiding the tab is the intended, visible outcome. Widening this to visible
  tables takes the decision away from the admin — do not.

The closure is displayed, never silent: `user_tables_get` returns a
`hidden_children` map and the Access tab lists what each tick drags in.
`admin_assignable_items()` still excludes hidden tables from the picker — the two
work together, so do not "fix" the picker to offer them.

Still not covered: a hidden table used **only** as a workflow step target, reachable
from no subtable relation. It cannot be ticked and is not closed over, so a restricted
user cannot run that workflow — unhide the table and grant it explicitly.

Note that the reason has changed since workflows became a scope of their own. It used
to be that closing over workflow bindings would grant every restricted user every
table any workflow touches, because everyone saw every workflow — there was no anchor.
Now there is one: workflows are granted individually, so "grant the step tables of a
granted workflow" would be as well-anchored as the subtable closure. It is deliberately
**not** implemented, because it is a visible widening of table access and the two ticks
are documented as independent. If you ever add it, it belongs next to
`with_hidden_subtables()` and needs the same treatment: displayed in the Access tab,
never silent.

`api/fk.php` is the sharp edge: it rewrites `$_GET['table']` to the reference table
and re-enters `public/api.php`. It gates its own request-supplied source table, then
defines `OS_TABLE_ACCESS_DELEGATED` so the `list` branch skips the gate for the
delegated name. Remove that constant and every FK pointing outside a user's tables
returns 403.

**That waiver only holds because the projection is narrowed with it.** The `list`
branch selects every configured column and derives its `search` clause from the same
list, so an unnarrowed delegation is a full read of the reference table — columns,
free-text search, filters and pagination included — for a user who may not open it.
So when the reference table is out of scope, `api/fk.php` also defines
`OS_FK_LABEL_COLUMNS` (the `reference_column` plus the configured
`display_column`/`display_columns`), and the `list` branch intersects its projection
with it. The two constants belong together: never define one without the other.
Intersect, never assign — the constant may only ever *remove* columns from the
schema-derived list, so it can never introduce a name of its own into the SQL.
`tests/Security/AccessScopeEndpointGuardTest.php` pins both halves.

`api.php?api=schema` echoes the schema document itself and is therefore filtered
through `filter_tables_for_user()`, exactly like its sibling `public/api/schema.php`.
The internal `$schema` variable stays unfiltered on purpose — every config-supplied
lookup in that file reads it and must keep resolving — so the filtered copy
(`$schemaPublic`) is what gets encoded. The two endpoints must not disagree about
what a user is allowed to know exists.

### Files, boards and workflows

Three call sites do not fit the "gate the request-supplied name" shape and are easy
to get wrong:

- **`assertFileAccess()` in `api/files.php`** is the single write gate behind
  `delete`, `mass_delete`, `mass_tag` and `update_meta`. It resolves each uuid to its
  `(related_table, related_id)` and checks both the table scope and record ownership.
  It must **not** filter on `related_field`: doing so is what left every plain
  attachment unchecked while galleries were guarded. Unattached files (no
  `related_table`/`related_id`) belong to no record and stay editable by any logged-in
  user, matching how `actionList()` lists them; a file pointing at a table missing
  from the schema config fails closed and logs, because there is nothing left to check
  ownership against.
- **`api=board`** resolves `?board=` against the **filtered** list, not the raw
  config. That ordering is the point: the branch falls back to "the first board" when
  the parameter is missing or does not match, and filtering after that fallback would
  hand a restricted user a board they were never granted. It also blanks `table` and
  `status_column` when the bound table is out of scope — the lanes were already empty,
  but the binding itself is schema metadata and must not be named to someone who
  cannot open that table.
- **A workflow needs both ticks, at every one of its four call sites.** The scope
  grants the workflow by id; `workflow_tables_in_scope()` in `includes/api_helpers.php`
  is the other half, and it must run wherever a workflow is *served or fired*:
  `api=workflows`, the `menu.php` submenu, `index.php` (which hosts the wizard) and
  `workflow_procedure`. Step bindings are config-supplied, which normally means "skip,
  do not reject" — a workflow is the exception because it is the unit that either runs
  or does not, so a half-usable wizard is dropped rather than served. The parent menu
  entry is keyed off the surviving children, so it disappears with them.

  **Do not write the step loop out inline.** It was inline in the two sites that
  *display* a workflow, and that is precisely why `workflow_procedure` — the one that
  *runs* it — shipped gating the id alone: a user granted a workflow whose steps target
  tables they never got could not see it anywhere, yet could POST its id and run the
  procedure against those tables. `AccessScopeEndpointGuardTest` now pins the shared
  predicate at all four sites and fails on a re-inlined copy.
- **`workflow_procedure`** gates the request-supplied `workflow_id` *and* the resolved
  entry's step tables. Without the first the whole scope would be cosmetic: the
  workflow would vanish from the menu and the list while a direct POST still fired its
  procedure. Any future endpoint that acts on a workflow id needs both lines. Unknown
  ids fall through to the existing 400 — "unknown" and "not yours" stay different
  answers, the same rule the boundary gate follows.

### The admin shortcut is about the caller, not the subject

`user_allowed_items()` returns "unrestricted" for admins by reading
`$_SESSION['role']` — but only when no `$userId` was passed. An explicit id asks
about somebody else, and the caller's role says nothing about that user: answering
from the session would report every user as unrestricted whenever the code runs in
the admin panel or under a cron impersonating an admin. A "what does this user see"
preview would show them everything, and a per-user notification job would leak across
the very boundary it is meant to respect. Callers that want the shortcut for another
user must look up that user's role themselves.

### Hidden entries in the other scopes

`access_scope_items()` skips `hidden` entries in every scope, so a hidden board, view
or printout cannot be ticked — and unlike tables there is no parent to inherit from,
so a restricted user can never reach one. That is a tightening, not a hole: an
unrestricted user still opens them by direct URL exactly as before (the `hidden` flag
was only ever a menu-visibility flag, never a boundary). If a restricted user needs
one, unhide it and grant it explicitly. Do not "fix" this by offering hidden entries
in the picker — that would put items in the tab that the menu will never show.

### Accepted gaps — do not "fix" without a design change

- FK labels still resolve across the boundary: a permitted table may display a name
  from a restricted one. That is a name leak, not a data leak — the narrowed
  projection above is what keeps it to names — and closing it would break the
  dropdowns.
- `filter_col` on a delegated FK lookup still accepts any column of the reference
  table, so an equality filter over a *guessed* value is an oracle: it reveals that
  a matching record exists, and its label. Cascading FK dropdowns need that
  parameter, and the response carries nothing but the label columns, so this stays.
  Narrowing it would have to come with a configured cascade binding to replace the
  client-supplied one.
- A view or printout the user may open can read data from a table they may not. The
  binding is config-supplied, so it follows the same rule as FK references — grant
  the view only to users who should see its contents. Table access is not a
  substitute for reviewing what a view selects.

### When adding a new endpoint that takes the name of a protected object

Add `require_access($scope, $name)` (or one of the table/view/print wrappers) right
after the existing "unknown/not found" check, and add the action to `$postActions` in
`public/admin/api.php` if it mutates. Both lists are hand-kept;
`tests/Admin/AdminApiGuardsTest.php` guards the second one.

**Then record it in `tests/Security/request_scope_inventory.php`.** That file lists
every place the code reads a request-supplied table/view/print/board/workflow name,
with a decision — `gated`, `scoped`, `admin` or `none` — and a reason.
`RequestScopeInventoryTest` scans the source and fails when a read is missing from it,
when an entry describes a read that no longer exists, or when a reason is too short to
be a reason. So a new endpoint accepting a `?table=` cannot merge until someone has
written down what it does about it.

Why this exists: every gap found in the 2026-08 audit was a **forgotten gate**, not a
broken helper. The registry above removes the duplicated scope lists, but it cannot
make anyone call a gate — that still depends on remembering, and this is what moves
the remembering into CI.

Read its green run correctly. It proves that somebody looked at each of those reads,
**not** that they are all safe: the test cannot tell whether a gate is right, only
whether the file claiming to gate contains a gate call at all. A check that felt
complete would be worse than none, because then nobody would read the inventory
either. When you touch one of those endpoints, re-read its entry and ask whether the
reason still holds.

**A directory outside its globs is invisible to it, and that looks exactly like a
directory with nothing to find.** `public/admin/*.php` was missing from the list while
`includes/admin/*.php` was in it, and adding it immediately turned up an unrecorded
`$body['table']` in `public/admin/api_csv_import.php` — a read nobody had decided
about, sitting there the whole time. The decision was `admin` and nothing had to
change, but that was the answer only *after* someone looked. So
`testEveryRequestReachableDirectoryIsScanned` now asserts the **file list** rather
than the findings: a glob covering a directory with no reads today is otherwise
indistinguishable from a glob that was never written. Add a new request-reachable
directory to both the globs and that test.

### The boundary gate: closed by default

`os_api_bootstrap()` now applies the access rules itself, from
`OS_REQUEST_SCOPE_PARAMS` — the canonical map of request parameter names
(`table`, `related_table`, `view`, `print`, `board`, `workflow`, `workflow_id`) to
scopes. Every API endpoint is gated on those parameters whether or not its author
remembered to say so. Opting out is explicit and needs a reason at the call site:

```php
os_api_bootstrap(['gate' => false]);                 // whole endpoint
os_api_bootstrap(['gate' => ['table' => false]]);    // one parameter
```

Four rules make this safe, and none of them is optional:

- **Only names that EXIST in the configuration are gated.** An unknown name falls
  through so the endpoint answers 400/404 in its own words; gating it would turn every
  typo into a 403 and collapse the "unknown is 400, not yours is 403" distinction that
  the endpoints and their tests depend on.
- **Existence is checked including hidden entries.** A hidden table is
  unreachable-by-menu, not unknown — treating it as unknown would let it through
  ungated, which is the opposite of what this is for.
- **The body is read, not consumed.** `php://input` is re-readable, so endpoints still
  parse it themselves. That was verified against a running server, not assumed: a
  consumed stream would break every POST silently.
- **Nothing about the decision may come from a value the client chooses.** Two shapes
  broke this and both are now closed, so do not reintroduce either:
  - `os_gate_request_body()` parses the body for **every** content type except
    `multipart/form-data`, not just `application/json`. Keying it on the declared type
    handed the client the off switch: a POST labelled `text/plain`, or carrying no
    `Content-Type` at all, sailed past the gate while the endpoint parsed the same
    bytes as JSON. `multipart/form-data` is the sole exclusion and it is mechanical,
    not stylistic — PHP has already consumed that stream into `$_POST`/`$_FILES`, so
    there is nothing left to read.
  - Array-valued parameters (`?table[]=secret`) are walked element by element. A bare
    `is_string()` test skipped them, and "skip" is the one outcome a gate must never
    have for a shape the caller picks. Nesting deeper than one level still falls
    through, which is safe only because no endpoint casts that to anything but the
    string `Array`.

  `TableAccessTest` pins both, including a source check that the content-type
  condition has not crept back in.

`api/fk.php` is the one built-in exemption — its delegated `$_GET['table']` is skipped
when `OS_TABLE_ACCESS_DELEGATED` is defined, the same constant the `list` branch reads.

**The per-endpoint gates stay.** They run closer to the data, they cover names this map
cannot see (config-supplied bindings, values nested deeper in a body), and two
independent checks on an access boundary is the point, not redundancy to clean up.

The rule and the gate are separate functions on purpose:
`os_request_scope_violation()` decides and returns, `os_gate_request_scopes()` acts on
it. `require_access()` ends the process, so merged into one function the rule could not
be tested at all — `TableAccessTest` covers it directly.

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
