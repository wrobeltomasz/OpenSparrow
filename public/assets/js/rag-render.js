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
    const htmlParts   = [];
    let lineIndex = 0;

    while (lineIndex < lines.length) {
        const line = lines[lineIndex];

        if (/^\x00B(\d+)\x00$/.test(line)) { htmlParts.push(line); lineIndex++; continue; }

        const headingMatch = line.match(/^(#{1,3}) (.+)/);
        if (headingMatch) {
            const tag = ['h3', 'h4', 'h5'][headingMatch[1].length - 1];
            htmlParts.push('<' + tag + ' class="rag-md-h">' + restoreInline(inlineFormat(escHtml(headingMatch[2]))) + '</' + tag + '>');
            lineIndex++; continue;
        }

        if (/^[-*] /.test(line)) {
            const items = [];
            while (lineIndex < lines.length && /^[-*] /.test(lines[lineIndex]))
                items.push('<li>' + restoreInline(inlineFormat(escHtml(lines[lineIndex++].replace(/^[-*] /, '')))) + '</li>');
            htmlParts.push('<ul class="rag-md-list">' + items.join('') + '</ul>');
            continue;
        }

        if (/^\d+\. /.test(line)) {
            const items = [];
            while (lineIndex < lines.length && /^\d+\. /.test(lines[lineIndex]))
                items.push('<li>' + restoreInline(inlineFormat(escHtml(lines[lineIndex++].replace(/^\d+\. /, '')))) + '</li>');
            htmlParts.push('<ol class="rag-md-list">' + items.join('') + '</ol>');
            continue;
        }

        if (line.trim() === '') { lineIndex++; continue; }

        const paragraph = [];
        while (
            lineIndex < lines.length &&
            lines[lineIndex].trim() !== '' &&
            !/^[-*] /.test(lines[lineIndex]) &&
            !/^\d+\. /.test(lines[lineIndex]) &&
            !/^\x00B/.test(lines[lineIndex]) &&
            !/^#{1,3} /.test(lines[lineIndex])
        ) {
            paragraph.push(restoreInline(inlineFormat(escHtml(lines[lineIndex]))));
            lineIndex++;
        }
        if (paragraph.length) htmlParts.push('<p class="rag-md-p">' + paragraph.join('<br>') + '</p>');
    }

    return htmlParts.join('');
}

export function renderAnswer(raw, options = {}) {
    const allowed   = Array.isArray(options.allowedTables) ? options.allowedTables : [];
    const linkClass = options.linkClass || '';
    const markdown  = options.markdown !== false;

    const blocks = [];
    const inline = [];
    const restoreInline = string => string.replace(/\x00I(\d+)\x00/g, (_, lineIndex) => inline[+lineIndex]);

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
    html = html.replace(/\x00B(\d+)\x00/g, (_, lineIndex) => '<pre class="rag-md-pre"><code>' + blocks[+lineIndex] + '</code></pre>');
    return html;
}
