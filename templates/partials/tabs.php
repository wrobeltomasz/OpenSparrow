<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$tabs ??= [];
?>
<div class="tab-list" role="tablist">
    <?php foreach ($tabs as $tabIndex => $tab) : ?>
        <?php $tabActive = $tabIndex === 0; ?>
        <button class="tab-btn<?= $tabActive ? ' active' : '' ?>"
                data-tab="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                role="tab"
                aria-selected="<?= $tabActive ? 'true' : 'false' ?>">
            <?php if (!empty($tab['icon'])) : ?>
                <img class="tab-icon" src="<?= htmlspecialchars($tab['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php endif; ?>
            <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
        </button>
    <?php endforeach; ?>
</div>
