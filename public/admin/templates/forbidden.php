<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$forbiddenUser ??= 'unknown';
$forbiddenRole ??= 'none';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/buttons.css">
</head>
<body class="admin-403-page">
    <div class="admin-403-card">
        <h1>Access Denied</h1>
        <p>Your account does not have permission to access the admin panel.</p>
        <p>Logged in as: <strong><?= htmlspecialchars($forbiddenUser, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p>Your role: <strong><?= htmlspecialchars($forbiddenRole, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p>Required role: <strong>admin</strong></p>
        <p><a href="../logout.php">Log out</a> | <a href="../">Return to application</a></p>
    </div>
</body>
</html>
