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
  in `includes/bootstrap.php`, wired in `App\Service\AppContext` —
  create.php/edit.php) and PostgreSQL record
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

> Since 2026-08-15 that front-controller body lives in
> `includes/Controller/FrontApiController.php` and `public/api.php` is only its entry
> point — see "Page and API controllers". Everything below still describes it,
> unchanged; only the file it sits in moved, and the guard tests moved with it.

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

`phpstan-baseline.neon` started at 49 pre-existing findings and is down to **48**.
It may **shrink, never grow**. When a change would add an entry, fix the code
instead of regenerating the file — a baseline that grows is just a lint suppression
list. The ratchet is enforced from the other side too: `reportUnmatchedIgnoredErrors`
is on, so an entry that stops matching **fails the run** until it is deleted.

The `$firstRun` entry for `public/admin/templates/header.php` was the first one
retired (2026-08-15). It stopped matching once that template grew a
`$firstRun ??= false;` default of its own, which is the intended way out of an
entry: fix the code, then delete the line. It was removed by hand — the file was
*not* regenerated, because regenerating would have re-recorded every other finding
as if it were new.

Everything left falls into four accepted categories, all stable:

- `$action` / `$file` / `$isDemoMode` in `includes/admin/*.php` — the documented
  front-controller scope contract.
- `$userRole` in `templates/template.php` — template scope inheritance; it is set by
  the including page (`public/index.php:16`).
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
| `includes/admin/performance.php` | a `pg_query_params()` handle | `$countResult` |
| `public/cypress_seed.php` | a `pg_query_params()` handle | `$userResult` |
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

`os_boot_app()` returns an `App\Service\AppContext`, and the container is reachable
on it as `services()`.

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

### Testing the handler: why it runs in a subprocess

`ExitFreeRequestPathTest` proves the request path *throws*;
`tests/Http/ExceptionHandlerTest` proves the handler *answers* — that
`ForbiddenException` is a 403, `BadRequestException` a 400, `NotFoundException` a
404, `RedirectException` a `Location:` header, and anything else a logged,
message-free 500.

None of that is observable from inside a PHPUnit process, and the reasons are worth
writing down before someone "simplifies" the test:

- `os_response_mode('html')` resolves to `cli` under the CLI SAPI, so the HTML
  branch never executes in a normal test.
- `headers_list()` always returns an empty array under CLI, so no assertion about
  a sent header is possible.
- `http_response_code()` refuses to set once output has started, and PHPUnit has
  already printed a progress dot by the time a test body runs.

So the test runs `tests/Http/fixtures/throwing_request.php` as a **subprocess** and
inspects the result from outside. Three details carry weight:

- The fixture is configured through `OS_TEST_*` **environment variables, not
  `$argv`** — CGI does not pass argv, and this keeps one fixture valid under both
  SAPIs.
- It **throws with no `try`/`catch`**, so what is exercised is the real
  `set_exception_handler` path, not a direct call to `os_handle_exception()`.
- The status code comes back via a `register_shutdown_function` that writes
  `http_response_code()` to a file, because the shutdown function runs *after* the
  exception handler.

Real response headers (`Location:`, `Content-Type:`) still need a web SAPI, so
those four cases shell out to `php-cgi` with `SCRIPT_FILENAME` set and parse the
raw header block. When that binary is missing the four `markTestSkipped` and the
other nine still run — verified by pointing `PHP_BINARY` at a directory with no
`php-cgi` sibling and an empty `PATH` lookup: 9 pass, 4 skip, no errors.
`shivammathur/setup-php` installs the CLI binary only, so `php-tests.yml` installs
`php{version}-cgi` explicitly and pins `OS_TEST_PHP_CGI` to the matrix version —
otherwise `update-alternatives` can point `/usr/bin/php-cgi` at the runner's
preinstalled PHP and the 8.5 job would quietly test CGI 8.3. That install step is
deliberately tolerant (`::warning::`, not a failure): the tests degrade to skips on
their own, and this job is a release gate, so an upstream packaging gap must not
turn a required check red.

The suite was proven red before being committed green. Seven mutations of
`exception_handler.php` were each caught: dropping the `RedirectException` branch
(2 failures), dropping the `ResponseException` branch (1), removing the
`error_log()` call (1), passing the original message into the 500 instead of the
generic text (1), hard-coding `http_response_code(200)` (7), and ignoring the mode
argument on re-registration (9). Suite after the addition: 405 tests, 1006
assertions.

## The request object: `os_request()` instead of superglobals (2026-08-15)

### The rule

Request input is read through `App\Http\PhpRequest`, never through `$_POST` or
`$_GET` directly. Pages booted by `os_boot_app()` reach it as
`$context->request()`; everything else calls `os_request()`.

```php
$selected = array_values(array_filter(
    (array) $request->post('m2m_' . $m2mIndex, []),
    'ctype_digit'
));
```

`src/Http/PhpRequest.php` is the **only** file in the request path that may name
`$_POST` or `$_GET`. Test fixtures may set them, because that is how the object is
driven under test.

### One shared instance, reachable without the full boot

`os_request()` lives in `includes/bootstrap.php` and returns a lazily built static
instance. It `require_once`s `includes/autoload.php` itself, so it works in the API
endpoints and cron-adjacent code that never call `os_boot_app()` — and
`os_boot_app()` returns that same instance rather than constructing a second one.

`os_request_scope_violation()` in `includes/api_helpers.php` requires
`bootstrap.php` **inside the function body**, not at the top of the file. Several
entry points (`public/setup_api.php`, `cron/*`, the `includes/admin/*` modules, the
security tests) include `api_helpers.php` without bootstrap, and a top-level require
would close an include cycle through `page_helpers.php`.

### `post()` returns `mixed`; `query()` deliberately does not

`post()` has to hand back arrays — the many-to-many pickers submit `m2m_<n>[]` — so
its signature is `post(string $key, mixed $default = ''): mixed` and it no longer
casts. **Every scalar call site casts for itself:**

```php
if (!$csrf->isValid((string) $request->post('csrf_token'))) {
```

Without that cast, `csrf_token[]=x` reaches `isValid(string $given)` as an array and
the request dies with a TypeError — a 500 where the guard used to answer 403.

