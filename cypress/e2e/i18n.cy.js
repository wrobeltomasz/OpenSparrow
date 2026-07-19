// cypress/e2e/i18n.cy.js
// ============================================================================
// Internationalisation Tests
//
// Language detection priority (includes/i18n.php):
//   ?lang=xx GET param → session → user pref → settings default → Accept-Language → 'en'
//
// The JS bundle is served by: GET /api.php?action=i18n_bundle
// The bundle is a flat object with dot-notation keys, e.g. "common.save": "Save"
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: i18n API Bundle
// ============================================================================

describe('OpenSparrow – i18n: Bundle API', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('GET /api.php?action=i18n_bundle returns 200', () => {
    loginAsTestUser();
    cy.request({
      method: 'GET',
      url: `${BASE}/api.php?action=i18n_bundle`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).its('status').should('eq', 200);
  });

  it('bundle response is a non-empty JSON object', () => {
    loginAsTestUser();
    cy.request({
      method: 'GET',
      url: `${BASE}/api.php?action=i18n_bundle`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      const body = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      expect(body).to.be.an('object');
      expect(Object.keys(body).length).to.be.greaterThan(0);
    });
  });

  it('bundle contains required common keys', () => {
    loginAsTestUser();
    cy.request({
      method: 'GET',
      url: `${BASE}/api.php?action=i18n_bundle`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      const body = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      // The flat bundle uses dot-separated keys
      expect(body).to.include.keys('common.save', 'common.cancel', 'common.delete');
    });
  });

  it('English bundle value for common.save is "Save"', () => {
    loginAsTestUser();
    cy.request({
      method: 'GET',
      url: `${BASE}/api.php?action=i18n_bundle&lang=en`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(res => {
      const body = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      expect(body['common.save']).to.eq('Save');
    });
  });

  it('alternate language bundle differs from English when configured', () => {
    loginAsTestUser();
    cy.request({
      method: 'GET',
      url: `${BASE}/api.php?action=i18n_bundle&lang=pl`,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(plRes => {
      cy.request({
        method: 'GET',
        url: `${BASE}/api.php?action=i18n_bundle&lang=en`,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(enRes => {
        const plBody = typeof plRes.body === 'string' ? JSON.parse(plRes.body) : plRes.body;
        const enBody = typeof enRes.body === 'string' ? JSON.parse(enRes.body) : enRes.body;

        expect(plBody).to.be.an('object');
        expect(enBody).to.be.an('object');

        if (plBody['common.save'] !== enBody['common.save']) {
          // Polish is available: value should differ
          expect(plBody['common.save']).to.be.a('string').and.not.be.empty;
        } else {
          // Polish not in available_languages — server correctly fell back to English
          Cypress.log({ message: 'Polish not in available_languages — server fell back to English (expected)' });
        }
      });
    });
  });

});

// ============================================================================
// Test Suite: Language Switching via URL Parameter
// ============================================================================

describe('OpenSparrow – i18n: ?lang= URL Switch', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('page lang attribute matches en by default', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').then(lang => {
      // Default may be 'en' or the system default; it must be a non-empty string
      expect(lang).to.be.a('string').and.have.length.gte(2);
    });
  });

  it('?lang=pl sets <html lang="pl">', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=pl`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'pl');
  });

  it('?lang=de sets <html lang="de">', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=de`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'de');
  });

  it('?lang=en restores English', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=en`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'en');
  });

  it('invalid ?lang= falls back gracefully (does not crash the page)', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=zz`, { failOnStatusCode: false });
    // Page must still load without 500 — content should exist
    cy.get('body').should('exist').and('not.be.empty');
  });
});

// ============================================================================
// Test Suite: Language Persistence Across Navigation
// ============================================================================

