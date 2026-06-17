// cypress/e2e/rag.cy.js
// ============================================================================
// RAG Knowledge Base Module Tests — rag.php
// ============================================================================

const BASE = 'http://localhost:8080';

// ============================================================================
// Test Suite: RAG Page Structure
// ============================================================================

describe('OpenSparrow – RAG: Page Structure', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/rag.php`);
    cy.get('#ragSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('loads RAG page', () => {
    cy.get('#ragSection').should('exist');
  });

  it('has conversation container', () => {
    cy.get('#ragConversation').should('exist');
  });

  it('has query textarea', () => {
    cy.get('#ragQuery').should('exist');
  });

  it('has Send button', () => {
    cy.get('#ragSendBtn').should('exist').and('be.visible');
  });

  it('has Clear history button', () => {
    cy.get('#ragClearBtn').should('exist').and('be.visible');
  });

  it('has tag filter sidebar', () => {
    cy.get('#ragFileList').should('exist');
  });

  it('sidebar has a title', () => {
    cy.get('.rag-sidebar-title').should('exist').invoke('text').should('not.be.empty');
  });

  it('textarea is writable', () => {
    cy.get('#ragQuery').type('test question');
    cy.get('#ragQuery').should('have.value', 'test question');
  });
});

// ============================================================================
// Test Suite: RAG Tag Loading
// ============================================================================

describe('OpenSparrow – RAG: Tag Loading', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/rag.php`);
  });

  it('file list transitions from loading state', () => {
    cy.get('#ragFileList', { timeout: CypressHelpers.TIMEOUTS.long }).should($el => {
      const hasLoading = $el.find('.rag-tag-loading').length > 0;
      const hasItems   = $el.find('.rag-tag-item, .rag-tag-empty, label').length > 0;
      expect(hasItems || !hasLoading, 'file list should finish loading').to.be.true;
    });
  });

  it('file list shows documents or empty state', () => {
    cy.get('#ragFileList', { timeout: CypressHelpers.TIMEOUTS.long }).then($el => {
      const hasDocs  = $el.find('.rag-tag-item, label').length > 0;
      const hasEmpty = $el.find('.rag-tag-empty').length > 0;
      if (hasDocs) {
        cy.wrap($el).find('.rag-tag-item, label').should('have.length.gte', 1);
      } else {
        Cypress.log({ message: 'No documents in RAG knowledge base — empty state shown' });
        expect(hasDocs || hasEmpty, 'file list shows docs or empty state').to.be.true;
      }
    });
  });
});

// ============================================================================
// Test Suite: RAG Send Interaction
// ============================================================================

describe('OpenSparrow – RAG: Send Interaction', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/rag.php`);
    cy.get('#ragSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('Send button click with empty textarea does not post', () => {
    cy.intercept('POST', '**/api/rag.php**').as('ragPost');
    cy.get('#ragSendBtn').click();
    // With empty input, should not fire API call
    cy.wait(500);
    cy.get('@ragPost.all').should('have.length', 0);
  });

  it('typing question enables send interaction', () => {
    cy.get('#ragQuery').type('What is OpenSparrow?');
    cy.get('#ragSendBtn').should('not.be.disabled');
  });

  it('clicking Send with query but no file selected shows error in conversation', () => {
    // When no document is checked the JS appends the user message + an error
    // bubble into #ragConversation without calling the API.
    cy.get('#ragQuery').type('What is OpenSparrow?');
    cy.get('#ragSendBtn').click();
    cy.get('#ragConversation', { timeout: CypressHelpers.TIMEOUTS.medium })
      .children()
      .should('have.length.gte', 1);
  });

  it('Send fires POST to api/rag.php when a document is checked', () => {
    cy.intercept('POST', '**/api/rag.php**').as('ragQuery');

    // Only run if at least one document is available to check
    cy.get('#ragFileList', { timeout: CypressHelpers.TIMEOUTS.long }).then($list => {
      const checkboxes = $list.find('input[type="checkbox"]');
      if (checkboxes.length === 0) {
        Cypress.log({ message: 'No RAG documents available — skipping API send test' });
        return;
      }
      cy.wrap(checkboxes.first()).check();
      cy.get('#ragQuery').type('What is OpenSparrow?');
      cy.get('#ragSendBtn').click();
      cy.wait('@ragQuery', { timeout: CypressHelpers.TIMEOUTS.long })
        .its('request.body')
        .should('have.property', 'query');
    });
  });

  it('after send: user message appears in conversation', () => {
    cy.get('#ragQuery').type('Hello');
    cy.get('#ragSendBtn').click();
    // Message always appears (either the user bubble, or the no-source error)
    cy.get('#ragConversation', { timeout: CypressHelpers.TIMEOUTS.medium })
      .children()
      .should('have.length.gte', 1);
  });

  it('Clear history button empties conversation', () => {
    // Add a message first if possible
    cy.get('#ragConversation').then($conv => {
      if ($conv.children().length === 0) {
        Cypress.log({ message: 'No conversation to clear — skipping' });
        return;
      }
      cy.get('#ragClearBtn').click();
      cy.get('#ragConversation').children().should('have.length', 0);
    });
  });
});

// ============================================================================
// Test Suite: RAG Mobile
// ============================================================================

describe('OpenSparrow – RAG: Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/rag.php`);
  });

  it('loads on mobile viewport', () => {
    cy.get('#ragSection', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('textarea and send button exist on mobile', () => {
    cy.get('#ragQuery').should('exist');
    cy.get('#ragSendBtn').should('exist');
  });
});
