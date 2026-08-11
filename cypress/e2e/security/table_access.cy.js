// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/security/table_access.cy.js
// ============================================================================
// Security — Per-user frontend access (spw_config key "user_table_access")
//
// An admin restricts the "test" account to a subset of tables, views and
// printouts, then the same account is checked from the frontend side. The
// interesting assertions are the server-side ones: the menu hiding an entry
// proves nothing, so every case here also probes the endpoint directly.
//
// Covers user_allowed_items() / require_access() in includes/api_helpers.php,
// the filtering in public/api/schema.php, public/api/views.php,
// public/api/print.php and templates/menu.php, and the user_tables_* actions in
// includes/admin/users.php.
//
// The restriction is removed again in after() — an empty list means
// UNRESTRICTED, which is exactly the pre-test state.
// ============================================================================

const ALLOWED = 'companies';

let testUserId = null;
let deniedTable = null;
// Views and printouts are optional in a given install, so the specs that need a
// second one skip themselves rather than fail when the demo has fewer.
let allowedView = null;
let deniedView = null;
let allowedPrint = null;
let deniedPrint = null;

/**
 * Admin-side helper: POST an admin api.php action with the admin's CSRF token.
 * Kept local — no other spec drives the admin API this way.
 */
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

/**
 * Omitted scopes are sent as empty lists, i.e. explicitly unrestricted — the same
 * thing the admin UI posts when a group has nothing ticked.
 */
function setAccess({ tables = [], views = [], prints = [] } = {}) {
  return adminPost('user_tables_save', { user_id: testUserId, tables, views, prints }).then(res => {
    expect(res.status, 'user_tables_save status').to.eq(200);
    expect(res.body.status, `user_tables_save body: ${JSON.stringify(res.body)}`).to.eq('success');
  });
}

describe('Security – per-user table access', () => {
  before(() => {
    cy.seedDatabase();

    // Resolve the test user's id and pick a second, definitely-not-allowed table
    // from the live schema rather than hardcoding one that a demo reshuffle could
    // remove.
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

    // Views and printouts are read as admin, who is never restricted, so these are
    // the full configured lists.
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

    cy.then(() => setAccess({
      tables: [ALLOWED],
      views:  allowedView ? [allowedView] : [],
      prints: allowedPrint ? [allowedPrint] : [],
    }));
  });

  after(() => {
    // Restore the unrestricted state; leaving a restriction behind would break
    // every later spec that logs in as "test".
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
      // 400 would mean the request died at validation — that green would prove
      // nothing about the access check. The status distinction IS the test.
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
      // A subtable tab renders whole rows of the child table, so unlike an FK label
      // it must follow the restriction. Needs a row in the allowed table to open.
      cy.request({
        url: `/api.php?api=list&table=${ALLOWED}&limit=1`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        const rows = res.body.data || res.body.rows || [];
        if (rows.length === 0) {
          this.skip();
        }
        cy.visit(`/edit.php?table=${ALLOWED}&id=${rows[0].id}`);
        // Every rendered subtable tab must name a table the user may reach; the
        // restricted one must not appear anywhere in the form.
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
      // The three scopes are independent — this is the case a shared code path
      // breaks first.
      if (!allowedView) {
        this.skip();
      }
      loginAsAdmin();
      setAccess({ tables: [ALLOWED] });
      loginAsTestUser();
      cy.request('/api/views.php?action=list').then(res => {
        expect((res.body.views || []).length, 'views stay unrestricted').to.be.greaterThan(1);
      });
      // Put the fixture back for the remaining tests in this block.
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

  describe('back to unrestricted', () => {
    it('clearing the selection restores access to every table', () => {
      loginAsAdmin();
      setAccess();

      // No re-login in between on purpose: the allow-list is read per request,
      // never cached in the session, so revoking or granting must take effect
      // immediately.
      loginAsTestUser();
      cy.probe({ url: `/api.php?api=list&table=${deniedTable}` }).then(res => {
        expect(res.status, 'previously restricted table after clearing').to.eq(200);
      });
    });
  });
});
