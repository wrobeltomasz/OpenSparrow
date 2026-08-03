// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/security/authorization.cy.js
// ============================================================================
// Security — Authentication & authorisation gates
//
// Every request here is expected to be REFUSED. The point of the suite is not
// that the app works, but that it keeps saying no: an exact status code per
// surface, and no server internals in the error body.
//
// Covers the gates in includes/bootstrap.php (os_page_bootstrap /
// os_api_bootstrap), includes/api_helpers.php (requireLogin / requireWrite),
// public/admin/index.php and the per-action admin checks inside
// public/api/views.php and public/api/print.php.
// ============================================================================

const TABLE = 'companies';

// HTML controllers that must never render to an anonymous visitor.
const GUARDED_PAGES = [
  '/dashboard.php',
  '/calendar.php',
  '/board.php',
  '/views.php',
  '/print.php',
  '/files.php',
  `/create.php?table=${TABLE}`,
  `/edit.php?table=${TABLE}&id=1`,
];

// JSON endpoints that must answer 401 to an anonymous caller.
const GUARDED_APIS = [
  `/api.php?api=list&table=${TABLE}`,
  '/api/notifications.php?action=get_count',
  '/api/files.php?action=list',
  '/api/comments.php?action=counts',
  '/api/notes.php?action=list',
  '/api/owners.php?action=mine',
  '/api/views.php?action=list',
  '/api/print.php?action=list',
  '/api/rag.php?action=tags',
];

describe('Security – anonymous access', () => {
  beforeEach(() => {
    cy.clearCookies();
  });

  GUARDED_PAGES.forEach(path => {
    it(`${path} redirects an anonymous visitor to login.php`, () => {
      cy.probe({ url: path }).then(res => {
        expect(res.status, `${path} status`).to.be.oneOf([302, 303]);
        expect(res.headers.location, `${path} redirect target`).to.match(/login\.php/);
      });
    });
  });

  GUARDED_APIS.forEach(path => {
    it(`${path} rejects an anonymous caller with 401`, () => {
      cy.probe({ url: path, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => cy.expectDenied(res, [401], path));
    });
  });

  it('admin/index.php redirects an anonymous visitor to login.php', () => {
    cy.probe({ url: '/admin/index.php' }).then(res => {
      expect(res.status).to.be.oneOf([302, 303]);
      expect(res.headers.location).to.match(/login\.php/);
    });
  });

  it('admin/api.php rejects an anonymous caller with 401', () => {
    cy.probe({ url: '/admin/api.php?action=cron_stats' })
      .then(res => cy.expectDenied(res, [401], 'admin/api.php'));
  });

  it('setup_api.php is dead on a configured instance', () => {
    // config/database.json exists once the wizard has run, so the endpoint must
    // refuse to re-run init_database against a live database.
    cy.probe({ url: '/setup_api.php', method: 'POST', form: true, body: { action: 'init_database' } })
      .then(res => cy.expectDenied(res, [403, 404], 'setup_api.php'));
  });

  it('cypress_seed.php rejects a wrong token with 403', () => {
    cy.probe({
      url: '/cypress_seed.php',
      method: 'POST',
      form: true,
      body: { token: 'not-the-token', action: 'seed' },
    }).then(res => cy.expectDenied(res, [403, 404], 'cypress_seed.php'));
  });
});

describe('Security – editor may not act as admin', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('admin panel answers 403 Access Denied to an editor', () => {
    cy.probe({ url: '/admin/index.php' }).then(res => {
      expect(res.status, 'admin/index.php as editor').to.eq(403);
      expect(res.body, 'access denied page').to.match(/Access Denied/i);
    });
  });

  it('admin/api.php answers 403 to an editor', () => {
    cy.probe({ url: '/admin/api.php?action=cron_stats' }).then(res => {
      cy.expectDenied(res, [403], 'admin/api.php as editor');
      expect(JSON.stringify(res.body)).to.match(/admin/i);
    });
  });

  it('admin/api_migrations.php and api_csv_import.php answer 403 to an editor', () => {
    cy.probe({ url: '/admin/api_migrations.php?action=scan' })
      .then(res => cy.expectDenied(res, [403], 'api_migrations.php'));
    cy.probe({ url: '/admin/api_csv_import.php?action=csv_schemas' })
      .then(res => cy.expectDenied(res, [403], 'api_csv_import.php'));
  });

  // views.php / print.php gate their admin-only actions inside the action block
  // rather than at the bootstrap, so a non-admin falls through to a generic
  // 400/404 instead of a 403. The status is therefore not the interesting part —
  // what matters is that no configuration or schema listing comes back.
  const ADMIN_ONLY = [
    '/api/views.php?action=config',
    '/api/views.php?action=schemas',
    '/api/views.php?action=sync',
    '/api/print.php?action=config',
    '/api/print.php?action=columns',
  ];

  ADMIN_ONLY.forEach(path => {
    it(`${path} leaks no configuration to an editor`, () => {
      cy.probe({ url: path, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(res => {
        const body = typeof res.body === 'string' ? res.body : JSON.stringify(res.body || '');
        expect(res.status, `${path} must not succeed with data`).to.not.eq(200);
        expect(body, `${path} must not return config`).to.not.match(/"(views|printouts|templates|schemas|columns)"\s*:/);
      });
    });
  });

  it('require_ajax endpoints refuse a plain browser request', () => {
    // os_api_bootstrap(['require_ajax' => true]) — a cross-origin form post or an
    // <img> tag cannot set X-Requested-With, so this header is a cheap extra
    // barrier in front of the CSRF-exempt GET-only endpoints.
    ['/api/schema.php', '/api/fk.php'].forEach(path => {
      cy.probe({ url: path }).then(res => cy.expectDenied(res, [403], path));
    });
  });
});

describe('Security – admin is kept out of the frontend data API', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsAdmin();
  });

  [
    `/api.php?api=list&table=${TABLE}`,
    `/api.php?api=subtable_counts&table=${TABLE}&ids=1`,
    '/api/mass_edit.php?action=mass_edit_preview',
    '/api/data_cleanup.php?action=data_cleanup_preview',
  ].forEach(path => {
    it(`${path} refuses an admin session`, () => {
      cy.probe({ url: path, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => cy.expectDenied(res, [400, 403, 405], path));
    });
  });
});
