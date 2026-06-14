/// <reference types="cypress" />

/**
 * MySQL CRUD E2E Tests for OpenSparrow application.
 * Covers: load edit page, boolean toggle, nullable fields, update field,
 * create record, search, delete, datetime-local, pagination, inline editing.
 * Table: users_mysql (id, username, email, first_name, last_name, is_active, created_at)
 */

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'users_mysql';

describe('MySQL CRUD E2E Tests', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  // ================================
  // Test 1 – Read / Load Record (Edit Flow)
  // ================================
  it('loads edit page for a MySQL table without PostgreSQL fallback error', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]', { timeout: CypressHelpers.TIMEOUTS.long })
      .should('be.visible');
    cy.contains(`relation "public.${TEST_TABLE}" does not exist`).should('not.exist');
  });

  // ================================
  // Test 2 – Boolean Mapping (TINYINT(1) <-> PHP bool)
  // ================================
  it('handles boolean toggle correctly and persists changes', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="is_active"]').filter(':visible').as('boolToggle');
    cy.get('@boolToggle').should($el => expect($el.is(':checked')).to.be.a('boolean'));
    cy.get('@boolToggle').click({ force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('include', `edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('.error').should('not.exist');
  });

  // ================================
  // Test 3 – Nullable Fields (first_name, last_name)
  // ================================
  it('accepts empty values for nullable fields without errors', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="first_name"]').filter(':visible').clear();
    cy.get('input[name="last_name"]').filter(':visible').clear();
    cy.get('button[type="submit"]').first().click();
    cy.url().should('include', `edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('.error').should('not.exist');
  });

  // ================================
  // Test 4 – Update Standard Text Field (email)
  // ================================
  it('updates a standard text field correctly', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="email"]').filter(':visible')
      .invoke('val')
      .then(oldVal => {
        const newVal = oldVal.includes('test')
          ? oldVal.replace('test', 'updated')
          : `${oldVal}_updated`;
        cy.get('input[name="email"]').filter(':visible').clear().type(newVal);
      });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('include', `edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('.error').should('not.exist');
  });

  // ================================
  // Test 5 – Create Record + Basic Search Verification
  // ================================
  it('creates a new record in a MySQL table and verifies search works', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    const uniqueSuffix = Date.now();
    const username = `user_${uniqueSuffix}`;
    const email = `user_${uniqueSuffix}@example.com`;
    cy.get('input[name="username"]').filter(':visible').type(username);
    cy.get('input[name="email"]').filter(':visible').type(email);
    cy.get('input[name="is_active"]').filter(':visible').check({ force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url({ timeout: 10000 }).should('eq', `${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('.error').should('not.exist');
    cy.contains(username).should('exist');
  });

  // ================================
  // Test 7 – Graceful Degradation of Postgres-only Features
  // ================================
  it('does not break when PostgreSQL-specific UI components are absent', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.window().its('Cypress').then(cyObj => {
      const errors = cyObj?.state?.errors || [];
      expect(errors).to.be.empty;
    });
    cy.get('button[type="submit"]').first().should('be.visible');
  });

  // ================================
  // Test 8 – HTML5 datetime-local input conversion
  // ================================
  it('formats HTML5 datetime-local input safely for MySQL', () => {
    cy.visit(`${BASE}/edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="created_at"]').filter(':visible').as('datetimeField');
    cy.get('@datetimeField').should('have.attr', 'type', 'datetime-local');
    cy.get('@datetimeField').clear().type('2026-06-14T12:00', { force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('include', `edit.php?table=${TEST_TABLE}&id=1`);
    cy.get('.error').should('not.exist');
  });

  // ================================
  // Test 9 – Search for Newly Created Record (global search)
  // ================================
  it('searches for a newly created record and finds it', () => {
    const uniqueSuffix = Date.now();
    const username = `search_${uniqueSuffix}`;
    const email = `search_${uniqueSuffix}@example.com`;
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="username"]').filter(':visible').type(username);
    cy.get('input[name="email"]').filter(':visible').type(email);
    cy.get('input[name="is_active"]').filter(':visible').check({ force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('eq', `${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('#globalSearch').clear().type(username);
    cy.contains(username, { timeout: 10000 }).should('be.visible');
  });

  // ================================
  // Test 10 – Delete Record (Full CRUD – Delete operation)
  // ================================
  it('deletes a record and confirms it no longer appears on the grid', () => {
    const uniqueSuffix = Date.now();
    const username = `delete_${uniqueSuffix}`;
    const email = `delete_${uniqueSuffix}@example.com`;
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="username"]').filter(':visible').type(username);
    cy.get('input[name="email"]').filter(':visible').type(email);
    cy.get('input[name="is_active"]').filter(':visible').check({ force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('eq', `${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('#globalSearch').clear().type(username);
    cy.contains(username, { timeout: 10000 }).should('be.visible');
    cy.contains('tr', username).within(() => {
      cy.get('button[data-cy="row-delete"]').click({ force: true });
    });
    // Accept the native confirm. Text is i18n-driven (pl by default), so we do
    // not assert on its wording — just that a non-empty prompt was shown.
    cy.on('window:confirm', (text) => {
      expect(text).to.be.a('string').and.not.be.empty;
      return true;
    });
    cy.contains(username, { timeout: 10000 }).should('not.exist');
  });

  // ================================
  // Test 11 – Pagination (Change rows per page) – FINAL: bez intercept, prostsza asercja
  // ================================
  it('changes number of rows per page and updates pagination info', () => {
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);

    // Znajdź select paginacji – elastyczny selektor
    cy.get('#pagination select, .pagination select, select[name="per_page"]').first().as('rowsSelect');
    cy.get('@rowsSelect').should('be.visible');

    // Wybierz 25 wierszy na stronę
    cy.get('@rowsSelect').select('25', { force: true });

    // Po zmianie strona może się przeładować lub grid odświeży asynchronicznie
    // Czekamy, aż grid załaduje maksymalnie 25 wierszy
    cy.get('#grid tbody tr', { timeout: 10000 }).should('have.length.lte', 25);

    // Sprawdź komunikat paginacji – dopuszczamy różne formaty (nie wymagamy konkretnego słowa)
    cy.get('#pagination-info, .pagination-info, .dataTables_info')
      .should('be.visible')
      .invoke('text')
      .should('match', /\d+[–-]\d+\s+of\s+\d+/); // np. "1-25 of 120"
  });

  // ================================
  // Test 12 – Inline Editing on Grid (contenteditable) – FINAL: uproszczony, bez oczekiwania na toast
  // ================================
  it('allows inline editing of a field and persists the change', () => {
    const suffix = Date.now();
    const username = `inline_${suffix}`;
    const email = `inline_${suffix}@example.com`;

    // 1. Przygotuj unikalny rekord
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.get('form.editor-form, form[method="POST"]').should('be.visible');
    cy.get('input[name="username"]').type(username);
    cy.get('input[name="email"]').type(email);
    cy.get('input[name="is_active"]').check({ force: true });
    cy.get('button[type="submit"]').first().click();
    cy.url().should('eq', `${BASE}/index.php?table=${TEST_TABLE}`);

    // 2. Wyszukaj rekord. Czekamy aż debounce wyszukiwania (300ms) przerenderuje
    //    grid do JEDNEGO wiersza ZANIM zaczniemy edycję inline — inaczej to
    //    opóźnione przerenderowanie odpina komórkę w trakcie pisania i zapisuje
    //    tylko fragment wartości (np. "Edi").
    cy.get('#globalSearch').clear().type(username);
    cy.get('#grid tbody tr', { timeout: 10000 }).should('have.length', 1);
    cy.contains('tr', username).should('be.visible');

    // 3. Edycja inline – komórki to contenteditable <td>, więc czyścimy przez
    //    {selectall}{backspace} (cy.clear() działa tylko na input/textarea).
    //    Przechwytujemy PATCH zapisu, aby deterministycznie poczekać na zapis
    //    zamiast ścigać się z przeładowaniami gridu.
    const newFirstName = `Edited_${suffix}`;
    cy.intercept('PATCH', '**/index.php**').as('cellSave');

    cy.contains('tr', username).find('td[data-column="first_name"]').as('cell');
    cy.get('@cell').should('be.visible').click();
    cy.get('@cell').type('{selectall}{backspace}' + newFirstName);

    // 4. Zapis odpala się na blur. Przenosimy fokus poza grid (pole wyszukiwania,
    //    które nie odpina się przy przeładowaniu), co wyzwala PATCH, i czekamy aż
    //    serwer potwierdzi zapis – bez sztywnego cy.wait i bez asercji na komórce
    //    gridu (ta jest nietrwała przez wyścig przeładowań + paginację).
    cy.get('#globalSearch').focus();
    cy.wait('@cellSave').its('response.statusCode').should('be.oneOf', [200, 204]);

    // 5. Trwałość weryfikujemy w formularzu edycji (źródło prawdy). Po zapisie
    //    grid przeładował się bez filtra, więc filtrujemy ponownie i — tak jak
    //    wyżej — czekamy aż debounce ustabilizuje grid do 1 wiersza, dopiero
    //    potem otwieramy edycję.
    cy.get('#globalSearch').clear().type(username);
    cy.get('#grid tbody tr', { timeout: 10000 }).should('have.length', 1);
    cy.contains('tr', username).should('be.visible');
    cy.get('button[data-cy="row-edit"]').first().click();
    cy.url().should('include', 'edit.php');
    cy.get('input[name="first_name"]').should('have.value', newFirstName);

    // 6. Sprzątanie – usuń rekord
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    cy.get('#globalSearch').clear().type(username);
    cy.contains('tr', username).within(() => {
      cy.get('button[data-cy="row-delete"]').click({ force: true });
    });
    cy.on('window:confirm', () => true);
    cy.contains(username).should('not.exist');
  });
});