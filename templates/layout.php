<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$pageTitle    ??= 'OpenSparrow';
$cspNonce     ??= '';
$extraCss     ??= '';
$extraMeta    ??= '';
$pageContent  ??= '';
$extraScripts ??= '';

require_once __DIR__ . '/../includes/page_helpers.php';
$moduleGraph = os_fe_module_graph();

$clickstatsOn = false;
if (!empty($_SESSION['user_id']) && !(defined('DEMO_MODE') && DEMO_MODE)) {
    try {
        require_once __DIR__ . '/../includes/clickstats.php';
        $clickstatsOn = clickstats_settings(60)['enabled'];
    } catch (Throwable $e) {
        $clickstatsOn = false;
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link href="/assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/styles.css') ?>" rel="stylesheet">
    <link href="/assets/css/buttons.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/buttons.css') ?>" rel="stylesheet">
    <link href="/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/mobile.css') ?>"
          rel="stylesheet" media="only screen and (max-width: 768px)">
    <?= $extraCss ?>
    <?= $extraMeta ?>
    <?= os_import_map($moduleGraph['imports'], $cspNonce) ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<?= $pageContent ?>
</div><?php include __DIR__ . '/footer.php'; ?>
<?= $extraScripts ?>
<?php if ($clickstatsOn) : ?>
    <?php ?>
    <script type="module" src="./assets/js/util/clickstats.js?v=<?= (int) $moduleGraph['version'] ?>"
            nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
