<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$printLabels ??= [];
?>
<main>
    <section id="printSection">
        <div id="printContainer" class="pr-container">
            <div class="pr-loading"><?= htmlspecialchars($printLabels['loading'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>
</main>
