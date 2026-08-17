// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getGlobalSchema } from './app.js';

const SVG_NAMESPACE     = 'http://www.w3.org/2000/svg';
const NODE_WIDTH        = 195;
const NODE_HEADER_HEIGHT = 36;
const NODE_ROW_HEIGHT   = 21;
const NODE_MAX_COLUMNS  = 9;
const NODE_PADDING      = 8;

export async function renderErdPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;height:calc(100vh - 120px);min-height:480px;';
    workspaceElement.appendChild(wrap);

    const toolbar = document.createElement('div');
    toolbar.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-shrink:0;';

    const h2 = document.createElement('h2');
    h2.textContent = 'Schema Map';
    h2.style.cssText = 'margin:0;color:var(--text);';
    toolbar.appendChild(h2);

    const hint = document.createElement('span');
    hint.textContent = 'Drag canvas to pan · Scroll to zoom · Click table to highlight · Drag table to reposition';
    hint.style.cssText = 'color:var(--muted);';
    toolbar.appendChild(hint);

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

    toolbar.appendChild(right);
    wrap.appendChild(toolbar);

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
        const allColumns = Object.entries(config.columns || {})
            .filter(([columnName]) => columnName !== 'id');
        const shown = allColumns.slice(0, NODE_MAX_COLUMNS);
        const extra = allColumns.length - shown.length;
        const rows  = Math.max(1, shown.length + (extra > 0 ? 1 : 0));
        nodes.push({
            id:     name,
            label:  config.display_name || name,
            schema: config.schema || 'public',
            hidden: !!config.hidden,
            cols:   shown.map(([columnName, columnConfig]) => ({
                name: columnName,
                type: columnConfig.type || 'text',
                isFk: !!(config.foreign_keys?.[columnName]),
            })),
            extra,
            w: NODE_WIDTH,
            h: NODE_HEADER_HEIGHT + rows * NODE_ROW_HEIGHT + NODE_PADDING,
            x: 0, y: 0, vx: 0, vy: 0,
        });
    }

    const nodeSet = new Set(nodes.map(node => node.id));

    for (const [name, config] of Object.entries(tables)) {
        if (!nodeSet.has(name)) continue;
        for (const [column, fk] of Object.entries(config.foreign_keys || {})) {
            const targetTable = fk.reference_table;
            if (targetTable && nodeSet.has(targetTable) && targetTable !== name)
                edges.push({ src: name, tgt: targetTable, type: 'fk', label: column });
        }
        for (const subtable of (config.subtables || [])) {
            if (subtable.table && nodeSet.has(subtable.table))
                edges.push({
                    src: name,
                    tgt: subtable.table,
                    type: 'sub',
                    label: subtable.foreign_key || '',
                });
        }
        for (const m2m of (config.many_to_many || [])) {
            const targetTable = m2m.other_table;
            if (targetTable && nodeSet.has(targetTable) && targetTable !== name) {
                const duplicateEdge = edges.find(edge => edge.type === 'm2m' &&
                    ((edge.src === name && edge.tgt === targetTable)
                        || (edge.src === targetTable && edge.tgt === name)));
                if (!duplicateEdge) {
                    edges.push({ src: name, tgt: targetTable, type: 'm2m', label: m2m.label || '' });
                }
            }
        }
    }

    return { nodes, edges };
}

