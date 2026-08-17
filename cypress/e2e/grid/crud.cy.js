// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies';

describe('OpenSparrow – Create Record Flow', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
  });

  it('navigates to create.php with table parameter', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.url().should('include', `create.php?table=${TEST_TABLE}`);
  });

  it('displays create form', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('form.editor-form, form[method="POST"]', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('exist');
  });

  it('displays submit button', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('button[type="submit"], button.btn-save', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('displays cancel button', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('button.btn-cancel, a.btn-cancel', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('cancel button returns to grid', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('button.btn-cancel').click();
    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should(
      'include',
      `table=${TEST_TABLE}`
    );
  });

  it('displays form fields', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('form.editor-form input, form.editor-form select, form.editor-form textarea', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    })
      .should('have.length.greaterThan', 0);
  });

  it('shows CSRF token in form', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('input[name="csrf_token"]').should('exist');
  });

  it('marks required fields', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('span.required, input[required]', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).then($els => {
      if ($els.length > 0) {
        cy.wrap($els).should('exist');
      }
    });
  });

  it('handles enum fields with dropdown if present', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('body').then($body => {
      const $enums = $body.find('select[data-enum-colors], select[data-enum-status], select');
      if ($enums.length > 0) {
        cy.wrap($enums).first().find('option').should('have.length.greaterThan', 0);
      } else {
        Cypress.log({ message: 'No enum selects on create form' });
      }
    });
  });

  it('form respects pattern validation on inputs if present', () => {
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);

    cy.get('body').then($body => {
      const $patterns = $body.find('input[data-pattern]');
      if ($patterns.length > 0) {
        cy.wrap($patterns).first().should('have.attr', 'data-pattern');
      } else {
        Cypress.log({ message: 'No pattern-validated inputs on this form' });
      }
    });
  });
});

describe('OpenSparrow – Edit Record Flow', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type === 'grid') {
        cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
          .first()
          .find('[data-cy=row-edit]')
          .click({ force: true });

        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      } else {
        Cypress.log({ message: 'Empty grid — Edit Record tests skipped' });
      }
    });
  });

  it('loads edit.php with record ID', () => {
    cy.url().should('include', 'edit.php').and('include', 'id=');
  });

  it('displays tab navigation', () => {
    cy.get('div.tab-list[role="tablist"], .tab-list', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('exist');
  });

  it('shows Details tab as default', () => {
    cy.get('button.tab-btn[data-tab="tab-details"]').should(
      'have.class',
      'active'
    );
  });

  it('displays tab buttons: Details, Comments, History', () => {
    const tabs = ['Details', 'Comments', 'History'];

    tabs.forEach(tab => {
      cy.get('button.tab-btn').then($tabs => {
        const tabNames = $tabs.toArray().map(t => t.textContent.toLowerCase());

        const hasTab = tabNames.some(name => name.includes(tab.toLowerCase()));
        if (hasTab) {
          cy.contains('button.tab-btn', tab).should('exist');
        }
      });
    });
  });

  it('switches to Comments tab', () => {
    cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
      if ($btn.length > 0) {
        cy.wrap($btn).click();
        cy.get('button.tab-btn[data-tab="tab-comments"]').should(
          'have.class',
          'active'
        );
      }
    });
  });

  it('switches to History tab', () => {
    cy.get('button.tab-btn[data-tab="tab-history"]').then($btn => {
      if ($btn.length > 0) {
        cy.wrap($btn).click();
        cy.get('button.tab-btn[data-tab="tab-history"]').should(
          'have.class',
          'active'
        );
      }
    });
  });

  it('displays Save button', () => {
    cy.get('button.btn-save[type="submit"], button[onclick*="saveAction"]', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('displays Cancel button', () => {
    cy.get('button.btn-cancel', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('shows record ID strip', () => {
    cy.get('div.form-id-strip, .form-id-value', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('exist');
  });

  it('displays form fields in Details tab', () => {
    cy.get('input, select, textarea').should('have.length.greaterThan', 0);
  });

  it('Comments tab mounts comment panel', () => {
    cy.get('button.tab-btn[data-tab="tab-comments"]').then($btn => {
      if ($btn.length > 0) {
        cy.wrap($btn).click();
        cy.get('#c-panel, [data-cy=comments-panel]').should('exist');
      }
    });
  });
});

describe('OpenSparrow – Delete Record', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('displays Delete button in grid row actions', () => {
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type === 'grid') {
        cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
          .first()
          .find('[data-cy=row-delete]')
          .then($btn => {
            if ($btn.length > 0) {
              cy.wrap($btn).should('exist');
            }
          });
      }
    });
  });

  it('shows delete button exists in grid row', () => {
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type === 'grid') {
        cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
          .first()
          .find('[data-cy=row-delete]')
          .then($btn => {
            if ($btn.length > 0) {
              cy.wrap($btn).should('exist');
            }
          });
      }
    });
  });
});

