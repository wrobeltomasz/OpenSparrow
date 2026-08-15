// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const TABLE = 'companies';
const SEED_TOKEN = 'cypress-dev-seed';

const SQL_PAYLOADS = [
  "' OR '1'='1",
  "1; DROP TABLE spw_users; --",
  "1 UNION SELECT null, version()",
  "') OR 1=1 --",
  "%' --",
];

function asJson(body) {
  return typeof body === 'string' ? JSON.parse(body) : body;
}

function rowsOf(payload) {
  return payload.rows || payload.data || payload.records || [];
}

describe('Security – SQL injection resistance', () => {
  let textColumn = null;
  let cleanCount = null;

  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('reads the table schema and a clean baseline', () => {
    cy.probe({
      url: `/api.php?api=schema&table=${TABLE}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(res.status, 'schema readable').to.eq(200);
      const schema = asJson(res.body);
      const cfg = schema.tables ? schema.tables[TABLE] : schema;
      const columns = cfg.columns || {};
      textColumn = Object.keys(columns).find(c => {
        const type = String(columns[c].type || '').toLowerCase();
        return c !== 'id' && (type === '' || type.includes('text') || type.includes('char'));
      });
      expect(textColumn, `a text column on ${TABLE}`).to.be.a('string');
    });

    cy.probe({
      url: `/api.php?api=list&table=${TABLE}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(res.status).to.eq(200);
      cleanCount = rowsOf(asJson(res.body)).length;
      cy.task('log', `[security] baseline row count for ${TABLE}: ${cleanCount}`);
    });
  });

  SQL_PAYLOADS.forEach(payload => {
    it(`search=${payload} neither errors nor widens the result set`, () => {
      cy.probe({
        url: `/api.php?api=list&table=${TABLE}&search=${encodeURIComponent(payload)}`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        expect(res.status, 'no server error').to.eq(200);
        expect(JSON.stringify(res.body), 'no database internals leaked')
          .to.not.match(/SQLSTATE|pg_query|syntax error at or near/i);
        const rows = rowsOf(asJson(res.body));

        expect(rows.length, 'payload treated as a literal search term')
          .to.be.at.most(cleanCount);
      });
    });

    it(`filter_val=${payload} is bound, not interpolated`, () => {
      cy.probe({
        url: `/api.php?api=list&table=${TABLE}&filter_col=${textColumn}&filter_val=${encodeURIComponent(payload)}`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(res => {
        expect(res.status).to.eq(200);
        expect(JSON.stringify(res.body)).to.not.match(/SQLSTATE|pg_query/i);
        expect(rowsOf(asJson(res.body)).length, 'equality filter matches nothing').to.eq(0);
      });
    });
  });

  it('an unknown table is refused without echoing the schema', () => {
    cy.probe({
      url: '/api.php?api=list&table=spw_users',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      cy.expectDenied(res, [400, 403, 404], 'spw_users via grid API');
    });
  });

  it('an unknown filter column is ignored rather than injected', () => {
    cy.probe({
      url: `/api.php?api=list&table=${TABLE}&filter_col=${encodeURIComponent("id) OR (1=1")}&filter_val=x`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(res.status, 'no server error').to.eq(200);
      expect(JSON.stringify(res.body)).to.not.match(/SQLSTATE|syntax error/i);
    });
  });

  it('a hostile offset does not reach SQL', () => {
    cy.probe({
      url: `/api.php?api=list&table=${TABLE}&offset=${encodeURIComponent('0; DROP TABLE spw_users')}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(res.status).to.eq(200);
      expect(JSON.stringify(res.body)).to.not.match(/SQLSTATE|syntax error/i);
    });
  });

  it('the probes left the database intact', () => {
    cy.dbCount(TABLE).should('be.a', 'number');
    cy.probe({
      url: `/api.php?api=list&table=${TABLE}`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      expect(rowsOf(asJson(res.body)).length, 'clean query still returns the baseline')
        .to.eq(cleanCount);
    });
  });
});

describe('Security – stored XSS', () => {
  const PAYLOAD = '<img src=x onerror="window.__xss=1">cypress-xss';

  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('a script payload stored in a record is rendered as text, never as markup', () => {
    cy.visit('/dashboard.php');
    cy.csrfToken().then(token => {
      cy.probe({
        url: `/api.php?api=schema&table=${TABLE}`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(schemaRes => {
        const schema = asJson(schemaRes.body);
        const cfg = schema.tables ? schema.tables[TABLE] : schema;
        const columns = cfg.columns || {};
        const col = Object.keys(columns).find(c => {
          const type = String(columns[c].type || '').toLowerCase();
          return c !== 'id' && (type === '' || type.includes('text') || type.includes('char'));
        });

        cy.probe({
          url: '/api.php',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
          body: { table: TABLE, data: { [col]: PAYLOAD } },
        }).then(res => {
          expect(res.status, 'record created for the render test').to.be.oneOf([200, 201]);
          const newId = asJson(res.body).id;
          expect(newId, 'new record id returned').to.not.be.oneOf([null, undefined]);

          cy.visit(`/edit.php?table=${TABLE}&id=${newId}`);
          cy.window().then(win => {
            expect(win.__xss, 'onerror handler must never fire').to.be.undefined;
          });
          cy.get('body').then($body => {
            expect($body.find('img[onerror]').length, 'payload must not become an element').to.eq(0);
            expect($body.find('script:contains("__xss")').length, 'payload must not become a script').to.eq(0);
          });

          cy.get(`[value*="cypress-xss"], :contains("cypress-xss")`).should('exist');
        });
      });
    });
  });

  after(() => {
    cy.request({
      method: 'POST',
      url: '/cypress_seed.php',
      form: true,
      body: { token: SEED_TOKEN, action: 'cleanup' },
    });
  });
});

describe('Security – path traversal', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  [
    '../../includes/config.php',
    '..%2f..%2fincludes%2fconfig.php',
    '....//....//includes/config.php',
    '%2e%2e%2f%2e%2e%2fconfig%2fdatabase.json',
    '/etc/passwd',
  ].forEach(payload => {
    it(`file_download.php refuses uuid=${payload}`, () => {
      cy.probe({ url: `/file_download.php?uuid=${payload}` }).then(res => {
        cy.expectDenied(res, [400, 404], `uuid=${payload}`);
        expect(String(res.body), 'no file contents returned')
          .to.not.match(/DB_HOST|password|<\?php/i);
      });
    });
  });

  it('config and includes are not served from the docroot', () => {
    cy.clearCookies();
    ['/config/database.json', '/includes/config.php', '/../includes/config.php', '/.env', '/.git/config']
      .forEach(path => {
        cy.probe({ url: path }).then(res => {
          expect(res.status, `${path} must not be served`).to.not.eq(200);
        });
      });
  });
});
