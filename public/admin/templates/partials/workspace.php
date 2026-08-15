<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$breadcrumbRoot    ??= 'Admin';
$breadcrumbCurrent ??= '';
?>
<div class="admin-main">

    <div class="admin-breadcrumb">
        <span class="breadcrumb-root"><?= htmlspecialchars($breadcrumbRoot, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current" id="breadcrumbCurrent"><?= htmlspecialchars($breadcrumbCurrent, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="admin-content">

        <section class="admin-workspace" id="workspace">
            <div id="itemPanel" class="admin-item-panel"></div>
            <div id="editorForm"></div>
        </section>

    </div>

</div>
