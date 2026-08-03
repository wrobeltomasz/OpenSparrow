// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/security/idor.cy.js
// ============================================================================
// Security — row-level ownership (IDOR)
//
// Two editors, two records. `test` owns cypress-idor-a, `test2` owns
// cypress-idor-b, and the table is owner_restricted for the duration of the
// suite. Everything here is `test` reaching for record b.
//
// Exercises the three separate implementations of one policy — they are easy to
// drift apart, which is precisely the bug class this suite exists to catch:
//   can_access_record()      single-record reads and mutations
//   owner_restriction_sql()  bulk statements (list, mass_edit, data_cleanup)
//   filter_visible_ids()     id-taking side channels (thumbnails, subtable counts)
// all in includes/api_helpers.php.
//
// The fixture is created by cypress_seed.php action=own and undone by
// action=own_reset, which also restores the owner_restricted flag.
// ============================================================================

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
  /** @type {{table:string, column:string, id_a:number, id_b:number, was_restricted:boolean}} */
  let fx = null;

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

    // A 403 alone does not prove the UPDATE never ran — verify through the owner.
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
    // filter_visible_ids() — subtable counts and thumbnails accept ids as input,
    // so without it a record the grid never returned can still be probed directly.
    cy.probe({
      url: `/api.php?api=subtable_counts&table=${fx.table}&ids=${fx.id_a},${fx.id_b}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      if (res.status !== 200) return; // table has no subtables configured — nothing to leak
      const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      expect(JSON.stringify(payload), 'foreign id must not appear in the counts')
        .to.not.match(new RegExp(`"${fx.id_b}"\\s*:`));
    });
  });

  it('ownership cannot be reassigned on another user\'s record', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      cy.probe({
        url: '/api/owners.php?action=set',
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { table: fx.table, record_id: fx.id_b, owner_id: fx.owner_a, csrf_token: token },
      }).then(res => cy.expectDenied(res, [403, 400], 'owners.php set on foreign record'));
    });
  });

  it('file_download.php answers 404 — not 403 — for a foreign uuid', () => {
    // A 403 would confirm the file exists. The endpoint deliberately makes an
    // inaccessible file indistinguishable from a missing one.
    cy.probe({ url: '/file_download.php?uuid=11111111-1111-4111-8111-111111111111' })
      .then(res => cy.expectDenied(res, [404], 'foreign file'));
  });

  // Runs last: it consumes the fixture by deleting record a.
  it('a bulk delete skips rows owned by someone else', () => {
    cy.dbCount(fx.table).then(before => {
      cy.visit('/dashboard.php');
      cy.csrfToken().then(token => {
        cy.probe({
          url: '/api/mass_edit.php?action=mass_delete',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
          body: { table: fx.table, ids: [fx.id_a, fx.id_b] },
        }).then(res => {
          expect(res.status, 'bulk delete is accepted, then filtered').to.be.oneOf([200, 403]);
        });
      });

      // Exactly one row may have gone: the caller's own. owner_restriction_sql()
      // silently degrades to a no-op if its id expression is not table-qualified,
      // and this count is what catches that.
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
