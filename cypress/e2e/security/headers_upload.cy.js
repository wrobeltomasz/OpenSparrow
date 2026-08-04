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

/**
 * POST a real multipart upload from inside the page, yielding { status, body }.
 *
 * Two things this gets right that a cy.request() probe cannot:
 *
 *  1. `cy.request()` cannot send a FormData body — it serialises the object, so
 *     the request arrives with no file at all. Issuing the fetch from the page
 *     keeps the session cookie, the same origin and a genuine multipart body.
 *  2. `api/files.php` reads the action from `$_POST` on POST (see its dispatcher:
 *     `$action = $_POST['action'] ?? ''`). A query-string `?action=upload` is
 *     ignored, so the request dies with 400 "Unknown action or empty request
 *     payload" *before* a single upload check runs. It must be a form field.
 *
 * Both were true of the previous version of this spec, which meant every test
 * below asserted against the dispatcher's error instead of the upload gauntlet.
 */
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
    }).then(res => res.text().then(body => ({ status: res.status, body })));
  });
}

/**
 * Soft-delete an uploaded file through the API.
 *
 * The seed endpoint's cleanup walks only the tables in the schema config, and the
 * files table is a system table — so `action=cleanup` does NOT reclaim uploads
 * (it answers `{"cleaned":[]}`). Without this, the accepted-upload control case
 * leaves a row and a blob in storage/files/ on every single run.
 */
function deleteUpload(uuid, token) {
  return cy.window().then(win =>
    win.fetch('/api/files.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete', uuid, csrf_token: token }),
    }).then(res => {
      expect(res.status, 'probe upload must be cleaned up').to.eq(200);
    })
  );
}

/**
 * Assert an upload was refused with `code` and that the message names the check
 * that caught it — a 415 from the wrong branch is not the same guarantee.
 *
 * `cy.expectDenied` is a queued command while a bare `expect()` is synchronous,
 * so a plain `expect()` written after it would run *first* and report before the
 * status was ever checked. The message assertion is queued with `cy.then()` to
 * keep failures in the order they read.
 */
function expectUploadRefused(res, code, pattern, label) {
  cy.expectDenied(res, [code], label);
  cy.then(() => {
    expect(res.body, `${label}: message must name the failing check`).to.match(pattern);
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
        uploadProbe({ filename: 'evil.php', content, mime: 'application/x-php', token })
          .then(res => expectUploadRefused(res, 415, /not allowed/i, 'evil.php'));
      });
    });
  });

  it('rejects SVG at the extension allowlist, not just the content sniff', () => {
    // SVG is script-bearing markup and must never reach file_download.php, which
    // could serve it inline. `svg` used to sit in allowed_extensions, and
    // detectType() sorts it into the allowed 'other' category — so the only thing
    // refusing it was mimeMatchesExtension() having no map entry for `svg`. That
    // guard lives behind `if (class_exists('finfo'))`, which meant a build without
    // finfo would have stored it. It is off the allowlist now, so assert the
    // extension check is what answers: this fails if `svg` is ever re-added, even
    // though the upload would still be refused by the sniff on most builds.
    cy.csrfToken().then(token => {
      cy.fixture('evil.svg', 'utf8').then(content => {
        uploadProbe({ filename: 'evil.svg', content, mime: 'image/svg+xml', token })
          .then(res => expectUploadRefused(res, 415, /not allowed/i, 'evil.svg'));
      });
    });
  });

  it('rejects a file whose bytes do not match its extension', () => {
    // The extension allowlist alone is trivially bypassed by renaming. The finfo
    // sniff is what actually stops a PHP payload arriving as photo.png.
    cy.csrfToken().then(token => {
      cy.fixture('fake_png.png', 'utf8').then(content => {
        uploadProbe({ filename: 'fake_png.png', content, mime: 'image/png', token })
          .then(res => expectUploadRefused(res, 415, /does not match its extension/i, 'fake_png.png'));
      });
    });
  });

  it('rejects an oversized file', () => {
    cy.csrfToken().then(token => {
      // FILES_MAX_SIZE_MB defaults to 20; 24 MB clears it under any sane config.
      const oversized = 'A'.repeat(24 * 1024 * 1024);
      uploadProbe({ filename: 'cypress-big.txt', content: oversized, mime: 'text/plain', token })
        .then(res => {
          // 413 exactly. Both size paths answer 413 — the explicit max_file_size_mb
          // check and the post_max_size drop caught earlier in the dispatcher — so
          // there is no reason to tolerate 400 here. Tolerating it is what let this
          // test pass while every request was dying on "Unknown action" instead.
          cy.expectDenied(res, [413], 'oversized upload');
        });
    });
  });

  it('stores an accepted file under a server-generated name, not the client one', () => {
    // A client-controlled path on disk is how an upload turns into a webshell.
    cy.csrfToken().then(token => {
      // .csv is in allowed_extensions and maps to the allowed 'spreadsheet' type.
      // The previous probe used .txt, which is not on the allowlist, so the
      // control case could not have succeeded even with a well-formed request.
      uploadProbe({
        filename: 'cypress-upload-probe.csv',
        content: 'name,email\ncypress-probe,probe@example.test\n',
        mime: 'text/csv',
        token,
      }).then(res => {
        // The endpoint answers 201 Created. The old spec compared against 200 and
        // returned early when it did not match, turning a broken upload path into
        // a passing test — this must be a hard assertion.
        expect(res.status, 'control upload must be accepted').to.eq(201);
        expect(res.body, 'a uuid filename was assigned')
          .to.match(/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i);
        expect(res.body, 'the client filename must not become the stored name')
          .to.not.match(/cypress-upload-probe\.csv/);

        deleteUpload(JSON.parse(res.body).file.uuid, token);
      });
    });
  });

  it('the storage directory is not reachable over HTTP', () => {
    // Files are served only through file_download.php, which applies the session
    // and ownership checks. A direct path must never return storage contents.
    ['/storage/files/', '/storage/files/imports/', '/storage/'].forEach(path => {
      cy.probe({ url: path }).then(res => {
        if ([301, 302, 403, 404].includes(res.status)) return;

        // The PHP built-in server has no docroot isolation for paths it cannot
        // resolve: it falls back to index.php, so ANY unknown path — including
        // /zupelnie-nieistniejacy-katalog/ — answers 200 with the app shell.
        // Under Apache/nginx rooted at public/ these are a plain 404. Accept the
        // dev-server case, but prove what came back is the shell and not a
        // directory listing or file bytes.
        expect(res.status, `${path} unexpected status`).to.eq(200);
        const body = typeof res.body === 'string' ? res.body : JSON.stringify(res.body);
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
        }).then(res => {
          const body = typeof res.body === 'string' ? res.body : JSON.stringify(res.body);
          expect(body, 'SVG logo must be refused').to.not.match(/"status"\s*:\s*"(ok|success)"/);
        });
      });
    });
  });
});
