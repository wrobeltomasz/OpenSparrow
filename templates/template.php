<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$searchPlaceholderLabel = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$allColumnsLabel        = htmlspecialchars(t('grid.all_columns'), ENT_QUOTES, 'UTF-8');
$clearFiltersLabel      = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$chooseActionLabel      = htmlspecialchars(t('grid.choose_action'), ENT_QUOTES, 'UTF-8');
$addRowLabel            = htmlspecialchars(t('grid.add_row'), ENT_QUOTES, 'UTF-8');
$exportCsvLabel         = htmlspecialchars(t('grid.export_csv'), ENT_QUOTES, 'UTF-8');
$refreshTableLabel      = htmlspecialchars(t('grid.refresh_table'), ENT_QUOTES, 'UTF-8');
$dataCleanupLabel       = htmlspecialchars(t('data_cleanup.title'), ENT_QUOTES, 'UTF-8');
$shortcutsHelpLabel     = htmlspecialchars(t('shortcuts.help_title'), ENT_QUOTES, 'UTF-8');
$addLabel               = htmlspecialchars(t('common.add'), ENT_QUOTES, 'UTF-8');

$gridActions = [
    [
        'value' => 'add',
        'optionLabel' => $addRowLabel,
        'role' => 'editor',
        'button' => ['id' => 'addRow', 'cy' => 'add', 'class' => 'success', 'label' => $addLabel],
    ],
    [
        'value' => 'export',
        'optionLabel' => $exportCsvLabel,
        'role' => null,
        'button' => ['id' => 'exportCsv', 'cy' => 'export', 'class' => null, 'label' => $exportCsvLabel],
    ],
    [
        'value' => 'refresh',
        'optionLabel' => $refreshTableLabel,
        'role' => null,
        'button' => null,
    ],
    [
        'value' => 'data-cleanup',
        'optionLabel' => $dataCleanupLabel,
        'role' => 'editor',
        'button' => ['id' => 'dataCleanupBtn', 'cy' => 'data-cleanup', 'class' => null, 'label' => $dataCleanupLabel],
    ],
    [
        'value' => 'keyboard-help',
        'optionLabel' => $shortcutsHelpLabel,
        'role' => null,
        'button' => [
            'id' => 'kgHelpBtn',
            'cy' => 'keyboard-help',
            'class' => 'kg-help-btn',
            'label' => '&#9000;',
            'title' => $shortcutsHelpLabel,
        ],
    ],
];
$gridActionAllowed = fn ($gridAction) => !$gridAction['role'] || ($userRole ?? '') === $gridAction['role'];
$headerControls = <<<HTML
    <input id="globalSearch" data-cy="search" type="text" placeholder="{$searchPlaceholderLabel}" />
    <select id="columnFilter" data-cy="column-filter"><option value="">{$allColumnsLabel}</option></select>
    <div id="filterBar"></div>
    <button id="clearFilters" title="{$clearFiltersLabel}" style="display:none;">{$clearFiltersLabel}</button>
HTML;
$pageTitle = 'OpenSparrow | Open source | PHP + vanilla JS + Postgres';
ob_start();
?>
<main>
    <section id="gridSection">
        <h2 id="gridTitle" data-cy="grid-title">Table</h2>

        <div id="grid" data-cy="grid"></div>

        <div id="actions" class="actions">
            <div class="left">
                <select id="mobileActions">
                    <option value=""><?= $chooseActionLabel ?></option>
                    <?php foreach ($gridActions as $gridAction) : ?>
                        <?php if (!$gridActionAllowed($gridAction)) {
                            continue;
                        } ?>
                    <option value="<?= $gridAction['value'] ?>"><?= $gridAction['optionLabel'] ?></option>
                    <?php endforeach; ?>
                </select>

                <?php foreach ($gridActions as $gridAction) : ?>
                    <?php if (!$gridAction['button'] || !$gridActionAllowed($gridAction)) {
                        continue;
                    } ?>
                    <?php
                    $button = $gridAction['button'];
                    $bAttrs = ($button['class'] ? ' class="' . $button['class'] . '"' : '')
                        . (isset($button['title']) ? ' title="' . $button['title'] . '"' : '');
                    ?>
                <button id="<?= $button['id'] ?>" data-cy="<?= $button['cy'] ?>"<?= $bAttrs ?>>
                    <?= $button['label'] ?></button>
                <?php endforeach; ?>
            </div>

            <div id="pagination" data-cy="pagination" class="pagination"></div>
        </div>
    </section>

    <pre id="debug"></pre>
</main>

<?php
$pageContent = ob_get_clean();
ob_start();
?>
<?php
    require_once __DIR__ . '/../includes/config_store.php';
    $decodedSchemaTpl = config_get('schema');
    $schemaTableNames = is_array($decodedSchemaTpl['tables'] ?? null)
        ? array_keys($decodedSchemaTpl['tables'])
        : [];
    echo os_inline_globals([
        'USER_ROLE'      => $userRole ?? 'viewer',
        'SCHEMA_TABLES'  => $schemaTableNames,
    ], $cspNonce ?? '');
    ?>
<?= os_module_script('assets/js/grid/mobile-actions.js', $cspNonce ?? '') ?>
<?= os_module_script('assets/js/app.js', $cspNonce ?? '') ?>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/layout.php';
