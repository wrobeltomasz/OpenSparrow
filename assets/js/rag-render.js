// assets/js/rag-render.js — shared RAG answer renderer (Markdown + record links).
// Converts an LLM answer into safe HTML. Code regions are extracted before record
// markers are processed, so a [View: table:id] marker inside a code span or fenced
// block stays literal and is never turned into a link.

// HTML-escape a string for safe insertion as text content.
function escHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

// Build the anchor HTML for a validated record marker.
function buildRecordLink(table, id, linkClass) {
    return '<a href="edit.php?table=' + encodeURIComponent(table)
        + '&id=' + encodeURIComponent(id)
        + '" target="_blank" rel="noopener noreferrer" class="' + linkClass + '">'
        + escHtml(table) + ':' + id + '</a>';
}

// Apply inline bold and italic to already-escaped, code-free text.
function inlineFormat(s) {
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    return s;
}

// Render the code-free, marker-free Markdown skeleton into block-level HTML.
// Inline placeholders (\x00I tokens) are restored per line via restoreInline.
function renderBlocks(s, restoreInline) {
    const lines = s.split('\n');
    const out   = [];
    let i = 0;

    while (i < lines.length) {
        const ln = lines[i];

        // A standalone fenced-code placeholder line is emitted as-is for later restore.
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

// Render an LLM answer to safe HTML.
// opts.allowedTables — table names permitted for record links (others are erased).
// opts.linkClass     — CSS class applied to generated record-link anchors.
// opts.markdown      — when false, plain text with links only (no Markdown, no code).
export function renderAnswer(raw, opts = {}) {
    const allowed   = Array.isArray(opts.allowedTables) ? opts.allowedTables : [];
    const linkClass = opts.linkClass || '';
    const markdown  = opts.markdown !== false;

    const blocks = [];
    const inline = [];
    const restoreInline = str => str.replace(/\x00I(\d+)\x00/g, (_, i) => inline[+i]);

    let s = String(raw ?? '');

    if (markdown) {
        // Protect fenced code blocks first so nothing inside is interpreted.
        s = s.replace(/```[\w]*\r?\n?([\s\S]*?)```/g, (_, code) => {
            blocks.push(escHtml(code.trimEnd()));
            return '\x00B' + (blocks.length - 1) + '\x00';
        });
        // Protect inline code spans next.
        s = s.replace(/`([^`\n]+)`/g, (_, code) => {
            inline.push('<code class="rag-md-code">' + escHtml(code) + '</code>');
            return '\x00I' + (inline.length - 1) + '\x00';
        });
    }

    // Convert record markers on the now code-free text. Unknown tables are erased.
    s = s.replace(/\[View:\s*([a-zA-Z0-9_]+):(\d+)\]/g, (_, table, id) => {
        if (!allowed.includes(table)) return '';
        inline.push(buildRecordLink(table, id, linkClass));
        return '\x00I' + (inline.length - 1) + '\x00';
    });

    if (!markdown) {
        return restoreInline(escHtml(s)).replace(/\n/g, '<br>');
    }

    let html = renderBlocks(s, restoreInline);
    html = html.replace(/\x00B(\d+)\x00/g, (_, i) => '<pre class="rag-md-pre"><code>' + blocks[+i] + '</code></pre>');
    return html;
}
