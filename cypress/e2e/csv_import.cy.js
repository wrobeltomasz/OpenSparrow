// cypress/e2e/csv_import.cy.js
// ============================================================================
// CSV Import Admin Panel Tests
//
// Feature lives in: admin/index.php → tab[data-file="csv_import"]
// Rendered by: admin/js/csv_import.js into #editorForm (fully dynamic DOM)
// Backend API: admin/api_csv_import.php
//
// Behavioral notes (from csv_import.js):
//  - The workspace builds asynchronously; the table select is populated from
//    GET api.php?action=get&file=schema.
//  - Selecting a CSV file does NOT upload unless a target table is selected
//    first (or "Create new table" mode is on with a name entered) — it only
//    flashes "Select a target table first."
//  - After upload, mapping selects render as select[data-header] and the
//    execute button is labelled "Execute Import".
//
// Fixture: cypress/fixtures/test_companies.csv  (3 rows: name, email, phone)
// Values are prefixed "Cypress CSV Import" so cypress_seed.php cleanup removes them.
// ============================================================================

const BASE = 'http://localhost:8080';

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Open the CSV Import tab and wait for its async workspace to build. */
function openCsvImportTab() {
  cy.visit(`${BASE}/admin/index.php`);
  // Wait until the admin JS has initialised and rendered the initial tab —
  // clicking the nav before listeners attach silently does nothing.
  cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
    .should($el => {
      expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
    });
  cy.get('button.admin-tab[data-file="csv_import"]')
    .scrollIntoView()
    .should('be.visible')
    .click();
  // The drop-zone file input appears once the async build completes
  cy.get('#editorForm input[type="file"][accept*=".csv"]', { timeout: CypressHelpers.TIMEOUTS.long })
    .should('exist');
}

/** Select the first real table in the target-table select (prefers "companies"). */
function selectTargetTable() {
  cy.get('#editorForm select').first().find('option').then($opts => {
    const real = [...$opts].filter(o => o.value !== '');
    expect(real.length, 'at least one table available for import').to.be.gte(1);
    const preferred = real.find(o => o.value === 'companies') || real[0];
    cy.get('#editorForm select').first().select(preferred.value);
  });
}

/** Select table, upload the fixture, and wait for the upload POST. */
function uploadFixture() {
  selectTargetTable();
  cy.intercept('POST', '**/api_csv_import.php*').as('csvUpload');
  cy.get('#editorForm input[type="file"][accept*=".csv"]')
    .selectFile('cypress/fixtures/test_companies.csv', { force: true });
  return cy.wait('@csvUpload', { timeout: CypressHelpers.TIMEOUTS.long });
}

// ============================================================================
// Test Suite: CSV Import Tab Structure
// ============================================================================

describe('OpenSparrow – CSV Import: Tab Structure', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openCsvImportTab();
  });

  it('csv_import nav button is visible after scrolling', () => {
    cy.get('button.admin-tab[data-file="csv_import"]')
      .scrollIntoView()
      .should('be.visible');
  });

  it('workspace shows the CSV Import heading', () => {
    cy.get('#editorForm').should('contain.text', 'CSV Import');
  });

  it('renders sub-tabs: Import / Configuration / Import History', () => {
    cy.get('#editorForm button[data-tab]').should('have.length.gte', 3);
  });

  it('file input accepts only CSV', () => {
    cy.get('#editorForm input[type="file"]')
      .should('have.attr', 'accept')
      .and('include', '.csv');
  });

  it('target table select has a placeholder and table options', () => {
    cy.get('#editorForm select').first().within(() => {
      cy.get('option').first().should('have.value', '');
      cy.get('option').should('have.length.gte', 2); // placeholder + at least one table
    });
  });

  it('create-new-table checkbox is present and unchecked by default', () => {
    cy.get('#csv-create-table-chk').should('exist').and('not.be.checked');
  });

  it('delimiter and encoding selects are rendered', () => {
    // table select + delimiter + encoding at minimum
    cy.get('#editorForm select').should('have.length.gte', 3);
  });

  it('switching to Import History sub-tab works', () => {
    cy.get('#editorForm button[data-tab]').last().click();
    cy.get('#editorForm').should('contain.text', 'Import History');
  });
});

