<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$filesPanel ??= ['rows' => [], 'columns' => [], 'tagSuggestions' => []];
$isReadOnly ??= true;
?>
<div class="tab-panel" id="tab-files" role="tabpanel">
    <div class="subtable-container form-wrapper">
        <div class="ef-panel-head">
            <h3 class="ef-panel-title"><?= htmlspecialchars($filesPanel['title'], ENT_QUOTES, 'UTF-8') ?></h3>
        </div>

        <?php if (!$isReadOnly) : ?>
            <div class="ef-upload-bar">
                <input type="file" id="inlineFileInput" class="ef-upload-input">
                <input type="text" id="inlineFileName" class="ef-upload-text"
                       placeholder="<?= htmlspecialchars($filesPanel['phDisplayName'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="text" id="inlineFileTags" class="ef-upload-text" list="tagSuggestions"
                       placeholder="<?= htmlspecialchars($filesPanel['phTags'], ENT_QUOTES, 'UTF-8') ?>">

                <datalist id="tagSuggestions">
                    <?php foreach ($filesPanel['tagSuggestions'] as $tagSuggestion) : ?>
                        <option value="<?= htmlspecialchars($tagSuggestion, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>
                </datalist>

                <button type="button" id="btnInlineUpload" class="btn-action ef-upload-btn">
                    <?= htmlspecialchars($filesPanel['uploadLabel'], ENT_QUOTES, 'UTF-8') ?>
                </button>
                <span id="inlineUploadStatus" class="ef-upload-status"></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($filesPanel['rows'])) : ?>
            <div class="edit-subtable-wrapper">
                <table class="ef-files-table">
                    <thead>
                        <tr>
                            <?php foreach ($filesPanel['columns'] as $fileColumn) : ?>
                                <th><?= htmlspecialchars($fileColumn, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                            <th class="ef-col-actions"><?= htmlspecialchars($filesPanel['actionsLabel'], ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filesPanel['rows'] as $fileRow) : ?>
                            <tr>
                                <td class="ef-file-type">
                                    <div class="ef-file-type-inner">
                                        <img src="<?= htmlspecialchars($fileRow['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="" class="ef-file-icon">
                                        <?= htmlspecialchars($fileRow['type'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($fileRow['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if (!empty($fileRow['tags'])) : ?>
                                        <?php foreach ($fileRow['tags'] as $fileTag) : ?>
                                            <span class="tag-badge"><?= htmlspecialchars($fileTag, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span class="ef-file-dash">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ef-file-meta"><?= htmlspecialchars($fileRow['size'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="ef-file-meta"><?= htmlspecialchars($fileRow['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($fileRow['downloadUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                                       rel="noopener" class="btn-action ef-download-btn">
                                        <?= htmlspecialchars($filesPanel['downloadLabel'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="ef-empty"><?= htmlspecialchars($filesPanel['emptyText'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
</div>
