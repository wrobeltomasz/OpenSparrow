// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

describe('OpenSparrow – Board: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
  });

  it('loads board page', () => {
    cy.get('#boardMain', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
    assertSidebarPresent();
  });

  it('shows a board title', () => {
    cy.get('#boardTitle', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist')
      .invoke('text')
      .should('have.length.gte', 1);
  });

  it('renders lanes or a configuration notice', () => {
    cy.get('#boardContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should($c => {
      const lanes = $c.find('.board-lane').length;
      const notice = $c.find('.board-notice').length;
      expect(lanes + notice, 'lanes or notice present').to.be.greaterThan(0);
    });
  });
});

describe('OpenSparrow – Board: Lanes & Cards', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
    cy.get('#boardContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('each lane has a header with a title and a count badge', () => {
    cy.get('body').then($body => {
      if ($body.find('.board-lane').length === 0) {
        Cypress.log({ message: 'Board not configured — skipping lane checks' });
        return;
      }
      cy.get('.board-lane').first().within(() => {
        cy.get('.board-lane-title').should('exist');
        cy.get('.board-lane-count').should('exist');
      });
    });
  });

  it('cards are draggable for the editor role', () => {
    cy.get('body').then($body => {
      if ($body.find('.board-card').length === 0) {
        Cypress.log({ message: 'No cards on the board — skipping drag attribute check' });
        return;
      }
      cy.get('.board-card').first().should('have.attr', 'draggable', 'true');
    });
  });

  it('a card has a colored left border accent', () => {
    cy.get('body').then($body => {
      if ($body.find('.board-card').length === 0) return;
      cy.get('.board-card').first()
        .should('have.attr', 'style')
        .and('include', 'border-left');
    });
  });

  it('clicking a card opens the record in edit.php', () => {
    cy.get('body').then($body => {
      if ($body.find('.board-card').length === 0) {
        Cypress.log({ message: 'No cards — skipping click test' });
        return;
      }
      cy.get('.board-card').first().click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
    });
  });
});

describe('OpenSparrow – Board: Search', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
    cy.get('#boardContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('shows the search input above the lanes', () => {
    cy.get('#boardSearch').should('exist').and('have.attr', 'type', 'search');
  });

  it('typing a phrase hides non-matching cards and clearing restores them', () => {
    cy.get('body').then($body => {
      const $cards = $body.find('.board-card');
      if ($cards.length === 0) {
        Cypress.log({ message: 'No cards on the board — skipping search test' });
        return;
      }
      const total = $cards.length;
      const firstTitle = $cards.first().find('.board-card-title').text().trim();

      cy.get('#boardSearch').type(firstTitle);
      cy.get('.board-card-title').should('contain.text', firstTitle);

      cy.get('#boardSearch').clear().type('zzz-no-such-card-zzz');
      cy.get('.board-card').should('have.length', 0);

      cy.get('#boardSearch').clear();
      cy.get('.board-card').should('have.length', total);
    });
  });
});

describe('OpenSparrow – Board: Filters', () => {
  beforeEach(() => {
    cy.clearLocalStorage();
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
    cy.get('#boardContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('renders a visibility chip per lane', () => {
    cy.get('body').then($body => {
      if ($body.find('#boardFilters .filter-chip').length === 0) {
        Cypress.log({ message: 'Board not configured — skipping chip tests' });
        return;
      }
      cy.get('#boardFilters .filter-chip').should('have.length.gte', 1);
      cy.get('#boardFilters .filter-chip').first().find('.filter-dot').should('exist');
    });
  });

  it('hiding a lane chip follows the clear-filters contract and restores every lane', () => {
    cy.get('body').then($body => {
      if ($body.find('#boardFilters .filter-chip').length === 0) return;
      cy.get('.board-lane').then($lanes => {
        const totalLanes = $lanes.length;
        assertClearFiltersContract({
          activate: () => {
            cy.get('#boardFilters .filter-chip').first().click();
            cy.get('#boardFilters .filter-chip').first().should('have.class', 'off');
            cy.get('.board-lane').should('have.length', totalLanes - 1);
          },
          reset: () => {
            cy.get('#boardFilters .filter-chip.off').should('have.length', 0);
            cy.get('.board-lane').should('have.length', totalLanes);
          },
        });
      });
    });
  });
});

describe('OpenSparrow – Board: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
  });

  it('board and title render on mobile', () => {
    assertMobileSmoke(['#boardMain', '#boardTitle']);
  });
});

describe('OpenSparrow – Board: API contract', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('returns the expected board payload shape', () => {
    cy.request({
      url: `${BASE}/api.php?api=board`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(({ status, body }) => {
      expect(status).to.eq(200);
      expect(body).to.have.property('menu_name');
      expect(body).to.have.property('configured');
      expect(body).to.have.property('columns');
      expect(body).to.have.property('cards');
      expect(body.columns).to.be.an('array');
      expect(body.cards).to.be.an('array');

      if (body.configured) {
        expect(body).to.have.property('status_column').that.is.a('string');
        if (body.columns.length > 0) {
          expect(body.columns[0]).to.include.all.keys('value', 'label', 'color');
        }
        body.cards.forEach(card => {
          expect(card).to.have.property('id');
          expect(card).to.have.property('status');
        });
      }
    });
  });

  it('rejects a move_card request with a missing CSRF token', () => {
    cy.request({
      method: 'POST',
      url: `${BASE}/api.php`,
      failOnStatusCode: false,
      body: { api: 'board', action: 'move_card', table: 'deals', id: 1, newStatus: 'Won' },
    }).then(({ status }) => {
      expect(status).to.be.gte(400);
    });
  });
});
