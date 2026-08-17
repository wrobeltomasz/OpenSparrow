// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { I18n } from '../i18n.js';
import { state } from './state.js';

const SKIP_CLASS = new Set(['td-actions', 'td-m2m', 'td-images']);
const PAGE_STEP    = 10;
const CTRL_HOLD_MS = 2000;
const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform ?? navigator.userAgent);

function isCtrl(event) { return IS_MAC ? event.metaKey : event.ctrlKey; }

function matchShortcut(event, shortcuts) {
    if (!shortcuts) return false;
    if (event.key !== shortcuts.key && event.key.toLowerCase() !== shortcuts.key.toLowerCase()) return false;
    if (isCtrl(event) !== (shortcuts.ctrl ?? false)) return false;
    if (event.shiftKey !== (shortcuts.shift ?? false)) return false;
    if (event.altKey !== (shortcuts.alt ?? false)) return false;
    return true;
}

function inEditContext() {
    const element = document.activeElement;
    if (!element) return false;
    const tag = element.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (element.closest('[role="dialog"]') !== null && tag !== 'TD') return true;
    return false;
}

function isCellNavigable(td) {
    if (!td.dataset.column) return false;
    for (const cssClass of SKIP_CLASS) {
        if (td.classList.contains(cssClass)) return false;
    }
    return true;
}

export const defaultShortcuts = {
    navigate: {
        up:        { key: 'ArrowUp' },
        down:      { key: 'ArrowDown' },
        left:      { key: 'ArrowLeft' },
        right:     { key: 'ArrowRight' },
        tabNext:   { key: 'Tab' },
        tabPrev:   { key: 'Tab', shift: true },
        rowStart:  { key: 'Home' },
        rowEnd:    { key: 'End' },
        gridFirst: { key: 'Home', ctrl: true },
        gridLast:  { key: 'End',  ctrl: true },
        pageUp:    { key: 'PageUp' },
        pageDown:  { key: 'PageDown' },
    },
    edit: {
        enter:  { key: 'Enter' },
        f2:     { key: 'F2' },
        escape: { key: 'Escape' },
    },
    select: {
        all:         { key: 'a', ctrl: true },
        extendUp:    { key: 'ArrowUp',    shift: true },
        extendDown:  { key: 'ArrowDown',  shift: true },
        extendLeft:  { key: 'ArrowLeft',  shift: true },
        extendRight: { key: 'ArrowRight', shift: true },
    },
    clipboard: {
        copy:  { key: 'c', ctrl: true },
        paste: { key: 'v', ctrl: true },
        cut:   { key: 'x', ctrl: true },
        undo:  { key: 'z', ctrl: true },
    },
    search: { key: 'f', ctrl: true },
};

export class GridKeyboard {
    constructor(containerElement, customShortcuts = {}) {
        this._container = containerElement;
        this._sc        = this._mergeShortcuts(customShortcuts);

        this._grid      = [];
        this._focusRow  = -1;
        this._focusCol  = -1;
        this._anchorRow = -1;
        this._anchorCol = -1;
        this._selected  = new Set();
        this._searchMatches   = new Set();
        this._navModeEditable = new Map();
        this._ctrlHoldTimer   = null;
        this._helpEl    = null;
        this._backdropEl = null;
        this._liveRegion = null;

        this._onKeyDown     = this._handleKeyDown.bind(this);
        this._onKeyUp       = this._handleKeyUp.bind(this);
        this._onClick       = this._handleClick.bind(this);
        this._onFocusin     = this._handleFocusin.bind(this);
        this._onTableLoaded = this._refresh.bind(this);

        document.addEventListener('keydown', this._onKeyDown, true);
        document.addEventListener('keyup',   this._onKeyUp,   true);
        document.addEventListener('tableLoaded', this._onTableLoaded);
        containerElement.addEventListener('click',   this._onClick);
        containerElement.addEventListener('focusin', this._onFocusin);

        const helpButton = document.getElementById('kgHelpBtn');
        if (helpButton) helpButton.addEventListener('click', () => this._showHelp());

        this._buildLiveRegion();
        this._refresh();
    }

