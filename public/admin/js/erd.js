// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getGlobalSchema } from './app.js';

const NS  = 'http://www.w3.org/2000/svg';
const NW  = 195;
const NHD = 36;
const NRH = 21;
const NMC = 9;
const NPD = 8;

export async function renderErdPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;height:calc(100vh - 120px);min-height:480px;';
    workspaceElement.appendChild(wrap);

    const tb = document.createElement('div');
    tb.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-shrink:0;';

    const h2 = document.createElement('h2');
    h2.textContent = 'Schema Map';
    h2.style.cssText = 'margin:0;color:var(--text);';
    tb.appendChild(h2);

    const hint = document.createElement('span');
    hint.textContent = 'Drag canvas to pan · Scroll to zoom · Click table to highlight · Drag table to reposition';
    hint.style.cssText = 'color:var(--muted);';
    tb.appendChild(hint);

    const right = document.createElement('div');
    right.style.cssText = 'margin-left:auto;display:flex;gap:10px;align-items:center;';

    const statisticsElement = document.createElement('span');
    statisticsElement.style.cssText = 'color:var(--muted);';
    right.appendChild(statisticsElement);

    const searchElement = document.createElement('input');
    searchElement.type = 'search';
    searchElement.placeholder = 'Search tables…';
    searchElement.className = 'adm-input w-160';
    right.appendChild(searchElement);

    const hiddenLabel = document.createElement('label');
    hiddenLabel.style.cssText = 'color:var(--muted);display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none;';
    const hiddenCheckbox = document.createElement('input');
    hiddenCheckbox.type = 'checkbox';
    hiddenLabel.appendChild(hiddenCheckbox);
    hiddenLabel.append(' Hidden tables');
    right.appendChild(hiddenLabel);

    const resetButton = document.createElement('button');
    resetButton.textContent = '⤢ Fit View';
    resetButton.className = 'btn btn-secondary btn-sm';
    right.appendChild(resetButton);

    const exportButton = document.createElement('button');
    exportButton.textContent = '↓ PNG';
    exportButton.title = 'Export full diagram as PNG';
    exportButton.className = 'btn btn-secondary btn-sm';
    right.appendChild(exportButton);

    tb.appendChild(right);
    wrap.appendChild(tb);

    const loadElement = document.createElement('p');
    loadElement.textContent = 'Loading schema…';
    loadElement.style.cssText = 'color:var(--muted);';
    wrap.appendChild(loadElement);

    let rawSchema;
    try {
        rawSchema = await getGlobalSchema();
        if (!rawSchema) throw new Error('schema unavailable');
    } catch {
        loadElement.textContent = 'Failed to load schema.';
        return;
    }
    loadElement.remove();

    const container = document.createElement('div');
    container.style.cssText = 'flex:1;position:relative;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--bg);cursor:grab;';
    wrap.appendChild(container);

    startDiagram(container, rawSchema, hiddenCheckbox, resetButton, exportButton, searchElement, statisticsElement);
}

function buildGraph(rawSchema, showHidden) {
    const tables = rawSchema.tables || {};
    const nodes = [], edges = [];

    for (const [name, config] of Object.entries(tables)) {
        if (!showHidden && config.hidden) continue;
        const allColumns = Object.entries(config.columns || {}).filter(([k]) => k !== 'id');
        const shown = allColumns.slice(0, NMC);
        const extra = allColumns.length - shown.length;
        const rows  = Math.max(1, shown.length + (extra > 0 ? 1 : 0));
        nodes.push({
            id:     name,
            label:  config.display_name || name,
            schema: config.schema || 'public',
            hidden: !!config.hidden,
            cols:   shown.map(([k, v]) => ({
                name: k,
                type: v.type || 'text',
                isFk: !!(config.foreign_keys?.[k]),
            })),
            extra,
            w: NW,
            h: NHD + rows * NRH + NPD,
            x: 0, y: 0, vx: 0, vy: 0,
        });
    }

    const nodeSet = new Set(nodes.map(n => n.id));

    for (const [name, config] of Object.entries(tables)) {
        if (!nodeSet.has(name)) continue;
        for (const [column, fk] of Object.entries(config.foreign_keys || {})) {
            const tgt = fk.reference_table;
            if (tgt && nodeSet.has(tgt) && tgt !== name)
                edges.push({ src: name, tgt, type: 'fk', label: column });
        }
        for (const sub of (config.subtables || [])) {
            if (sub.table && nodeSet.has(sub.table))
                edges.push({ src: name, tgt: sub.table, type: 'sub', label: sub.foreign_key || '' });
        }
        for (const m2m of (config.many_to_many || [])) {
            const tgt = m2m.other_table;
            if (tgt && nodeSet.has(tgt) && tgt !== name) {
                const dup = edges.find(e => e.type === 'm2m' &&
                    ((e.src === name && e.tgt === tgt) || (e.src === tgt && e.tgt === name)));
                if (!dup) edges.push({ src: name, tgt, type: 'm2m', label: m2m.label || '' });
            }
        }
    }

    return { nodes, edges };
}

