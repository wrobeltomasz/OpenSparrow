<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

if (file_exists(__DIR__ . '/../config/database.json')) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/i18n.php';
$lang = htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8');

$e = static fn(string $k, array $v = []): string => htmlspecialchars(t($k, $v), ENT_QUOTES, 'UTF-8');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

                <div id="schema-exists-box" style="background: var(--warn-light); padding: 12px; border-radius: var(--radius); border-left: 3px solid var(--warn); font-size: 13px; color: var(--text); margin-top: 8px; display: none;">
                    <strong id="schema-exists-text" style="display: block; margin-bottom: 8px;"></strong>
                    <div class="checkbox-group">
                        <input type="checkbox" id="drop-schema">
                        <label for="drop-schema"><?= $e('setup.drop_schema') ?></label>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="install-demo">
                    <label for="install-demo"><?= $e('setup.install_demo') ?></label>
                </div>
                <div class="help-text"><?= $e('setup.help_install_demo') ?></div>

                <div class="admin-info">
                    <strong><?= $e('setup.admin_default') ?></strong>
                    <div><?= $e('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $e('setup.password_colon') ?> <?= $e('setup.admin_pwd_note') ?></div>
                </div>

                <div style="background: var(--accent-light); padding: 12px; border-radius: var(--radius); border-left: 3px solid var(--accent); font-size: 13px; color: var(--accent);">
                    <strong style="display: block; margin-bottom: 4px;">⚠ <?= $e('setup.important') ?></strong>
                    <?= $e('setup.important_text') ?>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(2)"><?= $e('setup.back') ?></button>
                    <button type="button" class="primary" onclick="nextStep(4)"><?= $e('setup.next') ?></button>
                </div>
            </div>

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

            <div class="setup-step" id="step-5">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✓</div>
                    <h2 style="font-size: 20px; color: var(--ok); margin: 0 0 8px 0;"><?= $e('setup.complete_title') ?></h2>
                    <p style="color: var(--muted); margin: 0 0 24px 0;"><?= $e('setup.complete_sub') ?></p>
                </div>

                <div class="admin-info" id="admin-info">
                    <strong><?= $e('setup.admin_created') ?></strong>
                    <div><?= $e('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $e('setup.password_colon') ?> <code id="created-admin-password"></code></div>
                </div>

                <div id="admin-account-note" class="status-message" hidden></div>

                <div id="demo-install-msg" class="status-message" hidden></div>

                <div style="background: var(--accent-light); padding: 12px; border-radius: var(--radius); border-left: 3px solid var(--accent); font-size: 13px; color: var(--accent); margin-bottom: 20px;">
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
        window.SETUP_TEXT = <?php echo json_encode([
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
            'demo_installed'    => t('setup.demo_installed'),
            'demo_failed_prefix' => t('setup.demo_failed_prefix'),
            'schema_exists_text' => t('setup.schema_exists_text', ['schema' => '{schema}']),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script type="module" src="assets/js/setup.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/setup.js'); ?>"></script>
</body>
</html>
