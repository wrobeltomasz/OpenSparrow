// cypress/e2e/calendar.cy.js
// ============================================================================
// Calendar Module Tests — calendar.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: Calendar Page Structure
// ============================================================================

describe('OpenSparrow – Calendar: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
  });

  it('loads calendar page', () => {
    cy.get('#calendarMain', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('shows navigation buttons', () => {
    cy.get('#btnPrev').should('exist');
    cy.get('#btnNext').should('exist');
  });

  it('shows month/year in calendar title', () => {
    cy.get('#calendarTitle', { timeout: CypressHelpers.TIMEOUTS.medium })
      .invoke('text')
      .should('match', /\S+\s+\d{4}/);
  });

  it('renders 7 day-name headers', () => {
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-day-name')
      .should('have.length', 7);
  });

  it('renders day cells (>= 28 for any month)', () => {
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-cell:not(.empty)')
      .should('have.length.gte', 28);
  });

  it('marks today cell with .today class', () => {
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-cell.today')
      .should('have.length', 1);
  });

  it('shows sidebar menu', () => {
    cy.get('#menu').should('exist');
  });
});

// ============================================================================
// Test Suite: Calendar Navigation
// ============================================================================

describe('OpenSparrow – Calendar: Navigation', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-day-name')
      .should('have.length', 7);
  });

  it('Next button changes month title', () => {
    cy.get('#calendarTitle').invoke('text').then(before => {
      cy.get('#btnNext').click();
      cy.get('#calendarTitle')
        .invoke('text')
        .should('not.eq', before);
    });
  });

  it('Prev button changes month title', () => {
    cy.get('#calendarTitle').invoke('text').then(before => {
      cy.get('#btnPrev').click();
      cy.get('#calendarTitle')
        .invoke('text')
        .should('not.eq', before);
    });
  });

  it('Prev then Next returns to original month', () => {
    cy.get('#calendarTitle').invoke('text').then(original => {
      cy.get('#btnNext').click();
      cy.get('#btnPrev').click();
      cy.get('#calendarTitle').invoke('text').should('eq', original);
    });
  });

  it('calendar re-renders cells after navigation', () => {
    cy.get('#btnNext').click();
    cy.get('#calendarContainer')
      .find('.calendar-cell:not(.empty)')
      .should('have.length.gte', 28);
  });
});

// ============================================================================
// Test Suite: Calendar Events
// ============================================================================

describe('OpenSparrow – Calendar: Events', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-day-name')
      .should('have.length', 7);
  });

  it('event chips render if calendar is configured', () => {
    cy.get('body').then($body => {
      const hasEvents = $body.find('.calendar-event').length > 0;
      if (hasEvents) {
        cy.get('.calendar-event').should('have.length.gte', 1);
      } else {
        Cypress.log({ message: 'No calendar events — calendar may not be configured' });
      }
    });
  });

  it('event chip click navigates to edit.php', () => {
    cy.get('body').then($body => {
      if ($body.find('.calendar-event').length === 0) {
        Cypress.log({ message: 'No events — skipping click test' });
        return;
      }
      cy.get('.calendar-event').first().click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
    });
  });

  it('event chip has background color set', () => {
    cy.get('body').then($body => {
      if ($body.find('.calendar-event').length === 0) return;
      cy.get('.calendar-event').first()
        .should('have.attr', 'style')
        .and('include', 'background');
    });
  });

  it('event chip is draggable', () => {
    cy.get('body').then($body => {
      if ($body.find('.calendar-event').length === 0) return;
      cy.get('.calendar-event').first()
        .should('have.attr', 'draggable', 'true');
    });
  });
});

// ============================================================================
// Test Suite: Calendar Mobile
// ============================================================================

describe('OpenSparrow – Calendar: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
  });

  it('loads calendar on mobile viewport', () => {
    cy.get('#calendarMain', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('calendar title visible on mobile', () => {
    cy.get('#calendarTitle', { timeout: CypressHelpers.TIMEOUTS.medium })
      .should('exist');
  });

  it('navigation buttons exist on mobile', () => {
    cy.get('#btnPrev').should('exist');
    cy.get('#btnNext').should('exist');
  });
});