`query()` keeps its `string` parameter and return type for exactly the same reason
read the other way round: widening it would let `?table[]=x` reach
`hasTable()`/`safe_table()` as an array and turn today's 400 into a 500. When raw
access is genuinely needed, use the array accessors `queryAll()` / `postAll()` —
that is what the request-scope gate does, because it needs the
absent-vs-null-vs-array distinction uniformly across GET, POST and body.

### The trap: the scope inventory keys encode the read *shape*

`tests/Security/RequestScopeInventoryTest` scans source text for request-supplied
table/view/print/board/workflow names, and its inventory keys spell out how the read
was written — `_POST.table` for a superglobal, `post().table` for the accessor.
Moving a read onto `$request` therefore **renames its inventory key**, and
`tests/Security/request_scope_inventory.php` has to be updated in the same change or
three tests go red. Coverage is not lost: the scanner already recognises the
`query`/`post`/`input`/`get` accessor shapes. Only the key changes, and the recorded
decision and reason carry over verbatim.

That refactor also emptied out the last literal `$_POST['<protected key>']` in the
scanned globs, so the scanner's own shape check no longer had a live example to
point at. `scanSource()` was extracted from `scan()` and the check now runs all five
shapes against an inline source fixture; the real-file assertions were kept only for
shapes the tree still contains. Pinning that check to whichever file happens to
retain a superglobal today would make it silently rot on the next refactor.

### Deliberately left alone

Nothing. As of this change `$_POST` appears only in `PhpRequest` and in test
fixtures. `public/cypress_seed.php` was converted too and gained an explicit
`require_once bootstrap.php`; its POST-with-GET-fallback shape is preserved as
`$request->post('table', $request->query('table'))`.

### Verification

392 tests / 965 assertions green; `phpcs` unchanged against the pre-refactor tree
(0 errors, the same five pre-existing `Files.SideEffects` warnings). A `php -r`
smoke test confirmed the array default, the empty-array default, scalar reads and
the m2m filter reindexing to `["1","2"]`.

PHPStan is green. The run surfaced one `ignore.unmatched` error for `$firstRun` in
`public/admin/templates/header.php`, which was **pre-existing and unrelated** —
reproduced on a reconstruction of the tree as it stood before this refactor — and
was retired from the baseline as described under "The baseline is a ratchet".

## Page and API controllers: `App\Controller\` (2026-08-15)

`public/edit.php` was 336 lines of procedural page: permission checks, the POST
handler, snapshots, automations, many-to-many synchronisation, six blocks of
view-data preparation and two output buffers, all at file scope. `create.php` (136)
and `api.php` (178) were the same shape at smaller sizes. Everything in them ran on
include, so none of it could be reached from a test, and the only way to describe
the flow was to read it top to bottom.

Each is now a **controller class plus an entry point of roughly 15 lines**:

```php
$pageMeta = os_page_bootstrap(['csp' => 'unsafe-style', 'redirect_admin' => false]);

$controller = new EditController(os_boot_app());
$controller->handle($pageMeta);
```

The entry point owns nothing else: no business logic, no superglobals, no template
includes. Everything else moved into `handle()`.

### One constructor argument: `AppContext` (2026-08-16)

The first cut of these controllers took the whole object graph apart in the entry
point and passed it back one service at a time — a ten- and eleven-argument
constructor, fed by a list-destructuring of `os_boot_app()` that `create.php` and
`edit.php` each repeated with a slightly different key set. Adding a dependency
meant editing three places, and the arguments were positional, so a reordered pair
of same-shaped services would have type-checked.

`os_boot_app()` now returns **`App\Service\AppContext`** (`includes/Service/AppContext.php`)
and every page controller takes that one object. The context builds the graph in
its constructor — exactly what `os_boot_app()` used to assemble, in the same order,
so `db_connect()` still runs at boot — and exposes each dependency through a typed
accessor (`session()`, `request()`, `csrf()`, `schemas()`, `fieldRegistry()`,
`mapper()`, `records()`, `files()`, `audit()`, `fkLoader()`, `services()`,
plus `connection()`/`database()` for the raw handles).

Controllers unpack the accessors they need into `private readonly` properties in the
constructor, so the body of the class is unchanged and still names concrete types —
`AppContext` is a wiring seam, not a service locator to be passed further down.
Nothing outside a constructor takes it, and nothing calls it at random from deep in
a method.

`handle()` lost its `PhpRequest` argument at the same time: the request was being
injected into the constructor *and* handed back in on every call. The controller
reads `$this->request`, and the private `save()`/`prefilledValues()` helpers dropped
their pass-through `$request` parameter too.

`ServiceContainer` is unchanged and still lazy — `AppContext` holds one and delegates.
The two are different layers: the container builds connection-only domain services,
the context is the whole request-scoped graph a page controller needs.

### `AppContext` is lazy, and that is a guarantee, not an optimisation

Every accessor memoises through `??=`, and **`db_connect()` appears exactly once in the
class**, inside `connection()`. Everything connection-bound reaches the handle through
that one accessor. Reading `session()`, `request()`, `csrf()`, `fieldRegistry()` or
`mapper()` therefore opens no connection at all.

This is what lets a controller answer 401/403 before the database is touched — the
ordering `public/api.php` gets today from `os_api_bootstrap(['connect' => false])`, and
the ordering six of the thirteen `public/api/*.php` endpoints depend on. `AppContextTest`
pins all of it: nothing is built by the constructor, the session and CSRF paths leave
every connection-bound property null, and the single `db_connect()` call site is asserted
on the source text.

For the page controllers the practical effect is nil: they unpack the accessors they need
in their own constructor, so the connection opens one line later than it used to.

### `ApiRequest`: the action envelope

`os_api_action()` returned `['method', 'action', 'body']` and four endpoints
(`comments`, `files`, `notes`, `owners`) each destructured it by hand — the same shape
that made `os_boot_app()`'s array a maintenance cost. It now returns
**`App\Service\ApiRequest`** (`includes/Service/ApiRequest.php`): a readonly value object
with `method`, `action`, `body(key, default)`, `bodyAll()`, `isMethod()` and `isWrite()`.

