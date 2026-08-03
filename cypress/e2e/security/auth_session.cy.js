// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// cypress/e2e/security/auth_session.cy.js
// ============================================================================
// Security — session and login hardening
//
// Covers start_session() cookie flags and session_is_stale() (includes/session.php),
// the session_regenerate_id(true) on successful login, logout invalidation
// (public/logout.php), and the brute-force throttle backed by
// sys_table('login_attempts') (LOGIN_MAX_ATTEMPTS_PER_USERNAME, default 5).
//
// This spec never uses cy.session(): a cached session would hide exactly the
// identifier rotation it is here to observe.
// ============================================================================

const SEED_TOKEN = 'cypress-dev-seed';

/** Fetch login.php and pull the CSRF token out of the rendered form. */
function loginPageToken() {
  return cy.probe({ url: '/login.php' }).then(res => {
    const match = /name="csrf_token"\s+value="([^"]+)"/.exec(res.body);
    expect(match, 'csrf_token hidden field present on login.php').to.not.be.null;
    return match[1];
  });
}

/** POST credentials to login.php with a freshly minted token. Yields the response. */
function submitLogin(username, password) {
  return loginPageToken().then(token =>
    cy.probe({
      url: '/login.php',
      method: 'POST',
      form: true,
      body: { username, password, csrf_token: token },
    }));
}

function seedRequest(action, body = {}) {
  return cy.request({
    method: 'POST',
    url: '/cypress_seed.php',
    form: true,
    body: { token: SEED_TOKEN, action, ...body },
  });
}

describe('Security – session cookie flags', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('the session cookie is HttpOnly and SameSite-constrained', () => {
    cy.clearCookies();
    cy.probe({ url: '/login.php' }).then(res => {
      const raw = [].concat(res.headers['set-cookie'] || []).find(c => /^PHPSESSID=/.test(c));
      expect(raw, 'Set-Cookie for PHPSESSID').to.be.a('string');

      // HttpOnly keeps the session id out of reach of any injected script — the
      // single most valuable flag on this cookie, and non-negotiable.
      expect(raw, 'HttpOnly flag').to.match(/;\s*HttpOnly/i);
      expect(raw, 'SameSite flag').to.match(/;\s*SameSite=(Lax|Strict)/i);

      // Secure is driven by the SECURE_COOKIES env var. Over plain HTTP the test
      // runner must set it to false or no cookie would be stored at all, so this
      // is reported rather than asserted; on an HTTPS deployment it must be on.
      cy.task('log', `[security] PHPSESSID Secure flag: ${/;\s*Secure/i.test(raw)}`);
    });
  });

  it('the session identifier is rotated on successful login', () => {
    // Without session_regenerate_id(true) an attacker who plants a known session
    // id before login keeps holding a valid session afterwards (session fixation).
    cy.clearCookies();
    cy.probe({ url: '/login.php' });
    cy.getCookie('PHPSESSID').then(pre => {
      expect(pre, 'pre-login session cookie').to.not.be.null;
      submitLogin('test', 'test').then(res => {
        expect(res.status, 'login redirects on success').to.be.oneOf([302, 303]);
        cy.getCookie('PHPSESSID').then(post => {
          expect(post.value, 'session id must change across login').to.not.eq(pre.value);
        });
      });
    });
  });

  it('logout invalidates the session for the old cookie', () => {
    cy.clearCookies();
    submitLogin('test', 'test');
    cy.getCookie('PHPSESSID').then(cookie => {
      cy.probe({ url: '/dashboard.php' }).its('status').should('eq', 200);
      cy.probe({ url: '/logout.php' });

      // Re-present the pre-logout identifier explicitly: clearing the browser's
      // copy would prove nothing about server-side destruction.
      cy.probe({
        url: '/dashboard.php',
        headers: { Cookie: `PHPSESSID=${cookie.value}` },
      }).then(res => {
        expect(res.status, 'destroyed session must not authorise').to.be.oneOf([302, 303]);
        expect(res.headers.location).to.match(/login\.php/);
      });
    });
  });
});

describe('Security – login hardening', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    cy.clearCookies();
  });

  it('login.php rejects a POST without a CSRF token', () => {
    cy.probe({
      url: '/login.php',
      method: 'POST',
      form: true,
      body: { username: 'test', password: 'test' },
    }).then(res => cy.expectDenied(res, [403], 'login without token'));
  });

  it('an unknown user and a wrong password are indistinguishable', () => {
    // Different wording for the two cases turns the login form into a user
    // enumeration oracle.
    let unknownBody;
    submitLogin('cypress-nosuchuser', 'whatever').then(res => {
      unknownBody = /class="[^"]*error[^"]*"[^>]*>([^<]*)</i.exec(res.body);
      expect(unknownBody, 'error message rendered for unknown user').to.not.be.null;
      return submitLogin('test', 'definitely-wrong-password');
    }).then(res => {
      const wrongPass = /class="[^"]*error[^"]*"[^>]*>([^<]*)</i.exec(res.body);
      expect(wrongPass, 'error message rendered for wrong password').to.not.be.null;
      expect(wrongPass[1].trim(), 'identical message for both failures')
        .to.eq(unknownBody[1].trim());
    });
    // Two failures were just recorded against this IP — clear them so the shared
    // per-IP budget is not spent on the rest of the run.
    seedRequest('login_reset');
  });

  it('a malformed username yields the generic credential error, not a validation hint', () => {
    // Usernames are gated by /^[a-zA-Z0-9_.-]{3,50}$/ before the database is
    // touched; the rejection must not reveal that a separate check exists.
    submitLogin("' OR 1=1 --", 'x').then(res => {
      expect(res.status, 'no server error on a hostile username').to.eq(200);
      expect(res.body, 'no internals leaked').to.not.match(/SQLSTATE|pg_query|Fatal error/);
      expect(res.body, 'generic credential error').to.match(/Invalid credentials/i);
    });
  });

  it('locks an account out after LOGIN_MAX_ATTEMPTS_PER_USERNAME failures', () => {
    const victim = 'cypress-lock-user';
    seedRequest('login_reset');

    // Default limit is 5; the sixth attempt must be refused on the throttle
    // rather than on the credentials.
    Cypress._.times(6, () => submitLogin(victim, 'wrong-password'));

    submitLogin(victim, 'wrong-password').then(res => {
      expect(res.body, 'throttle message').to.match(/Too many failed attempts/i);
    });

    // Leave no throttle debt behind for the specs that run after this one.
    seedRequest('login_reset');
  });

  after(() => {
    seedRequest('login_reset');
  });
});

describe('Security – known gaps (documented, not yet fixed)', () => {
  before(() => {
    cy.seedDatabase();
  });

  // KNOWN GAP: /logout.php accepts GET and validates no CSRF token, so a third
  // party can force a logout. Impact is limited to denial of convenience, but the
  // behaviour is pinned here so that changing it is a deliberate decision rather
  // than an accident. Flip this assertion when logout is moved to POST + token.
  it('KNOWN: GET /logout.php logs the user out without a CSRF token', () => {
    cy.clearCookies();
    submitLogin('test', 'test');
    cy.probe({ url: '/logout.php' }).then(res => {
      expect(res.status, 'logout still answers to a bare GET').to.be.oneOf([302, 303]);
    });
    cy.probe({ url: '/dashboard.php' }).its('status').should('be.oneOf', [302, 303]);
  });
});
