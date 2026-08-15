// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const SEED_TOKEN = 'cypress-dev-seed';

function seedRequest(action, body = {}) {
  return cy.request({
    method: 'POST',
    url: '/cypress_seed.php',
    form: true,
    body: { token: SEED_TOKEN, action, ...body },
  });
}

describe('Security – IDOR / row-level ownership', () => {
  let fx = null;

  const probeFileUuids = [];
  let foreignFileUuid  = null;

  before(() => {
    cy.seedDatabase();
    seedRequest('own').then(({ body }) => {
      expect(body.status, 'seed own status').to.eq('ok');
      if (body.results.skipped) {
        cy.task('log', '[security] No configured table usable for the ownership fixture — IDOR suite skipped.');
        return;
      }
      fx = body.results;
      cy.task('log', `[security] ownership fixture: ${fx.table} a=${fx.id_a} (test) b=${fx.id_b} (test2)`);
    });
  });

  after(() => {
    if (fx) {
      seedRequest('own_reset', { table: fx.table, was_restricted: fx.was_restricted ? '1' : '0' });
    }
    if (probeFileUuids.length === 0) {
      return;
    }

    loginAsTestUser();
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      probeFileUuids.forEach(uuid => {
        cy.probe({
          method: 'POST',
          url: '/api/files.php',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: { action: 'delete', uuid, csrf_token: token },
        }).then(res => {
          expect(res.status, `probe attachment ${uuid} must be cleaned up`).to.eq(200);
        });
      });
    });
  });

  beforeEach(function () {
    if (!fx) {
      this.skip();
    }
    loginAsTestUser();
  });

  it('the grid does not list another user\'s record', () => {
    cy.probe({
      url: `/api.php?api=list&table=${fx.table}&search=cypress-idor`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(res.status).to.eq(200);
      const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      const rows = payload.rows || payload.data || payload.records || [];
      const ids = rows.map(r => Number(r.id));
      expect(ids, 'own record is visible').to.include(fx.id_a);
      expect(ids, 'other owner\'s record must be filtered out').to.not.include(fx.id_b);
    });
  });

  it('edit.php refuses to open another user\'s record', () => {
    cy.probe({ url: `/edit.php?table=${fx.table}&id=${fx.id_b}` }).then(res => {
      expect(res.status, 'edit.php on a foreign record').to.not.eq(200);
    });
  });

  it('PATCH on another user\'s record is refused and changes nothing', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      cy.probe({
        url: `/api.php?table=${fx.table}`,
        method: 'PATCH',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
        body: { table: fx.table, id: fx.id_b, column: fx.column, value: 'cypress-idor-hacked' },
      }).then(res => {
        cy.expectDenied(res, [403], 'PATCH foreign record');
        expect(JSON.stringify(res.body)).to.match(/do not own this record/i);
      });
    });

    loginAsTestUser2();
    cy.probe({
      url: `/api.php?api=list&table=${fx.table}&search=cypress-idor-b`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      const rows = payload.rows || payload.data || payload.records || [];
      const mine = rows.find(r => Number(r.id) === fx.id_b);
      expect(mine, 'owner still sees the record').to.not.be.undefined;
      expect(mine[fx.column], 'value untouched by the failed write').to.eq('cypress-idor-b');
    });
  });

  it('DELETE on another user\'s record is refused and the row survives', () => {
    cy.dbCount(fx.table).then(before => {
      cy.visit('/dashboard.php');
      cy.csrfToken().then(token => {
        cy.probe({
          url: `/api.php?table=${fx.table}`,
          method: 'DELETE',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
          body: { table: fx.table, id: fx.id_b },
        }).then(res => cy.expectDenied(res, [403], 'DELETE foreign record'));
      });
      cy.dbCount(fx.table).should('eq', before);
    });
  });

  it('id-taking side channels drop ids the caller may not see', () => {
    cy.probe({
      url: `/api.php?api=subtable_counts&table=${fx.table}&ids=${fx.id_a},${fx.id_b}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      if (res.status !== 200) return;
      const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      expect(JSON.stringify(payload), 'foreign id must not appear in the counts')
        .to.not.match(new RegExp(`"${fx.id_b}"\\s*:`));
    });
  });

  it('the file listing drops attachments on another user\'s record', () => {
    let mineUuid = null;

    const upload = (table, recordId, label) => cy.csrfToken().then(token => cy.window().then(win => {
      const form = new win.FormData();
      form.append('action', 'upload');
      form.append('csrf_token', token);
      form.append('related_table', table);
      form.append('related_id', String(recordId));
      form.append('file', new win.Blob([`probe,${label}\n`], { type: 'text/csv' }), `${label}.csv`);
      return win.fetch('/api/files.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: form,
      }).then(res => res.text().then(body => {
        expect(res.status, `${label} upload must be accepted: ${body}`).to.eq(201);
        const m = body.match(/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i);
        expect(m, `${label} upload must return a uuid`).to.not.eq(null);
        return m[0];
      }));
    }));

    loginAsTestUser2();
    cy.visit('/dashboard.php');
    upload(fx.table, fx.id_b, 'cypress-idor-file-b').then(u => {
      foreignFileUuid = u;
      probeFileUuids.push(u);
    });

    loginAsTestUser();
    cy.visit('/dashboard.php');
    upload(fx.table, fx.id_a, 'cypress-idor-file-a').then(u => {
      mineUuid = u;
      probeFileUuids.push(u);
    });

    cy.then(() => {
      cy.probe({
        url: '/api/files.php?action=list&limit=200',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        expect(res.status, 'file listing').to.eq(200);
        const uuids = (res.body.files || []).map(f => f.uuid);
        expect(uuids, 'own attachment stays listed').to.include(mineUuid);
        expect(uuids, 'attachment on another owner\'s record must not be listed')
          .to.not.include(foreignFileUuid);
      });
    });
  });

  it('an editor may take ownership of any record (by design)', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      cy.probe({
        url: '/api/owners.php?action=set',
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },

        body: { action: 'set', table: fx.table, record_id: fx.id_b, owner_id: fx.owner_a, csrf_token: token },
      }).then(res => {
        expect(res.status, 'ownership transfer is permitted').to.eq(200);
      });
    });

    cy.probe({
      url: `/api.php?api=list&table=${fx.table}&search=cypress-idor-b`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      const rows = payload.rows || payload.data || payload.records || [];
      expect(rows.map(r => Number(r.id)), 'the new owner now sees the record')
        .to.include(fx.id_b);
    });
  });

  it('file_download.php answers 404 — not 403 — for a foreign uuid', () => {
    cy.probe({ url: '/file_download.php?uuid=11111111-1111-4111-8111-111111111111' })
      .then(res => cy.expectDenied(res, [404], 'foreign file'));
  });

  it('a bulk delete skips rows owned by someone else', () => {
    seedRequest('own').then(({ body }) => {
      expect(body.status).to.eq('ok');
      if (!body.results.skipped) {
        fx = body.results;
      }
    });
    loginAsTestUser();

    cy.dbCount(fx.table).then(before => {
      cy.visit('/dashboard.php');
      cy.csrfToken().then(token => {
        cy.probe({
          url: '/api/mass_edit.php?action=mass_delete',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },

          body: { table: fx.table, row_ids: [fx.id_a, fx.id_b] },
        }).then(res => {
          expect(res.status, 'bulk delete is accepted, then filtered').to.be.oneOf([200, 403]);
        });
      });

      cy.dbCount(fx.table).then(after => {
        expect(before - after, 'at most the caller\'s own row was deleted').to.be.oneOf([0, 1]);
      });

      loginAsTestUser2();
      cy.probe({
        url: `/api.php?api=list&table=${fx.table}&search=cypress-idor-b`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
        const rows = payload.rows || payload.data || payload.records || [];
        expect(rows.map(r => Number(r.id)), 'the other owner\'s row survived')
          .to.include(fx.id_b);
      });
    });
  });
});
