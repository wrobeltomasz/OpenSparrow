<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$imagesPanel ??= null;
$isReadOnly  ??= true;

if (!$imagesPanel) {
    return;
}
?>
<div class="tab-panel" id="tab-images" role="tabpanel">
    <div class="subtable-container form-wrapper">
        <div class="ef-panel-head">
            <h3 class="ef-panel-title"><?= htmlspecialchars($imagesPanel['label'], ENT_QUOTES, 'UTF-8') ?></h3>
            <span class="img-count" id="imgCount"><?=
                htmlspecialchars($imagesPanel['countText'], ENT_QUOTES, 'UTF-8')
            ?></span>
        </div>

        <?php if (!empty($imagesPanel['items'])) : ?>
            <div class="img-gallery">
                <?php foreach ($imagesPanel['items'] as $image) : ?>
                    <div class="img-gallery-item">
                        <a
                            href="<?= htmlspecialchars($image['url'], ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <img src="<?= htmlspecialchars($image['thumbUrl'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($image['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                        </a>
                        <div class="img-gallery-name"><?= htmlspecialchars($image['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!$isReadOnly) : ?>
                            <button type="button" class="btn-action img-delete-btn"
                                    data-uuid="<?= htmlspecialchars($image['uuid'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($imagesPanel['deleteLabel'], ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="ef-empty"><?= htmlspecialchars($imagesPanel['emptyText'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($imagesPanel['canUpload']) : ?>
            <div class="img-upload-row">
                <input type="file" id="imageInput" accept="image/*" class="ef-upload-input">
                <button type="button" id="btnImageUpload" class="btn-action ef-upload-btn">
                    <?= htmlspecialchars($imagesPanel['uploadLabel'], ENT_QUOTES, 'UTF-8') ?>
                </button>
                <span id="imageUploadStatus" class="ef-upload-status"></span>
            </div>
        <?php elseif (!$isReadOnly) : ?>
            <p class="ef-empty"><?= htmlspecialchars($imagesPanel['limitText'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
</div>
