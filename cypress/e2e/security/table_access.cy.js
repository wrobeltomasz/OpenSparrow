// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const ALLOWED = 'companies';

let testUserId = null;
let deniedTable = null;

let allowedView = null;
let deniedView = null;
let allowedPrint = null;
let deniedPrint = null;

let boards = [];
let workflows = [];

let runnableWf = null;
let otherWf = null;

let wfWithheldTable = null;

function adminPost(action, body) {
  return cy.csrfToken().then(token =>
    cy.request({
      method: 'POST',
      url: `/admin/api.php?action=${action}`,
      headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
      body,
      failOnStatusCode: false,
    })
  );
}

function setAccess({ tables = [], views = [], prints = [], boards: bd = [], workflows: wf = [] } = {}) {
  return adminPost('user_tables_save', {
    user_id: testUserId,
    tables,
    views,
    prints,
    boards: bd,
    workflows: wf,
  }).then(res => {
    expect(res.status, 'user_tables_save status').to.eq(200);
    expect(res.body.status, `user_tables_save body: ${JSON.stringify(res.body)}`).to.eq('success');
  });
}

function grant(fixture) {
  loginAsAdmin();
  cy.visit('/admin/');
  setAccess(fixture);
  loginAsTestUser();

  cy.visit('/index.php');
}