`body()` is deliberately named so that `RequestScopeInventoryTest`'s `ACCESSORS` list
catches `->body('table')` as a `body().table` read. **A new request accessor that is not
in that list is invisible to the scan**, which is the same failure mode as a file moved
out of the glob: the guard stays green and stops guarding. Add the name to `ACCESSORS`
in the same change that introduces the accessor.

### API controllers read the request through `PhpRequest` (2026-08-16)

The controllers moved in the two preceding stages still read `$_GET` and
`$_SERVER['REQUEST_METHOD']` directly — the move preserved the bodies verbatim on purpose.
46 of the 51 remaining request-superglobal reads now go through
`$context->request()` (`App\Http\PhpRequest`): `query()`, `queryAll()`, `method()`.

**`ApiRequest` is the wrong target here, and that is not a style preference.** Every one of
these endpoints is called as `api/views.php?action=data`, `?action=mass_delete`,
`?action=data_cleanup_apply` — **the action is in the query string, and never in the JSON
body**. `os_api_action()` fills `action` from `$_GET` for GET but from `$body['action']` for
POST, so `$_GET['action']` → `$this->api->action` would resolve to `''` on every POST and
turn each of them into a 400. `$_GET['action']` becomes `$this->request->query('action')`.

For the same reason the `php://input` decodes were **left alone**. `os_api_action()` only
decodes a body when `Content-Type` contains `application/json`; a bare
`file_get_contents('php://input')` decodes unconditionally. Swapping them makes behaviour
depend on a header the caller controls — a wire change, not a read-shape change.

**Where `query()` is not a faithful translation.** `query()` is
`(string)($_GET[$key] ?? $default)`, so it collapses two distinctions the raw read keeps:
absent vs. present-but-empty, and scalar vs. array. That is harmless where the value is
compared or used as an array key, but not where it reaches SQL or a nullable branch. Three
reads use `queryAll()` instead:

- `ViewsController::viewData()` — `filter_val` is `null` when absent and becomes a
  `pg_query_params` bind when present, so `?filter_val=` must still produce `WHERE col = ''`.
- `PrintController::printData()` — `p_<key>` values are bound the same way.
- `CommentsController::listComments()` — `limit` absent means "no limit", not "limit 0".

The behaviour baseline is the only thing that proves this, so five scenarios were added
that discriminate the collapsed cases (see the coverage note below).

**Not converted, and why:** the five reads in `FilesController` that handle multipart
uploads — `$_FILES`, `$_SERVER['CONTENT_LENGTH']`, `$_SERVER['CONTENT_TYPE']`.
`PhpRequest` has no accessor for them and `src/` is frozen, so adding one is a separate
decision. The 37 `$_SESSION` reads in these controllers are a different seam
(`$context->session()`) and a separate stage.

**Inventory keys move with the read shape.** Seven entries in
`tests/Security/request_scope_inventory.php` changed from `_GET.x` to `query().x`
(comments, files, notes, owners, print ×2, views). `RequestScopeInventoryTest` failed from
**both** directions — undescribed new reads *and* stale entries describing reads that no
longer exist — which is exactly what that test is for.

### `os_query_string()`: an array-valued parameter is "absent", not a 500 (2026-08-16)

`?table[]=x`, `?view[]=x`, `?print[]=x`, `?board[]=x` and `?workflow[]=x` each produced a
**500**: the value reached `substr()` as an array and PHP 8 raises a `TypeError`. Nothing
was exploitable — the exception handler returns a generic page — but a query string a user
can type should not take the page down.

`public/index.php` had *already* guarded its `table` read with
`is_string(...) ? ... : ''`. The fix generalises that intent instead of inventing a new
one, as `includes/bootstrap.php`:

```php
function os_query_string(string $key, string $default = ''): string
{
    $value = os_request()->queryAll()[$key] ?? $default;

    return is_string($value) ? $value : $default;
}
```

Now used by `index.php` (table, workflow), `views.php`, `print.php`, `board.php`,
`file_download.php` (uuid) and `templates/menu.php` (all five). **An array is treated
exactly like an absent parameter** — the baseline confirms all five array scenarios now
render byte-identically to their no-parameter equivalents.

**`PhpRequest::query()` was deliberately not changed instead.** It would be the tidier
place, but `src/` is frozen, and more importantly `query()` has ~30 call sites across the
controllers and admin whose current `(string)` behaviour was proved faithful stage by
stage. Changing it would re-open all of them for a bug that lives on six pages.

**The scanner needed a third pattern.** `RequestScopeInventoryTest` matched
`$holder['key']` and `->accessor('key')`; a plain function call like
`os_query_string('table')` is neither, so all ten reads would have gone invisible — the
same failure the `HOLDERS` gap produced one commit earlier. A `HELPERS` list was added in
the same change, and this time **the ten stale entries were matched by ten new ones**,
which is the balance to check for. `testScannerStillMatchesTheShapesItClaimsTo` now covers
both the helper and the `$queryParameters` holder.

Blast radius, recorded against HEAD `26203eb`: **5 of 648 scenarios differ**, all five
array cases, all `500 → 200`. Nothing else moved.

### `cron/`: nothing to convert, one guard that was missing (2026-08-16)

The last stage of the read refactor changed **no production code**, and that is the
finding, not a shortfall.

All four superglobal reads in `cron/` are `$_SERVER['argv']` — CLI arguments, not request
input. `PhpRequest` has no accessor for argv and should not grow one: argv is not a
request, and routing it through a request object would misrepresent where the data comes
from. They stay raw.

**The shared CLI prologue was deliberately not extracted.** `cron_etl.php` and
`cron_etl_flow.php` call `etl_cli_boot()` (`includes/etl_cli.php`);
`cron_anonymization.php` and `cron_notifications.php` hand-roll the same block. They look
identical but are not — the hand-rolled pair also sets
`@ini_set('zlib.output_compression', '0')`, which `etl_cli_boot()` does not. Extracting a
shared helper would have to either add that to the ETL scripts or drop it from the other
two; both are behaviour changes wearing a de-duplication costume. Same rule as the
`data_cleanup` prologue in the API stage: **de-duplicate blocks that are byte-identical,
leave blocks that differ and say why.**

