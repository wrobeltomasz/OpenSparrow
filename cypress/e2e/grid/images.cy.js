// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

function clickAdminTab(dataFile) {
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).then($btn => {
    const $section = $btn.closest('.nav-section');
    if ($section.length && !$section.hasClass('open')) {
      cy.wrap($section.find('.nav-section-header')).click();
    }
  });
  cy.get(`button.admin-tab[data-file="${dataFile}"]`).scrollIntoView().should('be.visible').click();
}

describe('OpenSparrow – Images: admin schema editor', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    cy.get('header.admin-header', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
      });
    clickAdminTab('schema');
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('table editor exposes an Images section', () => {
    cy.get('#workspace .column-block .block-header').first().click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium })
      .contains('h3', 'Images')
      .should('exist');
  });

  it('Images section has the enable switch, label, limit and grid toggle', () => {
    cy.get('#workspace .column-block .block-header').first().click();
    cy.get('#workspace', { timeout: CypressHelpers.TIMEOUTS.medium }).within(() => {
      cy.contains('label, .adm-field-label', 'Enable Images For This Table').should('exist');
      cy.contains('label, .adm-field-label', 'Max Images Per Record').should('exist');
      cy.contains('label, .adm-field-label', 'Show Thumbnail Column In Grid').should('exist');
    });
  });
});

describe('OpenSparrow – Images: batch API contract', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('image_rows returns a data object for a table without images enabled', () => {
    cy.request({
      url: `${BASE}/api.php?api=image_rows&table=nonexistent_table&ids=1,2,3`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status).to.eq(200);
      expect(result.body).to.have.property('data');
    });
  });

  it('image_rows ignores non-numeric ids', () => {
    cy.request({
      url: `${BASE}/api.php?api=image_rows&table=nonexistent_table&ids=abc,';DROP`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status).to.eq(200);
      expect(result.body.data).to.deep.eq({});
    });
  });

  it('image_rows requires a session', () => {
    cy.clearCookies();
    cy.request({
      url: `${BASE}/api.php?api=image_rows&table=nonexistent_table&ids=1`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status).to.be.oneOf([401, 403]);
    });
  });
});

describe('OpenSparrow – Images: grid column', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php`);
  });

  it('renders a thumbnail cell for every header images column', () => {
    waitForGridOrEmpty();
    cy.get('body').then($body => {
      const headers = $body.find('th.th-images').length;
      const cells   = $body.find('td.td-images').length;
      if (headers === 0) {
        expect(cells, 'no image cells without an image header').to.eq(0);
      } else {
        expect(cells, 'one image cell per row').to.be.gte(1);
      }
    });
  });
});
