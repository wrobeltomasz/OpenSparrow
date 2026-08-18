<?php
// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$userRole  = $_SESSION['role']      ?? 'viewer';
$avatarId  = $_SESSION['avatar_id'] ?? null;
$uname     = $_SESSION['username']  ?? '';

$initial     = htmlspecialchars(mb_strtoupper(mb_substr($uname, 0, 1)), ENT_QUOTES, 'UTF-8');
$avatarColor = os_avatar_color($avatarId !== null ? (int)$avatarId : null);
$escapedUsername  = htmlspecialchars($uname, ENT_QUOTES, 'UTF-8');
$nonceAttribute = isset($cspNonce)
    ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"'
    : '';
$cacheBust = asset_version(__DIR__ . '/../public/assets/js/user-menu.js');

$toggleSidebarLabel  = htmlspecialchars(t('header.toggle_sidebar'), ENT_QUOTES, 'UTF-8');
$toggleSearchLabel   = htmlspecialchars(t('header.toggle_search'), ENT_QUOTES, 'UTF-8');
$notificationsTable  = htmlspecialchars(t('header.notifications'), ENT_QUOTES, 'UTF-8');
$adminPanelLabel     = htmlspecialchars(t('header.admin_panel'), ENT_QUOTES, 'UTF-8');
$adminTitleLabel     = htmlspecialchars(t('admin.title'), ENT_QUOTES, 'UTF-8');
$changeAvatarLabel   = htmlspecialchars(t('header.change_avatar'), ENT_QUOTES, 'UTF-8');
$changePasswordLabel = htmlspecialchars(t('auth.change_password'), ENT_QUOTES, 'UTF-8');
$agentTitleLabel     = htmlspecialchars(t('agent.title'), ENT_QUOTES, 'UTF-8');
$myRecordsLabel      = htmlspecialchars(t('header.my_records'), ENT_QUOTES, 'UTF-8');
$myCommentsLabel     = htmlspecialchars(t('header.my_comments'), ENT_QUOTES, 'UTF-8');
$notesTable          = htmlspecialchars(t('header.notes'), ENT_QUOTES, 'UTF-8');
$logoutLabel         = htmlspecialchars(t('auth.logout'), ENT_QUOTES, 'UTF-8');

$sidebarJsVersion = asset_version(__DIR__ . '/../public/assets/js/sidebar.js');
$notificationsJsVersion   = asset_version(__DIR__ . '/../public/assets/js/notifications.js');
$agentJsVersion   = asset_version(__DIR__ . '/../public/assets/js/agent-panel.js');

$logoEnabled    = (bool) settings_value('logo_enabled', false);
$customLogoPath = settings_value('custom_logo_path', null);
$logoPath        = null;
if ($logoEnabled) {
    $logoPath = is_string($customLogoPath) && $customLogoPath !== ''
        ? htmlspecialchars($customLogoPath, ENT_QUOTES, 'UTF-8')
        : 'assets/img/logo.png';
}
?>
<header>
    <?php if ($logoPath !== null) : ?>
    <a href="/" class="brand-logo">
        <img src="<?= $logoPath ?>" alt="OpenSparrow Logo">
    </a>
    <?php endif; ?>
    <button id="sidebarToggle" data-cy="sidebar-toggle" aria-label="<?= $toggleSidebarLabel ?>">&#9776;</button>
    <button class="header-search-toggle" id="searchToggle" aria-label="<?= $toggleSearchLabel ?>">
        <img class="header-search-icon" src="assets/icons/search.png" alt="">
    </button>

    <div class="header-controls">
        <?php if (!empty($headerControls)) {
            echo $headerControls;
        } ?>
    </div>

    <div class="header-user-menu">
        <div class="notifications-wrapper" data-cy="notifications" aria-label="<?= $notificationsTable ?>">
            <span>
                <img class="notif-icon-img" title="<?= $notificationsTable ?>"
                     src="assets/icons/notifications.png" alt="<?= $notificationsTable ?>">
            </span>
            <span id="notif-badge" class="notif-badge">0</span>
            <div id="notif-dropdown" class="notif-dropdown">
                <div class="notif-dropdown-header"><?= $notificationsTable ?></div>
                <ul id="notif-list" class="notif-list"></ul>
            </div>
        </div>

        <?php if ($userRole === 'admin') : ?>
        <a href="/admin/index.php" class="header-admin-link" data-cy="admin-link" title="<?= $adminPanelLabel ?>">
            <img title="<?= $adminPanelLabel ?>" src="assets/icons/settings.png" alt="<?= $adminTitleLabel ?>">
        </a>
        <?php endif; ?>

        <?php if ($uname !== '') : ?>
        <div class="user-avatar-wrap">
            <button class="user-avatar-btn" id="userAvatarBtn" data-cy="user-avatar"
                    <?php if ($avatarId) :
                        ?>data-avatar-id="<?= (int)$avatarId ?>" <?php
                    endif; ?>
                    aria-label="User menu" aria-expanded="false" aria-haspopup="true">
                <svg class="avatar avatar-border avatar-initial" viewBox="0 0 32 32" aria-hidden="true">
                    <circle cx="16" cy="16" r="16" fill="<?= $avatarColor ?>"/>
                    <text x="16" y="21" text-anchor="middle" fill="#fff"
                          font-size="14" font-family="system-ui,sans-serif" font-weight="600"><?= $initial ?></text>
                </svg>
                <span class="user-avatar-tooltip"><?= $escapedUsername ?></span>
            </button>
            <div class="user-avatar-menu" id="userAvatarMenu" role="menu">
                <button class="user-avatar-menu-item" id="changeAvatarBtn" role="menuitem">
                    <?= $changeAvatarLabel ?>
                </button>
                <button class="user-avatar-menu-item" id="changePasswordBtn" role="menuitem">
                    <?= $changePasswordLabel ?>
                </button>
                <button class="user-avatar-menu-item" id="openAgentBtn" role="menuitem"><?= $agentTitleLabel ?></button>
                <button class="user-avatar-menu-item" id="myRecordsBtn" data-cy="my-records" role="menuitem">
                    <?= $myRecordsLabel ?>
                </button>
                <button class="user-avatar-menu-item" id="myCommentsBtn" data-cy="my-comments" role="menuitem">
                    <?= $myCommentsLabel ?>
                </button>
                <button class="user-avatar-menu-item" id="notesBtn" data-cy="notes" role="menuitem">
                    <?= $notesTable ?>
                </button>
                <div class="user-avatar-menu-divider"></div>
                <button class="user-avatar-menu-item danger" id="logoutBtn" data-cy="logout" role="menuitem">
                    <?= $logoutLabel ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</header>
<script src="assets/js/sidebar.js?v=<?= $sidebarJsVersion ?>"<?= $nonceAttribute ?>></script>
<script src="assets/js/notifications.js?v=<?= $notificationsJsVersion ?>"<?= $nonceAttribute ?>></script>
<script type="module" src="assets/js/user-menu.js?v=<?= $cacheBust ?>"<?= $nonceAttribute ?>></script>
<?= os_inline_globals([
    'CHAT_BUBBLE_ENABLED' => defined('CHAT_BUBBLE_ENABLED') && CHAT_BUBBLE_ENABLED,
], $cspNonce ?? '') ?>
<script type="module" src="assets/js/agent-panel.js?v=<?= $agentJsVersion ?>"<?= $nonceAttribute ?>></script>
<div class="app-container">
<?php include __DIR__ . '/menu.php'; ?>
