// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';
const TABLE = 'companies';

function expectCreateForm({ timeout = CypressHelpers.TIMEOUTS.long } = {}) {
  return cy.document({ timeout }).then(doc => {
    const deadline = Date.now() + timeout;

    const check = () => {
      const form = doc.querySelector('form.editor-form');
      if (form) {
        return cy.wrap(form, { log: false }).should('exist');
      }
      if (Date.now() > deadline) {
        const alert = (doc.querySelector('.form-alert')?.textContent || '').trim();
        throw new Error(
          `form.editor-form never rendered at ${doc.location.href}`
          + (alert
            ? ` — server error on page: "${alert}"`
            : ` — page text: "${(doc.body?.textContent || '').trim().slice(0, 200)}"`)
        );
      }
      return cy.wait(200, { log: false }).then(check);
    };

    return check();
  });
}

describe('OpenSparrow – DB row counts: companies', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('baseline: demo data is present', () => {
    cy.dbCount(TABLE).then(count => {
      expect(count, 'companies rows').to.be.greaterThan(0);
    });
  });

  it('creating a fully filled record increases the row count by exactly 1', () => {
    const stamp = Date.now();

    const company = {
      name:     `cypress-co-${stamp}`,
      industry: 'Technology',
      website:  `cypress-${stamp}.example.com`,
      phone:    '+48-555-0142',
      email:    `cypress-${stamp}@example.com`,
    };

    cy.dbCount(TABLE).then(before => {
      cy.log(`**companies before create: ${before}**`);

      cy.visit(`${BASE}/create.php?table=${TABLE}`);
      expectCreateForm();

      Object.entries(company).forEach(([field, value]) => {
        cy.get(`input[name="${field}"]`).clear().type(value);
      });

      cy.get('button[type="submit"].btn-save').click();

      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

      cy.dbCount(TABLE).then(after => {
        cy.log(`**companies after create: ${after}**`);
        expect(after, 'count after create').to.eq(before + 1);
      });
    });
  });

  it('every field of the created record is persisted', () => {
    const stamp = Date.now();
    const company = {
      name:     `cypress-full-${stamp}`,
      industry: 'Cloud Services',
      website:  `cypress-full-${stamp}.example.com`,
      phone:    '+48-555-0199',
      email:    `cypress-full-${stamp}@example.com`,
    };

    cy.visit(`${BASE}/create.php?table=${TABLE}`);
    expectCreateForm();

    Object.entries(company).forEach(([field, value]) => {
      cy.get(`input[name="${field}"]`).clear().type(value);
    });
    cy.get('button[type="submit"].btn-save').click();
    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

    cy.url().then(url => {
      const id = new URL(url).searchParams.get('id');
      expect(id, 'new record id in the redirect URL').to.not.be.null;

      cy.visit(`${BASE}/edit.php?table=${TABLE}&id=${id}`);
      expectCreateForm();

      Object.entries(company).forEach(([field, value]) => {
        cy.get(`input[name="${field}"]`).should('have.value', value);
      });
    });
  });

  it('deleting a record decreases the row count by exactly 1', () => {
    cy.dbCount(TABLE).then(before => {
      cy.intercept('DELETE', /index\.php/).as('deleteReq');
      cy.visit(`${BASE}/index.php?table=${TABLE}`);

      waitForGridOrEmpty().then(res => {
        expect(res.type, 'grid rendered').to.eq('grid');

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);

          cy.get('#grid tbody tr')
            .last()
            .find('[data-cy=row-delete]')
            .click({ force: true });

          cy.wait('@deleteReq', { timeout: CypressHelpers.TIMEOUTS.medium });

          cy.dbCount(TABLE).then(after => {
            cy.log(`**companies before delete: ${before} → after: ${after}**`);
            expect(after, 'count after delete').to.eq(before - 1);
          });
        });
      });
    });
  });

  it('cancelling the create form leaves the row count unchanged', () => {
    cy.dbCount(TABLE).then(before => {
      cy.visit(`${BASE}/create.php?table=${TABLE}`);
      expectCreateForm();
      cy.get('input[name="name"]').clear().type(`cypress-cancelled-${Date.now()}`);
      cy.get('button.btn-cancel, a.btn-cancel').click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', `table=${TABLE}`);

      cy.dbCount(TABLE).then(after => {
        expect(after, 'count after cancel').to.eq(before);
      });
    });
  });
});

