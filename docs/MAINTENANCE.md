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
  user, matching how `files_action_list()` lists them; a file pointing at a table missing
  from the schema config fails closed and logs, because there is nothing left to check
  ownership against.

  **`files_action_list()` carries the read half, and it is a separate predicate.** The write
  gate resolves one uuid at a time; the listing selects from `spw_files` directly and a
  single page spans many tables, so `owner_restriction_sql()` does not fit — it binds
  `table_name` to a parameter. The listing correlates on `f.related_table` instead,
  scoped to the tables actually marked `owner_restricted`, and filters in SQL so
  `COUNT(*)` and the `LIMIT`/`OFFSET` pagination agree with what is visible. Without it
  the listing handed out the name, tags, uploader and `related_id` of attachments on
  rows the caller cannot open, while every other file surface refused them. Admins are
  exempt, as `can_access_record()` exempts them and as `user_allowed_tables()` already
  no-ops for them in the same function: the admin Files module lists the whole library
  through this action. Covered by `idor.cy.js` (behaviour) and
  `AccessScopeEndpointGuardTest` (both halves pinned, so a refactor cannot drop one and
  leave the other looking complete).

  Note the asymmetry this leaves: the read path exempts admins, the write path does
  **not** — `assertFileAccess()` calls `can_access_record()` without the `$role`
  argument, so an admin is refused a `delete` on a file attached to another user's row
  in an `owner_restricted` table. Deliberately left as-is rather than changed in
  passing; it is a decision about the admin Files module, not about this boundary.
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

## Frontend data API: front controller + route modules (2026-08-14)

`public/api.php` was 1533 lines: nineteen inline
`if ($method === 'GET' && ($_GET['api'] ?? '') === '…')` branches, nesting eight levels
deep, 53 superglobal reads, and no use of the `os_api_dispatch()` helper its own
siblings in `public/api/` already had. Every new frontend feature landed in it.

It is now a **239-line front controller** plus one module per route group under
`includes/frontapi/`, mirroring how `public/admin/api.php` dispatches into
`includes/admin/`.

### What the front controller still owns

Order matters here, and all of it is pinned by `tests/Security/FrontApiGuardsTest`:

1. `os_api_bootstrap()` — auth, staleness, header-CSRF on POST/PATCH/DELETE.
2. The **self-service profile actions** (`update_avatar`, `change_password`). These
   run *before* the role gates on purpose: changing your own password must not depend
   on your role. Do not move them below the gates.
3. The admin block and the viewer read-only block — both before the schema is read.
4. The schema load, and the access-filtered copy (`$schemaJson`) that is the only form
   ever sent to a client. `?api=schema` stays inline: a module for one `echo` would
   cost more than it explains.
5. Read-route dispatch.
6. The **shared write preamble** — see below — and write-route dispatch.

### The one write gate

All six mutating routes (insert, patch, duplicate, delete, calendar move, board move)
resolve their target from one `$body['table']`, so the preamble decodes the body,
resolves the table through `safe_table()` (unknown ⇒ 400) and calls
`require_table_access()` **exactly once**.

Pushing that gate into the modules would recreate a hand-kept list of "routes that
remembered to gate" — the same shape as the admin `$postActions` whitelist and the
per-action `DEMO_MODE` calls, both of which have silently drifted before. So:

- `record.php`, `calendar.php` and `board.php` must **never** call
  `require_table_access()`. They receive a `FrontApiWriteContext`, and constructing
  one is what proves the gate already ran.
- `list.php` and `m2m.php` **must** call it: they take a request-supplied `?table=`
  of their own and run before any write preamble exists.

Both directions are asserted, and both were proven red before being merged.

### Explicit context, not ambient variables

The admin modules read `$action`, `$file` and `$isDemoMode` from the including scope.
That works, but no static analyser can verify it — those 21 files are the largest
group in `phpstan-baseline.neon` and the reason the project cannot yet raise PHPStan
past level 2.

