// cypress/support/e2e.js
// ============================================================================
// Shared Cypress helpers for OpenSparrow tests
// ============================================================================

const BASE = 'http://localhost:8080';

const TIMEOUTS = {
  short:  5000,
  medium: 8000,
  long:   15000,
};

// ============================================================================
// Database Seeding
// ============================================================================

/**
 * Upsert test users and clean cypress-created records via the seed endpoint.
 * Call once in a describe-level before() hook.
 * Creates:  test / test   (editor role)
 *           testadmin / testadmin  (admin role)
 */
Cypress.Commands.add('seedDatabase', () => {
  cy.request({
    method: 'POST',
    url: `${BASE}/cypress_seed.php`,
    form: true,
    body: { token: 'cypress-dev-seed', action: 'seed' },
    failOnStatusCode: true,
  }).its('body.status').should('eq', 'ok');
});

// ============================================================================
// Session & Authentication
// ============================================================================

/**
 * Authenticate as editor test user in a persistent cy.session.
 * Session is reused across tests in a suite (faster than re-login each time).
 */
function loginAsTestUser() {
  cy.session('testUser', () => {
    cy.visit(`${BASE}/index.php`);
    cy.get('[data-cy=username], input[name="username"]', { timeout: TIMEOUTS.long })
      .should('exist')
      .clear()
      .type('test');
    cy.get('[data-cy=password], input[name="password"]')
      .clear()
      .type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]')
      .click();

    cy.url({ timeout: TIMEOUTS.long }).should('include', '/dashboard.php');
    cy.get('#menu', { timeout: TIMEOUTS.long }).should('exist');
  }, {
    // Re-create the session if the cached cookie was invalidated (e.g. by a logout test)
    validate() {
      cy.request({ url: `${BASE}/dashboard.php`, followRedirect: false })
        .its('status').should('eq', 200);
    },
  });
}

/**
 * Authenticate as admin test user in a persistent cy.session.
 * The testadmin account must exist (call cy.seedDatabase() first).
 */
function loginAsAdmin() {
  cy.session('adminUser', () => {
    cy.visit(`${BASE}/login.php`);
    cy.get('[data-cy=username], input[name="username"]', { timeout: TIMEOUTS.long })
      .should('exist')
      .clear()
      .type('testadmin');
    cy.get('[data-cy=password], input[name="password"]')
      .clear()
      .type('testadmin');
    cy.get('[data-cy=loginBtn], button[type="submit"]')
      .click();

    // Admin is redirected to /admin/ after login (not /dashboard.php)
    cy.url({ timeout: TIMEOUTS.long }).should('match', /\/(admin\/?(index\.php)?|dashboard\.php)/);
  }, {
    // Re-create the session if the cached cookie was invalidated (e.g. by a logout test)
    validate() {
      cy.request({ url: `${BASE}/admin/index.php`, followRedirect: false })
        .its('status').should('eq', 200);
    },
  });
}

// ============================================================================
// Grid Helpers
// ============================================================================

/**
 * Wait for grid to load OR empty-state to appear, with a hard timeout.
 * Returns { type: 'grid' | 'empty' }.
 */
function waitForGridOrEmpty({ timeout = TIMEOUTS.long } = {}) {
  const gridSel  = '#grid, [data-cy=grid], table[id*="grid"], .datagrid, .grid-wrapper';
  const emptySel = '.no-data, .empty-state, .grid-empty, .no-results, [data-cy=empty-state]';

  return cy.document({ timeout }).then(doc => {
    const deadline = Date.now() + timeout;

    const check = () => {
      const grid  = doc.querySelector(gridSel);
      const empty = doc.querySelector(emptySel);

      if (grid) {
        return cy.wrap(grid).should('exist').then(() => ({ type: 'grid', element: grid }));
      }
      if (empty) {
        return cy.wrap(empty).should('exist').then(() => ({ type: 'empty', element: empty }));
      }
      if (Date.now() > deadline) {
        throw new Error(`waitForGridOrEmpty: neither grid nor empty state appeared within ${timeout}ms`);
      }

      return cy.wait(200, { log: false }).then(check);
    };

    return check();
  });
}

/**
 * Wait for action buttons to be available (Add / Export).
 */
function waitForActions({ timeout = TIMEOUTS.long } = {}) {
  return cy.get('#actions, #mobileActions', { timeout }).should('exist').then($container => {
    if ($container.is('#mobileActions')) {
      return cy.wrap($container)
        .find('option')
        .should('have.length.greaterThan', 0)
        .then(() => null);
    }

    return cy.wrap($container).within(() => {
      cy.get('[data-cy=export], #exportCsv')
        .should('exist')
        .and('be.visible');
    }).then(() => null);
  });
}

/**
 * Click the Add button if present and verify navigation to create.php.
 * Gracefully skips if the button is absent (read-only table / viewer role).
 */
function clickAddIfPresent(tableParam = null) {
  const addSel    = '#addRow, [data-cy=add], [data-action="add"], .btn-add';
  const mobileSel = '#mobileActions';

  return cy.get('body').then($body => {
    if ($body.find(addSel).length > 0) {
      return cy
        .get(addSel)
        .first()
        .should('be.visible')
        .and('not.be.disabled')
        .scrollIntoView()
        .click()
        .then(() => {
          if (tableParam) {
            cy.url({ timeout: TIMEOUTS.long }).should('include', 'create.php');
          }
        });
    }

    if ($body.find(mobileSel).length > 0) {
      return cy
        .get(mobileSel)
        .select((i, el) => {
          const opts  = Array.from(el.options);
          const match = opts.find(o => /add/i.test(o.value) || /add/i.test(o.text));
          return match ? match.value : null;
        })
        .then(() => {
          if (tableParam) {
            cy.url({ timeout: TIMEOUTS.long }).should('include', 'create.php');
          }
        });
    }

    Cypress.log({ name: 'clickAddIfPresent', message: 'Add button not found (read-only)' });
  });
}

/**
 * Tolerant pagination check — verifies pagination exists when the table has
 * enough records. Returns true if found, false otherwise (both are valid).
 */
function waitForPagination({ timeout = TIMEOUTS.medium } = {}) {
  const pagSel = '#pagination, [data-cy=pagination], .pagination, [data-testid="pagination"]';

  return cy.document({ timeout }).then(doc => {
    const deadline = Date.now() + timeout;

    const check = () => {
      const pag = doc.querySelector(pagSel);
      if (pag) {
        return cy.wrap(pag).scrollIntoView().should('exist').then(() => true);
      }

      if (Date.now() > deadline) {
        Cypress.log({
          name:    'waitForPagination',
          message: `Not found after ${timeout}ms (acceptable — may be single page)`,
        });
        return false;
      }

      return cy.wait(200, { log: false }).then(check);
    };

    return check();
  });
}

// ============================================================================
// Expose helpers globally so spec files can call them without import
// ============================================================================

window.BASE              = BASE;
window.TIMEOUTS          = TIMEOUTS;
window.loginAsTestUser   = loginAsTestUser;
window.loginAsAdmin      = loginAsAdmin;
window.waitForGridOrEmpty = waitForGridOrEmpty;
window.waitForActions    = waitForActions;
window.clickAddIfPresent = clickAddIfPresent;
window.waitForPagination = waitForPagination;

window.CypressHelpers = {
  BASE,
  TIMEOUTS,
  loginAsTestUser,
  loginAsAdmin,
  waitForGridOrEmpty,
  waitForActions,
  clickAddIfPresent,
  waitForPagination,
};
