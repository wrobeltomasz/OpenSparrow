// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import {
    mkStatus, showStatus, fg, input, checkbox,
    buildCollapsibleCard, buildHistoryTable, persistConfig, runCronAction,
} from './etl_common.js';

let flowsConfig  = null;
let flowsVersion = 0;
let jobsForPicker = [];

async function saveFlowsConfig(statusElement) {
    const result = await persistConfig('etl_flow_save', { ...flowsConfig, version: flowsVersion });
    if (result.ok) {
        flowsVersion = result.version;
        if (statusElement) showStatus(statusElement, 'Configuration saved.', true);
        return true;
    }
    if (statusElement) showStatus(statusElement, result.error, false);
    return false;
}

function buildTile(text, cls) {
    const tile = document.createElement('div');
    tile.className = 'flow-tile ' + cls;
    tile.textContent = text;
    return tile;
}

function buildJobTile(flow, stepIndex, redraw) {
    const tile = document.createElement('div');
    tile.className = 'flow-tile flow-tile-job';

    const select = document.createElement('select');
    select.className = 'adm-input';
    if (jobsForPicker.length === 0) {
        const o = document.createElement('option');
        o.value = ''; o.textContent = '(no jobs configured)';
        select.appendChild(o);
    }
    jobsForPicker.forEach((job) => {
        const o = document.createElement('option');
        o.value = job.id; o.textContent = job.name;
        if (flow.steps[stepIndex] === job.id) o.selected = true;
        select.appendChild(o);
    });
    select.onchange = () => { flow.steps[stepIndex] = select.value; };

    const btns = document.createElement('div');
    btns.className = 'flow-tile-btns';

    const up = document.createElement('button');
    up.type = 'button';
    up.className = 'icon-btn';
    up.title = 'Move earlier';
    up.textContent = '↑';
    up.disabled = stepIndex === 0;
    up.onclick = () => {
        [flow.steps[stepIndex - 1], flow.steps[stepIndex]] = [flow.steps[stepIndex], flow.steps[stepIndex - 1]];
        redraw();
    };

    const down = document.createElement('button');
    down.type = 'button';
    down.className = 'icon-btn';
    down.title = 'Move later';
    down.textContent = '↓';
    down.disabled = stepIndex === flow.steps.length - 1;
    down.onclick = () => {
        [flow.steps[stepIndex + 1], flow.steps[stepIndex]] = [flow.steps[stepIndex], flow.steps[stepIndex + 1]];
        redraw();
    };

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'icon-btn icon-btn-danger';
    remove.title = 'Remove step';
    remove.textContent = '✕';
    remove.onclick = () => {
        flow.steps.splice(stepIndex, 1);
        redraw();
    };

    btns.append(up, down, remove);
    tile.append(select, btns);
    return tile;
}

function buildTileRow(flow, redraw) {
    const row = document.createElement('div');
    row.className = 'flow-tile-row';

    row.appendChild(buildTile('Start', 'flow-tile-start'));
    flow.steps.forEach((_, stepIndex) => row.appendChild(buildJobTile(flow, stepIndex, redraw)));

    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'flow-tile flow-tile-add';
    addButton.textContent = '+ Add step';
    addButton.onclick = () => {
        flow.steps.push((jobsForPicker[0] || {}).id || '');
        redraw();
    };
    row.appendChild(addButton);

    row.appendChild(buildTile('End', 'flow-tile-end'));
    return row;
}

function buildFlowHistory(rows) {
    return buildHistoryTable(
        ['Started', 'Trigger', 'Status', 'Failed step', 'Error'],
        rows,
        (r, h) => [
            h.td(r.started_at || ''),
            h.td(r.triggered_by || ''),
            h.statusCell(r.status),
            h.td(r.failed_step_index != null ? String(parseInt(r.failed_step_index, 10) + 1) : ''),
            h.errorCell(r.error_message),
        ]
    );
}

