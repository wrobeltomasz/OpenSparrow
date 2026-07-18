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
// Test Suite: Calendar Event Deletion (red ✕ on each chip)
// ============================================================================
// The test user has the editor role, so the delete button renders. All tests
// stub the DELETE request via cy.intercept so the real (seeded) records are
// never mutated — we only assert the client behaviour (fire, optimistic remove,
// rollback, no navigation).

describe('OpenSparrow – Calendar: Event Deletion', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-day-name')
      .should('have.length', 7);
  });

  // Runs `fn` only when at least one event chip is present, otherwise logs a skip.
  const withEvents = (fn) => {
    cy.get('body').then($body => {
      if ($body.find('.calendar-event').length === 0) {
        Cypress.log({ message: 'No calendar events — skipping deletion test' });
        return;
      }
      fn();
    });
  };

  it('renders a delete button on each event chip for editors', () => {
    withEvents(() => {
      cy.get('.calendar-event').each($chip => {
        cy.wrap($chip).find('.calendar-event-del').should('exist');
      });
      cy.get('.calendar-event').first()
        .find('.calendar-event-del')
        .should('have.text', '✕')
        .and('have.attr', 'aria-label');
    });
  });

  it('cancelling the confirm dialog fires no request and keeps the event', () => {
    withEvents(() => {
      cy.intercept('DELETE', '**/api.php*', cy.spy().as('deleteReq'));
      cy.on('window:confirm', () => false); // user clicks "Cancel"

      cy.get('.calendar-event').then($chips => {
        const before = $chips.length;
        cy.get('.calendar-event').first().find('.calendar-event-del').click({ force: true });
        cy.get('.calendar-event').should('have.length', before);
        cy.get('@deleteReq').should('not.have.been.called');
      });
    });
  });

  it('confirming the delete fires a DELETE to api.php and removes the chip optimistically', () => {
    withEvents(() => {
      cy.intercept('DELETE', '**/api.php*', { statusCode: 200, body: { ok: true } }).as('del');
      cy.on('window:confirm', () => true); // user accepts

      cy.get('.calendar-event').then($chips => {
        const before = $chips.length;
        cy.get('.calendar-event').first().find('.calendar-event-del').click({ force: true });

        cy.wait('@del').its('request.body').should(body => {
          expect(body).to.have.property('table');
          expect(body).to.have.property('id');
        });
        cy.get('.calendar-event').should('have.length', before - 1);
      });
    });
  });

  it('rolls the event back into view when the DELETE fails', () => {
    withEvents(() => {
      cy.intercept('DELETE', '**/api.php*', { statusCode: 403, body: { error: 'Forbidden' } }).as('delFail');
      cy.on('window:confirm', () => true);

      cy.get('.calendar-event').then($chips => {
        const before = $chips.length;
        cy.get('.calendar-event').first().find('.calendar-event-del').click({ force: true });
        cy.wait('@delFail');
        // Optimistically removed, then restored on the failure response.
        cy.get('.calendar-event').should('have.length', before);
      });
    });
  });

  it('clicking the delete button does not navigate to edit.php', () => {
    withEvents(() => {
      cy.intercept('DELETE', '**/api.php*', { statusCode: 200, body: { ok: true } }).as('del');
      cy.on('window:confirm', () => true);

      cy.get('.calendar-event').first().find('.calendar-event-del').click({ force: true });
      cy.wait('@del');
      cy.url().should('include', 'calendar.php').and('not.include', 'edit.php');
    });
  });
});

// ============================================================================
// Test Suite: Calendar Search & Filters
// ============================================================================

describe('OpenSparrow – Calendar: Search & Filters', () => {
  beforeEach(() => {
    cy.clearLocalStorage();
    loginAsTestUser();
    cy.visit(`${BASE}/calendar.php`);
    cy.get('#calendarContainer', { timeout: CypressHelpers.TIMEOUTS.long })
      .find('.calendar-day-name')
      .should('have.length', 7);
  });

  it('shows the search input in the header', () => {
    cy.get('#calendarSearch').should('exist').and('have.attr', 'type', 'search');
  });

  it('clear-filters button is hidden until a filter is active', () => {
    cy.get('#clearFilters').should('have.attr', 'hidden');
  });

  it('typing a search phrase reveals the clear-filters button; clearing hides it again', () => {
    cy.get('#calendarSearch').type('zzz-search-term');
    cy.get('#clearFilters').should('not.have.attr', 'hidden');
    cy.get('#clearFilters').click();
    cy.get('#calendarSearch').should('have.value', '');
    cy.get('#clearFilters').should('have.attr', 'hidden');
  });

  it('renders a visibility chip per configured calendar source', () => {
    cy.get('body').then($body => {
      if ($body.find('#calendarFilters .filter-chip').length === 0) {
        Cypress.log({ message: 'No calendar sources configured — skipping chip tests' });
        return;
      }
      cy.get('#calendarFilters .filter-chip').should('have.length.gte', 1);
      cy.get('#calendarFilters .filter-chip').first().find('.filter-dot').should('exist');
    });
  });

  it('clicking a source chip toggles its off state and the clear-filters button', () => {
    cy.get('body').then($body => {
      if ($body.find('#calendarFilters .filter-chip').length === 0) return;
      cy.get('#calendarFilters .filter-chip').first().click();
      cy.get('#calendarFilters .filter-chip').first().should('have.class', 'off');
      cy.get('#clearFilters').should('not.have.attr', 'hidden');
      cy.get('#clearFilters').click();
      cy.get('#calendarFilters .filter-chip.off').should('have.length', 0);
      cy.get('#clearFilters').should('have.attr', 'hidden');
    });
  });

  it('a hidden source chip persists across reload via localStorage', () => {
    cy.get('body').then($body => {
      if ($body.find('#calendarFilters .filter-chip').length === 0) return;
      cy.get('#calendarFilters .filter-chip').first().click();
      cy.reload();
      cy.get('#calendarContainer .calendar-day-name', { timeout: CypressHelpers.TIMEOUTS.long })
        .should('have.length', 7);
      cy.get('#calendarFilters .filter-chip').first().should('have.class', 'off');
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
