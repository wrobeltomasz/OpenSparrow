<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$calendarLabels ??= [];
?>
<main id="calendarMain">
    <div class="calendar-header">
        <h2 id="calendarTitle"><?= htmlspecialchars($calendarLabels['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="calendar-nav">
            <button id="btnPrev"><?= htmlspecialchars($calendarLabels['prev'], ENT_QUOTES, 'UTF-8') ?></button>
            <button id="btnNext"><?= htmlspecialchars($calendarLabels['next'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>

    <div id="calendarContainer" class="calendar-grid"></div>
</main>
