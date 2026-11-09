<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$historyPanel ??= [];
$changesEnabled = !empty($historyPanel['changesEnabled']);
$changeRows     = $historyPanel['changeRows'] ?? [];
$changeDate     = $historyPanel['changeDate'] ?? '';
?>
<div class="tab-panel" id="tab-comments" role="tabpanel">
    <div id="c-panel" class="form-wrapper"></div>
</div>
<div class="tab-panel" id="tab-history" role="tabpanel">
    <div class="form-wrapper">
        <div id="ow-panel" class="owner-panel ow-panel">
            <h3 class="ow-section-title"><?= htmlspecialchars($historyPanel['ownerTitle'], ENT_QUOTES, 'UTF-8') ?></h3>
            <div id="ow-current" class="ow-current"><?=
                htmlspecialchars($historyPanel['loadingText'], ENT_QUOTES, 'UTF-8')
            ?></div>
            <div id="ow-change" class="ow-change" hidden>
                <select id="ow-select" class="ow-select"></select>
                <button id="ow-save" type="button" class="btn-action">
                    <?= htmlspecialchars($historyPanel['changeOwnerLabel'], ENT_QUOTES, 'UTF-8') ?>
                </button>
                <span id="ow-status"></span>
            </div>
        </div>
        <div id="ow-last-edit-wrap" class="ow-last-edit-wrap">
            <h3 class="ow-section-title"><?=
                htmlspecialchars($historyPanel['lastEditTitle'], ENT_QUOTES, 'UTF-8')
            ?></h3>
            <div id="ow-last-edit" class="ow-last-edit"><?=
                htmlspecialchars($historyPanel['loadingText'], ENT_QUOTES, 'UTF-8')
            ?></div>
        </div>
        <?php if ($changesEnabled) : ?>
            <div id="ow-changes-wrap" class="ow-changes-wrap">
                <h3 class="ow-section-title"><?=
                    htmlspecialchars($historyPanel['changesTitle'], ENT_QUOTES, 'UTF-8')
                ?></h3>
                <?php if (!empty($changeRows)) : ?>
                    <div class="ow-changes-date"><?=
                        htmlspecialchars($changeDate, ENT_QUOTES, 'UTF-8')
                    ?></div>
                    <table class="ow-history-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($historyPanel['colColumn'], ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars($historyPanel['colBefore'], ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars($historyPanel['colAfter'], ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($changeRows as $changeRow) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($changeRow['column'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($changeRow['old'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($changeRow['new'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="ow-last-edit"><?=
                        htmlspecialchars($historyPanel['noChangesText'], ENT_QUOTES, 'UTF-8')
                    ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div id="ow-history" class="ow-history-wrap">
            <h3 class="ow-section-title"><?=
                htmlspecialchars($historyPanel['historyTitle'], ENT_QUOTES, 'UTF-8')
            ?></h3>
            <div id="ow-history-body" class="ow-history-body"><?=
                htmlspecialchars($historyPanel['loadingText'], ENT_QUOTES, 'UTF-8')
            ?></div>
        </div>
    </div>
</div>
