# Cypress E2E Testing Guidelines
## OpenSparrow Frontend Testing Standards

**Version:** 2.7  
**Audience:** Frontend Developers  
**Focus:** All Cypress E2E test files in `cypress/e2e/`  

---

## I. Overview & Current State

### Test Stack
- **Framework:** Cypress 13.x
- **Language:** JavaScript (ES6+)
- **Target:** OpenSparrow web application (PHP/PostgreSQL)
- **Base URL:** http://localhost:8080
- **Current Test Files:**
  - `cypress/e2e/login.cy.js` — Authentication flows, grid navigation, core UI
  - `cypress/e2e/admin.cy.js` — Admin panel interactions
  - `cypress/e2e/grid.cy.js` — Grid operations, sorting, filtering, pagination
  - `cypress/e2e/crud.cy.js` — Create/edit/delete record flows
  - `cypress/e2e/data_cleanup.cy.js` — Find & Replace drawer, accent/case options
  - `cypress/e2e/keyboard_shortcuts.cy.js` — Keyboard navigation, cell selection, copy/paste

- **Shared Helpers** (`cypress/support/e2e.js`): `loginAsTestUser`, `loginAsAdmin`, `waitForGridOrEmpty`, `waitForPagination`

### Guiding Principle
**Tests as living documentation.** Every test should answer: _"What does the happy path look like?"_ and _"When does it fail?"_

---

## II. Test Organization & Structure

### 1. File Organization
```javascript
// cypress/e2e/login.cy.js
// 1. Imports and configuration (constants, timeouts)
// 2. Helper functions (reusable actions and assertions)
// 3. Test suites (describe blocks)
// 4. Individual tests (it blocks)
```

### 2. Naming Convention
**Tests must be descriptive and focused:**

✅ **Good:**
```javascript
it('logs in successfully with valid credentials', () => {
  // Test code
});

it('displays error message when password is incorrect', () => {
  // Test code
});
```

❌ **Bad:**
```javascript
it('login test', () => { /* unclear scope */ });
it('checks login', () => { /* too vague */ });
```

**Rules:**
- Start with verb: `displays`, `submits`, `validates`, `navigates`, `prevents`
- Include condition: `with valid credentials`, `on mobile viewport`, `when admin`
- One behavior per test
- Avoid duplicating describe block name

### 3. Suite Organization
```javascript
// Group related tests logically
describe('OpenSparrow – Login & Logout flow', () => {
  // Tests that share beforeEach setup
});

describe('OpenSparrow – Grid Navigation', () => {
  // Separate concerns → separate suite
});
```

---

## III. Selector Strategy (Critical for Stability)

### Selector Priority (Strongest to Weakest)
1. **`data-cy` attributes** (explicit, future-proof)
2. **`data-testid` attributes** (alternative if data-cy unavailable)
3. **Role selectors** (`[role="button"]`, `[role="table"]`)
4. **Name selectors** (`[aria-label="Save"]`)
5. **Semantic HTML** (`button`, `input[type="submit"]`)
6. **Class fallbacks** (`.btn-primary`, `.menu-item`) — only if #1-5 unavailable
7. **ID selectors** (`.js-only`) — last resort, ID rarely stable in dynamic apps

### Best Practice Example
```javascript
// ✅ GOOD: data-cy first, with smart fallbacks
cy.get('[data-cy=username], input[name="username"]', { timeout: TIMEOUTS.long })
  .should('exist')
  .clear()
  .type('test');

// ✅ GOOD: Role selector when semantic
cy.get('button[type="submit"]').click();

// ❌ AVOID: Relying on .css-class-name or nth-child
cy.get('div > div > button:nth-child(2)').click();

// ❌ AVOID: Overly specific XPath
cy.get('//*[@id="app"]/div[1]/form[2]/div[3]/button')
```

### Adding data-cy Attributes to Source Code
If a selector doesn't exist yet:
```html
<!-- In PHP templates -->
<button type="submit" data-cy="loginBtn">Log In</button>
<input type="text" name="username" data-cy="username" />
<div class="grid-wrapper" data-cy="grid" id="grid"></div>
```

### Fallback Pattern for Tests
Use OR fallback for legacy/flexible markup:
```javascript
// Handles both old and new attribute names
cy.get('[data-cy=add], #addRow, [data-action="add"], .btn-add').click();
```

---

## IV. Helper Functions