describe('OpenSparrow – DB row counts: deals', () => {
  const DEALS    = 'deals';
  const JUNCTION = 'deal_contacts';

  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  function fillDealForm(title) {
    const deal = {
      title,
      value:          '1500.00',
      stage:          'Proposal',
      expected_close: '2026-12-31',
    };

    expectCreateForm();

    ['company_id', 'contact_id'].forEach(field => {
      cy.get(`select[name="${field}"] option`).eq(1).then($opt => {
        deal[field] = $opt.val();
        cy.get(`select[name="${field}"]`).select(String($opt.val()));
      });
    });

    cy.get('input[name="title"]').clear().type(deal.title);
    cy.get('input[name="value"]').clear().type(deal.value);
    cy.get('select[name="stage"]').select(deal.stage);
    cy.get('input[name="expected_close"]').clear().type(deal.expected_close);

    cy.get('.m2m-picker input[type="checkbox"]').then($boxes => {
      expect($boxes.length, 'stakeholder options available').to.be.at.least(2);
      deal.stakeholders = [$boxes.eq(0).val(), $boxes.eq(1).val()];

      deal.stakeholders.forEach(v => {
        cy.get(`.m2m-picker input[type="checkbox"][value="${v}"]`).check({ force: true });
      });
    });

    return cy.wrap(deal, { log: false });
  }

  it('baseline: demo deals are present', () => {
    cy.dbCount(DEALS).should('be.greaterThan', 0);
  });

  it('creating a fully filled deal increases the row count by exactly 1', () => {
    cy.dbCount(DEALS).then(beforeDeals => {
      cy.dbCount(JUNCTION).then(beforeLinks => {
        cy.visit(`${BASE}/create.php?table=${DEALS}`);
        fillDealForm(`cypress-deal-${Date.now()}`);

        cy.get('button[type="submit"].btn-save').click();
        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

        cy.dbCount(DEALS).then(after => {
          expect(after, 'count after create').to.eq(beforeDeals + 1);
        });

        cy.dbCount(JUNCTION).then(after => {
          expect(after, 'deal_contacts rows after create').to.eq(beforeLinks + 2);
        });
      });
    });
  });

  it('every field of the created deal is persisted', () => {
    cy.visit(`${BASE}/create.php?table=${DEALS}`);

    fillDealForm(`cypress-deal-full-${Date.now()}`).then(deal => {
      cy.get('button[type="submit"].btn-save').click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

      cy.url().then(url => {
        const id = new URL(url).searchParams.get('id');
        expect(id, 'new deal id in the redirect URL').to.not.be.null;

        cy.visit(`${BASE}/edit.php?table=${DEALS}&id=${id}`);
        expectCreateForm();

        cy.get('input[name="title"]').should('have.value', deal.title);
        cy.get('select[name="company_id"]').should('have.value', deal.company_id);
        cy.get('select[name="contact_id"]').should('have.value', deal.contact_id);
        cy.get('select[name="stage"]').should('have.value', deal.stage);
        cy.get('input[name="expected_close"]').should('have.value', deal.expected_close);

        cy.get('input[name="value"]').invoke('val').then(val => {
          expect(parseFloat(val), 'deal value').to.eq(parseFloat(deal.value));
        });

        deal.stakeholders.forEach(v => {
          cy.get(`.m2m-picker input[type="checkbox"][value="${v}"]`).should('be.checked');
        });
        cy.get('.m2m-picker input[type="checkbox"]:checked')
          .should('have.length', deal.stakeholders.length);
      });
    });
  });

  it('deleting a deal decreases the row count by exactly 1', () => {
    cy.dbCount(DEALS).then(before => {
      cy.intercept('DELETE', /index\.php/).as('deleteReq');
      cy.visit(`${BASE}/index.php?table=${DEALS}`);

      waitForGridOrEmpty().then(res => {
        expect(res.type, 'grid rendered').to.eq('grid');

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);

          cy.get('#grid tbody tr')
            .last()
            .find('[data-cy=row-delete]')
            .click({ force: true });

          cy.wait('@deleteReq', { timeout: CypressHelpers.TIMEOUTS.medium });

          cy.dbCount(DEALS).then(after => {
            expect(after, 'count after delete').to.eq(before - 1);
          });
        });
      });
    });
  });
});

