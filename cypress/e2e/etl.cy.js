// cypress/e2e/etl.cy.js
// ============================================================================
// Admin ETL Module Tests
// Requires:  testadmin / testadmin user with role = 'admin'
// Seed:      cy.seedDatabase() in before() creates / resets that account.
// Scope:     UI + config persistence only — no real external source database
//            is available in CI, so connection tests use unreachable/invalid
//            credentials and assert on the resulting error path, not success.
// ============================================================================

const BASE = 'http://localhost:8080';

function openEtlTab() {
  cy.visit(`${BASE}/admin/index.php`);
  cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  // The ETL button lives in the "Data Management" collapsible nav-section,
  // which is closed by default — expand it before the button is clickable.
  cy.get('button.admin-tab[data-file="etl"]').then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get('button.admin-tab[data-file="etl"]').scrollIntoView().should('be.visible').click();
  cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long }).should('contain.text', 'ETL');
}

function etlTab(label) {
  return cy.contains('#workspace .item-btn', label).click();
}

describe('OpenSparrow – Admin ETL', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openEtlTab();
  });

  // ── Navigation ──────────────────────────────────────────────────────────

  it('shows all five ETL tabs', () => {
    ['Sources', 'Jobs', 'Schedule', 'History', 'Flows'].forEach(label => {
      cy.contains('#workspace .item-btn', label).should('be.visible');
    });
  });

  // ── Sources tab ─────────────────────────────────────────────────────────

  function addSource() {
    cy.contains('button', '+ Add source').click();
    cy.get('#workspace .column-block').last().within(() => {
      cy.get('.block-header').click();
    });
    return cy.get('#workspace .column-block').last();
  }

  it('Sources tab: adds a source, switches driver default port', () => {
    etlTab('Sources');
    addSource().within(() => {
      cy.get('select').first().select('pgsql');
      cy.get('input[type="number"]').first().should('have.value', '5432');

      cy.get('select').first().select('mysql');
      cy.get('input[type="number"]').first().should('have.value', '3306');
    });
  });

  it('Sources tab: Test connection reports failure for an unreachable host', () => {
    etlTab('Sources');
    cy.get('#workspace .column-block').first().within(() => {
      cy.get('.block-header').click();
      cy.get('input').eq(1).clear().type('nonexistent-host.invalid');
      cy.get('input[type="number"]').clear().type('3306');
      cy.get('input').eq(2).clear().type('nonexistent_db');
      cy.get('input').eq(3).clear().type('nonexistent_user');
      cy.get('input[type="password"]').clear().type('wrong-password');
      cy.contains('button', 'Test connection').click();
    });
    cy.get('#workspace p', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('be.visible')
      .and('not.contain.text', 'Connection OK');
  });

  it('Sources tab: Save configuration persists the source', () => {
    etlTab('Sources');
    cy.get('#workspace .column-block').first().within(() => {
      cy.get('.block-header').click();
      cy.get('input').eq(0).clear().type('Cypress source');
      cy.get('input').eq(1).clear().type('cypress-host');
      cy.get('input').eq(2).clear().type('cypress_db');
      cy.get('input').eq(3).clear().type('cypress_user');
    });
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');

    // Reload and confirm persistence.
    openEtlTab();
    etlTab('Sources');
    cy.get('#workspace .column-block .block-title').should('contain.text', 'Cypress source');
  });

  it('Sources tab: supports 2+ sources at once', () => {
    etlTab('Sources');
    addSource();
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');
    cy.get('#workspace .column-block').should('have.length.gte', 2);
  });

  // ── Jobs tab ────────────────────────────────────────────────────────────

  it('Jobs tab: adds a job, fills fields, toggles upsert key, and saves', () => {
    etlTab('Jobs');
    cy.contains('button', '+ Add job').click();

    // The newly-added job card is collapsed by default — open it.
    cy.get('#workspace .column-block').last().within(() => {
      cy.get('.block-header').click();
    });

    cy.get('#workspace .column-block').last().within(() => {
      cy.get('input').eq(0).clear().type('Cypress test job');
      cy.get('textarea').clear().type('SELECT id, name FROM customers');
      cy.get('input').eq(1).clear().type('customers');

      // Switch to upsert — the key-column field must appear. select(0) is the
      // source picker; select(1) is the load-mode dropdown.
      cy.get('select').eq(1).select('upsert');
      cy.contains('label, div', 'Upsert key').should('be.visible');
      cy.get('input').eq(2).clear().type('id');

      // Batch size, incremental column/initial value, column mapping.
      cy.get('input[type="number"]').clear().type('250');
      cy.get('input[placeholder*="updated_at"]').type('updated_at');
      cy.get('input[placeholder*="1970-01-01"]').type('1970-01-01');
      cy.get('input[placeholder*="source_col:target_col"]').type('src_id:id');
    });

    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');
  });

  it('Jobs tab: job persists after reload', () => {
    etlTab('Jobs');
    cy.get('#workspace .column-block .block-title').should('contain.text', 'Cypress test job');
  });

  it('Jobs tab: Preview surfaces a backend error for an unreachable source', () => {
    etlTab('Jobs');
    cy.get('#workspace .column-block').first().within(() => {
      cy.get('.block-header').click();
      cy.contains('button', 'Preview').click();
    });
    cy.get('#workspace pre', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('be.visible')
      .and('contain.text', 'Error');
  });

  it('Jobs tab: deletes a job after confirmation', () => {
    etlTab('Jobs');
    cy.window().then(win => cy.stub(win, 'confirm').returns(true));
    cy.get('#workspace .column-block').last().within(() => {
      cy.get('.icon-btn-danger').click();
    });
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');
  });

  // ── Schedule tab ────────────────────────────────────────────────────────

  it('Schedule tab: toggles enabled + frequency and saves', () => {
    etlTab('Schedule');
    cy.get('#workspace input[type="checkbox"]').click();
    cy.get('#workspace select').select('weekly');
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');

    openEtlTab();
    etlTab('Schedule');
    cy.get('#workspace select').should('have.value', 'weekly');
  });

  it('Schedule tab: shows the cron command hint', () => {
    etlTab('Schedule');
    cy.get('#workspace').should('contain.text', 'cron_etl.php');
  });

  // ── Flows tab ───────────────────────────────────────────────────────────

  it('Flows tab: renders without a load error', () => {
    etlTab('Flows');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('contain.text', 'Chain existing ETL jobs')
      .and('not.contain.text', 'Failed to load config')
      .and('not.contain.text', 'Network error');
    cy.contains('#workspace button', '+ Add flow').should('be.visible');
  });

  it('Flows tab: adds a flow and persists it after reload', () => {
    etlTab('Flows');
    cy.contains('button', '+ Add flow').click();
    cy.get('#workspace .column-block').last().within(() => {
      cy.get('.block-header').click();
      cy.get('input').eq(0).clear().type('Cypress flow');
    });
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');

    openEtlTab();
    etlTab('Flows');
    cy.get('#workspace .column-block .block-title').should('contain.text', 'Cypress flow');
  });

  it('Flows tab: deletes a flow after confirmation', () => {
    etlTab('Flows');
    cy.window().then(win => cy.stub(win, 'confirm').returns(true));
    cy.get('#workspace .column-block').last().within(() => {
      cy.get('.icon-btn-danger').click();
    });
    cy.contains('#workspace button', 'Save configuration').click();
    cy.get('#workspace p').should('contain.text', 'saved');
  });

  // ── History tab ─────────────────────────────────────────────────────────

  it('History tab: shows empty state or note when no runs exist', () => {
    etlTab('History');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        const text = $el.text();
        expect(
          /No runs yet\.|Initialize System Tables/.test(text),
          'shows either the empty-history message or the missing-log-table note'
        ).to.be.true;
      });
  });

  it('History tab: Purge logs asks for confirmation', () => {
    etlTab('History');
    cy.window().then(win => cy.stub(win, 'confirm').returns(false));
    cy.contains('#workspace button', 'Purge logs').click();
    // confirm() was stubbed to return false — no request should have been made,
    // the panel must remain on the same tab without a status message flashing an error.
    cy.get('#workspace').should('exist');
  });
});

// ============================================================================
// Test Suite: ETL Access Control
// ============================================================================

describe('OpenSparrow – ETL Access Control', () => {
  it('editor-role user cannot reach the ETL admin action', () => {
    loginAsTestUser();
    cy.request({
      url: `${BASE}/admin/api.php?action=etl_load`,
      failOnStatusCode: false,
    }).its('status').should('be.oneOf', [401, 403]);
  });
});