function layoutForce(nodes, edges) {
    if (!nodes.length) return;
    const columns = Math.ceil(Math.sqrt(nodes.length));
    const spacingX = 290, spacingY = 310;
    nodes.forEach((node, index) => {
        node.x  = (index % columns) * spacingX + spacingX / 2 + (Math.random() - 0.5) * 30;
        node.y  = Math.floor(index / columns) * spacingY + spacingY / 2 + (Math.random() - 0.5) * 30;
        node.vx = node.vy = 0;
    });

    const nodeMap = new Map(nodes.map(node => [node.id, node]));
    const REPEL = 14000, SPRING = 320, K = 0.04, DAMP = 0.82;

    for (let iteration = 0; iteration < 150; iteration++) {
        const cool = 1 - iteration / 150;
        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                const nodeA = nodes[i], nodeB = nodes[j];
                const deltaX = nodeB.x - nodeA.x, deltaY = nodeB.y - nodeA.y;
                const distanceSquared = Math.max(1, deltaX*deltaX + deltaY*deltaY);
                const distance = Math.sqrt(distanceSquared);
                const force  = REPEL / distanceSquared;
                const forceX = deltaX/distance*force, forceY = deltaY/distance*force;
                nodeA.vx -= forceX; nodeA.vy -= forceY;
                nodeB.vx += forceX; nodeB.vy += forceY;
            }
        }
        for (const edge of edges) {
            const nodeA = nodeMap.get(edge.src), nodeB = nodeMap.get(edge.tgt);
            if (!nodeA || !nodeB) continue;
            const deltaX = nodeB.x - nodeA.x, deltaY = nodeB.y - nodeA.y;
            const distance = Math.sqrt(deltaX*deltaX + deltaY*deltaY) || 1;
            const force  = K * (distance - SPRING);
            const forceX = deltaX/distance*force, forceY = deltaY/distance*force;
            nodeA.vx += forceX; nodeA.vy += forceY;
            nodeB.vx -= forceX; nodeB.vy -= forceY;
        }
        const maxV = 55 * cool + 4;
        for (const node of nodes) {
            node.vx = Math.max(-maxV, Math.min(maxV, node.vx));
            node.vy = Math.max(-maxV, Math.min(maxV, node.vy));
            node.x += node.vx; node.y += node.vy;
            node.vx *= DAMP; node.vy *= DAMP;
        }
    }
    const minX = Math.min(...nodes.map(node => node.x - node.w/2));
    const minY = Math.min(...nodes.map(node => node.y - node.h/2));
    nodes.forEach(node => { node.x -= minX - 60; node.y -= minY - 60; });
}

function svgElement(tag, attributes = {}) {
    const element = document.createElementNS(SVG_NAMESPACE, tag);
    for (const [attributeName, attributeValue] of Object.entries(attributes)) {
        element.setAttribute(attributeName, attributeValue);
    }
    return element;
}

function svgText(content, x, y, options = {}) {
    const textElement = svgElement('text', {
        x, y,
        'dominant-baseline': options.bl   || 'middle',
        'text-anchor':       options.ta   || 'start',
        'font-size':         options.sz   || 12,
        'font-family':       'system-ui,-apple-system,sans-serif',
        fill:                options.fill || '#6E767F',
        ...(options.weight  ? { 'font-weight': options.weight  } : {}),
        ...(options.opacity ? { opacity:       options.opacity  } : {}),
    });
    const stringContent = String(content), max = options.max || 26;
    textElement.textContent = stringContent.length > max
        ? stringContent.slice(0, max - 1) + '…'
        : stringContent;
    return textElement;
}

function borderPt(node, targetX, targetY) {
    const deltaX = targetX - node.x, deltaY = targetY - node.y;
    const halfWidth = node.w/2, halfHeight = node.h/2;
    if (!deltaX && !deltaY) return { x: node.x, y: node.y };
    const signX = deltaX < 0 ? -1 : 1, signY = deltaY < 0 ? -1 : 1;
    if (!deltaX) return { x: node.x, y: node.y + signY*halfHeight };
    if (!deltaY) return { x: node.x + signX*halfWidth, y: node.y };
    const intersectY = deltaY * (halfWidth / Math.abs(deltaX));
    if (Math.abs(intersectY) <= halfHeight) {
        return { x: node.x + signX*halfWidth, y: node.y + intersectY };
    }
    return {
        x: node.x + deltaX*(halfHeight/Math.abs(deltaY)),
        y: node.y + signY*halfHeight,
    };
}

function bezierD(x1, y1, x2, y2) {
    const deltaX = Math.abs(x2-x1), deltaY = Math.abs(y2-y1);
    if (deltaX > deltaY) {
        const controlOffset = deltaX * 0.45;
        return `M${x1},${y1} C${x1+controlOffset},${y1} ${x2-controlOffset},${y2} ${x2},${y2}`;
    }
    const controlOffset = deltaY * 0.45, signY = y2 > y1 ? 1 : -1;
    return `M${x1},${y1} C${x1},${y1+controlOffset*signY} `
        + `${x2},${y2-controlOffset*signY} ${x2},${y2}`;
}

