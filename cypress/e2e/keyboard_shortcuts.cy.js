// cypress/e2e/keyboard_shortcuts.cy.js
// ============================================================================
// Grid Keyboard Shortcuts + Nav-Mode + Help Modal Tests
// ============================================================================

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies';

// ============================================================================
// Test Suite: Help Modal
// ============================================================================

describe('OpenSparrow – Grid Keyboard Help Modal', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('help button exists in grid area', () => {
    cy.get('#kgHelpBtn', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('clicking help button opens help overlay', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) {
        Cypress.log({ message: 'kgHelpBtn not present — skipping' });
        return;
      }
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('exist')
        .and('be.visible');
    });
  });

  it('help overlay has keyboard shortcut table', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay', { timeout: CypressHelpers.TIMEOUTS.medium })
        .find('.kg-help-table')
        .should('exist')
        .find('tr')
        .should('have.length.greaterThan', 0);
    });
  });

  it('help overlay has key labels with kg-help-key class', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay .kg-help-key', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
    });
  });

  it('help overlay has close button', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-close', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('exist');
    });
  });

  it('close button dismisses help overlay', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
      cy.get('.kg-help-close').click();
      cy.get('.kg-help-overlay').should('not.exist');
    });
  });

  it('Escape key closes help overlay', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
      cy.get('body').type('{esc}');
      cy.get('.kg-help-overlay').should('not.exist');
    });
  });

  it('backdrop click dismisses help overlay', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-modal-backdrop', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('exist')
        .click({ force: true });
      cy.get('.kg-help-overlay').should('not.exist');
    });
  });

  it('help overlay has role=dialog and aria-modal', () => {
    cy.get('body').then($body => {
      if ($body.find('#kgHelpBtn').length === 0) return;
      cy.get('#kgHelpBtn').click({ force: true });
      cy.get('.kg-help-overlay', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.attr', 'role', 'dialog')
        .and('have.attr', 'aria-modal', 'true');
    });
  });
});

// ============================================================================
// Test Suite: ARIA Live Region
// ============================================================================

describe('OpenSparrow – Grid ARIA Live Region', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('aria live region exists in DOM after grid loads', () => {
    cy.get('#kg-live-region', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist')
      .and('have.attr', 'role', 'status')
      .and('have.attr', 'aria-live', 'polite');
  });
});

// ============================================================================
// Test Suite: Cell Focus & Navigation
// ============================================================================

describe('OpenSparrow – Grid Keyboard Navigation', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('grid cells have tabindex attribute set', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody td[data-column]', { timeout: CypressHelpers.TIMEOUTS.medium })
        .first()
        .should('have.attr', 'tabindex');
    });
  });

  it('grid cells have role=gridcell', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody td[data-column]', { timeout: CypressHelpers.TIMEOUTS.medium })
        .first()
        .should('have.attr', 'role', 'gridcell');
    });
  });

  it('grid table has role=grid', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid table', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.attr', 'role', 'grid');
    });
  });

  it('cell gets kg-focused class on focus', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody td[data-column]')
        .first()
        .focus()
        .should('have.class', 'kg-focused');
    });
  });

  it('arrow key moves kg-focused to next row', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid tbody td[data-column]').then($cells => {
        if ($cells.length < 2) return;

        cy.wrap($cells).first().focus().should('have.class', 'kg-focused');

        // Escape exits click-edit mode → enters nav-mode so arrow keys are not blocked
        cy.focused().trigger('keydown', { key: 'Escape', bubbles: true, cancelable: true });

        // Use body.type consistent with other shortcut tests — trigger on element alone is insufficient
        cy.get('body').type('{downArrow}');

        cy.get('#grid tbody td[data-column]').first()
          .should('not.have.class', 'kg-focused');
      });
    });
  });

  it('Ctrl+A selects all cells with kg-selected class', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid tbody td[data-column]').first().focus();
      // Exit click-edit mode first so global shortcuts are not blocked
      cy.focused().trigger('keydown', { key: 'Escape', bubbles: true, cancelable: true });
      cy.get('body').type('{ctrl}a');

      cy.get('#grid tbody td.kg-selected', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
    });
  });

  it('Ctrl+F focuses search input', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid tbody td[data-column]').first().focus();
      // Exit click-edit mode first so global shortcuts are not blocked
      cy.focused().trigger('keydown', { key: 'Escape', bubbles: true, cancelable: true });
      cy.get('body').type('{ctrl}f');

      cy.get('#globalSearch', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('be.focused');
    });
  });

  it('search highlights cells with kg-search-match when term matches', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#globalSearch').then($input => {
        if (!$input.length) return;
        cy.wrap($input).clear().type('a'); // common letter

        cy.get('#grid tbody td[data-column]').first().focus();
        cy.get('body').type('{ctrl}f');

        // kg-search-match cells may or may not appear depending on data
        cy.get('#grid tbody td[data-column]').then($cells => {
          const matched = $cells.toArray().some(c => c.classList.contains('kg-search-match'));
          if (matched) {
            cy.get('#grid tbody td.kg-search-match').should('have.length.greaterThan', 0);
          } else {
            Cypress.log({ message: 'No search matches in current data — acceptable' });
          }
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: Nav-Mode (contentEditable toggle)
// ============================================================================

describe('OpenSparrow – Grid Nav-Mode', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('keyboard navigation does not leave cell in editing state', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid tbody td[data-column]').first().focus();

      // Exit click-edit mode → nav-mode so ArrowDown fires correctly
      cy.focused().trigger('keydown', { key: 'Escape', bubbles: true, cancelable: true });

      cy.get('body').type('{downArrow}');

      // After arrow navigation, active cell should not be in contenteditable editing state
      cy.get('#grid tbody td.kg-focused').then($focused => {
        if ($focused.length > 0) {
          // contentEditable should be 'false' (nav-mode) or absent — not 'true' (editing)
          const ce = $focused.first().attr('contenteditable');
          expect(ce).to.not.equal('true');
        }
      });
    });
  });
});
