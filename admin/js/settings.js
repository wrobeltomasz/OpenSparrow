// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

// admin/settings.js
import { showStatusPill } from './app.js';

export async function renderSettingsPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<h3>Loading settings…</h3>';

    let data;
    try {
        const res = await fetch('api.php?action=get_language_setting');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        data = await res.json();
    } catch (e) {
        workspaceEl.innerHTML = '<h3 style="color:#d00000;">Error loading settings. Check server logs.</h3>';
        return;
    }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    }

    workspaceEl.innerHTML = '';

    const h3 = document.createElement('h3');
    h3.style.marginTop = '0';
    h3.textContent = 'Application Settings';
    workspaceEl.appendChild(h3);

    // ── Language Settings card ─────────────────────────────────────────────

    const card = document.createElement('div');
    card.style.cssText = 'padding:20px; background:white; border:1px solid #CBD5E1; border-radius:8px; margin-bottom:24px; max-width:540px;';

    const cardTitle = document.createElement('h4');
    cardTitle.style.cssText = 'margin:0 0 4px; font-size:15px;';
    cardTitle.textContent = 'Language Settings';
    card.appendChild(cardTitle);

    const cardDesc = document.createElement('p');
    cardDesc.style.cssText = 'color:#64748B; font-size:13px; margin:0 0 20px;';
    cardDesc.textContent = 'Set the site-wide default language and which languages users can switch to. Language files live in languages/*.json.';
    card.appendChild(cardDesc);

    // Default language select
    const defRow = document.createElement('div');
    defRow.style.cssText = 'margin-bottom:18px;';

    const defLabel = document.createElement('label');
    defLabel.htmlFor = 'setting-default-lang';
    defLabel.style.cssText = 'display:block; font-size:13px; font-weight:600; color:#64748B; margin-bottom:6px;';
    defLabel.textContent = 'Default language';
    defRow.appendChild(defLabel);

    const defSelect = document.createElement('select');
    defSelect.id = 'setting-default-lang';
    defSelect.style.cssText = 'padding:7px 10px; border:1px solid #CBD5E1; border-radius:6px; font-size:14px; width:220px; background:white;';
    data.all_locales.forEach(loc => {
        const opt = document.createElement('option');
        opt.value = loc.code;
        opt.textContent = `${loc.name} (${loc.code})`;
        if (loc.code === data.default_language) opt.selected = true;
        defSelect.appendChild(opt);
    });
    defRow.appendChild(defSelect);
    card.appendChild(defRow);

    // Available languages checkboxes
    const availRow = document.createElement('div');
    availRow.style.cssText = 'margin-bottom:20px;';

    const availLabel = document.createElement('div');
    availLabel.style.cssText = 'font-size:13px; font-weight:600; color:#64748B; margin-bottom:8px;';
    availLabel.textContent = 'Available languages';
    availRow.appendChild(availLabel);

    const checkboxes = [];
    data.all_locales.forEach(loc => {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer; font-size:14px; color:#64748B;';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = loc.code;
        cb.checked = data.available_languages.includes(loc.code);
        cb.style.cssText = 'width:15px; height:15px; cursor:pointer;';

        row.appendChild(cb);
        row.appendChild(document.createTextNode(`${loc.name} (${loc.code})`));
        availRow.appendChild(row);
        checkboxes.push(cb);
    });
    card.appendChild(availRow);

    // Save button + status pill anchor
    const saveRow = document.createElement('div');
    saveRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Save language settings';
    saveBtn.className = 'btn btn-primary';

    const pillAnchor = document.createElement('span');

    saveBtn.addEventListener('click', async () => {
        const chosenDefault = defSelect.value;
        const chosenAvailable = checkboxes.filter(c => c.checked).map(c => c.value);

        if (chosenAvailable.length === 0) {
            showStatusPill(pillAnchor, 'Select at least one available language.', 'error');
            return;
        }
        if (!chosenAvailable.includes(chosenDefault)) {
            showStatusPill(pillAnchor, 'Default language must be in the available languages list.', 'error');
            return;
        }

        saveBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=set_language_setting', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken(),
                },
                body: JSON.stringify({
                    default_language:    chosenDefault,
                    available_languages: chosenAvailable,
                }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(pillAnchor, 'Language settings saved.', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings.', 'error');
            }
        } catch (e) {
            showStatusPill(pillAnchor, 'Request failed.', 'error');
        }
        saveBtn.disabled = false;
    });

    saveRow.appendChild(saveBtn);
    saveRow.appendChild(pillAnchor);
    card.appendChild(saveRow);

    workspaceEl.appendChild(card);

    // ── Info card ──────────────────────────────────────────────────────────

    const infoCard = document.createElement('div');
    infoCard.style.cssText = 'padding:14px 18px; background:#F4F7F9; border:1px solid #CBD5E1; border-radius:8px; font-size:13px; color:#64748B; max-width:540px;';
    infoCard.innerHTML = '<strong style="display:block; margin-bottom:6px; color:#64748B;">How language detection works</strong>'
        + '<ol style="margin:0; padding-left:18px; line-height:1.8;">'
        + '<li>User selects language via URL <code>?lang=xx</code> → stored in session</li>'
        + '<li>User\'s personal preference from <code>spw_users.locale</code> (if set)</li>'
        + '<li>Browser <code>Accept-Language</code> header</li>'
        + '<li><strong>Default language</strong> from this settings page</li>'
        + '<li>Fallback: <code>en</code></li>'
        + '</ol>'
        + '<p style="margin:10px 0 0; color:#64748B;">Add new language: create <code>languages/xx.json</code> — it appears here automatically.</p>';
    workspaceEl.appendChild(infoCard);
}
