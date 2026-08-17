// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { debugLog } from './debug.js';
import { showToast } from './toast.js';
import { loadTable } from './grid.js';
import { state } from './grid/state.js';

import { apiFetch } from './util/api.js';
import { I18n } from './i18n.js';

function debugError(message, data = {}) {
  const debugElement = document.getElementById('debug');
  if (!debugElement) return;

  const time = new Date().toLocaleTimeString();
  const entry =
    `[${time}] ERROR: ${message}\n` +
    `${JSON.stringify(data, null, 2)}\n\n`;

  debugElement.textContent += entry;
  debugElement.scrollTop = debugElement.scrollHeight;
}

function getCurrentTable() {
  return window.AppState?.currentTable;
}

async function postJson(url, method, body) {
  const result = await apiFetch(url, { method, body });

  let payload = null;

  try { payload = await result.json(); } catch {}

  return { res: result, payload };
}

function normalizeValue(element) {
  if (element.type === 'checkbox') {
    return element.checked;
  }
  if (element.type === 'date') {
    return (element.value || '').toString().slice(0, 10);
  }

  if (element.hasAttribute('list')) {
    const dl = document.getElementById(element.getAttribute('list'));
    if (dl) {
      const option = Array.from(dl.options).find(o => o.value === element.value);
      if (option) return option.dataset.realId;
      if (element.value === '') return null;
      return element._originalValue;
    }
  }

  if (element.isContentEditable) {
    return element.textContent.trim();
  }
  return element.value ?? element.textContent;
}

function markCell(td, ok) {
  if (!td) return;
  td.classList.remove('cell-success', 'cell-error');
  td.classList.add(ok ? 'cell-success' : 'cell-error');
  setTimeout(() => td.classList.remove('cell-success', 'cell-error'), 2000);
}

export function attachCellEvents(element) {
  element.addEventListener("focus", () => {
    element._originalValue = normalizeValue(element);
  });

  element.addEventListener("input", onInputChange);

  if (element.tagName === 'SELECT' || element.type === 'checkbox') {
    element.addEventListener("change", onCellBlur);
  } else {
    element.addEventListener("blur", onCellBlur);
  }
}

async function performUpdate(element, table, id, column, value) {
  debugLog("Updating cell", { id, col: column, value, table });
  const td = element.closest('td');

  try {
    const { res: result, payload } = await postJson('index.php?api=update', 'PATCH', { table, id, column, value });

    if (!result.ok || payload?.error) {
      console.error("Update failed", { status: result.status, payload });
      debugError("Update failed", {
        status: result.status,
        error: payload?.error || "Unknown error"
      });
      markCell(td, false);
      return;
    }

    debugLog("Update success", payload || { ok: true });
    markCell(td, true);
    element._originalValue = value;

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

export function onInputChange(e) {
  const element = e.target;
  const table = getCurrentTable();
  const id = element.dataset.id;
  const column = element.dataset.column;
  const value = normalizeValue(element);
}

export function onCellBlur(e) {
  const element = e.target;
  const table = getCurrentTable();
  const id = element.dataset.id;
  const column = element.dataset.column;
  const value = normalizeValue(element);

  const original = element._originalValue;

  if (original !== undefined && original === value) {
    return;
  }

  const pattern = element.dataset.pattern;
  if (pattern && value !== '' && value !== null) {
      try {
          const regex = new RegExp(pattern);
          if (!regex.test(String(value))) {
              const msg = element.dataset.message || 'Invalid input format';
              showToast(msg, 'error');

              if (element.isContentEditable) element.textContent = original ?? '';
              else element.value = original ?? '';

              return;
          }
      } catch (err) {
          console.error("Invalid regex pattern provided from schema", err);
      }
  }

  element._originalValue = value;

  if (!table || !id || !column) {
    console.warn("Missing update context", { table, id, column });
    return;
  }

  performUpdate(element, table, id, column, value);
}

export async function deleteRow(id) {
  const table = getCurrentTable();
  if (!table || !id) return;

  try {
    const { res: result, payload } = await postJson('index.php?api=delete', 'DELETE', { table, id });

    if (!result.ok || payload?.error) {
      console.error("Delete failed", { status: result.status, payload });
      debugError("Delete failed", {
        status: result.status,
        error: payload?.error || "Unknown error"
      });
      showToast(I18n.t('grid.delete_failed', { status: result.status }), 'error');
      return;
    }

    debugLog("Delete success", { id });
    return payload;
  } catch (err) {
    console.error("Network error during delete", err);
  }
}

export async function duplicateRow(id) {
  const table = getCurrentTable();
  if (!table || !id) return;

  try {
    const { res: result, payload } = await postJson('index.php?api=duplicate', 'POST', { table, id, action: 'duplicate' });

    if (!result.ok || payload?.error) {
      console.error("Duplicate failed", { status: result.status, payload });
      showToast(payload?.error || `Duplicate failed (${result.status})`, 'error');
      return;
    }

    debugLog("Duplicate success", { id, newId: payload?.id });
    return payload;
  } catch (err) {
    console.error("Network error during duplicate", err);
  }
}

export async function addRow() {
  const table = getCurrentTable();
  if (!table) return;

  try {
    const { res: result, payload } = await postJson('index.php?api=insert', 'POST', { table, data: {} });

    if (!result.ok || payload?.error) {
      console.error("Insert failed", { status: result.status, payload });
      debugError("Insert failed", {
        status: result.status,
        error: payload?.error || "Unknown error"
      });
      showToast(I18n.t('grid.insert_failed', { status: result.status }), 'error');
      return;
    }

    debugLog("Insert success", payload || { ok: true });
    return payload;
  } catch (err) {
    console.error("Network error during insert", err);
  }
}
