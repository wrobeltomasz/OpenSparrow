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
    window.BREADCRUMB_LABELS = <?= json_encode($breadcrumbLabels, $adminJsonFlags) ?>;
    window.LOGOUT_URL        = <?= json_encode($adminLogoutUrl, $adminJsonFlags) ?>;
</script>
<script type="module" src="js/nav-chrome.js?v=<?= (int) @filemtime(__DIR__ . '/../../js/nav-chrome.js') ?>"></script>