describe('OpenSparrow – DB row counts: contacts', () => {
  const CONTACTS = 'contacts';

  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  function fillContactForm(lastName) {
    const contact = {
      first_name: 'Cypress',
      last_name:  lastName,
      email:      `${lastName}@example.com`,
      phone:      '+48-555-0177',
      position:   'Procurement Lead',
    };

    expectCreateForm();

    cy.get('select[name="company_id"] option').eq(1).then($opt => {
      contact.company_id = $opt.val();
      cy.get('select[name="company_id"]').select(String($opt.val()));
    });

    ['first_name', 'last_name', 'email', 'phone', 'position'].forEach(field => {
      cy.get(`input[name="${field}"]`).clear().type(contact[field]);
    });

    return cy.wrap(contact, { log: false });
  }

  it('baseline: demo contacts are present', () => {
    cy.dbCount(CONTACTS).should('be.greaterThan', 0);
  });

  it('creating a fully filled contact increases the row count by exactly 1', () => {
    cy.dbCount(CONTACTS).then(before => {
      cy.visit(`${BASE}/create.php?table=${CONTACTS}`);
      fillContactForm(`cypress-contact-${Date.now()}`);

      cy.get('button[type="submit"].btn-save').click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

      cy.dbCount(CONTACTS).then(after => {
        expect(after, 'count after create').to.eq(before + 1);
      });
    });
  });

  it('every field of the created contact is persisted', () => {
    cy.visit(`${BASE}/create.php?table=${CONTACTS}`);

    fillContactForm(`cypress-contact-full-${Date.now()}`).then(contact => {
      cy.get('button[type="submit"].btn-save').click();
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('not.include', 'create.php');

      cy.url().then(url => {
        const id = new URL(url).searchParams.get('id');
        expect(id, 'new contact id in the redirect URL').to.not.be.null;

        cy.visit(`${BASE}/edit.php?table=${CONTACTS}&id=${id}`);
        expectCreateForm();

        cy.get('select[name="company_id"]').should('have.value', contact.company_id);
        ['first_name', 'last_name', 'email', 'phone', 'position'].forEach(field => {
          cy.get(`input[name="${field}"]`).should('have.value', contact[field]);
        });
      });
    });
  });

  it('deleting a contact decreases the row count by exactly 1', () => {
    cy.dbCount(CONTACTS).then(before => {
      cy.intercept('DELETE', /index\.php/).as('deleteReq');
      cy.visit(`${BASE}/index.php?table=${CONTACTS}`);

      waitForGridOrEmpty().then(res => {
        expect(res.type, 'grid rendered').to.eq('grid');

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);

          cy.get('#grid tbody tr')
            .last()
            .find('[data-cy=row-delete]')
            .click({ force: true });

          cy.wait('@deleteReq', { timeout: CypressHelpers.TIMEOUTS.medium });

          cy.dbCount(CONTACTS).then(after => {
            expect(after, 'count after delete').to.eq(before - 1);
          });
        });
      });
    });
  });
});
