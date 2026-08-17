// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

const TIMEOUTS = {
  short:  5000,
  medium: 8000,
  long:   15000,
};

Cypress.Commands.add('seedDatabase', () => {
  cy.request({
    method: 'POST',
    url: `${BASE}/cypress_seed.php`,
    form: true,
    body: { token: 'cypress-dev-seed', action: 'seed' },
    failOnStatusCode: true,
  }).its('body.status').should('eq', 'ok');
});

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

    cy.log(`**dbCount(${table}) = ${count}**`);

    return cy.task('log', `[dbCount] ${table} = ${count}`, { log: false })
      .then(() => count);
  });
});

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
    validate() {
      cy.request({ url: `${BASE}/dashboard.php`, followRedirect: false })
        .its('status').should('eq', 200);
    },
  });
}

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

    cy.url({ timeout: TIMEOUTS.long }).should('match', /\/(admin\/?(index\.php)?|dashboard\.php)/);
  }, {
    validate() {
      cy.request({ url: `${BASE}/admin/index.php`, followRedirect: false })
        .its('status').should('eq', 200);
    },
  });
}

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

const LEAK_PATTERN = /SQLSTATE|Fatal error|Warning: |Stack trace|pg_query|\/var\/www|[A-Za-z]:\\\\|\.php on line|\.php:\d+/;

Cypress.Commands.add('expectDenied', (result, codes, label = '') => {
  const prefix = label ? `${label}: ` : '';
  expect(codes, `${prefix}expected status`).to.include(result.status);
  const body = typeof result.body === 'string' ? result.body : JSON.stringify(result.body || '');
  expect(body, `${prefix}error body must not leak internals`).to.not.match(LEAK_PATTERN);
});

Cypress.Commands.add('probe', (options) => {
  return cy.request({
    failOnStatusCode: false,
    followRedirect: false,
    ...options,
    url: options.url.startsWith('http') ? options.url : `${BASE}${options.url}`,
  });
});

function waitForGridOrEmpty({ timeout = TIMEOUTS.long } = {}) {
  const gridSelect  = '#grid, [data-cy=grid], table[id*="grid"], .datagrid, .grid-wrapper';
  const emptySelect = '.no-data, .empty-state, .grid-empty, .no-results, [data-cy=empty-state]';

  return cy.document({ timeout }).then(doc => {
    const deadline = Date.now() + timeout;

    const check = () => {
      const grid  = doc.querySelector(gridSelect);
      const empty = doc.querySelector(emptySelect);

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

function clickAddIfPresent(tableParameter = null) {
  const addSelect    = '#addRow, [data-cy=add], [data-action="add"], .btn-add';
  const mobileSelect = '#mobileActions';

  return cy.get('body').then($body => {
    if ($body.find(addSelect).length > 0) {
      return cy
        .get(addSelect)
        .first()
        .should('be.visible')
        .and('not.be.disabled')
        .scrollIntoView()
        .click()
        .then(() => {
          if (tableParameter) {
            cy.url({ timeout: TIMEOUTS.long }).should('include', 'create.php');
          }
        });
    }

    if ($body.find(mobileSelect).length > 0) {
      return cy
        .get(mobileSelect)
        .select((index, element) => {
          const options  = Array.from(element.options);
          const match = options.find(option => /add/i.test(option.value) || /add/i.test(option.text));
          return match ? match.value : null;
        })
        .then(() => {
          if (tableParameter) {
            cy.url({ timeout: TIMEOUTS.long }).should('include', 'create.php');
          }
        });
    }

    Cypress.log({ name: 'clickAddIfPresent', message: 'Add button not found (read-only)' });
  });
}

function waitForPagination({ timeout = TIMEOUTS.medium } = {}) {
  const pagSelect = '#pagination, [data-cy=pagination], .pagination, [data-testid="pagination"]';

  return cy.document({ timeout }).then(doc => {
    const deadline = Date.now() + timeout;

    const check = () => {
      const pag = doc.querySelector(pagSelect);
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

function assertClearFiltersContract({ activate, reset, settle } = {}) {
  cy.get('#clearFilters').should('have.attr', 'hidden');

  activate();
  if (settle) settle();
  cy.get('#clearFilters').should('not.have.attr', 'hidden');

  cy.get('#clearFilters').click();
  if (settle) settle();

  if (reset) reset();
  cy.get('#clearFilters').should('have.attr', 'hidden');
}

function assertSidebarPresent() {
  cy.get('#menu', { timeout: TIMEOUTS.long }).should('exist');
  cy.get('.menu-list li').its('length').should('be.gte', 1);
}

function assertMobileSmoke(selectors) {
  selectors.forEach(sel => {
    cy.get(sel, { timeout: TIMEOUTS.medium }).should('exist');
  });
}

window.BASE              = BASE;
window.TIMEOUTS          = TIMEOUTS;
window.loginAsTestUser   = loginAsTestUser;
window.loginAsTestUser2  = loginAsTestUser2;
window.loginAsAdmin      = loginAsAdmin;
window.waitForGridOrEmpty = waitForGridOrEmpty;
window.waitForActions    = waitForActions;
window.clickAddIfPresent = clickAddIfPresent;
window.waitForPagination = waitForPagination;
window.assertClearFiltersContract = assertClearFiltersContract;
window.assertSidebarPresent = assertSidebarPresent;
window.assertMobileSmoke = assertMobileSmoke;

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
  assertClearFiltersContract,
  assertSidebarPresent,
  assertMobileSmoke,
};