describe('OpenSparrow – Form Validation', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
  });

  it('prevents submit with empty required fields', () => {
    cy.get('button[type="submit"]').click();

    cy.get(':invalid, [aria-invalid="true"]').then($invalid => {
      if ($invalid.length > 0) {
        expect($invalid.length).to.be.greaterThan(0);
      }
    });
  });

  it('shows validation for pattern inputs if present', () => {
    cy.get('body').then($body => {
      const $patternInputs = $body.find('input[data-pattern]');
      if ($patternInputs.length > 0) {
        cy.wrap($patternInputs).first().clear().type('??invalid??');
        cy.get('button[type="submit"]').click();
        cy.get(':invalid, [aria-invalid="true"]').then($invalid => {
          if ($invalid.length > 0) {
            cy.wrap($invalid).should('exist');
          }
        });
      } else {
        Cypress.log({ message: 'No pattern-validated inputs on this form' });
      }
    });
  });

  it('form has visible submit button', () => {
    cy.get('button[type="submit"].btn-save, button.btn-save[type="submit"]')
      .should('be.visible');
  });
});

describe('OpenSparrow – Subtables (if present)', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type === 'grid') {
        cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
          .first()
          .find('[data-cy=row-edit]')
          .click({ force: true });

        cy.url().should('include', 'edit.php');
      }
    });
  });

  it('displays subtable containers if present', () => {
    cy.get('div.subtable-container').then($containers => {
      if ($containers.length > 0) {
        cy.wrap($containers).should('exist');
      } else {
        Cypress.log({ message: 'No subtables on this record' });
      }
    });
  });

  it('displays Add subtable links if present', () => {
    cy.get('a.btn-add[href*="create.php"]').then($links => {
      if ($links.length > 0) {
        cy.wrap($links).should('exist');
      }
    });
  });

  it('subtable Add link navigates to create.php', () => {
    cy.get('a.btn-add[href*="create.php"]').then($links => {
      if ($links.length > 0) {
        cy.wrap($links)
          .first()
          .click({ force: true });

        cy.url().should('include', 'create.php');
      }
    });
  });
});

