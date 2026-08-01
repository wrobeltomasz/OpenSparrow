# Example Test Patterns
## Worked examples for OpenSparrow Cypress specs

Every example below is grounded in code that exists in this repository —
`cypress/support/e2e.js`, `templates/template.php`, `public/login.php` and the
23 specs in `cypress/e2e/`. Selectors used here are real; do not invent new
`data-cy` hooks in a spec without adding them to the source in the same PR
(the full inventory is in `cypress/TEST_CHECKLIST.md`).

Companion documents: `cypress/TEST_CHECKLIST.md` (the short pre-PR list) and
`docs/TESTING_GUIDELINES.md` (the full reference).

---

## Example 1: Simple user journey

**Scenario:** the user logs in and opens the Company grid.

### Bad implementation
```javascript
it('user logs in and navigates', () => {
  cy.visit('http://localhost:8080/index.php'); // magic URL
  cy.get('input').eq(0).type('test');          // which input?
  cy.get('input').eq(1).type('test');
  cy.get('button').click();                    // which button?
  cy.wait(3000);                               // arbitrary delay
  cy.get('div').contains('Company').click();
  // no assertions — what was verified?
});
```

**Problems:** positional selectors, hardcoded URL, fixed delay, no assertions,
and a login flow reimplemented instead of reusing the shared session helper.

### Good implementation
```javascript
describe('OpenSparrow – Grid navigation', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();               // shared helper, cy.session-cached
  });

  it('displays the dashboard after login', () => {
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('#menu').should('be.visible');
  });

  it('opens the Company grid from the menu', () => {
    cy.visit(`${BASE}/index.php?table=company`);
    cy.url().should('include', 'table=company');
    waitForGridOrEmpty().should('have.property', 'type', 'grid');
    cy.get('[data-cy=grid-title]').should('contain.text', 'Company');
  });
});
```

**Why it works:** `BASE`, `loginAsTestUser` and `waitForGridOrEmpty` all come
from `cypress/support/e2e.js` and are available globally — no local copies. The
session is created once and reused. Every navigation is asserted.

---

## Example 2: Role-dependent UI

**Scenario:** test the Add button, but the current role may not have one.

This is not a hypothetical. `templates/template.php` filters grid actions by
role — `add` and `data-cleanup` render only for `editor`, so the same page has a
different button set for a viewer.

### Bad implementation
```javascript
it('can add a new record', () => {
  cy.visit(`${BASE}/index.php?table=company`);
  cy.get('#addRow').click();          // fails outright for a viewer
  cy.url().should('include', 'create.php');
});
```

The failure is also uninformative: "element not found" does not distinguish a
missing permission from a broken page.

### Good implementation
```javascript
it('opens the create form when the role allows it', () => {
  cy.visit(`${BASE}/index.php?table=company`);

  cy.get('body').then($body => {
    if ($body.find('#addRow, [data-cy=add]').length === 0) {
      Cypress.log({
        name: 'addButton',
        message: 'Add button not present — role has no create permission',
      });
      return;
    }

    cy.get('#addRow, [data-cy=add]')
      .first()
      .should('be.visible')
      .and('not.be.disabled');
    cy.get('#addRow, [data-cy=add]').first().click();

    cy.url({ timeout: TIMEOUTS.long }).should('include', 'create.php');
  });
});
```

The shared `clickAddIfPresent()` already implements this — prefer it. The example
is here because the same shape applies to any optional control.

---

## Example 3: Never chain `.should()` into `.click()`

**Scenario:** click a button only once it is no longer hidden.

### Broken — and this is a real failure from `files.cy.js`
```javascript
cy.get('#clearFilters').should('not.have.attr', 'hidden').click();
```

```
CypressError: cy.click() failed because it requires a DOM element.
The subject received was: undefined
The previous command that ran was: cy.should()
```

### Correct
```javascript
cy.get('#clearFilters').should('not.have.attr', 'hidden');
cy.get('#clearFilters').click();
```

Assert on one statement, then re-query for the click. Apply it even to static
elements. Components that re-render on every state change — board and calendar
filter chips, grid rows — make the stale subject especially likely.

---

## Example 4: Waiting for a grid that may be empty