function buildFlowCard(flow, index, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: flow.name,
        placeholder: '(unnamed flow)',
        confirmMsg: `Delete flow "${flow.name}"?`,
        onDelete: () => { flowsConfig.flows.splice(index, 1); redraw(); },
    });

    const name = input(flow.name);
    name.oninput = () => { flow.name = name.value; title.textContent = flow.name || '(unnamed flow)'; };

    const enabled = checkbox('Enabled (runs on schedule)', flow.enabled !== false, (v) => { flow.enabled = v; });

    const tileRowWrap = document.createElement('div');
    function redrawTiles() {
        tileRowWrap.innerHTML = '';
        tileRowWrap.appendChild(buildTileRow(flow, redrawTiles));
    }

    body.append(fg('Name', name), fg('', enabled.label), fg('Steps', tileRowWrap));
    redrawTiles();

    const out = document.createElement('pre');
    out.className = 'adm-input';
    out.style.cssText = 'white-space:pre-wrap; max-height:220px; overflow:auto; display:none;';

    const buttonRun = document.createElement('button');
    buttonRun.className = 'btn btn-success';
    buttonRun.textContent = 'Run now';
    buttonRun.onclick = async () => {
        if (!(await saveFlowsConfig(status))) return;
        await runCronAction('run_etl_flow', { flow_id: flow.id }, out);
    };

    const histWrap = document.createElement('div');
    histWrap.style.marginTop = '10px';
    async function loadHistory() {
        histWrap.textContent = 'Loading history…';
        try {
            const result  = await apiFetch('api.php?action=etl_flow_log&flow_id=' + encodeURIComponent(flow.id));
            const data = await result.json();
            if (data.status !== 'success') { histWrap.textContent = data.error || 'Failed to load history.'; return; }
            if (data.note && (!data.rows || data.rows.length === 0)) { histWrap.textContent = data.note; return; }
            if (!data.rows || data.rows.length === 0) { histWrap.textContent = 'No runs yet.'; return; }
            histWrap.innerHTML = '';
            histWrap.appendChild(buildFlowHistory(data.rows));
        } catch (_) { histWrap.textContent = 'Network error.'; }
    }
    if (flow.id) loadHistory();

    const buttonBar = document.createElement('div');
    buttonBar.style.marginTop = '10px';
    buttonBar.append(buttonRun);
    body.append(buttonBar, out, histWrap);

    return card;
}

export async function renderFlowsTab(panel) {
    panel.innerHTML = '<p class="c-muted" style="padding:16px;">Loading flows…</p>';

    try {
        const result  = await apiFetch('api.php?action=etl_flow_load');
        const data = await result.json();
        if (data.status !== 'success') {
            panel.innerHTML = `<p style="color:var(--error); padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        flowsConfig   = data.config;
        flowsVersion  = data.version || 0;
        jobsForPicker = data.jobs || [];
    } catch (_) {
        panel.innerHTML = '<p style="color:var(--error); padding:16px;">Network error loading Flows config.</p>';
        return;
    }
    if (!Array.isArray(flowsConfig.flows)) flowsConfig.flows = [];

    panel.innerHTML = '';
    const intro = document.createElement('p');
    intro.className = 'c-muted';
    intro.style.margin = '0 0 16px';
    intro.textContent = 'Chain existing ETL jobs into an ordered sequence: start, one or more jobs, end. '
        + 'The flow runs its steps in order and stops immediately at the first failing step.';
    panel.appendChild(intro);

    const status = mkStatus();
    const list = document.createElement('div');

    function redraw() {
        list.innerHTML = '';
        flowsConfig.flows.forEach((flow, index) => list.appendChild(buildFlowCard(flow, index, redraw, status)));
    }

    const buttonAdd = document.createElement('button');
    buttonAdd.className = 'btn btn-success';
    buttonAdd.textContent = '+ Add flow';
    buttonAdd.onclick = () => {
        flowsConfig.flows.push({
            id: '', name: 'New flow', enabled: true, steps: [],
            last_run_status: null, last_run_at: null,
        });
        redraw();
    };

    const buttonSave = document.createElement('button');
    buttonSave.className = 'btn';
    buttonSave.textContent = 'Save configuration';
    buttonSave.style.marginLeft = '8px';
    buttonSave.onclick = () => saveFlowsConfig(status);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    bar.append(buttonAdd, buttonSave);
    panel.append(bar, status, list);
    redraw();
}
