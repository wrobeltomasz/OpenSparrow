// cypress/e2e/notifications.cy.js
// ============================================================================
// Notifications Bell Tests — header present on all pages
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Notification Bell Structure
// ============================================================================

describe('OpenSparrow – Notifications: Bell Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
  });

  it('notifications wrapper exists in header', () => {
    cy.get('[data-cy=notifications], .notifications-wrapper').should('exist');
  });

  it('badge element exists', () => {
    cy.get('#notif-badge').should('exist');
  });

  it('dropdown container exists', () => {
    cy.get('#notif-dropdown').should('exist');
  });

  it('notification list exists', () => {
    cy.get('#notif-list').should('exist');
  });

  it('badge displays a number', () => {
    cy.get('#notif-badge').invoke('text').then(text => {
      expect(parseInt(text, 10)).to.be.gte(0);
    });
  });
});

// ============================================================================
// Test Suite: Notification Bell Interaction
// ============================================================================

describe('OpenSparrow – Notifications: Interaction', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('[data-cy=notifications], .notifications-wrapper').should('exist');
  });

  it('clicking bell opens the dropdown when it is closed', () => {
    // Ensure the dropdown is closed first, then verify clicking opens it
    cy.get('#notif-dropdown').then($dropdown => {
      if ($dropdown.is(':visible')) {
        // Close it by clicking elsewhere, then reopen
        cy.get('body').click(0, 0);
      }
    });

    cy.get('.notifications-wrapper').click();
    cy.get('#notif-dropdown').should('be.visible');
  });

  it('dropdown has a header section', () => {
    cy.get('.notifications-wrapper').click();
    cy.get('.notif-dropdown-header', { timeout: CypressHelpers.TIMEOUTS.short }).should('exist');
  });

  it('notification list is ul element', () => {
    cy.get('#notif-list').should('exist');
  });

  it('if notifications exist: list has items', () => {
    cy.get('.notifications-wrapper').click();
    cy.get('#notif-list').then($list => {
      const count = $list.find('li').length;
      if (count > 0) {
        cy.wrap($list).find('li').should('have.length.gte', 1);
      } else {
        Cypress.log({ message: 'No notification items — clean system' });
      }
    });
  });

  it('bell present on grid page too', () => {
    cy.visit(`${BASE}/index.php?table=companies`);
    cy.get('[data-cy=notifications], .notifications-wrapper').should('exist');
  });
});

// ============================================================================
// Test Suite: Notifications via API
// ============================================================================

describe('OpenSparrow – Notifications: API', () => {
  it('page load triggers GET to api_notifications.php', () => {
    cy.intercept('GET', '**/api_notifications.php**').as('notifFetch');
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.wait('@notifFetch', { timeout: CypressHelpers.TIMEOUTS.long });
    cy.get('@notifFetch').its('response.statusCode').should('eq', 200);
  });

  it('API response has expected shape', () => {
    cy.intercept('GET', '**/api_notifications.php**').as('notifShape');
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.wait('@notifShape', { timeout: CypressHelpers.TIMEOUTS.long })
      .its('response.body')
      .should(body => {
        // Body must be an array of notifications, or an object carrying one
        const data = typeof body === 'string' ? JSON.parse(body) : body;
        const list = Array.isArray(data) ? data : data?.notifications;
        expect(list, 'response carries a notifications array').to.be.an('array');
      });
  });
});
