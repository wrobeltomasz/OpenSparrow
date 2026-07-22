// admin/js/etl_flow.js — ETL Flows tab (ordered chains of existing ETL jobs: start
// tile, job tile, job tile, ..., end tile; stops on first failing step).
// Persists the "etl_flows" config via etl_flow_save (optimistic-lock version).
// Cron worker: cron/cron_etl_flow.php.
import { apiFetch } from '../../assets/js/util/api.js';
import { escHtml } from '../../assets/js/util/esc.js';
import {
    mkStatus, showStatus, fg, input, checkbox,
    buildCollapsibleCard, buildHistoryTable, persistConfig, runCronAction,
} from './etl_common.js';

let flowsConfig  = null;
let flowsVersion = 0;
let jobsForPicker = [];

async function saveFlowsConfig(statusEl) {
    const result = await persistConfig('etl_flow_save', { ...flowsConfig, version: flowsVersion });
    if (result.ok) {
        flowsVersion = result.version;
        if (statusEl) showStatus(statusEl, 'Configuration saved.', true);
        return true;
    }
    if (statusEl) showStatus(statusEl, result.error, false);
    return false;
}

function buildTile(text, cls) {
    const tile = document.createElement('div');
    tile.className = 'flow-tile ' + cls;
    tile.textContent = text;
    return tile;
}

function buildJobTile(flow, stepIdx, redraw) {
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
        if (flow.steps[stepIdx] === job.id) o.selected = true;
        select.appendChild(o);
    });
    select.onchange = () => { flow.steps[stepIdx] = select.value; };

    const btns = document.createElement('div');
    btns.className = 'flow-tile-btns';

    const up = document.createElement('button');
    up.type = 'button';
    up.className = 'icon-btn';
    up.title = 'Move earlier';
    up.textContent = '↑';
    up.disabled = stepIdx === 0;
    up.onclick = () => {
        [flow.steps[stepIdx - 1], flow.steps[stepIdx]] = [flow.steps[stepIdx], flow.steps[stepIdx - 1]];
        redraw();
    };

    const down = document.createElement('button');
    down.type = 'button';
    down.className = 'icon-btn';
    down.title = 'Move later';
    down.textContent = '↓';
    down.disabled = stepIdx === flow.steps.length - 1;
    down.onclick = () => {
        [flow.steps[stepIdx + 1], flow.steps[stepIdx]] = [flow.steps[stepIdx], flow.steps[stepIdx + 1]];
        redraw();
    };

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'icon-btn icon-btn-danger';
    remove.title = 'Remove step';
    remove.textContent = '✕';
    remove.onclick = () => {
        flow.steps.splice(stepIdx, 1);
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
    flow.steps.forEach((_, stepIdx) => row.appendChild(buildJobTile(flow, stepIdx, redraw)));

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'flow-tile flow-tile-add';
    addBtn.textContent = '+ Add step';
    addBtn.onclick = () => {
        flow.steps.push((jobsForPicker[0] || {}).id || '');
        redraw();
    };
    row.appendChild(addBtn);

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

function buildFlowCard(flow, idx, redraw, status) {
    const { card, body, title } = buildCollapsibleCard({
        titleText: flow.name,
        placeholder: '(unnamed flow)',
        confirmMsg: `Delete flow "${flow.name}"?`,
        onDelete: () => { flowsConfig.flows.splice(idx, 1); redraw(); },
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

    const btnRun = document.createElement('button');
    btnRun.className = 'btn btn-success';
    btnRun.textContent = 'Run now';
    btnRun.onclick = async () => {
        if (!(await saveFlowsConfig(status))) return; // persist so the cron reads the latest flow
        await runCronAction('run_etl_flow', { flow_id: flow.id }, out);
    };

    const histWrap = document.createElement('div');
    histWrap.style.marginTop = '10px';
    async function loadHistory() {
        histWrap.textContent = 'Loading history…';
        try {
            const res  = await apiFetch('api.php?action=etl_flow_log&flow_id=' + encodeURIComponent(flow.id));
            const data = await res.json();
            if (data.status !== 'success') { histWrap.textContent = data.error || 'Failed to load history.'; return; }
            if (data.note && (!data.rows || data.rows.length === 0)) { histWrap.textContent = data.note; return; }
            if (!data.rows || data.rows.length === 0) { histWrap.textContent = 'No runs yet.'; return; }
            histWrap.innerHTML = '';
            histWrap.appendChild(buildFlowHistory(data.rows));
        } catch (_) { histWrap.textContent = 'Network error.'; }
    }
    if (flow.id) loadHistory();

    const btnBar = document.createElement('div');
    btnBar.style.marginTop = '10px';
    btnBar.append(btnRun);
    body.append(btnBar, out, histWrap);

    return card;
}

/* ---------- entry ---------- */
export async function renderFlowsTab(panel) {
    panel.innerHTML = '<p class="c-muted" style="padding:16px;">Loading flows…</p>';

    try {
        const res  = await apiFetch('api.php?action=etl_flow_load');
        const data = await res.json();
        if (data.status !== 'success') {
            panel.innerHTML = `<p style="color:var(--danger); padding:16px;">${escHtml(data.error || 'Failed to load config.')}</p>`;
            return;
        }
        flowsConfig   = data.config;
        flowsVersion  = data.version || 0;
        jobsForPicker = data.jobs || [];
    } catch (_) {
        panel.innerHTML = '<p style="color:var(--danger); padding:16px;">Network error loading Flows config.</p>';
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
        flowsConfig.flows.forEach((flow, idx) => list.appendChild(buildFlowCard(flow, idx, redraw, status)));
    }

    const btnAdd = document.createElement('button');
    btnAdd.className = 'btn btn-success';
    btnAdd.textContent = '+ Add flow';
    btnAdd.onclick = () => {
        flowsConfig.flows.push({
            id: '', name: 'New flow', enabled: true, steps: [],
            last_run_status: null, last_run_at: null,
        });
        redraw();
    };

    const btnSave = document.createElement('button');
    btnSave.className = 'btn';
    btnSave.textContent = 'Save configuration';
    btnSave.style.marginLeft = '8px';
    btnSave.onclick = () => saveFlowsConfig(status);

    const bar = document.createElement('div');
    bar.style.marginBottom = '12px';
    bar.append(btnAdd, btnSave);
    panel.append(bar, status, list);
    redraw();
}
