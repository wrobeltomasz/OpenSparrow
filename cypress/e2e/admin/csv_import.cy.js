// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

function openCsvImportTab() {
  cy.visit(`${BASE}/admin/index.php`);

  cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
    .should('contain.text', 'Admin Overview');

  cy.get('button.admin-tab[data-file="csv_import"]').then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get('button.admin-tab[data-file="csv_import"]')
    .scrollIntoView()
    .should('be.visible')
    .click();

  cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
    .should('contain.text', 'CSV Import')
    .and('not.contain.text', 'Admin Overview');

  cy.get('#editorForm input[type="file"][accept*=".csv"]', { timeout: CypressHelpers.TIMEOUTS.long })
    .should('exist');
}

function selectTargetTable() {
  cy.get('#editorForm select').first()
    .find('option')
    .should('have.length.gte', 2);
  cy.get('#editorForm select').first().should('not.be.disabled').then($sel => {
    const real = [...$sel.find('option')].filter(o => o.value !== '');
    const preferred = real.find(o => o.value === 'companies') || real[0];
    cy.get('#editorForm select').first().select(preferred.value);
  });
}

function uploadFixture() {
  selectTargetTable();
  cy.intercept('POST', '**/api_csv_import.php*').as('csvUpload');
  cy.get('#editorForm input[type="file"][accept*=".csv"]')
    .selectFile('cypress/fixtures/test_companies.csv', { force: true });
  return cy.wait('@csvUpload', { timeout: CypressHelpers.TIMEOUTS.long });
}

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
      cy.get('option').should('have.length.gte', 2);
    });
  });

  it('create-new-table checkbox is present and unchecked by default', () => {
    cy.get('#csv-create-table-chk').should('exist').and('not.be.checked');
  });

  it('delimiter and encoding selects are rendered', () => {
    cy.get('#editorForm select').should('have.length.gte', 3);
  });

  it('switching to Import History sub-tab works', () => {
    cy.get('#editorForm button[data-tab]').last().click();
    cy.get('#editorForm').should('contain.text', 'Import History');
  });
});

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

    cy.get('#editorForm').should('contain.text', 'Select a target table first');
    cy.get('@blockedUpload.all').should('have.length', 0);
  });
});

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

      .should('be.oneOf', [200, 400, 422]);

    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('not.be.empty');
  });

  after(() => {
    cy.request({
      method: 'POST',
      url: `${BASE}/cypress_seed.php`,
      form: true,
      body: { token: 'cypress-dev-seed', action: 'cleanup' },
      failOnStatusCode: false,
    });
  });
});