The frontend modules therefore take a typed `FrontApiContext` /
`FrontApiWriteContext` parameter (`includes/frontapi/context.php`) instead, so a
mistyped field is a PHPStan error rather than a silent null. **New backend code
should follow this pattern, not the admin one.**

`$schema` on the context is the FULL document — every config-supplied lookup (FK
references, subtables, board and calendar bindings) resolves against it. `$schemaJson`
is the filtered one. Do not confuse them.

### Two couplings to know about before touching this

- **`public/api/fk.php` re-enters `public/api.php`.** It rewrites
  `$_GET['api'] = 'list'`, points `$_GET['table']` at the reference table, defines
  `OS_TABLE_ACCESS_DELEGATED` and `OS_FK_LABEL_COLUMNS`, and `require`s the front
  controller — which boots a second time. `api.php` must stay re-enterable, and the
  list route must keep honouring both constants.
- **A GET naming no known `?api=` route answers HTTP 200 with an empty body.** That is
  pre-existing behaviour, preserved deliberately rather than tightened inside a
  refactor: `index.php` requires this file whenever `?api` is present and the frontend
  reads an empty body as "nothing to do". Worth revisiting on its own, with the client
  checked alongside.

### Verification used for the split

Logged in as the seeded editor and captured all 24 read routes — including the FK
delegation path, `list` with filter / search / range / offset, and the empty-body edge
cases — before and after the change: **byte-for-byte identical**. All six mutating
routes were then exercised against real rows and the rows removed again. Repeat that
comparison if this dispatch is reorganised; the guard tests cover structure, not
output.

## Static analysis with PHPStan (2026-08-14)

### Why it was added

The codebase is ~33k lines of PHP with roughly 270 global functions loaded through
roughly 300 hand-written `require_once` calls. That shape has one dominant failure
mode: a call to a helper whose `require` is missing, a mistyped function name, or a
wrong argument count. `php -l` cannot see any of them — it checks syntax, and all
three are syntactically valid. They surface only when that one code path runs in a
browser, which is why they tend to reach production in rarely-exercised branches.

PHPStan closes exactly that gap. On its first run against the existing tree it
flagged two places where a variable was assigned in one conditional block and read
in a second, separate block testing the same condition — correct only for as long
as the two conditions stayed identical, with nothing enforcing that. One of them fed
`set_record_owner()` on an owner-restricted table, where a wrong owner is an
access-control outcome, not a cosmetic bug. Both were fixed rather than baselined
(`public/api/mass_edit.php`, `cron/cron_notifications.php`).

### Level 2 is a floor, not a ceiling

`phpstan.neon` pins `level: 2`. Levels 0–2 cover the failure mode above (unknown
functions and classes, unknown methods, argument counts, always-undefined
variables).

Level 3+ is **not currently reachable**, and the reason is architectural rather
than a matter of effort: the 21 procedural modules under `includes/admin/` read
`$action`, `$file` and `$isDemoMode` from the front controller's scope, and the
files under `templates/` read variables from whichever page included them. No
static analyser can verify a variable that arrives by scope inheritance. Raising
the level means giving those modules an explicit contract first — see the
`includes/frontapi/` convention, which passes an explicit context object precisely
so that new code does not add to this debt.

### The baseline is a ratchet

`phpstan-baseline.neon` records the 49 pre-existing findings. It may **shrink,
never grow**. When a change would add an entry, fix the code instead of
regenerating the file — a baseline that grows is just a lint suppression list.

Everything in it falls into four accepted categories, all stable:

- `$action` / `$file` / `$isDemoMode` in `includes/admin/*.php` — the documented
  front-controller scope contract.
- `$userRole` in `templates/template.php` and `$firstRun` in
  `public/admin/templates/header.php` — template scope inheritance; both are set by
  the including page (`public/index.php:16` for the former).
- 15 constants (`DB_HOST`, `RECORD_SNAPSHOTS_ENABLED`, `APP_ENCRYPTION_KEY`, …) —
  genuinely defined in `includes/config.php`, but via `define()` inside closures and
  conditionals, which PHPStan does not resolve across file boundaries.