**What was actually missing: a guard test.** Every cron script refuses a non-CLI SAPI —

```php
if (php_sapi_name() !== 'cli') {
    os_register_exception_handler('html');
    throw new ForbiddenException('This script may only be run from the command line.');
}
```

— and **nothing verified it**. `cron/` sits outside the `public/` docroot, so this is
defence in depth against a docroot pointed at the repository root, which is exactly the
kind of guard that gets deleted in a cleanup because nothing goes red.
`tests/Security/CronCliGuardTest.php` now pins it: every `cron/*.php` either carries the
check or delegates to `etl_cli_boot()`, the helper itself carries it and throws, and the
guard is reached **before** any `db_connect()`. The directory-not-empty assertion is there
so the whole thing cannot pass vacuously.

It was proved red the way these tests have to be: a probe script whose guard was present
only **inside a comment** made it fail, which also confirms the `token_get_all()`
comment-stripping does its job.

No baseline run for this stage — the harness speaks HTTP and these are CLI entry points,
and with zero production changes a comparison would only be theatre.

### Admin reads, and the one place that stays raw (2026-08-16)

The admin modules stay procedural. Their query-string and session reads moved to
`os_request()` / `admin_user_id()`, with one deliberate exception.

**The security prologue of `public/admin/api.php` stays on raw superglobals.** Lines 25–36
(login + admin-role gate), 47–54 (the CSRF check) and 78 (the `$postActions` POST-only
gate) keep `$_SESSION` and `$_SERVER['REQUEST_METHOD']` verbatim. Two reasons, and the
first is decisive:

- `AdminApiGuardsTest::testCsrfIsValidatedOnEveryMutatingVerb` asserts the **source text**
  matches `in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE']`. Converting
  that line forces the regex to be rewritten, which means loosening a security guard to
  accommodate a cosmetic change. The guard is worth more than the consistency.
- Keeping the whole prologue in **one** idiom keeps it greppable. A half-converted
  prologue is harder to audit than a fully raw one.

`$_SERVER['HTTP_X_CSRF_TOKEN']` has no `PhpRequest` accessor anyway, and `src/` is frozen.

**DRY: `admin_user_id()` had seven copies.** `includes/admin/helpers.php` already defines

```php
function admin_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}
```

and `config_files.php` (×2), `m2m.php` (×2), `rag.php` (×2), `schema.php` and
`api.php`'s `auto_cfg_write()` each inlined that exact expression instead of calling it.
All eight now call the helper; `helpers.php` holds the only copy, and `AdminHelpersTest`
already covers it including the absent-key → `null` case. The nullable return matters for
the same reason as everywhere else: it feeds `config_save(..., $userId)` as the authorship
column, where `null` is "unknown" and `0` would be a real id.

**`queryAll()` again for `(int)` casts.** `(int) ($_GET['page'] ?? 1)` on `?page[]=2` casts
the *array* — PHP makes that `1`. `query()` would produce `(int) "Array"` = `0`. The three
`(int)`-cast reads (`clickstats` page, `cron` limit, `users` user_id) therefore use
`queryAll()`. Reads that already cast with `(string)` are safe with `query()`, because the
cast is what `query()` does anyway.

**Baseline: admin had zero coverage before this.** `scenarios.php` did not touch
`public/admin/api.php` or any module, so the first "identical" run proved nothing at all
about this stage. 38 admin scenarios were added (now 212 × 3 = 636 recordings), including
the two that matter most: `adm.save_rejects_get` → **405** proves the `$postActions` gate
still fires, and `adm.save_no_csrf` → **403** proves the CSRF gate still fires.

Honest limits on the new scenarios — they execute the reads but do not all discriminate:

- `adm.clickstats_log*` all return the same 107 bytes: the clickstats flag is off in this
  database and the table is empty, so `element`, `user` and `page` filter nothing.
- `adm.cron_log_array_limit` cannot separate the two implementations: both `(int) [5]` and
  `(int) "Array"` end up clamped to `1` by `max(1, …)`.
- `adm.config_get*` return an empty 200 body — pre-existing, and the same on both sides.
- Genuinely discriminating: `automations_runs` 6928 vs `automations_runs_rule` 43,
  `cron_log` 1388 vs its array form 382, `etl_target_tables`, `etl_flow_log`.

### FE pages read the query string through `os_request()` (2026-08-16)

The FE pages stay procedural — they are 36–106 lines of labels and were deliberately left
alone when the controllers were built. Only their query-string reads moved:
`index.php`, `views.php`, `print.php`, `board.php`, `file_download.php` and
`templates/menu.php` now take `os_request()->queryAll()` into a local
`$queryParameters` and read it from there.

**Why `queryAll()` and not `query()`.** `query()` casts to string, and every one of these
sites does `substr($_GET[$key] ?? '', 0, 64)` or `trim(...)`. On an array-valued parameter
(`?table[]=contacts`) the raw read hands `substr()` an array, which is a **`TypeError` →
500**; `query()` would hand it the string `"Array"` and the page would render. Converting
to `query()` therefore *silently removes a 500* on six pages. The baseline caught exactly
that on `page.index_array_table` (HEAD 500, converted 200) and the conversion was redone
with `queryAll()`. This is the rule in action: a refactor preserves odd behaviour and
reports it, because otherwise a regression and a "fix" are indistinguishable.

That 500 was then fixed **as its own commit** — see `os_query_string()` below.

**The variable name is load-bearing.** `RequestScopeInventoryTest` matches
`$holder['key']` against a fixed `HOLDERS` list, so a read parked in a differently named
local is invisible to it. The first pass used `$queryParameters` and
`public/index.php → _GET.table` — a **gated** read — simply vanished from the scan: the
test reported ten stale entries but only nine new ones, so the count is the only thing
that gave it away. `queryParameters` was added to `HOLDERS` in the same change. **Check
the two lists balance**; a stale entry with no replacement means a read went dark, not
that a read was removed.

**Pre-existing bug, found by this stage and fixed in the commit after it.**
`public/index.php` used to do

```php
$requestedTable = is_string($queryParameters['table'] ?? '') ? (string) $queryParameters['table'] : '';
```

