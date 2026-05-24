// cypress/e2e/mass_edit.cy.js
// ============================================================================
// Mass Edit Module Tests
// Covers: checkbox selection, floating me-bar, Edit Fields panel,
//         Export column picker, Assign Owner panel, duplicate/delete guards.
// All suites gate on .row-select-cb presence (editor role only).
// ============================================================================

const BASE       = 'http://localhost:8080';
const TEST_TABLE = 'companies';

// ─── helpers ─────────────────────────────────────────────────────────────────

/** Select first row checkbox and return whether it existed. */
function selectFirstRow() {
  return cy.get('body').then($body => {
    if ($body.find('.row-select-cb').length === 0) return false;
    cy.get('.row-select-cb').first().check({ force: true });
    return true;
  });
}

// ============================================================================
// Suite: Checkbox column & floating bar
// ============================================================================

describe('OpenSparrow – Mass Edit: Selection & Bar', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('row select checkboxes exist for editor users', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) {
        Cypress.log({ message: '.row-select-cb not present — user is not editor, skipping' });
        return;
      }
      cy.get('.row-select-cb').should('have.length.greaterThan', 0);
    });
  });

  it('select-all header checkbox exists for editor users', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.th-select input[type="checkbox"]').should('exist');
    });
  });

  it('me-bar appears after checking a row', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('#me-bar', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('me-bar count reflects selected row count', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('#me-bar-count', { timeout: CypressHelpers.TIMEOUTS.medium })
        .invoke('text')
        .should('match', /1/);
    });
  });

  it('me-bar contains all action buttons', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('#me-bar', { timeout: CypressHelpers.TIMEOUTS.medium }).within(() => {
        cy.get('.me-bar-edit-btn').should('exist');
        cy.get('.me-bar-export-btn').should('exist');
        cy.get('.me-bar-owner-btn').should('exist');
        cy.get('.me-bar-dup-btn').should('exist');
        cy.get('.me-bar-delete-btn').should('exist');
        cy.get('.me-bar-clear-btn').should('exist');
      });
    });
  });

  it('Deselect All button hides me-bar', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('#me-bar', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
      cy.get('.me-bar-clear-btn').click({ force: true });
      cy.get('#me-bar').should('not.have.class', 'active');
    });
  });

  it('Deselect All unchecks all visible row checkboxes', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-clear-btn').click({ force: true });
      cy.get('.row-select-cb').each($cb => {
        expect($cb.prop('checked')).to.be.false;
      });
    });
  });

  it('select-all header checkbox selects all visible rows', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.th-select input[type="checkbox"]').check({ force: true });
      cy.get('#me-bar', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
    });
  });
});

// ============================================================================
// Suite: Edit Fields Panel
// ============================================================================

describe('OpenSparrow – Mass Edit: Edit Fields Panel', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('Edit Fields button opens mass edit panel', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn', { timeout: CypressHelpers.TIMEOUTS.medium }).click({ force: true });
      cy.get('#me-panel', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('mass edit panel contains required form elements', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).within(() => {
        cy.get('#me-column').should('exist');
        cy.get('#me-value').should('exist');
        cy.get('#me-set-null').should('exist');
        cy.get('#me-preview-btn').should('exist');
        cy.get('.me-preview-area').should('exist');
      });
    });
  });

  it('apply button is disabled before preview runs', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-panel .bp-apply-btn', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('be.disabled');
    });
  });

  it('column select is populated', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
    });
  });

  it('null toggle disables value input when checked', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-set-null', { timeout: CypressHelpers.TIMEOUTS.medium }).check({ force: true });
      cy.get('#me-value').should('be.disabled');
    });
  });

  it('close button dismisses mass edit panel', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
      cy.get('#me-panel .bp-close').click({ force: true });
      cy.get('#me-panel').should('not.have.class', 'active');
    });
  });

  it('overlay click dismisses mass edit panel', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
      cy.get('.bp-overlay').first().click({ force: true });
      cy.get('#me-panel').should('not.have.class', 'active');
    });
  });

  it('changing column clears preview and disables apply', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-edit-btn').click({ force: true });
      cy.get('#me-column option', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 1);
      cy.get('#me-column').then($sel => {
        const opts = $sel.find('option');
        if (opts.length < 2) return;
        cy.wrap($sel).select(opts.eq(1).val());
        cy.get('#me-panel .bp-apply-btn').should('be.disabled');
      });
    });
  });
});

// ============================================================================
// Suite: Export Column Picker
// ============================================================================

