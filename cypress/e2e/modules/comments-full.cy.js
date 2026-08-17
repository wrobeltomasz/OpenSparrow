// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies';

describe('OpenSparrow – Comments: My Comments panel (full, requires PHP_CLI_SERVER_WORKERS)', () => {
  const openPanel = () => {
    cy.get('[data-cy=user-avatar]').click();
    cy.get('[data-cy=my-comments]').click({ force: true });
    cy.get('#myCommentsPanel', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('have.class', 'active');
  };

  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
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
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
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