    _mergeShortcuts(custom) {
        const merge = (def, over) => {
            const shortcutMap = {};
            for (const k of Object.keys(def)) shortcutMap[k] = (over && k in over) ? over[k] : def[k];
            return shortcutMap;
        };
        const defaults = defaultShortcuts;
        return {
            navigate:  merge(defaults.navigate,  custom.navigate),
            edit:      merge(defaults.edit,      custom.edit),
            select:    merge(defaults.select,    custom.select),
            clipboard: merge(defaults.clipboard, custom.clipboard),
            search:    custom.search !== undefined ? custom.search : defaults.search,
        };
    }

    _buildLiveRegion() {
        let element = document.getElementById('kg-live-region');
        if (!element) {
            element = document.createElement('div');
            element.id = 'kg-live-region';
            element.className = 'kg-live-region';
            element.setAttribute('role', 'status');
            element.setAttribute('aria-live', 'polite');
            element.setAttribute('aria-atomic', 'true');
            document.body.appendChild(element);
        }
        this._liveRegion = element;
    }

    _announce(message) {
        if (!this._liveRegion) return;
        this._liveRegion.textContent = '';
        requestAnimationFrame(() => { this._liveRegion.textContent = message; });
    }

    _refresh() {
        this._navModeEditable.clear();
        this._grid = this._buildCellGrid();
        this._focusRow = -1;
        this._focusCol = -1;
        this._selected.clear();
        this._clearSearchHighlights();
        this._applyAria();
    }

    _buildCellGrid() {
        const table = this._container.querySelector('table');
        if (!table) return [];
        const rows = table.querySelectorAll('tbody tr:not(.drilldown-row)');
        const grid = [];
        for (const tr of rows) {
            const cells = Array.from(tr.querySelectorAll('td')).filter(isCellNavigable);
            if (cells.length > 0) grid.push(cells);
        }
        return grid;
    }

    _applyAria() {
        const table = this._container.querySelector('table');
        if (!table) return;

        if (!table.hasAttribute('role')) table.setAttribute('role', 'grid');

        table.querySelectorAll('tbody tr:not(.drilldown-row)').forEach(tr => {
            if (!tr.hasAttribute('role')) tr.setAttribute('role', 'row');
            Array.from(tr.querySelectorAll('td')).forEach(td => {
                if (!isCellNavigable(td)) return;
                if (!td.hasAttribute('role')) td.setAttribute('role', 'gridcell');
                td.tabIndex = -1;
            });
        });

        if (this._focusRow >= 0 && this._grid[this._focusRow]?.[this._focusCol]) {
            this._grid[this._focusRow][this._focusCol].tabIndex = 0;
        } else if (this._grid.length > 0) {
            this._grid[0][0]?.setAttribute('tabindex', '0');
        }
    }

    _focusCell(rowIndex, columnIndex, announce = true, navMode = false) {
        if (rowIndex < 0 || rowIndex >= this._grid.length) return;
        const row = this._grid[rowIndex];
        if (!row || columnIndex < 0 || columnIndex >= row.length) return;

        const previous = this._focusRow >= 0 ? this._grid[this._focusRow]?.[this._focusCol] : null;
        if (previous) {
            previous.classList.remove('kg-focused');
            previous.tabIndex = -1;
            if (this._navModeEditable.has(previous)) {
                previous.contentEditable = this._navModeEditable.get(previous);
                this._navModeEditable.delete(previous);
            }
        }

        this._focusRow = rowIndex;
        this._focusCol = columnIndex;
        const cell = row[columnIndex];
        cell.classList.add('kg-focused');
        cell.tabIndex = 0;

        if (navMode && cell.contentEditable === 'true') {
            this._navModeEditable.set(cell, 'true');
            cell.contentEditable = 'false';
        }

        cell.focus({ preventScroll: false });

        if (announce) {
            const columnName  = cell.dataset.column || '';
            const text = cell.textContent.trim();
            this._announce(columnName ? `${columnName}: ${text}` : text);
        }
    }

    _navFocus(rowIndex, columnIndex, announce = true) { this._focusCell(rowIndex, columnIndex, announce, true); }

