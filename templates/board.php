<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$boardLabels ??= [];
?>
<main id="boardMain">
    <div class="board-header">
        <h2 id="boardTitle"><?= htmlspecialchars($boardLabels['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="board-meta" id="boardMeta"></div>
    </div>

    <div id="boardContainer" class="board-grid">
        <div class="board-loading"><?= htmlspecialchars($boardLabels['loading'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</main>
