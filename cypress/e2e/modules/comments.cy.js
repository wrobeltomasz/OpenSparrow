// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/modules/comments.cy.js
// ============================================================================
// Comments Module Tests — edit.php Comments tab
// ============================================================================

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies';

// ============================================================================
// Test Suite: Comments Tab Structure
// ============================================================================

describe('OpenSparrow – Comments: Tab Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
    });
  });

  it('edit.php shows Comments tab button', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').should('exist');
    });
  });

  it('clicking Comments tab activates it', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.wrap($btn).should('have.class', 'active');
      });
    });
  });

  it('Comments panel #c-panel renders after tab click', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
      });
    });
  });

  it('Comments panel has thread container', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel .c-thread', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
      });
    });
  });

  it('thread shows messages or empty state after load', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel .c-thread', { timeout: CypressHelpers.TIMEOUTS.medium })
          .should($thread => {
            const hasMsgs  = $thread.find('.c-msg').length > 0;
            const hasEmpty = $thread.find('.c-empty').length > 0;
            expect(hasMsgs || hasEmpty, 'thread should have messages or empty state').to.be.true;
          });
      });
    });
  });

  it('editor role: textarea and send button present', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
          const hasInput = $panel.find('.c-input').length > 0;
          if (!hasInput) {
            Cypress.log({ message: 'No .c-input — user is viewer; skipping' });
            return;
          }
          cy.get('.c-input').should('exist');
          cy.get('.c-send-btn').should('exist');
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: Comments Add
// ============================================================================

describe('OpenSparrow – Comments: Add Comment', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length > 0) cy.wrap($btn).click();
      });
    });
  });

  it('typing in textarea updates its value', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-input').length === 0) return;
        cy.get('.c-input').type('Test comment from Cypress');
        cy.get('.c-input').should('have.value', 'Test comment from Cypress');
      });
    });
  });

  it('send button not disabled when textarea has content', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-input').length === 0) return;
        cy.get('.c-input').type('Hello');
        cy.get('.c-send-btn').should('not.be.disabled');
      });
    });
  });

  it('submitting comment appends .c-msg to thread and clears input', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-input').length === 0) return;
        const msg = `cypress-${Date.now()}`;
        cy.get('.c-input').type(msg);
        cy.get('.c-send-btn').click();
        cy.get('#c-panel .c-thread .c-msg', { timeout: CypressHelpers.TIMEOUTS.long })
          .should('have.length.gte', 1);
        cy.get('.c-input').should('have.value', '');
      });
    });
  });

  it('Ctrl+Enter posts comment', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-input').length === 0) return;
        cy.get('.c-input').type(`ctrlenter-${Date.now()}{ctrl+enter}`);
        cy.get('#c-panel .c-thread .c-msg', { timeout: CypressHelpers.TIMEOUTS.long })
          .should('have.length.gte', 1);
      });
    });
  });
});

// ============================================================================
// Test Suite: Comments Delete
// ============================================================================

describe('OpenSparrow – Comments: Delete Comment', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length > 0) cy.wrap($btn).click();
      });
    });
  });

  it('cancelling delete confirm keeps message intact', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-msg-del-btn').length === 0) return;
        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(false);
          cy.get('.c-msg-del-btn').first().click({ force: true });
          cy.get('.c-thread .c-msg').first().should('not.have.class', 'c-msg-deleted');
        });
      });
    });
  });

  it('confirming delete adds .c-msg-deleted to message', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        // Add comment first so we own a deletable message
        if ($panel.find('.c-input').length === 0) return;
        cy.get('.c-input').type(`del-test-${Date.now()}`);
        cy.get('.c-send-btn').click();
        cy.get('.c-thread .c-msg', { timeout: CypressHelpers.TIMEOUTS.long })
          .should('have.length.gte', 1);

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);
          cy.get('.c-msg-del-btn').last().click({ force: true });
          cy.get('.c-thread .c-msg-deleted', { timeout: CypressHelpers.TIMEOUTS.medium })
            .should('exist');
        });
      });
    });
  });

  it('deleted message hides delete button', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;
      cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
        if ($panel.find('.c-input').length === 0) return;
        cy.get('.c-input').type(`delbtn-${Date.now()}`);
        cy.get('.c-send-btn').click();
        cy.get('.c-thread .c-msg', { timeout: CypressHelpers.TIMEOUTS.long })
          .should('have.length.gte', 1);

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);
          cy.get('.c-msg-del-btn').last().click({ force: true });
          cy.get('.c-thread .c-msg-deleted').last()
            .find('.c-msg-del-btn')
            .should('not.exist');
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: My Comments panel (avatar menu)
// ============================================================================

describe('OpenSparrow – Comments: My Comments panel', () => {
  const openPanel = () => {
    cy.get('[data-cy=user-avatar]').click();
    cy.get('[data-cy=my-comments]').should('be.visible');
    cy.get('[data-cy=my-comments]').click();
    cy.get('#myCommentsPanel', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('have.class', 'active');
  };

  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('avatar menu exposes the My comments item', () => {
    cy.get('[data-cy=user-avatar]').click();
    cy.get('[data-cy=my-comments]').should('be.visible');
  });

  it('panel opens and calls api/comments.php?action=mine', () => {
    cy.intercept('GET', '**/api/comments.php?action=mine').as('mineFetch');
    openPanel();
    cy.wait('@mineFetch', { timeout: CypressHelpers.TIMEOUTS.long })
      .its('response.body.success').should('eq', true);
  });

  it('panel shows own comments or the empty state', () => {
    openPanel();
    cy.get('#myCommentsPanel .bp-body', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($body => {
        const hasItems = $body.find('.um-item').length > 0;
        const hasEmpty = $body.find('.dc-empty').length > 0;
        expect(hasItems || hasEmpty, 'panel shows a list or an empty state').to.be.true;
      });
  });

  it('a listed comment links to its record comment tab', () => {
    // Guarantee at least one own comment before opening the panel.
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
          if ($panel.find('.c-input').length === 0) return;
          cy.get('.c-input').type(`mine-${Date.now()}`);
          cy.get('.c-send-btn').click();
          cy.get('#c-panel .c-thread .c-msg', { timeout: CypressHelpers.TIMEOUTS.long })
            .should('have.length.gte', 1);

          openPanel();
          cy.get('#myCommentsPanel .um-item', { timeout: CypressHelpers.TIMEOUTS.long })
            .should('have.length.gte', 1);
          cy.get('#myCommentsPanel .um-item').first().find('.um-item-link')
            .should('have.attr', 'href')
            .and('match', /edit\.php\?table=[^&]+&id=\d+#tab-comments$/);
        });
      });
    });
  });
});

// ============================================================================
// Test Suite: Comments API Integration
// ============================================================================

describe('OpenSparrow – Comments: API Integration', () => {
  it('activating Comments tab triggers GET to api/comments.php', () => {
    cy.intercept('GET', '**/api/comments.php**').as('commentsFetch');
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.wait('@commentsFetch', { timeout: CypressHelpers.TIMEOUTS.long });
        cy.get('#c-panel .c-thread', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
      });
    });
  });

  it('posting comment triggers POST to api/comments.php', () => {
    cy.intercept('POST', '**/api/comments.php**').as('commentPost');
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#c-panel', { timeout: CypressHelpers.TIMEOUTS.medium }).then($panel => {
          if ($panel.find('.c-input').length === 0) return;
          cy.get('.c-input').type(`api-test-${Date.now()}`);
          cy.get('.c-send-btn').click();
          cy.wait('@commentPost', { timeout: CypressHelpers.TIMEOUTS.long });
        });
      });
    });
  });
});
