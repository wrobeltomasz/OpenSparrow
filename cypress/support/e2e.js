// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

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

/**
 * Row count straight from PostgreSQL for a configured table (bypasses the UI,
 * so it is an independent check of what actually landed in the database).
 * Yields a Number, chainable:  cy.dbCount('companies').then(n => ...)
 */
Cypress.Commands.add('dbCount', (table) => {
  return cy.request({
    method: 'POST',
    url: `${BASE}/cypress_seed.php`,
    form: true,
    body: { token: 'cypress-dev-seed', action: 'count', table },
    failOnStatusCode: true,
  }).then(({ body }) => {
    expect(body.status, `count(${table}) status`).to.eq('ok');
    const count = body.results.count;
    // cy.log → browser Command Log, cy.task('log') → terminal during cypress run
    cy.log(`**dbCount(${table}) = ${count}**`);
    // Yield the count explicitly: when a .then() callback queues commands, its
    // plain return value is discarded and the last command's yield wins.
    return cy.task('log', `[dbCount] ${table} = ${count}`, { log: false })
      .then(() => count);
  });
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

/**
 * Authenticate as the *second* editor account (test2 / test2).
 * Exists so the security specs can act as "another user" and probe row-level
 * ownership (IDOR). Requires cy.seedDatabase() to have run.
 */
function loginAsTestUser2() {
  cy.session('testUser2', () => {
    cy.visit(`${BASE}/login.php`);
    cy.get('[data-cy=username], input[name="username"]', { timeout: TIMEOUTS.long })
      .should('exist')
      .clear()
      .type('test2');
    cy.get('[data-cy=password], input[name="password"]')
      .clear()
      .type('test2');
    cy.get('[data-cy=loginBtn], button[type="submit"]')
      .click();

    cy.url({ timeout: TIMEOUTS.long }).should('not.include', 'login.php');
  }, {
    validate() {
      cy.request({ url: `${BASE}/dashboard.php`, followRedirect: false })
        .its('status').should('eq', 200);
    },
  });
}

// ============================================================================
// Security Helpers
// ============================================================================

/**
 * Read the CSRF token the way the app's own client does
 * (public/assets/js/util/csrf.js): window.CSRF_TOKEN first, <meta> second.
 * Visit a page of the app first — the token lives in the rendered document.
 * Yields the token string.
 */
Cypress.Commands.add('csrfToken', () => {
  return cy.window({ log: false }).then(win => {
    const fromGlobal = win.CSRF_TOKEN;
    if (fromGlobal) return fromGlobal;
    const meta = win.document.querySelector('meta[name="csrf-token"]');
    const token = meta && meta.getAttribute('content');
    expect(token, 'CSRF token present in document').to.be.a('string').and.not.be.empty;
    return token;
  });
});

// Server internals that must never reach the client in an error body.
const LEAK_PATTERN = /SQLSTATE|Fatal error|Warning: |Stack trace|pg_query|\/var\/www|[A-Za-z]:\\\\|\.php on line|\.php:\d+/;

/**
 * Assert a request was denied with one of the expected status codes AND that the
 * response body leaks no server internals.
 *
 * The exact code matters: file_download.php deliberately answers 404 (not 403)
 * for someone else's file so that existence is not disclosed, and admin/api.php
 * answers 405 (not 403) for a mutation attempted over GET. Asserting merely
 * ">= 400" would let those distinctions rot away unnoticed.
 *
 *   cy.expectDenied(res, [401, 403])
 */
Cypress.Commands.add('expectDenied', (res, codes, label = '') => {
  const prefix = label ? `${label}: ` : '';
  expect(codes, `${prefix}expected status`).to.include(res.status);
  const body = typeof res.body === 'string' ? res.body : JSON.stringify(res.body || '');
  expect(body, `${prefix}error body must not leak internals`).to.not.match(LEAK_PATTERN);
});

/**
 * cy.request() that fails soft — every security probe expects a rejection, so
 * failOnStatusCode is always off and redirects are never followed (a 302 to
 * login.php is itself the assertion in most access-control tests).
 */
Cypress.Commands.add('probe', (options) => {
  return cy.request({
    failOnStatusCode: false,
    followRedirect: false,
    ...options,
    url: options.url.startsWith('http') ? options.url : `${BASE}${options.url}`,
  });
});

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
window.loginAsTestUser2  = loginAsTestUser2;
window.loginAsAdmin      = loginAsAdmin;
window.waitForGridOrEmpty = waitForGridOrEmpty;
window.waitForActions    = waitForActions;
window.clickAddIfPresent = clickAddIfPresent;
window.waitForPagination = waitForPagination;

window.CypressHelpers = {
  BASE,
  TIMEOUTS,
  loginAsTestUser,
  loginAsTestUser2,
  loginAsAdmin,
  waitForGridOrEmpty,
  waitForActions,
  clickAddIfPresent,
  waitForPagination,
};
