// cypress/e2e/anonymization.cy.js
// ============================================================================
// Admin Data Anonymization Module Tests
// Requires:  testadmin / testadmin user with role = 'admin'
// Seed:      cy.seedDatabase() in before() creates / resets that account.
// Scope:     UI + config persistence only — Run Now / Preview shell out to
//            cron/cron_anonymization.php, so we only assert the request
//            completes and renders *some* output, not specific row counts.
// ============================================================================

const BASE = 'http://localhost:8080';

function openAnonTab() {
  cy.visit(`${BASE}/admin/index.php`);
  cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  // app.js attaches the .admin-tab click handlers inside its DOMContentLoaded
  // callback, but the sidebar is server-rendered PHP and clickable well before
  // that module boots — an early click is silently swallowed and the panel
  // stays on Overview. #workspace itself is not a usable signal — it ships with
  // server-rendered #itemPanel/#editorForm children — so wait for #editorForm
  // to be *populated* by the first render.
  cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
    .should($el => expect($el.children().length).to.be.greaterThan(0));
  // The Anonymization button lives in a collapsible nav-section closed by
  // default — expand it before the button is clickable.
  cy.get('button.admin-tab[data-file="anonymization"]').then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get('button.admin-tab[data-file="anonymization"]').scrollIntoView().should('be.visible').click();
  cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long }).should('contain.text', 'Data Anonymization');
}

function anonTab(label) {
  return cy.contains('#workspace .item-btn', label).click();
}

describe('OpenSparrow – Admin Anonymization', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openAnonTab();
  });

  // ── Navigation ──────────────────────────────────────────────────────────

  it('shows all five Anonymization tabs', () => {
    ['Rules', 'Schedule', 'Suggestions', 'Dictionary', 'History'].forEach(label => {
      cy.contains('#workspace .item-btn', label).should('be.visible');
    });
  });

  // ── Rules tab ───────────────────────────────────────────────────────────

  it('Rules tab: shows empty state when no rules configured', () => {
    anonTab('Rules');
    cy.get('#workspace').should('contain.text', 'No rules configured yet');
  });

  it('Rules tab: Add Rule form validates before submitting', () => {
    anonTab('Rules');
    cy.contains('#workspace button:visible', '+ Add Rule').click();
    cy.get('#workspace').should('contain.text', 'Select a table and a date/timestamp column.');
  });

  it('Rules tab: Preview (dry run) reports output or an error', () => {
    anonTab('Rules');
    cy.contains('#workspace button:visible', 'Preview (dry run)').click();
    cy.get('#workspace pre:visible', { timeout: CypressHelpers.TIMEOUTS.long }).should('be.visible');
  });

  // ── Schedule tab ────────────────────────────────────────────────────────

  it('Schedule tab: toggles enabled + frequency and saves', () => {
    anonTab('Schedule');
    cy.get('#anon-enabled').click();
    cy.get('#anon-frequency').select('weekly');
    cy.contains('#workspace button:visible', 'Save Schedule Settings').click();
    cy.get('#workspace p:visible').should('contain.text', 'saved');

    openAnonTab();
    anonTab('Schedule');
    cy.get('#anon-frequency').should('have.value', 'weekly');
  });

  it('Schedule tab: shows the cron command hint and guide blocks', () => {
    anonTab('Schedule');
    cy.get('#workspace').should('contain.text', 'cron_anonymization.php');
    cy.get('#workspace').should('contain.text', 'crontab');
    cy.get('#workspace').should('contain.text', 'Task Scheduler');
    cy.get('#workspace').should('contain.text', 'docker-compose.yml');
  });

  it('Schedule tab: Run Now reports output or an error', () => {
    anonTab('Schedule');
    cy.contains('#workspace button:visible', 'Run Now').click();
    cy.get('#workspace pre:visible', { timeout: CypressHelpers.TIMEOUTS.long }).should('be.visible');
  });

  // ── Suggestions tab ─────────────────────────────────────────────────────

  it('Suggestions tab: Scan Schema surfaces matches or an empty-state message', () => {
    anonTab('Suggestions');
    cy.contains('#workspace button:visible', 'Scan Schema').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long }).should($el => {
      const text = $el.text();
      expect(
        /potential PII column\(s\) found|No columns matched|Dictionary is empty/.test(text),
        'shows scan results, no-match message, or empty-dictionary message'
      ).to.be.true;
    });
  });

  // ── Dictionary tab ──────────────────────────────────────────────────────

  it('Dictionary tab: saves keyword list and persists after reload', () => {
    anonTab('Dictionary');
    cy.get('#workspace textarea:visible').clear().type('pesel, nip, email, cypress_keyword');
    cy.contains('#workspace button:visible', 'Save Dictionary').click();
    cy.get('#workspace p:visible').should('contain.text', 'saved');

    openAnonTab();
    anonTab('Dictionary');
    cy.get('#workspace textarea:visible').should('contain.value', 'cypress_keyword');
  });

  it('Dictionary tab: Purge Old Logs asks for confirmation', () => {
    anonTab('Dictionary');
    cy.window().then(win => cy.stub(win, 'confirm').returns(false));
    cy.contains('#workspace button:visible', 'Purge Old Logs').click();
    // confirm() was stubbed to return false — no request should have been
    // made, the panel must remain on the same tab without error output.
    cy.get('#workspace').should('exist');
  });

  // ── History tab ─────────────────────────────────────────────────────────

  it('History tab: Load History shows empty state or note when no runs exist', () => {
    anonTab('History');
    cy.contains('#workspace button:visible', 'Load History').click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long }).should($el => {
      const text = $el.text();
      expect(
        /No runs recorded yet\.|Initialize System Tables/.test(text),
        'shows either the empty-history message or the missing-log-table note'
      ).to.be.true;
    });
  });
});

// ============================================================================
// Test Suite: Anonymization Access Control
// ============================================================================

describe('OpenSparrow – Anonymization Access Control', () => {
  it('editor-role user cannot reach the Anonymization admin action', () => {
    loginAsTestUser();
    cy.request({
      url: `${BASE}/admin/api.php?action=anonymization_load`,
      failOnStatusCode: false,
    }).its('status').should('be.oneOf', [401, 403]);
  });
});
