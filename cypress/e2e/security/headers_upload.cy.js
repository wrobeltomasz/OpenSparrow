// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const SEED_TOKEN = 'cypress-dev-seed';

function uploadProbe({ filename, content, mime, token }) {
  return cy.window().then(win => {
    const form = new win.FormData();
    form.append('action', 'upload');
    form.append('csrf_token', token);
    form.append('file', new win.Blob([content], { type: mime }), filename);

    return win.fetch('/api/files.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: form,
    }).then(result => result.text().then(body => ({ status: result.status, body })));
  });
}

function deleteUpload(uuid, token) {
  return cy.window().then(win =>
    win.fetch('/api/files.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete', uuid, csrf_token: token }),
    }).then(result => {
      expect(result.status, 'probe upload must be cleaned up').to.eq(200);
    })
  );
}

function expectUploadRefused(result, code, pattern, label) {
  cy.expectDenied(result, [code], label);
  cy.then(() => {
    expect(result.body, `${label}: message must name the failing check`).to.match(pattern);
  });
}

describe('Security – response headers', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('sends the baseline hardening headers on an application page', () => {
    cy.probe({ url: '/dashboard.php' }).then(result => {
      expect(result.status).to.eq(200);
      expect(result.headers['x-frame-options'], 'clickjacking').to.match(/^DENY$/i);
      expect(result.headers['x-content-type-options'], 'MIME sniffing').to.eq('nosniff');
      expect(result.headers['referrer-policy']).to.eq('strict-origin-when-cross-origin');
    });
  });

  it('sends a CSP with a per-request nonce and no unsafe-eval', () => {
    cy.probe({ url: '/dashboard.php' }).then(first => {
      const csp = first.headers['content-security-policy'];
      expect(csp, 'CSP present').to.be.a('string');
      expect(csp, 'scripts locked to self + nonce').to.match(/script-src 'self' 'nonce-[a-f0-9]+'/);
      expect(csp, 'unsafe-eval must never appear').to.not.match(/unsafe-eval/);

      const nonce = /'nonce-([a-f0-9]+)'/.exec(csp)[1];
      cy.probe({ url: '/dashboard.php' }).then(second => {
        const nonce2 = /'nonce-([a-f0-9]+)'/.exec(second.headers['content-security-policy'])[1];
        expect(nonce2, 'nonce must be per-request').to.not.eq(nonce);
      });
    });
  });

  it('does not advertise the server stack', () => {
    cy.probe({ url: '/dashboard.php' }).then(result => {
      expect(result.headers['x-powered-by'], 'X-Powered-By').to.be.undefined;
      expect(JSON.stringify(result.headers), 'no PHP version in headers').to.not.match(/PHP\/\d/);
    });
  });

  it('the file proxy denies every resource type via CSP', () => {
    cy.probe({ url: '/file_download.php?uuid=11111111-1111-4111-8111-111111111111' }).then(result => {
      expect(result.headers['content-security-policy']).to.match(/default-src 'none'/);
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
        uploadProbe({ filename: 'evil.php', content, mime: 'application/x-php', token })
          .then(result => expectUploadRefused(result, 415, /not allowed/i, 'evil.php'));
      });
    });
  });

  it('rejects SVG at the extension allowlist, not just the content sniff', () => {
    cy.csrfToken().then(token => {
      cy.fixture('evil.svg', 'utf8').then(content => {
        uploadProbe({ filename: 'evil.svg', content, mime: 'image/svg+xml', token })
          .then(result => expectUploadRefused(result, 415, /not allowed/i, 'evil.svg'));
      });
    });
  });

  it('rejects a file whose bytes do not match its extension', () => {
    cy.csrfToken().then(token => {
      cy.fixture('fake_png.png', 'utf8').then(content => {
        uploadProbe({ filename: 'fake_png.png', content, mime: 'image/png', token })
          .then(result => expectUploadRefused(result, 415, /does not match its extension/i, 'fake_png.png'));
      });
    });
  });

  it('rejects an oversized file', () => {
    cy.csrfToken().then(token => {
      const oversized = 'A'.repeat(24 * 1024 * 1024);
      uploadProbe({ filename: 'cypress-big.txt', content: oversized, mime: 'text/plain', token })
        .then(result => {
          cy.expectDenied(result, [413], 'oversized upload');
        });
    });
  });

  it('stores an accepted file under a server-generated name, not the client one', () => {
    cy.csrfToken().then(token => {
      uploadProbe({
        filename: 'cypress-upload-probe.csv',
        content: 'name,email\ncypress-probe,probe@example.test\n',
        mime: 'text/csv',
        token,
      }).then(result => {
        expect(result.status, 'control upload must be accepted').to.eq(201);
        expect(result.body, 'a uuid filename was assigned')
          .to.match(/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i);
        expect(result.body, 'the client filename must not become the stored name')
          .to.not.match(/cypress-upload-probe\.csv/);

        deleteUpload(JSON.parse(result.body).file.uuid, token);
      });
    });
  });

  it('the storage directory is not reachable over HTTP', () => {
    ['/storage/files/', '/storage/files/imports/', '/storage/'].forEach(path => {
      cy.probe({ url: path }).then(result => {
        if ([301, 302, 403, 404].includes(result.status)) return;

        expect(result.status, `${path} unexpected status`).to.eq(200);
        const body = typeof result.body === 'string' ? result.body : JSON.stringify(result.body);
        expect(body, `${path} served the app shell, not storage`)
          .to.match(/<title>OpenSparrow/);
        expect(body, `${path} must not be a directory listing`)
          .to.not.match(/Index of |Directory listing/i);
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
        }).then(result => {
          const body = typeof result.body === 'string' ? result.body : JSON.stringify(result.body);
          expect(body, 'SVG logo must be refused').to.not.match(/"status"\s*:\s*"(ok|success)"/);
        });
      });
    });
  });
});