### 1. Session Management (Login Helpers)
```javascript
/**
 * Authenticates as standard user in reusable session.
 * ✅ Uses cy.session() to avoid re-login in every test
 * ✅ Reduces test runtime by ~70% for multi-test runs
 */
function loginAsTestUser() {
  cy.session('testUser', () => {
    cy.visit(`${BASE}/index.php`);
    cy.get('[data-cy=username]').type('test');
    cy.get('[data-cy=password]').type('test');
    cy.get('[data-cy=loginBtn]').click();
    cy.url({ timeout: TIMEOUTS.long }).should('include', '/dashboard.php');
  });
}
```

**Rules for login helpers:**
- Always use `cy.session()` with a unique session name
- Validate login completion with URL or DOM check
- Include session validation logic if needed (see admin.cy.js `validate()` callback)
- Never hardcode credentials; use `Cypress.env()` or config if needed
- Document expected state after login (dashboard, admin panel, etc.)

### 2. Wait Helpers (Smart Polling)
```javascript
/**
 * Waits for grid to load OR empty-state to appear.
 * ✅ Tolerates both states (flexibility)
 * ✅ Polls until timeout (doesn't fail immediately)
 * ✅ Returns result object for conditional logic
 */
function waitForGridOrEmpty({ timeout = TIMEOUTS.long } = {}) {
  const gridSel = '#grid, [data-cy=grid]';
  const emptySel = '.empty-state, [data-cy=empty-state]';

  return cy.document({ timeout }).then(doc => {
    const check = () => {
      const grid = doc.querySelector(gridSel);
      const empty = doc.querySelector(emptySel);

      if (grid) return cy.wrap(grid).should('exist').then(() => ({ type: 'grid' }));
      if (empty) return cy.wrap(empty).should('exist').then(() => ({ type: 'empty' }));

      return cy.wait(200, { log: false }).then(check);
    };
    return check();
  });
}
```

