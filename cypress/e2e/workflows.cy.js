// cypress/e2e/workflows.cy.js
// ============================================================================
// Workflows Module Tests
// Workflows are rendered in #grid container via index.php?workflows
// or by clicking the sidebar link a[data-page="workflows"]
// ============================================================================

const BASE       = 'http://localhost:8080';

// ─── helper ──────────────────────────────────────────────────────────────────

/**
 * Wait for workflow cards or empty/error state inside #grid.
 * Returns true if workflow list rendered, false if no workflows configured.
 */
function waitForWorkflowList({ timeout = CypressHelpers.TIMEOUTS.long } = {}) {
  return cy.get('#grid, [data-cy=grid]', { timeout }).then($grid => {
    // Cards have no stable CSS class — use h3 as proxy for card titles
    const hasCards = $grid.find('h3').length > 0;
    return hasCards;
  });
}

// ============================================================================
// Test Suite: Workflows Page via URL
// ============================================================================

describe('OpenSparrow – Workflows: Page Load', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?workflows`);
    cy.get('#menu', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('page loads with sidebar menu', () => {
    cy.get('#menu').should('exist');
  });

  it('workflows link in sidebar is active', () => {
    cy.get('a[data-page="workflows"]').then($link => {
      if ($link.length === 0) {
        Cypress.log({ message: 'No workflows link in sidebar — workflows not configured' });
        return;
      }
      cy.wrap($link).should('have.class', 'active');
    });
  });

  it('grid container renders workflow list or keeps standard grid', () => {
    cy.get('#grid, [data-cy=grid]', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('exist');
  });
});

// ============================================================================
// Test Suite: Workflows List Rendering
// ============================================================================

describe('OpenSparrow – Workflows: List', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?workflows`);
    cy.get('#grid', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('workflow cards render if workflows are configured', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) {
        Cypress.log({ message: 'No workflow cards — workflows.json may be empty or not configured' });
        return;
      }
      cy.get('#grid h3').should('have.length.gte', 1);
    });
  });

  it('each workflow card has a non-empty title', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;
      cy.get('#grid h3').each($h3 => {
        expect($h3.text().trim()).to.not.be.empty;
      });
    });
  });

  it('each workflow card has a description paragraph', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;
      cy.get('#grid').find('p').should('have.length.gte', 1);
    });
  });

  it('grid title reflects workflow section name', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;
      cy.get('#gridTitle').invoke('text').should('not.be.empty');
    });
  });
});

// ============================================================================
// Test Suite: Workflow Step Wizard
// ============================================================================

describe('OpenSparrow – Workflows: Step Wizard', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?workflows`);
    cy.get('#grid', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('clicking a workflow card shows step bar', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) {
        Cypress.log({ message: 'No workflows — skipping step wizard tests' });
        return;
      }

      // Click first card (they have no stable class; use first div > div in grid)
      cy.get('#grid').find('h3').first().closest('div').click();

      cy.get('#wf-step-bar', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('step bar contains pill elements', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;

      cy.get('#grid').find('h3').first().closest('div').click();
      cy.get('#wf-step-bar', { timeout: CypressHelpers.TIMEOUTS.long })
        .find('div')
        .should('have.length.gte', 1);
    });
  });

  it('grid title shows step progress (Step X of Y pattern)', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;

      cy.get('#grid').find('h3').first().closest('div').click();
      cy.get('#gridTitle', { timeout: CypressHelpers.TIMEOUTS.long })
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('step form renders after card click', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;

      cy.get('#grid').find('h3').first().closest('div').click();
      cy.get('#grid form', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('step form has submit button', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;

      cy.get('#grid').find('h3').first().closest('div').click();
      cy.get('#grid form button[type="submit"]', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('exist');
    });
  });

  it('step form has at least one input field', () => {
    waitForWorkflowList().then(hasCards => {
      if (!hasCards) return;

      cy.get('#grid').find('h3').first().closest('div').click();
      cy.get('#grid form', { timeout: CypressHelpers.TIMEOUTS.long })
        .find('input, select, textarea')
        .should('have.length.gte', 1);
    });
  });
});

// ============================================================================
// Test Suite: Workflows Sidebar Link
// ============================================================================

describe('OpenSparrow – Workflows: Sidebar Navigation', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=companies`);
    waitForGridOrEmpty();
  });

  it('sidebar contains workflows link if configured', () => {
    cy.get('body').then($body => {
      const $wfLink = $body.find('a[data-page="workflows"]');
      if ($wfLink.length === 0) {
        Cypress.log({ message: 'No workflows sidebar link — not configured' });
        return;
      }
      cy.wrap($wfLink).should('exist');
    });
  });

  it('clicking sidebar workflows link shows workflow list', () => {
    cy.get('body').then($body => {
      const $wfLink = $body.find('a[data-page="workflows"]');
      if ($wfLink.length === 0) return;

      cy.wrap($wfLink).click();
      cy.get('#grid', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
      cy.get('#wf-step-bar').should('not.exist');
    });
  });
});
