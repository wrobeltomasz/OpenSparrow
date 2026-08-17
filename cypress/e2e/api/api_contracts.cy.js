// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';

function asJson(body) {
  return typeof body === 'string' ? JSON.parse(body) : body;
}

describe('OpenSparrow – API Contracts: Response Shapes', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('api=list returns columns/rows/truncated/total/table', () => {
    cy.request(`${BASE}/api.php?api=list&table=companies`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.columns, 'columns').to.be.an('array').and.not.be.empty;
      expect(data.rows, 'rows').to.be.an('array');
      expect(data.truncated, 'truncated flag').to.be.a('boolean');
      expect(data.total, 'total').to.be.a('number');
      expect(data.table, 'table meta').to.be.an('object');
      expect(data.table.name, 'table name').to.eq('companies');
      expect(data.table.display_name, 'display name').to.be.a('string');
    });
  });

  it('api=list rows carry every announced column', () => {
    cy.request(`${BASE}/api.php?api=list&table=companies`).then(result => {
      const { columns, rows } = asJson(result.body);
      if (rows.length === 0) {
        Cypress.log({ message: 'No rows — column check skipped' });
        return;
      }
      columns.forEach(column => {
        expect(rows[0], `row has column "${column}"`).to.have.property(column);
      });
    });
  });

  it('action=i18n_bundle returns a flat translation map', () => {
    cy.request(`${BASE}/api.php?action=i18n_bundle`).then(result => {
      expect(result.status).to.eq(200);
      const bundle = asJson(result.body);
      expect(bundle, 'bundle').to.be.an('object');
      expect(bundle['common.save'], 'common.save key').to.be.a('string').and.not.be.empty;

      expect(bundle.common, 'no nested "common" object').to.be.undefined;
    });
  });

  it('api=board returns a configured flag; lanes when configured', () => {
    cy.request(`${BASE}/api.php?api=board`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.configured, 'configured flag').to.be.a('boolean');
      if (data.configured) {
        expect(data.columns, 'lanes').to.be.an('array');
        expect(data.cards, 'cards').to.be.an('array');
      }
    });
  });

  it('notifications get_count returns status and numeric count', () => {
    cy.request(`${BASE}/api/notifications.php?action=get_count`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.status, 'status').to.eq('success');
      expect(data.count, 'count').to.be.a('number');
    });
  });

  it('notifications get_list returns a notifications array', () => {
    cy.request(`${BASE}/api/notifications.php?action=get_list`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.status, 'status').to.eq('success');
      expect(data.notifications, 'notifications').to.be.an('array');
    });
  });

  it('files action=list returns a files array', () => {
    cy.request(`${BASE}/api/files.php?action=list`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.files, 'files').to.be.an('array');
    });
  });

  it('owners action=mine returns a flat records array with assignment dates', () => {
    cy.request(`${BASE}/api/owners.php?action=mine`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.success, 'success').to.eq(true);
      expect(data.records, 'records').to.be.an('array');
      if (data.records.length === 0) {
        Cypress.log({ message: 'No owned records — item shape check skipped' });
        return;
      }
      const r = data.records[0];
      expect(r.id, 'id').to.be.a('number');
      ['table', 'table_display', 'label', 'assigned_at']
        .forEach(k => expect(r[k], k).to.be.a('string'));
    });
  });

  it('comments action=mine returns a comments array with resolved record labels', () => {
    cy.request(`${BASE}/api/comments.php?action=mine`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.success, 'success').to.eq(true);
      expect(data.comments, 'comments').to.be.an('array');
      if (data.comments.length === 0) {
        Cypress.log({ message: 'No own comments — item shape check skipped' });
        return;
      }
      const c = data.comments[0];
      ['id', 'related_id'].forEach(k => expect(c[k], k).to.be.a('number'));
      ['body', 'related_table', 'table_display', 'record_label', 'created_at']
        .forEach(k => expect(c[k], k).to.be.a('string'));
    });
  });

  it('api=list with an unknown table does not return 200', () => {
    cy.request({
      url: `${BASE}/api.php?api=list&table=definitely_not_a_table`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status, 'unknown table must be rejected').to.be.gte(400);
    });
  });
});

describe('OpenSparrow – API Contracts: Print Module', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('action=list returns a prints array', () => {
    cy.request(`${BASE}/api/print.php?action=list`).then(result => {
      expect(result.status).to.eq(200);
      const data = asJson(result.body);
      expect(data.status, 'status').to.eq('ok');
      expect(data.prints, 'prints').to.be.an('array');
    });
  });

  it('action=data with an unknown print returns 404 with an error', () => {
    cy.request({
      url: `${BASE}/api/print.php?action=data&print=definitely_not_a_print`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status).to.eq(404);
      expect(asJson(result.body).error, 'error message').to.be.a('string').and.not.be.empty;
    });
  });

  it('action=param_options with an unknown print returns 404', () => {
    cy.request({
      url: `${BASE}/api/print.php?action=param_options&print=definitely_not_a_print&key=x`,
      failOnStatusCode: false,
    }).then(result => {
      expect(result.status).to.eq(404);
    });
  });

  it('action=data always includes params/applied_params, even without a filter applied', () => {
    cy.request(`${BASE}/api/print.php?action=list`).then(listResult => {
      const { prints } = asJson(listResult.body);
      if (!prints || prints.length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping data shape check' });
        return;
      }
      cy.request(`${BASE}/api/print.php?action=data&print=${encodeURIComponent(prints[0].name)}`).then(result => {
        expect(result.status).to.eq(200);
        const data = asJson(result.body);
        expect(data.rows, 'rows').to.be.an('array');
        expect(data.params, 'params').to.be.an('array');
        expect(data.applied_params, 'applied_params').to.be.an('object');
      });
    });
  });

  it('action=param_options for a declared parameter returns an options array', () => {
    cy.request(`${BASE}/api/print.php?action=list`).then(listResult => {
      const { prints } = asJson(listResult.body);
      if (!prints || prints.length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping param_options check' });
        return;
      }
      cy.request(`${BASE}/api/print.php?action=data&print=${encodeURIComponent(prints[0].name)}`).then(dataResult => {
        const { params: parameters } = asJson(dataResult.body);
        if (!parameters || parameters.length === 0) {
          Cypress.log({ message: 'Template declares no parameters — skipping param_options check' });
          return;
        }
        cy.request(
          `${BASE}/api/print.php?action=param_options&print=${encodeURIComponent(prints[0].name)}`
            + `&key=${encodeURIComponent(parameters[0].key)}`
        ).then(result => {
          expect(result.status).to.eq(200);
          const data = asJson(result.body);
          expect(data.status, 'status').to.eq('ok');
          expect(data.options, 'options').to.be.an('array');
        });
      });
    });
  });

  it('action=data ignores a p_ filter that is not declared as a parameter on that template', () => {
    cy.request(`${BASE}/api/print.php?action=list`).then(listResult => {
      const { prints } = asJson(listResult.body);
      if (!prints || prints.length === 0) {
        Cypress.log({ message: 'No print templates configured — skipping unknown-param robustness check' });
        return;
      }
      cy.request(
        `${BASE}/api/print.php?action=data&print=${encodeURIComponent(prints[0].name)}&p_not_a_real_param=xyz`
      ).then(result => {
        expect(result.status).to.eq(200);
      });
    });
  });
});