- one dead `empty()` check in `includes/automations.php`.

None of these is a bug. Do not "fix" them by namespacing, casting, or adding
`@phpstan-ignore` comments.

### Running it

```bash
php phpstan.phar analyse --configuration=phpstan.neon --memory-limit=1G
```

The phar is a local convenience binary and is gitignored, exactly like
`phpcs.phar`. CI installs PHPStan through `setup-php`'s `tools:` key instead, so
nothing is added to `composer.json` and `composer.lock` needs no regeneration.

PHPStan's bundled signature map covers extensions that are not loaded locally
(`ftp`, `gd`), so a baseline generated on a developer machine matches what CI
produces. Without that, unmatched baseline entries would fail the CI run.

### The release-gate trap

`release-zip.yml` verifies required checks with `grep -Fxq` — an **exact
whole-line match** on check-run names — and the `php-version` matrix renames jobs
to `phpstan (8.4)` / `phpstan (8.5)`. Both names are listed in that workflow's
`REQUIRED` heredoc.

Two directions to get wrong here, and the second is worse:

- A name missing from `REQUIRED` means a red PHPStan does not block a release.
- A name present in `REQUIRED` whose job does not run on push-to-main blocks
  **every** release on a permanently missing check.

So the `phpstan` job must keep the same triggers as `phpcs` (`push: main`,
`pull_request: main`, `workflow_dispatch`). If a required check is ever absent
rather than red on a release commit, re-run the workflow from the Actions UI — do
not reach for `skip_checks=true`.

## PHP variable naming: no abbreviations (2026-08-15)

Commit `0d2d03f` renamed 193 single-letter and shortened variables across 33 PHP
files. The convention it established is binding for new code.

### The rule

Write the whole word. `$row`, not `$r`. `$column`, not `$c`. `$subtableData` and
`$subtableIndex`, not `$sd` and `$si`. `$galleryImage`, not `$gi`. `$m2mIndex`,
not `$mi`. This holds inside arrow functions and closures too — `fn($column) =>
pg_ident($column)` was the single most common offender, and the short form there
is no more readable for being one line long.

Established domain terms are **not** abbreviations and stay as they are: `$id`,
`$fk`, `$m2m`, `$sql`, `$csrf`, `$conn`, `$cfg`. Expanding those would make the
code read worse, not better.

### Name the value, not the loop

A mechanical `$r → $row` is wrong wherever `$r` did not hold a row, and seven
files in the tree were exactly that. They were renamed by meaning instead:

| Location | Held | Renamed to |
| --- | --- | --- |
| `includes/bootstrap.php` | a `UserRole` enum case | `$userRole` |
| `includes/admin/anonymization.php` | a replacement string | `$replacement` |
| `includes/admin/performance.php` | a `pg_query_params()` handle | `$countRes` |
| `public/cypress_seed.php` | a `pg_query_params()` handle | `$userRes` |
| `public/admin/demo/seed.php` | a comment row / an anonymization rule | `$comment` / `$rule` |
| `public/api/data_cleanup.php` | one character of a character class | `$char` |
| `includes/automations.php` | an automation rule | `$rule` |

The `performance.php` case is the one that would have introduced a bug rather
than merely a bad name: a blanket `$r → $row` there collides with the `$row`
already live in that scope.

Renaming also has to carry the derived names along. `$gi` became
`$galleryImage`, so `$giUrl` became `$galleryImageUrl` in the same change; the
same for `$siLabel` → `$subtableLabel`. A half-renamed prefix is worse than the
original abbreviation because it implies a variable that no longer exists.

### How the rename was done, and how the next one should be

Not with `sed` or `preg_replace`. The rename ran through `token_get_all()`,
substituting only `T_VARIABLE` tokens and re-emitting every other token
verbatim — which is lossless for valid PHP, and leaves string literals, regular
expressions and the SPDX headers untouched. A textual pass over
`includes/etl_engine.php` alone would have corrupted three `preg_match()`
patterns.