**Rules for wait helpers:**
- Return a Cypress chain (so it's awaitable in tests)
- Accept optional `{ timeout }` parameter
- Poll repeatedly with `cy.wait()` for resilience
- Suppress verbose logging with `{ log: false }`
- Return result object for tests to branch on

### 3. Action Helpers (UI Interactions)
```javascript
/**
 * Clicks Add button (exists) and optionally asserts URL change.
 * ✅ Checks existence before click (graceful skip)
 * ✅ Waits for onclick to be set by loadTable()
 */
function clickAddIfPresentAndAssert(tableParam = null) {
  const addSel = '#addRow, [data-cy=add]';

  return cy.get('body').then($body => {
    if ($body.find(addSel).length === 0) {
      Cypress.log({ message: 'Add button not present (likely read-only)' });
      return;
    }

    return cy
      .get(addSel)
      .first()
      .should('be.visible')
      .and('not.be.disabled')
      .click()
      .then(() => {
        if (tableParam) {
          cy.url().should('include', 'create.php');
        }
      });
  });
}
```

**Rules for action helpers:**
- Check existence with `.then($body => ...)` before acting
- Use `.first()` if multiple matches (common in fallback selectors)
- Assert preconditions (`be.visible`, `not.be.disabled`)
- Return Cypress chain for chaining in tests
- Optional assertions based on parameters

---

## V. Test Patterns & Assertions

### 1. Setup Pattern: beforeEach
```javascript
describe('My Feature', () => {
  beforeEach(() => {
    loginAsTestUser();  // ✅ Reuse session (fast)
    cy.visit(`${BASE}/index.php?table=company`);
    cy.url().should('include', 'table=company');  // ✅ Assert navigation worked
  });

  it('displays the grid', () => {
    // At this point, user is logged in and on the grid page
  });
});
```

### 2. Assertion Patterns

#### Existence Assertions
```javascript
// ✅ Preferred: Be explicit about what you're checking
cy.get('[data-cy=grid]').should('exist');
cy.get('[data-cy=error-message]').should('not.exist');

// ✅ Good for UI state
cy.get('[data-cy=add-button]').should('be.visible');
cy.get('[data-cy=delete-button]').should('be.disabled');
```

#### Content Assertions
```javascript
// ✅ Check text content (not exact HTML)
cy.contains('Dashboard').should('be.visible');
cy.get('[data-cy=title]').should('contain.text', 'Company Grid');

// ❌ Avoid: Exact HTML matching (fragile)
cy.get('.error').should('have.text', 'Error: Invalid input!'); // brittle
```

#### URL Assertions
```javascript
// ✅ For navigation checks
cy.url().should('include', '/dashboard.php');
cy.url().should('not.include', '/login.php');

// ✅ Not:
cy.url().should('equal', 'http://localhost:8080/dashboard.php'); // fragile to port changes
```

#### Visibility Assertions
```javascript
// ✅ Strong visibility check
cy.get('[data-cy=button]').should('be.visible');

// ✅ Account for hidden elements
cy.get('[data-cy=mobile-menu]').should('exist'); // may exist but hidden
cy.get('[data-cy=mobile-menu]').should('be.visible'); // actually rendered

// ❌ Don't assume visibility from existence
// cy.get('#hidden-div').click(); // Will fail if not visible
```

### 3. Conditional Logic (Graceful Skipping)
```javascript
it('shows Add button when not read-only', () => {
  cy.visit(`${BASE}/index.php?table=company`);

  cy.get('[data-cy=add]').then($btn => {
    if ($btn.length === 0) {
      // Read-only table, skip assertion
      Cypress.log({ message: 'Add button not present (read-only)' });
      return;
    }
    cy.wrap($btn).should('be.visible').click();
  });
});
```

---

## VI. Flakiness Prevention

### 1. Timeout Strategy
```javascript
// Define global timeouts at top of file
const TIMEOUTS = {
  short: 5000,      // Quick DOM interactions
  medium: 8000,     // AJAX/API calls
  long: 15000,      // Page loads, complex renders
};

// Use appropriate timeout per action
cy.get('[data-cy=grid]', { timeout: TIMEOUTS.long }).should('exist');
cy.get('[data-cy=username]', { timeout: TIMEOUTS.short }).clear();
```

### 2. Network Stability
```javascript
// ✅ Good: Wait for actual element to stabilize
cy.get('[data-cy=grid]', { timeout: TIMEOUTS.long })
  .should('exist')
  .and('be.visible');

// ❌ Bad: Arbitrary delay
cy.wait(2000); // 2s is a guess, may be too short or too long
```

### 3. DOM Stability (Waiting for JavaScript to Run)
```javascript
// ✅ Wait for onclick to be attached (loadTable() runs after DOM ready)
cy.get('#addRow').should($btn => {
  expect($btn[0].onclick).to.not.be.null; // Retry until onclick exists
});

// ✅ Wait for attribute (e.g., disabled state set by JS)
cy.get('[data-cy=save]').should('not.be.disabled');
```

### 4. Scroll & Visibility
```javascript
// ✅ Scroll element into view before click
cy.get('[data-cy=button]')
  .scrollIntoView()
  .should('be.visible')
  .click();

// ✅ Don't scroll off-screen
cy.get('[data-cy=modal]')
  .should('be.visible') // Already centered
  .click();
```

### 5. Race Conditions
```javascript
// ❌ Bad: Assumes elements load in order
cy.get('[data-cy=form]').submit();
cy.get('[data-cy=success-message]').should('exist');

// ✅ Good: Wait for form to actually submit
cy.get('[data-cy=form]').submit();
cy.get('[data-cy=success-message]', { timeout: TIMEOUTS.medium })
  .should('be.visible'); // Waits for AJAX to complete
```

---

## VII. Mobile & Responsive Testing

### Viewport Configuration
```javascript
it('works on mobile viewport', () => {
  cy.viewport('iphone-x'); // 375x812
  cy.visit(`${BASE}/index.php?table=company`);
  
  // Mobile-specific selector
  cy.get('[data-cy=mobile-menu], #mobileActions').should('exist');
});

it('works on tablet', () => {
  cy.viewport('ipad-2'); // 768x1024
  cy.visit(`${BASE}/index.php?table=company`);
});

it('works on desktop', () => {
  cy.viewport(1920, 1080);
  cy.visit(`${BASE}/index.php?table=company`);
});
```

### Handling Responsive Differences
```javascript
// ✅ Different selectors for mobile vs desktop
function waitForActions({ timeout = TIMEOUTS.long } = {}) {
  cy.get('body').then($body => {
    if ($body.find('#mobileActions').length > 0) {
      // Mobile: <select> element
      cy.get('#mobileActions option').should('have.length.gt', 0);
    } else {
      // Desktop: button elements
      cy.get('[data-cy=add]').should('be.visible');
    }
  });
}
```

---

## VIII. Maintainability & Code Quality

### 1. DRY Principle (Don't Repeat Yourself)
```javascript
// ❌ Bad: Login repeated in every test
describe('My tests', () => {
  it('test 1', () => {
    cy.visit('/login.php');
    cy.get('[data-cy=username]').type('test');
    // ... 20 lines of login code ...
  });

  it('test 2', () => {
    cy.visit('/login.php');
    cy.get('[data-cy=username]').type('test');
    // ... 20 lines of login code (again) ...
  });
});

// ✅ Good: Centralize login
describe('My tests', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('test 1', () => {
    // Already logged in
  });

  it('test 2', () => {
    // Already logged in
  });
});
```

### 2. Constants Over Magic Values
```javascript
// ✅ Good
const VALID_USER = 'test';
const INVALID_USER = 'baduser';

cy.get('[data-cy=username]').type(VALID_USER);
cy.get('[data-cy=password]').type('correct-password');
cy.get('[data-cy=loginBtn]').click();

// ❌ Bad: Magic strings scattered
cy.get('[data-cy=username]').type('test');
cy.get('[data-cy=password]').type('test');
cy.get('[data-cy=loginBtn]').click();
// Later in another test...
cy.get('[data-cy=username]').type('test'); // Is it the same test user?
```

### 3. Comments (Sparse, Intentional)
```javascript
// ✅ Why, not what
// Logout via URL param because session validation requires valid cookies
cy.visit(`${BASE}/admin/index.php?logout=1`);

// ❌ Obvious comments that clutter
// Type 'test' into the username field
cy.get('[data-cy=username]').type('test');
```

### 4. Helper Function Documentation
```javascript
/**
 * Authenticates as admin in persistent session.
 * 
 * ✅ Session reused across multiple tests (faster)
 * ✅ Session validation ensures auth is still valid
 * 
 * Precondition: None (auto-handles first visit)
 * Postcondition: Admin panel loaded, auth headers set
 * 
 * @returns {void} (implicit Cypress chain)
 * @example
 *   loginAsAdmin();
 *   cy.visit('/admin/index.php');
 */
function loginAsAdmin() {
  cy.session('adminUser', () => {
    // ...
  });
}
```

---

## IX. Error Handling & Debugging

### 1. Meaningful Logs
```javascript
// ✅ Descriptive log for troubleshooting
if ($btn.length === 0) {
  Cypress.log({
    name: 'clickAddButton',
    message: 'Add button not found – likely read-only permission',
  });
}

// ❌ Unclear
console.log('btn not found');
```

### 2. Screenshots on Failure
```javascript
// Cypress config (cypress.config.js) handles this automatically
// screenshotOnRunFailure: true

// Manually if needed:
cy.screenshot('error-state', { overwrite: true });
```

### 3. Debugging in Real-Time
```bash
# Open Cypress UI (interactive mode)
npm run cy:open

# Run in headless mode with verbose output
npm run cy:run -- --verbose
```

---

## X. Test Coverage Checklist

### Authentication & Session
- [ ] Login with valid credentials → dashboard
- [ ] Login with invalid password → error message
- [ ] Logout → redirect to login
- [ ] Session persists across page reloads
- [ ] Unauthorized access → redirect to login

### Navigation & UI
- [ ] Sidebar displays all menu items
- [ ] Click menu item → correct table grid loads
- [ ] Sidebar toggles on mobile
- [ ] User avatar button visible and clickable
- [ ] Admin link visible to authorized users

### Grid Operations
- [ ] Grid loads and displays data
- [ ] Empty state shown when no records
- [ ] Add button visible (when permitted)
- [ ] Export button visible
- [ ] Search filters results
- [ ] Pagination works (if applicable)

### Admin Panel (admin.cy.js)
- [ ] Admin auth flow separate from user auth
- [ ] All header tabs clickable (Schema, Dashboard, etc.)
- [ ] System dropdown opens/closes
- [ ] Config export/import buttons present
- [ ] Logout from admin → login page

### Grid Operations (grid.cy.js)
- [ ] Sort by column header click (asc/desc toggle)
- [ ] Column filter by type (text, enum, date range, boolean)
- [ ] Active filter chips shown and removable
- [ ] Row checkbox selection and mass edit bar appears
- [ ] Duplicate row action creates copy
- [ ] Inline cell edit saves on blur/Enter, cancels on Escape

### CRUD Flows (crud.cy.js)
- [ ] Create record → appears in grid
- [ ] Edit record → changes persist
- [ ] Delete record → removed from grid
- [ ] Required field validation prevents submit
- [ ] FK autocomplete resolves display value

### Keyboard Navigation (keyboard_shortcuts.cy.js)
- [ ] Arrow keys move focus between cells
- [ ] Enter/F2 enters edit mode, Escape exits
- [ ] Ctrl+C copies selection to clipboard
- [ ] Ctrl+F focuses search field
- [ ] Ctrl hold 2s shows shortcut help overlay
- [ ] Escape pressed before nav shortcuts (isCellEdit guard)

### Data Cleanup (data_cleanup.cy.js)
- [ ] Find & Replace drawer opens from toolbar
- [ ] Preview shows matched rows with before/after highlight
- [ ] Case-insensitive and accent-ignore options work
- [ ] Apply replaces all matched values
- [ ] Wait for column options to load before typing

---

## XI. Code Review Checklist

Before merging test changes, verify:

### Test Structure
- [ ] Clear, descriptive test name (verb + condition)
- [ ] Single behavior per test (not mega-test)
- [ ] No duplication (logic extracted to helpers)
- [ ] Proper beforeEach/afterEach usage

### Selectors
- [ ] Primary: `data-cy` attributes
- [ ] Fallback: semantic or class selectors
- [ ] No overly specific selectors (nth-child, ID chains)
- [ ] Handles both desktop and mobile viewports

### Assertions
- [ ] Validates actual behavior (not implementation)
- [ ] Timeouts appropriate for async operations
- [ ] Visible/exists assertions correct
- [ ] No hardcoded waits (cy.wait(ms) avoided)

### Helpers
- [ ] Documented with JSDoc comment
- [ ] Reusable across multiple tests
- [ ] Returns Cypress chain (chainable)
- [ ] Handles errors gracefully

### Flakiness
- [ ] No arbitrary delays
- [ ] Waits for actual DOM changes
- [ ] Handles async operations
- [ ] Resilient to race conditions

### Debugging
- [ ] Meaningful Cypress.log() messages
- [ ] Error messages aid troubleshooting
- [ ] Screenshots configured for failures

---

## XII. Common Pitfalls & Solutions

| Pitfall | Problem | Solution |
|---------|---------|----------|
| No `{ timeout }` on `.get()` | Default 10s may be too short for slow page | Always pass `{ timeout: TIMEOUTS.long }` for initial page load |
| Hardcoded delays `cy.wait(2000)` | Flaky: too short on slow CI, too long normally | Poll for actual element: `.should('exist')` |
| Clicking without visibility check | Fails if element off-screen or hidden | Use `.scrollIntoView().should('be.visible')` |
| Repeated login code | Test runtime bloats, maintenance burden | Use `beforeEach(loginAsTestUser)` or `cy.session()` |
| Brittle XPath selectors | Break with minor HTML changes | Use `data-cy`, semantic HTML, or role selectors |
| Testing implementation details | Tests break when refactored, not about behavior | Test user-visible outcomes (URL, text, visibility) |
| No async wait for AJAX | Test assumes data loaded, but request still pending | Add `{ timeout: TIMEOUTS.long }` to assertions after `.click()` |
| Circular dependencies in helpers | Hard to debug, slow to run | Keep helpers focused; avoid calling helpers from helpers |

---

## XIII. Running Tests Locally

### Prerequisites
```bash
# 1. Docker stack running
docker compose up -d

# 2. Test user created in database (or use /admin/Users)
# Username: test, Password: test

# 3. Node dependencies
npm install
```

### Commands
```bash
# Interactive mode (Cypress UI)
npm run cy:open

# Headless (CI)
npm run cy:run

# Run specific test file
npm run cy:run -- --spec "cypress/e2e/login.cy.js"

# Run with browser preview
npm run cy:run -- --headed

# Debug mode (pauses at first failure)
npm run cy:run -- --browser chrome --debug
```

### Test Output
```
✓ logs in successfully with valid credentials
✓ fails to log in with invalid credentials
✗ logs out successfully
  Error: timeout of 15000ms exceeded
  (Screenshot saved to cypress/screenshots/login.cy.js/logout.png)
```

---

## XIV. Best Practices Summary

1. **Selectors:** `data-cy` > semantic HTML > class > ID
2. **Timeouts:** Always explicit, appropriate for operation
3. **Assertions:** One per test, test behavior not implementation
4. **Helpers:** DRY, documented, returns Cypress chain
5. **Logging:** Meaningful messages for debugging
6. **Flakiness:** No hardcoded delays, wait for DOM
7. **Coverage:** Happy path + critical error cases
8. **Mobile:** Test both desktop and mobile viewports
9. **Readability:** Clear names, sparse comments (why, not what)
10. **Maintainability:** Modular, reusable, easy to update

---

## XV. Resources & Further Reading

- **Cypress Best Practices:** https://docs.cypress.io/guides/references/best-practices
- **Flakiness Prevention:** https://docs.cypress.io/guides/core-concepts/flaky-test-management
- **Selectors:** https://docs.cypress.io/guides/core-concepts/selecting-elements
- **WCAG Accessibility Testing:** https://www.w3.org/WAI/test-evaluate/
- **OpenSparrow CLAUDE.md:** Security patterns, SQL safety, deployment

---

**Last Updated:** 2026-05-26  
**Maintained By:** Senior Test Engineer  
**Questions/Issues:** Open discussion in PR reviews
