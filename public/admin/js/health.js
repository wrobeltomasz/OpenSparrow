// admin/js/health.js — System health dashboard (renderHealthDashboard): fetches api.php?action=health and shows the status checks.
export async function renderHealthDashboard(ctx) {
    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = `<h3>Checking system status...</h3>`;

    workspaceEl._renderId = (workspaceEl._renderId || 0) + 1;
    const myId = workspaceEl._renderId;

    try {
        const res  = await fetch('api.php?action=health');
        const data = await res.json();

        const card = (title, isOk, msg) => `
            <div style="padding:12px 16px; border-left:4px solid ${isOk ? 'var(--ok)' : 'var(--danger)'}; background:var(--panel); box-shadow:var(--shadow-sm); border-radius:4px;">
                <strong style="font-size:14px; display:block; margin-bottom:4px; color:${isOk ? 'var(--ok)' : 'var(--danger)'};">${isOk ? '[OK]' : '[FAIL]'} ${title}</strong>
                <span style="color:var(--muted); font-size:13px;">${msg}</span>
            </div>`;

        let _sectionIdx = 0;
        const section = (title) => {
            const id = `health-section-${_sectionIdx++}`;
            return `<h4 id="${id}" style="margin:24px 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted);">${title}</h4>`;
        };

        let html = `
            <div class="admin-page">
            <h2 class="admin-page-title">System Health</h2>
            <p class="admin-page-desc">Diagnostics of the hosting environment running OpenSparrow.</p>
            <div style="padding:12px 18px; background:var(--bg); border:1px solid var(--border); border-radius:8px; margin-bottom:8px; font-size:14px;">
                <strong>OpenSparrow</strong>&nbsp;&nbsp;v${data.app_version}
            </div>
            <div style="display:grid; gap:10px;">
        `;

        // --- PHP environment ---
        html += section('PHP Environment');
        html += card('PHP Version', data.php_version_ok,
            `Detected: <strong>${data.php_version}</strong> — required: PHP &gt;= 8.1`);
        html += card('memory_limit', data.memory_limit_ok,
            `Current: <strong>${data.memory_limit}</strong> — minimum: 64M`);
        html += card('upload_max_filesize', data.upload_max_filesize_ok,
            `Current: <strong>${data.upload_max_filesize}</strong> — minimum: 8M`);
        html += card('display_errors = Off', data.display_errors_off,
            data.display_errors_off ? 'Disabled — correct for production.' : 'Should be Off in production to avoid leaking error details.');

        // --- Extensions ---
        html += section('PHP Extensions');
        html += card('ext/pgsql', data.pgsql_ok,
            data.pgsql_ok ? 'PostgreSQL driver active.' : 'Missing — enable pgsql in php.ini.');
        html += card('ext/json', data.json_ok,
            data.json_ok ? 'JSON encode/decode available.' : 'Missing — required for config files.');
        html += card('ext/session', data.session_ok,
            data.session_ok ? 'Session handling active.' : 'Missing — required for authentication.');
        html += card('ext/mbstring', data.mbstring_ok,
            data.mbstring_ok ? 'Multibyte string support active.' : 'Missing — required for text handling.');
        html += card('ext/fileinfo', data.fileinfo_ok,
            data.fileinfo_ok ? 'MIME type detection active.' : 'Missing — required for file uploads.');
        html += card('ext/openssl', data.openssl_ok,
            data.openssl_ok ? 'OpenSSL active.' : 'Missing — required for CSRF token generation.');

        // --- Security functions ---
        html += section('Security Functions');
        html += card('PASSWORD_ARGON2ID', data.argon2id_ok,
            data.argon2id_ok ? 'Argon2id hashing available.' : 'Not available — libargon2 not compiled in. Login will fail.');
        html += card('random_bytes()', data.random_bytes_ok,
            data.random_bytes_ok ? 'Cryptographic randomness available.' : 'Missing — CSRF tokens cannot be generated.');
        html += card('hash_equals()', data.hash_equals_ok,
            data.hash_equals_ok ? 'Timing-safe comparison available.' : 'Missing — CSRF validation will not work.');
        html += card('bin2hex()', data.bin2hex_ok,
            data.bin2hex_ok ? 'Token hex encoding available.' : 'Missing.');

        // --- Database ---
        html += section('Database');
        html += card('PostgreSQL Connection', data.db_connected,
            data.db_connected
                ? `Connected: <strong>PostgreSQL ${data.pg_version}</strong>`
                : `Connection failed: <strong>${data.db_error}</strong> — check database.json.`);
        // --- Filesystem ---
        html += section('Filesystem');
        html += card('includes/ writable', data.dir_writable,
            data.dir_writable ? 'Config JSON files can be saved.' : 'Not writable — chmod 755 on includes/.');
        html += card('storage/ writable', data.storage_writable,
            data.storage_writable ? 'Upload root directory is writable.' : 'Not writable — chmod 755 on storage/.');
        html += card('storage/files/ writable', data.storage_files_writable,
            data.storage_files_writable ? 'Upload directory is writable.' : 'Not writable — chmod 755 on storage/files/.');

        // --- Config files ---
        html += section('Config Files');
        html += card('config/database.json', data.database_json_ok,
            data.database_json_ok ? 'Present and valid JSON.' : 'Missing or invalid — create via FTP after first deploy.');
        html += card('config/schema.json', data.schema_json_ok,
            data.schema_json_ok ? 'Present and valid JSON.' : 'Missing — define tables in the Schema tab.');
        html += card('config/security.json', data.security_json_ok,
            data.security_json_ok ? 'Present and valid JSON.' : 'Missing — create via admin Security tab.');

        html += `</div>`;

        // --- First time setup ---
        if (data.db_connected) {
            html += `
                <div style="margin-top:30px; padding:20px; background:var(--bg); border:1px dashed var(--accent); border-radius:8px;">
                    <h4 style="margin-top:0; color:var(--text);">Database Migrations</h4>
                    <p style="font-size:14px; color:var(--text);">Use the Migrations tab to apply pending schema changes and view migration history.</p>
                    <button id="goto-migrations-btn" class="btn btn-primary">Go to Migrations</button>
                </div>`;
        }

        html += `</div>`; // /.admin-page

        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = html;

        const gotoBtn = document.getElementById('goto-migrations-btn');
        if (gotoBtn) {
            gotoBtn.addEventListener('click', () => {
                const tab = document.querySelector('.admin-tab[data-file="migrations"]');
                if (tab) tab.click();
            });
        }

    } catch (e) {
        if (workspaceEl._renderId !== myId) return;
        workspaceEl.innerHTML = `<h3 style="color:var(--danger);">Error loading diagnostics. Check server logs.</h3>`;
    }
}