    _handleFocusin(event) {
        const td = event.target.closest('td');
        if (!td || !isCellNavigable(td)) return;
        for (let rowIndex = 0; rowIndex < this._grid.length; rowIndex++) {
            const columnIndex = this._grid[rowIndex].indexOf(td);
            if (columnIndex >= 0) {
                if (this._focusRow !== rowIndex || this._focusCol !== columnIndex) {
                    const previous = this._focusRow >= 0 ? this._grid[this._focusRow]?.[this._focusCol] : null;
                    if (previous) {
                        previous.classList.remove('kg-focused');
                        previous.tabIndex = -1;
                        if (this._navModeEditable.has(previous)) {
                            previous.contentEditable = this._navModeEditable.get(previous);
                            this._navModeEditable.delete(previous);
                        }
                    }
                    this._focusRow = rowIndex;
                    this._focusCol = columnIndex;
                    td.classList.add('kg-focused');
                    td.tabIndex = 0;
                }
                break;
            }
        }
    }

    _moveFocus(dr, dc) {
        let rowIndex = Math.max(0, Math.min(this._focusRow + dr, this._grid.length - 1));
        const row = this._grid[rowIndex];
        if (!row) return;
        const columnIndex = Math.max(0, Math.min(this._focusCol + dc, row.length - 1));
        this._navFocus(rowIndex, columnIndex);
    }

    _tabMove(forward) {
        if (this._grid.length === 0) return;
        let rowIndex = this._focusRow;
        let columnIndex = this._focusCol;

        if (rowIndex < 0) {
            this._navFocus(forward ? 0 : this._grid.length - 1,
                forward ? 0 : (this._grid.at(-1)?.length ?? 1) - 1);
            return;
        }

        if (forward) {
            columnIndex++;
            if (columnIndex >= this._grid[rowIndex].length) { columnIndex = 0; rowIndex = (rowIndex + 1) % this._grid.length; }
        } else {
            columnIndex--;
            if (columnIndex < 0) { rowIndex = rowIndex > 0 ? rowIndex - 1 : this._grid.length - 1; columnIndex = (this._grid[rowIndex]?.length ?? 1) - 1; }
        }
        this._navFocus(rowIndex, columnIndex);
    }

    _moveToRowBoundary(end) {
        if (this._focusRow < 0) return;
        const row = this._grid[this._focusRow];
        if (!row) return;
        this._navFocus(this._focusRow, end ? row.length - 1 : 0);
    }

    _moveToGridBoundary(end) {
        if (this._grid.length === 0) return;
        if (end) {
            const lastRowIndex = this._grid.length - 1;
            this._navFocus(lastRowIndex, (this._grid[lastRowIndex]?.length ?? 1) - 1);
        } else {
            this._navFocus(0, 0);
        }
    }

    _moveByPage(down) {
        if (this._focusRow < 0) { this._navFocus(0, 0); return; }
        const rowIndex = Math.max(0, Math.min(this._focusRow + (down ? PAGE_STEP : -PAGE_STEP), this._grid.length - 1));
        const row = this._grid[rowIndex];
        if (!row) return;
        this._navFocus(rowIndex, Math.min(this._focusCol, row.length - 1));
    }

    _extendSelection(dr, dc) {
        if (this._anchorRow < 0) {
            this._anchorRow = Math.max(0, this._focusRow);
            this._anchorCol = Math.max(0, this._focusCol);
        }
        this._moveFocus(dr, dc);
        this._rebuildSelectionRect();
    }

    _rebuildSelectionRect() {
        this._clearSelectionClasses();
        this._selected.clear();
        const r1 = Math.min(this._anchorRow, this._focusRow);
        const r2 = Math.max(this._anchorRow, this._focusRow);
        const c1 = Math.min(this._anchorCol, this._focusCol);
        const c2 = Math.max(this._anchorCol, this._focusCol);
        for (let rowIndex = r1; rowIndex <= r2; rowIndex++) {
            const row = this._grid[rowIndex];
            if (!row) continue;
            for (let columnIndex = c1; columnIndex <= Math.min(c2, row.length - 1); columnIndex++) {
                row[columnIndex].classList.add('kg-selected');
                this._selected.add(row[columnIndex]);
            }
        }
    }

    _selectAll() {
        this._clearSelectionClasses();
        this._selected.clear();
        for (const row of this._grid) {
            for (const cell of row) { cell.classList.add('kg-selected'); this._selected.add(cell); }
        }
        this._announce(I18n.t('shortcuts.all_selected').replace('{n}', this._selected.size));
    }