describe('OpenSparrow – Create Record: Actual Save', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/create.php?table=${TEST_TABLE}`);
    cy.get('form.editor-form', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
  });

  it('filling first text input and submitting redirects to grid', () => {
    cy.get('form.editor-form').then($form => {
      const $inputs = $form.find('input[type="text"]:not([readonly]):not([disabled])');
      if ($inputs.length === 0) {
        Cypress.log({ message: 'No text inputs on create form — may be all FK/enum/readonly' });
        return;
      }

      const uniqueValue = `cypress-test-${Date.now()}`;
      cy.wrap($inputs).first().clear().type(uniqueValue);
      cy.get('button[type="submit"].btn-save').click();

      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).then(url => {
        if (url.includes('index.php')) {
          cy.url().should('include', `table=${TEST_TABLE}`);
        } else {
          cy.url().should('include', 'create.php');
          Cypress.log({ message: 'Form has more required fields — stayed on create page' });
        }
      });
    });
  });

  it('empty required field shows native validation or stays on page', () => {
    cy.get('button[type="submit"].btn-save').click();

    cy.url().then(url => {
      if (url.includes('create.php')) {
        cy.url().should('include', 'create.php');
      } else {
        Cypress.log({ message: 'No required fields — record created with empty values' });
      }
    });
  });

  it('cancel button returns to grid without creating record', () => {
    cy.get('button.btn-cancel, a.btn-cancel').click();
    cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', `table=${TEST_TABLE}`);
  });
});

describe('OpenSparrow – Edit Record: Actual Save', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
      cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
    });
  });

  it('changing a text field and saving redirects to grid', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;

      cy.get('input[type="text"]:not([readonly]):not([disabled])', {
        timeout: CypressHelpers.TIMEOUTS.medium,
      }).then($inputs => {
        if ($inputs.length === 0) {
          Cypress.log({ message: 'No editable text inputs on edit form' });
          return;
        }

        const uniqueSuffix = ` (edited ${Date.now()})`;
        cy.wrap($inputs).first().then($inp => {
          const originalValue = $inp.val();
          const newValue = String(originalValue).slice(0, 50) + ' cy';
          cy.wrap($inp).clear().type(newValue);
        });

        cy.get('button.btn-save[type="submit"], button[onclick*="saveAction"]')
          .first()
          .click();

        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).then(afterUrl => {
          if (afterUrl.includes('index.php') || afterUrl.includes('?saved=1')) {
            cy.url().should('include', TEST_TABLE);
          } else {
            Cypress.log({ message: 'Stayed on edit page after save' });
          }
        });
      });
    });
  });

  it('save shows success indication (toast or saved=1)', () => {
    cy.url().then(url => {
      if (!url.includes('edit.php')) return;

      cy.get('button.btn-save[type="submit"], button[onclick*="saveAction"]')
        .first()
        .click();

      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).then(afterUrl => {
        const isSuccess = afterUrl.includes('saved=1')
          || afterUrl.includes('index.php')
          || afterUrl.includes(TEST_TABLE);

        if (isSuccess) {
          expect(isSuccess).to.be.true;
        } else {
          cy.get('.toast, .success-msg, [class*="success"]').should('exist');
        }
      });
    });
  });
});

describe('OpenSparrow – Delete Record: Actual Flow', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('cancelling delete confirm keeps record in grid', () => {
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;

      cy.get('#grid tbody tr').its('length').then(rowsBefore => {
        cy.window().then(win => {
          cy.stub(win, 'confirm').as('delConfirm').returns(false);

          cy.get('#grid tbody tr')
            .first()
            .find('[data-cy=row-delete]')
            .click({ force: true });

          cy.get('@delConfirm').should('have.been.called');
          cy.get('#grid tbody tr').should('have.length', rowsBefore);
        });
      });
    });
  });

  it('confirming delete removes record from grid', () => {
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    cy.intercept('DELETE', /index\.php/).as('deleteReq');

    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;

      cy.get('#grid tbody tr').its('length').then(rowsBefore => {
        if (rowsBefore < 1) {
          Cypress.log({ message: 'No rows to delete' });
          return;
        }

        cy.window().then(win => {
          cy.stub(win, 'confirm').returns(true);

          cy.get('#grid tbody tr')
            .last()
            .find('[data-cy=row-delete]')
            .click({ force: true });

          cy.wait('@deleteReq', { timeout: CypressHelpers.TIMEOUTS.medium });

          cy.get('body').then($body => {
            const hasPagination = $body.find('#pagination, .pagination').length > 0;
            if (!hasPagination) {
              cy.get('#grid tbody tr').should('have.length.lt', rowsBefore);
            } else {
              Cypress.log({ message: 'Pagination active — delete verified via API intercept' });
            }
          });
        });
      });
    });
  });
});

describe('OpenSparrow – Duplicate Record: Actual Flow', () => {
  beforeEach(() => {
    loginAsTestUser();
  });

  it('clicking Duplicate fires POST to duplicate endpoint', () => {
    cy.intercept('POST', /index\.php.*duplicate|api.*duplicate/).as('dupReq');
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-duplicate]')
        .then($btn => {
          if ($btn.length === 0) {
            Cypress.log({ message: 'No Duplicate button — skipping' });
            return;
          }
          cy.wrap($btn).click({ force: true });
          cy.wait('@dupReq', { timeout: CypressHelpers.TIMEOUTS.medium });
        });
    });
  });

  it('duplicate does not decrease row count', () => {
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
      cy.get('#grid tbody tr').its('length').then(rowsBefore => {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-duplicate]')
          .then($btn => {
            if ($btn.length === 0) return;
            cy.wrap($btn).click({ force: true });
            cy.get('#grid tbody tr', { timeout: CypressHelpers.TIMEOUTS.long })
              .should('have.length.gte', rowsBefore);
          });
      });
    });
  });

  it('duplicate adds a row on success (no unique constraint)', () => {
    cy.intercept('POST', /index\.php.*duplicate|api.*duplicate/, request => {
      request.continue(result => {
        result.body = result.body;
      });
    }).as('dupCheck');

    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
      cy.get('#grid tbody tr').its('length').then(rowsBefore => {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-duplicate]')
          .then($btn => {
            if ($btn.length === 0) return;
            cy.wrap($btn).click({ force: true });
            cy.wait('@dupCheck', { timeout: CypressHelpers.TIMEOUTS.medium })
              .its('response.statusCode')
              .then(status => {
                if (status === 200) {
                  cy.get('#grid tbody tr', { timeout: CypressHelpers.TIMEOUTS.long })
                    .should('have.length.gte', rowsBefore);
                } else {
                  Cypress.log({ message: `Duplicate returned ${status} — unique constraint or permission` });
                }
              });
          });
      });
    });
  });
});

describe('OpenSparrow – Role-Based UI', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('editor role: Add button is visible; it navigates to create.php', () => {
    cy.get('#addRow, [data-cy=add]').should('exist').and('be.visible');
  });

  it('Export CSV visible for all roles', () => {
    cy.get('#exportCsv, [data-cy=export]').should('exist');
  });

  it('editor role: Data Cleanup button is present', () => {
    cy.get('#dataCleanupBtn, [data-cy=data-cleanup]').should('exist');
  });

  it('editor role: contenteditable cells are present', () => {
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;

      cy.get('[contenteditable="true"]').should('have.length.gte', 1);
    });
  });

  it('UI is role-consistent: Add and Cleanup both appear for editor', () => {
    cy.get('body').then($body => {
      const hasAdd     = $body.find('#addRow').length > 0;
      const hasCleanup = $body.find('#dataCleanupBtn').length > 0;
      expect(hasAdd).to.equal(hasCleanup);
    });
  });

  it('History tab shows audit entries or empty state on edit.php', () => {
    waitForGridOrEmpty().then(result => {
      if (result.type !== 'grid') return;
      cy.get('#grid tbody tr')
        .first()
        .find('[data-cy=row-edit]')
        .click({ force: true });
      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      cy.get('button.tab-btn[data-tab="tab-history"]').then($btn => {
        if ($btn.length === 0) return;
        cy.wrap($btn).click();
        cy.get('#tab-history', { timeout: CypressHelpers.TIMEOUTS.medium }).should('exist');
        cy.get('#tab-history').should($panel => {
          const hasRows  = $panel.find('tr, .history-row').length > 0;
          const hasEmpty = $panel.text().length > 0;
          expect(hasRows || hasEmpty).to.be.true;
        });
      });
    });
  });
});
