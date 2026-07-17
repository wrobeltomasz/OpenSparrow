// admin/js/erd.js — Schema Map: renders an SVG ERD of FK / subtable / many-to-many relationships from the "schema" config. Layout constants below (node width/height, row height, etc.).

const NS  = 'http://www.w3.org/2000/svg';
const NW  = 195;   // node width
const NHD = 36;    // header height
const NRH = 21;    // column row height
const NMC = 9;     // max columns shown
const NPD = 8;     // bottom padding inside node

// ── entry ─────────────────────────────────────────────────────────────────────

export async function renderErdPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;height:calc(100vh - 120px);min-height:480px;';
    workspaceEl.appendChild(wrap);

    const tb = document.createElement('div');
    tb.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-shrink:0;';

    const h2 = document.createElement('h2');
    h2.textContent = 'Schema Map';
    h2.style.cssText = 'margin:0;font-size:18px;color:#1E293B;';
    tb.appendChild(h2);

    const hint = document.createElement('span');
    hint.textContent = 'Drag canvas to pan · Scroll to zoom · Click table to highlight · Drag table to reposition';
    hint.style.cssText = 'font-size:11px;color:#64748B;';
    tb.appendChild(hint);

    const right = document.createElement('div');
    right.style.cssText = 'margin-left:auto;display:flex;gap:10px;align-items:center;';

    const statsEl = document.createElement('span');
    statsEl.style.cssText = 'font-size:12px;color:#64748B;';
    right.appendChild(statsEl);

    const searchEl = document.createElement('input');
    searchEl.type = 'search';
    searchEl.placeholder = 'Search tables…';
    searchEl.className = 'adm-input w-160';
    right.appendChild(searchEl);

    const hiddenLbl = document.createElement('label');
    hiddenLbl.style.cssText = 'font-size:12px;color:#64748B;display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none;';
    const hiddenChk = document.createElement('input');
    hiddenChk.type = 'checkbox';
    hiddenLbl.appendChild(hiddenChk);
    hiddenLbl.append(' Hidden tables');
    right.appendChild(hiddenLbl);

    const resetBtn = document.createElement('button');
    resetBtn.textContent = '⤢ Fit View';
    resetBtn.className = 'btn btn-secondary btn-sm';
    right.appendChild(resetBtn);

    const exportBtn = document.createElement('button');
    exportBtn.textContent = '↓ PNG';
    exportBtn.title = 'Export full diagram as PNG';
    exportBtn.className = 'btn btn-secondary btn-sm';
    right.appendChild(exportBtn);

    tb.appendChild(right);
    wrap.appendChild(tb);

    const loadEl = document.createElement('p');
    loadEl.textContent = 'Loading schema…';
    loadEl.style.cssText = 'color:#64748B;font-size:14px;';
    wrap.appendChild(loadEl);

    let rawSchema;
    try {
        const r = await fetch('api.php?action=get&file=schema');
        rawSchema = await r.json();
    } catch {
        loadEl.textContent = 'Failed to load schema.';
        return;
    }
    loadEl.remove();

    const container = document.createElement('div');
    container.style.cssText = 'flex:1;position:relative;border:1px solid #CBD5E1;border-radius:8px;overflow:hidden;background:#F4F7F9;cursor:grab;';
    wrap.appendChild(container);

    startDiagram(container, rawSchema, hiddenChk, resetBtn, exportBtn, searchEl, statsEl);
}

// ── graph ─────────────────────────────────────────────────────────────────────