    _clearSelectionClasses() { for (const columnIndex of this._selected) columnIndex.classList.remove('kg-selected'); }

    _clearSelection() {
        this._clearSelectionClasses();
        this._selected.clear();
        this._anchorRow = -1;
        this._anchorCol = -1;
    }

    _enterEditMode() {
        if (this._focusRow < 0) return;
        const cell = this._grid[this._focusRow]?.[this._focusCol];
        if (!cell) return;

        if (this._navModeEditable.has(cell)) {
            cell.contentEditable = this._navModeEditable.get(cell);
            this._navModeEditable.delete(cell);
            cell.focus();
            const range = document.createRange();
            const selectElement   = window.getSelection();
            range.selectNodeContents(cell);
            range.collapse(false);
            selectElement.removeAllRanges();
            selectElement.addRange(range);
            return;
        }

        if (cell.contentEditable === 'true') return;

        const rowId = cell.dataset.id || this._getRowId(cell);
        if (rowId && state.currentTable) {
            window.location.href = `edit.php?table=${encodeURIComponent(state.currentTable)}&id=${encodeURIComponent(rowId)}`;
        }
    }

    _getRowId(cell) {
        const tr = cell.closest('tr');
        if (!tr) return null;
        return tr.querySelector('[data-actions-row-id]')?.dataset.actionsRowId
            ?? tr.querySelector('[data-m2m-row-id]')?.dataset.m2mRowId
            ?? null;
    }

    _copySelection() {
        const cells = this._selected.size > 0
            ? [...this._selected]
            : (this._focusRow >= 0 ? [this._grid[this._focusRow]?.[this._focusCol]] : []);
        if (!cells.length || !cells[0]) return;

        const byRow = new Map();
        for (const cell of cells) {
            const tr = cell.closest('tr');
            if (!tr) continue;
            if (!byRow.has(tr)) byRow.set(tr, []);
            byRow.get(tr).push(cell.textContent.trim());
        }
        const text = [...byRow.values()].map(rowIndex => rowIndex.join('\t')).join('\n');
        navigator.clipboard?.writeText(text).catch(() => {});
        this._announce(I18n.t('shortcuts.copied'));
    }

    _pasteClipboard() {
        const element = document.activeElement;
        if (element?.isContentEditable) {
            navigator.clipboard?.readText().then(clipboardText => document.execCommand('insertText', false, clipboardText)).catch(() => {});
        }
    }

    _undo() {
        if (document.activeElement?.isContentEditable) document.execCommand('undo');
    }

    _openSearch() {
        const searchElement = document.getElementById('globalSearch');
        if (!searchElement) return;
        searchElement.focus();
        searchElement.select?.();
        const term = searchElement.value.trim().toLowerCase();
        if (term) this._highlightSearchMatches(term);
    }

    _highlightSearchMatches(term) {
        this._clearSearchHighlights();
        for (const row of this._grid) {
            for (const cell of row) {
                if (cell.textContent.toLowerCase().includes(term)) {
                    cell.classList.add('kg-search-match');
                    this._searchMatches.add(cell);
                }
            }
        }
    }

    _clearSearchHighlights() {
        for (const cell of this._searchMatches) cell.classList.remove('kg-search-match');
        this._searchMatches.clear();
    }

