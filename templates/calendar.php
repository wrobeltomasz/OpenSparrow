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
            <div class="calendar-view-toggle" role="group" aria-label="<?= htmlspecialchars($calendarLabels['view_toggle'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" id="btnViewMonth" aria-pressed="true"><?= htmlspecialchars($calendarLabels['view_month'], ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" id="btnViewWeek" aria-pressed="false"><?= htmlspecialchars($calendarLabels['view_week'], ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <button id="btnPrev"><?= htmlspecialchars($calendarLabels['prev'], ENT_QUOTES, 'UTF-8') ?></button>
            <button id="btnNext"><?= htmlspecialchars($calendarLabels['next'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>

    <div id="calendarContainer" class="calendar-grid"></div>
</main>