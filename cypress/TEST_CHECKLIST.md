# Cypress E2E Test Checklist
## Quick reference for writing and reviewing OpenSparrow specs

Companion to `docs/TESTING_GUIDELINES.md` (the full reference). This file is the
short version you run through while writing a spec and again before opening a PR.

---

## Before writing tests

- [ ] Understand the happy path from the user's side, not the implementation's
- [ ] Identify the assertions: what must be true *after* the action?
- [ ] Check the 23 existing specs in `cypress/e2e/` — is this already covered?
- [ ] Plan selectors: is there a `data-cy` hook? If not, a stable `id`? (see below)
- [ ] Decide which session the spec needs: `test` (editor) or `testadmin` (admin)

---

## Use the shared helpers — do not redefine them

`cypress/support/e2e.js` is loaded into every spec and exposes its helpers
globally. Redefining them in a spec is the most common review comment.

| Helper | Purpose |
|---|---|
| `cy.seedDatabase()` | Upserts both test users and cleans `cypress%` / `cy-%` rows. Call in `before()`, **not** `beforeEach()`. |
| `loginAsTestUser()` | `cy.session()` login as `test` / `test` (editor) → lands on `/dashboard.php` |
| `loginAsAdmin()` | `cy.session()` login as `testadmin` / `testadmin` (admin) → lands on `/admin/` |
| `waitForGridOrEmpty()` | Resolves to `{ type: 'grid' \| 'empty' }`; throws at the deadline |
| `waitForActions()` | Waits for `#actions` (desktop) or `#mobileActions` (mobile) |
| `clickAddIfPresent()` | Clicks Add, or logs and skips when the role has no Add button |
| `waitForPagination()` | Returns `true`/`false` — a single-page table is a valid outcome |
| `BASE`, `TIMEOUTS` | `http://localhost:8080` and `{ short: 5000, medium: 8000, long: 15000 }` |

Both access styles work: bare (`TIMEOUTS.long`) or namespaced
(`CypressHelpers.TIMEOUTS.long`). Existing specs use both; pick one per file.

- [ ] No local copy of a login / wait helper that already exists above
- [ ] `cy.seedDatabase()` is in `before()`, once per describe block

---

## Selectors

### Real `data-cy` inventory

The app ships a deliberately small set of hooks — do not invent one in a spec and
assume it exists. Anything else must be added to the source in the same PR.

| Source | Hooks |
|---|---|
| `public/login.php` | `username`, `password`, `loginBtn`, `login-box`, `login-error` |
| `templates/header.php` | `sidebar-toggle`, `user-avatar`, `admin-link`, `logout`, `notifications`, `notes`, `my-records`, `my-comments` |
| `templates/template.php` | `search`, `grid`, `grid-title`, `pagination`, `column-filter`, `add`, `export`, `data-cleanup`, `keyboard-help` |

Grid **rows** carry no `data-cy` — row-level assertions go through the grid
container and its DOM (`#grid`, `tr`, cell classes).

### Priority order

- [ ] `[data-cy=name]` — if it exists in the table above
- [ ] Stable `id` — `#grid`, `#menu`, `#workspace`, `#actions`, `#globalSearch`
- [ ] Data attributes used by the app itself — `button.admin-tab[data-file="schema"]`
- [ ] `[aria-label="…"]` or a role selector — semantic and accessible
- [ ] `input[name="…"]` — form fields
- [ ] `.css-class` — only when nothing above fits
- [ ] Avoid: `nth-child`, `>` chains, XPath, positional `.eq(n)`

The OR-fallback style used across the suite is deliberate — it survives markup
changes: `cy.get('[data-cy=username], input[name="username"]')`.

---

## Assertions

- [ ] `.should('exist')` — in the DOM
- [ ] `.should('be.visible')` — actually rendered
- [ ] `.should('be.disabled')` — state
- [ ] `.should('contain.text', '…')` — content (not `.text()`)
- [ ] `cy.url().should('include', '…')` — navigation
- [ ] Every click is followed by an assertion about what changed

---

## Avoiding flakiness

