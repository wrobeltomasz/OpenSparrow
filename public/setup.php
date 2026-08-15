<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\RedirectException;

os_register_exception_handler('html');

if (file_exists(__DIR__ . '/../config/database.json')) {
    throw new RedirectException('login.php');
}

require_once __DIR__ . '/../includes/i18n.php';
$lang = htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8');

$escape = static fn(string $key, array $vars = []): string => htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

header(
    "Content-Security-Policy: default-src 'self'; style-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; connect-src 'self'"
);
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
                <h1><?= $escape('setup.title') ?></h1>
                <p><?= $escape('setup.subtitle') ?></p>
            </div>

            <div class="step-counter">
                <span id="step-counter"><?= $escape('setup.step_of', ['current' => 1]) ?></span>
            </div>

            <div class="setup-step active" id="step-1">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $escape('setup.welcome_title') ?></h2>
                <div class="welcome-text">
                    <p><?= $escape('setup.welcome_intro') ?></p>
                    <p><?= $escape('setup.welcome_need') ?></p>
                    <ul style="margin: 8px 0; padding-left: 20px;">
                        <li><?= $escape('setup.welcome_need_db') ?></li>
                        <li><?= $escape('setup.welcome_need_admin') ?></li>
                    </ul>
                    <p><?= $escape('setup.welcome_go') ?></p>
                </div>
                <div class="button-group">
                    <button type="button" class="primary" onclick="nextStep(2)"><?= $escape('setup.next') ?></button>
                </div>
            </div>

            <div class="setup-step" id="step-2">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $escape('setup.db_conn_title') ?></h2>
                <div id="status-message-2" class="status-message"></div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label for="db-host"><?= $escape('setup.lbl_host') ?></label>
                        <input type="text" id="db-host" placeholder="localhost" value="localhost">
                        <div class="help-text"><?= $escape('setup.help_host') ?></div>
                    </div>
                    <div class="form-group">
                        <label for="db-port"><?= $escape('setup.lbl_port') ?></label>
                        <input type="number" id="db-port" placeholder="5432" value="5432" min="1" max="65535">
                    </div>
                </div>

                <div class="form-group">
                    <label for="db-name"><?= $escape('setup.lbl_dbname') ?></label>
                    <input type="text" id="db-name" placeholder="opensparrow" value="opensparrow">
                    <div class="help-text"><?= $escape('setup.help_dbname') ?></div>
                </div>

                <div class="form-group">
                    <label for="db-user"><?= $escape('setup.lbl_user') ?></label>
                    <input type="text" id="db-user" placeholder="postgres" value="postgres">
                    <div class="help-text"><?= $escape('setup.help_user') ?></div>
                </div>

                <div class="form-group">
                    <label for="db-password"><?= $escape('setup.lbl_password') ?></label>
                    <input type="password" id="db-password" placeholder="••••••••">
                </div>

                <button
                    type="button"
                    class="primary"
                    id="test-btn"
                    style="width: 100%; margin-bottom: 16px;"
                    onclick="testConnection()"
                >
                    <?= $escape('setup.test_conn') ?>
                </button>

                <div class="connection-status" id="connection-status">
                    <div class="status-icon"></div>
                    <div id="connection-message"><?= $escape('setup.checking') ?></div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(1)">
                        <?= $escape('setup.back') ?></button>
                    <button type="button" class="primary" id="next-btn-2" disabled onclick="nextStep(3)">
                        <?= $escape('setup.next') ?>
                    </button>
                </div>
            </div>

            <div class="setup-step" id="step-3">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $escape('setup.schema_title') ?></h2>

                <div class="form-group">
                    <label for="db-schema"><?= $escape('setup.lbl_schema') ?></label>
                    <input type="text" id="db-schema" placeholder="app" value="app">
                    <div class="help-text"><?= $escape('setup.help_schema') ?></div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="create-schema" checked>
                    <label for="create-schema"><?= $escape('setup.create_schema') ?></label>
                </div>

                <div
                    id="schema-exists-box"
                    style="background: var(--warn-light); padding: 12px; border-radius: var(--radius);
                        border-left: 3px solid var(--warn); font-size: 13px; color: var(--text);
                        margin-top: 8px; display: none;"
                >
                    <strong id="schema-exists-text" style="display: block; margin-bottom: 8px;"></strong>
                    <div class="checkbox-group">
                        <input type="checkbox" id="drop-schema">
                        <label for="drop-schema"><?= $escape('setup.drop_schema') ?></label>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="install-demo">
                    <label for="install-demo"><?= $escape('setup.install_demo') ?></label>
                </div>
                <div class="help-text"><?= $escape('setup.help_install_demo') ?></div>

                <div class="admin-info">
                    <strong><?= $escape('setup.admin_default') ?></strong>
                    <div><?= $escape('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $escape('setup.password_colon') ?> <?= $escape('setup.admin_pwd_note') ?></div>
                </div>

                <div
                    style="background: var(--accent-light); padding: 12px; border-radius: var(--radius);
                        border-left: 3px solid var(--accent); font-size: 13px; color: var(--accent);"
                >
                    <strong style="display: block; margin-bottom: 4px;">⚠ <?= $escape('setup.important') ?></strong>
                    <?= $escape('setup.important_text') ?>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" onclick="previousStep(2)">
                        <?= $escape('setup.back') ?></button>
                    <button type="button" class="primary" onclick="nextStep(4)"><?= $escape('setup.next') ?></button>
                </div>
            </div>

            <div class="setup-step" id="step-4">
                <h2 style="font-size: 16px; margin-top: 0;"><?= $escape('setup.review_title') ?></h2>
                <div id="status-message-4" class="status-message"></div>

                <div
                    style="background: var(--accent-light); padding: 16px; border-radius: var(--radius);
                        margin-bottom: 20px;"
                >
                    <div class="summary-item">
                        <div class="summary-label"><?= $escape('setup.sum_host') ?></div>
                        <div class="summary-value" id="summary-host">localhost</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $escape('setup.sum_port') ?></div>
                        <div class="summary-value" id="summary-port">5432</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $escape('setup.sum_db') ?></div>
                        <div class="summary-value" id="summary-db">opensparrow</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $escape('setup.sum_user') ?></div>
                        <div class="summary-value" id="summary-user">postgres</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label"><?= $escape('setup.sum_schema') ?></div>
                        <div class="summary-value" id="summary-schema">app</div>
                    </div>
                </div>

                <div class="button-group">
                    <button type="button" class="secondary" id="back-btn-4" onclick="previousStep(3)">
                        <?= $escape('setup.back') ?>
                    </button>
                    <button type="button" class="primary" id="init-btn" onclick="initializeDatabase()">
                        <?= $escape('setup.init_btn') ?>
                    </button>
                </div>
            </div>

            <div class="setup-step" id="step-5">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✓</div>
                    <h2 style="font-size: 20px; color: var(--ok); margin: 0 0 8px 0;">
                        <?= $escape('setup.complete_title') ?>
                    </h2>
                    <p style="color: var(--muted); margin: 0 0 24px 0;"><?= $escape('setup.complete_sub') ?></p>
                </div>

                <div class="admin-info" id="admin-info">
                    <strong><?= $escape('setup.admin_created') ?></strong>
                    <div><?= $escape('setup.username_colon') ?> <code>admin</code></div>
                    <div><?= $escape('setup.password_colon') ?> <code id="created-admin-password"></code></div>
                </div>

                <div id="admin-account-note" class="status-message" hidden></div>

                <div id="demo-install-msg" class="status-message" hidden></div>

                <div
                    style="background: var(--accent-light); padding: 12px; border-radius: var(--radius);
                        border-left: 3px solid var(--accent); font-size: 13px; color: var(--accent);
                        margin-bottom: 20px;"
                >
                    <strong style="display: block; margin-bottom: 4px;"><?= $escape('setup.next_steps') ?></strong>
                    <ol style="margin: 0; padding-left: 16px;">
                        <li><?= $escape('setup.next_step_1') ?></li>
                        <li><?= $escape('setup.next_step_2') ?></li>
                        <li><?= $escape('setup.next_step_3') ?></li>
                    </ol>
                </div>

                <div class="button-group">
                    <button type="button" class="primary" style="flex: 1;" onclick="window.location.href = 'login.php'">
                        <?= $escape('setup.go_login') ?>
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
    <script
        type="module"
        src="assets/js/setup.js?v=<?php echo asset_version(__DIR__ . '/assets/js/setup.js'); ?>"
    ></script>
</body>
</html>
