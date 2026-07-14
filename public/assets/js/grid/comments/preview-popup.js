// assets/js/grid/comments/preview-popup.js — Hover popup showing a row's latest comments (cached); fetched via api.js, avatars via renderAvatar.

import { renderAvatar } from '../../avatar.js';
import { fetchCommentPreview } from '../api.js';
import { state } from '../state.js';
import { I18n } from '../../i18n.js';
import { createHoverPopup } from '../hover-popup.js';

const previewCache = new Map();
let popup = null;

export function clearPreviewCache() {
    previewCache.clear();
}

export function initPreviewPopup() {
    popup = createHoverPopup({ className: 'c-preview-popup', width: 360, verticalThreshold: 180 });

    document.addEventListener('mouseover', async e => {
        const badge = e.target.closest('.c-count-badge[data-row-id]');
        if (!badge) return;

        popup.el.replaceChildren(makeParagraph('c-preview-loading', 'Loading…'));
        popup.show(badge);

        const rowId = badge.dataset.rowId;
        const cacheKey = `${state.currentTable}:${rowId}`;

        if (!previewCache.has(cacheKey)) {
            try {
                const comments = await fetchCommentPreview(state.currentTable, rowId);
                previewCache.set(cacheKey, comments);
            } catch {
                // Do not cache on error — next hover will retry
                if (!popup.el.hidden) renderContent([]);
                return;
            }
        }

        if (!popup.el.hidden) renderContent(previewCache.get(cacheKey) ?? []);
    });

    document.addEventListener('mouseout', e => {
        if (!e.target.closest('.c-count-badge[data-row-id]')) return;
        popup.scheduleHide();
    });
}

function renderContent(comments) {
    popup.el.replaceChildren();
    const title = document.createElement('div');
    title.className = 'c-preview-title';
    title.textContent = I18n.t('grid.recent_comments');
    popup.el.appendChild(title);

    const visible = comments.filter(c => !c.deleted_at);
    if (visible.length === 0) {
        popup.el.appendChild(makeParagraph('c-preview-empty', I18n.t('grid.no_comments')));
        return;
    }

    for (const c of visible) {
        const item = document.createElement('div');
        item.className = 'c-preview-item';
        item.appendChild(renderAvatar(c.avatar_id ? parseInt(c.avatar_id, 10) : null, c.username ?? '?', 24));

        const content = document.createElement('div');
        content.className = 'c-preview-item-content';

        const meta = document.createElement('div');
        meta.className = 'c-preview-meta';
        const author = document.createElement('strong');
        author.textContent = c.username ?? 'Unknown';
        const time = document.createElement('span');
        time.className = 'c-preview-time';
        time.textContent = new Date(c.created_at || '').toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
        meta.append(author, time);

        const body = document.createElement('p');
        body.className = 'c-preview-body';
        const raw = (c.body ?? '').replace(/\s+/g, ' ');
        body.textContent = raw.length > 90 ? raw.slice(0, 90) + '…' : raw;

        content.append(meta, body);
        item.appendChild(content);
        popup.el.appendChild(item);
    }
}

function makeParagraph(className, text) {
    const p = document.createElement('p');
    p.className = className;
    p.textContent = text;
    return p;
}