- [ ] No `cy.wait(1000)` — wait for the element or intercept the request
- [ ] No blind `.click()` — assert the element is visible first (see the split rule below)
- [ ] No assumption about ordering — assert each step completed
- [ ] No `.then()` without a return when the chain continues
- [ ] Async work has an explicit `{ timeout: TIMEOUTS.* }`

### Never chain `.should(...)` directly into `.click()`

This is a real failure, not a style preference. `files.cy.js` doing

```javascript
cy.get('#clearFilters').should('not.have.attr', 'hidden').click();
```

failed with `cy.click() failed because it requires a DOM element (subject:
undefined, previous command: cy.should())`. Split it into two statements — assert
on one, then re-query for the click:

```javascript
cy.get('#clearFilters').should('not.have.attr', 'hidden');
cy.get('#clearFilters').click();
```

Apply this whenever a click follows a `.should()` on the same element, including
static elements. Elements that re-render (board and calendar chips, grid rows)
make a stale subject especially likely.

### Async pattern

```javascript
// CORRECT: wait for the async operation to complete
cy.get('[data-cy=form]').submit();
cy.get('[data-cy=success]', { timeout: TIMEOUTS.long }).should('exist');

// WRONG: assume immediate completion — AJAX may still be pending
cy.get('[data-cy=form]').submit();
cy.get('[data-cy=success]').should('exist');
```

Debounced inputs need the same care: the grid and file search debounce by ~400 ms,
so assert on the resulting content, never on a fixed delay.

---

## Admin specs

Three facts that break admin specs written like frontend ones:

- [ ] Session is `loginAsAdmin()` — the `test` user cannot reach `/admin/`
- [ ] **The admin role is blocked from `public/api.php`** (403 on every data call).
      Admin specs may only hit `/admin/index.php` and `/admin/api.php`.
- [ ] **The sidebar is collapsible and closed by default** (except Overview), so a
      `.admin-tab` inside a `.nav-section` is not clickable until the section is
      expanded. Use the pattern from `images.cy.js`:

```javascript
/** Click a sidebar admin-tab, expanding its collapsed .nav-section first. */
function clickAdminTab(dataFile) {
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).scrollIntoView().should('be.visible').click();
}
```

---

## Role-dependent UI

Grid action buttons are filtered by role in `templates/template.php`: `add` and
`data-cleanup` render only for `editor`. A spec that assumes the Add button is
present will fail for any other role — this is why `clickAddIfPresent()` logs and
skips rather than failing.

- [ ] Optional controls are guarded with a `cy.get('body').then($body => …)` check
- [ ] The skip path logs via `Cypress.log()` so CI output explains itself

---

## Mobile / responsive

- [ ] Works at `cy.viewport(375, 667)` (mobile) — actions collapse into `#mobileActions`
- [ ] Works at `cy.viewport(1920, 1080)` (desktop) — actions are buttons in `#actions`
- [ ] Selectors account for the two layouts (see `waitForActions()`)

---

## Final review (before PR)

### Functionality
- [ ] Passes locally several runs in a row
- [ ] Fails when the feature breaks (i.e. it actually tests something)
- [ ] Leaves no residue — records it creates are prefixed `Cypress` or `cy-` so
      `cy.seedDatabase()` cleans them up
- [ ] Credentials come from the seeded test users, not new hardcoded accounts

### Code quality
- [ ] SPDX header at the top of the file (four lines, copy from any spec)
- [ ] Header comment states the feature, coverage and required users
- [ ] No duplication of shared helpers
- [ ] Comments explain *why*, not *what*
- [ ] Constants over magic values; 2-space indentation

### Coverage
- [ ] A PR that touches header search/filter UI adds or extends a spec in the same
      commit — this is the gap that historically shipped uncovered

---

## Debugging a flaky test

1. Re-run the single spec a few times:
   `npm run cy:run -- --spec "cypress/e2e/login.cy.js"`
   (PowerShell loop: `1..10 | ForEach-Object { npm run cy:run }`)
2. Check every `.get()` has an explicit `{ timeout }` where the DOM is async
3. Check that each `.click()` is followed by an assertion on the result
4. Check the selector exists in the running app (DevTools, not assumption)
5. Check for a `.should()` chained into `.click()` — split it
6. Watch it run: `npm run cy:run -- --headed`

