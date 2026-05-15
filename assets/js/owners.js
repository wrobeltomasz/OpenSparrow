const table    = window.EDIT_TABLE;
const recordId = window.EDIT_ID;
const userRole = window.USER_ROLE;
const csrf     = window.CSRF_TOKEN;

const panel     = document.getElementById('ow-panel');
const current   = document.getElementById('ow-current');
const changeEl  = document.getElementById('ow-change');
const select    = document.getElementById('ow-select');
const saveBtn   = document.getElementById('ow-save');
const status    = document.getElementById('ow-status');
const historyEl = document.getElementById('ow-history-body');

let hasOwner = false;

async function loadOwner() {
    try {
        const res  = await fetch(`api_owners.php?action=get&table=${encodeURIComponent(table)}&id=${encodeURIComponent(recordId)}`);
        const data = await res.json();
        if (!data.success) { current.textContent = 'Error loading owner.'; return; }

        if (!data.owner || data.owner.id === null) {
            current.textContent = 'No owner assigned.';
            hasOwner = false;
        } else {
            hasOwner = true;
            let label = data.owner.username;
            if (data.owner.changed_at) {
                const d = new Date(data.owner.changed_at);
                label += ' (last changed: ' + d.toLocaleDateString() + ')';
            }
            current.textContent = label;
        }

        if (saveBtn) {
            saveBtn.textContent = hasOwner ? 'Change Owner' : 'Assign Owner';
        }
    } catch {
        current.textContent = 'Error loading owner.';
    }
}

async function loadHistory() {
    if (!historyEl) return;
    try {
        const res  = await fetch(`api_owners.php?action=history&table=${encodeURIComponent(table)}&id=${encodeURIComponent(recordId)}`);
        const data = await res.json();
        if (!data.success) { historyEl.textContent = 'Error loading history.'; return; }

        if (!data.history.length) {
            historyEl.textContent = 'No assignment history.';
            return;
        }

        const table_ = document.createElement('table');
        table_.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

        const thead = table_.createTHead();
        const hrow  = thead.insertRow();
        ['Owner', 'Changed by', 'Date'].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            th.style.cssText = 'text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;color:#475569;font-weight:600;';
            hrow.appendChild(th);
        });

        const tbody = table_.createTBody();
        data.history.forEach(row => {
            const tr = tbody.insertRow();
            tr.style.borderBottom = '1px solid #f1f5f9';
            [
                row.username       || '—',
                row.changed_by_name || '—',
                row.changed_at ? new Date(row.changed_at).toLocaleString() : '—',
            ].forEach(val => {
                const td = tr.insertCell();
                td.textContent = val;
                td.style.padding = '8px 10px';
            });
        });

        historyEl.textContent = '';
        historyEl.appendChild(table_);
    } catch {
        historyEl.textContent = 'Error loading history.';
    }
}

async function loadEditors() {
    const res  = await fetch('api_owners.php?action=editors');
    const data = await res.json();
    if (!data.success) return;

    select.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— select user —';
    placeholder.disabled = true;
    placeholder.selected = true;
    select.appendChild(placeholder);

    data.users.forEach(u => {
        const opt       = document.createElement('option');
        opt.value       = u.id;
        opt.textContent = u.username;
        select.appendChild(opt);
    });
}

async function saveOwner() {
    const ownerId = parseInt(select.value, 10);
    if (!ownerId) {
        status.textContent = 'Select a user first.';
        status.style.color = '#ef4444';
        return;
    }

    saveBtn.disabled   = true;
    status.textContent = '';

    try {
        const res  = await fetch('api_owners.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set', table, record_id: recordId, owner_id: ownerId, csrf_token: csrf }),
        });
        const data = await res.json();
        if (data.success) {
            status.textContent = 'Owner saved.';
            status.style.color = '#10b981';
            await loadOwner();
            await loadHistory();
        } else {
            status.textContent = data.error || 'Error saving owner.';
            status.style.color = '#ef4444';
        }
    } catch {
        status.textContent = 'Network error.';
        status.style.color = '#ef4444';
    } finally {
        saveBtn.disabled = false;
    }
}

async function init() {
    if (!panel) return;

    await loadOwner();
    await loadHistory();

    if (userRole === 'editor' || userRole === 'admin') {
        await loadEditors();
        changeEl.hidden = false;
        changeEl.style.display = 'flex';
        saveBtn.addEventListener('click', saveOwner);
    }
}

document.addEventListener('DOMContentLoaded', init);
