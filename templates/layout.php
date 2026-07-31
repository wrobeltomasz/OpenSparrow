<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// templates/layout.php — unified page layout for all app pages.
//
// Variables (set before include):
//   $pageTitle     (string)  — <title> text
//   $cspNonce      (string)  — CSP nonce for inline scripts
//   $extraCss      (string)  — additional <link> or <style> tags for the <head>
//   $extraMeta     (string)  — additional <meta> tags for the <head>
//   $pageContent   (string)  — main HTML content (between header and footer)
//   $extraScripts  (string)  — <script> tags injected before </body>

$pageTitle    ??= 'OpenSparrow';
$cspNonce     ??= '';
$extraCss     ??= '';
$extraMeta    ??= '';
$pageContent  ??= '';
$extraScripts ??= '';

// Cache busting for the whole frontend module tree. The entry tags (app.js,
// user-menu.js, agent-panel.js, comments.js, owners.js) already carry a "?v=",
// but everything they import did not — so after an upgrade the browser kept
// serving those from cache and the change never reached the user. Emitted here,
// in <head>, because an import map must precede the first module script.
require_once __DIR__ . '/../includes/page_helpers.php';
$moduleGraph = os_fe_module_graph();
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
</div><!-- /.app-container -->
<?php include __DIR__ . '/footer.php'; ?>
<?= $extraScripts ?>
</body>
</html>
