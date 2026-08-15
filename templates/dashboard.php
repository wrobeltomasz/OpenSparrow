<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$dashboardLabels ??= [];
?>
<main id="dashboardMain">
    <h2 id="gridTitle"><?= htmlspecialchars($dashboardLabels['title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <section id="dashboardSection" class="dashboard-grid"></section>
</main>
