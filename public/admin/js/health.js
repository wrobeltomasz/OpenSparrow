// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { apiFetch } from '../../assets/js/util/api.js';
import { buildInnerTabs, createPageHeader, el } from './ui.js';

function card(title, isOk, message) {
    const cardEl = el('div', `health-card ${isOk ? 'health-card-ok' : 'health-card-fail'}`);
    const titleEl = el('strong', 'health-card-title', `${isOk ? '[OK]' : '[FAIL]'} ${title}`);
    const messageEl = el('span', '', '');
    messageEl.innerHTML = message;
    cardEl.appendChild(titleEl);
    cardEl.appendChild(messageEl);
    return cardEl;
}

function section(container, title) {
    container.appendChild(el('h4', 'health-section-title', title));
}

async function renderEnvironmentPanel(panel) {
    panel.innerHTML = `<h3>Checking system status...</h3>`;

    let data;
    try {
        const result = await apiFetch('api.php?action=health');
        data = await result.json();
    } catch (error) {
        panel.innerHTML = `<h3 style="color:var(--error);">Error loading diagnostics. Check server logs.</h3>`;
        return;
    }

    panel.replaceChildren();

    const infoBar = el('div', 'adm-sec-card');
    const infoBody = el('div', 'adm-sec-body');
    infoBody.innerHTML = `<strong>OpenSparrow</strong>&nbsp;&nbsp;v${data.app_version}`;
    infoBar.appendChild(infoBody);
    panel.appendChild(infoBar);

    section(panel, 'PHP Environment');
    panel.appendChild(card('PHP Version', data.php_version_ok,
        `Detected: <strong>${data.php_version}</strong> — required: PHP &gt;= 8.4`));
    panel.appendChild(card('memory_limit', data.memory_limit_ok,
        `Current: <strong>${data.memory_limit}</strong> — minimum: 64M`));
    panel.appendChild(card('upload_max_filesize', data.upload_max_filesize_ok,
        `Current: <strong>${data.upload_max_filesize}</strong> — minimum: 8M`));
    panel.appendChild(card('display_errors = Off', data.display_errors_off,
        data.display_errors_off ? 'Disabled — correct for production.' : 'Should be Off in production to avoid leaking error details.'));

    section(panel, 'PHP Extensions');
    panel.appendChild(card('ext/pgsql', data.pgsql_ok,
        data.pgsql_ok ? 'PostgreSQL driver active.' : 'Missing — enable pgsql in php.ini.'));
    panel.appendChild(card('ext/json', data.json_ok,
        data.json_ok ? 'JSON encode/decode available.' : 'Missing — required for config files.'));
    panel.appendChild(card('ext/session', data.session_ok,
        data.session_ok ? 'Session handling active.' : 'Missing — required for authentication.'));
    panel.appendChild(card('ext/mbstring', data.mbstring_ok,
        data.mbstring_ok ? 'Multibyte string support active.' : 'Missing — required for text handling.'));
    panel.appendChild(card('ext/fileinfo', data.fileinfo_ok,
        data.fileinfo_ok ? 'MIME type detection active.' : 'Missing — required for file uploads.'));
    panel.appendChild(card('ext/openssl', data.openssl_ok,
        data.openssl_ok ? 'OpenSSL active.' : 'Missing — required for CSRF token generation.'));

    section(panel, 'Security Functions');
    panel.appendChild(card('PASSWORD_ARGON2ID', data.argon2id_ok,
        data.argon2id_ok ? 'Argon2id hashing available.' : 'Not available — libargon2 not compiled in. Login will fail.'));
    panel.appendChild(card('random_bytes()', data.random_bytes_ok,
        data.random_bytes_ok ? 'Cryptographic randomness available.' : 'Missing — CSRF tokens cannot be generated.'));
    panel.appendChild(card('hash_equals()', data.hash_equals_ok,
        data.hash_equals_ok ? 'Timing-safe comparison available.' : 'Missing — CSRF validation will not work.'));
    panel.appendChild(card('bin2hex()', data.bin2hex_ok,
        data.bin2hex_ok ? 'Token hex encoding available.' : 'Missing.'));

    section(panel, 'Database');
    panel.appendChild(card('PostgreSQL Connection', data.db_connected,
        data.db_connected
            ? `Connected: <strong>PostgreSQL ${data.pg_version}</strong>`
            : `Connection failed: <strong>${data.db_error}</strong> — check database.json.`));

    section(panel, 'Filesystem');
    panel.appendChild(card('includes/ writable', data.dir_writable,
        data.dir_writable ? 'Config JSON files can be saved.' : 'Not writable — chmod 755 on includes/.'));
    panel.appendChild(card('storage/ writable', data.storage_writable,
        data.storage_writable ? 'Upload root directory is writable.' : 'Not writable — chmod 755 on storage/.'));
    panel.appendChild(card('storage/files/ writable', data.storage_files_writable,
        data.storage_files_writable ? 'Upload directory is writable.' : 'Not writable — chmod 755 on storage/files/.'));

    section(panel, 'Config Files');
    panel.appendChild(card('config/database.json', data.database_json_ok,
        data.database_json_ok ? 'Present and valid JSON.' : 'Missing or invalid — create via FTP after first deploy.'));
    panel.appendChild(card('Schema configuration', data.schema_json_ok,
        data.schema_json_ok ? 'Present and valid.' : 'Missing — define tables in the Schema tab.'));

    if (data.db_connected) {
        const migrationsBox = el('div', 'adm-sec-card');
        const migrationsBody = el('div', 'adm-sec-body');
        migrationsBody.innerHTML = `<h4 style="margin-top:0;">Database Migrations</h4>
            <p>Use the Migrations tab to apply pending schema changes and view migration history.</p>`;
        const gotoButton = el('button', 'btn btn-primary', 'Go to Migrations');
        gotoButton.type = 'button';
        gotoButton.addEventListener('click', () => {
            const tab = document.querySelector('.admin-tab[data-file="migrations"]');
            if (tab) tab.click();
        });
        migrationsBody.appendChild(gotoButton);
        migrationsBox.appendChild(migrationsBody);
        panel.appendChild(migrationsBox);
    }
}

