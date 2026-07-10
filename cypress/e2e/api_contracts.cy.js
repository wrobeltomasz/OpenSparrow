// cypress/e2e/api_contracts.cy.js
// ============================================================================
// API Contract Tests — pure cy.request(), no DOM
//
// Asserts the JSON shapes and auth/CSRF/role gates of the backend endpoints
// the frontend depends on. These catch contract drift (renamed keys, changed
// status codes) long before an e2e spec fails on a missing DOM element.
//
// Covered:
//  - api.php            auth gate (401), admin block (403), CSRF gate (403),
//                       api=list shape, action=i18n_bundle shape, api=board shape
//  - api/notifications.php  get_count / get_list shapes
//  - api/files.php      action=list shape
// ============================================================================

const BASE = 'http://localhost:8080';

/** Parse a response body that may arrive as a string or as parsed JSON. */
function asJson(body) {
  return typeof body === 'string' ? JSON.parse(body) : body;
}

// ============================================================================
// Test Suite: Unauthenticated requests are rejected
// ============================================================================

describe('OpenSparrow – API Contracts: Auth Gate', () => {
  beforeEach(() => {
    cy.clearCookies();
  });

  it('api.php rejects unauthenticated GET with 401 JSON', () => {
    cy.request({
      url: `${BASE}/api.php?api=list&table=companies`,
      failOnStatusCode: false,
    }).then(res => {
      expect(res.status).to.eq(401);
      expect(asJson(res.body).error, 'error message').to.eq('Unauthorized');
    });
  });

  it('api/notifications.php rejects unauthenticated GET with 401', () => {
    cy.request({
      url: `${BASE}/api/notifications.php?action=get_count`,
      failOnStatusCode: false,
    }).then(res => {
      expect(res.status).to.eq(401);
    });
  });

  it('api/files.php rejects unauthenticated GET with 401', () => {
    cy.request({
      url: `${BASE}/api/files.php?action=list`,
      failOnStatusCode: false,
    }).then(res => {
      expect(res.status).to.eq(401);
    });
  });

  it('api/files.php bulk actions reject unauthenticated POST with 401', () => {
    ['mass_delete', 'mass_tag'].forEach(action => {
      cy.request({
        method: 'POST',
        url: `${BASE}/api/files.php`,
        body: { action, uuids: ['00000000-0000-4000-8000-000000000000'], tags: 'x' },
        failOnStatusCode: false,
      }).then(res => {
        expect(res.status, `${action} status`).to.eq(401);
      });
    });
  });
});

// ============================================================================
// Test Suite: CSRF and role gates
// ============================================================================

describe('OpenSparrow – API Contracts: CSRF & Roles', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('POST to api.php without X-CSRF-Token header is rejected with 403', () => {
    loginAsTestUser();
    cy.request({
      method: 'POST',
      url: `${BASE}/api.php?table=companies`,
      body: {},
      failOnStatusCode: false,
    }).then(res => {
      expect(res.status).to.eq(403);
      expect(asJson(res.body).error, 'error message').to.match(/CSRF/i);
    });
  });

  it('admin accounts are blocked from the frontend data API with 403', () => {
    loginAsAdmin();
    cy.request({
      url: `${BASE}/api.php?api=list&table=companies`,
      failOnStatusCode: false,
    }).then(res => {
      expect(res.status).to.eq(403);
      expect(asJson(res.body).error, 'error message').to.match(/admin/i);
    });
  });
});

// ============================================================================
// Test Suite: Response shapes (as editor test user)
// ============================================================================

describe('OpenSparrow – API Contracts: Response Shapes', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('api=list returns columns/rows/truncated/total/table', () => {
    cy.request(`${BASE}/api.php?api=list&table=companies`).then(res => {
      expect(res.status).to.eq(200);
      const data = asJson(res.body);
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
    cy.request(`${BASE}/api.php?api=list&table=companies`).then(res => {
      const { columns, rows } = asJson(res.body);
      if (rows.length === 0) {
        Cypress.log({ message: 'No rows — column check skipped' });
        return;
      }
      columns.forEach(col => {
        expect(rows[0], `row has column "${col}"`).to.have.property(col);
      });
    });
  });

  it('action=i18n_bundle returns a flat translation map', () => {
    cy.request(`${BASE}/api.php?action=i18n_bundle`).then(res => {
      expect(res.status).to.eq(200);
      const bundle = asJson(res.body);
      expect(bundle, 'bundle').to.be.an('object');
      expect(bundle['common.save'], 'common.save key').to.be.a('string').and.not.be.empty;
      // Flat map: no nested namespace objects at the top level
      expect(bundle.common, 'no nested "common" object').to.be.undefined;
    });
  });

  it('api=board returns a configured flag; lanes when configured', () => {
    cy.request(`${BASE}/api.php?api=board`).then(res => {
      expect(res.status).to.eq(200);
      const data = asJson(res.body);
      expect(data.configured, 'configured flag').to.be.a('boolean');
      if (data.configured) {
        expect(data.columns, 'lanes').to.be.an('array');
        expect(data.cards, 'cards').to.be.an('array');
      }
    });
  });

  it('notifications get_count returns status and numeric count', () => {
    cy.request(`${BASE}/api/notifications.php?action=get_count`).then(res => {
      expect(res.status).to.eq(200);
      const data = asJson(res.body);
      expect(data.status, 'status').to.eq('success');
      expect(data.count, 'count').to.be.a('number');
    });
  });

  it('notifications get_list returns a notifications array', () => {
    cy.request(`${BASE}/api/notifications.php?action=get_list`).then(res => {
      expect(res.status).to.eq(200);
      const data = asJson(res.body);
      expect(data.status, 'status').to.eq('success');
      expect(data.notifications, 'notifications').to.be.an('array');
    });
  });

  it('files action=list returns a files array', () => {
    cy.request(`${BASE}/api/files.php?action=list`).then(res => {
      expect(res.status).to.eq(200);
      const data = asJson(res.body);
      expect(data.files, 'files').to.be.an('array');
    });
  });

  it('api=list with an unknown table does not return 200', () => {
    cy.request({
      url: `${BASE}/api.php?api=list&table=definitely_not_a_table`,
      failOnStatusCode: false,
    }).then(res => {
      // Currently surfaces as 500 via the global catch; any 4xx/5xx is a pass —
      // the contract is only that unknown tables never return data.
      expect(res.status, 'unknown table must be rejected').to.be.gte(400);
    });
  });
});
