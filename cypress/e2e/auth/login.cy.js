// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

describe('OpenSparrow – Authenticated shell', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.url().should('include', '/dashboard.php');
    cy.get('#menu', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('displays the sidebar with core menu items', () => {
    assertSidebarPresent();
    cy.get('#menu').should('be.visible');
    cy.contains('.menu-text', 'Dashboard').should('be.visible');
  });

  it('displays user avatar button in header', () => {
    cy.get('[data-cy=user-avatar], #userAvatarBtn', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('shows admin link for admin users', () => {
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
});

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
    cy.get('[data-cy=username], input[name="username"]').clear();
    cy.get('[data-cy=password], input[name="password"]').clear().type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    cy.get('input[name="username"]:invalid, [data-cy=login-error]').should('exist');
  });

  it('logs out successfully', () => {
    cy.intercept('GET', /action=i18n_bundle/).as('i18nReady');

    cy.get('[data-cy=username], input[name="username"]').clear().type('test');
    cy.get('[data-cy=password], input[name="password"]').clear().type('test');
    cy.get('[data-cy=loginBtn], button[type="submit"]').click();

    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', '/dashboard.php');

    cy.wait('@i18nReady', { timeout: CypressHelpers.TIMEOUTS.medium });

    cy.get('[data-cy=user-avatar], #userAvatarBtn').click();
    cy.get('#userAvatarMenu').should('have.class', 'open');
    cy.get('[data-cy=logout], #logoutBtn').click();

    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'login.php');
    cy.contains('OpenSparrow').should('be.visible');
  });
});
