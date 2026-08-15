// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { escHtml } from './util/esc.js';

function buildRecordLink(table, id, linkClass) {
    return '<a href="edit.php?table=' + encodeURIComponent(table)
        + '&id=' + encodeURIComponent(id)
        + '" target="_blank" rel="noopener noreferrer" class="' + linkClass + '">'
        + escHtml(table) + ':' + id + '</a>';
}

function inlineFormat(s) {
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    return s;
}

function renderBlocks(s, restoreInline) {
    const lines = s.split('\n');
    const out   = [];
    let i = 0;

    while (i < lines.length) {
        const ln = lines[i];

        if (/^\x00B(\d+)\x00$/.test(ln)) { out.push(ln); i++; continue; }

        const hm = ln.match(/^(#{1,3}) (.+)/);
        if (hm) {
            const tag = ['h3', 'h4', 'h5'][hm[1].length - 1];
            out.push('<' + tag + ' class="rag-md-h">' + restoreInline(inlineFormat(escHtml(hm[2]))) + '</' + tag + '>');
            i++; continue;
        }

        if (/^[-*] /.test(ln)) {
            const items = [];
            while (i < lines.length && /^[-*] /.test(lines[i]))
                items.push('<li>' + restoreInline(inlineFormat(escHtml(lines[i++].replace(/^[-*] /, '')))) + '</li>');
            out.push('<ul class="rag-md-list">' + items.join('') + '</ul>');
            continue;
        }

        if (/^\d+\. /.test(ln)) {
            const items = [];
            while (i < lines.length && /^\d+\. /.test(lines[i]))
                items.push('<li>' + restoreInline(inlineFormat(escHtml(lines[i++].replace(/^\d+\. /, '')))) + '</li>');
            out.push('<ol class="rag-md-list">' + items.join('') + '</ol>');
            continue;
        }

        if (ln.trim() === '') { i++; continue; }

        const para = [];
        while (
            i < lines.length &&
            lines[i].trim() !== '' &&
            !/^[-*] /.test(lines[i]) &&
            !/^\d+\. /.test(lines[i]) &&
            !/^\x00B/.test(lines[i]) &&
            !/^#{1,3} /.test(lines[i])
        ) {
            para.push(restoreInline(inlineFormat(escHtml(lines[i]))));
            i++;
        }
        if (para.length) out.push('<p class="rag-md-p">' + para.join('<br>') + '</p>');
    }

    return out.join('');
}

export function renderAnswer(raw, opts = {}) {
    const allowed   = Array.isArray(opts.allowedTables) ? opts.allowedTables : [];
    const linkClass = opts.linkClass || '';
    const markdown  = opts.markdown !== false;

    const blocks = [];
    const inline = [];
    const restoreInline = str => str.replace(/\x00I(\d+)\x00/g, (_, i) => inline[+i]);

    let s = String(raw ?? '');

    if (markdown) {
        s = s.replace(/```[\w]*\r?\n?([\s\S]*?)```/g, (_, code) => {
            blocks.push(escHtml(code.trimEnd()));
            return '\x00B' + (blocks.length - 1) + '\x00';
        });

        s = s.replace(/`([^`\n]+)`/g, (_, code) => {
            inline.push('<code class="rag-md-code">' + escHtml(code) + '</code>');
            return '\x00I' + (inline.length - 1) + '\x00';
        });
    }

    s = s.replace(/\[View:\s*([a-zA-Z0-9_]+):(\d+)\]/g, (_, table, id) => {
        if (!allowed.includes(table)) return '';
        inline.push(buildRecordLink(table, id, linkClass));
        return '\x00I' + (inline.length - 1) + '\x00';
    });

    s = s.replace(/\s*\[View:[^\]]*\]/g, '');

    if (!markdown) {
        return restoreInline(escHtml(s)).replace(/\n/g, '<br>');
    }

    let html = renderBlocks(s, restoreInline);
    html = html.replace(/\x00B(\d+)\x00/g, (_, i) => '<pre class="rag-md-pre"><code>' + blocks[+i] + '</code></pre>');
    return html;
}
