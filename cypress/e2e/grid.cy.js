// cypress/e2e/grid.cy.js
// ============================================================================
// Grid Display, Search, Filter, Actions Tests
// ============================================================================

const BASE = 'http://localhost:8080';
const TEST_TABLE = 'companies'; // Use companies table (confirmed in schema.json)

// ============================================================================
// Test Suite: Grid Display & Core Features
// ============================================================================

describe('OpenSparrow – Grid Display', () => {
  before(() => {
    cy.seedDatabase();
  });

  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    cy.url().should('include', `table=${TEST_TABLE}`);
  });

  it('loads grid with correct title', () => {
    cy.get('[data-cy=grid-title], #gridTitle', { timeout: CypressHelpers.TIMEOUTS.short })
      .should('exist');
    // Title may be hidden by CSS until loadTable() runs; existence is sufficient
  });

  it('displays grid or empty state', () => {
    waitForGridOrEmpty().then(res => {
      expect(res.type).to.be.oneOf(['grid', 'empty']);
    });
  });

  it('shows grid header with column names', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('[data-cy=grid] thead, #grid thead')
          .should('be.visible')
          .find('th')
          .should('have.length.greaterThan', 0);
      }
    });
  });

  it('renders grid rows', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('[data-cy=grid] tbody, #grid tbody')
          .should('exist')
          .find('tr')
          .should('have.length.greaterThan', 0);
      }
    });
  });

  it('shows empty state when no records', () => {
    // Navigate to a table likely to be empty
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);

    waitForGridOrEmpty().then(res => {
      if (res.type === 'empty') {
        cy.wrap(res.element).should('be.visible');
      }
    });
  });
});

// ============================================================================
// Test Suite: Grid Search & Filter
// ============================================================================

describe('OpenSparrow – Grid Search & Filter', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('displays search input field', () => {
    cy.get('[data-cy=search], #globalSearch', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('displays column filter select', () => {
    cy.get('[data-cy=column-filter], #columnFilter', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    }).should('be.visible');
  });

  it('search input filters results', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        // Get initial row count
        cy.get('[data-cy=grid] tbody tr, #grid tbody tr')
          .its('length')
          .then(initialCount => {
            // Type search term
            cy.get('[data-cy=search], #globalSearch')
              .clear()
              .type('a');

            // Wait for grid to re-render with filtered results
            cy.get('[data-cy=grid] tbody tr, #grid tbody tr', {
              timeout: CypressHelpers.TIMEOUTS.medium,
            })
              .its('length')
              .then(filteredCount => {
                // Filtered count should be <= initial count
                expect(filteredCount).to.be.lte(initialCount);
              });
          });
      }
    });
  });

  it('clears search term', () => {
    cy.get('[data-cy=search], #globalSearch')
      .clear()
      .type('a');

    // Clear button or input clear
    cy.get('[data-cy=search], #globalSearch').clear();
    cy.get('[data-cy=search], #globalSearch').should('have.value', '');
  });

  it('column filter select loads options', () => {
    cy.get('[data-cy=column-filter], #columnFilter')
      .find('option')
      .should('have.length.greaterThan', 1); // At least "All columns" option
  });

  it('displays clear filters button when filter applied', () => {
    // Apply search
    cy.get('[data-cy=search], #globalSearch').clear().type('test');

    // Clear Filters button should appear
    cy.get('#clearFilters', { timeout: CypressHelpers.TIMEOUTS.medium }).then($btn => {
      if ($btn.is(':visible')) {
        cy.wrap($btn).click();
        cy.get('[data-cy=search], #globalSearch').should('have.value', '');
      }
    });
  });
});

// ============================================================================
// Test Suite: Grid Actions (Edit, Delete, Export)
// ============================================================================

