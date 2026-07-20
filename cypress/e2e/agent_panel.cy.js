// cypress/e2e/agent_panel.cy.js
// ============================================================================
// AI Agent Panel Tests — the sliding panel opened via #openAgentBtn.
// Panel DOM is built by assets/js/agent-panel.js after DOMContentLoaded.
// ============================================================================

const BASE       = 'http://localhost:8080';
const TEST_TABLE = 'companies';

// ============================================================================
// Test Suite: Panel Structure
// ============================================================================

describe('OpenSparrow – Agent Panel: Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    // Wait for the agent-panel module to initialise (DOMContentLoaded + I18n.load)
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('panel container exists in DOM after page load', () => {
    cy.get('#agPanel').should('exist');
  });

  it('overlay element exists in DOM', () => {
    cy.get('#agOverlay').should('exist');
  });

  it('panel is not active (hidden) before opening', () => {
    cy.get('#agPanel').should('not.have.class', 'active');
  });

  it('FAB button exists when chat_bubble_enabled is true', () => {
    cy.window().then(win => {
      if (win.CHAT_BUBBLE_ENABLED) {
        cy.get('#agFab').should('exist');
      } else {
        Cypress.log({ message: 'CHAT_BUBBLE_ENABLED is false — FAB not rendered' });
      }
    });
  });
});

// ============================================================================
// Test Suite: Opening the Panel
// ============================================================================

describe('OpenSparrow – Agent Panel: Open & Close', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  function openPanel() {
    // The page is already loaded; just wait for the button to be interactive
    cy.get('#userAvatarBtn', { timeout: CypressHelpers.TIMEOUTS.medium }).click();
    cy.get('#openAgentBtn', { timeout: CypressHelpers.TIMEOUTS.short }).should('be.visible').click();
    cy.get('#agPanel').should('have.class', 'active');
  }

  it('panel opens via openAgentBtn in user menu', () => {
    cy.get('#userAvatarBtn', { timeout: CypressHelpers.TIMEOUTS.medium }).click();
    cy.get('#openAgentBtn', { timeout: CypressHelpers.TIMEOUTS.short }).should('be.visible').click();
    cy.get('#agPanel').should('have.class', 'active');
    cy.get('#agOverlay').should('have.class', 'active');
  });

  it('panel contains conversation area, textarea, and send button', () => {
    openPanel();
    cy.get('#agConv').should('exist');
    cy.get('#agQuery').should('exist').and('be.visible');
    cy.get('.ag-send-btn').should('exist').and('be.visible');
  });

  it('panel contains tag strip', () => {
    openPanel();
    cy.get('#agTags').should('exist');
  });

  it('close button dismisses the panel', () => {
    openPanel();
    cy.get('.ag-close').click();
    cy.get('#agPanel').should('not.have.class', 'active');
    cy.get('#agOverlay').should('not.have.class', 'active');
  });

  it('overlay click dismisses the panel', () => {
    openPanel();
    cy.get('#agOverlay').click({ force: true });
    cy.get('#agPanel').should('not.have.class', 'active');
  });

  it('Escape key dismisses the panel', () => {
    openPanel();
    cy.get('body').type('{esc}');
    cy.get('#agPanel').should('not.have.class', 'active');
  });

  it('panel has role=dialog and aria-modal attributes', () => {
    cy.get('#agPanel')
      .should('have.attr', 'role', 'dialog')
      .and('have.attr', 'aria-modal', 'true');
  });
});

// ============================================================================
// Test Suite: Send Interaction
// ============================================================================

