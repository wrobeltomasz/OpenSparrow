// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

function clickAdminTab(dataFile) {
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).scrollIntoView().should('be.visible').click();
}

describe('OpenSparrow – Admin Panel', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');

    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
      });
  });

  it('displays admin header', () => {
    cy.get('header.admin-header').should('be.visible');
  });

  it('Save Config button is hidden on Overview and shown on config tabs', () => {
    cy.get('#btnSave').should('exist').and('not.be.visible');

    clickAdminTab('schema');
    cy.get('#btnSave', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('be.visible')
      .and('not.be.disabled');
  });

  it('displays Logout button', () => {
    cy.get('button.btn-header-logout').should('be.visible');
  });

  it('displays admin nav sidebar', () => {
    cy.get('nav.admin-nav, #adminNav').should('exist');
  });

  ['schema', 'dashboard', 'calendar', 'files', 'views', 'csv_import'].forEach(tab => {
    it(`navigates to data tab: ${tab}`, () => {
      clickAdminTab(tab);
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  it('Schema tab exposes Add New Table', () => {
    clickAdminTab('schema');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.contains('.item-btn', 'Add New Table').should('be.visible');
  });

  ['users', 'health', 'backup', 'migrations', 'performance', 'cron', 'demo', 'settings'].forEach(tab => {
    it(`navigates to system tab: ${tab}`, () => {
      clickAdminTab(tab);
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  it('Settings tab exposes Database and Audit & Snapshots inner tabs', () => {
    clickAdminTab('settings');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.contains('#workspace .item-btn', 'Database').should('be.visible').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.contains('#workspace .item-btn', 'Audit & Snapshots').should('be.visible').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  ['workflows', 'automations', 'rag', 'anonymization', 'etl'].forEach(tab => {
    it(`navigates to advanced tab: ${tab}`, () => {
      clickAdminTab(tab);
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  it('navigates to Docs tab', () => {
    clickAdminTab('docs');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('navigates to Overview tab', () => {
    clickAdminTab('overview');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('Save Config button is clickable and panel survives', () => {
    clickAdminTab('schema');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.get('#btnSave').should('be.visible').click();
    cy.get('#workspace').should('exist');
  });

  it('Users tab lists existing users', () => {
    clickAdminTab('users');

    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('contain.text', 'testadmin');
  });
});

describe('OpenSparrow – Admin Access Control', () => {
  it('unauthenticated user is redirected to login', () => {
    cy.clearCookies();
    cy.visit(`${BASE}/admin/index.php`, { failOnStatusCode: false });
    cy.url({ timeout: CypressHelpers.TIMEOUTS.medium }).should('include', 'login.php');
    cy.get('input[name="username"], [data-cy=username]').should('be.visible');
  });

  it('editor-role user cannot access admin panel', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/admin/index.php`, { failOnStatusCode: false });
    cy.get('body').then($body => {
      const denied = $body.text().includes('Access Denied') || $body.find('input[name="username"]').length > 0;
      expect(denied, 'editor should be denied admin access').to.be.true;
    });
  });

  it('admin logout redirects to login page', () => {
    cy.seedDatabase();
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('button.btn-header-logout').click();
    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'login.php');
  });
});

describe('OpenSparrow – Admin Panel Mobile', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsAdmin();
  });

  it('loads admin panel on mobile', () => {
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('admin nav is accessible on mobile', () => {
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('nav.admin-nav, #adminNav').should('exist');
  });
});
