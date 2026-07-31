// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/login.cy.js
// ============================================================================
// Login, Logout, and Authenticated User Flow Tests
// ============================================================================

const BASE = 'http://localhost:8080';

// Helpers are imported from cypress/support/e2e.js (supportFile)
// Usage: loginAsTestUser(), waitForGridOrEmpty(), waitForActions(), etc.

// ============================================================================
// Test Suite: Authenticated User Flow
// ============================================================================

describe('OpenSparrow – Authenticated user flow', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.url().should('include', '/dashboard.php');
    cy.get('#menu', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('displays the sidebar with core menu items', () => {
    cy.get('#menu').should('be.visible');
    cy.get('.menu-list li').its('length').should('be.gte', 1);
    cy.contains('.menu-text', 'Dashboard').should('be.visible');
  });

  it('toggles the sidebar on mobile', () => {
    cy.viewport('iphone-x');
    cy.get('[data-cy=sidebar-toggle], #sidebarToggle').click();
    cy.get('#menu').should('exist');
  });

  it('displays user avatar button in header', () => {
    cy.get('[data-cy=user-avatar], #userAvatarBtn', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('displays notifications widget', () => {
    cy.get('[data-cy=notifications], .notifications-wrapper', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('exist');
  });

  it('shows admin link for admin users', () => {
    // Note: test user may or may not be admin — this test is conditional
    cy.get('body').then($body => {
      const hasAdminLink = $body.find('[data-cy=admin-link], .header-admin-link').length > 0;
      if (hasAdminLink) {
        cy.get('[data-cy=admin-link], .header-admin-link')
          .should('exist')
          .and('have.attr', 'href', '/admin/index.php');
      } else {
        Cypress.log({ message: 'Admin link not present (user not admin)' });
      }
    });
  });

  it('navigates to Company grid', () => {
    cy.visit(`${BASE}/index.php?table=companies`);
    cy.url().should('include', 'table=companies');

    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('[data-cy=grid-title], #gridTitle', { timeout: CypressHelpers.TIMEOUTS.short })
          .should('contain.text', 'Companies');
      } else {
        Cypress.log({ message: 'Empty grid state' });
      }
    });
  });

  it('shows grid action buttons when present', () => {
    cy.visit(`${BASE}/index.php?table=companies`);
    waitForGridOrEmpty();
    waitForActions();
  });

  it('displays search and filter controls on grid', () => {
    cy.visit(`${BASE}/index.php?table=companies`);

    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('[data-cy=search], #globalSearch', {
          timeout: CypressHelpers.TIMEOUTS.medium,
        })
          .should('exist')
          .and('be.visible');

        cy.get('[data-cy=column-filter], #columnFilter', {
          timeout: CypressHelpers.TIMEOUTS.medium,
        })
          .should('exist')
          .and('be.visible');
      } else {
        Cypress.log({ message: 'Empty state — skipping search/filter' });
      }
    });
  });

  it('shows pagination if table has enough records', () => {
    cy.visit(`${BASE}/index.php?table=companies`);
    waitForGridOrEmpty();
    waitForPagination().then(found => {
      if (found) {
        Cypress.log({ message: 'Pagination found' });
      } else {
        Cypress.log({ message: 'No pagination (likely single page)' });
      }
    });
  });

  it('allows Add record when editor role', () => {
    cy.visit(`${BASE}/index.php?table=companies`);

    cy.get('body').then($body => {
      const hasAddButton = $body.find('#addRow, [data-cy=add]').length > 0;

      if (!hasAddButton) {
        Cypress.log({ message: 'Add button not present (read-only)' });
        return;
      }

      // Wait for loadTable() to attach onclick before clicking
      cy.get('#addRow, [data-cy=add]')
        .first()
        .should($btn => {
          expect($btn[0].onclick, 'Add button onclick set by loadTable').to.not.be.null;
        })
        .click();

      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'create.php');
    });
  });
});

// ============================================================================
// Test Suite: Login & Logout Flow
// ============================================================================

describe('OpenSparrow – Login & Logout flow', () => {
  beforeEach(() => {
    cy.visit(`${BASE}/index.php`);
    cy.get('[data-cy=username], input[name="username"]', {
      timeout: CypressHelpers.TIMEOUTS.long,
    }).should('exist');
  });

  it('displays login page with branding', () => {
    cy.contains('OpenSparrow').should('be.visible');
    cy.get('[data-cy=login-box], .login-box').should('exist');
  });

  it('logs in successfully with valid credentials', () => {
    cy.get('[data-cy=username], input[name="username"]').clear().type('test');
    cy.get('[data-cy=password], input[name="password"]').clear().type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', '/dashboard.php');
    cy.get('#menu', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('fails to log in with invalid password', () => {
    cy.get('[data-cy=username], input[name="username"]').clear().type('test');
    cy.get('[data-cy=password], input[name="password"]').clear().type('wrongpassword');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    cy.get('[data-cy=login-error], .error', { timeout: CypressHelpers.TIMEOUTS.short })
      .should('be.visible')
      .and('contain.text', 'Invalid credentials');
    cy.url().should('not.include', '/dashboard.php');
  });

  it('shows error when submitting with empty username', () => {
    // HTML5 validation should prevent submit, but if it does, test error
    cy.get('[data-cy=username], input[name="username"]').clear();
    cy.get('[data-cy=password], input[name="password"]').clear().type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    // May show HTML5 validation error or server-side error
    cy.get('input[name="username"]:invalid, [data-cy=login-error]').should('exist');
  });

  it('logs out successfully', () => {
    // Intercept i18n bundle BEFORE login — dashboard load triggers it, which
    // then calls initUserMenu(). Without waiting, click handlers may not be
    // attached yet when we try to open the user dropdown.
    cy.intercept('GET', /action=i18n_bundle/).as('i18nReady');

    // Login first
    cy.get('[data-cy=username], input[name="username"]').clear().type('test');
    cy.get('[data-cy=password], input[name="password"]').clear().type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', '/dashboard.php');
    // Wait for i18n bundle to complete — after this, initUserMenu() has run
    cy.wait('@i18nReady', { timeout: CypressHelpers.TIMEOUTS.medium });

    cy.get('[data-cy=user-avatar], #userAvatarBtn').click();
    cy.get('#userAvatarMenu').should('have.class', 'open');
    cy.get('[data-cy=logout], #logoutBtn').click();

    // Should be redirected to login
    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'login.php');
    cy.contains('OpenSparrow').should('be.visible');
  });
});

// ============================================================================
// Test Suite: Mobile Viewport
// ============================================================================

describe('OpenSparrow – Mobile viewport', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
  });

  it('loads dashboard on mobile', () => {
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('#menu').should('exist');
  });

  it('displays mobile-friendly grid on mobile', () => {
    cy.visit(`${BASE}/index.php?table=companies`);
    cy.viewport('iphone-x');

    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('[data-cy=grid]').should('exist');
      }
    });
  });

  it('shows mobileActions select on mobile viewport', () => {
    cy.visit(`${BASE}/index.php?table=companies`);

    // mobileActions exists in DOM regardless of viewport (CSS visibility depends on media query)
    cy.get('#mobileActions').should('exist');
    cy.get('#mobileActions option').should('have.length.gt', 0);
  });

  it('mobileActions select has at least export option', () => {
    cy.visit(`${BASE}/index.php?table=companies`);

    cy.get('#mobileActions').find('option').then($opts => {
      const values = $opts.toArray().map(o => o.value.toLowerCase());
      expect(values.some(v => v === 'export')).to.be.true;
    });
  });
});
