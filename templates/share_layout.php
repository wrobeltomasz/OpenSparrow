<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$pageTitle    = $pageTitle ?? 'OpenSparrow';
$cspNonce     = $cspNonce ?? '';
$extraMeta    = $extraMeta ?? '';
$pageContent  = $pageContent ?? '';
$extraScripts = $extraScripts ?? '';
$sharedNote   = $sharedNote ?? '';

require_once __DIR__ . '/../includes/page_helpers.php';
$moduleGraph = os_fe_module_graph();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $extraMeta ?>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link
        href="/assets/css/styles.css?v=<?= asset_version(__DIR__ . '/../public/assets/css/styles.css') ?>"
        rel="stylesheet"
    >
    <link
        href="/assets/css/buttons.css?v=<?= asset_version(__DIR__ . '/../public/assets/css/buttons.css') ?>"
        rel="stylesheet"
    >
    <link href="/assets/css/mobile.css?v=<?= asset_version(__DIR__ . '/../public/assets/css/mobile.css') ?>"
          rel="stylesheet" media="only screen and (max-width: 768px)">
    <?= os_import_map($moduleGraph['imports'], $cspNonce) ?>
</head>
<body class="share-body">
<header class="share-topbar">
    <span class="share-app-name"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="share-note"><?= $sharedNote ?></span>
</header>
<?= $pageContent ?>
<?= $extraScripts ?>
</body>
</html>