function topRoundedRect(x, y, width, height, radius) {
    return `M${x+radius},${y} H${x+width-radius} Q${x+width},${y} ${x+width},${y+radius} `
        + `V${y+height} H${x} V${y+radius} Q${x},${y} ${x+radius},${y}Z`;
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

function doRender(svg, edgeGroup, nodeGroup, nodes, edges, selectId, searchTerm) {
    const definitions = svg.querySelector('defs');
    Array.from(definitions.querySelectorAll('clipPath'))
        .forEach(clipPath => clipPath.remove());
    edgeGroup.innerHTML = '';
    nodeGroup.innerHTML = '';

    const nodeMap = new Map(nodes.map(node => [node.id, node]));
    let linked = null;
    if (searchTerm) {
        const term = searchTerm.toLowerCase();
        linked = new Set(
            nodes.filter(node =>
                node.id.toLowerCase().includes(term) ||
                node.label.toLowerCase().includes(term)
            ).map(node => node.id)
        );
    } else if (selectId) {
        linked = new Set(edges
            .filter(edge => edge.src === selectId || edge.tgt === selectId)
            .flatMap(edge => [edge.src, edge.tgt]));
    }

    for (const edge of edges) {
        const sourceNode = nodeMap.get(edge.src), targetNode = nodeMap.get(edge.tgt);
        if (!sourceNode || !targetNode) continue;
        const style   = EDGE_STYLE[edge.type];
        const dimmed = linked && !linked.has(edge.src) && !linked.has(edge.tgt);
        const startPoint = borderPt(sourceNode, targetNode.x, targetNode.y);
        const endPoint   = borderPt(targetNode, sourceNode.x, sourceNode.y);

        edgeGroup.appendChild(svgElement('path', {
            d: bezierD(startPoint.x, startPoint.y, endPoint.x, endPoint.y),
            stroke: style.color, 'stroke-width': dimmed ? 1 : 2,
            fill: 'none', opacity: dimmed ? 0.1 : 0.85,
            ...(style.dash ? { 'stroke-dasharray': style.dash } : {}),
            'marker-end': `url(#mk-${edge.type})`,
        }));

        if (edge.label && !dimmed) {
            const midX = (startPoint.x+endPoint.x)/2, midY = (startPoint.y+endPoint.y)/2;
            edgeGroup.appendChild(svgElement('rect', {
                x:midX-28, y:midY-8, width:56, height:15, rx:3, fill:'#fff', opacity:0.88,
            }));
            edgeGroup.appendChild(svgText(edge.label, midX, midY+1,
                { ta:'middle', sz:10, fill:style.color, max:16 }));
        }
    }

    for (const node of nodes) {
        const dimmed   = linked && !linked.has(node.id);
        const isSelect = node.id === selectId;
        const isNeighbour = linked && !isSelect && linked.has(node.id);
        const headerColor  = isSelect ? HDR_COLOR.sel : isNeighbour ? HDR_COLOR.nbr
                    : node.hidden ? HDR_COLOR.hidden : HDR_COLOR.normal;
        const rectX = node.x - node.w/2, rectY = node.y - node.h/2;

        const group = svgElement('g', { 'data-id': node.id, opacity: dimmed ? 0.2 : 1 });
        group.style.cursor = 'pointer';

        group.appendChild(svgElement('rect', {
            x:rectX+3, y:rectY+3, width:node.w, height:node.h, rx:7, fill:'#00000016',
        }));

        group.appendChild(svgElement('rect', {
            x: rectX, y: rectY, width:node.w, height:node.h, rx:7,
            fill: isSelect ? '#F1F4F8' : '#fff',
            stroke: isSelect ? '#003366' : isNeighbour ? '#1F2A37' : '#D0DAE6',
            'stroke-width': (isSelect || isNeighbour) ? 2 : 1,
        }));

        group.appendChild(svgElement('path', {
            d: topRoundedRect(rectX, rectY, node.w, NODE_HEADER_HEIGHT, 7),
            fill: headerColor,
        }));

        group.appendChild(svgText(node.label, rectX+node.w/2, rectY+NODE_HEADER_HEIGHT/2+1,
            { ta:'middle', fill:'#fff', weight:'600', sz:13, max:22 }));

        if (node.schema !== 'public') {
            group.appendChild(svgText(node.schema, rectX+node.w-7,
                rectY+NODE_HEADER_HEIGHT/2+1,
                { ta:'end', fill:'rgba(255,255,255,.5)', sz:9, max:12 }));
        }

        group.appendChild(svgElement('line', {
            x1:rectX, y1:rectY+NODE_HEADER_HEIGHT,
            x2:rectX+node.w, y2:rectY+NODE_HEADER_HEIGHT,
            stroke:'#D0DAE6', 'stroke-width':1,
        }));

        node.cols.forEach((column, columnIndex) => {
            const rowY = rectY + NODE_HEADER_HEIGHT + columnIndex*NODE_ROW_HEIGHT
                + NODE_ROW_HEIGHT/2 + 2;
            if (column.isFk) {
                group.appendChild(svgText('→', rectX+6, rowY, { fill:'#6E767F', sz:10 }));
            }
            group.appendChild(svgText(column.name, column.isFk ? rectX+18 : rectX+8, rowY,
                { fill:'#1F2A37', sz:11, max:17 }));
            group.appendChild(svgText(column.type, rectX+node.w-7, rowY,
                { ta:'end', fill:'#6E767F', sz:10, max:10 }));
        });

        if (node.extra > 0) {
            const rowY = rectY + NODE_HEADER_HEIGHT + node.cols.length*NODE_ROW_HEIGHT
                + NODE_ROW_HEIGHT/2 + 2;
            group.appendChild(svgText(`+ ${node.extra} more`, rectX+node.w/2, rowY,
                { ta:'middle', fill:'#6E767F', sz:10 }));
        }

        nodeGroup.appendChild(group);
    }
}

function startDiagram(container, rawSchema, hiddenCheckbox, resetButton, exportButton, searchElement, statisticsElement) {
    const svg = document.createElementNS(SVG_NAMESPACE, 'svg');
    svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;';
    container.appendChild(svg);

    const definitions = svgElement('defs');
    for (const [type, style] of Object.entries(EDGE_STYLE)) {
        const marker = svgElement('marker', {
            id:`mk-${type}`, markerWidth:8, markerHeight:8, refX:7, refY:3, orient:'auto',
        });
        const polygon = svgElement('polygon', { points:'0 0,8 3,0 6', fill:style.color });
        marker.appendChild(polygon); definitions.appendChild(marker);
    }
    svg.appendChild(definitions);

    const rootGroup = svgElement('g'), edgeGroup = svgElement('g'), nodeGroup = svgElement('g');
    rootGroup.appendChild(edgeGroup); rootGroup.appendChild(nodeGroup);
    svg.appendChild(rootGroup);

    const legend = document.createElement('div');
    legend.style.cssText = 'position:absolute;bottom:12px;left:12px;background:rgba(255,255,255,.95);border:1px solid var(--border);border-radius:6px;padding:8px 12px;line-height:2;pointer-events:none;z-index:5;';
    legend.innerHTML = [
        '<b style="color:var(--muted);display:block;margin-bottom:2px;">Legend</b>',
        '<div><span style="display:inline-block;width:22px;height:2px;background:#6E767F;vertical-align:middle;margin-right:6px;"></span>Foreign key</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dashed #003366;vertical-align:middle;margin-right:6px;"></span>Subtable</div>',
        '<div><span style="display:inline-block;width:22px;height:0;border-top:2px dotted #8A9199;vertical-align:middle;margin-right:6px;"></span>Many-to-many</div>',
    ].join('');
    container.appendChild(legend);

    let pan = { x: 40, y: 40 }, zoom = 1;
    let selectId = null, searchTerm = '', nodes = [], edges = [];
    let panning = false, panStart = null, panOriginal = null;
    let dragId = null, dragMoved = false, dragClient = null;

    const applyXform = () =>
        rootGroup.setAttribute('transform', `translate(${pan.x},${pan.y}) scale(${zoom})`);

    function render() {
        doRender(svg, edgeGroup, nodeGroup, nodes, edges, selectId, searchTerm);
        nodeGroup.querySelectorAll('[data-id]').forEach(group => {
            const nodeId = group.getAttribute('data-id');
            group.addEventListener('mousedown', event => {
                event.stopPropagation();
                dragId     = nodeId;
                dragMoved  = false;
                dragClient = { x: event.clientX, y: event.clientY };
            });
        });
    }

    function fitView() {
        if (!nodes.length) return;
        const minX = Math.min(...nodes.map(node => node.x - node.w/2));
        const minY = Math.min(...nodes.map(node => node.y - node.h/2));
        const maxX = Math.max(...nodes.map(node => node.x + node.w/2));
        const maxY = Math.max(...nodes.map(node => node.y + node.h/2));
        const containerWidth = container.clientWidth, containerHeight = container.clientHeight;
        const graphWidth = maxX - minX + 100, graphHeight = maxY - minY + 100;
        zoom  = Math.min(1.2, Math.min(containerWidth/graphWidth, containerHeight/graphHeight));
        pan.x = (containerWidth - graphWidth*zoom)/2 - minX*zoom + 50*zoom;
        pan.y = (containerHeight - graphHeight*zoom)/2 - minY*zoom + 50*zoom;
        applyXform();
    }

    function rebuild() {
        ({ nodes, edges } = buildGraph(rawSchema, hiddenCheckbox.checked));
        selectId = null;
        layoutForce(nodes, edges);
        const foreignKeyCount = edges.filter(edge => edge.type==='fk').length;
        const subtableCount   = edges.filter(edge => edge.type==='sub').length;
        const m2mCount        = edges.filter(edge => edge.type==='m2m').length;
        statisticsElement.textContent = `${nodes.length} tables · ${foreignKeyCount} FK · `
            + `${subtableCount} subtable · ${m2mCount} M2M`;
        render();
        fitView();
    }

    container.addEventListener('mousedown', event => {
        if (dragId) return;
        panning  = true;
        panStart = { x: event.clientX, y: event.clientY };
        panOriginal  = { ...pan };
        container.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', event => {
        if (dragId) {
            if (dragClient) {
                const dragDeltaX = event.clientX - dragClient.x;
                const dragDeltaY = event.clientY - dragClient.y;
                if (dragDeltaX*dragDeltaX + dragDeltaY*dragDeltaY > 16) dragMoved = true;
            }
            if (dragMoved) {
                const node = nodes.find(candidate => candidate.id === dragId);
                if (node) {
                    node.x += event.movementX/zoom;
                    node.y += event.movementY/zoom;
                    render();
                }
            }
            return;
        }
        if (!panning) return;
        pan.x = panOriginal.x + (event.clientX - panStart.x);
        pan.y = panOriginal.y + (event.clientY - panStart.y);
        applyXform();
    });

    window.addEventListener('mouseup', () => {
        if (dragId && !dragMoved) {
            selectId = selectId === dragId ? null : dragId;
            render();
        }
        dragId = null; dragMoved = false; dragClient = null;
        panning = false;
        container.style.cursor = 'grab';
    });

    container.addEventListener('wheel', event => {
        event.preventDefault();
        const rect = container.getBoundingClientRect();
        const pointerX = event.clientX - rect.left, pointerY = event.clientY - rect.top;
        const nextZoom = Math.max(0.12,
            Math.min(3, zoom * (event.deltaY > 0 ? 0.88 : 1.14)));
        pan.x = pointerX - (pointerX - pan.x) * nextZoom / zoom;
        pan.y = pointerY - (pointerY - pan.y) * nextZoom / zoom;
        zoom = nextZoom;
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

    const padding = 50;
    const minX = Math.min(...nodes.map(node => node.x - node.w/2)) - padding;
    const minY = Math.min(...nodes.map(node => node.y - node.h/2)) - padding;
    const maxX = Math.max(...nodes.map(node => node.x + node.w/2)) + padding;
    const maxY = Math.max(...nodes.map(node => node.y + node.h/2)) + padding;
    const viewWidth = maxX - minX, viewHeight = maxY - minY;

    const clone = svg.cloneNode(true);
    clone.setAttribute('width',   String(viewWidth));
    clone.setAttribute('height',  String(viewHeight));
    clone.setAttribute('viewBox', `${minX} ${minY} ${viewWidth} ${viewHeight}`);
    const rootGroupClone = clone.querySelector('g');
    if (rootGroupClone) rootGroupClone.removeAttribute('transform');

    const background = document.createElementNS(SVG_NAMESPACE, 'rect');
    background.setAttribute('x', String(minX)); background.setAttribute('y', String(minY));
    background.setAttribute('width', String(viewWidth));
    background.setAttribute('height', String(viewHeight));
    background.setAttribute('fill', '#D0DAE6');
    if (rootGroupClone) rootGroupClone.prepend(background);

    const svgString  = new XMLSerializer().serializeToString(clone);
    const svgBlob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' });
    const url     = URL.createObjectURL(svgBlob);

    const image = new Image();
    image.onload = () => {
        const scale  = Math.min(2, 4000 / Math.max(viewWidth, viewHeight));
        const canvas = document.createElement('canvas');
        canvas.width  = Math.round(viewWidth * scale);
        canvas.height = Math.round(viewHeight * scale);
        const canvasContext = canvas.getContext('2d');
        canvasContext.fillStyle = '#D0DAE6';
        canvasContext.fillRect(0, 0, canvas.width, canvas.height);
        canvasContext.scale(scale, scale);
        canvasContext.drawImage(image, 0, 0);
        URL.revokeObjectURL(url);
        canvas.toBlob(blob => {
            const downloadLink = document.createElement('a');
            downloadLink.href = URL.createObjectURL(blob);
            downloadLink.download = 'schema-map.png';
            downloadLink.click();
            setTimeout(() => URL.revokeObjectURL(downloadLink.href), 2000);
        }, 'image/png');
    };
    image.onerror = () => URL.revokeObjectURL(url);
    image.src = url;
}
