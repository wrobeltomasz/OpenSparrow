// cypress/e2e/print.cy.js
// ============================================================================
// Print Module Tests — print.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Print Page Structure
// ============================================================================

describe('OpenSparrow – Print: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/print.php`);
  });

  it('loads print page', () => {
    cy.get('#printSection', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('print container exists', () => {
    cy.get('#printContainer').should('exist');
  });

  it('shows sidebar menu', () => {
    cy.get('#menu').should('exist');
  });
});

// ============================================================================
// Test Suite: Print Selector Loading
// ============================================================================

describe('OpenSparrow – Print: Selector Loading', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/print.php`);
  });

  it('print container transitions out of loading state', () => {
    cy.get('#printContainer .pr-loading', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('not.exist');
  });

  it('shows selector cards, empty message, or error after load', () => {
    cy.get('#printContainer .pr-loading').should('not.exist');
    cy.get('#printContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should($el => {
      const hasCards = $el.find('.pr-selector-card').length > 0;
      const hasEmpty = $el.find('.pr-empty').length > 0;
      const hasError = $el.find('.pr-error').length > 0;
      expect(hasCards || hasEmpty || hasError).to.be.true;
    });
  });

  it('if prints configured: selector cards render with a title', () => {
    cy.get('#printContainer', { timeout: CypressHelpers.TIMEOUTS.long }).then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping selector card tests' });
        return;
      }
      cy.get('.pr-selector-card').should('have.length.gte', 1);
      cy.get('.pr-card-title')
        .first()
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('if prints configured: sidebar lists print templates in a submenu under Print', () => {
    cy.get('#printContainer .pr-loading').should('not.exist');
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping menu submenu test' });
        return;
      }
      cy.get('#menu a[href^="print.php?print="]')
        .should('have.length.gte', 1)
        .first()
        .closest('.menu-submenu')
        .should('exist');
    });
  });

  it('if no prints configured: shows empty state message', () => {
    cy.get('#printContainer .pr-loading').should('not.exist');
    cy.get('#printContainer').should($el => {
      const hasCards = $el.find('.pr-selector-card').length > 0;
      const hasError = $el.find('.pr-error').length > 0;
      if (hasCards || hasError) return;
      expect($el.find('.pr-empty').length, '.pr-empty should exist when no prints').to.be.gte(1);
    });
  });
});

// ============================================================================
// Test Suite: Opening a Print Template
// ============================================================================

describe('OpenSparrow – Print: Open Template', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/print.php`);
    cy.get('#printContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('clicking a print card opens the printable sheet', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates — skipping open test' });
        return;
      }
      cy.get('.pr-selector-card').first().click();
      cy.get('#printSheet, .pr-error', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('opened template shows a Print button in the toolbar', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) return;
      cy.get('.pr-selector-card').first().click();
      cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).then($sheet => {
        if ($sheet.length === 0) return;
        cy.get('#printPage').should('exist');
      });
    });
  });

  it('opened template is paginated with a "current / total" footer on each page', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) return;
      cy.get('.pr-selector-card').first().click();
      cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).then($sheet => {
        if ($sheet.length === 0) return;
        cy.get('#printSheet .pr-page').should('have.length.gte', 1);
        cy.get('#printSheet .pr-page-footer').each($footer => {
          expect($footer.text().trim()).to.match(/^\d+\s*\/\s*\d+$/);
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: Print via URL param
// ============================================================================

describe('OpenSparrow – Print: URL param', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('print.php with an unknown ?print= shows an error instead of crashing', () => {
    cy.visit(`${BASE}/print.php?print=nonexistent_xyz`);
    cy.get('#printContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('#printContainer .pr-loading').should('not.exist');
    cy.get('#printContainer .pr-error', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('exist');
  });
});
