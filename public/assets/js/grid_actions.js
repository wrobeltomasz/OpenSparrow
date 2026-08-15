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

async function postJson(url, method, body) {
  const res = await apiFetch(url, { method, body });

  let payload = null;

  try { payload = await res.json(); } catch {}

  return { res, payload };
}

function normalizeValue(el) {
  if (el.type === 'checkbox') {
    return el.checked;
  }
  if (el.type === 'date') {
    return (el.value || '').toString().slice(0, 10);
  }

  if (el.hasAttribute('list')) {
    const dl = document.getElementById(el.getAttribute('list'));
    if (dl) {
      const opt = Array.from(dl.options).find(o => o.value === el.value);
      if (opt) return opt.dataset.realId;
      if (el.value === '') return null;
      return el._originalValue;
    }
  }

  if (el.isContentEditable) {
    return el.textContent.trim();
  }
  return el.value ?? el.textContent;
}

function markCell(td, ok) {
  if (!td) return;
  td.classList.remove('cell-success', 'cell-error');
  td.classList.add(ok ? 'cell-success' : 'cell-error');
  setTimeout(() => td.classList.remove('cell-success', 'cell-error'), 2000);
}

export function attachCellEvents(el) {
  el.addEventListener("focus", () => {
    el._originalValue = normalizeValue(el);
  });

  el.addEventListener("input", onInputChange);

  if (el.tagName === 'SELECT' || el.type === 'checkbox') {
    el.addEventListener("change", onCellBlur);
  } else {
    el.addEventListener("blur", onCellBlur);
  }
}

async function performUpdate(el, table, id, column, value) {
  debugLog("Updating cell", { id, col: column, value, table });
  const td = el.closest('td');

  try {
    const { res, payload } = await postJson('index.php?api=update', 'PATCH', { table, id, column, value });

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
  const el = e.target;
  const table = getCurrentTable();
  const id = el.dataset.id;
  const column = el.dataset.column;
  const value = normalizeValue(el);
}

export function onCellBlur(e) {
  const el = e.target;
  const table = getCurrentTable();
  const id = el.dataset.id;
  const column = el.dataset.column;
  const value = normalizeValue(el);

  const original = el._originalValue;

  if (original !== undefined && original === value) {
    return;
  }

  const pattern = el.dataset.pattern;
  if (pattern && value !== '' && value !== null) {
      try {
          const regex = new RegExp(pattern);
          if (!regex.test(String(value))) {
              const msg = el.dataset.message || 'Invalid input format';
              showToast(msg, 'error');

              if (el.isContentEditable) el.textContent = original ?? '';
              else el.value = original ?? '';

              return;
          }
      } catch (err) {
          console.error("Invalid regex pattern provided from schema", err);
      }
  }

  el._originalValue = value;

  if (!table || !id || !column) {
    console.warn("Missing update context", { table, id, column });
    return;
  }

  performUpdate(el, table, id, column, value);
}

export async function deleteRow(id) {
  const table = getCurrentTable();
  if (!table || !id) return;

  try {
    const { res, payload } = await postJson('index.php?api=delete', 'DELETE', { table, id });

    if (!res.ok || payload?.error) {
      console.error("Delete failed", { status: res.status, payload });
      debugError("Delete failed", {
        status: res.status,
        error: payload?.error || "Unknown error"
      });
      showToast(I18n.t('grid.delete_failed', { status: res.status }), 'error');
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
    const { res, payload } = await postJson('index.php?api=duplicate', 'POST', { table, id, action: 'duplicate' });

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

export async function addRow() {
  const table = getCurrentTable();
  if (!table) return;

  try {
    const { res, payload } = await postJson('index.php?api=insert', 'POST', { table, data: {} });

    if (!res.ok || payload?.error) {
      console.error("Insert failed", { status: res.status, payload });
      debugError("Insert failed", {
        status: res.status,
        error: payload?.error || "Unknown error"
      });
      showToast(I18n.t('grid.insert_failed', { status: res.status }), 'error');
      return;
    }

    debugLog("Insert success", payload || { ok: true });
    return payload;
  } catch (err) {
    console.error("Network error during insert", err);
  }
}
