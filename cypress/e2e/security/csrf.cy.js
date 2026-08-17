// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const TABLE = 'companies';
const ADMIN_API_SRC = 'public/admin/api.php';

function phpArrayStrings(source, varName) {
  const start = source.indexOf(`$${varName} = [`);
  expect(start, `$${varName} found in ${ADMIN_API_SRC}`).to.be.greaterThan(-1);
  const end = source.indexOf('];', start);
  expect(end, `$${varName} literal terminated`).to.be.greaterThan(start);
  const block = source.slice(start, end);
  return (block.match(/'([a-z0-9_]+)'/g) || []).map(s => s.slice(1, -1));
}

describe('Security – CSRF on the frontend APIs', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  const HEADER_ENDPOINTS = [
    { url: `/api.php?table=${TABLE}`, method: 'POST' },
    { url: `/api.php?table=${TABLE}&id=1`, method: 'PATCH' },
    { url: `/api.php?table=${TABLE}&id=1`, method: 'DELETE' },
    { url: '/api/notes.php?action=add', method: 'POST' },
    { url: '/api/notifications.php?action=mark_read', method: 'POST' },
    { url: '/api/mass_edit.php?action=mass_edit_apply', method: 'POST' },
    { url: '/api/data_cleanup.php?action=data_cleanup_apply', method: 'POST' },
  ];

  HEADER_ENDPOINTS.forEach(({ url, method }) => {
    it(`${method} ${url} without X-CSRF-Token is rejected`, () => {
      cy.probe({
        url,
        method,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { name: 'cypress-csrf-probe' },
      }).then(result => {
        cy.expectDenied(result, [403], `${method} ${url}`);
        expect(JSON.stringify(result.body), 'CSRF error message').to.match(/CSRF/i);
      });
    });
  });

  it('rejects a CSRF token minted for a different session', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(ownToken => {
      const forged = ownToken.split('').reverse().join('');
      cy.probe({
        url: `/api.php?table=${TABLE}`,
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': forged },
        body: { name: 'cypress-csrf-probe' },
      }).then(result => {
        cy.expectDenied(result, [403], 'forged token');
        expect(JSON.stringify(result.body)).to.match(/CSRF/i);
      });
    });
  });

  it('a valid token is accepted (the gate is not simply refusing everything)', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      cy.probe({
        url: `/api.php?api=list&table=${TABLE}`,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
      }).its('status').should('eq', 200);
    });
  });

  const BODY_TOKEN_ACTIONS = [
    { endpoint: '/api/comments.php', action: 'add', body: { related_table: TABLE, related_id: 1, body: 'cypress' } },
    { endpoint: '/api/comments.php', action: 'delete', body: { id: 1 } },
    { endpoint: '/api/owners.php', action: 'set', body: { table: TABLE, record_id: 1, owner_id: 1 } },
    { endpoint: '/api/owners.php', action: 'mass_set', body: { table: TABLE, ids: [1], owner_id: 1 } },
    { endpoint: '/api/files.php', action: 'mass_delete', body: { uuids: [] } },
    { endpoint: '/api/files.php', action: 'mass_tag', body: { uuids: [], tags: 'cypress' } },
  ];

  BODY_TOKEN_ACTIONS.forEach(({ endpoint, action, body }) => {
    it(`POST ${endpoint} action=${action} without a csrf_token field is rejected`, () => {
      cy.probe({
        url: `${endpoint}?action=${action}`,
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action, ...body },
      }).then(result => cy.expectDenied(result, [403], `${endpoint} ${action}`));
    });
  });

  it('PUT never mutates: it is outside the CSRF-validated verb set', () => {
    cy.dbCount(TABLE).then(before => {
      [`/api.php?table=${TABLE}&id=1`, '/api/notes.php?action=update', '/api/files.php?action=update_meta']
        .forEach(url => {
          cy.probe({
            url,
            method: 'PUT',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: { table: TABLE, id: 1, action: 'update', name: 'cypress-csrf-probe' },
          }).then(result => {
            const body = typeof result.body === 'string' ? result.body : JSON.stringify(result.body || '');
            expect(body, `PUT ${url} must not report success`)
              .to.not.match(/"(ok|success)"\s*:\s*true/);
          });
        });

      cy.dbCount(TABLE).should('eq', before);
    });
  });
});

describe('Security – CSRF on the admin API', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
  });

  it('every $postActions entry rejects GET with 405', () => {
    cy.readFile(ADMIN_API_SRC).then(source => {
      phpArrayStrings(source, 'postActions').forEach(action => {
        cy.probe({ url: `/admin/api.php?action=${action}` }).then(result => {
          expect(result.status, `GET action=${action}`).to.eq(405);
        });
      });
    });
  });

  it('every $postActions entry rejects POST without a token with 403', () => {
    cy.readFile(ADMIN_API_SRC).then(source => {
      phpArrayStrings(source, 'postActions').forEach(action => {
        cy.probe({
          url: `/admin/api.php?action=${action}`,
          method: 'POST',
          body: {},
        }).then(result => {
          expect(result.status, `POST action=${action} without token`).to.eq(403);
        });
      });
    });
  });

  const MUTATING_NAME = /^(save|init_db|run_|set_|create_|delete_|add_|upload_|remove_|backup_|demo_)|(_save|_delete|_add|_upload|_purge_log|_toggle|_change_password|_update_role|_rechunk|_rechunk_all)$/;

  const READ_ONLY_EXCEPTIONS = {
    sync_schema: 'SELECTs information_schema only — no write',
    dashboard_calculate: 'runs an unsaved widget query for preview — no write',
  };

  it('no mutating admin action is missing from $postActions', () => {
    cy.readFile(ADMIN_API_SRC).then(source => {
      const postActions = phpArrayStrings(source, 'postActions');
      const allActions = phpArrayStrings(source, 'adminModules');

      const missing = allActions.filter(a =>
        MUTATING_NAME.test(a) &&
        !postActions.includes(a) &&
        !(a in READ_ONLY_EXCEPTIONS));

      expect(
        missing,
        'mutating actions absent from $postActions — reachable via GET, therefore CSRF-able. ' +
        'Add them to $postActions in public/admin/api.php, or document them in ' +
        'READ_ONLY_EXCEPTIONS here if they genuinely do not write',
      ).to.deep.eq([]);
    });
  });
});