When `table` is absent the condition is **true** — `''` is a string — so the true branch
read the key again *without* `??` and PHP emitted `Undefined array key "table"`. Harmless
to control flow (`$requestedTable` still ended up `''`) but it rendered into the page
whenever `display_errors` is on, disclosing the absolute filesystem path of `index.php`.
Note that `os_page_bootstrap()` does **not** set `display_errors = 0` — only
`os_api_bootstrap()` does — so FE pages inherit it from `php.ini`, which is why the admin
Health panel checks for it. It is now:

```php
$rawTable = $queryParameters['table'] ?? '';
$requestedTable = is_string($rawTable) ? $rawTable : '';
```

one read instead of two, and no redundant cast. All three outcomes are unchanged: absent
and array-valued both yield `''`, a string passes through. It was the only occurrence of
the pattern in the repository.

The fix was recorded against its own baseline (HEAD `18b82ff`), and the blast radius is
worth keeping as the template for a behaviour-changing commit: **exactly 4 of 636
scenarios differ**, all of them `page.index*` cases with no `table` parameter, and each
diff is *byte-for-byte* the removed `<br />` + warning block and nothing else.
`page.index_real_table`, `page.index_unknown_table` and — the one that mattered —
`page.index_array_table` are all unchanged, so the array branch that still 500s in
`menu.php` was not disturbed.

**Deliberately skipped: `public/setup_api.php`.** Its single `$_GET['action']` read stays
raw. The setup wizard does not include `bootstrap.php` — it runs before the app is
configured, and `os_request()` lives in that file. Pulling the bootstrap into the wizard
to save one read is the class of change that has broken fresh installs before. Same
reasoning as `fk.php` in the API stage.

**Not converted:** `$_SERVER['PHP_SELF']` (menu) and `$_SERVER['HTTP_USER_AGENT']`
(login, setup) — `PhpRequest` has no accessor and `src/` is frozen. Session writes in
`login.php`, `logout.php` and `setup_api.php` establish or destroy the session
(`$_SESSION = []`, the post-authentication key set); they are the auth path, `SessionInterface`
has no `clear()`, and routing them through it buys nothing.

### Session reads go through `SessionInterface` (2026-08-16)

The 37 `$_SESSION` reads left in the API controllers now go through
`$context->session()`. `session()` builds a `PhpSession` and opens **no** database
connection, so it is safe to resolve in a constructor — including `clickstats` and
`schema`, which boot with `connect => false`.

Controllers that unpack their dependencies hold `private readonly SessionInterface
$session`; the four that keep `$this->context` (`print`, `views`, `rag`, `clickstats`)
call `$this->context->session()` at the point of use, matching how they already reach
`connection()` and `request()`.

**`userId()` is not a drop-in for every `user_id` read.** It is
`(int)($_SESSION['user_id'] ?? 0)`, so it returns **0** for a missing key. Three call
sites — `FilesController::saveConfig()`, `PrintController::saveTemplates()`,
`ViewsController::saveConfig()` — used `isset(...) ? (int) ... : null` and pass the result
to `config_save(..., $userId)` as the *authorship* column, where `null` means "unknown"
and `0` would be a real user id that does not exist. Those keep the distinction as
`$session->has('user_id') ? $session->userId() : null`. Same for `role()`: it defaults to
`viewer`, so the two sites that defaulted to `editor` and `''` use
`get('role', 'editor')` / `get('role', '')` instead.

`tests/Http/PhpSessionTest.php` pins exactly that: `has()`-guarded and raw `isset()` forms
agree on every input, and an absent `user_id` is `null` in the nullable form while
`userId()` is `0`. **The behaviour baseline cannot prove this** — see below — so the test
is the only guard on it.

**Baseline coverage limits for this change, do not overclaim:**

- The three nullable-`userId` sites are **not exercised**. Every `save`/`save_config`
  scenario fails validation with 400 before reaching `config_save`, and a successful
  config save is a mutation the harness excludes by design.
- `FilesController`'s owner-restriction parameter (`$params[] = $session->userId()`) is
  **not exercised**: no table in this database sets `owner_restricted`, so the branch is
  skipped. The `role !== 'admin'` test above it does run for both roles, but with an empty
  result either way — so it executes without its effect being observable.
- Covered and executing a real query: `comments.mine`, `owners.mine`, `files.list`.

### API endpoints move to `includes/Controller/Api/` (from 2026-08-16)

`public/api/*.php` is being converted one endpoint per commit. The shape is the page
controllers' shape with one difference: an API controller takes **`AppContext` and
`ApiRequest`** (two arguments), because the action envelope is not part of the object
graph.

```php
os_api_bootstrap(['csrf' => 'manual']);

$controller = new CommentsController(os_boot_app(), os_api_action());
$controller->handle();
```

`os_api_bootstrap()` keeps doing what it did — session, headers, role gates, request-scope
gating — and still runs **before** the controller exists. `handle()` only owns the
dispatch map, which is the same `os_api_dispatch()` call the file used to make at top
level; the former `foo_action_*()` functions became private methods.

`$conn` comes from `$context->connection()` rather than `os_api_bootstrap()`'s return
value. That is the same connection: `pg_connect()` hands back the existing one for an
identical connection string, verified by comparing `pg_get_pid()` across two
`db_connect()` calls, not assumed from the manual.

**What deliberately does not change in this step**: `$_GET` and `$_SESSION` reads stay
exactly as they were. Converting them to `$this->request` changes the *shape* the
inventory keys encode (`_GET.related_table` → `query().related_table`) and is a separate
pass, so that the move commit stays a move.

Done so far (all 2026-08-16): `CommentsController`, `NotesController`, `OwnersController`,
`FilesController` — Etap 1, the four endpoints that already had an action map — then
`NotificationsController`, `SchemaController`, `RagController`, `ViewsController`,
`DataCleanupController`, `MassEditController`, `PrintController` and
`ClickstatsController`. Each inventory key
moved from `public/api/<name>.php` to the controller path in the same commit, which the
now-recursive scan picks up automatically — under the old flat glob the new subdirectory
would have vanished from the guard.

