// cypress/e2e/board.cy.js
// ============================================================================
// Board (Kanban) Module Tests — board.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Board Page Structure
// ============================================================================

describe('OpenSparrow – Board: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
  });

  it('loads board page', () => {
    cy.get('#boardMain', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('shows a board title', () => {
    cy.get('#boardTitle', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist')
      .invoke('text')
      .should('have.length.gte', 1);
  });

  it('renders lanes or a configuration notice', () => {
    // .should() retries until the async board fetch + render completes;
    // a one-shot .then() raced the render because #boardContainer exists
    // in the server HTML long before lanes are painted.
    cy.get('#boardContainer', { timeout: CypressHelpers.TIMEOUTS.long }).should($c => {
      const lanes = $c.find('.board-lane').length;
      const notice = $c.find('.board-notice').length;
      expect(lanes + notice, 'lanes or notice present').to.be.greaterThan(0);
    });
  });

  it('shows sidebar menu', () => {
    cy.get('#menu').should('exist');
  });
});

// ============================================================================
// Test Suite: Board Lanes & Cards (only when configured)
// ============================================================================

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

// ============================================================================
// Test Suite: Board Search
// ============================================================================

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

      // A phrase from the first card keeps that card visible.
      cy.get('#boardSearch').type(firstTitle);
      cy.get('.board-card-title').should('contain.text', firstTitle);

      // A phrase matching nothing hides every card.
      cy.get('#boardSearch').clear().type('zzz-no-such-card-zzz');
      cy.get('.board-card').should('have.length', 0);

      // Clearing the box restores the full board.
      cy.get('#boardSearch').clear();
      cy.get('.board-card').should('have.length', total);
    });
  });
});

// ============================================================================
// Test Suite: Board Mobile
// ============================================================================

describe('OpenSparrow – Board: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/board.php`);
  });

  it('loads board on mobile viewport', () => {
    cy.get('#boardMain', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('board title visible on mobile', () => {
    cy.get('#boardTitle', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });
});

// ============================================================================
// Test Suite: Board API contract (api.php?api=board)
// ============================================================================

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

      // When the board is configured, lanes expose value/label/color and
      // every card carries an id + status.
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
      // 403 = CSRF rejected (expected); other 4xx also acceptable as a rejection.
      expect(status).to.be.gte(400);
    });
  });
});