The precondition that makes any file-scoped rename safe was verified first: the
tree contains no `extract()`, no `compact()` and no `$$variable`. If that ever
stops being true, a file-scoped rename stops being sound and this recipe no
longer applies.

Verification for the change was `php -l` on every touched file, the full PHPUnit
suite (366 tests), and a per-file `phpcs` comparison against `HEAD` rather than a
single repository-wide total — a whole-tree count silently hid one file that
failed to extract from the comparison snapshot and made the result look five
warnings worse than it was.

### Longer names cost line length — wrap, do not re-abbreviate

Six lines crossed the 120-character PSR-12 warning threshold once the names grew.
All six were wrapped: arguments split one per line, or the repeated expression
hoisted into a named variable (`$setCols` in `includes/etl_engine.php`). None was
solved by shortening the name back. Net effect on the tree was zero new
`Generic.Files.LineLength` warnings and one fewer in
`includes/admin/performance.php`.

PHP CS Fixer was deliberately **not** introduced for this. `phpcs --standard=phpcs.xml .`
reports **0 errors** across the tree, so indentation and PSR-12 layout are already
compliant; a second formatter would add a dev dependency and a large reformatting
diff for no correctness gain. `phpcbf.phar` remains the auto-fixer of record.

## Service layer and the end of `$GLOBALS['conn']` (2026-08-15)

### What the audit actually found

The premise going in was that "classes and functions reach directly into
`$GLOBALS['conn']`, so nothing can be isolated or unit-tested." Measured against
the tree, that was only a quarter true. `$GLOBALS` appeared **15 times in 4
files**: the assignment in `includes/bootstrap.php`, a fallback in
`map_fk_display()`, and 13 reads inside `public/edit.php` and
`public/create.php`. Every persistence helper already took `$conn` as its first
parameter — the connection was passed explicitly almost everywhere.

The real coupling was different and worth naming precisely: **~150 global
procedural functions** across `includes/`, reached by name. A function called by
name cannot be substituted, so a caller cannot be exercised without the real
database behind it. That is a seam problem, not a `$GLOBALS` problem, and the two
need separate fixes.

### The seam: `includes/Service/`, namespace `App\Service\`

New classes, each taking the connection through the constructor:

| Class | Replaces |
| --- | --- |
| `RecordOwnershipService` | `get_record_owner_id`, `can_access_record`, `filter_visible_ids`, `set_record_owner`, `owner_restriction_sql` |
| `RecordSnapshotService` | `fetch_record_json`, `snapshot_record` |
| `M2MService` | `m2m_options`, `m2m_selected`, `m2m_sync` |
| `ImageService` | `images_config`, `images_for_record`, `images_count`, `images_for_rows` |
| `AutomationService` | `auto_capture_old_record`, `evaluate_automation_rules` |
| `ServiceContainer` | lazily builds all of the above from one connection |
| `Sql` | identifier quoting and `int[]` literals, previously open-coded per file |

`os_boot_app()` returns the container under the `services` key.

