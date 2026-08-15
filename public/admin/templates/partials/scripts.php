<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$adminImportMap      ??= '';
$adminAppVersion     ??= '';
$breadcrumbLabels    ??= [];
$adminLogoutUrl      ??= '../logout.php';
$adminJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<?= $adminImportMap ?>
<script type="module" src="js/app.js?v=<?= htmlspecialchars($adminAppVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
    var BREADCRUMB_LABELS = <?= json_encode($breadcrumbLabels, $adminJsonFlags) ?>;
    var LOGOUT_URL        = <?= json_encode($adminLogoutUrl, $adminJsonFlags) ?>;

    document.querySelectorAll('.nav-section-header').forEach(function(header) {
        header.addEventListener('click', function() {
            header.closest('.nav-section').classList.toggle('open');
        });
    });

    var navEdgeToggle = document.getElementById('navEdgeToggle');
    var adminNav      = document.getElementById('adminNav');
    var adminLayout   = document.querySelector('.admin-layout');

    function toggleNav() {
        var collapsed = adminNav.classList.toggle('collapsed');
        adminLayout.classList.toggle('nav-collapsed', collapsed);
        navEdgeToggle.innerHTML = collapsed ? '&#8250;' : '&#8249;';
    }
    navEdgeToggle.addEventListener('click', toggleNav);

    var btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', function() {
            window.location.href = LOGOUT_URL;
        });
    }

    var breadcrumbCurrent = document.getElementById('breadcrumbCurrent');
    document.querySelectorAll('.admin-tab[data-file]').forEach(function(tab) {
        tab.addEventListener('click', function() {
            breadcrumbCurrent.textContent = BREADCRUMB_LABELS[tab.dataset.file] || tab.dataset.file;
        });
    });
</script>
