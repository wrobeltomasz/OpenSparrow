<?php
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

// templates/header.php — unified application header for all app pages.
// Optional variables (set before include):
//   $headerControls (string) — extra HTML inside <header>, e.g. search/filter bar for the grid page
//   $cspNonce       (string) — CSP nonce for the user-menu script tag

$userRole  = $_SESSION['role']      ?? 'viewer';
$avatarId  = $_SESSION['avatar_id'] ?? null;
$uname     = $_SESSION['username']  ?? '';
$initial   = htmlspecialchars(strtoupper(substr($uname, 0, 1)), ENT_QUOTES, 'UTF-8');
$unameEsc  = htmlspecialchars($uname, ENT_QUOTES, 'UTF-8');
$nonceAttr = isset($cspNonce)
    ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"'
    : '';
$cacheBust = @filemtime(__DIR__ . '/../public/assets/js/user-menu.js');

$tToggleSidebar  = htmlspecialchars(t('header.toggle_sidebar'), ENT_QUOTES, 'UTF-8');
$tToggleSearch   = htmlspecialchars(t('header.toggle_search'), ENT_QUOTES, 'UTF-8');
$tNotifications  = htmlspecialchars(t('header.notifications'), ENT_QUOTES, 'UTF-8');
$tAdminPanel     = htmlspecialchars(t('header.admin_panel'), ENT_QUOTES, 'UTF-8');
$tAdminTitle     = htmlspecialchars(t('admin.title'), ENT_QUOTES, 'UTF-8');
$tChangeAvatar   = htmlspecialchars(t('header.change_avatar'), ENT_QUOTES, 'UTF-8');
$tChangePassword = htmlspecialchars(t('auth.change_password'), ENT_QUOTES, 'UTF-8');
$tAgentTitle     = htmlspecialchars(t('agent.title'), ENT_QUOTES, 'UTF-8');
$tMyRecords      = htmlspecialchars(t('header.my_records'), ENT_QUOTES, 'UTF-8');
$tLogout         = htmlspecialchars(t('auth.logout'), ENT_QUOTES, 'UTF-8');

$vSidebarJs = @filemtime(__DIR__ . '/../public/assets/js/sidebar.js');
$vNotifJs   = @filemtime(__DIR__ . '/../public/assets/js/notifications.js');
$vAgentJs   = @filemtime(__DIR__ . '/../public/assets/js/agent-panel.js');

// Logo is opt-in: a fresh install shows no logo (matches the header layout before
// this feature existed). Admin → Settings → Custom Logo must enable it explicitly.
$logoEnabled    = (bool) settings_value('logo_enabled', false);
$customLogoPath = settings_value('custom_logo_path', null);
$logoSrc        = null;
if ($logoEnabled) {
    $logoSrc = is_string($customLogoPath) && $customLogoPath !== ''
        ? htmlspecialchars($customLogoPath, ENT_QUOTES, 'UTF-8')
        : 'assets/img/logo-blue.png';
}
?>
<header>
    <?php if ($logoSrc !== null) : ?>
    <a href="/" class="brand-logo">
        <img src="<?= $logoSrc ?>" alt="OpenSparrow Logo">
    </a>
    <?php endif; ?>
    <button id="sidebarToggle" data-cy="sidebar-toggle" aria-label="<?= $tToggleSidebar ?>">&#9776;</button>
    <button class="header-search-toggle" id="searchToggle" aria-label="<?= $tToggleSearch ?>">
        <img class="header-search-icon" src="assets/icons/search.png" alt="">
    </button>

    <div class="header-controls">
        <?php if (!empty($headerControls)) {
            echo $headerControls;
        } ?>
    </div>

    <div class="header-user-menu">
        <div class="notifications-wrapper" data-cy="notifications" aria-label="<?= $tNotifications ?>">
            <span>
                <img class="notif-icon-img" title="<?= $tNotifications ?>"
                     src="assets/img/notifications.png" alt="<?= $tNotifications ?>">
            </span>
            <span id="notif-badge" class="notif-badge">0</span>
            <div id="notif-dropdown" class="notif-dropdown">
                <div class="notif-dropdown-header"><?= $tNotifications ?></div>
                <ul id="notif-list" class="notif-list"></ul>
            </div>
        </div>

        <?php if ($userRole === 'admin') : ?>
        <a href="/admin/index.php" class="header-admin-link" data-cy="admin-link" title="<?= $tAdminPanel ?>">
            <img title="<?= $tAdminPanel ?>" src="assets/img/settings.png" alt="<?= $tAdminTitle ?>">
        </a>
        <?php endif; ?>

        <?php if ($uname !== '') : ?>
        <div class="user-avatar-wrap">
            <button class="user-avatar-btn" id="userAvatarBtn" data-cy="user-avatar"
                    aria-label="User menu" aria-expanded="false" aria-haspopup="true">
                <?php if ($avatarId) : ?>
                    <img class="avatar avatar-border"
                         src="assets/img/avatar-<?= (int)$avatarId ?>.png"
                         alt="Avatar <?= (int)$avatarId ?>" />
                <?php else : ?>
                    <svg class="avatar avatar-border avatar-initial" viewBox="0 0 32 32" aria-hidden="true">
                        <circle cx="16" cy="16" r="16" fill="#364B60"/>
                        <text x="16" y="21" text-anchor="middle" fill="#fff"
                              font-size="14" font-family="Inter,sans-serif" font-weight="600"><?= $initial ?></text>
                    </svg>
                <?php endif; ?>
                <span class="user-avatar-tooltip"><?= $unameEsc ?></span>
            </button>
            <div class="user-avatar-menu" id="userAvatarMenu" role="menu">
                <button class="user-avatar-menu-item" id="changeAvatarBtn" role="menuitem">
                    <?= $tChangeAvatar ?>
                </button>
                <button class="user-avatar-menu-item" id="changePasswordBtn" role="menuitem">
                    <?= $tChangePassword ?>
                </button>
                <button class="user-avatar-menu-item" id="openAgentBtn" role="menuitem"><?= $tAgentTitle ?></button>
                <button class="user-avatar-menu-item" id="myRecordsBtn" data-cy="my-records" role="menuitem">
                    <?= $tMyRecords ?>
                </button>
                <div class="user-avatar-menu-divider"></div>
                <button class="user-avatar-menu-item danger" id="logoutBtn" data-cy="logout" role="menuitem">
                    <?= $tLogout ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</header>
<script src="assets/js/sidebar.js?v=<?= $vSidebarJs ?>"<?= $nonceAttr ?>></script>
<script src="assets/js/notifications.js?v=<?= $vNotifJs ?>"<?= $nonceAttr ?>></script>
<script type="module" src="assets/js/user-menu.js?v=<?= $cacheBust ?>"<?= $nonceAttr ?>></script>
<script<?= $nonceAttr ?>>
    window.CHAT_BUBBLE_ENABLED = <?php
        echo (defined('CHAT_BUBBLE_ENABLED') && CHAT_BUBBLE_ENABLED) ? 'true' : 'false';
    ?>;
</script>
<script type="module" src="assets/js/agent-panel.js?v=<?= $vAgentJs ?>"<?= $nonceAttr ?>></script>
<div class="app-container">
<?php include __DIR__ . '/menu.php'; ?>
