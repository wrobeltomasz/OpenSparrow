<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$canEdit       ??= false;
$fileColumns   ??= [];
$filesColspan  ??= 8;
$filesLabels   ??= [];
$fileTypeFilters ??= [];
?>
<main>
    <section id="filesSection">

        <div id="filesGrid">
            <table class="file-table">
                <thead>
                    <tr>
                        <?php if ($canEdit) : ?>
                            <th class="th-select">
                                <input type="checkbox" class="select-all-cb"
                                       aria-label="<?= htmlspecialchars($filesLabels['selectAll'], ENT_QUOTES, 'UTF-8') ?>"
                                       title="<?= htmlspecialchars($filesLabels['selectAllToggle'], ENT_QUOTES, 'UTF-8') ?>">
                            </th>
                        <?php endif; ?>
                        <?php foreach ($fileColumns as $fileColumn) : ?>
                            <th data-sort="<?= htmlspecialchars($fileColumn['sort'], ENT_QUOTES, 'UTF-8') ?>"
                                data-label="<?= htmlspecialchars($fileColumn['label'], ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= htmlspecialchars($fileColumn['tip'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="th-label th-tip"><?= htmlspecialchars($fileColumn['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </th>
                        <?php endforeach; ?>
                        <th class="th-actions"></th>
                    </tr>
                </thead>
                <tbody id="fileTableBody">
                    <tr>
                        <td colspan="<?= (int) $filesColspan ?>" class="f-td-empty">
                            <?= htmlspecialchars($filesLabels['loading'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="filesActions" class="actions">
            <div class="left">
                <button id="btnRefreshFiles"><?= htmlspecialchars($filesLabels['refresh'], ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <div id="filePagination" class="pagination"></div>
        </div>

        <div id="fileUploadBar" class="f-upload-bar">
            <span class="f-upload-label"><?= htmlspecialchars($filesLabels['uploadNew'], ENT_QUOTES, 'UTF-8') ?></span>
            <input type="file" id="fileInput" class="f-input f-input-file"
                   aria-label="<?= htmlspecialchars($filesLabels['chooseFile'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" id="fileNameInput" class="f-input f-input-w160"
                   placeholder="<?= htmlspecialchars($filesLabels['phDisplayName'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" id="fileTagsInput" class="f-input f-input-w160"
                   placeholder="<?= htmlspecialchars($filesLabels['phTags'], ENT_QUOTES, 'UTF-8') ?>">
            <select id="fileRelatedTable" class="f-input f-input-w160">
                <option value=""><?= htmlspecialchars($filesLabels['optTargetTable'], ENT_QUOTES, 'UTF-8') ?></option>
            </select>
            <select id="fileRelatedId" class="f-input f-input-w220" disabled>
                <option value=""><?= htmlspecialchars($filesLabels['optSelectTableFirst'], ENT_QUOTES, 'UTF-8') ?></option>
            </select>
            <button id="btnUpload" class="success"><?= htmlspecialchars($filesLabels['upload'], ENT_QUOTES, 'UTF-8') ?></button>
            <span id="uploadStatus" class="f-upload-status"></span>
        </div>

    </section>
</main>
