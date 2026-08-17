// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { showStatusPill } from './app.js';
import { createPageHeader, buildInnerTabs } from './ui.js';
import { renderDatabaseSection } from './database.js';
import { renderAuditEditor } from './audit.js';

export async function renderSettingsPage(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '<h3>Loading settings…</h3>';

    let data, bubbleData, logoData;
    try {
        const [langResult, bubbleResult, logoResult] = await Promise.all([
            apiFetch('api.php?action=get_language_setting'),
            apiFetch('api.php?action=get_chat_bubble_setting'),
            apiFetch('api.php?action=get_logo_setting'),
        ]);
        if (!langResult.ok) throw new Error('HTTP ' + langResult.status);
        data       = await langResult.json();
        bubbleData = bubbleResult.ok ? await bubbleResult.json() : { chat_bubble_enabled: false };
        logoData   = logoResult.ok ? await logoResult.json() : { logo_path: null };
    } catch (error) {
        workspaceElement.innerHTML = '<h3 style="color:var(--error);">Error loading settings. Check server logs.</h3>';
        return;
    }

    workspaceElement.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'admin-page';
    workspaceElement.appendChild(wrap);

    wrap.appendChild(createPageHeader('Application Settings'));

    const [languagePanel, chatBubblePanel, brandingPanel, databasePanel, auditPanel] = buildInnerTabs(wrap, [
        { label: 'Language', icon: 'menu_book.png' },
        { label: 'Chat Bubble', icon: 'comment.png' },
        { label: 'Branding', icon: 'image.png' },
        { label: 'Database', icon: 'database.png' },
        { label: 'Audit & Snapshots', icon: 'fact_check.png' },
    ]);

    renderDatabaseSection(databasePanel);
    renderAuditEditor({ workspaceEl: auditPanel });

    const card = document.createElement('div');
    card.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const cardTitle = document.createElement('h4');
    cardTitle.style.cssText = 'margin:0 0 4px; ';
    cardTitle.textContent = 'Language Settings';
    card.appendChild(cardTitle);

    const cardDescription = document.createElement('p');
    cardDescription.style.cssText = '  margin:0 0 20px;';
    cardDescription.textContent = 'Set the site-wide default language. Language files live in languages/*.json.';
    card.appendChild(cardDescription);

    const defRow = document.createElement('div');
    defRow.style.cssText = 'margin-bottom:20px;';

    const defLabel = document.createElement('label');
    defLabel.htmlFor = 'setting-default-lang';
    defLabel.className = 'adm-field-label';
    defLabel.textContent = 'Default language';
    defRow.appendChild(defLabel);

    const defSelect = document.createElement('select');
    defSelect.id = 'setting-default-lang';
    defSelect.className = 'adm-input w-220';
    data.all_locales.forEach(locale => {
        const option = document.createElement('option');
        option.value = locale.code;
        option.textContent = `${locale.name} (${locale.code})`;
        if (locale.code === data.default_language) option.selected = true;
        defSelect.appendChild(option);
    });
    defRow.appendChild(defSelect);
    card.appendChild(defRow);

    const saveRow = document.createElement('div');
    saveRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const saveButton = document.createElement('button');
    saveButton.textContent = 'Save language settings';
    saveButton.className = 'btn btn-primary';

    const pillAnchor = document.createElement('span');

    saveButton.addEventListener('click', async () => {
        saveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=set_language_setting', {
                method: 'POST',
                body: JSON.stringify({
                    default_language: defSelect.value,
                }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(pillAnchor, 'Language settings saved.', 'success');
            } else {
                showStatusPill(pillAnchor, result.error || 'Error saving settings.', 'error');
            }
        } catch (error) {
            showStatusPill(pillAnchor, 'Request failed.', 'error');
        }
        saveButton.disabled = false;
    });

    saveRow.appendChild(saveButton);
    saveRow.appendChild(pillAnchor);
    card.appendChild(saveRow);

    languagePanel.appendChild(card);

    const bubbleCard = document.createElement('div');
    bubbleCard.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const bubbleTitle = document.createElement('h4');
    bubbleTitle.style.cssText = 'margin:0 0 4px; ';
    bubbleTitle.textContent = 'AI Chat Bubble';
    bubbleCard.appendChild(bubbleTitle);

    const bubbleDescription = document.createElement('p');
    bubbleDescription.style.cssText = '  margin:0 0 20px;';
    bubbleDescription.textContent = 'Show a floating chat button in the bottom-right corner of every app page. Users can click it to open the AI assistant without going through the user menu.';
    bubbleCard.appendChild(bubbleDescription);

    const toggleRow = document.createElement('label');
    toggleRow.style.cssText = 'display:flex; align-items:center; gap:10px; cursor:pointer;   margin-bottom:20px;';

    const toggleCallback = document.createElement('input');
    toggleCallback.type    = 'checkbox';
    toggleCallback.id      = 'setting-chat-bubble';
    toggleCallback.checked = !!(bubbleData.chat_bubble_enabled);
    toggleCallback.style.cssText = 'width:16px; height:16px; cursor:pointer;';

    toggleRow.appendChild(toggleCallback);
    toggleRow.appendChild(document.createTextNode('Enable floating chat button'));
    bubbleCard.appendChild(toggleRow);

    const bubbleSaveRow = document.createElement('div');
    bubbleSaveRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const bubbleSaveButton = document.createElement('button');
    bubbleSaveButton.textContent = 'Save';
    bubbleSaveButton.className = 'btn btn-primary';

    const bubblePillAnchor = document.createElement('span');

    bubbleSaveButton.addEventListener('click', async () => {
        bubbleSaveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=set_chat_bubble_setting', {
                method: 'POST',
                body: JSON.stringify({ chat_bubble_enabled: toggleCallback.checked }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(bubblePillAnchor, 'Saved. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(bubblePillAnchor, result.error || 'Error saving setting.', 'error');
            }
        } catch (error) {
            showStatusPill(bubblePillAnchor, 'Request failed.', 'error');
        }
        bubbleSaveButton.disabled = false;
    });

    bubbleSaveRow.appendChild(bubbleSaveButton);
    bubbleSaveRow.appendChild(bubblePillAnchor);
    bubbleCard.appendChild(bubbleSaveRow);

    chatBubblePanel.appendChild(bubbleCard);

    const logoCard = document.createElement('div');
    logoCard.style.cssText = 'padding:20px; background:white; border:1px solid var(--border); border-radius:8px; margin-bottom:24px; max-width:540px;';

    const logoTitle = document.createElement('h4');
    logoTitle.style.cssText = 'margin:0 0 4px; ';
    logoTitle.textContent = 'Custom Logo';
    logoCard.appendChild(logoTitle);

    const logoDescription = document.createElement('p');
    logoDescription.style.cssText = '  margin:0 0 16px;';
    logoDescription.textContent = 'Replace the default OpenSparrow logo shown in the frontend header with your own image. PNG, JPEG or WEBP, up to 2 MB.';
    logoCard.appendChild(logoDescription);

    const appNameRow = document.createElement('div');
    appNameRow.style.cssText = 'margin-bottom:20px;';

    const appNameLabel = document.createElement('label');
    appNameLabel.htmlFor = 'setting-app-name';
    appNameLabel.className = 'adm-field-label';
    appNameLabel.textContent = 'Application name (shown on the login page)';
    appNameRow.appendChild(appNameLabel);

    const appNameInputRow = document.createElement('div');
    appNameInputRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const appNameInput = document.createElement('input');
    appNameInput.type = 'text';
    appNameInput.id = 'setting-app-name';
    appNameInput.maxLength = 60;
    appNameInput.value = logoData.app_name || 'OpenSparrow';
    appNameInput.className = 'adm-input w-260';
    appNameInputRow.appendChild(appNameInput);

    const appNameSaveButton = document.createElement('button');
    appNameSaveButton.textContent = 'Save';
    appNameSaveButton.className = 'btn btn-primary';
    appNameInputRow.appendChild(appNameSaveButton);

    const appNamePillAnchor = document.createElement('span');
    appNameInputRow.appendChild(appNamePillAnchor);

    appNameSaveButton.addEventListener('click', async () => {
        const chosenName = appNameInput.value.trim();
        if (!chosenName) {
            showStatusPill(appNamePillAnchor, 'App name cannot be empty.', 'error');
            return;
        }
        appNameSaveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=set_app_name', {
                method: 'POST',
                body: JSON.stringify({ app_name: chosenName }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(appNamePillAnchor, 'Saved.', 'success');
            } else {
                showStatusPill(appNamePillAnchor, result.error || 'Error saving app name.', 'error');
            }
        } catch (error) {
            showStatusPill(appNamePillAnchor, 'Request failed.', 'error');
        }
        appNameSaveButton.disabled = false;
    });

    appNameRow.appendChild(appNameInputRow);
    logoCard.appendChild(appNameRow);

    const logoEnabledRow = document.createElement('label');
    logoEnabledRow.style.cssText = 'display:flex; align-items:center; gap:10px; cursor:pointer;   margin-bottom:16px;';

    const logoEnabledCallback = document.createElement('input');
    logoEnabledCallback.type = 'checkbox';
    logoEnabledCallback.id = 'setting-logo-enabled';
    logoEnabledCallback.checked = !!(logoData.logo_enabled);
    logoEnabledCallback.style.cssText = 'width:16px; height:16px; cursor:pointer;';

    logoEnabledRow.appendChild(logoEnabledCallback);
    logoEnabledRow.appendChild(document.createTextNode('Show logo in header (unchecked = no logo, as before this feature)'));
    logoCard.appendChild(logoEnabledRow);

    const logoEnabledSaveRow = document.createElement('div');
    logoEnabledSaveRow.style.cssText = 'display:flex; align-items:center; gap:12px; margin-bottom:20px;';

    const logoEnabledSaveButton = document.createElement('button');
    logoEnabledSaveButton.textContent = 'Save';
    logoEnabledSaveButton.className = 'btn btn-primary';

    const logoEnabledPillAnchor = document.createElement('span');

    logoEnabledSaveButton.addEventListener('click', async () => {
        logoEnabledSaveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=set_logo_enabled', {
                method: 'POST',
                body: JSON.stringify({ logo_enabled: logoEnabledCallback.checked }),
            });
            const result = await response.json();
            if (result.status === 'success') {
                showStatusPill(logoEnabledPillAnchor, 'Saved. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoEnabledPillAnchor, result.error || 'Error saving setting.', 'error');
            }
        } catch (error) {
            showStatusPill(logoEnabledPillAnchor, 'Request failed.', 'error');
        }
        logoEnabledSaveButton.disabled = false;
    });

    logoEnabledSaveRow.appendChild(logoEnabledSaveButton);
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

    const logoButtonRow = document.createElement('div');
    logoButtonRow.style.cssText = 'display:flex; align-items:center; gap:12px;';

    const logoUploadButton = document.createElement('button');
    logoUploadButton.textContent = 'Upload logo';
    logoUploadButton.className = 'btn btn-primary';

    const logoRemoveButton = document.createElement('button');
    logoRemoveButton.textContent = 'Remove logo';
    logoRemoveButton.className = 'btn';
    logoRemoveButton.style.display = logoData.logo_path ? 'inline-block' : 'none';

    const logoPillAnchor = document.createElement('span');

    logoUploadButton.addEventListener('click', async () => {
        const chosenFile = logoFileInput.files[0];
        if (!chosenFile) {
            showStatusPill(logoPillAnchor, 'Choose a file first.', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('file', chosenFile);

        logoUploadButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=upload_logo', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();
            if (result.status === 'success') {
                logoPreview.src = result.logo_path + '?t=' + Date.now();
                logoPreview.style.display = 'block';
                logoRemoveButton.style.display = 'inline-block';
                logoFileInput.value = '';
                logoEnabledCallback.checked = true;
                showStatusPill(logoPillAnchor, 'Logo uploaded and enabled. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoPillAnchor, result.error || 'Error uploading logo.', 'error');
            }
        } catch (error) {
            showStatusPill(logoPillAnchor, 'Request failed.', 'error');
        }
        logoUploadButton.disabled = false;
    });

    logoRemoveButton.addEventListener('click', async () => {
        logoRemoveButton.disabled = true;
        try {
            const response = await apiFetch('api.php?action=remove_logo', {
                method: 'POST',
                body: JSON.stringify({}),
            });
            const result = await response.json();
            if (result.status === 'success') {
                logoPreview.style.display = 'none';
                logoRemoveButton.style.display = 'none';
                logoEnabledCallback.checked = false;
                showStatusPill(logoPillAnchor, 'Logo removed. Reload the app to see the change.', 'success');
            } else {
                showStatusPill(logoPillAnchor, result.error || 'Error removing logo.', 'error');
            }
        } catch (error) {
            showStatusPill(logoPillAnchor, 'Request failed.', 'error');
        }
        logoRemoveButton.disabled = false;
    });

    logoButtonRow.appendChild(logoUploadButton);
    logoButtonRow.appendChild(logoRemoveButton);
    logoButtonRow.appendChild(logoPillAnchor);
    logoCard.appendChild(logoButtonRow);

    brandingPanel.appendChild(logoCard);

    const informationCard = document.createElement('div');
    informationCard.style.cssText = 'padding:14px 18px; background:var(--bg); border:1px solid var(--border); border-radius:8px;   max-width:540px;';
    informationCard.innerHTML = '<strong style="display:block; margin-bottom:6px; ">How language detection works</strong>'
        + '<ol style="margin:0; padding-left:18px; line-height:1.8;">'
        + '<li>User selects language via URL <code>?lang=xx</code> → stored in session</li>'
        + '<li>User\'s personal preference from <code>spw_users.locale</code> (if set)</li>'
        + '<li>Browser <code>Accept-Language</code> header</li>'
        + '<li><strong>Default language</strong> from this settings page</li>'
        + '<li>Fallback: <code>en</code></li>'
        + '</ol>'
        + '<p style="margin:10px 0 0; ">Add new language: create <code>languages/xx.json</code> — it appears here automatically.</p>';
    languagePanel.appendChild(informationCard);
}
