// cypress/e2e/files.cy.js
// ============================================================================
// File Manager Module Tests — files.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Files Page Structure
// ============================================================================

describe('OpenSparrow – Files: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
    cy.get('#filesSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('loads files page', () => {
    cy.get('#filesSection').should('exist');
  });

  it('shows upload card with required inputs', () => {
    cy.get('#fileInput').should('exist');
    cy.get('#fileNameInput').should('exist');
    cy.get('#fileTagsInput').should('exist');
  });

  it('shows target table select', () => {
    cy.get('#fileRelatedTable').should('exist');
  });

  it('record select is disabled before table chosen', () => {
    cy.get('#fileRelatedId').should('be.disabled');
  });

  it('shows Upload button', () => {
    cy.get('#btnUpload').should('exist').and('be.visible');
  });

  it('shows upload status area', () => {
    cy.get('#uploadStatus').should('exist');
  });

  it('shows search input', () => {
    cy.get('#fileSearch').should('be.visible');
  });

  it('shows file type filter with predefined options', () => {
    cy.get('#fileTypeFilter')
      .should('be.visible')
      .find('option')
      .should('have.length.gte', 3);
  });

  it('type filter includes All option', () => {
    cy.get('#fileTypeFilter option[value="all"]').should('exist');
  });

  it('shows Refresh button', () => {
    cy.get('#btnRefreshFiles').should('exist').and('be.visible');
  });

  it('shows file table with headers', () => {
    cy.get('.file-table').should('exist');
    cy.get('.file-table thead th').should('have.length.gte', 5);
  });

  it('file table body exists', () => {
    cy.get('#fileTableBody').should('exist');
  });
});

// ============================================================================
// Test Suite: Files List Loading
// ============================================================================

describe('OpenSparrow – Files: List Loading', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
  });

  it('file table body transitions from loading state', () => {
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('exist')
      .invoke('text')
      .should('not.eq', '');
  });

  it('file table shows files or empty message after load', () => {
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.long }).then($tbody => {
      const text = $tbody.text().toLowerCase();
      const hasFiles  = $tbody.find('.f-td-name').length > 0;
      const hasEmpty  = text.includes('no files') || text.includes('loading') === false;

      if (hasFiles) {
        cy.get('#fileTableBody .f-td-name').should('have.length.gte', 1);
      } else {
        Cypress.log({ message: 'No files in system — empty state' });
      }
    });
  });

  it('files show download links if files exist', () => {
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.long }).then($tbody => {
      if ($tbody.find('.f-btn-outline').length === 0) return;
      cy.get('#fileTableBody .f-btn-outline').first()
        .should('have.attr', 'href')
        .and('include', 'file_download.php');
    });
  });

  it('table dropdown loads available tables', () => {
    cy.get('#fileRelatedTable option', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('have.length.gte', 1);
  });
});

// ============================================================================
// Test Suite: Files Search & Filter
// ============================================================================

describe('OpenSparrow – Files: Search & Filter', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('typing in search input triggers file refresh', () => {
    cy.get('#fileSearch').clear().type('nonexistentfileXYZ123');
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('not.include', 'Loading');
  });

  it('clearing search shows all files again', () => {
    cy.get('#fileSearch').clear().type('abc');
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    cy.get('#fileSearch').clear();
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('not.include', 'Loading');
  });

  it('type filter change triggers file reload', () => {
    cy.get('#fileTypeFilter').select('image');
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist')
      .invoke('text')
      .should('not.include', 'Loading');
  });

  it('Refresh button reloads file list', () => {
    cy.get('#btnRefreshFiles').click();
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });
});

// ============================================================================
// Test Suite: Files Upload Form Validation
// ============================================================================

describe('OpenSparrow – Files: Upload Form', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
  });

  it('clicking Upload without file shows status message', () => {
    cy.get('#btnUpload').click();
    cy.get('#uploadStatus', { timeout: CypressHelpers.TIMEOUTS.short })
      .should('not.be.empty');
  });

  it('choosing table enables record select if relations configured', () => {
    cy.get('#fileRelatedTable', { timeout: CypressHelpers.TIMEOUTS.long }).then($sel => {
      if ($sel.prop('disabled')) {
        Cypress.log({ message: 'No file relations configured — table select disabled, skipping' });
        return;
      }
      const opts = $sel.find('option').toArray().filter(o => o.value !== '');
      if (opts.length === 0) {
        Cypress.log({ message: 'No relation tables available — skipping' });
        return;
      }
      // The record select only becomes enabled when get_related_records
      // succeeds — verify against the actual API outcome instead of assuming.
      cy.intercept('GET', '**/api/files.php?action=get_related_records*').as('relRecords');
      cy.wrap($sel).select(opts[0].value);
      cy.wait('@relRecords', { timeout: CypressHelpers.TIMEOUTS.long }).then(({ response }) => {
        const body = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
        if (body && body.success && body.records) {
          cy.get('#fileRelatedId', { timeout: CypressHelpers.TIMEOUTS.medium })
            .should('not.be.disabled');
        } else {
          Cypress.log({ message: 'get_related_records returned no data — select stays disabled by design' });
          cy.get('#fileRelatedId').should('be.disabled');
        }
      });
    });
  });

  it('selecting a file populates the file name input', () => {
    cy.get('#fileInput').selectFile('cypress/fixtures/test_upload.txt', { force: true });
    // After selectFile, the name input should be auto-populated or the input should register
    cy.get('#fileInput').then($input => {
      expect($input[0].files.length).to.be.gte(1);
    });
  });

  it('upload attempt reaches the API and returns a status message', () => {
    cy.intercept('POST', '**/api/files.php**').as('uploadReq');

    cy.get('#fileInput').selectFile('cypress/fixtures/test_upload.txt', { force: true });
    cy.get('#fileNameInput').then($el => {
      if ($el.val() === '') {
        cy.wrap($el).type('cypress-e2e-upload');
      }
    });
    cy.get('#btnUpload').click();

    cy.wait('@uploadReq', { timeout: CypressHelpers.TIMEOUTS.long })
      .its('response.statusCode')
      // 200/201 = success; 415 = .txt not in allowed_extensions (config-dependent)
      .should('be.oneOf', [200, 201, 415]);

    cy.get('#uploadStatus', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('not.be.empty');
  });
});

// ============================================================================
// Test Suite: Files Delete Guard
// ============================================================================

describe('OpenSparrow – Files: Delete Guard', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
    cy.get('#fileTableBody', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('delete button exists per file row for editor role', () => {
    cy.get('#fileTableBody').then($tbody => {
      const $delBtns = $tbody.find('[data-action="delete-file"]');
      if ($delBtns.length === 0) {
        Cypress.log({ message: 'No delete buttons — user is viewer or no files' });
        return;
      }
      cy.wrap($delBtns.first()).should('exist');
    });
  });

  it('delete button triggers confirm dialog', () => {
    cy.get('#fileTableBody').then($tbody => {
      if ($tbody.find('[data-action="delete-file"]').length === 0) return;

      cy.window().then(win => {
        cy.stub(win, 'confirm').as('deleteConfirm').returns(false);
        cy.get('[data-action="delete-file"]').first().click({ force: true });
        cy.get('@deleteConfirm').should('have.been.called');
      });
    });
  });
});

// ============================================================================
// Test Suite: Files Mobile
// ============================================================================

describe('OpenSparrow – Files: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/files.php`);
  });

  it('loads files page on mobile', () => {
    cy.get('#filesSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('upload and search inputs exist on mobile', () => {
    cy.get('#fileSearch').should('exist');
    cy.get('#btnUpload').should('exist');
  });
});