function buildGraph(rawSchema, showHidden) {
    const tables = rawSchema.tables || {};
    const nodes = [], edges = [];

    for (const [name, cfg] of Object.entries(tables)) {
        if (!showHidden && cfg.hidden) continue;
        const allCols = Object.entries(cfg.columns || {}).filter(([k]) => k !== 'id');
        const shown = allCols.slice(0, NMC);
        const extra = allCols.length - shown.length;
        const rows  = Math.max(1, shown.length + (extra > 0 ? 1 : 0));
        nodes.push({
            id:     name,
            label:  cfg.display_name || name,
            schema: cfg.schema || 'public',
            hidden: !!cfg.hidden,
            cols:   shown.map(([k, v]) => ({
                name: k,
                type: v.type || 'text',
                isFk: !!(cfg.foreign_keys?.[k]),
            })),
            extra,
            w: NW,
            h: NHD + rows * NRH + NPD,
            x: 0, y: 0, vx: 0, vy: 0,
        });
    }

    const nodeSet = new Set(nodes.map(n => n.id));

    for (const [name, cfg] of Object.entries(tables)) {
        if (!nodeSet.has(name)) continue;
        for (const [col, fk] of Object.entries(cfg.foreign_keys || {})) {
            const tgt = fk.reference_table;
            if (tgt && nodeSet.has(tgt) && tgt !== name)
                edges.push({ src: name, tgt, type: 'fk', label: col });
        }
        for (const sub of (cfg.subtables || [])) {
            if (sub.table && nodeSet.has(sub.table))
                edges.push({ src: name, tgt: sub.table, type: 'sub', label: sub.foreign_key || '' });
        }
        for (const m2m of (cfg.many_to_many || [])) {
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

// ── force layout ──────────────────────────────────────────────────────────────

function layoutForce(nodes, edges) {
    if (!nodes.length) return;
    const cols = Math.ceil(Math.sqrt(nodes.length));
    const sx = 290, sy = 310;
    nodes.forEach((n, i) => {
        n.x  = (i % cols) * sx + sx / 2 + (Math.random() - 0.5) * 30;
        n.y  = Math.floor(i / cols) * sy + sy / 2 + (Math.random() - 0.5) * 30;
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

// ── SVG helpers ───────────────────────────────────────────────────────────────

function svgEl(tag, attrs = {}) {
    const e = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    return e;
}

function svgTxt(content, x, y, o = {}) {
    const t = svgEl('text', {
        x, y,
        'dominant-baseline': o.bl   || 'middle',
        'text-anchor':       o.ta   || 'start',
        'font-size':         o.sz   || 12,
        'font-family':       'system-ui,-apple-system,sans-serif',
        fill:                o.fill || '#64748B',
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

// SVG path for a rect with only top corners rounded
function topRoundedRect(x, y, w, h, r) {
    return `M${x+r},${y} H${x+w-r} Q${x+w},${y} ${x+w},${y+r} V${y+h} H${x} V${y+r} Q${x},${y} ${x+r},${y}Z`;
}

// ── rendering ─────────────────────────────────────────────────────────────────

const EDGE_STYLE = {
    fk:  { color: '#64748B', dash: '' },
    sub: { color: '#2b9348', dash: '7,4' },
    m2m: { color: '#64748B', dash: '3,5' },
};

const HDR_COLOR = {
    normal: '#1E293B',
    hidden: '#64748B',
    sel:    '#ffc300',
    nbr:    '#1E293B',
};

function doRender(svg, gE, gN, nodes, edges, selId, searchTerm) {
    const defs = svg.querySelector('defs');
    Array.from(defs.querySelectorAll('clipPath')).forEach(c => c.remove());
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
    } else if (selId) {
        linked = new Set(edges
            .filter(e => e.src === selId || e.tgt === selId)
            .flatMap(e => [e.src, e.tgt]));
    }

    // ── edges ──
    for (const e of edges) {
        const a = nMap.get(e.src), b = nMap.get(e.tgt);
        if (!a || !b) continue;
        const c   = EDGE_STYLE[e.type];
        const dim = linked && !linked.has(e.src) && !linked.has(e.tgt);
        const p1  = borderPt(a, b.x, b.y), p2 = borderPt(b, a.x, a.y);

        gE.appendChild(svgEl('path', {
            d: bezierD(p1.x, p1.y, p2.x, p2.y),
            stroke: c.color, 'stroke-width': dim ? 1 : 2,
            fill: 'none', opacity: dim ? 0.1 : 0.85,
            ...(c.dash ? { 'stroke-dasharray': c.dash } : {}),
            'marker-end': `url(#mk-${e.type})`,
        }));

        if (e.label && !dim) {
            const mx = (p1.x+p2.x)/2, my = (p1.y+p2.y)/2;
            gE.appendChild(svgEl('rect', { x:mx-28, y:my-8, width:56, height:15, rx:3, fill:'#fff', opacity:0.88 }));
            gE.appendChild(svgTxt(e.label, mx, my+1, { ta:'middle', sz:10, fill:c.color, max:16 }));
        }
    }

    // ── nodes ──
    for (const n of nodes) {
        const dim   = linked && !linked.has(n.id);
        const isSel = n.id === selId;
        const isNbr = linked && !isSel && linked.has(n.id);
        const hdrC  = isSel ? HDR_COLOR.sel : isNbr ? HDR_COLOR.nbr
                    : n.hidden ? HDR_COLOR.hidden : HDR_COLOR.normal;
        const x = n.x - n.w/2, y = n.y - n.h/2;

        const g = svgEl('g', { 'data-id': n.id, opacity: dim ? 0.2 : 1 });
        g.style.cursor = 'pointer';

        // Drop shadow
        g.appendChild(svgEl('rect', { x:x+3, y:y+3, width:n.w, height:n.h, rx:7, fill:'#00000016' }));

        // Body
        g.appendChild(svgEl('rect', { x, y, width:n.w, height:n.h, rx:7, fill:'#fff',
            stroke: isSel ? '#ffc300' : isNbr ? '#1E293B' : '#DDEAF4',
            'stroke-width': (isSel || isNbr) ? 2 : 1,
        }));

        // Header (top-rounded only)
        g.appendChild(svgEl('path', { d: topRoundedRect(x, y, n.w, NHD, 7), fill: hdrC }));

        // Table name
        g.appendChild(svgTxt(n.label, x+n.w/2, y+NHD/2+1,
            { ta:'middle', fill:'#fff', weight:'600', sz:13, max:22 }));

        // Schema badge (non-default schemas)
        if (n.schema !== 'public') {
            g.appendChild(svgTxt(n.schema, x+n.w-7, y+NHD/2+1,
                { ta:'end', fill:'rgba(255,255,255,.5)', sz:9, max:12 }));
        }

        // Separator
        g.appendChild(svgEl('line', { x1:x, y1:y+NHD, x2:x+n.w, y2:y+NHD, stroke:'#DDEAF4', 'stroke-width':1 }));

        // Columns
        n.cols.forEach((col, ci) => {
            const cy = y + NHD + ci*NRH + NRH/2 + 2;
            if (col.isFk) g.appendChild(svgTxt('→', x+6, cy, { fill:'#64748B', sz:10 }));
            g.appendChild(svgTxt(col.name, col.isFk ? x+18 : x+8, cy,
                { fill:'#1E293B', sz:11, max:17 }));
            g.appendChild(svgTxt(col.type, x+n.w-7, cy,
                { ta:'end', fill:'#64748B', sz:10, max:10 }));
        });

        if (n.extra > 0) {
            const cy = y + NHD + n.cols.length*NRH + NRH/2 + 2;
            g.appendChild(svgTxt(`+ ${n.extra} more`, x+n.w/2, cy,
                { ta:'middle', fill:'#64748B', sz:10 }));
        }

        gN.appendChild(g);
    }
}

// ── pan / zoom / drag ─────────────────────────────────────────────────────────

function startDiagram(container, rawSchema, hiddenChk, resetBtn, exportBtn, searchEl, statsEl) {
    const svg = document.createElementNS(NS, 'svg');
    svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;';
    container.appendChild(svg);

    // Arrow markers
    const defs = svgEl('defs');
    for (const [type, c] of Object.entries(EDGE_STYLE)) {
        const mk   = svgEl('marker', { id:`mk-${type}`, markerWidth:8, markerHeight:8, refX:7, refY:3, orient:'auto' });
        const poly = svgEl('polygon', { points:'0 0,8 3,0 6', fill:c.color });
        mk.appendChild(poly); defs.appendChild(mk);
    }
    svg.appendChild(defs);

    const gAll = svgEl('g'), gE = svgEl('g'), gN = svgEl('g');
    gAll.appendChild(gE); gAll.appendChild(gN); svg.appendChild(gAll);

    // Legend overlay
    const leg = document.createElement('div');
    leg.style.cssText = 'position:absolute;bottom:12px;left:12px;background:rgba(255,255,255,.95);border:1px solid #CBD5E1;border-radius:6px;padding:8px 12px;font-size:11px;line-height:2;pointer-events:none;z-index:5;';
    leg.innerHTML = [
        '<b style="font-size:12px;color:#64748B;display:block;margin-bottom:2px;">Legend</b>',
        '<div><span style="display:inline-block;width:22px;height:2px;background:#005A9E;vertical-align:middle;margin-right:6px;"></span>Foreign key</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dashed #2b9348;vertical-align:middle;margin-right:6px;"></span>Subtable</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dotted #64748B;vertical-align:middle;margin-right:6px;"></span>Many-to-many</div>',
    ].join('');
    container.appendChild(leg);

    let pan = { x: 40, y: 40 }, zoom = 1;
    let selId = null, searchTerm = '', nodes = [], edges = [];
    let panning = false, panStart = null, panOrig = null;
    let dragId = null, dragMoved = false, dragClient = null;

    const applyXform = () =>
        gAll.setAttribute('transform', `translate(${pan.x},${pan.y}) scale(${zoom})`);

    function render() {
        doRender(svg, gE, gN, nodes, edges, selId, searchTerm);
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
        ({ nodes, edges } = buildGraph(rawSchema, hiddenChk.checked));
        selId = null;
        layoutForce(nodes, edges);
        const fk  = edges.filter(e => e.type==='fk').length;
        const sub = edges.filter(e => e.type==='sub').length;
        const m2m = edges.filter(e => e.type==='m2m').length;
        statsEl.textContent = `${nodes.length} tables · ${fk} FK · ${sub} subtable · ${m2m} M2M`;
        render();
        fitView();
    }

    // Canvas pan
    container.addEventListener('mousedown', ev => {
        if (dragId) return;
        panning  = true;
        panStart = { x: ev.clientX, y: ev.clientY };
        panOrig  = { ...pan };
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
        pan.x = panOrig.x + (ev.clientX - panStart.x);
        pan.y = panOrig.y + (ev.clientY - panStart.y);
        applyXform();
    });

    window.addEventListener('mouseup', () => {
        if (dragId && !dragMoved) {
            selId = selId === dragId ? null : dragId;
            render();
        }
        dragId = null; dragMoved = false; dragClient = null;
        panning = false;
        container.style.cursor = 'grab';
    });

    // Scroll zoom
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

    resetBtn.addEventListener('click', fitView);
    hiddenChk.addEventListener('change', rebuild);

    searchEl.addEventListener('input', () => {
        searchTerm = searchEl.value.trim();
        selId = null;
        render();
    });

    exportBtn.addEventListener('click', () => exportPng(svg, nodes));

    rebuild();
}

// ── PNG export ────────────────────────────────────────────────────────────────

function exportPng(svg, nodes) {
    if (!nodes.length) return;

    const pad = 50;
    const mnX = Math.min(...nodes.map(n => n.x - n.w/2)) - pad;
    const mnY = Math.min(...nodes.map(n => n.y - n.h/2)) - pad;
    const mxX = Math.max(...nodes.map(n => n.x + n.w/2)) + pad;
    const mxY = Math.max(...nodes.map(n => n.y + n.h/2)) + pad;
    const vw = mxX - mnX, vh = mxY - mnY;

    // Clone SVG, set viewBox to full content, remove pan/zoom transform
    const clone = svg.cloneNode(true);
    clone.setAttribute('width',   String(vw));
    clone.setAttribute('height',  String(vh));
    clone.setAttribute('viewBox', `${mnX} ${mnY} ${vw} ${vh}`);
    const gAllClone = clone.querySelector('g');
    if (gAllClone) gAllClone.removeAttribute('transform');

    // White background rect
    const bg = document.createElementNS(NS, 'rect');
    bg.setAttribute('x', String(mnX)); bg.setAttribute('y', String(mnY));
    bg.setAttribute('width', String(vw)); bg.setAttribute('height', String(vh));
    bg.setAttribute('fill', '#DDEAF4');
    if (gAllClone) gAllClone.prepend(bg);

    const svgStr  = new XMLSerializer().serializeToString(clone);
    const svgBlob = new Blob([svgStr], { type: 'image/svg+xml;charset=utf-8' });
    const url     = URL.createObjectURL(svgBlob);

    const img = new Image();
    img.onload = () => {
        const scale  = Math.min(2, 4000 / Math.max(vw, vh));
        const canvas = document.createElement('canvas');
        canvas.width  = Math.round(vw * scale);
        canvas.height = Math.round(vh * scale);
        const c2 = canvas.getContext('2d');
        c2.fillStyle = '#DDEAF4';
        c2.fillRect(0, 0, canvas.width, canvas.height);
        c2.scale(scale, scale);
        c2.drawImage(img, 0, 0);
        URL.revokeObjectURL(url);
        canvas.toBlob(blob => {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'schema-map.png';
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        }, 'image/png');
    };
    img.onerror = () => URL.revokeObjectURL(url);
    img.src = url;
}
