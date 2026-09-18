<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$roadmapLabels ??= [];
?>
<?php $roadmapSelectLabel = htmlspecialchars($roadmapLabels['title'], ENT_QUOTES, 'UTF-8'); ?>
<main id="roadmapMain">
    <div class="roadmap-header">
        <h2 id="roadmapTitle"><?= htmlspecialchars($roadmapLabels['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="roadmap-controls">
            <select id="roadmapBoardSelect" class="roadmap-select" aria-label="<?= $roadmapSelectLabel ?>"></select>
            <div class="roadmap-nav">
                <button type="button" id="roadmapPrev" aria-label="Previous">&#9666;</button>
                <button type="button" id="roadmapNext" aria-label="Next">&#9656;</button>
            </div>
        </div>
    </div>
    <div id="roadmapScroll" class="roadmap-scroll">
        <div id="roadmapGrid" class="roadmap-grid"></div>
    </div>
</main>