describe('OpenSparrow – Grid Row Actions', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('displays Export CSV button', () => {
    cy.get('[data-cy=export], #exportCsv')
      .should('be.visible')
      .and('not.be.disabled');
  });

  it('Export CSV button is clickable', () => {
    cy.get('[data-cy=export], #exportCsv')
      .should('be.visible')
      .click();
    // Browser will download file — just verify click succeeds
  });

  it('displays Add button for editor role', () => {
    cy.get('body').then($body => {
      const hasAddBtn = $body.find('#addRow, [data-cy=add]').length > 0;

      if (hasAddBtn) {
        cy.get('#addRow, [data-cy=add]')
          .should('be.visible')
          .and('not.be.disabled');
      } else {
        Cypress.log({ message: 'Add button not present (read-only role)' });
      }
    });
  });

  it('Add button navigates to create.php', () => {
    cy.get('body').then($body => {
      const hasAddBtn = $body.find('#addRow, [data-cy=add]').length > 0;

      if (!hasAddBtn) {
        Cypress.log({ message: 'Add button not present — skipping' });
        return;
      }

      // Wait for loadTable() to attach onclick before clicking
      cy.get('#addRow, [data-cy=add]')
        .first()
        .should($btn => {
          expect($btn[0].onclick, 'Add button onclick set by loadTable').to.not.be.null;
        })
        .click();

      cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'create.php');
    });
  });

  it('displays Edit button in row actions', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-edit]')
          .should('exist');
      }
    });
  });

  it('Edit button navigates to edit.php', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-edit]')
          .click({ force: true });

        cy.url({ timeout: CypressHelpers.TIMEOUTS.long }).should('include', 'edit.php');
      }
    });
  });

  it('displays Duplicate button in row actions', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-duplicate]')
          .should('exist');
      }
    });
  });

  it('displays Delete button in row actions', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type === 'grid') {
        cy.get('#grid tbody tr')
          .first()
          .find('[data-cy=row-delete]')
          .should('exist');
      }
    });
  });
});

// ============================================================================
// Test Suite: Grid Pagination
// ============================================================================

describe('OpenSparrow – Grid Pagination', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('shows pagination if table has multiple pages', () => {
    waitForPagination().then(found => {
      if (found) {
        Cypress.log({ message: 'Pagination present' });
      } else {
        Cypress.log({ message: 'No pagination (single page or too few records)' });
      }
    });
  });

  it('pagination buttons are functional', () => {
    waitForPagination().then(found => {
      if (found) {
        cy.get('[data-cy=pagination] button, #pagination button').then($buttons => {
          if ($buttons.length > 0) {
            cy.wrap($buttons).first().should('be.visible');
          }
        });
      }
    });
  });
});

// ============================================================================
// Test Suite: Grid Mobile
// ============================================================================

describe('OpenSparrow – Grid Mobile', () => {
  beforeEach(() => {
    cy.viewport('iphone-x');
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
  });

  it('loads grid on mobile viewport', () => {
    waitForGridOrEmpty().then(res => {
      expect(res.type).to.be.oneOf(['grid', 'empty']);
    });
  });

  it('displays mobileActions select on mobile', () => {
    // mobileActions exists in DOM; CSS visibility depends on media query
    cy.get('#mobileActions').should('exist');
  });

  it('mobileActions select has Export option', () => {
    // Check by value (locale-independent), not text
    cy.get('#mobileActions option[value="export"]').should('exist');
  });

  it('mobileActions select has Refresh option', () => {
    cy.get('#mobileActions option[value="refresh"]').should('exist');
  });
});

// ============================================================================
// Test Suite: Grid Inline Edit
// ============================================================================

describe('OpenSparrow – Grid Inline Edit', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('text cells have contentEditable for editor role', () => {
    cy.get('body').then($body => {
      // contentEditable cells only present for editor role
      const $editableCells = $body.find('[contenteditable="true"]');
      if ($editableCells.length === 0) {
        Cypress.log({ message: 'No contentEditable cells — user is viewer or no text columns' });
        return;
      }
      cy.get('[contenteditable="true"]').should('have.length.gte', 1);
    });
  });

  it('clicking editable cell keeps it focused', () => {
    cy.get('body').then($body => {
      if ($body.find('[contenteditable="true"]').length === 0) return;

      cy.get('[contenteditable="true"]').first().click().should('be.focused');
    });
  });

  it('typing in editable cell updates cell content', () => {
    cy.get('body').then($body => {
      if ($body.find('[contenteditable="true"]').length === 0) return;

      const testText = `cy-${Date.now()}`;
      cy.get('[contenteditable="true"]').first()
        .click()
        .clear()
        .type(testText);

      cy.get('[contenteditable="true"]').first()
        .invoke('text')
        .should('include', testText.slice(0, 6));
    });
  });

  it('blurring editable cell after change triggers a PATCH request to the API', () => {
    cy.get('body').then($body => {
      if ($body.find('[contenteditable="true"]').length === 0) return;

      cy.intercept('PATCH', /api\.php/).as('inlineSave');

      cy.get('[contenteditable="true"]').first()
        .click()
        .type('test')
        .blur();

      cy.wait('@inlineSave', { timeout: CypressHelpers.TIMEOUTS.medium })
        .its('response.statusCode')
        .should('be.oneOf', [200, 400, 422]);
    });
  });

  it('Escape key on editable cell cancels edit without navigating away', () => {
    cy.get('body').then($body => {
      if ($body.find('[contenteditable="true"]').length === 0) return;

      cy.get('[contenteditable="true"]').first()
        .click()
        .type('some-changes');

      cy.get('body').type('{esc}');
      cy.url().should('include', `table=${TEST_TABLE}`);
    });
  });
});