async function renderProductionPanel(panel) {
    panel.innerHTML = `<h3>Checking production readiness...</h3>`;

    let data;
    let migrationsPending = null;
    try {
        const result = await apiFetch('api.php?action=health_production');
        data = await result.json();
        try {
            const migrationsResult = await apiFetch('api.php?action=migrations_list');
            const migrationsData = await migrationsResult.json();
            if (migrationsData.status === 'success') {
                migrationsPending = migrationsData.migrations.filter(m => m.status === 'pending').length;
            }
        } catch (error) {
            migrationsPending = null;
        }
    } catch (error) {
        panel.innerHTML = `<h3 style="color:var(--error);">Error loading diagnostics. Check server logs.</h3>`;
        return;
    }

    panel.replaceChildren();

    const envBar = el('div', 'adm-sec-card');
    const envBody = el('div', 'adm-sec-body');
    envBody.innerHTML = `Current <strong>APP_ENV</strong>: <strong>${data.app_env}</strong>`;
    envBar.appendChild(envBody);
    panel.appendChild(envBar);

    section(panel, 'Security');
    panel.appendChild(card('DEMO_MODE = false', data.demo_mode_off,
        data.demo_mode_off ? 'Write actions are not restricted by demo mode.' : 'DEMO_MODE is on — every write action is blocked. Unset it for production.'));
    panel.appendChild(card('SECURE_COOKIES = true', data.secure_cookies_on,
        data.secure_cookies_on ? 'Session cookies require HTTPS.' : 'Off — only correct on plain HTTP. Set true behind TLS.'));
    panel.appendChild(card('HTTPS detected', data.https_detected,
        data.https_detected ? 'Request reached the app over HTTPS (directly or via a forwarding header).' : 'No HTTPS signal on this request — verify TLS termination at the proxy/load balancer.'));
    panel.appendChild(card('display_errors = Off', data.display_errors_off,
        data.display_errors_off ? 'Disabled — correct for production.' : 'Should be Off in production to avoid leaking error details.'));
    panel.appendChild(card('PASSWORD_ARGON2ID', data.argon2id_ok,
        data.argon2id_ok ? 'Argon2id hashing available.' : 'Not available — libargon2 not compiled in. Login will fail.'));
    panel.appendChild(card('PASSWORD_MIN_LENGTH &gt;= 12', data.password_min_length_ok,
        `Current: <strong>${data.password_min_length}</strong> — recommended minimum: 12`));

    section(panel, 'Network & Rate Limiting');
    panel.appendChild(card('API_RATE_LIMIT_PER_MIN enabled', data.rate_limit_on,
        data.rate_limit_on
            ? `Current: <strong>${data.rate_limit_per_min}</strong> requests/min per user.`
            : 'Rate limiting is disabled (0) — every endpoint accepts unlimited requests.'));
    panel.appendChild(card('SESSION_SAMESITE valid', !!data.session_samesite,
        `Current: <strong>${data.session_samesite}</strong>`));
    if (data.trust_proxy_headers) {
        panel.appendChild(card('TRUSTED_PROXY_IPS configured', data.trusted_proxy_ips_set,
            data.trusted_proxy_ips_set
                ? 'Proxy headers are trusted only from the listed addresses.'
                : 'TRUST_PROXY_HEADERS is on but TRUSTED_PROXY_IPS is empty — every client can spoof its IP.'));
    }

    section(panel, 'Database');
    if (migrationsPending === null) {
        panel.appendChild(card('Migrations up to date', false, 'Could not load migration status — check the Migrations tab.'));
    } else {
        panel.appendChild(card('Migrations up to date', migrationsPending === 0,
            migrationsPending === 0 ? 'All known migrations are applied.' : `${migrationsPending} migration(s) pending — apply them in the Migrations tab.`));
    }
}

export async function renderHealthDashboard(context) {
    const { workspaceEl: workspaceElement } = context;
    workspaceElement.replaceChildren();

    workspaceElement._renderId = (workspaceElement._renderId || 0) + 1;
    const myId = workspaceElement._renderId;

    const wrap = el('div', 'admin-page');
    workspaceElement.appendChild(wrap);
    wrap.appendChild(createPageHeader('System Health', 'Diagnostics of the hosting environment running OpenSparrow.'));

    const panels = buildInnerTabs(wrap, [
        { label: 'Environment', icon: 'health_and_safety.png' },
        { label: 'Production Readiness', icon: 'checklist_rtl.png' },
    ]);

    if (workspaceElement._renderId !== myId) return;

    await renderEnvironmentPanel(panels[0]);
    if (workspaceElement._renderId !== myId) return;

    await renderProductionPanel(panels[1]);
}
