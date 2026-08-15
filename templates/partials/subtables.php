<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$subtablePanels ??= [];
$isReadOnly     ??= true;
?>
<?php foreach ($subtablePanels as $panel) : ?>
    <div class="tab-panel" id="<?= htmlspecialchars($panel['id'], ENT_QUOTES, 'UTF-8') ?>" role="tabpanel">
        <div class="subtable-container form-wrapper">
            <div class="ef-panel-head">
                <h3><?= htmlspecialchars($panel['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (!$isReadOnly) : ?>
                    <a href="<?= htmlspecialchars($panel['addUrl'], ENT_QUOTES, 'UTF-8') ?>" class="btn-add">
                        <?= htmlspecialchars($panel['addLabel'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($panel['rows'])) : ?>
                <p class="ef-empty"><?= htmlspecialchars($panel['emptyText'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php else : ?>
                <div class="edit-subtable-wrapper">
                    <table data-columns='<?= htmlspecialchars($panel['columnsJson'], ENT_QUOTES, 'UTF-8') ?>'>
                        <thead>
                            <tr>
                                <?php foreach ($panel['headers'] as $header) : ?>
                                    <th><?= htmlspecialchars($header, ENT_QUOTES, 'UTF-8') ?></th>
                                <?php endforeach; ?>
                                <th class="subtable-actions"><?= htmlspecialchars($panel['actionsLabel'], ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($panel['rows'] as $subRow) : ?>
                                <tr data-row='<?= htmlspecialchars($subRow['json'], ENT_QUOTES, 'UTF-8') ?>'
                                    data-title="<?= htmlspecialchars($panel['label'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($subRow['cells'] as $cell) : ?>
                                        <td><?= htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') ?></td>
                                    <?php endforeach; ?>
                                    <td class="subtable-actions">
                                        <?php if ($isReadOnly) : ?>
                                            <a href="<?= htmlspecialchars($subRow['editUrl'], ENT_QUOTES, 'UTF-8') ?>"
                                               class="btn-action btn-action-disabled"><?= htmlspecialchars($panel['viewLabel'], ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else : ?>
                                            <a href="<?= htmlspecialchars($subRow['editUrl'], ENT_QUOTES, 'UTF-8') ?>"
                                               class="btn-action"><?= htmlspecialchars($panel['editLabel'], ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
