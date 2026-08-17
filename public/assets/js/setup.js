// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const TEXT = window.SETUP_TEXT;
let currentStep = 1;
let connectionValid = false;
const dbData = {
    host: '',
    port: '',
    dbname: '',
    user: '',
    password: '',
    schema: '',
    connSchemas: []
};

function checkSchemaExists() {
    const box = document.getElementById('schema-exists-box');
    const schema = document.getElementById('db-schema').value.trim();
    if (schema && dbData.connSchemas.includes(schema)) {
        box.style.display = '';
        document.getElementById('schema-exists-text').textContent = TEXT.schema_exists_text.replace('{schema}', schema);
    } else {
        box.style.display = 'none';
        document.getElementById('drop-schema').checked = false;
    }
}

document.getElementById('db-schema').addEventListener('input', checkSchemaExists);

function nextStep(step) {
    if (step === 3 && !connectionValid) {
        showMessage('status-message-2', TEXT.test_first, 'error');
        return;
    }
    currentStep = step;
    updateDisplay();
    if (step === 3) {
        checkSchemaExists();
    }
    if (step === 4) {
        updateSummary();
    }
    window.scrollTo(0, 0);
}

function previousStep(step) {
    currentStep = step;
    updateDisplay();
    window.scrollTo(0, 0);
}

function updateDisplay() {
    document.querySelectorAll('.setup-step').forEach(element => element.classList.remove('active'));
    document.getElementById('step-' + currentStep).classList.add('active');
    document.getElementById('step-counter').textContent = currentStep <= 4 ? TEXT.step_of.replace('{current}', currentStep) : TEXT.complete_short;
}

function testConnection() {
    const button = document.getElementById('test-btn');
    const status = document.getElementById('connection-status');
    const message = document.getElementById('connection-message');
    const nextButton = document.getElementById('next-btn-2');

    dbData.host = document.getElementById('db-host').value;
    dbData.port = document.getElementById('db-port').value;
    dbData.dbname = document.getElementById('db-name').value;
    dbData.user = document.getElementById('db-user').value;
    dbData.password = document.getElementById('db-password').value;

    if (!dbData.host || !dbData.port || !dbData.dbname || !dbData.user) {
        showMessage('status-message-2', TEXT.fill_required, 'error');
        return;
    }

    button.disabled = true;
    message.innerHTML = '<span class="spinner"></span>' + TEXT.checking;
    status.classList.add('show');
    nextButton.disabled = true;
    connectionValid = false;

    fetch('setup_api.php?action=test_connection', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            host: dbData.host,
            port: dbData.port,
            dbname: dbData.dbname,
            user: dbData.user,
            password: dbData.password
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            status.classList.remove('error');
            status.classList.add('success');
            message.innerHTML = '<span class="status-icon success"></span>' + TEXT.conn_success;
            connectionValid = true;
            dbData.connSchemas = data.schemas || [];
            nextButton.disabled = false;
            showMessage('status-message-2', '', '');
        } else {
            status.classList.remove('success');
            status.classList.add('error');
            message.innerHTML = '<span class="status-icon error"></span>' + (data.message || TEXT.conn_failed);
            connectionValid = false;
            nextButton.disabled = true;
            showMessage('status-message-2', data.message || TEXT.conn_failed, 'error');
        }
    })
    .catch(error => {
        status.classList.remove('success');
        status.classList.add('error');
        message.innerHTML = '<span class="status-icon error"></span>' + TEXT.network_error;
        connectionValid = false;
        nextButton.disabled = true;
        showMessage('status-message-2', TEXT.network_error_msg.replace('{msg}', error.message), 'error');
    })
    .finally(() => {
        button.disabled = false;
    });
}

function updateSummary() {
    dbData.schema = document.getElementById('db-schema').value;

    document.getElementById('summary-host').textContent = dbData.host;
    document.getElementById('summary-port').textContent = dbData.port;
    document.getElementById('summary-db').textContent = dbData.dbname;
    document.getElementById('summary-user').textContent = dbData.user;
    document.getElementById('summary-schema').textContent = dbData.schema;
}

function initializeDatabase() {
    const button = document.getElementById('init-btn');
    const backButton = document.getElementById('back-btn-4');

    button.disabled = true;
    backButton.disabled = true;
    button.innerHTML = '<span class="spinner"></span>' + TEXT.initializing;

    fetch('setup_api.php?action=init_database', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            host: dbData.host,
            port: dbData.port,
            dbname: dbData.dbname,
            user: dbData.user,
            password: dbData.password,
            schema: dbData.schema,
            create_schema: document.getElementById('create-schema').checked,
            drop_schema: document.getElementById('drop-schema').checked,
            install_demo: document.getElementById('install-demo').checked
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const hasAdmin = !!data.admin_password;
            document.getElementById('admin-info').hidden = !hasAdmin;
            document.getElementById('created-admin-password').textContent = data.admin_password || '';
            const adminNote = document.getElementById('admin-account-note');
            adminNote.hidden = hasAdmin;
            if (!hasAdmin) {
                adminNote.textContent = data.message || '';
                adminNote.className = 'status-message show error';
            }
            const demoMessage = document.getElementById('demo-install-msg');
            if (document.getElementById('install-demo').checked) {
                demoMessage.hidden = false;
                demoMessage.textContent = data.demo_installed
                    ? TEXT.demo_installed
                    : (TEXT.demo_failed_prefix + (data.demo_error || ''));
                demoMessage.className = 'status-message show ' + (data.demo_installed ? 'success' : 'error');
            }
            currentStep = 5;
            updateDisplay();
        } else {
            showMessage('status-message-4', data.message || TEXT.init_failed, 'error');
            button.disabled = false;
            backButton.disabled = false;
            button.innerHTML = TEXT.init_btn;
        }
    })
    .catch(error => {
        showMessage('status-message-4', TEXT.network_error_msg.replace('{msg}', error.message), 'error');
        button.disabled = false;
        backButton.disabled = false;
        button.innerHTML = TEXT.init_btn;
    });
}

function showMessage(elementId, message, type) {
    const element = document.getElementById(elementId);
    if (!message) {
        element.classList.remove('show');
        return;
    }
    element.textContent = message;
    element.className = 'status-message show ' + type;
}

window.nextStep = nextStep;
window.previousStep = previousStep;
window.testConnection = testConnection;
window.initializeDatabase = initializeDatabase;