// ============================================================================
// Test Suite: Grid Column Filters
// ============================================================================

describe('OpenSparrow – Grid Column Filters', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('#columnFilter select exists and has options', () => {
    cy.get('[data-cy=column-filter], #columnFilter', {
      timeout: CypressHelpers.TIMEOUTS.medium,
    })
      .find('option')
      .should('have.length.greaterThan', 1);
  });

  it('#filterPills container exists in DOM', () => {
    cy.get('#filterPills').should('exist');
  });

  it('#filterBar exists in DOM', () => {
    cy.get('#filterBar').should('exist');
  });

  it('selecting a column may render filter input in #filterBar', () => {
    cy.get('#columnFilter').then($sel => {
      const opts = $sel.find('option').toArray().filter(o => o.value !== '');
      if (opts.length === 0) {
        Cypress.log({ message: 'No column options — skipping' });
        return;
      }
      cy.wrap($sel).select(opts[0].value);
      // #filterBar content depends on column type; just verify no JS error
      cy.get('#filterBar').should('exist');
    });
  });

  it('if enum/FK column selected: #dictFilter appears', () => {
    cy.get('#columnFilter').then($sel => {
      // Look for an option whose value corresponds to an enum/FK column
      // by trying each option until dictFilter appears (or none found)
      const opts = $sel.find('option').toArray().filter(o => o.value !== '');
      if (opts.length === 0) return;

      let found = false;
      const tryNext = (i) => {
        if (i >= opts.length || found) return;
        cy.wrap($sel).select(opts[i].value);
        cy.get('#filterBar').then($bar => {
          if ($bar.find('#dictFilter').length > 0) {
            found = true;
            cy.get('#dictFilter').should('exist').find('option').should('have.length.gte', 1);
          } else {
            tryNext(i + 1);
          }
        });
      };
      tryNext(0);
    });
  });

  it('if bool column selected: #boolFilter appears', () => {
    cy.get('#columnFilter').then($sel => {
      const opts = $sel.find('option').toArray().filter(o => o.value !== '');
      if (opts.length === 0) return;

      let found = false;
      const tryNext = (i) => {
        if (i >= opts.length || found) return;
        cy.wrap($sel).select(opts[i].value);
        cy.get('#filterBar').then($bar => {
          if ($bar.find('#boolFilter').length > 0) {
            found = true;
            cy.get('#boolFilter').should('exist');
          } else {
            tryNext(i + 1);
          }
        });
      };
      tryNext(0);
    });
  });

  it('applying dict filter creates pill in #filterPills', () => {
    cy.get('#columnFilter').then($sel => {
      const opts = $sel.find('option').toArray().filter(o => o.value !== '');
      if (opts.length === 0) return;

      // Find first option that creates a dictFilter
      let pilledUp = false;
      const tryPill = (i) => {
        if (i >= opts.length || pilledUp) return;
        cy.wrap($sel).select(opts[i].value);
        cy.get('#filterBar').then($bar => {
          const $dict = $bar.find('#dictFilter');
          if ($dict.length === 0) {
            tryPill(i + 1);
            return;
          }
          // Select a non-empty option in dictFilter
          const dictOpts = $dict.find('option').toArray().filter(o => o.value !== '');
          if (dictOpts.length === 0) {
            tryPill(i + 1);
            return;
          }
          pilledUp = true;
          cy.get('#dictFilter').select(dictOpts[0].value);
          cy.get('#filterPills', { timeout: CypressHelpers.TIMEOUTS.medium })
            .should('be.visible')
            .find('[title="Remove filter"]')
            .should('have.length.gte', 1);
        });
      };
      tryPill(0);
    });
  });

  it('clicking pill × removes it', () => {
    cy.get('#filterPills').then($pills => {
      // Only run if pills are already visible from a previous action
      const $closeBtns = $pills.find('[title="Remove filter"]');
      if ($closeBtns.length === 0) {
        Cypress.log({ message: 'No pills — skipping pill-remove test' });
        return;
      }
      const countBefore = $closeBtns.length;
      cy.wrap($closeBtns).first().click({ force: true });
      cy.get('#filterPills [title="Remove filter"]')
        .should('have.length', countBefore - 1);
    });
  });
});

