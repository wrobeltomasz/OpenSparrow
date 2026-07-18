<?php

// setup.php — First-run database configuration wizard (HTML, standalone)
// Intentionally standalone (no config.php / db require) so it runs before config/database.json exists
// Aborts to login.php if database.json already exists; sets its own security headers (X-Frame-Options, CSP, etc.)
// Renders a 4-step wizard (welcome -> DB connection -> init -> done); all actions POST to setup_api.php

// Check if already configured
if (file_exists(__DIR__ . '/../config/database.json')) {
    header('Location: login.php');
    exit;
}

// Standalone i18n: i18n.php only loads language files from disk. Locale detection
// falls back safely to Accept-Language / 'en' when no DB/config exists yet
// (settings_value() is guarded and config.php does not open a DB connection on load).
require_once __DIR__ . '/../includes/i18n.php';
$lang = htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8');
// Escaped translation shorthand for this template.
$e = static fn(string $k, array $v = []): string => htmlspecialchars(t($k, $v), ENT_QUOTES, 'UTF-8');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// 'unsafe-inline' for <script> blocks; styles served from assets/css/ via <link>
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(t('setup.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/setup.css">
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1><?= $e('setup.title') ?></h1>
                <p><?= $e('setup.subtitle') ?></p>
            </div>

            <div class="step-counter"><span id="step-counter"><?= $e('setup.step_of', ['current' => 1]) ?></span></div>

            <!-- STEP 1: Welcome -->
            <div class="setup-step active" id="step-1">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $e('setup.welcome_title') ?></h2>
                <div class="welcome-text">
                    <p><?= $e('setup.welcome_intro') ?></p>
                    <p><?= $e('setup.welcome_need') ?></p>
                    <ul style="margin: 8px 0; padding-left: 20px;">
                        <li><?= $e('setup.welcome_need_db') ?></li>
                        <li><?= $e('setup.welcome_need_admin') ?></li>
                    </ul>
                    <p><?= $e('setup.welcome_go') ?></p>
                </div>
                <div class="button-group">
                    <button type="button" class="primary" onclick="nextStep(2)"><?= $e('setup.next') ?></button>
                </div>
            </div>

            <!-- STEP 2: Database Connection -->
            <div class="setup-step" id="step-2">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $e('setup.db_conn_title') ?></h2>
                <div id="status-message-2" class="status-message"></div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label for="db-host"><?= $e('setup.lbl_host') ?></label>
                        <input type="text" id="db-host" placeholder="localhost" value="localhost">
                        <div class="help-text"><?= $e('setup.help_host') ?></div>
                    </div>
                    <div class="form-group">
                        <label for="db-port"><?= $e('setup.lbl_port') ?></label>
                        <input type="number" id="db-port" placeholder="5432" value="5432" min="1" max="65535">
                    </div>
                </div>

                <div class="form-group">
                    <label for="db-name"><?= $e('setup.lbl_dbname') ?></label>
                    <input type="text" id="db-name" placeholder="opensparrow" value="opensparrow">
                    <div class="help-text"><?= $e('setup.help_dbname') ?></div>
                </div>

                <div class="form-group">
                    <label for="db-user"><?= $e('setup.lbl_user') ?></label>
                    <input type="text" id="db-user" placeholder="postgres" value="postgres">
                    <div class="help-text"><?= $e('setup.help_user') ?></div>
                </div>

                <div class="form-group">
                    <label for="db-password"><?= $e('setup.lbl_password') ?></label>
                    <input type="password" id="db-password" placeholder="••••••••">
                </div>

                <button type="button" class="primary" id="test-btn" style="width: 100%; margin-bottom: 16px;" onclick="testConnection()">
                    <?= $e('setup.test_conn') ?>
                </button>

                <div class="connection-status" id="connection-status">
                    <div class="status-icon"></div>
                    <div id="connection-message"><?= $e('setup.checking') ?></div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(1)"><?= $e('setup.back') ?></button>
                    <button type="button" class="primary" id="next-btn-2" disabled onclick="nextStep(3)"><?= $e('setup.next') ?></button>
                </div>
            </div>

            <!-- STEP 3: Schema & Info -->
            <div class="setup-step" id="step-3">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $e('setup.schema_title') ?></h2>

                <div class="form-group">
                    <label for="db-schema"><?= $e('setup.lbl_schema') ?></label>
                    <input type="text" id="db-schema" placeholder="app" value="app">
                    <div class="help-text"><?= $e('setup.help_schema') ?></div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="create-schema" checked>
                    <label for="create-schema"><?= $e('setup.create_schema') ?></label>
                </div>

                <div class="admin-info">
                    <strong><?= $e('setup.admin_default') ?></strong>
                    <div><?= $e('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $e('setup.password_colon') ?> <?= $e('setup.admin_pwd_note') ?></div>
                </div>

                <div style="background: var(--accent-light); padding: 12px; border-radius: var(--radius); border-left: 3px solid var(--accent); font-size: 13px; color: #003366;">
                    <strong style="display: block; margin-bottom: 4px;">⚠ <?= $e('setup.important') ?></strong>
                    <?= $e('setup.important_text') ?>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(2)"><?= $e('setup.back') ?></button>
                    <button type="button" class="primary" onclick="nextStep(4)"><?= $e('setup.next') ?></button>
                </div>
            </div>

            <!-- STEP 4: Summary & Initialize -->
            <div class="setup-step" id="step-4">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $e('setup.review_title') ?></h2>
                <div id="status-message-4" class="status-message"></div>

                <div style="background: var(--accent-light); padding: 16px; border-radius: var(--radius); margin-bottom: 20px;">
                    <div class="summary-item">
                        <div class="summary-label"><?= $e('setup.sum_host') ?></div>
                        <div class="summary-value" id="summary-host">localhost</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $e('setup.sum_port') ?></div>
                        <div class="summary-value" id="summary-port">5432</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $e('setup.sum_db') ?></div>
                        <div class="summary-value" id="summary-db">opensparrow</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $e('setup.sum_user') ?></div>
                        <div class="summary-value" id="summary-user">postgres</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $e('setup.sum_schema') ?></div>
                        <div class="summary-value" id="summary-schema">app</div>
                    </div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" id="back-btn-4" onclick="previousStep(3)"><?= $e('setup.back') ?></button>
                    <button type="button" class="primary" id="init-btn" onclick="initializeDatabase()">
                        <?= $e('setup.init_btn') ?>
                    </button>
                </div>
            </div>

            <!-- STEP 5: Complete -->
            <div class="setup-step" id="step-5">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✓</div>
                    <h2 style="font-size: 20px; color: var(--ok); margin: 0 0 8px 0;"><?= $e('setup.complete_title') ?></h2>
                    <p style="color: var(--muted); margin: 0 0 24px 0;"><?= $e('setup.complete_sub') ?></p>
                </div>

                <div class="admin-info">
                    <strong><?= $e('setup.admin_created') ?></strong>
                    <div><?= $e('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $e('setup.password_colon') ?> <code id="created-admin-password"></code></div>
                </div>

                <div style="background: #f0f6fa; padding: 12px; border-radius: var(--radius); border-left: 3px solid #0284c7; font-size: 13px; color: #003366; margin-bottom: 20px;">
                    <strong style="display: block; margin-bottom: 4px;"><?= $e('setup.next_steps') ?></strong>
                    <ol style="margin: 0; padding-left: 16px;">
                        <li><?= $e('setup.next_step_1') ?></li>
                        <li><?= $e('setup.next_step_2') ?></li>
                        <li><?= $e('setup.next_step_3') ?></li>
                    </ol>
                </div>

                <div class="button-group">
                    <button type="button" class="primary" style="flex: 1;" onclick="window.location.href = 'login.php'">
                        <?= $e('setup.go_login') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const T = <?php echo json_encode([
            'step_of'           => t('setup.step_of', ['current' => '{current}']),
            'complete_short'    => t('setup.complete_short'),
            'test_first'        => t('setup.test_first'),
            'fill_required'     => t('setup.fill_required'),
            'checking'          => t('setup.checking'),
            'conn_success'      => t('setup.conn_success'),
            'conn_failed'       => t('setup.conn_failed'),
            'network_error'     => t('setup.network_error'),
            'network_error_msg' => t('setup.network_error_msg'),
            'initializing'      => t('setup.initializing'),
            'init_failed'       => t('setup.init_failed'),
            'init_btn'          => t('setup.init_btn'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
                showMessage('status-message-2', T.test_first, 'error');
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
            document.getElementById('step-counter').textContent = currentStep <= 4 ? T.step_of.replace('{current}', currentStep) : T.complete_short;
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
                showMessage('status-message-2', T.fill_required, 'error');
                return;
            }

            btn.disabled = true;
            message.innerHTML = '<span class="spinner"></span>' + T.checking;
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
                    message.innerHTML = '<span class="status-icon success"></span>' + T.conn_success;
                    connectionValid = true;
                    nextBtn.disabled = false;
                    showMessage('status-message-2', '', '');
                } else {
                    status.classList.remove('success');
                    status.classList.add('error');
                    message.innerHTML = '<span class="status-icon error"></span>' + (data.message || T.conn_failed);
                    connectionValid = false;
                    nextBtn.disabled = true;
                    showMessage('status-message-2', data.message || T.conn_failed, 'error');
                }
            })
            .catch(err => {
                status.classList.remove('success');
                status.classList.add('error');
                message.innerHTML = '<span class="status-icon error"></span>' + T.network_error;
                connectionValid = false;
                nextBtn.disabled = true;
                showMessage('status-message-2', T.network_error_msg.replace('{msg}', err.message), 'error');
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
            btn.innerHTML = '<span class="spinner"></span>' + T.initializing;

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
                    document.getElementById('created-admin-password').textContent = data.admin_password || '';
                    currentStep = 5;
                    updateDisplay();
                } else {
                    showMessage('status-message-4', data.message || T.init_failed, 'error');
                    btn.disabled = false;
                    backBtn.disabled = false;
                    btn.innerHTML = T.init_btn;
                }
            })
            .catch(err => {
                showMessage('status-message-4', T.network_error_msg.replace('{msg}', err.message), 'error');
                btn.disabled = false;
                backBtn.disabled = false;
                btn.innerHTML = T.init_btn;
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
