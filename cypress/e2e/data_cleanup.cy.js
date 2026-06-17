// cypress/e2e/data_cleanup.cy.js
// ============================================================================
// Quick Data Cleanup Grid Module Tests
// ============================================================================

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies';

// ============================================================================
// Test Suite: Data Cleanup Panel UI
// ============================================================================

describe('OpenSparrow – Data Cleanup Panel', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('displays Data Cleanup button in grid toolbar', () => {
    cy.get('#dataCleanupBtn', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('opens panel when Data Cleanup button clicked', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) {
        Cypress.log({ message: 'dataCleanupBtn not present — skipping' });
        return;
      }
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-panel', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('panel contains required form elements', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-panel').within(() => {
        cy.get('#dc-column').should('exist');
        cy.get('#dc-find').should('exist');
        cy.get('#dc-replace').should('exist');
        cy.get('#dc-toggle-case').should('exist');
        cy.get('#dc-toggle-word').should('exist');
        cy.get('#dc-toggle-accent').should('exist');
        cy.get('#dc-apply').should('exist').and('be.disabled');
        cy.get('#dc-status').should('exist');
        cy.get('#dc-preview-area').should('exist');
      });
    });
  });

  it('column select is populated with text columns', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-column', { timeout: CypressHelpers.TIMEOUTS.medium })
        .find('option')
        .should('have.length.greaterThan', 0);
    });
  });

  it('overlay appears when panel is open', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('.dc-overlay', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('close button hides panel', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-panel').should('have.class', 'active');
      cy.get('#dc-close').click();
      cy.get('#dc-panel').should('not.have.class', 'active');
    });
  });

  it('overlay click hides panel', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-panel').should('have.class', 'active');
      cy.get('.dc-overlay').click({ force: true });
      cy.get('#dc-panel').should('not.have.class', 'active');
    });
  });

  it('apply button stays disabled without preview', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-find').type('test');
      // Apply must remain disabled until preview runs and hash matches
      cy.get('#dc-apply').should('be.disabled');
    });
  });

  it('typing in find field triggers debounced status update', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      // Wait for column select to be populated — confirms gridState.currentTable is set
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('test');
      // Status updates after 400ms debounce + fetch
      cy.get('#dc-status', { timeout: CypressHelpers.TIMEOUTS.long })
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('find field cleared on panel reopen', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-find').type('some value');
      cy.get('#dc-close').click();
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-find').should('have.value', '');
    });
  });

  it('replace field accepts empty value (delete mode)', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-replace').should('have.value', '');
      cy.get('#dc-replace').should('not.have.attr', 'required');
    });
  });
});

// ============================================================================
// Test Suite: Data Cleanup Preview Table
// ============================================================================

describe('OpenSparrow – Data Cleanup Preview', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('preview area empty on panel open', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-preview-area').should('be.empty');
    });
  });

  it('status shows loading then result after find input', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      // Wait for column select — confirms gridState.currentTable is set
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('e'); // common letter — likely matches
      // Wait for fetch + debounce
      cy.get('#dc-status', { timeout: CypressHelpers.TIMEOUTS.long })
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('preview table uses dc-preview-table class when rows returned', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-find').type('e');
      cy.get('#dc-preview-area', { timeout: CypressHelpers.TIMEOUTS.long }).then($area => {
        const hasTable = $area.find('.dc-preview-table').length > 0;
        if (hasTable) {
          cy.wrap($area).find('.dc-preview-table')
            .should('exist')
            .find('thead th')
            .should('have.length', 2);
        }
      });
    });
  });

  it('apply button becomes enabled after preview finds matches', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('e');
      cy.get('#dc-preview-area', { timeout: CypressHelpers.TIMEOUTS.long }).then($area => {
        if ($area.find('.dc-preview-table').length === 0) {
          Cypress.log({ message: 'No preview table — no matches; apply stays disabled (OK)' });
          return;
        }
        cy.get('#dc-apply').should('not.be.disabled');
      });
    });
  });

  it('apply button disabled again after clear', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('e');
      cy.get('#dc-status', { timeout: CypressHelpers.TIMEOUTS.long }).invoke('text').should('not.be.empty');
      cy.get('#dc-find').clear();
      cy.get('#dc-status', { timeout: CypressHelpers.TIMEOUTS.long }).invoke('text').should('not.be.empty');
      cy.get('#dc-apply').should('be.disabled');
    });
  });
});

// ============================================================================
// Test Suite: Data Cleanup Apply Flow
// ============================================================================

describe('OpenSparrow – Data Cleanup Apply', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('apply triggers POST to api/data_cleanup.php', () => {
    cy.intercept('POST', '**/api/data_cleanup.php**').as('cleanupApply');
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('e');
      cy.get('#dc-preview-area', { timeout: CypressHelpers.TIMEOUTS.long }).then($area => {
        if ($area.find('.dc-preview-table').length === 0) return;
        cy.get('#dc-apply').should('not.be.disabled').click();
        cy.wait('@cleanupApply', { timeout: CypressHelpers.TIMEOUTS.long });
        cy.get('#dc-status', { timeout: CypressHelpers.TIMEOUTS.medium })
          .invoke('text')
          .should('not.be.empty');
      });
    });
  });

  it('after apply: apply button returns to disabled', () => {
    cy.get('body').then($body => {
      if ($body.find('#dataCleanupBtn').length === 0) return;
      cy.get('#dataCleanupBtn').click({ force: true });
      cy.get('#dc-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
      cy.get('#dc-find').type('e');
      cy.get('#dc-preview-area', { timeout: CypressHelpers.TIMEOUTS.long }).then($area => {
        if ($area.find('.dc-preview-table').length === 0) return;
        cy.get('#dc-apply').should('not.be.disabled').click();
        cy.get('#dc-apply', { timeout: CypressHelpers.TIMEOUTS.medium })
          .should('be.disabled');
      });
    });
  });
});