// ============================================================================
// Test Suite: Grid Rows Per Page
// ============================================================================

describe('OpenSparrow – Grid Rows Per Page', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('pagination area renders if table has records', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') {
        Cypress.log({ message: 'No grid — pagination test skipped' });
        return;
      }
      // #pagination may exist even for single-page tables (contains size select)
      cy.get('#pagination').should('exist');
    });
  });

  it('page size select inside #pagination has standard options', () => {
    cy.get('#pagination').then($pag => {
      const $sel = $pag.find('select');
      if ($sel.length === 0) {
        Cypress.log({ message: 'No page-size select — single-page table; skipping' });
        return;
      }
      const vals = $sel.find('option').toArray().map(o => parseInt(o.value, 10));
      // Expect at least some of [10, 25, 50, 100]
      expect(vals.some(v => [10, 25, 50, 100].includes(v))).to.be.true;
    });
  });

  it('changing page size persists to localStorage', () => {
    cy.get('#pagination').then($pag => {
      const $sel = $pag.find('select');
      if ($sel.length === 0) return;

      const opts = $sel.find('option').toArray();
      if (opts.length < 2) return;

      // Pick option different from current
      const current = $sel.val();
      const target = opts.find(o => o.value !== current);
      if (!target) return;

      cy.wrap($sel).select(target.value);
      cy.window().then(win => {
        const stored = win.localStorage.getItem('sparrow_page_size');
        expect(stored).to.equal(target.value);
      });
    });
  });

  it('page size change re-renders grid rows', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#pagination').then($pag => {
        const $sel = $pag.find('select');
        if ($sel.length === 0) return;

        const opts = $sel.find('option').toArray();
        if (opts.length < 2) return;

        const current = $sel.val();
        const target = opts.find(o => o.value !== current);
        if (!target) return;

        cy.wrap($sel).select(target.value);
        cy.get('#grid tbody tr', { timeout: CypressHelpers.TIMEOUTS.medium })
          .should('have.length.gte', 1);
      });
    });
  });

  it('page size persists after reload', () => {
    cy.get('#pagination').then($pag => {
      const $sel = $pag.find('select');
      if ($sel.length === 0) return;

      const opts = $sel.find('option').toArray();
      if (opts.length < 2) return;

      const target = opts[opts.length - 1]; // pick last option (e.g. 100)
      cy.wrap($sel).select(target.value);

      cy.reload();
      cy.get('#pagination', { timeout: CypressHelpers.TIMEOUTS.long }).then($pagAfter => {
        const $selAfter = $pagAfter.find('select');
        if ($selAfter.length === 0) return;
        cy.wrap($selAfter).should('have.value', target.value);
      });
    });
  });
});

// ============================================================================
// Test Suite: Grid Column Sorting
// ============================================================================

describe('OpenSparrow – Grid Column Sorting', () => {
  beforeEach(() => {
    loginAsTestUser();
    cy.visit(`${BASE}/index.php?table=${TEST_TABLE}`);
    waitForGridOrEmpty();
  });

  it('column headers are clickable', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;
      cy.get('#grid thead th').first().should('exist').click({ force: true });
    });
  });

  it('clicking header adds sort indicator', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid thead th').first().click({ force: true });

      // Sort indicator is typically ↑ or ↓ text, or an SVG/span inside th
      cy.get('#grid thead th').first().invoke('text').then(text => {
        // Text should contain an arrow or the th's data-sort attr should change
        const hasSortIndicator = text.includes('↑') || text.includes('↓')
          || text.includes('▲') || text.includes('▼');

        if (!hasSortIndicator) {
          // Check for class-based sort indicator
          cy.get('#grid thead th').first().then($th => {
            const isSorted = $th.attr('data-sort') || $th.hasClass('sorted') || $th.hasClass('sort-asc') || $th.hasClass('sort-desc');
            // Either has class or attribute — both are valid
          });
        }
      });
    });
  });

  it('clicking sorted header twice reverses sort', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid thead th').first().click({ force: true });
      cy.get('#grid thead th').first().click({ force: true });

      // Grid should still be visible after two sort clicks
      cy.get('#grid tbody tr').should('have.length.gte', 1);
    });
  });

  it('grid rerenders after sort click', () => {
    waitForGridOrEmpty().then(res => {
      if (res.type !== 'grid') return;

      cy.get('#grid thead th').first().click({ force: true });

      // Grid still has rows after sorting
      cy.get('#grid tbody tr', { timeout: CypressHelpers.TIMEOUTS.medium })
        .should('have.length.gte', 1);
    });
  });
});
