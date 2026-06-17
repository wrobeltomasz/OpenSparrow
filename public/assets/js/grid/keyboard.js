// assets/js/grid/keyboard.js — Keyboard navigation/shortcuts for the grid (arrow keys, page step, Ctrl/Cmd combos; Mac-aware). Skips action/m2m cells.

import { I18n } from '../i18n.js';
import { state } from './state.js';

const SKIP_CLASS = new Set(['td-actions', 'td-m2m']);
const PAGE_STEP    = 10;
const CTRL_HOLD_MS = 2000;
const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform ?? navigator.userAgent);

function isCtrl(e) { return IS_MAC ? e.metaKey : e.ctrlKey; }

function matchShortcut(e, sc) {
    if (!sc) return false;
    if (e.key !== sc.key && e.key.toLowerCase() !== sc.key.toLowerCase()) return false;
    if (isCtrl(e) !== (sc.ctrl ?? false)) return false;
    if (e.shiftKey !== (sc.shift ?? false)) return false;
    if (e.altKey !== (sc.alt ?? false)) return false;
    return true;
}

function inEditContext() {
    const el = document.activeElement;
    if (!el) return false;
    const tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (el.closest('[role="dialog"]') !== null && tag !== 'TD') return true;
    return false;
}

function isCellNavigable(td) {
    if (!td.dataset.column) return false;
    for (const cls of SKIP_CLASS) {
        if (td.classList.contains(cls)) return false;
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
    constructor(containerEl, customShortcuts = {}) {
        this._container = containerEl;
        this._sc        = this._mergeShortcuts(customShortcuts);

        this._grid      = [];
        this._focusRow  = -1;
        this._focusCol  = -1;
        this._anchorRow = -1;
        this._anchorCol = -1;
        this._selected  = new Set();
        this._searchMatches   = new Set();
        this._navModeEditable = new Map(); // cells where contentEditable is temp-disabled
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
        containerEl.addEventListener('click',   this._onClick);
        containerEl.addEventListener('focusin', this._onFocusin);

        const helpBtn = document.getElementById('kgHelpBtn');
        if (helpBtn) helpBtn.addEventListener('click', () => this._showHelp());

        this._buildLiveRegion();
        this._refresh();
    }

    _mergeShortcuts(custom) {
        const merge = (def, over) => {
            const out = {};
            for (const k of Object.keys(def)) out[k] = (over && k in over) ? over[k] : def[k];
            return out;
        };
        const d = defaultShortcuts;
        return {
            navigate:  merge(d.navigate,  custom.navigate),
            edit:      merge(d.edit,      custom.edit),
            select:    merge(d.select,    custom.select),
            clipboard: merge(d.clipboard, custom.clipboard),
            search:    custom.search !== undefined ? custom.search : d.search,
        };
    }

    _buildLiveRegion() {
        let el = document.getElementById('kg-live-region');
        if (!el) {
            el = document.createElement('div');
            el.id = 'kg-live-region';
            el.className = 'kg-live-region';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.setAttribute('aria-atomic', 'true');
            document.body.appendChild(el);
        }
        this._liveRegion = el;
    }

    _announce(msg) {
        if (!this._liveRegion) return;
        this._liveRegion.textContent = '';
        requestAnimationFrame(() => { this._liveRegion.textContent = msg; });
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

    // navMode=true: temporarily disables contentEditable so arrow keys work after focus
    _focusCell(r, c, announce = true, navMode = false) {
        if (r < 0 || r >= this._grid.length) return;
        const row = this._grid[r];
        if (!row || c < 0 || c >= row.length) return;

        const prev = this._focusRow >= 0 ? this._grid[this._focusRow]?.[this._focusCol] : null;
        if (prev) {
            prev.classList.remove('kg-focused');
            prev.tabIndex = -1;
            if (this._navModeEditable.has(prev)) {
                prev.contentEditable = this._navModeEditable.get(prev);
                this._navModeEditable.delete(prev);
            }
        }

        this._focusRow = r;
        this._focusCol = c;
        const cell = row[c];
        cell.classList.add('kg-focused');
        cell.tabIndex = 0;

        if (navMode && cell.contentEditable === 'true') {
            this._navModeEditable.set(cell, 'true');
            cell.contentEditable = 'false';
        }

        cell.focus({ preventScroll: false });

        if (announce) {
            const col  = cell.dataset.column || '';
            const text = cell.textContent.trim();
            this._announce(col ? `${col}: ${text}` : text);
        }
    }

    // All keyboard-driven navigation uses navMode=true
    _navFocus(r, c, announce = true) { this._focusCell(r, c, announce, true); }

    _handleFocusin(e) {
        const td = e.target.closest('td');
        if (!td || !isCellNavigable(td)) return;
        for (let r = 0; r < this._grid.length; r++) {
            const c = this._grid[r].indexOf(td);
            if (c >= 0) {
                if (this._focusRow !== r || this._focusCol !== c) {
                    const prev = this._focusRow >= 0 ? this._grid[this._focusRow]?.[this._focusCol] : null;
                    if (prev) {
                        prev.classList.remove('kg-focused');
                        prev.tabIndex = -1;
                        if (this._navModeEditable.has(prev)) {
                            prev.contentEditable = this._navModeEditable.get(prev);
                            this._navModeEditable.delete(prev);
                        }
                    }
                    this._focusRow = r;
                    this._focusCol = c;
                    td.classList.add('kg-focused');
                    td.tabIndex = 0;
                }
                break;
            }
        }
    }

    _moveFocus(dr, dc) {
        let r = Math.max(0, Math.min(this._focusRow + dr, this._grid.length - 1));
        const row = this._grid[r];
        if (!row) return;
        const c = Math.max(0, Math.min(this._focusCol + dc, row.length - 1));
        this._navFocus(r, c);
    }

    _tabMove(forward) {
        if (this._grid.length === 0) return;
        let r = this._focusRow;
        let c = this._focusCol;

        if (r < 0) {
            this._navFocus(forward ? 0 : this._grid.length - 1,
                forward ? 0 : (this._grid.at(-1)?.length ?? 1) - 1);
            return;
        }

        if (forward) {
            c++;
            if (c >= this._grid[r].length) { c = 0; r = (r + 1) % this._grid.length; }
        } else {
            c--;
            if (c < 0) { r = r > 0 ? r - 1 : this._grid.length - 1; c = (this._grid[r]?.length ?? 1) - 1; }
        }
        this._navFocus(r, c);
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
            const lr = this._grid.length - 1;
            this._navFocus(lr, (this._grid[lr]?.length ?? 1) - 1);
        } else {
            this._navFocus(0, 0);
        }
    }

    _moveByPage(down) {
        if (this._focusRow < 0) { this._navFocus(0, 0); return; }
        const r = Math.max(0, Math.min(this._focusRow + (down ? PAGE_STEP : -PAGE_STEP), this._grid.length - 1));
        const row = this._grid[r];
        if (!row) return;
        this._navFocus(r, Math.min(this._focusCol, row.length - 1));
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
        for (let r = r1; r <= r2; r++) {
            const row = this._grid[r];
            if (!row) continue;
            for (let c = c1; c <= Math.min(c2, row.length - 1); c++) {
                row[c].classList.add('kg-selected');
                this._selected.add(row[c]);
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

    _clearSelectionClasses() { for (const c of this._selected) c.classList.remove('kg-selected'); }

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

        // Nav-mode cell: restore contentEditable and enter text editing
        if (this._navModeEditable.has(cell)) {
            cell.contentEditable = this._navModeEditable.get(cell);
            this._navModeEditable.delete(cell);
            cell.focus();
            const range = document.createRange();
            const sel   = window.getSelection();
            range.selectNodeContents(cell);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
            return;
        }

        if (cell.contentEditable === 'true') return; // already editing (click-focused)

        // Non-editable cell → navigate to edit form
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
        const text = [...byRow.values()].map(r => r.join('\t')).join('\n');
        navigator.clipboard?.writeText(text).catch(() => {});
        this._announce(I18n.t('shortcuts.copied'));
    }

    _pasteClipboard() {
        const el = document.activeElement;
        if (el?.isContentEditable) {
            navigator.clipboard?.readText().then(t => document.execCommand('insertText', false, t)).catch(() => {});
        }
    }

    _undo() {
        if (document.activeElement?.isContentEditable) document.execCommand('undo');
    }

    _openSearch() {
        const searchEl = document.getElementById('globalSearch');
        if (!searchEl) return;
        searchEl.focus();
        searchEl.select?.();
        const term = searchEl.value.trim().toLowerCase();
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
        const mod = IS_MAC ? '⌘' : 'Ctrl';

        const backdrop = document.createElement('div');
        backdrop.className = 'kg-modal-backdrop';
        backdrop.addEventListener('click', () => this._hideHelp());
        document.body.appendChild(backdrop);
        this._backdropEl = backdrop;

        const helpEl = document.createElement('div');
        helpEl.className = 'kg-help-overlay';
        helpEl.setAttribute('role', 'dialog');
        helpEl.setAttribute('aria-modal', 'true');
        helpEl.setAttribute('aria-label', I18n.t('shortcuts.help_title'));

        const title = document.createElement('h3');
        title.className = 'kg-help-title';
        title.textContent = I18n.t('shortcuts.help_title');
        helpEl.appendChild(title);

        const rows = [
            ['↑ ↓ ← →',                I18n.t('shortcuts.navigate')],
            ['Tab / Shift+Tab',          I18n.t('shortcuts.tab_nav')],
            ['Home / End',               I18n.t('shortcuts.row_bounds')],
            [`${mod}+Home / ${mod}+End`, I18n.t('shortcuts.grid_bounds')],
            ['PgUp / PgDn',              I18n.t('shortcuts.page_nav')],
            ['Enter / F2',               I18n.t('shortcuts.edit')],
            ['Esc',                      I18n.t('shortcuts.escape')],
            ['Shift+↑↓←→',              I18n.t('shortcuts.extend_sel')],
            [`${mod}+A`,                 I18n.t('shortcuts.select_all')],
            [`${mod}+C`,                 I18n.t('shortcuts.copy')],
            [`${mod}+F`,                 I18n.t('shortcuts.search')],
            [`${mod} (2s)`,              I18n.t('shortcuts.help')],
        ];

        const tbl = document.createElement('table');
        tbl.className = 'kg-help-table';
        for (const [key, desc] of rows) {
            const tr  = document.createElement('tr');
            const tdK = document.createElement('td');
            tdK.className = 'kg-help-key';
            tdK.textContent = key;
            const tdD = document.createElement('td');
            tdD.textContent = desc;
            tr.appendChild(tdK);
            tr.appendChild(tdD);
            tbl.appendChild(tr);
        }
        helpEl.appendChild(tbl);

        const closeBtn = document.createElement('button');
        closeBtn.className = 'kg-help-close';
        closeBtn.textContent = '×';
        closeBtn.setAttribute('aria-label', I18n.t('shortcuts.close_help'));
        closeBtn.addEventListener('click', () => this._hideHelp());
        helpEl.appendChild(closeBtn);

        document.body.appendChild(helpEl);
        this._helpEl = helpEl;
        closeBtn.focus();
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

    _handleKeyDown(e) {
        if (this._helpEl) {
            if (e.key === 'Escape') { e.preventDefault(); this._hideHelp(); }
            return;
        }

        // Ctrl hold for help overlay
        if (isCtrl(e) && !e.shiftKey && !e.altKey && !e.repeat) {
            if (!this._ctrlHoldTimer) {
                this._ctrlHoldTimer = setTimeout(() => {
                    this._ctrlHoldTimer = null;
                    this._showHelp();
                }, CTRL_HOLD_MS);
            }
        }

        const sc = this._sc;

        // Tab always intercepted when grid position is known
        if (this._focusRow >= 0) {
            if (matchShortcut(e, sc.navigate.tabNext)) { e.preventDefault(); this._clearSelection(); this._tabMove(true);  return; }
            if (matchShortcut(e, sc.navigate.tabPrev)) { e.preventDefault(); this._clearSelection(); this._tabMove(false); return; }
        }

        // contentEditable cell in EDIT mode (not nav mode — user is typing)
        const active = document.activeElement;
        const isCellEdit = active?.tagName === 'TD'
            && active.contentEditable === 'true'
            && !this._navModeEditable.has(active);

        if (isCellEdit) {
            if (e.key === 'Escape') {
                e.preventDefault();
                // Exit edit mode: stay on cell but disable text editing
                active.contentEditable = 'false';
                this._navModeEditable.set(active, 'true');
                active.focus({ preventScroll: false });
            }
            return;
        }

        // Block shortcuts in regular form inputs / dialogs
        if (inEditContext()) return;

        // Global shortcuts (work regardless of grid focus)
        if (matchShortcut(e, sc.search))           { e.preventDefault(); this._openSearch(); return; }
        if (matchShortcut(e, sc.clipboard.copy))   { e.preventDefault(); this._copySelection(); return; }
        if (matchShortcut(e, sc.clipboard.cut))    { e.preventDefault(); this._copySelection(); return; }
        if (matchShortcut(e, sc.clipboard.paste))  { e.preventDefault(); this._pasteClipboard(); return; }
        if (matchShortcut(e, sc.clipboard.undo))   { e.preventDefault(); this._undo(); return; }
        if (matchShortcut(e, sc.select.all))        { e.preventDefault(); this._selectAll(); return; }

        const inGrid = this._focusRow >= 0 || this._container.contains(active);
        if (!inGrid) return;

        // Grid boundary (Ctrl+Home/End) before plain Home/End
        if (matchShortcut(e, sc.navigate.gridFirst)) { e.preventDefault(); this._clearSelection(); this._moveToGridBoundary(false); return; }
        if (matchShortcut(e, sc.navigate.gridLast))  { e.preventDefault(); this._clearSelection(); this._moveToGridBoundary(true);  return; }

        // Shift+arrow selection before plain arrows
        if (matchShortcut(e, sc.select.extendUp))    { e.preventDefault(); this._extendSelection(-1, 0);  return; }
        if (matchShortcut(e, sc.select.extendDown))  { e.preventDefault(); this._extendSelection(1, 0);   return; }
        if (matchShortcut(e, sc.select.extendLeft))  { e.preventDefault(); this._extendSelection(0, -1);  return; }
        if (matchShortcut(e, sc.select.extendRight)) { e.preventDefault(); this._extendSelection(0, 1);   return; }

        // Navigation
        if (matchShortcut(e, sc.navigate.up))       { e.preventDefault(); this._clearSelection(); this._moveFocus(-1, 0);           return; }
        if (matchShortcut(e, sc.navigate.down))     { e.preventDefault(); this._clearSelection(); this._moveFocus(1, 0);            return; }
        if (matchShortcut(e, sc.navigate.left))     { e.preventDefault(); this._clearSelection(); this._moveFocus(0, -1);           return; }
        if (matchShortcut(e, sc.navigate.right))    { e.preventDefault(); this._clearSelection(); this._moveFocus(0, 1);            return; }
        if (matchShortcut(e, sc.navigate.rowStart)) { e.preventDefault(); this._clearSelection(); this._moveToRowBoundary(false);   return; }
        if (matchShortcut(e, sc.navigate.rowEnd))   { e.preventDefault(); this._clearSelection(); this._moveToRowBoundary(true);    return; }
        if (matchShortcut(e, sc.navigate.pageUp))   { e.preventDefault(); this._clearSelection(); this._moveByPage(false);          return; }
        if (matchShortcut(e, sc.navigate.pageDown)) { e.preventDefault(); this._clearSelection(); this._moveByPage(true);           return; }

        // Edit
        if (matchShortcut(e, sc.edit.enter) || matchShortcut(e, sc.edit.f2)) { e.preventDefault(); this._enterEditMode(); return; }
        if (matchShortcut(e, sc.edit.escape)) { this._clearSelection(); return; }
    }

    _handleKeyUp(e) {
        const ctrlReleased = IS_MAC ? !e.metaKey : !e.ctrlKey;
        if (ctrlReleased && this._ctrlHoldTimer) {
            clearTimeout(this._ctrlHoldTimer);
            this._ctrlHoldTimer = null;
        }
    }

    _handleClick(e) {
        const td = e.target.closest('td');
        if (!td || !isCellNavigable(td)) return;

        for (let r = 0; r < this._grid.length; r++) {
            const c = this._grid[r].indexOf(td);
            if (c < 0) continue;
            if (e.ctrlKey || e.metaKey) {
                if (this._selected.has(td)) { td.classList.remove('kg-selected'); this._selected.delete(td); }
                else                         { td.classList.add('kg-selected');    this._selected.add(td); }
            } else {
                this._clearSelection();
                // navMode=false: preserve contentEditable so clicking starts editing immediately
                this._focusCell(r, c, false, false);
            }
            break;
        }
    }

    destroy() {
        for (const [cell, val] of this._navModeEditable) cell.contentEditable = val;
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
