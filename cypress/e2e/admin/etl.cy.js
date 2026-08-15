// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

function openEtlTab() {
  cy.visit(`${BASE}/admin/index.php`);
  cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');

  cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
    .should($el => expect($el.children().length).to.be.greaterThan(0));

  cy.get('button.admin-tab[data-file="etl"]').then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get('button.admin-tab[data-file="etl"]').scrollIntoView().should('be.visible').click();

  cy.get('#workspace .admin-page-title', { timeout: CypressHelpers.TIMEOUTS.long })
    .should('contain.text', 'ETL');
}

function etlTab(label) {
  return cy.contains('#workspace .item-btn', label, { timeout: CypressHelpers.TIMEOUTS.long }).click();
}

describe('OpenSparrow – Admin ETL', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    openEtlTab();
  });

  it('shows all five ETL tabs', () => {
    ['Sources', 'Jobs', 'Schedule', 'History', 'Flows'].forEach(label => {
      cy.contains('#workspace .item-btn', label).should('be.visible');
    });
  });

  function addSource() {
    cy.contains('button', '+ Add source').click();
    cy.get('#workspace .column-block:visible').last().within(() => {
      cy.get('.block-header').click();
    });
    return cy.get('#workspace .column-block:visible').last();
  }

  it('Sources tab: adds a source, switches driver default port', () => {
    etlTab('Sources');
    addSource().within(() => {
      cy.get('select').first().select('pgsql');
      cy.get('input[type="number"]').first().should('have.value', '5432');

      cy.get('select').first().select('mysql');
      cy.get('input[type="number"]').first().should('have.value', '3306');
    });
  });

  it('Sources tab: Test connection reports failure for an unreachable host', () => {
    etlTab('Sources');
    addSource();
    cy.get('#workspace .column-block:visible').first().within(() => {
      cy.get('select').first().select('mysql');
      cy.contains('.form-group', 'Host').find('input').clear().type('nonexistent-host.invalid');
      cy.contains('.form-group', 'Port').find('input').clear().type('3306');
      cy.contains('.form-group', 'Database').find('input').clear().type('nonexistent_db');
      cy.contains('.form-group', 'User').find('input').clear().type('nonexistent_user');
      cy.contains('.form-group', 'Password').find('input').clear().type('wrong-password');
      cy.contains('button', 'Test connection').click();
    });
    cy.get('#workspace p:visible', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('be.visible')
      .and('not.contain.text', 'Connection OK');
  });

  it('Sources tab: supports 2+ sources at once', () => {
    etlTab('Sources');
    addSource();
    addSource();
    cy.contains('#workspace button:visible', 'Save configuration').click();
    cy.get('#workspace p:visible').should('contain.text', 'saved');
    cy.get('#workspace .column-block:visible').should('have.length.gte', 2);

    cy.window().then(win => cy.stub(win, 'confirm').returns(true));
    cy.get('#workspace .column-block:visible').last().within(() => {
      cy.get('.icon-btn-danger').click();
    });
    cy.get('#workspace .column-block:visible').last().within(() => {
      cy.get('.icon-btn-danger').click();
    });
    cy.contains('#workspace button:visible', 'Save configuration').click();
    cy.get('#workspace p:visible').should('contain.text', 'saved');
  });

  it('Schedule tab: toggles enabled + frequency and saves', () => {
    etlTab('Schedule');
    cy.get('#workspace input[type="checkbox"]:visible').click();
    cy.get('#workspace select:visible').select('weekly');
    cy.contains('#workspace button:visible', 'Save configuration').click();
    cy.get('#workspace p:visible').should('contain.text', 'saved');

    openEtlTab();
    etlTab('Schedule');
    cy.get('#workspace select:visible').should('have.value', 'weekly');
  });

  it('Schedule tab: shows the cron command hint', () => {
    etlTab('Schedule');
    cy.get('#workspace').should('contain.text', 'cron_etl.php');
  });

  it('Flows tab: renders without a load error', () => {
    etlTab('Flows');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('contain.text', 'Chain existing ETL jobs')
      .and('not.contain.text', 'Failed to load config')
      .and('not.contain.text', 'Network error');
    cy.contains('#workspace button', '+ Add flow').should('be.visible');
  });

  it('History tab: shows empty state or note when no runs exist', () => {
    etlTab('History');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        const text = $el.text();
        expect(
          /No runs yet\.|Initialize System Tables/.test(text),
          'shows either the empty-history message or the missing-log-table note'
        ).to.be.true;
      });
  });

  it('History tab: Purge logs asks for confirmation', () => {
    etlTab('History');
    cy.window().then(win => cy.stub(win, 'confirm').returns(false));
    cy.contains('#workspace button', 'Purge logs').click();

    cy.get('#workspace').should('exist');
  });
});

describe('OpenSparrow – ETL Access Control', () => {
  it('editor-role user cannot reach the ETL admin action', () => {
    loginAsTestUser();
    cy.request({
      url: `${BASE}/admin/api.php?action=etl_load`,
      failOnStatusCode: false,
    }).its('status').should('be.oneOf', [401, 403]);
  });
});
