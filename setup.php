<?php

// setup.php - First-run database configuration wizard
// This file is intentionally standalone (no config.php require)
// to avoid errors when database.json doesn't exist yet.

// Check if already configured
if (file_exists(__DIR__ . '/config/database.json')) {
    header('Location: login.php');
    exit;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// 'unsafe-inline' for <script> blocks; styles served from assets/css/ via <link>
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenSparrow Setup</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/setup.css">
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1>OpenSparrow Setup</h1>
                <p>Configure your database and initialize the system</p>
            </div>

            <div class="step-counter"><span id="step-counter">Step 1 of 4</span></div>

            <!-- STEP 1: Welcome -->
            <div class="setup-step active" id="step-1">
                <h2 style="font-size: 16px; margin-top: 0;">Welcome to OpenSparrow</h2>
                <div class="welcome-text">
                    <p>This wizard will help you configure your database connection and initialize the system.</p>
                    <p>You'll need:</p>
                    <ul style="margin: 8px 0; padding-left: 20px;">
                        <li>PostgreSQL 14+ server details (host, port, database, user, password)</li>
                        <li>Administrator password (to be set after initialization)</li>
                    </ul>
                    <p>Let's get started!</p>
                </div>
                <div class="button-group">
                    <button type="button" class="primary" onclick="nextStep(2)">Next</button>
                </div>
            </div>

            <!-- STEP 2: Database Connection -->
            <div class="setup-step" id="step-2">
                <h2 style="font-size: 16px; margin-top: 0;">Database Connection</h2>
                <div id="status-message-2" class="status-message"></div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label for="db-host">Host</label>
                        <input type="text" id="db-host" placeholder="localhost" value="localhost">
                        <div class="help-text">PostgreSQL server hostname</div>
                    </div>
                    <div class="form-group">
                        <label for="db-port">Port</label>
                        <input type="number" id="db-port" placeholder="5432" value="5432" min="1" max="65535">
                    </div>
                </div>

                <div class="form-group">
                    <label for="db-name">Database Name</label>
                    <input type="text" id="db-name" placeholder="opensparrow" value="opensparrow">
                    <div class="help-text">Name of the PostgreSQL database</div>
                </div>

                <div class="form-group">
                    <label for="db-user">Username</label>
                    <input type="text" id="db-user" placeholder="postgres" value="postgres">
                    <div class="help-text">PostgreSQL user with CREATE privileges</div>
                </div>

                <div class="form-group">
                    <label for="db-password">Password</label>
                    <input type="password" id="db-password" placeholder="••••••••">
                </div>

                <button type="button" class="primary" id="test-btn" style="width: 100%; margin-bottom: 16px;" onclick="testConnection()">
                    Test Connection
                </button>

                <div class="connection-status" id="connection-status">
                    <div class="status-icon"></div>
                    <div id="connection-message">Checking connection...</div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(1)">Back</button>
                    <button type="button" class="primary" id="next-btn-2" disabled onclick="nextStep(3)">Next</button>
                </div>
            </div>

            <!-- STEP 3: Schema & Info -->
            <div class="setup-step" id="step-3">
                <h2 style="font-size: 16px; margin-top: 0;">Schema Configuration</h2>

                <div class="form-group">
                    <label for="db-schema">Schema Name</label>
                    <input type="text" id="db-schema" placeholder="app" value="app">
                    <div class="help-text">PostgreSQL schema for system tables (default: app)</div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="create-schema" checked>
                    <label for="create-schema">Create schema if it doesn't exist</label>
                </div>

                <div class="admin-info">
                    <strong>Default Admin Account</strong>
                    <div>Username: <code>admin</code></div>
                    <div>Password: <code>admin</code></div>
                </div>

                <div style="background: var(--accent-light); padding: 12px; border-radius: var(--radius); border-left: 3px solid var(--accent); font-size: 13px; color: #003366;">
                    <strong style="display: block; margin-bottom: 4px;">⚠ Important</strong>
                    You can change the admin password after logging into the admin panel. Please change it immediately for security.
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(2)">Back</button>
                    <button type="button" class="primary" onclick="nextStep(4)">Next</button>
                </div>
            </div>

            <!-- STEP 4: Summary & Initialize -->
            <div class="setup-step" id="step-4">
                <h2 style="font-size: 16px; margin-top: 0;">Review & Initialize</h2>
                <div id="status-message-4" class="status-message"></div>

                <div style="background: var(--accent-light); padding: 16px; border-radius: var(--radius); margin-bottom: 20px;">
                    <div class="summary-item">
                        <div class="summary-label">Host</div>
                        <div class="summary-value" id="summary-host">localhost</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Port</div>
                        <div class="summary-value" id="summary-port">5432</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Database</div>
                        <div class="summary-value" id="summary-db">opensparrow</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">User</div>
                        <div class="summary-value" id="summary-user">postgres</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Schema</div>
                        <div class="summary-value" id="summary-schema">app</div>
                    </div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" id="back-btn-4" onclick="previousStep(3)">Back</button>
                    <button type="button" class="primary" id="init-btn" onclick="initializeDatabase()">
                        Initialize System Tables
                    </button>
                </div>
            </div>

            <!-- STEP 5: Complete -->
            <div class="setup-step" id="step-5">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✓</div>
                    <h2 style="font-size: 20px; color: var(--ok); margin: 0 0 8px 0;">Setup Complete!</h2>
                    <p style="color: var(--muted); margin: 0 0 24px 0;">Your database has been initialized successfully.</p>
                </div>

                <div class="admin-info">
                    <strong>Admin Account Created</strong>
                    <div>Username: <code>admin</code></div>
                    <div>Password: <code>admin</code></div>
                </div>

                <div style="background: #f0f6fa; padding: 12px; border-radius: var(--radius); border-left: 3px solid #0284c7; font-size: 13px; color: #003366; margin-bottom: 20px;">
                    <strong style="display: block; margin-bottom: 4px;">Next Steps</strong>
                    <ol style="margin: 0; padding-left: 16px;">
                        <li>Log in with admin / admin</li>
                        <li>Go to Admin Panel &rarr; Users</li>
                        <li>Change the admin password immediately</li>
                    </ol>
                </div>

                <div class="button-group">
                    <button type="button" class="primary" style="flex: 1;" onclick="window.location.href = 'login.php'">
                        Go to Login
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let connectionValid = false;
        const dbData = {
            host: '',
            port: '',
            dbname: '',
            user: '',
            password: '',
            schema: ''
        };

        function nextStep(step) {
            if (step === 3 && !connectionValid) {
                showMessage('status-message-2', 'Please test the connection first', 'error');
                return;
            }
            currentStep = step;
            updateDisplay();
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
            document.querySelectorAll('.setup-step').forEach(el => el.classList.remove('active'));
            document.getElementById('step-' + currentStep).classList.add('active');
            document.getElementById('step-counter').textContent = currentStep <= 4 ? `Step ${currentStep} of 4` : 'Complete!';
        }

        function testConnection() {
            const btn = document.getElementById('test-btn');
            const status = document.getElementById('connection-status');
            const message = document.getElementById('connection-message');
            const nextBtn = document.getElementById('next-btn-2');

            dbData.host = document.getElementById('db-host').value;
            dbData.port = document.getElementById('db-port').value;
            dbData.dbname = document.getElementById('db-name').value;
            dbData.user = document.getElementById('db-user').value;
            dbData.password = document.getElementById('db-password').value;

            if (!dbData.host || !dbData.port || !dbData.dbname || !dbData.user) {
                showMessage('status-message-2', 'Please fill in all required fields', 'error');
                return;
            }

            btn.disabled = true;
            message.innerHTML = '<span class="spinner"></span>Checking connection...';
            status.classList.add('show');
            nextBtn.disabled = true;
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
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    status.classList.remove('error');
                    status.classList.add('success');
                    message.innerHTML = '<span class="status-icon success"></span>Connection successful!';
                    connectionValid = true;
                    nextBtn.disabled = false;
                    showMessage('status-message-2', '', '');
                } else {
                    status.classList.remove('success');
                    status.classList.add('error');
                    message.innerHTML = '<span class="status-icon error"></span>' + (data.message || 'Connection failed');
                    connectionValid = false;
                    nextBtn.disabled = true;
                    showMessage('status-message-2', data.message || 'Connection failed', 'error');
                }
            })
            .catch(err => {
                status.classList.remove('success');
                status.classList.add('error');
                message.innerHTML = '<span class="status-icon error"></span>Network error';
                connectionValid = false;
                nextBtn.disabled = true;
                showMessage('status-message-2', 'Network error: ' + err.message, 'error');
            })
            .finally(() => {
                btn.disabled = false;
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
            const btn = document.getElementById('init-btn');
            const backBtn = document.getElementById('back-btn-4');

            btn.disabled = true;
            backBtn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>Initializing...';

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
                    create_schema: document.getElementById('create-schema').checked
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentStep = 5;
                    updateDisplay();
                } else {
                    showMessage('status-message-4', data.message || 'Initialization failed', 'error');
                    btn.disabled = false;
                    backBtn.disabled = false;
                    btn.innerHTML = 'Initialize System Tables';
                }
            })
            .catch(err => {
                showMessage('status-message-4', 'Network error: ' + err.message, 'error');
                btn.disabled = false;
                backBtn.disabled = false;
                btn.innerHTML = 'Initialize System Tables';
            });
        }

        function showMessage(elementId, message, type) {
            const el = document.getElementById(elementId);
            if (!message) {
                el.classList.remove('show');
                return;
            }
            el.textContent = message;
            el.className = 'status-message show ' + type;
        }
    </script>
</body>
</html>