`NotesController` also absorbed what the endpoint kept at file scope: the `const`
declarations `NOTE_BODY_MAX_LEN` / `NOTE_RECORD_PICKER_LIMIT` became class constants, and
the three `validatedRelation()` / `validatedReminderDate()` / `validatedBody()` helpers
became private methods. All five were global symbols with exactly one consumer, confirmed
by grepping the whole tree before moving them; the interpolated limit in
"Note exceeds maximum length of 4000 characters." is unchanged because the value is.

`OwnersController` keeps the **ownership transfer open to editors** exactly as it was:
`set` and `mass_set` call `requireWrite()` and validate the target user, and nothing
checks who currently owns the record. That is the documented decision above, not an
oversight the move was an opportunity to correct.

`FilesController` is the one where the move needed real care, and where two things had to
change on purpose:

- **`__DIR__` moved three levels, not two.** The upload target was
  `__DIR__ . '/../../' . $config['storage_path']` from `public/api/`, which is the repo
  root; from `includes/Controller/Api/` the same directory is `'/../../../'`. Both were
  resolved with `realpath()` and compared before the change was accepted — a silently
  wrong upload directory is the kind of bug no test in this repo would have caught.
- **Two SQL strings were split** across lines for the 120-character limit. The literal
  comparison flagged both (correctly — the literals *did* change), so the concatenated
  results were rebuilt in PHP and asserted identical to the originals rather than eyeballed.

The 413 "File is too large" guard for oversized multipart bodies moved from file scope into
the first statement of `handle()`. `os_api_action()` now runs before it instead of after;
for an oversized upload PHP has already discarded `$_POST`/`$_FILES`, so the envelope comes
back with an empty action and no side effect, and the 413 is still what the client sees.

### Not every endpoint can adopt `os_api_dispatch()` — the wire format decides

The plan for the nine `if`-chain endpoints was "normalise to an action map first, then move
the code". `notifications.php` (the first of them, 2026-08-16) shows why that step is not
automatic: it answers in a **different envelope** from the shared helpers.

| | `notifications.php` | `jsonSuccess()` / `os_api_dispatch()` |
| --- | --- | --- |
| success | `{"status":"success","count":3}` | `{"count":3,"success":true}` |
| unknown action | 400 `{"status":"error","message":"Invalid action"}` | 400 `{"success":false,"error":"Unknown action: x"}` |
| failure | 500 `{"status":"error","message":"Internal server error"}` | same status, different keys |

Adopting the shared dispatcher would therefore change three response bodies. The wire
format is byte-preserved instead: `NotificationsController` keeps its own `if`-chain and its
own `try`/`catch`, and the endpoint's `ResponseException::encoded()` / `::sent()` calls are
carried over unchanged. `public/assets/js/notifications.js` happens to read only `data.count`
and `data.notifications` and would have survived the change — that is luck, not licence.

**Rule for the rest of Etap 2**: check the response envelope before normalising. Converting
an endpoint to `os_api_dispatch()` is a wire-format change and needs to be its own decision,
not a side effect of moving code into a class.

### `ClickstatsController` — the three unusual things, all preserved

Moved last and alone, because it is the endpoint with the most non-standard wiring, and all
three pieces had to survive intact:

- **CSRF from the body**, not a header — `navigator.sendBeacon` cannot set headers, so the
  entry point boots with `csrf => 'manual'` and the controller calls
  `os_require_csrf('body', $payload)` itself, *after* decoding the payload and *before*
  `require_not_demo()`.
- **`gate => false`** at boot, with the reason recorded in the scope inventory. The stored
  table label is still checked with `user_can_access('tables', …)`, and a name outside the
  scope becomes `NULL` rather than an error — statistics must never fail a request.
- **The flag re-check**: `clickstats_settings()['enabled']` is consulted on every request and
  answers 204 without writing when it is off, which is what makes the "off means absent"
  guarantee hold for a page that was already open when the flag was switched.

Moving the six file-scope `const` declarations into the class also closed a **latent name
collision**: `includes/admin/clickstats.php` declares its own `CLICKSTATS_MAX_PAGE`, with the
value 10000 and a completely different meaning (a pagination page number, not a label
length). The two never met, because one is the public collector and the other an admin
module, but a future include that pulled both into one request would have redefined a
constant. They are now `self::MAX_PAGE` and the admin file's constant is untouched.

**Baseline coverage limit**: the flag is off in this database, so every valid POST stops at
the flag check with 204 and the INSERT path — budget window, per-event table access check,
multi-row insert — is not exercised by the matrix. What *is* proven identical is the whole
gate sequence (405 on GET and PUT, 403 without a body token, 401 for guests, 204 with the
flag off). Turning the flag on to record the write path is a config change, so it was not
done unasked.

### `public/api/fk.php` stays procedural — it is a shim, not an endpoint

`fk.php` has no actions and no handler. Its entire body is bootstrap-level work: gate the
source table, narrow the label projection into `OS_FK_LABEL_COLUMNS`, set
`OS_TABLE_ACCESS_DELEGATED`, **rewrite `$_GET['api']` and `$_GET['table']`**, then
`require public/api.php` re-entrantly and stop.

Wrapping that in a controller would produce a class whose one method mutates superglobals
and includes another entry point — ceremony that makes the delegation harder to see, not
easier, and it would move two `define()` calls that the required file depends on further
from the `require` that consumes them. It is skipped deliberately, not overlooked.

The honest precondition for converting it is a way to call the gateway's `list` route
*directly* — `FrontApiController` reading its route from an argument instead of `$_GET` —
at which point the `$_GET` rewriting disappears and the shim becomes a real caller. That is
a change to the gateway, not to `fk.php`, and it is not part of this stage.

This controller also takes **`AppContext` only**. It reads its action from the query string
with a default of `get_count`, and `mark_read` decodes `php://input` itself, so `ApiRequest`
would be an argument the class never consults.

Two guard tests pinned `public/api/files.php` by path and had to move with it:
`AccessScopeEndpointGuardTest::testFileWriteGateCoversEveryAttachment` /
`testFileListingIsFilteredByRecordOwnership` (now `self::FILES_CONTROLLER`), and the
`post().related_table` self-check in `RequestScopeInventoryTest`. The latter went **red**
first, which is the behaviour that rule is meant to produce.