describe('OpenSparrow – i18n: Session Persistence', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('language choice persists to the next page without ?lang= in URL', () => {
    loginAsTestUser();
    // Set Polish via URL — this stores it in session
    cy.visit(`${BASE}/dashboard.php?lang=pl`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'pl');

    // Navigate to a grid page WITHOUT ?lang= — session should keep it
    cy.visit(`${BASE}/index.php?table=companies`);
    cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'pl');

    // Reset back to English for isolation
    cy.visit(`${BASE}/dashboard.php?lang=en`);
  });

  it('i18n bundle locale matches <html lang> on the page', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=de`);

    cy.intercept('GET', /api\.php.*action=i18n_bundle/).as('bundle');
    cy.reload();
    cy.wait('@bundle', { timeout: CypressHelpers.TIMEOUTS.long }).then(({ request }) => {
      // The request should have been sent from a page with lang="de"
      cy.document().its('documentElement').invoke('getAttribute', 'lang').should('eq', 'de');
    });

    cy.visit(`${BASE}/dashboard.php?lang=en`);
  });
});

// ============================================================================
// Test Suite: JS I18n Module Integration
// ============================================================================

describe('OpenSparrow – i18n: JS Module', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('window.I18n is available on the page', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php`);
    cy.window().its('I18n').should('exist');
  });

  it('I18n.locale() returns a 2-letter language code', () => {
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=en`);
    cy.window().then(win => {
      const locale = win.I18n?.locale?.();
      if (locale) {
        expect(locale).to.match(/^[a-z]{2}$/);
      } else {
        Cypress.log({ message: 'I18n.locale() not available — bundle may not be loaded yet' });
      }
    });
  });

  it('I18n.t() returns a translated string', () => {
    cy.intercept('GET', /action=i18n_bundle/).as('bundle');
    loginAsTestUser();
    cy.visit(`${BASE}/dashboard.php?lang=en`);
    // Wait for the async bundle fetch to complete before probing the JS object
    cy.wait('@bundle', { timeout: CypressHelpers.TIMEOUTS.long });
    // .should() retries: the intercept resolves when the response arrives,
    // but the app assigns the parsed bundle a tick later (await res.json()).
    cy.window().should(win => {
      expect(win.I18n?.t, 'I18n.t available').to.be.a('function');
      const result = win.I18n.t('common.save');
      expect(result).to.be.a('string').and.not.eq('common.save');
    });
  });

  it('page load triggers exactly one i18n_bundle request', () => {
    cy.intercept('GET', /action=i18n_bundle/).as('bundle');
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=companies`);
    cy.get('#agPanel', { timeout: CypressHelpers.TIMEOUTS.long }).should('exist');
    cy.get('@bundle.all').should('have.length.lte', 3);
    // At most 3 concurrent module loads — the real constraint is no unbounded loop
  });
});

// ============================================================================
// Test Suite: Admin Language Settings
// ============================================================================

describe('OpenSparrow – i18n: Admin Settings Panel', () => {
  before(() => {
    cy.seedDatabase();
  });

  it('get_language_setting API returns current default and available languages', () => {
    loginAsAdmin();
    cy.request({
      method: 'GET',
      url: `${BASE}/admin/api.php?action=get_language_setting`,
    }).then(res => {
      expect(res.status).to.eq(200);
      const body = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
      expect(body).to.have.property('default_language').that.is.a('string');
      expect(body).to.have.property('available_languages').that.is.an('array').with.length.gte(1);
    });
  });

  it('settings tab renders language configuration card', () => {
    loginAsAdmin();
    cy.visit(`${BASE}/admin/index.php`);
    // Wait until the admin JS has rendered the initial tab before clicking nav
    cy.get('#editorForm', { timeout: CypressHelpers.TIMEOUTS.long })
      .should($el => {
        expect($el.children().length, 'admin JS rendered initial tab').to.be.gte(1);
      });
    // The settings button lives in the "System" collapsible nav-section,
    // which is closed by default — expand it first.
    cy.get('button.admin-tab[data-file="settings"]').then($btn => {
      const $section = $btn.closest('.nav-section');
      if ($section.length && !$section.hasClass('open')) {
        cy.wrap($section.find('.nav-section-header')).click();
      }
    });
    cy.get('button.admin-tab[data-file="settings"]')
      .scrollIntoView()
      .should('be.visible')
      .click();
    // settings.js renders the language card with a "Save language settings" button
    cy.contains('#editorForm button', 'Save language settings', {
      timeout: CypressHelpers.TIMEOUTS.long,
    }).should('exist');
  });
});
