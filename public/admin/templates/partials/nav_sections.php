<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$navSections ??= [];
?>
<div class="nav-sections">
    <?php foreach ($navSections as $navSection) : ?>
        <div class="nav-section<?= !empty($navSection['open']) ? ' open' : '' ?>">
            <?php if (!empty($navSection['label'])) : ?>
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="<?= htmlspecialchars($navSection['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <span class="nav-section-label"><?= htmlspecialchars($navSection['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="nav-chevron">▼</span>
                </div>
            <?php endif; ?>
            <div class="nav-section-items<?= empty($navSection['label']) ? ' nav-section-items-flush' : '' ?>">
                <?php foreach ($navSection['items'] as $navItem) : ?>
                    <button class="admin-tab<?= !empty($navItem['active']) ? ' active' : '' ?>"
                            data-file="<?= htmlspecialchars($navItem['file'], ENT_QUOTES, 'UTF-8') ?>">
                        <img class="nav-item-icon" src="<?= htmlspecialchars($navItem['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?= htmlspecialchars($navItem['label'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
