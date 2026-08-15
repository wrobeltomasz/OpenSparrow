// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const TABLE = 'companies';

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

  ['mass_delete', 'mass_tag'].forEach(action => {
    it(`api/files.php action=${action} rejects an anonymous POST with 401`, () => {
      cy.probe({
        method: 'POST',
        url: '/api/files.php',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: { action, uuids: ['00000000-0000-4000-8000-000000000000'], tags: 'cypress' },
      }).then(res => cy.expectDenied(res, [401], `files.php ${action}`));
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