---

## Test organization

### Good structure
```javascript
describe('OpenSparrow – Login & Logout flow', () => {
  before(() => cy.seedDatabase());

  it('logs in with valid credentials', () => {});
  it('shows an error with an invalid password', () => {});
  it('logs out successfully', () => {});
});

describe('OpenSparrow – Grid navigation', () => {
  beforeEach(() => loginAsTestUser());

  it('displays the Company grid', () => {});
  it('filters the grid by search term', () => {});
});
```

### Bad structure
```javascript
// Unrelated concerns in one suite
describe('My Tests', () => {
  it('login', () => {});
  it('grid', () => {});
  it('admin panel', () => {}); // too broad
});

// Mega-test asserting five unrelated things
it('does everything', () => { /* 50 lines */ });
```

---

## Running tests

The suite expects an instance at `http://localhost:8080` (`cypress.config.js`
`baseUrl`).

```bash
# Option A — Docker stack
docker compose up -d

# Option B — PHP built-in server. Both variables must be set BEFORE starting it:
#   APP_ENV=development   cypress_seed.php hard-404s otherwise
#   SECURE_COOKIES=false  otherwise the session cookie will not stick on HTTP
APP_ENV=development SECURE_COOKIES=false php -S localhost:8080 -t public
```

PowerShell equivalent — env vars only apply to processes started afterwards, so an
already-running server must be restarted:

```powershell
$env:APP_ENV = "development"
$env:SECURE_COOKIES = "false"
php -S localhost:8080 -t public
```

Verify the seed endpoint answers before running the suite:

```bash
curl -i -X POST http://localhost:8080/cypress_seed.php -d "token=cypress-dev-seed&action=seed"
# expect 200 + {"status":"ok",...}
```

Then:

```bash
npm run cy:open                                       # interactive
npm run cy:run                                        # headless (CI)
npm run cy:run -- --spec "cypress/e2e/login.cy.js"    # one spec
npm run cy:run -- --headed                            # watch the browser
npm run cy:run -- --browser edge                      # alternate browser
```

---

## Common assertion patterns

```javascript
// Existence
cy.get('[data-cy=grid]').should('exist');
cy.get('[data-cy=add]').should('not.exist');        // e.g. viewer role

// Visibility
cy.get('#menu').should('be.visible');

// State
cy.get('[data-cy=loginBtn]').should('be.disabled');

// Content
cy.get('[data-cy=grid-title]').should('contain.text', 'Company');
cy.get('[data-cy=login-error]').should('contain.text', 'Invalid');

// Attributes
cy.get('[data-cy=username]').should('have.value', 'test');
cy.get('[data-cy=admin-link]').should('have.attr', 'href').and('include', '/admin');

// Navigation
cy.url().should('include', '/dashboard.php');
cy.url().should('not.include', '/login.php');

// Length
cy.get('#grid tr').should('have.length.greaterThan', 1);
```

---

## Security checklist

- [ ] Only the seeded test accounts are used — no new plaintext credentials
- [ ] No hardcoded record IDs; create the data the spec needs, prefixed `Cypress`
- [ ] Nothing sensitive logged to the Cypress console
- [ ] `cypress_seed.php` is never assumed reachable in production — it 404s there
      by design, and specs must not work around that

---

## Related files

- **Full reference:** `docs/TESTING_GUIDELINES.md`
- **Worked examples:** `cypress/EXAMPLE_TEST_PATTERNS.md`
- **Shared helpers:** `cypress/support/e2e.js`
- **Cypress config:** `cypress.config.js` (baseUrl, timeouts, Chromium flags)
- **Seed endpoint:** `public/cypress_seed.php`
- **Fixtures:** `cypress/fixtures/test_upload.txt`, `cypress/fixtures/test_companies.csv`
- **Package:** `package.json` (Cypress ^13.0.0; scripts `cy:open`, `cy:run`)

---

**Last updated:** 2026-08-01 (OpenSparrow 3.1)
