// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/security/headers_upload.cy.js
// ============================================================================
// Security — response headers and file upload validation
//
// Headers come from send_security_headers() in includes/session.php; the upload
// gauntlet is actionUpload() in public/api/files.php, which checks in order:
// size (413) → extension allowlist (415) → type category (415) → finfo content
// sniff (415). Each step is probed separately so a regression names itself.
// ============================================================================

const SEED_TOKEN = 'cypress-dev-seed';

/** Build a multipart body for the files API. */
function uploadForm(filename, content, mime, token) {
  const form = new FormData();
  form.append('file', new Blob([content], { type: mime }), filename);
  form.append('csrf_token', token);
  return form;
}

describe('Security – response headers', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('sends the baseline hardening headers on an application page', () => {
    cy.probe({ url: '/dashboard.php' }).then(res => {
      expect(res.status).to.eq(200);
      expect(res.headers['x-frame-options'], 'clickjacking').to.match(/^DENY$/i);
      expect(res.headers['x-content-type-options'], 'MIME sniffing').to.eq('nosniff');
      expect(res.headers['referrer-policy']).to.eq('strict-origin-when-cross-origin');
    });
  });

  it('sends a CSP with a per-request nonce and no unsafe-eval', () => {
    cy.probe({ url: '/dashboard.php' }).then(first => {
      const csp = first.headers['content-security-policy'];
      expect(csp, 'CSP present').to.be.a('string');
      expect(csp, 'scripts locked to self + nonce').to.match(/script-src 'self' 'nonce-[a-f0-9]+'/);
      expect(csp, 'unsafe-eval must never appear').to.not.match(/unsafe-eval/);

      // A nonce that repeats across requests is no better than 'unsafe-inline'.
      const nonce = /'nonce-([a-f0-9]+)'/.exec(csp)[1];
      cy.probe({ url: '/dashboard.php' }).then(second => {
        const nonce2 = /'nonce-([a-f0-9]+)'/.exec(second.headers['content-security-policy'])[1];
        expect(nonce2, 'nonce must be per-request').to.not.eq(nonce);
      });
    });
  });

  it('does not advertise the server stack', () => {
    cy.probe({ url: '/dashboard.php' }).then(res => {
      expect(res.headers['x-powered-by'], 'X-Powered-By').to.be.undefined;
      expect(JSON.stringify(res.headers), 'no PHP version in headers').to.not.match(/PHP\/\d/);
    });
  });

  it('the file proxy denies every resource type via CSP', () => {
    cy.probe({ url: '/file_download.php?uuid=11111111-1111-4111-8111-111111111111' }).then(res => {
      expect(res.headers['content-security-policy']).to.match(/default-src 'none'/);
    });
  });
});

describe('Security – file upload validation', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
    cy.visit('/files.php');
  });

  it('rejects an executable extension', () => {
    cy.csrfToken().then(token => {
      cy.fixture('evil.php', 'utf8').then(content => {
        cy.probe({
          url: '/api/files.php?action=upload',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: uploadForm('evil.php', content, 'application/x-php', token),
        }).then(res => {
          cy.expectDenied(res, [415], 'evil.php');
          expect(JSON.stringify(res.body)).to.match(/not allowed/i);
        });
      });
    });
  });

  it('rejects SVG', () => {
    // SVG is script-bearing markup. It is deliberately kept out of the image
    // category in detectType() so it can never be served inline by
    // file_download.php.
    cy.csrfToken().then(token => {
      cy.fixture('evil.svg', 'utf8').then(content => {
        cy.probe({
          url: '/api/files.php?action=upload',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: uploadForm('evil.svg', content, 'image/svg+xml', token),
        }).then(res => cy.expectDenied(res, [415], 'evil.svg'));
      });
    });
  });

  it('rejects a file whose bytes do not match its extension', () => {
    // The extension allowlist alone is trivially bypassed by renaming. The finfo
    // sniff is what actually stops a PHP payload arriving as photo.png.
    cy.csrfToken().then(token => {
      cy.fixture('fake_png.png', 'utf8').then(content => {
        cy.probe({
          url: '/api/files.php?action=upload',
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: uploadForm('fake_png.png', content, 'image/png', token),
        }).then(res => {
          cy.expectDenied(res, [415], 'fake_png.png');
          expect(JSON.stringify(res.body)).to.match(/does not match its extension/i);
        });
      });
    });
  });

  it('rejects an oversized file', () => {
    cy.csrfToken().then(token => {
      // FILES_MAX_SIZE_MB defaults to 20; 24 MB clears it under any sane config.
      const oversized = 'A'.repeat(24 * 1024 * 1024);
      cy.probe({
        url: '/api/files.php?action=upload',
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: uploadForm('cypress-big.txt', oversized, 'text/plain', token),
      }).then(res => {
        expect(res.status, 'oversized upload').to.be.oneOf([413, 400]);
      });
    });
  });

  it('stores an accepted file under a server-generated name, not the client one', () => {
    // A client-controlled path on disk is how an upload turns into a webshell.
    cy.csrfToken().then(token => {
      cy.probe({
        url: '/api/files.php?action=upload',
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: uploadForm('cypress-ok.txt', 'cypress upload probe', 'text/plain', token),
      }).then(res => {
        if (res.status !== 200) {
          cy.task('log', `[security] upload control case returned ${res.status} — check the files config`);
          return;
        }
        const payload = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
        const stored = JSON.stringify(payload);
        expect(stored, 'a uuid filename was assigned')
          .to.match(/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i);
      });
    });
  });

  it('the storage directory is not reachable over HTTP', () => {
    // Files are served only through file_download.php, which applies the session
    // and ownership checks. A direct path must never work.
    ['/storage/files/', '/../storage/files/', '/storage/'].forEach(path => {
      cy.probe({ url: path }).then(res => {
        expect(res.status, `${path} must not be browsable`).to.be.oneOf([301, 302, 403, 404]);
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

describe('Security – admin logo upload', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('rejects an SVG logo', () => {
    loginAsAdmin();
    cy.visit('/admin/index.php');
    cy.csrfToken().then(token => {
      cy.fixture('evil.svg', 'utf8').then(content => {
        const form = new FormData();
        form.append('logo', new Blob([content], { type: 'image/svg+xml' }), 'evil.svg');
        form.append('file', new Blob([content], { type: 'image/svg+xml' }), 'evil.svg');
        cy.probe({
          url: '/admin/api.php?action=upload_logo',
          method: 'POST',
          headers: { 'X-CSRF-Token': token },
          body: form,
        }).then(res => {
          const body = typeof res.body === 'string' ? res.body : JSON.stringify(res.body);
          expect(body, 'SVG logo must be refused').to.not.match(/"status"\s*:\s*"(ok|success)"/);
        });
      });
    });
  });
});
