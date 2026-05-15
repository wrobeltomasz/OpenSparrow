import { state } from '../state.js';

export function buildExpandButton(row, schema, tr) {
    const tdExpand = document.createElement('td');
    const btn = document.createElement('button');
    btn.textContent = '>';
    btn.style.cssText = 'background:none; border:none; cursor:pointer; font-size:14px; color:var(--accent); font-weight:bold;';

    const subtables = schema.tables[state.currentTable]?.subtables || [];
    const isReadOnly = window.USER_ROLE !== 'editor' && window.USER_ROLE !== 'admin';

    btn.addEventListener('click', async () => {
        const next = tr.nextElementSibling;
        if (next?.classList.contains('drilldown-row')) {
            next.remove();
            btn.textContent = '>';
            return;
        }

        btn.textContent = 'v';
        const ddTr = document.createElement('tr');
        ddTr.className = 'drilldown-row';
        const ddTd = document.createElement('td');
        ddTd.colSpan = state.displayedColumns.length + (isReadOnly ? 2 : 3);

        const loading = document.createElement('em');
        loading.textContent = 'Loading...';
        ddTd.appendChild(loading);
        ddTr.appendChild(ddTd);
        tr.after(ddTr);
        ddTd.replaceChildren();

        for (const sub of subtables) {
            ddTd.appendChild(await buildSubtableBlock(sub, row));
        }
    });

    tdExpand.appendChild(btn);
    return tdExpand;
}

async function buildSubtableBlock(sub, row) {
    const wrapper = document.createElement('div');
    wrapper.className = 'drilldown-container';
    wrapper.style.marginBottom = '20px';

    const header = document.createElement('div');
    header.style.cssText = 'display:flex; align-items:center; gap:10px; margin-bottom:10px;';

    const title = document.createElement('h4');
    title.textContent = sub.label || sub.table;
    title.style.cssText = 'margin:0; font-size:14px; color:var(--text);';
    header.appendChild(title);

    const canWrite = window.USER_ROLE === 'editor' || window.USER_ROLE === 'admin';
    if (canWrite && sub.foreign_key) {
        const addBtn = document.createElement('a');
        addBtn.href = `create.php?table=${encodeURIComponent(sub.table)}&${encodeURIComponent(sub.foreign_key)}=${encodeURIComponent(row.id)}`;
        addBtn.className = 'btn-action';
        addBtn.textContent = '+';
        addBtn.style.cssText = 'padding:1px 8px; font-size:16px; font-weight:bold; line-height:1.4; text-decoration:none;';
        addBtn.title = `Add ${sub.label || sub.table}`;
        header.appendChild(addBtn);
    }

    wrapper.appendChild(header);

    try {
        const res = await fetch(
            `api.php?api=list&table=${encodeURIComponent(sub.table)}&filter_col=${encodeURIComponent(sub.foreign_key)}&filter_val=${encodeURIComponent(row.id)}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        const data = await res.json();
        const ul = document.createElement('ul');
        ul.className = 'drilldown-list';

        if (data.rows?.length > 0) {
            const cols = sub.columns_to_show?.length ? sub.columns_to_show : ['id'];
            data.rows.forEach(r => {
                const li = document.createElement('li');
                const textSpan = document.createElement('span');
                textSpan.textContent = cols.map(c => r[c + '__display'] ?? r[c] ?? '').join(' - ') || 'No title';
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = `id: ${r.id}`;
                li.appendChild(textSpan);
                li.appendChild(badge);
                li.addEventListener('click', () => {
                    window.location.href = `edit.php?table=${sub.table}&id=${r.id}`;
                });
                ul.appendChild(li);
            });
        } else {
            const empty = document.createElement('li');
            empty.textContent = 'No related records.';
            empty.style.cssText = 'justify-content:center; color:var(--muted);';
            ul.appendChild(empty);
        }
        wrapper.appendChild(ul);
    } catch {
        const err = document.createElement('p');
        err.style.cssText = 'color:var(--danger); font-size:13px;';
        err.textContent = 'Error fetching data.';
        wrapper.appendChild(err);
    }

    return wrapper;
}
