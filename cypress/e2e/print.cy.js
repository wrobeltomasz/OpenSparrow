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

  it('an unknown p_ query arg is ignored instead of breaking an existing print', () => {
    cy.get('#menu a[href^="print.php?print="]').then($links => {
      if ($links.length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping robustness test' });
        return;
      }
      const href = $links.first().attr('href');
      cy.visit(`${BASE}/${href}&p_not_a_real_param=xyz`);
      cy.get('#printContainer .pr-loading', { timeout: CypressHelpers.TIMEOUTS.long }).should('not.exist');
      cy.get('#printSheet, .pr-error', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    });
  });
});

// ============================================================================
// Test Suite: Report Parameters (filters rendered in the blue app header)
// ============================================================================
// These templates only exist when an admin has configured "params" on a print
// (see admin/js/print_editor.js "Report parameters" section), so every test
// here degrades gracefully to a logged skip when none are configured — same
// defensive pattern as the selector/open-template suites above. Filters live in
// #printFilters in the header (same pattern as #boardFilters/#dashboardFilters)
// and apply immediately on change, like every other header filter in the app.

describe('OpenSparrow – Print: Report Parameters', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/print.php`);
    cy.get('#printContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  function openFirstPrint() {
    cy.get('#printContainer .pr-loading').should('not.exist');
    cy.get('.pr-selector-card').first().click();
    cy.get('#printSheet, .pr-error', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  }

  it('opened template shows parameter selects in the header only when the template declares params', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping parameters test' });
        return;
      }
      openFirstPrint();
      cy.get('#printFilters').then($bar => {
        if ($bar.find('select').length === 0) {
          Cypress.log({ message: 'Opened template declares no parameters — skipping' });
          return;
        }
        cy.get('#printFilters select').should('have.length.gte', 1);
        cy.get('#printFilters label').first().invoke('text').should('not.be.empty');
      });
    });
  });

  it('picking a parameter value filters the report immediately and updates the URL', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping parameters test' });
        return;
      }
      openFirstPrint();
      cy.get('#printFilters').then($bar => {
        const $select = $bar.find('select').first();
        if ($select.length === 0 || $select.find('option[value!=""]').length === 0) {
          Cypress.log({ message: 'No selectable parameter options — skipping' });
          return;
        }
        const value = $select.find('option[value!=""]').first().val();

        cy.wrap($select).select(value);

        cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'p_');
        cy.get('#clearFilters').should('be.visible');
      });
    });
  });

  it('reloading a print URL with a p_ filter pre-selects that value in the header', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping parameters test' });
        return;
      }
      openFirstPrint();
      cy.get('#printFilters').then($bar => {
        const $select = $bar.find('select').first();
        if ($select.length === 0 || $select.find('option[value!=""]').length === 0) {
          Cypress.log({ message: 'No selectable parameter options — skipping' });
          return;
        }
        const value = $select.find('option[value!=""]').first().val();
        cy.wrap($select).select(value);
        cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');

        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).then(url => {
          cy.visit(url);
          cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
          cy.get('#printFilters select').first().should('have.value', value);
        });
      });
    });
  });

  it('Clear filters resets the header selects and reloads the unfiltered report', () => {
    cy.get('#printContainer').then($el => {
      if ($el.find('.pr-selector-card').length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping parameters test' });
        return;
      }
      openFirstPrint();
      cy.get('#printFilters').then($bar => {
        const $select = $bar.find('select').first();
        if ($select.length === 0 || $select.find('option[value!=""]').length === 0) {
          Cypress.log({ message: 'No selectable parameter options — skipping' });
          return;
        }
        const value = $select.find('option[value!=""]').first().val();
        cy.wrap($select).select(value);
        cy.get('#clearFilters', { timeout: CypressHelpers.TIMEOUTS.long }).should('be.visible').click();
        cy.get('#printSheet', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
        cy.get('#clearFilters').should('not.be.visible');
      });
    });
  });
});