**Proving a move is a move**: for each of the four controllers, the string literals were
compared token-by-token against the pre-move file (`token_get_all`, `T_CONSTANT_ENCAPSED_STRING`
and `T_ENCAPSED_AND_WHITESPACE`, sorted). The only differences are the ones the entry point
took over — `'/../../includes/bootstrap.php'`, `'csrf'`, `'manual'` — plus
`'/../../includes/config_store.php'` becoming `'/../../config_store.php'` for the new
directory depth. Every SQL string, error message and log tag is byte-identical, which is
what makes reformatted concatenations (needed for the 120-character limit) safe to claim
as cosmetic. One local variable was renamed against that rule, deliberately:
`$res2` → `$insertResult`, because the naming convention is binding for code being written
and a two-line rename with no literal in it cannot change behaviour.

**A known blind spot in the scanner, visible here**: `validatedRelation(array $source)`
reads `$source['related_table']` from the POST body, and `$source` is not in the scanner's
`HOLDERS` list, so that read has never appeared in the inventory — before or after the
move. It *is* gated (the helper ends in `validatedTable()`), and the behaviour is
unchanged, but the guard's coverage claim stops at the parameter name. Renaming a body
array on the way into a helper hides the read; keep body parameters named `$body` in new
code.

### The seam is `includes/Controller/`, not `src/`