describe('Security – per-user table access', () => {
  before(() => {
    cy.seedDatabase();

    loginAsAdmin();
    cy.request('/admin/api.php?action=users_list').then(res => {
      const user = res.body.users.find(u => u.username === 'test');
      expect(user, 'test user must exist after seeding').to.exist;
      testUserId = user.id;
    });

    cy.request('/api/schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(res => {
        const names = Object.keys(res.body.tables || {});
        expect(names, 'schema must expose more than one table').to.have.length.greaterThan(1);
        expect(names, `schema must contain ${ALLOWED}`).to.include(ALLOWED);
        deniedTable = names.find(n => n !== ALLOWED);
      });

    cy.request('/api/views.php?action=list').then(res => {
      const names = (res.body.views || []).map(v => v.name);
      if (names.length >= 2) {
        [allowedView, deniedView] = names;
      }
    });
    cy.request('/api/print.php?action=list').then(res => {
      const names = (res.body.prints || []).map(p => p.name);
      if (names.length >= 2) {
        [allowedPrint, deniedPrint] = names;
      }
    });

    cy.request('/admin/api.php?action=get&file=board').then(res => {
      expect(res.body, 'board config must parse as JSON').to.be.an('object');
      boards = (res.body.boards || [])
        .filter(b => b && b.id && b.table && b.status_column && !b.hidden)
        .map(b => ({ id: b.id, table: b.table, menuName: b.menu_name || '' }));
    });

    cy.request('/admin/api.php?action=get&file=workflows').then(res => {
      expect(res.body, 'workflow config must parse as JSON').to.be.an('object');
      workflows = (res.body.workflows || [])
        .filter(w => w && w.id)
        .map(w => ({
          id: w.id,
          tables: [...new Set(
            (w.steps || []).map(s => (s && s.table) || '').filter(t => t !== '')
          )],
        }));

      const byCost = [...workflows].sort((a, b) => a.tables.length - b.tables.length);
      runnableWf = byCost[0] || null;
      otherWf = runnableWf ? byCost.find(w => w.id !== runnableWf.id) || null : null;
      wfWithheldTable = runnableWf ? runnableWf.tables.find(t => t !== ALLOWED) || null : null;
    });

    cy.then(() => setAccess({
      tables: [ALLOWED],
      views:  allowedView ? [allowedView] : [],
      prints: allowedPrint ? [allowedPrint] : [],
    }));
  });

  after(() => {
    loginAsAdmin();
    setAccess();
  });

  describe('as the restricted user', () => {
    beforeEach(() => {
      loginAsTestUser();
    });

    it('api/schema.php exposes only the allowed table', () => {
      cy.request('/api/schema.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => {
          expect(Object.keys(res.body.tables)).to.deep.eq([ALLOWED]);
        });
    });

    it('the allowed table still lists rows', () => {
      cy.probe({ url: `/api.php?api=list&table=${ALLOWED}` }).then(res => {
        expect(res.status, 'allowed table must keep working').to.eq(200);
      });
    });

    it('listing a restricted table is refused with 403, not 400', () => {
      cy.probe({ url: `/api.php?api=list&table=${deniedTable}` }).then(res => {
        cy.expectDenied(res, [403], 'list restricted table');
      });
    });

    it('writing to a restricted table is refused', () => {
      cy.csrfToken().then(token => {
        cy.probe({
          method: 'POST',
          url: '/api.php',
          headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
          body: { table: deniedTable, data: {} },
        }).then(res => {
          cy.expectDenied(res, [403], 'insert into restricted table');
        });
      });
    });

    it('mass edit and data cleanup are refused for a restricted table', () => {
      cy.csrfToken().then(token => {
        const headers = { 'X-CSRF-Token': token, 'Content-Type': 'application/json' };
        cy.probe({
          method: 'POST',
          url: '/api/mass_edit.php?action=preview',
          headers,
          body: { table: deniedTable, column: 'id', value: '1', row_ids: [1] },
        }).then(res => cy.expectDenied(res, [403], 'mass_edit preview'));

        cy.probe({
          method: 'POST',
          url: '/api/data_cleanup.php?action=preview',
          headers,
          body: { table: deniedTable, column: 'id', mode: 'trim' },
        }).then(res => cy.expectDenied(res, [403], 'data_cleanup preview'));
      });
    });

    it('opening the grid for a restricted table redirects back to the default grid', () => {
      cy.probe({ url: `/index.php?table=${deniedTable}` }).then(res => {
        expect(res.status, 'restricted grid page').to.be.oneOf([302, 303]);
        expect(res.headers.location, 'redirect target').to.include('index.php');
      });
    });

    it('edit.php shows no subtable tab bound to a restricted table', function () {
      cy.request({
        url: `/api.php?api=list&table=${ALLOWED}&limit=1`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        const rows = res.body.data || res.body.rows || [];
        if (rows.length === 0) {
          this.skip();
        }
        cy.visit(`/edit.php?table=${ALLOWED}&id=${rows[0].id}`);

        cy.get('body').should('not.contain.text', deniedTable);
        cy.get(`.subtable-container a[href*="table=${deniedTable}"]`).should('not.exist');
      });
    });

    it('create.php and edit.php for a restricted table redirect too', () => {
      cy.probe({ url: `/create.php?table=${deniedTable}` }).then(res => {
        expect(res.status, 'restricted create page').to.be.oneOf([302, 303]);
      });
      cy.probe({ url: `/edit.php?table=${deniedTable}&id=1` }).then(res => {
        expect(res.status, 'restricted edit page').to.be.oneOf([302, 303]);
      });
    });

    it('api/views.php lists only the allowed view and refuses the other', function () {
      if (!deniedView) {
        this.skip();
      }
      cy.request('/api/views.php?action=list').then(res => {
        const names = (res.body.views || []).map(v => v.name);
        expect(names, 'view list').to.deep.eq([allowedView]);
      });
      cy.probe({ url: `/api/views.php?action=data&view=${deniedView}` }).then(res => {
        cy.expectDenied(res, [403], 'restricted view data');
      });
      cy.probe({ url: `/views.php?view=${deniedView}` }).then(res => {
        expect(res.status, 'restricted view page').to.be.oneOf([302, 303]);
      });
    });

    it('api/print.php lists only the allowed printout and refuses the other', function () {
      if (!deniedPrint) {
        this.skip();
      }
      cy.request('/api/print.php?action=list').then(res => {
        const names = (res.body.prints || []).map(p => p.name);
        expect(names, 'printout list').to.deep.eq([allowedPrint]);
      });
      cy.probe({ url: `/api/print.php?action=data&print=${deniedPrint}` }).then(res => {
        cy.expectDenied(res, [403], 'restricted printout data');
      });
      cy.probe({ url: `/api/print.php?action=param_options&print=${deniedPrint}&key=x` }).then(res => {
        cy.expectDenied(res, [403], 'restricted printout param_options');
      });
      cy.probe({ url: `/print.php?print=${deniedPrint}` }).then(res => {
        expect(res.status, 'restricted printout page').to.be.oneOf([302, 303]);
      });
    });

    it('restricting tables does not restrict views or printouts', function () {
      if (!allowedView) {
        this.skip();
      }
      loginAsAdmin();
      setAccess({ tables: [ALLOWED] });
      loginAsTestUser();
      cy.request('/api/views.php?action=list').then(res => {
        expect((res.body.views || []).length, 'views stay unrestricted').to.be.greaterThan(1);
      });

      loginAsAdmin();
      cy.then(() => setAccess({
        tables: [ALLOWED],
        views:  allowedView ? [allowedView] : [],
        prints: allowedPrint ? [allowedPrint] : [],
      }));
    });

    it('the menu lists the allowed table and not the restricted one', () => {
      cy.visit(`/index.php?table=${ALLOWED}`);
      cy.get('#menu').should('exist');
      cy.get('#menu').should('not.contain.text', deniedTable);
      cy.get(`#menu a[href*="table=${ALLOWED}"]`).should('exist');
      cy.get(`#menu a[href*="table=${deniedTable}"]`).should('not.exist');
    });
  });

  describe('the boards scope', () => {
    it('hides a board whose bound table is out of scope, without naming the binding', function () {
      if (boards.length === 0) {
        this.skip();
      }
      const board = boards[0];

      const grantTable = board.table === ALLOWED ? deniedTable : ALLOWED;
      grant({ tables: [grantTable] });

      cy.visit(`/index.php?table=${grantTable}`);
      cy.get(`#menu a[href*="board.php?board=${board.id}"]`).should('not.exist');

      cy.probe({ url: `/api.php?api=board&board=${board.id}` }).then(res => {
        expect(res.status, 'out-of-scope board data').to.eq(200);
        expect(res.body.table, 'bound table must not be disclosed').to.eq('');
        expect(res.body.status_column, 'status column must not be disclosed').to.eq('');
        expect(res.body.cards || [], 'no cards for an out-of-scope board').to.have.length(0);
      });
    });

    it('refuses a board outside the boards scope at both the page and the endpoint', function () {
      if (boards.length < 2) {
        this.skip();
      }
      const [granted, denied] = boards;
      grant({ tables: [granted.table, denied.table], boards: [granted.id] });

      cy.probe({ url: `/board.php?board=${denied.id}` }).then(res => {
        expect(res.status, 'out-of-scope board page').to.be.oneOf([302, 303]);
      });
      cy.probe({ url: `/api.php?api=board&board=${denied.id}` }).then(res => {
        cy.expectDenied(res, [403], 'out-of-scope board data');
      });

      cy.visit(`/index.php?table=${granted.table}`);
      cy.get(`#menu a[href*="board.php?board=${granted.id}"]`).should('exist');
      cy.get(`#menu a[href*="board.php?board=${denied.id}"]`).should('not.exist');
    });

    it('never falls back to a board that was not granted', function () {
      if (boards.length < 2) {
        this.skip();
      }
      const ungranted = boards[0];
      const granted = boards[1];
      grant({ tables: [granted.table], boards: [granted.id] });

      cy.probe({ url: '/api.php?api=board' }).then(res => {
        expect(res.status, 'board fallback').to.eq(200);
        expect(res.body.table, 'fallback must stay inside the granted boards').to.eq(granted.table);
        if (granted.menuName !== '' && granted.menuName !== ungranted.menuName) {
          expect(res.body.menu_name, 'fallback must not reach past the filter')
            .to.eq(granted.menuName);
        }
      });
    });
  });

  describe('the workflows scope', () => {
    it('drops a granted workflow when one of its step tables is out of scope', function () {
      if (!runnableWf || !wfWithheldTable) {
        this.skip();
      }

      grant({
        tables: [ALLOWED, ...runnableWf.tables.filter(t => t !== wfWithheldTable)],
        workflows: [runnableWf.id],
      });

      cy.request('/api.php?api=workflows').then(res => {
        const ids = (res.body.workflows || []).map(w => w.id);
        expect(ids, 'granting a workflow does not grant its tables').to.not.include(runnableWf.id);
      });
      cy.visit(`/index.php?table=${ALLOWED}`);
      cy.get(`#menu a[href*="workflow=${runnableWf.id}"]`).should('not.exist');
    });

    it('refuses workflow_procedure when a step table is out of scope', function () {
      if (!runnableWf || !wfWithheldTable) {
        this.skip();
      }
      grant({
        tables: [ALLOWED, ...runnableWf.tables.filter(t => t !== wfWithheldTable)],
        workflows: [runnableWf.id],
      });

      cy.csrfToken().then(token => {
        cy.probe({
          method: 'POST',
          url: '/api.php?api=workflow_procedure',
          headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
          body: { workflow_id: runnableWf.id, step_index: 0, step_values: {} },
        }).then(res => {
          cy.expectDenied(res, [403], 'workflow_procedure with an out-of-scope step table');
        });
      });
    });

    it('refuses a workflow outside the workflows scope even with every table granted', function () {
      if (!runnableWf || !otherWf) {
        this.skip();
      }
      grant({
        tables: [ALLOWED, ...runnableWf.tables],
        workflows: [otherWf.id],
      });

      cy.request('/api.php?api=workflows').then(res => {
        const ids = (res.body.workflows || []).map(w => w.id);
        expect(ids, 'an ungranted workflow must not be listed').to.not.include(runnableWf.id);
      });
      cy.probe({ url: `/index.php?workflow=${runnableWf.id}` }).then(res => {
        expect(res.status, 'ungranted workflow page').to.be.oneOf([302, 303]);
      });
      cy.csrfToken().then(token => {
        cy.probe({
          method: 'POST',
          url: '/api.php?api=workflow_procedure',
          headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
          body: { workflow_id: runnableWf.id, step_index: 0, step_values: {} },
        }).then(res => {
          cy.expectDenied(res, [403], 'workflow_procedure for an ungranted workflow');
        });
      });
    });

    it('keeps a workflow whose scope and step tables both hold', function () {
      if (!runnableWf) {
        this.skip();
      }
      grant({
        tables: [ALLOWED, ...runnableWf.tables],
        workflows: [runnableWf.id],
      });

      cy.request('/api.php?api=workflows').then(res => {
        const ids = (res.body.workflows || []).map(w => w.id);
        expect(ids, 'a fully granted workflow must survive both filters').to.include(runnableWf.id);
      });
      cy.probe({ url: `/index.php?workflow=${runnableWf.id}` }).then(res => {
        expect(res.status, 'fully granted workflow page').to.eq(200);
      });
    });
  });

  describe('the boundary gate', () => {
    it('refuses an array-valued table parameter instead of skipping it', () => {
      grant({ tables: [ALLOWED] });

      cy.probe({ url: `/api.php?api=list&table%5B%5D=${deniedTable}` }).then(res => {
        cy.expectDenied(res, [403], 'array-valued table parameter');
      });
    });

    it('refuses a JSON body sent under a non-JSON Content-Type', () => {
      grant({ tables: [ALLOWED] });

      cy.csrfToken().then(token => {
        cy.probe({
          method: 'POST',
          url: '/api.php',
          headers: { 'X-CSRF-Token': token, 'Content-Type': 'text/plain' },
          body: JSON.stringify({ table: deniedTable, data: {} }),
        }).then(res => {
          cy.expectDenied(res, [403], 'JSON body under text/plain');
        });
      });
    });
  });

  describe('the file write gate', () => {
    let probeUuid = null;
    let relatedId = null;

    function uploadAttachment(token) {
      return cy.window().then(win => {
        const form = new win.FormData();
        form.append('action', 'upload');
        form.append('csrf_token', token);
        form.append('related_table', deniedTable);
        form.append('related_id', String(relatedId));
        form.append(
          'file',
          new win.Blob(['name,note\ncypress-access-probe,probe\n'], { type: 'text/csv' }),
          'cypress-access-probe.csv'
        );

        return win.fetch('/api/files.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: form,
        }).then(res => res.text().then(body => ({ status: res.status, body })));
      });
    }

    before(() => {
      loginAsAdmin();
      cy.visit('/admin/');

      cy.request({
        url: `/api.php?api=list&table=${deniedTable}&limit=1`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        const rows = res.body.data || res.body.rows || [];
        relatedId = rows.length ? rows[0].id : null;
      });

      cy.then(() => {
        if (relatedId === null) {
          return;
        }
        cy.csrfToken().then(token => {
          uploadAttachment(token).then(res => {
            expect(res.status, `probe upload must be accepted: ${res.body}`).to.eq(201);
            const match = res.body.match(
              /[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i
            );
            expect(match, 'upload response must carry the new uuid').to.not.eq(null);
            probeUuid = match[0];
          });
        });
      });
    });

    after(() => {
      if (!probeUuid) {
        return;
      }
      loginAsAdmin();
      cy.visit('/admin/');
      cy.csrfToken().then(token => {
        cy.probe({
          method: 'POST',
          url: '/api/files.php',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: { action: 'delete', uuid: probeUuid, csrf_token: token },
        }).then(res => {
          expect(res.status, 'probe upload must be cleaned up').to.eq(200);
        });
      });
    });

    it('refuses every write path for a file hung off an out-of-scope record', function () {
      if (!probeUuid) {
        this.skip();
      }
      grant({ tables: [ALLOWED] });

      cy.csrfToken().then(token => {
        const headers = {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        };

        const cases = [
          ['delete', { action: 'delete', uuid: probeUuid, csrf_token: token }],
          ['mass_delete', { action: 'mass_delete', uuids: [probeUuid], csrf_token: token }],
          ['mass_tag', { action: 'mass_tag', uuids: [probeUuid], tags: 'probe', csrf_token: token }],
          ['update_meta', {
            action: 'update_meta',
            uuid: probeUuid,
            display_name: 'renamed-by-probe',
            csrf_token: token,
          }],
        ];

        cases.forEach(([label, body]) => {
          cy.probe({ method: 'POST', url: '/api/files.php', headers, body }).then(res => {
            cy.expectDenied(res, [404], `files ${label} on an out-of-scope record`);
          });
        });
      });
    });

    it('leaves the file listing free of out-of-scope attachments', function () {
      if (!probeUuid) {
        this.skip();
      }
      grant({ tables: [ALLOWED] });

      cy.request('/api/files.php?action=list&limit=200').then(res => {
        const uuids = (res.body.files || []).map(f => f.uuid);
        expect(uuids, 'a file on an out-of-scope table must not be listed').to.not.include(probeUuid);
      });
    });
  });

  describe('back to unrestricted', () => {
    it('clearing the selection restores access to every table', () => {
      loginAsAdmin();
      setAccess();

      loginAsTestUser();
      cy.probe({ url: `/api.php?api=list&table=${deniedTable}` }).then(res => {
        expect(res.status, 'previously restricted table after clearing').to.eq(200);
      });
    });
  });
});