    _showHelp() {
        if (this._helpEl) return;
        const modifierLabel = IS_MAC ? '⌘' : 'Ctrl';

        const backdrop = document.createElement('div');
        backdrop.className = 'kg-modal-backdrop';
        backdrop.addEventListener('click', () => this._hideHelp());
        document.body.appendChild(backdrop);
        this._backdropEl = backdrop;

        const helpElement = document.createElement('div');
        helpElement.className = 'kg-help-overlay';
        helpElement.setAttribute('role', 'dialog');
        helpElement.setAttribute('aria-modal', 'true');
        helpElement.setAttribute('aria-label', I18n.t('shortcuts.help_title'));

        const title = document.createElement('h3');
        title.className = 'kg-help-title';
        title.textContent = I18n.t('shortcuts.help_title');
        helpElement.appendChild(title);

        const rows = [
            ['↑ ↓ ← →',                I18n.t('shortcuts.navigate')],
            ['Tab / Shift+Tab',          I18n.t('shortcuts.tab_nav')],
            ['Home / End',               I18n.t('shortcuts.row_bounds')],
            [`${modifierLabel}+Home / ${modifierLabel}+End`, I18n.t('shortcuts.grid_bounds')],
            ['PgUp / PgDn',              I18n.t('shortcuts.page_nav')],
            ['Enter / F2',               I18n.t('shortcuts.edit')],
            ['Esc',                      I18n.t('shortcuts.escape')],
            ['Shift+↑↓←→',              I18n.t('shortcuts.extend_sel')],
            [`${modifierLabel}+A`,                 I18n.t('shortcuts.select_all')],
            [`${modifierLabel}+C`,                 I18n.t('shortcuts.copy')],
            [`${modifierLabel}+F`,                 I18n.t('shortcuts.search')],
            [`${modifierLabel} (2s)`,              I18n.t('shortcuts.help')],
        ];

        const tableElement = document.createElement('table');
        tableElement.className = 'kg-help-table';
        for (const [key, description] of rows) {
            const tr  = document.createElement('tr');
            const keyCell = document.createElement('td');
            keyCell.className = 'kg-help-key';
            keyCell.textContent = key;
            const tdD = document.createElement('td');
            tdD.textContent = description;
            tr.appendChild(keyCell);
            tr.appendChild(tdD);
            tableElement.appendChild(tr);
        }
        helpElement.appendChild(tableElement);

        const closeButton = document.createElement('button');
        closeButton.className = 'kg-help-close';
        closeButton.textContent = '×';
        closeButton.setAttribute('aria-label', I18n.t('shortcuts.close_help'));
        closeButton.addEventListener('click', () => this._hideHelp());
        helpElement.appendChild(closeButton);

        document.body.appendChild(helpElement);
        this._helpEl = helpElement;
        closeButton.focus();
    }

    _hideHelp() {
        this._helpEl?.remove();
        this._helpEl = null;
        this._backdropEl?.remove();
        this._backdropEl = null;
        if (this._focusRow >= 0 && this._grid[this._focusRow]?.[this._focusCol]) {
            this._grid[this._focusRow][this._focusCol].focus();
        }
    }

