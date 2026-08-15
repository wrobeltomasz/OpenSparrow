<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$formHeading   ??= '';
$formSaved     ??= false;
$formError     ??= '';
$formCsrfToken ??= '';
$formRecordId  ??= null;
$formFields    ??= [];
$m2mGroups     ??= [];
$formLabels    ??= [];
$cancelUrl     ??= 'index.php';
$isReadOnly    ??= true;
?>
<main class="form-page">
    <h2><?= htmlspecialchars($formHeading, ENT_QUOTES, 'UTF-8') ?></h2>

    <?php if ($formSaved) : ?>
        <div class="form-alert success">
            <?= htmlspecialchars($formLabels['saved'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($formError !== '') : ?>
        <div class="form-alert error">
            Error: <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/tabs.php'; ?>

    <div class="tab-panel active" id="tab-details" role="tabpanel">
        <div class="form-wrapper">
            <form method="POST" class="editor-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($formCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >

                <?php if ($formRecordId !== null) : ?>
                    <div class="form-id-strip">
                        <span class="form-id-label">ID</span>
                        <span class="form-id-value"><?=
                            htmlspecialchars((string) $formRecordId, ENT_QUOTES, 'UTF-8')
                        ?></span>
                    </div>
                <?php endif; ?>

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

                <input type="hidden" name="_save_action" id="saveAction" value="exit">
                <div class="form-actions">
                    <?php if ($isReadOnly) : ?>
                        <button type="button" class="btn-save" disabled>
                            <?= htmlspecialchars($formLabels['update'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php else : ?>
                        <button type="submit" class="btn-save" data-save-action="stay">
                            <?= htmlspecialchars($formLabels['save'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="submit" class="btn-cancel" data-save-action="exit">
                            <?= htmlspecialchars($formLabels['saveExit'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="btn-cancel"
                        data-nav="<?= htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <?= htmlspecialchars($formLabels['cancel'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <?php if (!$isReadOnly) : ?>
                        <button type="button" class="btn-delete" id="btnDeleteRecord">
                            <?= htmlspecialchars($formLabels['delete'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/partials/subtables.php'; ?>
    <?php include __DIR__ . '/partials/images.php'; ?>
    <?php include __DIR__ . '/partials/files.php'; ?>
    <?php include __DIR__ . '/partials/comments_history.php'; ?>
</main>