describe('OpenSparrow – Mass Edit: Export Column Picker', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('Export Selected button opens export panel', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn', { timeout: CypressHelpers.TIMEOUTS.medium }).click({ force: true });
      cy.get('#me-export-panel', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('export panel contains column checkboxes', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel .me-col-picker-cb', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.greaterThan', 0);
    });
  });

  it('all column checkboxes are checked by default', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel .me-col-picker-cb', { timeout: CypressHelpers.TIMEOUTS.medium })
        .each($cb => {
          expect($cb.prop('checked')).to.be.true;
        });
    });
  });

  it('Download CSV button is enabled when columns checked', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel .bp-apply-btn', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('not.be.disabled');
    });
  });

  it('Deselect all disables download button', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('.me-col-picker-quick-btn', { timeout: CypressHelpers.TIMEOUTS.medium })
        .last()
        .click({ force: true });
      cy.get('#me-export-panel .bp-apply-btn').should('be.disabled');
    });
  });

  it('Select all re-enables download button after deselect', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('.me-col-picker-quick-btn', { timeout: CypressHelpers.TIMEOUTS.medium })
        .last()
        .click({ force: true }); // deselect all
      cy.get('#me-export-panel .bp-apply-btn').should('be.disabled');
      cy.get('.me-col-picker-quick-btn').first().click({ force: true }); // select all
      cy.get('#me-export-panel .bp-apply-btn').should('not.be.disabled');
    });
  });

  it('export panel shows row count info', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel .me-scope-info', { timeout: CypressHelpers.TIMEOUTS.medium })
        .invoke('text')
        .should('match', /1/);
    });
  });

  it('column picker list uses me-col-picker-list container', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel .me-col-picker-list', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('exist');
    });
  });

  it('export panel close button works', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-export-btn').click({ force: true });
      cy.get('#me-export-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
      cy.get('#me-export-panel .bp-close').click({ force: true });
      cy.get('#me-export-panel').should('not.have.class', 'active');
    });
  });
});

// ============================================================================
// Suite: Assign Owner Panel
// ============================================================================

describe('OpenSparrow – Mass Edit: Assign Owner Panel', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('Assign Owner button opens owner panel', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn', { timeout: CypressHelpers.TIMEOUTS.medium }).click({ force: true });
      cy.get('#me-owner-panel', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.class', 'active');
    });
  });

  it('owner panel loads user select from API', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn').click({ force: true });
      cy.get('#me-owner-sel', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('apply button disabled when no user selected', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn').click({ force: true });
      cy.get('#me-owner-panel .bp-apply-btn', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('be.disabled');
    });
  });

  it('selecting a user enables apply button', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn').click({ force: true });
      cy.get('#me-owner-sel option', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('have.length.greaterThan', 1)
        .then($opts => {
          const nonBlank = $opts.toArray().find(o => o.value !== '');
          if (!nonBlank) return;
          cy.get('#me-owner-sel').select(nonBlank.value);
          cy.get('#me-owner-panel .bp-apply-btn').should('not.be.disabled');
        });
    });
  });

  it('owner panel shows scope info with row count', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn').click({ force: true });
      cy.get('#me-owner-panel .me-scope-info', { timeout: CypressHelpers.TIMEOUTS.long })
        .invoke('text')
        .should('match', /1/);
    });
  });

  it('owner panel close button works', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;
      cy.get('.row-select-cb').first().check({ force: true });
      cy.get('.me-bar-owner-btn').click({ force: true });
      cy.get('#me-owner-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).should('have.class', 'active');
      cy.get('#me-owner-panel .bp-close').click({ force: true });
      cy.get('#me-owner-panel').should('not.have.class', 'active');
    });
  });
});

// ============================================================================
// Suite: Mass Duplicate & Delete guards
// ============================================================================

describe('OpenSparrow – Mass Edit: Duplicate & Delete Guards', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('Duplicate button triggers confirm dialog', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;

      cy.window().then(win => {
        cy.stub(win, 'confirm').as('dupConfirm').returns(false);
        cy.get('.row-select-cb').first().check({ force: true });
        cy.get('.me-bar-dup-btn', { timeout: CypressHelpers.TIMEOUTS.medium }).click({ force: true });
        cy.get('@dupConfirm').should('have.been.called');
      });
    });
  });

  it('Delete button triggers confirm dialog', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;

      cy.window().then(win => {
        cy.stub(win, 'confirm').as('delConfirm').returns(false);
        cy.get('.row-select-cb').first().check({ force: true });
        cy.get('.me-bar-delete-btn', { timeout: CypressHelpers.TIMEOUTS.medium }).click({ force: true });
        cy.get('@delConfirm').should('have.been.called');
      });
    });
  });

  it('cancelling confirm keeps rows selected', () => {
    cy.get('body').then($body => {
      if ($body.find('.row-select-cb').length === 0) return;

      cy.window().then(win => {
        cy.stub(win, 'confirm').returns(false);
        cy.get('.row-select-cb').first().check({ force: true });
        cy.get('.me-bar-delete-btn').click({ force: true });
        cy.get('#me-bar').should('have.class', 'active');
      });
    });
  });
});