    _handleKeyDown(event) {
        if (this._helpEl) {
            if (event.key === 'Escape') { event.preventDefault(); this._hideHelp(); }
            return;
        }

        if (isCtrl(event) && !event.shiftKey && !event.altKey && !event.repeat) {
            if (!this._ctrlHoldTimer) {
                this._ctrlHoldTimer = setTimeout(() => {
                    this._ctrlHoldTimer = null;
                    this._showHelp();
                }, CTRL_HOLD_MS);
            }
        }

        const shortcuts = this._sc;

        if (this._focusRow >= 0) {
            if (matchShortcut(event, shortcuts.navigate.tabNext)) { event.preventDefault(); this._clearSelection(); this._tabMove(true);  return; }
            if (matchShortcut(event, shortcuts.navigate.tabPrev)) { event.preventDefault(); this._clearSelection(); this._tabMove(false); return; }
        }

        const active = document.activeElement;
        const isCellEdit = active?.tagName === 'TD'
            && active.contentEditable === 'true'
            && !this._navModeEditable.has(active);

        if (isCellEdit) {
            if (event.key === 'Escape') {
                event.preventDefault();

                active.contentEditable = 'false';
                this._navModeEditable.set(active, 'true');
                active.focus({ preventScroll: false });
            }
            return;
        }

        if (inEditContext()) return;

        if (matchShortcut(event, shortcuts.search))           { event.preventDefault(); this._openSearch(); return; }
        if (matchShortcut(event, shortcuts.clipboard.copy))   { event.preventDefault(); this._copySelection(); return; }
        if (matchShortcut(event, shortcuts.clipboard.cut))    { event.preventDefault(); this._copySelection(); return; }
        if (matchShortcut(event, shortcuts.clipboard.paste))  { event.preventDefault(); this._pasteClipboard(); return; }
        if (matchShortcut(event, shortcuts.clipboard.undo))   { event.preventDefault(); this._undo(); return; }
        if (matchShortcut(event, shortcuts.select.all))        { event.preventDefault(); this._selectAll(); return; }

        const inGrid = this._focusRow >= 0 || this._container.contains(active);
        if (!inGrid) return;

        if (matchShortcut(event, shortcuts.navigate.gridFirst)) { event.preventDefault(); this._clearSelection(); this._moveToGridBoundary(false); return; }
        if (matchShortcut(event, shortcuts.navigate.gridLast))  { event.preventDefault(); this._clearSelection(); this._moveToGridBoundary(true);  return; }

        if (matchShortcut(event, shortcuts.select.extendUp))    { event.preventDefault(); this._extendSelection(-1, 0);  return; }
        if (matchShortcut(event, shortcuts.select.extendDown))  { event.preventDefault(); this._extendSelection(1, 0);   return; }
        if (matchShortcut(event, shortcuts.select.extendLeft))  { event.preventDefault(); this._extendSelection(0, -1);  return; }
        if (matchShortcut(event, shortcuts.select.extendRight)) { event.preventDefault(); this._extendSelection(0, 1);   return; }

        if (matchShortcut(event, shortcuts.navigate.up))       { event.preventDefault(); this._clearSelection(); this._moveFocus(-1, 0);           return; }
        if (matchShortcut(event, shortcuts.navigate.down))     { event.preventDefault(); this._clearSelection(); this._moveFocus(1, 0);            return; }
        if (matchShortcut(event, shortcuts.navigate.left))     { event.preventDefault(); this._clearSelection(); this._moveFocus(0, -1);           return; }
        if (matchShortcut(event, shortcuts.navigate.right))    { event.preventDefault(); this._clearSelection(); this._moveFocus(0, 1);            return; }
        if (matchShortcut(event, shortcuts.navigate.rowStart)) { event.preventDefault(); this._clearSelection(); this._moveToRowBoundary(false);   return; }
        if (matchShortcut(event, shortcuts.navigate.rowEnd))   { event.preventDefault(); this._clearSelection(); this._moveToRowBoundary(true);    return; }
        if (matchShortcut(event, shortcuts.navigate.pageUp))   { event.preventDefault(); this._clearSelection(); this._moveByPage(false);          return; }
        if (matchShortcut(event, shortcuts.navigate.pageDown)) { event.preventDefault(); this._clearSelection(); this._moveByPage(true);           return; }

        if (matchShortcut(event, shortcuts.edit.enter) || matchShortcut(event, shortcuts.edit.f2)) { event.preventDefault(); this._enterEditMode(); return; }
        if (matchShortcut(event, shortcuts.edit.escape)) { this._clearSelection(); return; }
    }

    _handleKeyUp(event) {
        const ctrlReleased = IS_MAC ? !event.metaKey : !event.ctrlKey;
        if (ctrlReleased && this._ctrlHoldTimer) {
            clearTimeout(this._ctrlHoldTimer);
            this._ctrlHoldTimer = null;
        }
    }

    _handleClick(event) {
        const td = event.target.closest('td');
        if (!td || !isCellNavigable(td)) return;

        for (let rowIndex = 0; rowIndex < this._grid.length; rowIndex++) {
            const columnIndex = this._grid[rowIndex].indexOf(td);
            if (columnIndex < 0) continue;
            if (event.ctrlKey || event.metaKey) {
                if (this._selected.has(td)) { td.classList.remove('kg-selected'); this._selected.delete(td); }
                else                         { td.classList.add('kg-selected');    this._selected.add(td); }
            } else {
                this._clearSelection();

                this._focusCell(rowIndex, columnIndex, false, false);
            }
            break;
        }
    }

    destroy() {
        for (const [cell, value] of this._navModeEditable) cell.contentEditable = value;
        document.removeEventListener('keydown', this._onKeyDown, true);
        document.removeEventListener('keyup',   this._onKeyUp,   true);
        document.removeEventListener('tableLoaded', this._onTableLoaded);
        this._container.removeEventListener('click',   this._onClick);
        this._container.removeEventListener('focusin', this._onFocusin);
        if (this._ctrlHoldTimer) clearTimeout(this._ctrlHoldTimer);
        this._hideHelp();
        this._liveRegion?.remove();
    }
}

export function initGridKeyboard(customShortcuts = {}) {
    const container = document.getElementById('grid');
    if (!container) return null;
    return new GridKeyboard(container, customShortcuts);
}