function layoutForce(nodes, edges) {
    if (!nodes.length) return;
    const columns = Math.ceil(Math.sqrt(nodes.length));
    const sx = 290, sy = 310;
    nodes.forEach((n, i) => {
        n.x  = (i % columns) * sx + sx / 2 + (Math.random() - 0.5) * 30;
        n.y  = Math.floor(i / columns) * sy + sy / 2 + (Math.random() - 0.5) * 30;
        n.vx = n.vy = 0;
    });

    const nodeMap = new Map(nodes.map(n => [n.id, n]));
    const REPEL = 14000, SPRING = 320, K = 0.04, DAMP = 0.82;

    for (let it = 0; it < 150; it++) {
        const cool = 1 - it / 150;
        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                const a = nodes[i], b = nodes[j];
                const dx = b.x - a.x, dy = b.y - a.y;
                const d2 = Math.max(1, dx*dx + dy*dy), d = Math.sqrt(d2);
                const f  = REPEL / d2, fx = dx/d*f, fy = dy/d*f;
                a.vx -= fx; a.vy -= fy; b.vx += fx; b.vy += fy;
            }
        }
        for (const e of edges) {
            const a = nodeMap.get(e.src), b = nodeMap.get(e.tgt);
            if (!a || !b) continue;
            const dx = b.x - a.x, dy = b.y - a.y;
            const d  = Math.sqrt(dx*dx + dy*dy) || 1;
            const f  = K * (d - SPRING), fx = dx/d*f, fy = dy/d*f;
            a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
        }
        const maxV = 55 * cool + 4;
        for (const n of nodes) {
            n.vx = Math.max(-maxV, Math.min(maxV, n.vx));
            n.vy = Math.max(-maxV, Math.min(maxV, n.vy));
            n.x += n.vx; n.y += n.vy;
            n.vx *= DAMP; n.vy *= DAMP;
        }
    }
    const mnX = Math.min(...nodes.map(n => n.x - n.w/2));
    const mnY = Math.min(...nodes.map(n => n.y - n.h/2));
    nodes.forEach(n => { n.x -= mnX - 60; n.y -= mnY - 60; });
}

function svgElement(tag, attrs = {}) {
    const e = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    return e;
}

function svgText(content, x, y, o = {}) {
    const t = svgElement('text', {
        x, y,
        'dominant-baseline': o.bl   || 'middle',
        'text-anchor':       o.ta   || 'start',
        'font-size':         o.sz   || 12,
        'font-family':       'system-ui,-apple-system,sans-serif',
        fill:                o.fill || '#6E767F',
        ...(o.weight  ? { 'font-weight': o.weight  } : {}),
        ...(o.opacity ? { opacity:       o.opacity  } : {}),
    });
    const s = String(content), max = o.max || 26;
    t.textContent = s.length > max ? s.slice(0, max - 1) + '…' : s;
    return t;
}

function borderPt(n, tx, ty) {
    const dx = tx - n.x, dy = ty - n.y;
    const hw = n.w/2, hh = n.h/2;
    if (!dx && !dy) return { x: n.x, y: n.y };
    const sx = dx < 0 ? -1 : 1, sy = dy < 0 ? -1 : 1;
    if (!dx) return { x: n.x, y: n.y + sy*hh };
    if (!dy) return { x: n.x + sx*hw, y: n.y };
    const cy = dy * (hw / Math.abs(dx));
    if (Math.abs(cy) <= hh) return { x: n.x + sx*hw, y: n.y + cy };
    return { x: n.x + dx*(hh/Math.abs(dy)), y: n.y + sy*hh };
}

function bezierD(x1, y1, x2, y2) {
    const dx = Math.abs(x2-x1), dy = Math.abs(y2-y1);
    if (dx > dy) {
        const c = dx * 0.45;
        return `M${x1},${y1} C${x1+c},${y1} ${x2-c},${y2} ${x2},${y2}`;
    }
    const c = dy * 0.45, sy = y2 > y1 ? 1 : -1;
    return `M${x1},${y1} C${x1},${y1+c*sy} ${x2},${y2-c*sy} ${x2},${y2}`;
}