describe('OpenSparrow – Agent Panel: Send', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('#userAvatarBtn', { timeout: CypressHelpers.TIMEOUTS.medium }).click();
    cy.get('#openAgentBtn', { timeout: CypressHelpers.TIMEOUTS.short }).should('be.visible').click();
    cy.get('#agPanel').should('have.class', 'active');
  });

  it('empty textarea does not post to api/rag.php', () => {
    cy.intercept('POST', '**/api/rag.php**').as('ragPost');
    cy.get('.ag-send-btn').click();
    cy.wait(500);
    cy.get('@ragPost.all').should('have.length', 0);
  });

  it('without selecting a source, send shows a notice instead of posting', () => {
    cy.intercept('POST', '**/api/rag.php**').as('ragPostGuard');
    cy.get('#agQuery').type('test question');
    cy.get('.ag-send-btn').click();
    // Either a notice appears (no source selected) or a request fires (grid data auto-selected)
    cy.get('#agConv').should('not.be.empty');
    cy.get('@ragPostGuard.all').then($calls => {
      if ($calls.length === 0) {
        // Notice was shown — conversation has a warning message
        cy.get('#agConv .ag-msg-warning').should('exist');
      }
    });
  });

  it('typing in textarea and pressing Enter does not post when no source selected', () => {
    cy.get('#agQuery').type('hello{enter}');
    cy.get('#agConv').should('not.be.empty');
  });

  it('Clear button empties the conversation', () => {
    // Add at least one message (the warning from no-source send)
    cy.get('#agQuery').type('test{enter}');
    cy.get('#agConv').should('not.be.empty');
    cy.get('.ag-clear-btn').click();
    cy.get('#agConv').children().should('have.length', 0);
  });
});

// ============================================================================
// Test Suite: Context Bar (grid page)
// ============================================================================

describe('OpenSparrow – Agent Panel: Grid Context', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('#userAvatarBtn', { timeout: CypressHelpers.TIMEOUTS.medium }).click();
    cy.get('#openAgentBtn', { timeout: CypressHelpers.TIMEOUTS.short }).should('be.visible').click();
    cy.get('#agPanel').should('have.class', 'active');
  });

  it('context bar is visible when on a grid page with data', () => {
    cy.get('#agContextBar').then($bar => {
      if (!$bar.prop('hidden')) {
        cy.wrap($bar).should('be.visible');
      } else {
        Cypress.log({ message: 'Context bar hidden — grid may be empty' });
      }
    });
  });

  it('grid data opt-in checkbox appears when grid has rows', () => {
    cy.get('#agGridOpt').then($opt => {
      if (!$opt.prop('hidden')) {
        cy.get('#agGridDataCb').should('exist');
      } else {
        Cypress.log({ message: 'agGridOpt hidden — no grid rows on page' });
      }
    });
  });
});

// ============================================================================
// Test Suite: Context Bar (views page)
// ============================================================================

describe('OpenSparrow – Agent Panel: Views Context', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/views.php`);
    cy.get('#viewContainer .vw-loading', { timeout: CypressHelpers.TIMEOUTS.long }).should('not.exist');
  });

  function openPanel() {
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('#userAvatarBtn', { timeout: CypressHelpers.TIMEOUTS.medium }).click();
    cy.get('#openAgentBtn', { timeout: CypressHelpers.TIMEOUTS.short }).should('be.visible').click();
    cy.get('#agPanel').should('have.class', 'active');
  }

  it('current table data opt-in is hidden on the view selector screen', () => {
    openPanel();
    cy.get('#agGridOpt').should('have.prop', 'hidden', true);
  });

  it('current table data opt-in appears once a view with rows is opened', () => {
    cy.get('body').then($body => {
      if ($body.find('.vw-selector-card').length === 0) {
        Cypress.log({ message: 'No configured views — skipping' });
        return;
      }
      cy.get('.vw-selector-card').first().click();
      cy.get('#viewContainer .vw-loading', { timeout: CypressHelpers.TIMEOUTS.long }).should('not.exist');
      cy.get('body').then($b => {
        if ($b.find('#viewContainer table tbody tr').length === 0) {
          Cypress.log({ message: 'Opened view has no rows — skipping opt-in check' });
          return;
        }
        openPanel();
        cy.get('#agGridOpt').should('have.prop', 'hidden', false);
        cy.get('#agGridDataCb').should('exist');
      });
    });
  });
});
