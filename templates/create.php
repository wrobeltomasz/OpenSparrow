<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$formHeading   ??= '';
$formError     ??= '';
$formCsrfToken ??= '';
$formFields    ??= [];
$m2mGroups     ??= [];
$formLabels    ??= [];
$cancelUrl     ??= 'index.php';
$isReadOnly    ??= true;
?>
<main class="form-page">
    <h2><?= htmlspecialchars($formHeading, ENT_QUOTES, 'UTF-8') ?></h2>

    <?php if ($formError !== '') : ?>
        <div class="form-alert error">Error: <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="form-wrapper">
        <form method="POST" class="editor-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($formCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <?php foreach ($formFields as $formField) : ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($formField['label'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($formField['required']) : ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>
                        <?= $formField['html'] ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($m2mGroups)) : ?>
                <div class="m2m-block">
                    <?php foreach ($m2mGroups as $m2mGroup) : ?>
                        <?= $m2mGroup ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <?php if ($isReadOnly) : ?>
                    <button type="button" class="btn-save" disabled><?= htmlspecialchars($formLabels['add'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php else : ?>
                    <button type="submit" class="btn-save"><?= htmlspecialchars($formLabels['add'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" data-nav="<?= htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($formLabels['cancel'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</main>