function topRoundedRect(x, y, w, h, r) {
    return `M${x+r},${y} H${x+w-r} Q${x+w},${y} ${x+w},${y+r} V${y+h} H${x} V${y+r} Q${x},${y} ${x+r},${y}Z`;
}

const EDGE_STYLE = {
    fk:  { color: '#6E767F', dash: '' },
    sub: { color: '#003366', dash: '7,4' },
    m2m: { color: '#8A9199', dash: '3,5' },
};

const HDR_COLOR = {
    normal: '#1F2A37',
    hidden: '#8A9199',
    sel:    '#003366',
    nbr:    '#1F2A37',
};

function doRender(svg, gE, gN, nodes, edges, selectId, searchTerm) {
    const definitions = svg.querySelector('defs');
    Array.from(definitions.querySelectorAll('clipPath')).forEach(c => c.remove());
    gE.innerHTML = '';
    gN.innerHTML = '';

    const nMap = new Map(nodes.map(n => [n.id, n]));
    let linked = null;
    if (searchTerm) {
        const term = searchTerm.toLowerCase();
        linked = new Set(
            nodes.filter(n =>
                n.id.toLowerCase().includes(term) ||
                n.label.toLowerCase().includes(term)
            ).map(n => n.id)
        );
    } else if (selectId) {
        linked = new Set(edges
            .filter(e => e.src === selectId || e.tgt === selectId)
            .flatMap(e => [e.src, e.tgt]));
    }

    for (const e of edges) {
        const a = nMap.get(e.src), b = nMap.get(e.tgt);
        if (!a || !b) continue;
        const c   = EDGE_STYLE[e.type];
        const dim = linked && !linked.has(e.src) && !linked.has(e.tgt);
        const p1  = borderPt(a, b.x, b.y), p2 = borderPt(b, a.x, a.y);

        gE.appendChild(svgElement('path', {
            d: bezierD(p1.x, p1.y, p2.x, p2.y),
            stroke: c.color, 'stroke-width': dim ? 1 : 2,
            fill: 'none', opacity: dim ? 0.1 : 0.85,
            ...(c.dash ? { 'stroke-dasharray': c.dash } : {}),
            'marker-end': `url(#mk-${e.type})`,
        }));

        if (e.label && !dim) {
            const mx = (p1.x+p2.x)/2, my = (p1.y+p2.y)/2;
            gE.appendChild(svgElement('rect', { x:mx-28, y:my-8, width:56, height:15, rx:3, fill:'#fff', opacity:0.88 }));
            gE.appendChild(svgText(e.label, mx, my+1, { ta:'middle', sz:10, fill:c.color, max:16 }));
        }
    }

    for (const n of nodes) {
        const dim   = linked && !linked.has(n.id);
        const isSelect = n.id === selectId;
        const isNbr = linked && !isSelect && linked.has(n.id);
        const hdrC  = isSelect ? HDR_COLOR.sel : isNbr ? HDR_COLOR.nbr
                    : n.hidden ? HDR_COLOR.hidden : HDR_COLOR.normal;
        const x = n.x - n.w/2, y = n.y - n.h/2;

        const g = svgElement('g', { 'data-id': n.id, opacity: dim ? 0.2 : 1 });
        g.style.cursor = 'pointer';

        g.appendChild(svgElement('rect', { x:x+3, y:y+3, width:n.w, height:n.h, rx:7, fill:'#00000016' }));

        g.appendChild(svgElement('rect', { x, y, width:n.w, height:n.h, rx:7,
            fill: isSelect ? '#F1F4F8' : '#fff',
            stroke: isSelect ? '#003366' : isNbr ? '#1F2A37' : '#D0DAE6',
            'stroke-width': (isSelect || isNbr) ? 2 : 1,
        }));

        g.appendChild(svgElement('path', { d: topRoundedRect(x, y, n.w, NHD, 7), fill: hdrC }));

        g.appendChild(svgText(n.label, x+n.w/2, y+NHD/2+1,
            { ta:'middle', fill:'#fff', weight:'600', sz:13, max:22 }));

        if (n.schema !== 'public') {
            g.appendChild(svgText(n.schema, x+n.w-7, y+NHD/2+1,
                { ta:'end', fill:'rgba(255,255,255,.5)', sz:9, max:12 }));
        }

        g.appendChild(svgElement('line', { x1:x, y1:y+NHD, x2:x+n.w, y2:y+NHD, stroke:'#D0DAE6', 'stroke-width':1 }));

        n.cols.forEach((column, ci) => {
            const cy = y + NHD + ci*NRH + NRH/2 + 2;
            if (column.isFk) g.appendChild(svgText('→', x+6, cy, { fill:'#6E767F', sz:10 }));
            g.appendChild(svgText(column.name, column.isFk ? x+18 : x+8, cy,
                { fill:'#1F2A37', sz:11, max:17 }));
            g.appendChild(svgText(column.type, x+n.w-7, cy,
                { ta:'end', fill:'#6E767F', sz:10, max:10 }));
        });

        if (n.extra > 0) {
            const cy = y + NHD + n.cols.length*NRH + NRH/2 + 2;
            g.appendChild(svgText(`+ ${n.extra} more`, x+n.w/2, cy,
                { ta:'middle', fill:'#6E767F', sz:10 }));
        }

        gN.appendChild(g);
    }
}

