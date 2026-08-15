// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const SEED_TOKEN = 'cypress-dev-seed';

function loginPageToken() {
  return cy.probe({ url: '/login.php' }).then(res => {
    const match = /name="csrf_token"\s+value="([^"]+)"/.exec(res.body);
    expect(match, 'csrf_token hidden field present on login.php').to.not.be.null;
    return match[1];
  });
}

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

      expect(raw, 'HttpOnly flag').to.match(/;\s*HttpOnly/i);
      expect(raw, 'SameSite flag').to.match(/;\s*SameSite=(Lax|Strict)/i);

      cy.task('log', `[security] PHPSESSID Secure flag: ${/;\s*Secure/i.test(raw)}`);
    });
  });

  it('the session identifier is rotated on successful login', () => {
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

    seedRequest('login_reset');
  });

  it('a malformed username yields the generic credential error, not a validation hint', () => {
    submitLogin("' OR 1=1 --", 'x').then(res => {
      expect(res.status, 'no server error on a hostile username').to.eq(200);
      expect(res.body, 'no internals leaked').to.not.match(/SQLSTATE|pg_query|Fatal error/);
      expect(res.body, 'generic credential error').to.match(/Invalid credentials/i);
    });
  });

  it('locks an account out after LOGIN_MAX_ATTEMPTS_PER_USERNAME failures', () => {
    const victim = 'cypress-lock-user';
    seedRequest('login_reset');

    Cypress._.times(6, () => submitLogin(victim, 'wrong-password'));

    submitLogin(victim, 'wrong-password').then(res => {
      expect(res.body, 'throttle message').to.match(/Too many failed attempts/i);
    });

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

  it('KNOWN: GET /logout.php logs the user out without a CSRF token', () => {
    cy.clearCookies();
    submitLogin('test', 'test');
    cy.probe({ url: '/logout.php' }).then(res => {
      expect(res.status, 'logout still answers to a bare GET').to.be.oneOf([302, 303]);
    });
    cy.probe({ url: '/dashboard.php' }).its('status').should('be.oneOf', [302, 303]);
  });
});
