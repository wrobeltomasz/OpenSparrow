// grid_actions.js
import { debugLog } from './debug.js';
import { showToast } from './toast.js';
import { loadTable } from './grid.js';
import { state } from './grid/state.js';

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// Show errors in debug panel
function debugError(message, data = {}) {
  const debugEl = document.getElementById('debug');
  if (!debugEl) return;

  const time = new Date().toLocaleTimeString();
  const entry =
    `[${time}] ERROR: ${message}\n` +
    `${JSON.stringify(data, null, 2)}\n\n`;

  debugEl.textContent += entry;
  debugEl.scrollTop = debugEl.scrollHeight;
}

function getCurrentTable() {
  return window.AppState?.currentTable;
}

function normalizeValue(el) {
  if (el.type === 'checkbox') {
    return el.checked; 
  }
  if (el.type === 'date') {
    return (el.value || '').toString().slice(0, 10);
  }
  
  // Extract real ID from datalist elements
  if (el.hasAttribute('list')) {
    const dl = document.getElementById(el.getAttribute('list'));
    if (dl) {
      const opt = Array.from(dl.options).find(o => o.value === el.value);
      if (opt) return opt.dataset.realId; // Found? Return the real ID
      if (el.value === '') return null;   // Empty field? Return null
      return el._originalValue;           // Invalid text? Ignore the change and protect the database
    }
  }

  // Handle contenteditable elements like divs or spans
  if (el.isContentEditable) {
    return el.textContent.trim(); 
  }
  return el.value ?? el.textContent;
}

// Visual feedback helpers
function markCell(td, ok) {
  if (!td) return;
  td.classList.remove('cell-success', 'cell-error');
  td.classList.add(ok ? 'cell-success' : 'cell-error');
  setTimeout(() => td.classList.remove('cell-success', 'cell-error'), 2000);
}

// UNIVERSAL EVENT ATTACHER (exported for grid.js)
export function attachCellEvents(el) {
  // Save original value as a JS property (not in dataset) to preserve strict types
  el.addEventListener("focus", () => {
    el._originalValue = normalizeValue(el);
  });

  el.addEventListener("input", onInputChange);

  // Event optimization: avoid duplicate requests
  if (el.tagName === 'SELECT' || el.type === 'checkbox') {
    // Checkboxes and selects are best caught immediately on change
    el.addEventListener("change", onCellBlur);
  } else {
    // Regular text and dates are caught only when the user leaves the cell
    el.addEventListener("blur", onCellBlur);
  }
}

// Shared update function
async function performUpdate(el, table, id, column, value) {
  debugLog("Updating cell", { id, col: column, value, table });
  const td = el.closest('td');

  try {
    const res = await fetch('index.php?api=update', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      body: JSON.stringify({ table, id, column, value })
    });

    let payload = null;
    // Handle edge cases where server returns an empty or non-JSON response
    try { payload = await res.json(); } catch {}

    if (!res.ok || payload?.error) {
      console.error("Update failed", { status: res.status, payload });
      debugError("Update failed", {
        status: res.status,
        error: payload?.error || "Unknown error"
      });
      markCell(td, false);
      return;
    }

    debugLog("Update success", payload || { ok: true });
    markCell(td, true);
    el._originalValue = value;

    // Reload the grid row so automation-triggered field changes become visible.
    if (state.currentTable && window.schema) {
      loadTable(
        window.schema, state.currentTable,
        document.getElementById('gridTitle'),
        document.getElementById('addRow')
      );
    }

  } catch (err) {
    console.error("Network error during update", err);
    markCell(td, false);
  }
}

// Exported but NO DB call - just keeps compatibility
export function onInputChange(e) {
  const el = e.target;
  const table = getCurrentTable();
  const id = el.dataset.id;
  const column = el.dataset.column;
  const value = normalizeValue(el);

  // You can log typing if you want here
}

// Update ONLY on blur/change
export function onCellBlur(e) {
  const el = e.target;
  const table = getCurrentTable();
  const id = el.dataset.id;
  const column = el.dataset.column;
  const value = normalizeValue(el);

  const original = el._originalValue;

  // Skip if unchanged (now uses strict type and value comparison)
  if (original !== undefined && original === value) {
    return;
  }

  // Validate using RegExp if pattern is provided in dataset safely avoiding 'v' flag errors
  const pattern = el.dataset.pattern;
  if (pattern && value !== '' && value !== null) {
      try {
          const regex = new RegExp(pattern);
          if (!regex.test(String(value))) {
              const msg = el.dataset.message || 'Invalid input format';
              showToast(msg, 'error');

              // Revert visual change back to original value
              if (el.isContentEditable) el.textContent = original ?? '';
              else el.value = original ?? '';
              
              return; // Abort update
          }
      } catch (err) {
          console.error("Invalid regex pattern provided from schema", err);
      }
  }

  // IMMEDIATELY protect against race conditions (fire only one request if the event triggers twice)
  el._originalValue = value;

  // Ensure all required context parameters exist before firing network request
  if (!table || !id || !column) {
    console.warn("Missing update context", { table, id, column });
    return;
  }

  performUpdate(el, table, id, column, value);
}

// Delete row
export async function deleteRow(id) {
  const table = getCurrentTable();
  if (!table || !id) return;

  try {
    const res = await fetch('index.php?api=delete', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      body: JSON.stringify({ table, id })
    });

    let payload = null;
    try { payload = await res.json(); } catch {}

    if (!res.ok || payload?.error) {
      console.error("Delete failed", { status: res.status, payload });
      debugError("Delete failed", {
        status: res.status,
        error: payload?.error || "Unknown error"
      });
      showToast(`Delete failed (${res.status})`, 'error');
      return;
    }

    debugLog("Delete success", { id });
    return payload;
  } catch (err) {
    console.error("Network error during delete", err);
  }
}

// Duplicate row
export async function duplicateRow(id) {
  const table = getCurrentTable();
  if (!table || !id) return;

  try {
    const res = await fetch('index.php?api=duplicate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      body: JSON.stringify({ table, id, action: 'duplicate' })
    });

    let payload = null;
    try { payload = await res.json(); } catch {}

    if (!res.ok || payload?.error) {
      console.error("Duplicate failed", { status: res.status, payload });
      showToast(payload?.error || `Duplicate failed (${res.status})`, 'error');
      return;
    }

    debugLog("Duplicate success", { id, newId: payload?.id });
    return payload;
  } catch (err) {
    console.error("Network error during duplicate", err);
  }
}

// Add row
export async function addRow() {
  const table = getCurrentTable();
  if (!table) return;

  try {
    const res = await fetch('index.php?api=insert', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      body: JSON.stringify({ table, data: {} })
    });

    let payload = null;
    try { payload = await res.json(); } catch {}

    if (!res.ok || payload?.error) {
      console.error("Insert failed", { status: res.status, payload });
      debugError("Insert failed", {
        status: res.status,
        error: payload?.error || "Unknown error"
      });
      showToast(`Insert failed (${res.status})`, 'error');
      return;
    }

    debugLog("Insert success", payload || { ok: true });
    return payload;
  } catch (err) {
    console.error("Network error during insert", err);
  }
}