function startDiagram(container, rawSchema, hiddenCheckbox, resetButton, exportButton, searchElement, statisticsElement) {
    const svg = document.createElementNS(NS, 'svg');
    svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;';
    container.appendChild(svg);

    const definitions = svgElement('defs');
    for (const [type, c] of Object.entries(EDGE_STYLE)) {
        const mk   = svgElement('marker', { id:`mk-${type}`, markerWidth:8, markerHeight:8, refX:7, refY:3, orient:'auto' });
        const poly = svgElement('polygon', { points:'0 0,8 3,0 6', fill:c.color });
        mk.appendChild(poly); definitions.appendChild(mk);
    }
    svg.appendChild(definitions);

    const gAll = svgElement('g'), gE = svgElement('g'), gN = svgElement('g');
    gAll.appendChild(gE); gAll.appendChild(gN); svg.appendChild(gAll);

    const leg = document.createElement('div');
    leg.style.cssText = 'position:absolute;bottom:12px;left:12px;background:rgba(255,255,255,.95);border:1px solid var(--border);border-radius:6px;padding:8px 12px;line-height:2;pointer-events:none;z-index:5;';
    leg.innerHTML = [
        '<b style="color:var(--muted);display:block;margin-bottom:2px;">Legend</b>',
        '<div><span style="display:inline-block;width:22px;height:2px;background:#6E767F;vertical-align:middle;margin-right:6px;"></span>Foreign key</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dashed #003366;vertical-align:middle;margin-right:6px;"></span>Subtable</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dotted #8A9199;vertical-align:middle;margin-right:6px;"></span>Many-to-many</div>',
    ].join('');
    container.appendChild(leg);

    let pan = { x: 40, y: 40 }, zoom = 1;
    let selectId = null, searchTerm = '', nodes = [], edges = [];
    let panning = false, panStart = null, panOrig: panOriginal = null;
    let dragId = null, dragMoved = false, dragClient = null;

    const applyXform = () =>
        gAll.setAttribute('transform', `translate(${pan.x},${pan.y}) scale(${zoom})`);

    function render() {
        doRender(svg, gE, gN, nodes, edges, selectId, searchTerm);
        gN.querySelectorAll('[data-id]').forEach(g => {
            const nid = g.getAttribute('data-id');
            g.addEventListener('mousedown', ev => {
                ev.stopPropagation();
                dragId     = nid;
                dragMoved  = false;
                dragClient = { x: ev.clientX, y: ev.clientY };
            });
        });
    }

    function fitView() {
        if (!nodes.length) return;
        const mnX = Math.min(...nodes.map(n => n.x - n.w/2));
        const mnY = Math.min(...nodes.map(n => n.y - n.h/2));
        const mxX = Math.max(...nodes.map(n => n.x + n.w/2));
        const mxY = Math.max(...nodes.map(n => n.y + n.h/2));
        const cw = container.clientWidth, ch = container.clientHeight;
        const gw = mxX - mnX + 100, gh = mxY - mnY + 100;
        zoom  = Math.min(1.2, Math.min(cw/gw, ch/gh));
        pan.x = (cw - gw*zoom)/2 - mnX*zoom + 50*zoom;
        pan.y = (ch - gh*zoom)/2 - mnY*zoom + 50*zoom;
        applyXform();
    }

    function rebuild() {
        ({ nodes, edges } = buildGraph(rawSchema, hiddenCheckbox.checked));
        selectId = null;
        layoutForce(nodes, edges);
        const fk  = edges.filter(e => e.type==='fk').length;
        const sub = edges.filter(e => e.type==='sub').length;
        const m2m = edges.filter(e => e.type==='m2m').length;
        statisticsElement.textContent = `${nodes.length} tables · ${fk} FK · ${sub} subtable · ${m2m} M2M`;
        render();
        fitView();
    }

    container.addEventListener('mousedown', ev => {
        if (dragId) return;
        panning  = true;
        panStart = { x: ev.clientX, y: ev.clientY };
        panOriginal  = { ...pan };
        container.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', ev => {
        if (dragId) {
            if (dragClient) {
                const ddx = ev.clientX - dragClient.x, ddy = ev.clientY - dragClient.y;
                if (ddx*ddx + ddy*ddy > 16) dragMoved = true;
            }
            if (dragMoved) {
                const node = nodes.find(n => n.id === dragId);
                if (node) { node.x += ev.movementX/zoom; node.y += ev.movementY/zoom; render(); }
            }
            return;
        }
        if (!panning) return;
        pan.x = panOriginal.x + (ev.clientX - panStart.x);
        pan.y = panOriginal.y + (ev.clientY - panStart.y);
        applyXform();
    });

    window.addEventListener('mouseup', () => {
        if (dragId && !dragMoved) {
            selId: selectId = selectId === dragId ? null : dragId;
            render();
        }
        dragId = null; dragMoved = false; dragClient = null;
        panning = false;
        container.style.cursor = 'grab';
    });

    container.addEventListener('wheel', ev => {
        ev.preventDefault();
        const rect = container.getBoundingClientRect();
        const mx   = ev.clientX - rect.left, my = ev.clientY - rect.top;
        const nz   = Math.max(0.12, Math.min(3, zoom * (ev.deltaY > 0 ? 0.88 : 1.14)));
        pan.x = mx - (mx - pan.x) * nz / zoom;
        pan.y = my - (my - pan.y) * nz / zoom;
        zoom = nz;
        applyXform();
    }, { passive: false });

    resetButton.addEventListener('click', fitView);
    hiddenCheckbox.addEventListener('change', rebuild);

    searchElement.addEventListener('input', () => {
        searchTerm = searchElement.value.trim();
        selectId = null;
        render();
    });

    exportButton.addEventListener('click', () => exportPng(svg, nodes));

    rebuild();
}

