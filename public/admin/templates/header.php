<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$adminTitle        ??= 'Sparrow Admin';
$adminUserId       ??= 0;
$adminCsrfToken    ??= '';
$adminStyleVersion ??= '';
$firstRun          ??= false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="current-user-id" content="<?= (int) $adminUserId ?>">
    <title><?= htmlspecialchars($adminTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/buttons.css">
    <link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($adminStyleVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>

<?php include __DIR__ . '/partials/banners.php'; ?>

<header class="admin-header">
    <div class="admin-header-left">
        <a href="/" class="brand-logo">
            <img src="../assets/img/logo.png" alt="Sparrow Logo">
        </a>
        <span class="brand-name">OpenSparrow Admin</span>
    </div>

    <div class="admin-header-right">
        <label class="debug-toggle-label">
            <input type="checkbox" id="debugToggle">
            Debug FE
        </label>

        <button id="btnSave" type="button" class="btn-save">Save config</button>

        <button class="admin-tab btn-header-icon" data-file="docs" title="Documentation">
            <img src="../assets/icons/book_3s.png" alt="Docs">
            <span>Docs</span>
        </button>

        <button id="btnLogout" type="button" class="btn-header-logout">Logout</button>
    </div>
</header>

<div class="admin-layout">