`src/` stays **frozen**; this is why the layer lives in `includes/`. The
namespace is still `App\Service\` because both autoloaders are prefix-mapped —
`includes/autoload.php` checks `App\Service\` → `includes/Service/` *before*
falling through to `App\` → `src/`, and `composer.json` lists the more specific
PSR-4 prefix first. `phpunit.xml` now boots `tests/bootstrap.php`, which loads
the Composer autoloader and then `includes/autoload.php`, so the new namespace
resolves in tests without a `composer dump-autoload`.

### PDO was deliberately **not** introduced

The request named PDO. The platform runs on the `pg_*` extension end to end —
`pg_query_params`, `$1` placeholders, `PgSql\Connection` type hints in `src/`,
`pg_escape_literal`, `COPY` in the ETL engine. Swapping the driver is a rewrite
of every query in the tree with no behavioural gain, and it is orthogonal to
testability: constructor injection of `PgSql\Connection` buys the same seam.
The connection is injected; the driver is unchanged.

### Backward compatibility is deliberate

The old global functions still exist and keep their signatures — they are now
one-line delegators to the services. 177 PHP files, 27 test files and 30 Cypress
specs call them; converting every call site in one change would have been a
large untested diff. The delegators are the migration path, not the destination:
new code calls the service, and call sites move over as they are touched.

Two signatures did tighten, and both call-site sets were updated in the same
change:

- `map_fk_display()` — `$conn` went from optional (with a `$GLOBALS` fallback) to
  **required**. Its three callers in `includes/frontapi/` already had `$conn` in
  scope from `FrontApiContext`.
- the former `m2m_*` helpers took `mixed $conn`; the service constructor takes
  `PgSql\Connection`.

`includes/m2m.php` was **deleted**: once `edit.php` and `create.php` moved to
`M2MService`, it had zero callers anywhere in the tree.

### What was left procedural, and why

`includes/automations.php` is a 725-line rule engine (20 functions: condition
evaluation, webhooks, email, templating). `AutomationService` is a **facade**
over it — it owns the connection and exposes `captureOldRecord()` and
`evaluate()`, and the engine body is untouched. Rewriting that engine is a
behavioural change to automation semantics with no existing test coverage to
catch a regression; it is separate work.

Service classes call the global `sys_table()` without requiring `db.php`
themselves. That matches the existing frozen classes (`PgFileRepository`,
`DbAuditLogger`) and keeps the files free of the `PSR1.Files.SideEffects`
warning a file-scope `require_once` would add.

### Guard

`tests/Service/NoGlobalConnectionTest` walks `includes/`, `public/`, `src/`,
`cron/` and `templates/`, strips comments with `token_get_all`, and fails on any
`$GLOBALS` or `global $` in the tree. It was proven red by reintroducing a
`$GLOBALS['conn']` read into `api_helpers.php` before being committed green.
Suite: 388 tests, 956 assertions, 0 phpcs errors on the touched files (the three
remaining `SideEffects` warnings are unchanged from `HEAD`).

## Exceptions instead of `die()` / `exit()` (2026-08-15)

Request-path code no longer terminates itself. Every `die()` and `exit()` outside
`cron/` — 437 of them, across 60 files — was replaced by a thrown exception, and
a single handler turns that exception into the HTTP response.

**The hierarchy** lives in `includes/Exception/` under `App\Exception\`
(`includes/` is where new backend code goes; `src/` is frozen). `includes/autoload.php`
maps the namespace, and `includes/config.php` requires the autoloader, so the
classes are reachable from every entry point.

- `ControlFlowException` — marker interface extending `\Throwable`, implemented by
  all three families below. It is what `catch` blocks test against.
- `HttpException` — carries a status code and an optional response body.
  Subclasses fix the status: `BadRequestException` (400), `UnauthorizedException`
  (401), `ForbiddenException` (403), `NotFoundException` (404),
  `ConflictException` (409), `ServerErrorException` (500).
  `HttpException::fromStatus($code, $message, $body)` returns the right subclass
  for a status computed at runtime, and any other status as a plain
  `HttpException`.
- `RedirectException` — a `Location:` header and status. Replaces every
  `header('Location: …'); exit;` pair.
- `ResponseException` — a response that is already decided. `::json($data, $status)`,
  `::encoded($data, $flags)` (preserves `json_encode` flags and leaves the status
  untouched), `::raw($payload, $contentType)` and `::sent()` for output that was
  already streamed, e.g. `readfile()` in `file_download.php`.

`HttpException` deliberately extends `\Exception`, **not** `\RuntimeException`.
The codebase has many `catch (\RuntimeException)` blocks that exist to catch
`safe_table()` failures; making the new hierarchy a sibling keeps those blocks
from swallowing responses. A status code of `0` means "leave the current
`http_response_code()` alone" — that is how `admin_err()` and `admin_ok()` keep
their old behaviour of not touching the status.

**The handler** is `includes/exception_handler.php`, required by
`includes/bootstrap.php`. It is a separate file precisely so that pre-install and
lightweight entry points (`setup_api.php`, `cypress_seed.php`, `logout.php`,
`etl_cli.php`) can install it without pulling in session and config bootstrap.

`os_register_exception_handler($mode)` installs `os_handle_exception()` and sets
the response mode: `json`, `html`, or `cli` (chosen automatically for
`PHP_SAPI === 'cli'`). `os_page_bootstrap()` registers `html`,
`os_api_bootstrap()` registers `json`, and entry points that use neither register
it themselves as their first statement. The handler renders redirects, decided
responses and HTTP errors, guards every write with `headers_sent()`, and logs any
non-`ControlFlowException` throwable before answering with a generic 500 — the
original message is never sent to the client.

**Terminating helpers throw.** `jsonError()`, `jsonSuccess()`,
`require_not_demo()`, `check_record_ownership()`, `os_require_csrf()`,
`admin_ok()`, `admin_err()`, `admin_try()` and `admin_require_log_table()` all
keep their `never` return type and their exact JSON body shape — the wire format
is unchanged, only the mechanism is. That is why one conversion covered several
hundred call sites for free.

**The trap: broad `catch` blocks.** Once a helper throws instead of exiting, any
enclosing `catch (Throwable)` or `catch (Exception)` swallows the response and
turns a finished 200 into a generic error. Every such block must rethrow first:

```php
} catch (ControlFlowException $signal) {
    throw $signal;
} catch (Throwable $e) {
```

73 catch blocks were patched this way, including the two central ones —
`admin_try()` and `os_api_dispatch()`.

**`cron/` exits exactly once, at the end.** A CLI script's exit status is its
interface with cron, so the status call itself stays — but it is no longer
scattered through the script as an escape hatch. Each cron entry point now runs
its work in a `main` function returning an `int`, and the file's last statement
is the only `exit()`:

```php
function cron_etl_main(array $argv): int
{
    …
    return $anyError ? 1 : 0;
}

exit(cron_etl_main($argv));
```

`$argv` is passed in rather than read as a global. Everything above the `main`
function — the CLI guard, the output-buffering setup, the `require_once` lines
and the script's own helper functions — stays at file scope, so nothing about
symbol visibility changed. The 24 scattered `exit()` calls became `return`
statements: 9 in `cron_etl.php`, 7 in `cron_anonymization.php`, 6 in
`cron_etl_flow.php`, 2 in `cron_notifications.php`. The "this must run from the
command line" guards throw `ForbiddenException` instead of exiting.

Two exit codes were **deliberately left as they were**, because the restructure
was not the place to change behaviour — but they are now plainly visible as a
`return 0;` and worth a decision:

- `cron_notifications.php` catches a critical error, prints it in red, and still
  returns `0`. The admin panel's runner reports `status: success` for any zero
  exit, so a failed run currently reads as successful there.
- `cron_anonymization.php` returns `$errorMessage !== null ? 1 : 0` on the dry-run
  path but plain `0` on the real path, so a real run that recorded an error also
  reports success.

`tests/Http/ExitFreeRequestPathTest` enforces the rules — no `die`/`exit` tokens
in `includes/`, `public/`, `templates/` or `src/`; exactly one `exit()` per
`cron/` script, taking a status code, as its final statement; and no unguarded
broad `catch`. It scans with `token_get_all`, and was proven red by reintroducing
an `echo`+`exit` pair, a bare `exit;` in `cron_etl.php` and an unguarded
`catch (Throwable)` before being committed green.

All four cron scripts were run before and after the restructure against the same
database: byte-identical output and identical exit codes, including the `admin`
trigger, the `dry` flag and `cron_etl.php`'s `_run` subprocess entry point.

Verified end to end against a running instance: guest redirects, `401`/`403`/`400`
JSON envelopes, the admin API's eight read modules and its `admin_err` write
paths, the CSRF and method-not-allowed guards, and a frontend insert/patch/delete
round trip. Suite: 391 tests, 959 assertions, 0 phpcs errors.

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