function exportPng(svg, nodes) {
    if (!nodes.length) return;

    const pad = 50;
    const mnX = Math.min(...nodes.map(n => n.x - n.w/2)) - pad;
    const mnY = Math.min(...nodes.map(n => n.y - n.h/2)) - pad;
    const mxX = Math.max(...nodes.map(n => n.x + n.w/2)) + pad;
    const mxY = Math.max(...nodes.map(n => n.y + n.h/2)) + pad;
    const vw = mxX - mnX, vh = mxY - mnY;

    const clone = svg.cloneNode(true);
    clone.setAttribute('width',   String(vw));
    clone.setAttribute('height',  String(vh));
    clone.setAttribute('viewBox', `${mnX} ${mnY} ${vw} ${vh}`);
    const gAllClone = clone.querySelector('g');
    if (gAllClone) gAllClone.removeAttribute('transform');

    const background = document.createElementNS(NS, 'rect');
    background.setAttribute('x', String(mnX)); background.setAttribute('y', String(mnY));
    background.setAttribute('width', String(vw)); background.setAttribute('height', String(vh));
    background.setAttribute('fill', '#D0DAE6');
    if (gAllClone) gAllClone.prepend(background);

    const svgString  = new XMLSerializer().serializeToString(clone);
    const svgBlob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' });
    const url     = URL.createObjectURL(svgBlob);

    const image = new Image();
    image.onload = () => {
        const scale  = Math.min(2, 4000 / Math.max(vw, vh));
        const canvas = document.createElement('canvas');
        canvas.width  = Math.round(vw * scale);
        canvas.height = Math.round(vh * scale);
        const c2 = canvas.getContext('2d');
        c2.fillStyle = '#D0DAE6';
        c2.fillRect(0, 0, canvas.width, canvas.height);
        c2.scale(scale, scale);
        c2.drawImage(image, 0, 0);
        URL.revokeObjectURL(url);
        canvas.toBlob(blob => {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'schema-map.png';
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        }, 'image/png');
    };
    image.onerror = () => URL.revokeObjectURL(url);
    image.src = url;
}