**Scenario:** a table may legitimately have no records, so the page renders an
empty state instead of a grid.

### Bad implementation
```javascript
it('company grid displays', () => {
  cy.visit(`${BASE}/index.php?table=company`);
  cy.get('#grid').should('exist');    // fails on an empty table
});
```

### Good implementation — use the shared helper
```javascript
it('shows either the grid or the empty state', () => {
  cy.visit(`${BASE}/index.php?table=company`);

  waitForGridOrEmpty().then(result => {
    if (result.type === 'grid') {
      cy.wrap(result.element).find('tr').should('have.length.greaterThan', 0);
    } else {
      cy.wrap(result.element).should('be.visible');
    }
  });
});
```

Two details of the real implementation in `cypress/support/e2e.js` matter when
writing your own wait helper:

- it enforces a **hard deadline** and throws when neither state appears, instead
  of polling until Cypress's own timeout produces an unhelpful error;
- it returns `{ type, element }` so the caller can branch without re-querying.

```javascript
if (Date.now() > deadline) {
  throw new Error(`waitForGridOrEmpty: neither grid nor empty state appeared within ${timeout}ms`);
}
return cy.wait(200, { log: false }).then(check);
```

---

## Example 5: Admin panel navigation

**Scenario:** open an admin module and assert on its content.

Two facts break admin specs written like frontend ones:

1. The admin role is **blocked from `public/api.php`** — every frontend data call
   returns 403. Admin specs may only touch `/admin/index.php` and `/admin/api.php`.
2. The sidebar sections are **collapsed by default** (except Overview), so an
   `.admin-tab` is not clickable until its `.nav-section` is expanded.

### Good implementation — the pattern from `images.cy.js`
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

describe('OpenSparrow – Images: admin schema editor', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    // The admin SPA renders lazily — wait for the first tab to have content
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
      });
    clickAdminTab('schema');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('table editor exposes an Images section', () => {
    cy.get('#workspace .column-block .block-header').first().click();
    cy.get('#workspace').contains('h3', 'Images').should('exist');
  });
});
```

Note the assertion on `#editorForm` children rather than a fixed wait: the admin
panel lazy-loads its page modules, so "the shell is present" and "the module has
rendered" are two different states.

---

## Example 6: Mobile vs desktop

**Scenario:** grid actions are buttons on desktop and a `<select>` on mobile.

```javascript
describe('Grid actions', () => {
  beforeEach(() => loginAsTestUser());

  it('shows action buttons on desktop', () => {
    cy.viewport(1920, 1080);
    cy.visit(`${BASE}/index.php?table=company`);
    cy.get('#actions').should('exist');
    cy.get('[data-cy=export], #exportCsv').should('be.visible');
  });

  it('shows the action select on mobile', () => {
    cy.viewport(375, 667);
    cy.visit(`${BASE}/index.php?table=company`);
    cy.get('#mobileActions').find('option').should('have.length.greaterThan', 0);
  });
});
```

`waitForActions()` in the support file already tolerates both layouts and is the
better choice when the spec does not care which one it got.

---

## Example 7: Error case

**Scenario:** an invalid login shows an error and does not navigate away.

### Bad implementation
```javascript
it('login error', () => {
  cy.visit(`${BASE}/index.php`);
  cy.get('[name="username"]').type('test');
  cy.get('[name="password"]').type('wrongpwd');
  cy.get('button').click();
  cy.get('.error').should('be.visible');   // which error? no timeout
});
```

### Good implementation
```javascript
it('shows an error for invalid credentials', () => {
  cy.visit(`${BASE}/login.php`);

  cy.get('[data-cy=username]').clear().type('test');
  cy.get('[data-cy=password]').clear().type('wrongpassword');
  cy.get('[data-cy=loginBtn]').click();

  cy.get('[data-cy=login-error]', { timeout: TIMEOUTS.medium })
    .should('be.visible')
    .and('contain.text', 'Invalid');

  // Still on the login page, not redirected
  cy.url().should('not.include', 'dashboard.php');
});
```

Two cautions. `[data-cy=login-error]` is the real hook — there is no generic
`[data-cy=error]`. And the substring assertion only holds because
`public/login.php` emits that message as a literal English string; prefer
asserting on the element and the unchanged URL when a message may be translated.

