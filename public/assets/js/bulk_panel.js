// assets/js/bulk_panel.js — Reusable slide-in drawer (BulkPanel class) for bulk operations; CSS prefix bp-. Used by grid mass-edit, owner and export panels.

/**
 * BulkPanel — universal reusable slide-in drawer panel for bulk operations.
 *
 * Usage:
 *   const panel = new BulkPanel({ id, title, applyLabel });
 *   panel.onApply(handler);   // handler receives (panelInstance)
 *   panel.onClose(handler);   // optional
 *   panel.open();
 *   panel.bodyEl              // populate with your form fields
 *   panel.setStatus(msg, isError);
 *   panel.setApplyDisabled(bool);
 *   panel.close();
 *
 * CSS prefix: bp-
 */

export class BulkPanel {
    constructor({ id, title, applyLabel = 'Apply' }) {
        this._id          = id;
        this._title       = title;
        this._applyLabel  = applyLabel;
        this._panelEl     = null;
        this._overlayEl   = null;
        this._bodyEl      = null;
        this._statusEl    = null;
        this._applyBtn    = null;
        this._applyHandler = null;
        this._closeHandler = null;
    }

    get bodyEl() {
        if (!this._panelEl) this._createDOM();
        return this._bodyEl;
    }

    open() {
        if (!this._panelEl) this._createDOM();
        this._panelEl.classList.add('active');
        this._overlayEl.classList.add('active');
    }

    close() {
        this._panelEl?.classList.remove('active');
        this._overlayEl?.classList.remove('active');
    }

    isOpen() {
        return this._panelEl?.classList.contains('active') ?? false;
    }

    setApplyDisabled(disabled) {
        if (this._applyBtn) this._applyBtn.disabled = disabled;
    }

    setStatus(msg, isError = false) {
        if (!this._statusEl) return;
        this._statusEl.textContent = msg;
        this._statusEl.className = 'bp-status' + (isError ? ' error' : '');
    }

    clearStatus() {
        this.setStatus('');
    }

    onApply(handler) {
        this._applyHandler = handler;
        if (this._applyBtn) {
            this._applyBtn.onclick = () => handler(this);
        }
    }

    onClose(handler) {
        this._closeHandler = handler;
    }

    _createDOM() {
        this._overlayEl = document.createElement('div');
        this._overlayEl.className = 'bp-overlay';
        document.body.appendChild(this._overlayEl);
        this._overlayEl.addEventListener('click', () => this._handleClose());

        this._panelEl = document.createElement('div');
        this._panelEl.className = 'bp-panel';
        this._panelEl.id = this._id;

        const header = document.createElement('div');
        header.className = 'bp-header';

        const titleEl = document.createElement('h3');
        titleEl.className = 'bp-title';
        titleEl.textContent = this._title;

        const closeBtn = document.createElement('button');
        closeBtn.className = 'bp-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.title = 'Close';
        closeBtn.innerHTML = '&#x2715;';
        closeBtn.addEventListener('click', () => this._handleClose());

        header.appendChild(titleEl);
        header.appendChild(closeBtn);

        this._bodyEl = document.createElement('div');
        this._bodyEl.className = 'bp-body';

        this._statusEl = document.createElement('div');
        this._statusEl.className = 'bp-status';

        const footer = document.createElement('div');
        footer.className = 'bp-footer';

        this._applyBtn = document.createElement('button');
        this._applyBtn.className = 'bp-apply-btn';
        this._applyBtn.textContent = this._applyLabel;
        this._applyBtn.disabled = true;
        this._applyBtn.onclick = () => {
            if (this._applyHandler) this._applyHandler(this);
        };
        footer.appendChild(this._applyBtn);

        this._panelEl.appendChild(header);
        this._panelEl.appendChild(this._bodyEl);
        this._panelEl.appendChild(this._statusEl);
        this._panelEl.appendChild(footer);

        document.body.appendChild(this._panelEl);
    }

    _handleClose() {
        this.close();
        if (this._closeHandler) this._closeHandler();
    }
}