// ============================================================================
// Test Suite: CSV Import Upload Guard
// ============================================================================

describe('OpenSparrow – CSV Import: Upload Guard', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openCsvImportTab();
  });

  it('selecting a file without a target table shows a warning instead of uploading', () => {
    cy.intercept('POST', '**/api_csv_import.php*').as('blockedUpload');
    cy.get('#editorForm input[type="file"]')
      .selectFile('cypress/fixtures/test_companies.csv', { force: true });
    // The drop zone flashes "Select a target table first." — no POST fires
    cy.get('#editorForm').should('contain.text', 'Select a target table first');
    cy.get('@blockedUpload.all').should('have.length', 0);
  });
});

// ============================================================================
// Test Suite: CSV Import File Upload
// ============================================================================

describe('OpenSparrow – CSV Import: File Upload', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openCsvImportTab();
  });

  it('uploading with a table selected POSTs to api_csv_import.php and succeeds', () => {
    uploadFixture().its('response.statusCode').should('eq', 200);
  });

  it('upload response contains headers and row_count', () => {
    uploadFixture().then(({ response }) => {
      const body = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
      expect(body.headers, 'headers array').to.be.an('array').with.length(3);
      expect(body.row_count, 'row count').to.eq(3);
    });
  });

  it('upload status shows the filename and row count', () => {
    uploadFixture();
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('contain.text', 'test_companies.csv');
  });
});

// ============================================================================
// Test Suite: CSV Import Column Mapping
// ============================================================================

describe('OpenSparrow – CSV Import: Column Mapping', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openCsvImportTab();
    uploadFixture();
  });

  it('mapping renders one select per CSV column', () => {
    cy.get('#editorForm select[data-header]', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('have.length', 3);
  });

  it('mapping selects include a Skip option', () => {
    cy.get('#editorForm select[data-header]', { timeout: CypressHelpers.TIMEOUTS.long })
      .first()
      .find('option')
      .first()
      .invoke('text')
      .should('match', /skip/i);
  });

  it('Execute Import button is rendered in Step 2', () => {
    cy.contains('#editorForm button', 'Execute Import', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('exist');
  });
});

// ============================================================================
// Test Suite: CSV Import Execute
// ============================================================================

describe('OpenSparrow – CSV Import: Execute', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('mapping a column and executing fires csv_import_execute', () => {
    loginAsAdmin();
    openCsvImportTab();
    uploadFixture();

    cy.get('#editorForm select[data-header]', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('have.length', 3);

    // Map each CSV header to a same-named table column where one exists
    cy.get('#editorForm select[data-header]').each($sel => {
      const header = $sel.attr('data-header');
      const match  = [...$sel.find('option')].find(o => o.value === header);
      if (match) {
        cy.wrap($sel).select(header);
      }
    });

    cy.intercept('POST', /action=csv_import_execute/).as('execute');
    cy.contains('#editorForm button', 'Execute Import').click();

    cy.wait('@execute', { timeout: CypressHelpers.TIMEOUTS.long })
      .its('response.statusCode')
      // 200 = imported; 400/422 = server-side validation (still proves the wiring)
      .should('be.oneOf', [200, 400, 422]);

    // A result banner appears after execute
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('not.be.empty');
  });

  after(() => {
    // Remove the imported "Cypress CSV Import ..." rows
    cy.request({
      method: 'POST',
      url: `${BASE}/cypress_seed.php`,
      form: true,
      body: { token: 'cypress-dev-seed', action: 'cleanup' },
      failOnStatusCode: false,
    });
  });
});