The namespace is `App\Controller`, but the files live under `includes/Controller/`,
because `src/` is frozen — see "Feature modules added under `includes/`". The prefix
is registered twice, the same way `App\Service\` is: in the prefix map at the top of
`includes/autoload.php`, and in `composer.json` psr-4. The autoloader tries the
prefixed directories first and only then falls back to `App\` ⇒ `src/`, so a
controller resolves without touching the frozen tree.

### Constructor takes collaborators, `handle()` takes the request

Every dependency arrives through the constructor — session, request, CSRF manager,
schema repository, field registry, mapper, record repository, file repository, audit
logger, FK loader, and the `ServiceContainer`. The container is unpacked once in the
constructor body into the five services the page actually uses:

```php
$this->ownership   = $services->ownership();
$this->snapshots   = $services->snapshots();
$this->m2m         = $services->m2m();
$this->images      = $services->images();
$this->automations = $services->automations();
```

`handle(PhpRequest $request, array $pageMeta)` receives the request and whatever
`os_page_bootstrap()` returned — the CSP nonce is read from `$pageMeta`, never
regenerated. The private helpers below it (`save()`, `formFields()`,
`subtablePanels()`, `imagesPanel()`, `filesPanel()`, `tabs()`) are view-data
builders; the request handling itself stays in `handle()` so the order of the gates
is readable in one place.

Thrown exceptions were left exactly as they were: the handler registered by
`os_page_bootstrap()` already turns `RedirectException`, `ForbiddenException`,
`NotFoundException` and `BadRequestException` into responses. Only the two local
catches travelled with the code — `App\Form\ValidationException` and `RuntimeException`
inside the POST handler, which return a message string for the form to render
instead of aborting the page.

### Templates are included from method scope, and that is safe here

`include __DIR__ . '/../../templates/edit.php'` now runs inside a method, so the
template sees the method's locals rather than globals. That works because of the
view/template split: every template opens by defaulting its own inputs
(`$formFields ??= []`), and `layout.php`, `header.php` and `footer.php` read only
superglobals and their own locals. A template that reached for an ambient global
would have broken here — if you add one, it must take its data through a declared
variable, exactly as the existing ones do.

### The trap: the security guards pin *file paths*

Moving request handling out of `public/*.php` also moves it out of the globs the
source-scanning tests use — and those tests then go **green while checking nothing**,
which is the worst possible failure mode for an access-scope guard. Four places had
to move in the same change:

- `RequestScopeInventoryTest::scannedFiles()` — added the `includes/Controller/*.php`
  glob.
- `tests/Security/request_scope_inventory.php` — the `public/edit.php`,
  `public/create.php` and `public/api.php` keys became the controller paths. The
  decision and reason carried over verbatim; only the file changed.
- `FrontApiGuardsTest::API_PHP` — its structural assertions (one write gate,
  gate after `safe_table()` and before dispatch, profile actions before the role
  gates, role gates before the schema load, route-table parsing) now read the
  controller.
- `AccessScopeEndpointGuardTest::testSchemaEndpointIsFilteredByTableAccess`.

`phpstan-baseline.neon` moves with the code for the same reason: the two
`RECORD_SNAPSHOTS_ENABLED` entries were re-pointed at the controllers. The count is
unchanged — that is a relocation, not a new entry, and the ratchet still holds.

**The glob itself was the residual hole** (closed 2026-08-16). `includes/Controller/*.php`
does not match `includes/Controller/Api/*.php`, so the *next* file to move one directory
deeper would have repeated the failure — silently, because every existing inventory entry
keeps passing. `scannedFiles()` now walks `public/`, `includes/` and `templates/`
recursively (`SCANNED_ROOTS`), which also pulled 33 previously unscanned files into the
guard for the first time: `includes/Service/`, `includes/Exception/`,
`templates/partials/`, `public/admin/templates/` and `public/admin/demo/`. None of them
carried an unpinned read, so the widening cost nothing and the inventory is unchanged.

`testScanDescendsIntoSubdirectories()` pins the recursion from both directions: something
below each root must be scanned, and the deepest scanned path must be at least three
levels down. Restoring the old glob list turns it red, together with the five nested
representatives added to `testEveryRequestReachableDirectoryIsScanned()` — that was
verified by reintroducing the old implementation, not assumed.

### The behaviour baseline: `scripts/baseline/`

A refactor of this size needs a way to say *nothing changed* that is stronger than
reading the diff. `scripts/baseline/` records what the running application answers and
compares two recordings:

```bash
sh scripts/baseline/export-head.sh /some/scratch/head   # optional second arg: a revision
# serve the export on 8081 and the working copy on 8080, then:
php scripts/baseline/record.php --base=http://127.0.0.1:8081 --out=before.json --seed=1
php scripts/baseline/record.php --base=http://127.0.0.1:8080 --out=after.json
php scripts/baseline/compare.php before.json after.json
```

`scenarios.php` holds the matrix — 216 cases × 3 roles (guest, editor, admin) = 648
recordings covering every `public/api/*.php` endpoint, the `api.php` gateway routes and
every page, each with its unknown-action, missing-parameter and unknown-table variants,
plus happy paths against a real seeded table (`contacts`, id 1).
`compare.php` exits non-zero on any difference in status, content type, `Location` or
normalised body.

Write scenarios cover the **guards**, not the mutations: no-CSRF (403), unknown table
(400), empty body (400), bad record id (400), missing row (404). A successful insert or
delete would make the two recordings differ by construction (new row id), so mutating
happy paths stay Cypress's job. Two traps here, both found by recording and reading the
output rather than trusting it:

- The token has to go in the **request body**, not only the `X-CSRF-Token` header, or
  every write scenario against a `csrf => 'manual'` endpoint records a 403 that proves
  nothing — the exact "green but testing nothing" failure this file warns about elsewhere.
- The admin role is redirected away from `files.php`, so the token has to be read from
  `admin/index.php` for that role. `record.php` now **aborts with exit 5** when a
  logged-in role yields no token, rather than recording a wall of meaningless 403s.

**The export needs three untracked files, not one** — `export-head.sh` exists because
getting this wrong produces a difference that looks exactly like a regression.
`config/database.json` is the obvious one; `includes/.secret_key` and
`includes/.secret_salt` are not, and they are gitignored, so `git archive` never carries
them. Without them `config.php` derives a *different* `APP_ENCRYPTION_KEY` for the export,
and every value that goes through `secret_decrypt()` comes back as garbage. Found
2026-08-16 on the `rag` move: the export answered 500 "The assistant failed to answer"
where the working copy answered 200, reproducibly, across four runs — and the cause was
`Ollama error: Unauthorized` in the export's log, i.e. a mis-decrypted API key, not the
refactor. Anything reading `ollama_api_key_enc`, SMTP credentials or ETL passwords is
affected the same way.

**The literal comparison counts values, not occurrences.** `array_diff()` over the sorted
literal lists reports a string that disappeared entirely; it cannot see that four copies of
`'No rows selected'` became one. So it validates *edits* to strings, not *de-duplication* of
them — when a move also collapses repeated blocks, the baseline is what carries the proof.

**A 200 is not proof the interesting code ran.** `data_cleanup.preview_real` was written
against `contacts.name` — a column that does not exist there — so it returned 400 "Invalid
column" and the whole regex/`regexp_replace`/owner-restriction path stayed untouched while
the matrix still reported "identical". Pointing it at a real column (`contacts.last_name`,
plus a whole-word case on `companies.name`) turned it into 116 matched rows with the
rewritten values in the response. Read the recorded *bodies* of the cases you added, not
only the diff verdict.

**Scenarios must not depend on an external service.** The same investigation removed
`rag.query_unknown_table` from the matrix: `require_table_access()` does not reject an
*unconfigured* table for an unrestricted user, so the request ran all the way into a real
Ollama call. That made the case slow, dependent on a model being up, and non-deterministic
in its answer text — while proving nothing about the gate it was named after. The remaining
`rag` cases stop at validation (400) and never leave the process.

Two normalisations are not optional and were both found the hard way: the export carries
LF while the working copy carries CRLF (`core.autocrlf`), and absolute paths leak into
PHP warnings from two different directories. Both are folded out in `record.php` and the
`volatile` list, along with per-session CSRF tokens in their four spellings.

The harness was proven to *detect*, not just to agree: changing one `jsonError()` status
from 400 to 404 in the recorded revision surfaced as 8 differing scenarios and exit 1.
`scripts/` is already excluded from the release ZIP and the Docker image, so none of this
ships.

### One behaviour did change

In the old `edit.php`, the subtable loop reused `$row` as its inner loop variable and
clobbered the record fetched 60 lines above it. The ID strip below therefore printed
the id of the **last subtable row** whenever the table had a subtable — `deals/35`
rendered `128`. Splitting the loop into `subtablePanels()` gives it its own scope and
the strip now prints the record id. Everything else is byte-identical.

### Deliberately left alone

- `index.php`, `dashboard.php`, `board.php`, `calendar.php`, `views.php`, `print.php`
  and `files.php` are already thin: 36–106 lines of labels, `$headerControls` and a
  template include, with no request handling and no database access. A controller
  class would add a file and explain nothing.
- `login.php`, `setup.php`, `setup_api.php`, `cypress_seed.php` and
  `file_download.php` run before or outside `os_boot_app()` — no container, and in
  the setup case no configured database — so they do not fit this shape.
- `FrontApiController` still calls `db_connect()` itself rather than taking a
  connection, and it is the one controller that does **not** take `AppContext`.
  `public/api.php` boots with `['connect' => false]` on purpose: the admin and
  viewer blocks must answer **before** a connection is opened, and `AppContext`
  connects in its constructor, so injecting it would connect first and quietly undo
  that ordering. Its two arguments (session, request) are inside the limit anyway;
  only the duplicated `handle($request)` parameter was dropped, the same way it was
  in the page controllers.

### Verification

405 tests / 1006 assertions green, PHPStan clean, phpcs clean. Behaviour was compared
against `git show HEAD` copies of all three files served side by side on
`php -S`, logged in as the seeded editor:

- `edit.php` and `create.php`, with and without subtables, images, many-to-many and
  a prefilled foreign key: identical apart from the ID strip above.
- POST: `stay` ⇒ `…&saved=1`, `exit` ⇒ `index.php?table=…`, create ⇒
  `edit.php?…#tab-files`, a bad CSRF token ⇒ 403, an invalid value ⇒ the same
  re-rendered form and message. All identical to the baseline.
- Ten `api.php` GET cases (every read route, the schema route, the i18n bundle and
  the empty-body fall-through) plus five `api/fk.php` delegation cases — including
  the re-entrant `require` described under "Two couplings to know about before
  touching this" — byte-for-byte identical; the write routes were exercised against
  real rows and the rows removed again.

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