Repeated failures also trip the rate limiter
(`LOGIN_MAX_ATTEMPTS_PER_USERNAME`, default 5), so a spec that hammers bad
credentials will start seeing the lockout message instead.

---

## Example 8: Data-driven test

**Scenario:** assert that several menu entries are present.

### Bad implementation
```javascript
it('has Dashboard menu item', () => { /* … */ });
it('has Company menu item',   () => { /* … */ });
it('has Employee menu item',  () => { /* … */ });
// …ten more identical tests
```

### Good implementation
```javascript
describe('Menu items', () => {
  const MENU_ITEMS = ['Dashboard', 'Company', 'Employee'];

  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
  });

  MENU_ITEMS.forEach(item => {
    it(`displays ${item} in the menu`, () => {
      cy.contains('.menu-text', item).should('be.visible');
    });
  });
});
```

`.menu-text` is the real class emitted by `templates/menu.php`. Keep the list
short and tied to tables the seed guarantees — menu contents come from the
`schema` configuration and differ per installation.

---

## Example 9: Seeding and cleanup

**Scenario:** a spec creates records and must not leave residue.

OpenSparrow does not clean up per test with an `afterEach` logout. Cleanup is
centralised in the seed endpoint: `cy.seedDatabase()` upserts both test users
**and** deletes rows whose first text column matches `cypress%` or `cy-%`.

```javascript
describe('Record creation', () => {
  before(() => {
    cy.seedDatabase();          // once per describe — not beforeEach
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('creates a company', () => {
    cy.visit(`${BASE}/create.php?table=company`);
    // The prefix is what makes the row collectable by the next seed run
    cy.get('input[name="name"]').type('Cypress Test Company');
    cy.get('form').submit();
    cy.url({ timeout: TIMEOUTS.long }).should('include', 'edit.php');
  });
});
```

**Rules that follow from this design:**

- name every created record with a `Cypress` / `cy-` prefix, or it becomes permanent;
- call `cy.seedDatabase()` in `before()`, not `beforeEach()` — it is a full
  cleanup pass, not per-test setup;
- the endpoint 404s unless `APP_ENV=development`; a suite failing at the very
  first hook usually means that variable, not the tests.

---

## Quick comparison: before and after

| Aspect | Before | After |
|---|---|---|
| **Setup** | Login repeated in each test | `before(seedDatabase)` + `cy.session()` helper |
| **Selectors** | `cy.get('input').eq(0)` | `cy.get('[data-cy=username], input[name="username"]')` |
| **Waits** | `cy.wait(2000)` | `waitForGridOrEmpty()` with a hard deadline |
| **Clicks** | `.should(...).click()` chained | assert, then re-query and click |
| **Assertions** | `cy.get('div').should('exist')` | `cy.get('[data-cy=login-error]').should('contain.text', '…')` |
| **Optional UI** | Test fails for the wrong role | `cy.get('body').then(...)` + `Cypress.log()` skip |
| **Admin nav** | Click a hidden `.admin-tab` | Expand `.nav-section` first |
| **Cleanup** | None, or an ad-hoc logout | `Cypress`-prefixed data + `cy.seedDatabase()` |

---

## Template: new spec file

```javascript
// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/my_feature.cy.js
// ============================================================================
// Feature: [what is under test]
// Coverage: [which scenarios]
// Requires: test / testadmin users (cy.seedDatabase()).
// ============================================================================

const BASE = 'http://localhost:8080';

// Helpers specific to this spec go here. Anything reusable across specs belongs
// in cypress/support/e2e.js instead — BASE, TIMEOUTS, loginAsTestUser,
// loginAsAdmin, waitForGridOrEmpty, waitForActions, clickAddIfPresent and
// waitForPagination are already global.

describe('OpenSparrow – My Feature', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=company`);
    cy.url().should('include', 'table=company');
  });

  it('describes the behaviour under condition X', () => {
    // Arrange — done in beforeEach
    // Act
    cy.get('[data-cy=search]').type('Cypress');
    // Assert
    cy.get('[data-cy=grid]', { timeout: TIMEOUTS.medium }).should('be.visible');
  });
});
```

---

**Last updated:** 2026-08-01 (OpenSparrow 3.1)
