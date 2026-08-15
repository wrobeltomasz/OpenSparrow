// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

describe('OpenSparrow – Dashboard: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
  });

  it('loads dashboard page', () => {
    cy.get('#dashboardMain', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
    assertSidebarPresent();
  });

  it('has dashboard section container', () => {
    cy.get('#dashboardSection').should('exist');
  });

  it('has notifications bell', () => {
    cy.get('[data-cy=notifications], .notifications-wrapper').should('exist');
  });

  it('header contains user avatar button', () => {
    cy.get('#userAvatarBtn, [data-cy=user-avatar]').should('exist');
  });
});

describe('OpenSparrow – Dashboard: Widget Loading', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('dashboard section transitions out of loading state', () => {
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).should($sec => {
      const loading = $sec.find('.dash-loading').length;
      const widgets = $sec.find('.dash-widget').length;
      const error   = $sec.find('.dash-error').length;

      if (loading > 0 && widgets === 0 && error === 0) {
        throw new Error('Dashboard still in loading state');
      }
    });
  });

  it('renders widgets or error after load', () => {
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).should($sec => {
      const hasWidgets = $sec.find('.dash-widget').length > 0;
      const hasError   = $sec.find('.dash-error').length > 0;
      const isEmpty    = $sec.children().length === 0;
      expect(hasWidgets || hasError || isEmpty, 'section rendered').to.be.true;
    });
  });

  it('if widgets configured: each has a title', () => {
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).then($sec => {
      if ($sec.find('.dash-widget').length === 0) {
        Cypress.log({ message: 'No widgets configured — skipping title check' });
        return;
      }
      cy.get('.dash-widget .dash-title')
        .should('have.length.gte', 1)
        .first()
        .invoke('text')
        .should('not.be.empty');
    });
  });

  it('if widgets configured: widget has data-w attribute', () => {
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).then($sec => {
      if ($sec.find('.dash-widget').length === 0) return;
      cy.get('.dash-widget').first().should('have.attr', 'data-w');
    });
  });

  it('if error: .dash-error message is not empty', () => {
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).then($sec => {
      const $err = $sec.find('.dash-error');
      if ($err.length === 0) return;
      cy.wrap($err).first().invoke('text').should('not.be.empty');
    });
  });
});

describe('OpenSparrow – Dashboard: Period Filter', () => {
  beforeEach(() => {
    cy.clearLocalStorage();
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('shows the period select with the expected options', () => {
    cy.get('#dashDateFilter').should('exist');
    cy.get('#dashDateFilter option').should('have.length', 5);
    ['all', 'today', '7d', '30d', 'this_month'].forEach(val => {
      cy.get(`#dashDateFilter option[value="${val}"]`).should('exist');
    });
  });

  it('period filter follows the clear-filters contract', () => {
    assertClearFiltersContract({
      activate: () => cy.get('#dashDateFilter').select('7d'),
      settle: () => cy.get('#dashboardSection .dash-loading', {
        timeout: CypressHelpers.TIMEOUTS.long,
      }).should('not.exist'),
      reset: () => cy.get('#dashDateFilter').should('have.value', 'all'),
    });
  });
});

describe('OpenSparrow – Dashboard: Widget Visibility Filters', () => {
  beforeEach(() => {
    cy.clearLocalStorage();
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
  });

  it('renders a visibility chip per configured widget', () => {
    cy.get('body').then($body => {
      if ($body.find('#dashboardFilters .filter-chip').length === 0) {
        Cypress.log({ message: 'No widgets configured — skipping chip tests' });
        return;
      }
      cy.get('#dashboardFilters .filter-chip').should('have.length.gte', 1);
      cy.get('#dashboardFilters .filter-chip').first().find('.filter-dot').should('exist');
    });
  });

  it('hiding a widget chip follows the clear-filters contract and restores every widget', () => {
    cy.get('body').then($body => {
      if ($body.find('#dashboardFilters .filter-chip').length === 0) {
        Cypress.log({ message: 'No widgets configured — skipping chip tests' });
        return;
      }
      cy.get('.dash-widget').then($widgets => {
        const total = $widgets.length;
        assertClearFiltersContract({
          activate: () => {
            cy.get('#dashboardFilters .filter-chip').first().click();
            cy.get('#dashboardFilters .filter-chip').first().should('have.class', 'off');
            cy.get('.dash-widget').should('have.length', total - 1);
          },
          reset: () => {
            cy.get('#dashboardFilters .filter-chip.off').should('have.length', 0);
            cy.get('.dash-widget').should('have.length', total);
          },
        });
      });
    });
  });

  it('a hidden widget chip persists across reload via localStorage', () => {
    cy.get('body').then($body => {
      if ($body.find('#dashboardFilters .filter-chip').length === 0) return;
      cy.get('#dashboardFilters .filter-chip').first().click();
      cy.reload();
      cy.get('#dashboardSection', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
      cy.get('#dashboardFilters .filter-chip', { timeout: CypressHelpers.TIMEOUTS.long })
        .first()
        .should('have.class', 'off');
    });
  });
});

describe('OpenSparrow – Dashboard: Navigation', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
  });

  it('sidebar menu has at least one link', () => {
    cy.get('#menu .menu-list a, #menu a').should('have.length.gte', 1);
  });

  it('clicking first table link navigates to grid', () => {
    cy.get('#menu .menu-list a[href*="table="]').then($links => {
      if ($links.length === 0) {
        Cypress.log({ message: 'No table links in menu — skipping' });
        return;
      }
      cy.wrap($links).first().click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'table=');
      cy.get('#grid, [data-cy=grid]', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    });
  });

  it('sidebar toggle button exists', () => {
    cy.get('#sidebarToggle, [data-cy=sidebar-toggle]').should('exist');
  });
});

describe('OpenSparrow – Dashboard: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
  });

  it('loads on mobile viewport', () => {
    cy.get('#dashboardMain', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('sidebar toggle visible on mobile', () => {
    cy.get('#sidebarToggle').should('exist');
  });

  it('dashboard section renders on mobile', () => {
    cy.get('#dashboardSection').should('exist');
  });
});
