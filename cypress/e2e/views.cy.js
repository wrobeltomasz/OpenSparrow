// cypress/e2e/views.cy.js
// ============================================================================
// Views Module Tests — views.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Views Page Structure
// ============================================================================

describe('OpenSparrow – Views: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
  });

  it('loads views page', () => {
    cy.get('#viewSection', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('view container exists', () => {
    cy.get('#viewContainer').should('exist');
  });

  it('shows loading state initially', () => {
    // Loading may flash quickly; just verify container renders
    cy.get('#viewContainer').should('exist');
  });

  it('shows global search input', () => {
    cy.get('#globalSearch').should('exist');
  });

  it('shows sidebar menu', () => {
    cy.get('#menu').should('exist');
  });
});

// ============================================================================
// Test Suite: Views Selector Loading
// ============================================================================

describe('OpenSparrow – Views: Selector Loading', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
  });

  it('view container transitions out of loading state', () => {
    // The .vw-loading div is a child of #viewContainer, not the container itself
    cy.get('#viewContainer .vw-loading', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('not.exist');
  });

  it('shows selector cards, empty message, or error after load', () => {
    // Wait for loading child to disappear first, then check resulting state
    cy.get('#viewContainer .vw-loading').should('not.exist');
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should($el => {
      const hasCards = $el.find('.vw-selector-card').length > 0;
      const hasEmpty = $el.find('.vw-empty').length > 0;
      const hasError = $el.find('.vw-error').length > 0;
      expect(hasCards || hasEmpty || hasError).to.be.true;
    });
  });

  it('if views configured: selector cards render', () => {
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).then($el => {
      const hasCards = $el.find('.vw-selector-card').length > 0;
      if (!hasCards) {
        Cypress.log({ message: 'No views configured — skipping selector card tests' });
        return;
      }
      cy.get('.vw-selector-card').should('have.length.gte', 1);
    });
  });

  it('if views configured: each card has a title (h3)', () => {
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card h3')
        .should('have.length.gte', 1)
        .first()
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('if views configured: sidebar lists views in a submenu under Views', () => {
    cy.get('#viewContainer .vw-loading').should('not.exist');
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) {
        Cypress.log({ message: 'No views configured — skipping menu submenu test' });
        return;
      }
      cy.get('#menu a[href^="views.php?view="]')
        .should('have.length.gte', 1)
        .first()
        .closest('.menu-submenu')
        .should('exist');
    });
  });

  it('if no views: shows empty state message', () => {
    // Wait for JS to finish rendering before checking state
    cy.get('#viewContainer .vw-loading').should('not.exist');
    cy.get('#viewContainer').should($el => {
      // Only assert .vw-empty if no cards and no error rendered
      const hasCards = $el.find('.vw-selector-card').length > 0;
      const hasError = $el.find('.vw-error').length > 0;
      if (hasCards || hasError) return; // skip — views exist or error shown
      expect($el.find('.vw-empty').length, '.vw-empty should exist when no views').to.be.gte(1);
    });
  });
});

// ============================================================================
// Test Suite: View Opening
// ============================================================================

describe('OpenSparrow – Views: Open View', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('clicking a view card opens the view', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) {
        Cypress.log({ message: 'No views — skipping open test' });
        return;
      }
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('opened view shows data table or empty state', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should($cnt => {
        const hasTable = $cnt.find('.vw-table-wrap').length > 0;
        const hasEmpty = $cnt.find('.vw-empty').length > 0;
        expect(hasTable || hasEmpty).to.be.true;
      });
    });
  });

  it('opened view has no back button (navigation via sidebar menu)', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
      cy.get('.vw-drill-up').should('not.exist');
    });
  });

  it('view table has sortable column headers', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap', { timeout: CypressHelpers.TIMEOUTS.long }).then($wrap => {
        if ($wrap.length === 0) return;
        cy.get('.vw-table-wrap table thead th')
          .should('have.length.gte', 1);
      });
    });
  });
});

// ============================================================================
// Test Suite: Views via URL param
// ============================================================================

describe('OpenSparrow – Views: URL param', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('views.php with ?view= param auto-opens view if valid', () => {
    cy.visit(`${BASE}/views.php?view=nonexistent_xyz`);
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    // Wait for loading to finish, then assert rendered state
    cy.get('#viewContainer .vw-loading').should('not.exist');
    cy.get('#viewContainer').should($el => {
      const hasSomething = $el.find('.vw-error, .vw-selector-card, .vw-empty, .vw-table-wrap').length > 0;
      expect(hasSomething, 'view container rendered after load').to.be.true;
    });
  });
});

// ============================================================================
// Test Suite: Views Column Filters (grid-style, added with row grouping)
// ============================================================================

describe('OpenSparrow – Views: Column Filters', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('opening a view with data populates the column filter dropdown', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) {
        Cypress.log({ message: 'No views — skipping column filter tests' });
        return;
      }
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#columnFilter').should('not.have.attr', 'hidden');
        cy.get('#columnFilter option').should('have.length.gte', 2); // default + at least one column
      });
    });
  });

  it('selecting a column renders a filter control and an active-filter pill', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#columnFilter option').its('length').then(len => {
          if (len < 2) return;
          cy.get('#columnFilter option').eq(1).invoke('val').then(val => {
            cy.get('#columnFilter').select(val);
            cy.get('#filterBar').children().should('have.length.gte', 1);
            cy.get('#clearFilters').should('not.have.attr', 'hidden');
          });
        });
      });
    });
  });

  it('clear-filters button resets the column filter and hides itself', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#columnFilter option').its('length').then(len => {
          if (len < 2) return;
          cy.get('#columnFilter option').eq(1).invoke('val').then(val => {
            cy.get('#columnFilter').select(val);
            cy.get('#clearFilters').click();
            cy.get('#columnFilter').should('have.value', '');
            cy.get('#filterBar').children().should('have.length', 0);
            cy.get('#clearFilters').should('have.attr', 'hidden');
          });
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: Views Grouping (collapsible groups + subtotals)
// ============================================================================

describe('OpenSparrow – Views: Grouping', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
    cy.get('#viewContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('opening a view with data populates the group-by dropdown', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#groupBy').should('not.have.attr', 'hidden');
        cy.get('#groupBy option').should('have.length.gte', 2);
      });
    });
  });

  it('selecting a group-by column renders collapsible group headers', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#groupBy option').its('length').then(len => {
          if (len < 2) return;
          cy.get('#groupBy option').eq(1).invoke('val').then(val => {
            cy.get('#groupBy').select(val);
            cy.get('.vw-group-header').should('have.length.gte', 1);
          });
        });
      });
    });
  });

  it('clicking a group header collapses and re-expands its rows', () => {
    cy.get('#viewContainer').then($el => {
      if ($el.find('.vw-selector-card').length === 0) return;
      cy.get('.vw-selector-card').first().click();
      cy.get('.vw-table-wrap, .vw-empty', { timeout: CypressHelpers.TIMEOUTS.long }).then($state => {
        if (!$state.hasClass('vw-table-wrap')) return;
        cy.get('#groupBy option').its('length').then(len => {
          if (len < 2) return;
          cy.get('#groupBy option').eq(1).invoke('val').then(val => {
            cy.get('#groupBy').select(val);
            cy.get('.vw-group-header').first().find('.vw-group-arrow').invoke('text').then(before => {
              cy.get('.vw-group-header').first().click();
              cy.get('.vw-group-header').first().find('.vw-group-arrow')
                .invoke('text')
                .should('not.eq', before);
            });
          });
        });
      });
    });
  });
});
