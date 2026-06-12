// cypress/e2e/admin.cy.js
// ============================================================================
// Admin Panel Tests
// Requires:  testadmin / testadmin user with role = 'admin'
// Seed:      cy.seedDatabase() in before() creates / resets that account.
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Admin Panel Navigation
// ============================================================================

describe('OpenSparrow – Admin Panel', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    // Wait until the admin JS has rendered the initial tab — clicking nav
    // buttons before the listeners attach silently does nothing.
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
      });
  });

  // ── Header Elements ────────────────────────────────────────────────────────

  it('displays admin header', () => {
    cy.get('header.admin-header').should('be.visible');
  });

  it('Save Config button is hidden on Overview and shown on config tabs', () => {
    // On the Overview landing tab the Save button is hidden by design
    cy.get('#btnSave').should('exist').and('not.be.visible');
    // It appears once a config-editing tab (e.g. schema) is opened
    cy.get('button.admin-tab[data-file="schema"]').scrollIntoView().click();
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

  // ── Data Management Tabs ───────────────────────────────────────────────────

  // Note: the nav has overflow-y:auto — tabs below the fold must be scrolled
  // into view first, otherwise Cypress treats them as hidden.

  ['schema', 'dashboard', 'calendar', 'files', 'menu', 'add_table', 'erd', 'views', 'csv_import'].forEach(tab => {
    it(`navigates to data tab: ${tab}`, () => {
      cy.get(`button.admin-tab[data-file="${tab}"]`)
        .scrollIntoView()
        .should('be.visible')
        .click();
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  // ── System Tabs ────────────────────────────────────────────────────────────

  ['database', 'users', 'health', 'backup', 'audit', 'migrations', 'performance', 'cron', 'demo', 'settings'].forEach(tab => {
    it(`navigates to system tab: ${tab}`, () => {
      cy.get(`button.admin-tab[data-file="${tab}"]`)
        .scrollIntoView()
        .should('be.visible')
        .click();
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  // ── AI / Automation Tabs ───────────────────────────────────────────────────

  ['workflows', 'automations', 'rag'].forEach(tab => {
    it(`navigates to advanced tab: ${tab}`, () => {
      cy.get(`button.admin-tab[data-file="${tab}"]`)
        .scrollIntoView()
        .should('be.visible')
        .click();
      cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    });
  });

  it('navigates to Docs tab', () => {
    cy.get('button.admin-tab[data-file="docs"]').should('exist').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('navigates to Overview tab', () => {
    cy.get('button.admin-tab[data-file="overview"]').should('exist').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  // ── Config Buttons ─────────────────────────────────────────────────────────

  it('Export Config button is visible', () => {
    cy.get('#btnExport').scrollIntoView().should('be.visible');
  });

  it('clicking Export Config does not crash the panel', () => {
    cy.get('#btnExport').scrollIntoView().should('be.visible').click();
    cy.get('#workspace').should('exist');
  });

  it('Import Config button is visible', () => {
    cy.get('#btnImport').scrollIntoView().should('be.visible');
  });

  it('clicking Import Config reveals file input', () => {
    cy.get('#btnImport').scrollIntoView().should('be.visible').click();
    cy.get('#importFileInput').should('exist');
  });

  it('Save Config button is clickable and panel survives', () => {
    cy.get('button.admin-tab[data-file="schema"]').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.get('#btnSave').should('be.visible').click();
    cy.get('#workspace').should('exist');
  });

  it('Run Notifications Cron button exists', () => {
    cy.get('#btnRunCron').should('exist');
  });

  // ── Users Tab: add / list ──────────────────────────────────────────────────

  it('Users tab lists existing users', () => {
    cy.get('button.admin-tab[data-file="users"]').scrollIntoView().click();
    // The seeded testadmin account must appear in the user list
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('contain.text', 'testadmin');
  });
});

// ============================================================================
// Test Suite: Admin Access Control
// ============================================================================

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

// ============================================================================
// Test Suite: Admin Panel Mobile
// ============================================================================

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
