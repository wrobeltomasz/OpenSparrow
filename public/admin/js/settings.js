// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// admin/js/settings.js — General settings page (renderSettingsPage): loads/saves app + chat-bubble + custom-logo settings via api.php (get/set_*_setting, upload_logo, remove_logo).
import { showStatusPill } from './app.js';
import { createPageHeader, buildInnerTabs } from './ui.js';
import { getCsrfToken } from '../../assets/js/util/csrf.js';

export async function renderSettingsPage(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '<h3>Loading settings…</h3>';

    let data, bubbleData, logoData;
    try {
        const [langRes, bubbleRes, logoRes] = await Promise.all([
            fetch('api.php?action=get_language_setting'),
            fetch('api.php?action=get_chat_bubble_setting'),
            fetch('api.php?action=get_logo_setting'),
        ]);
        if (!langRes.ok) throw new Error('HTTP ' + langRes.status);
        data       = await langRes.json();
        bubbleData = bubbleRes.ok ? await bubbleRes.json() : { chat_bubble_enabled: false };
        logoData   = logoRes.ok ? await logoRes.json() : { logo_path: null };
    } catch (e) {
        workspaceEl.innerHTML = '<h3 style="color:var(--danger);">Error loading settings. Check server logs.</h3>';
        return;
    }

    workspaceEl.innerHTML = '';

    workspaceEl.appendChild(createPageHeader('Application Settings'));

    const tabsContainer = document.createElement('div');
    workspaceEl.appendChild(tabsContainer);
    const [languagePanel, chatBubblePanel, brandingPanel] = buildInnerTabs(tabsContainer, [
        { label: 'Language' },
        { label: 'Chat Bubble' },
        { label: 'Branding' },
    ]);

    // ── Language Settings card ─────────────────────────────────────────────

    const card = document.createElement('div');
    card.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const cardTitle = document.createElement('h4');
    cardTitle.style.cssText = 'margin:0 0 4px; font-size:15px;';
    cardTitle.textContent = 'Language Settings';
    card.appendChild(cardTitle);

    const cardDesc = document.createElement('p');
    cardDesc.style.cssText = 'color:var(--muted); font-size:13px; margin:0 0 20px;';
    cardDesc.textContent = 'Set the site-wide default language and which languages users can switch to. Language files live in languages/*.json.';
    card.appendChild(cardDesc);

    // Default language select
    const defRow = document.createElement('div');
    defRow.style.cssText = 'margin-bottom:18px;';

    const defLabel = document.createElement('label');
    defLabel.htmlFor = 'setting-default-lang';
    defLabel.style.cssText = 'display:block; font-size:13px; font-weight:600; color:var(--muted); margin-bottom:6px;';
    defLabel.textContent = 'Default language';
    defRow.appendChild(defLabel);

    const defSelect = document.createElement('select');
    defSelect.id = 'setting-default-lang';
    defSelect.style.cssText = 'padding:7px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; width:220px; background:white;';
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
    availLabel.style.cssText = 'font-size:13px; font-weight:600; color:var(--muted); margin-bottom:8px;';
    availLabel.textContent = 'Available languages';
    availRow.appendChild(availLabel);

    const checkboxes = [];
    data.all_locales.forEach(loc => {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer; font-size:14px; color:var(--muted);';

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

    languagePanel.appendChild(card);

    // ── AI Chat Bubble card ────────────────────────────────────────────────

    const bubbleCard = document.createElement('div');
    bubbleCard.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const bubbleTitle = document.createElement('h4');
    bubbleTitle.style.cssText = 'margin:0 0 4px; font-size:15px;';
    bubbleTitle.textContent = 'AI Chat Bubble';
    bubbleCard.appendChild(bubbleTitle);

    const bubbleDesc = document.createElement('p');
    bubbleDesc.style.cssText = 'color:var(--muted); font-size:13px; margin:0 0 20px;';
    bubbleDesc.textContent = 'Show a floating chat button in the bottom-right corner of every app page. Users can click it to open the AI assistant without going through the user menu.';
    bubbleCard.appendChild(bubbleDesc);

    const toggleRow = document.createElement('label');
    toggleRow.style.cssText = 'display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:var(--muted); margin-bottom:20px;';

    const toggleCb = document.createElement('input');
    toggleCb.type    = 'checkbox';
    toggleCb.id      = 'setting-chat-bubble';
    toggleCb.checked = !!(bubbleData.chat_bubble_enabled);
    toggleCb.style.cssText = 'width:16px; height:16px; cursor:pointer;';

    toggleRow.appendChild(toggleCb);
    toggleRow.appendChild(document.createTextNode('Enable floating chat button'));
    bubbleCard.appendChild(toggleRow);

    const bubbleSaveRow = document.createElement('div');
    bubbleSaveRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const bubbleSaveBtn = document.createElement('button');
    bubbleSaveBtn.textContent = 'Save';
    bubbleSaveBtn.className = 'btn btn-primary';

    const bubblePillAnchor = document.createElement('span');

    bubbleSaveBtn.addEventListener('click', async () => {
        bubbleSaveBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=set_chat_bubble_setting', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ chat_bubble_enabled: toggleCb.checked }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(bubblePillAnchor, 'Saved. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(bubblePillAnchor, result.error || 'Error saving setting.', 'error');
            }
        } catch (e) {
            showStatusPill(bubblePillAnchor, 'Request failed.', 'error');
        }
        bubbleSaveBtn.disabled = false;
    });

    bubbleSaveRow.appendChild(bubbleSaveBtn);
    bubbleSaveRow.appendChild(bubblePillAnchor);
    bubbleCard.appendChild(bubbleSaveRow);

    chatBubblePanel.appendChild(bubbleCard);

    // ── Custom Logo card ────────────────────────────────────────────────────

    const logoCard = document.createElement('div');
    logoCard.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const logoTitle = document.createElement('h4');
    logoTitle.style.cssText = 'margin:0 0 4px; font-size:15px;';
    logoTitle.textContent = 'Custom Logo';
    logoCard.appendChild(logoTitle);

    const logoDesc = document.createElement('p');
    logoDesc.style.cssText = 'color:var(--muted); font-size:13px; margin:0 0 16px;';
    logoDesc.textContent = 'Replace the default OpenSparrow logo shown in the frontend header with your own image. PNG, JPEG or WEBP, up to 2 MB.';
    logoCard.appendChild(logoDesc);

    // App name — shown as the heading on the login page in place of "OpenSparrow"
    const appNameRow = document.createElement('div');
    appNameRow.style.cssText = 'margin-bottom:20px;';

    const appNameLabel = document.createElement('label');
    appNameLabel.htmlFor = 'setting-app-name';
    appNameLabel.style.cssText = 'display:block; font-size:13px; font-weight:600; color:var(--muted); margin-bottom:6px;';
    appNameLabel.textContent = 'Application name (shown on the login page)';
    appNameRow.appendChild(appNameLabel);

    const appNameInputRow = document.createElement('div');
    appNameInputRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const appNameInput = document.createElement('input');
    appNameInput.type = 'text';
    appNameInput.id = 'setting-app-name';
    appNameInput.maxLength = 60;
    appNameInput.value = logoData.app_name || 'OpenSparrow';
    appNameInput.style.cssText = 'padding:7px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; width:260px;';
    appNameInputRow.appendChild(appNameInput);

    const appNameSaveBtn = document.createElement('button');
    appNameSaveBtn.textContent = 'Save';
    appNameSaveBtn.className = 'btn btn-primary';
    appNameInputRow.appendChild(appNameSaveBtn);

    const appNamePillAnchor = document.createElement('span');
    appNameInputRow.appendChild(appNamePillAnchor);

    appNameSaveBtn.addEventListener('click', async () => {
        const chosenName = appNameInput.value.trim();
        if (!chosenName) {
            showStatusPill(appNamePillAnchor, 'App name cannot be empty.', 'error');
            return;
        }
        appNameSaveBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=set_app_name', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ app_name: chosenName }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(appNamePillAnchor, 'Saved.', 'success');
            } else {
                showStatusPill(appNamePillAnchor, result.error || 'Error saving app name.', 'error');
            }
        } catch (e) {
            showStatusPill(appNamePillAnchor, 'Request failed.', 'error');
        }
        appNameSaveBtn.disabled = false;
    });

    appNameRow.appendChild(appNameInputRow);
    logoCard.appendChild(appNameRow);

    const logoEnabledRow = document.createElement('label');
    logoEnabledRow.style.cssText = 'display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:var(--muted); margin-bottom:16px;';

    const logoEnabledCb = document.createElement('input');
    logoEnabledCb.type = 'checkbox';
    logoEnabledCb.id = 'setting-logo-enabled';
    logoEnabledCb.checked = !!(logoData.logo_enabled);
    logoEnabledCb.style.cssText = 'width:16px; height:16px; cursor:pointer;';

    logoEnabledRow.appendChild(logoEnabledCb);
    logoEnabledRow.appendChild(document.createTextNode('Show logo in header (unchecked = no logo, as before this feature)'));
    logoCard.appendChild(logoEnabledRow);

    const logoEnabledSaveRow = document.createElement('div');
    logoEnabledSaveRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:20px;';

    const logoEnabledSaveBtn = document.createElement('button');
    logoEnabledSaveBtn.textContent = 'Save';
    logoEnabledSaveBtn.className = 'btn btn-primary';

    const logoEnabledPillAnchor = document.createElement('span');

    logoEnabledSaveBtn.addEventListener('click', async () => {
        logoEnabledSaveBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=set_logo_enabled', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({ logo_enabled: logoEnabledCb.checked }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                showStatusPill(logoEnabledPillAnchor, 'Saved. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoEnabledPillAnchor, result.error || 'Error saving setting.', 'error');
            }
        } catch (e) {
            showStatusPill(logoEnabledPillAnchor, 'Request failed.', 'error');
        }
        logoEnabledSaveBtn.disabled = false;
    });

    logoEnabledSaveRow.appendChild(logoEnabledSaveBtn);
    logoEnabledSaveRow.appendChild(logoEnabledPillAnchor);
    logoCard.appendChild(logoEnabledSaveRow);

    const logoPreview = document.createElement('img');
    logoPreview.style.cssText = 'max-height:60px; max-width:220px; display:' + (logoData.logo_path ? 'block' : 'none') + '; border:1px solid var(--border); border-radius:4px; padding:6px; margin-bottom:16px;';
    if (logoData.logo_path) logoPreview.src = logoData.logo_path + '?t=' + Date.now();
    logoCard.appendChild(logoPreview);

    const logoFileInput = document.createElement('input');
    logoFileInput.type = 'file';
    logoFileInput.accept = 'image/png,image/jpeg,image/webp';
    logoFileInput.style.cssText = 'margin-bottom:16px; display:block;';
    logoCard.appendChild(logoFileInput);

    const logoBtnRow = document.createElement('div');
    logoBtnRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const logoUploadBtn = document.createElement('button');
    logoUploadBtn.textContent = 'Upload logo';
    logoUploadBtn.className = 'btn btn-primary';

    const logoRemoveBtn = document.createElement('button');
    logoRemoveBtn.textContent = 'Remove logo';
    logoRemoveBtn.className = 'btn';
    logoRemoveBtn.style.display = logoData.logo_path ? 'inline-block' : 'none';

    const logoPillAnchor = document.createElement('span');

    logoUploadBtn.addEventListener('click', async () => {
        const chosenFile = logoFileInput.files[0];
        if (!chosenFile) {
            showStatusPill(logoPillAnchor, 'Choose a file first.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('file', chosenFile);

        logoUploadBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=upload_logo', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCsrfToken() },
                body: formData,
            });
            const result = await res.json();
            if (result.status === 'success') {
                logoPreview.src = result.logo_path + '?t=' + Date.now();
                logoPreview.style.display = 'block';
                logoRemoveBtn.style.display = 'inline-block';
                logoFileInput.value = '';
                logoEnabledCb.checked = true;
                showStatusPill(logoPillAnchor, 'Logo uploaded and enabled. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoPillAnchor, result.error || 'Error uploading logo.', 'error');
            }
        } catch (e) {
            showStatusPill(logoPillAnchor, 'Request failed.', 'error');
        }
        logoUploadBtn.disabled = false;
    });

    logoRemoveBtn.addEventListener('click', async () => {
        logoRemoveBtn.disabled = true;
        try {
            const res = await fetch('api.php?action=remove_logo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                body: JSON.stringify({}),
            });
            const result = await res.json();
            if (result.status === 'success') {
                logoPreview.style.display = 'none';
                logoRemoveBtn.style.display = 'none';
                logoEnabledCb.checked = false;
                showStatusPill(logoPillAnchor, 'Logo removed. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoPillAnchor, result.error || 'Error removing logo.', 'error');
            }
        } catch (e) {
            showStatusPill(logoPillAnchor, 'Request failed.', 'error');
        }
        logoRemoveBtn.disabled = false;
    });

    logoBtnRow.appendChild(logoUploadBtn);
    logoBtnRow.appendChild(logoRemoveBtn);
    logoBtnRow.appendChild(logoPillAnchor);
    logoCard.appendChild(logoBtnRow);

    brandingPanel.appendChild(logoCard);

    // ── Info card ──────────────────────────────────────────────────────────

    const infoCard = document.createElement('div');
    infoCard.style.cssText = 'padding:14px 18px; background:var(--bg); border:1px solid var(--border); border-radius:8px; font-size:13px; color:var(--muted); max-width:540px;';
    infoCard.innerHTML = '<strong style="display:block; margin-bottom:6px; color:var(--muted);">How language detection works</strong>'
        + '<ol style="margin:0; padding-left:18px; line-height:1.8;">'
        + '<li>User selects language via URL <code>?lang=xx</code> → stored in session</li>'
        + '<li>User\'s personal preference from <code>spw_users.locale</code> (if set)</li>'
        + '<li>Browser <code>Accept-Language</code> header</li>'
        + '<li><strong>Default language</strong> from this settings page</li>'
        + '<li>Fallback: <code>en</code></li>'
        + '</ol>'
        + '<p style="margin:10px 0 0; color:var(--muted);">Add new language: create <code>languages/xx.json</code> — it appears here automatically.</p>';
    languagePanel.appendChild(infoCard);
}
