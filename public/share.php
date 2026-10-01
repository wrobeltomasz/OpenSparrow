<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/config_store.php';
require_once __DIR__ . '/../includes/autoload.php';

use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Service\SharedLinkRepository;
use App\Service\SharedLinkValidator;

$page = os_page_bootstrap(['guest' => true, 'setup_check' => true, 'csp' => 'unsafe-style']);
$cspNonce = $page['nonce'];

header('X-Robots-Tag: noindex, nofollow');

$token = os_query_string('t');
if ($token === '' || strlen($token) > 128) {
    throw HttpException::fromStatus(404, 'Not Found');
}

$repository = new SharedLinkRepository();
$matched = $repository->entryByToken($token);
if ($matched === null || empty($matched['entry']['enabled'])) {
    throw HttpException::fromStatus(404, 'Not Found');
}

$sharedTable = (string) $matched['table'];
$schema = config_get('schema');
if (!is_array($schema) || !isset($schema['tables'][$sharedTable])) {
    throw HttpException::fromStatus(404, 'Not Found');
}
if (!SharedLinkValidator::isShareable($schema, $sharedTable)) {
    throw HttpException::fromStatus(404, 'Not Found');
}

$tableConfig = $schema['tables'][$sharedTable];
$displayName = $tableConfig['display_name'] ?? $sharedTable;

$shareTableConfig = $tableConfig;
unset(
    $shareTableConfig['subtables'],
    $shareTableConfig['many_to_many'],
    $shareTableConfig['images']
);

$shareSchema = [
    'tables' => [
        $sharedTable => $shareTableConfig,
    ],
];

$escapedTableName = htmlspecialchars($sharedTable, ENT_QUOTES, 'UTF-8');
$escapedDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
$escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$sharedNoteLabel = htmlspecialchars(t('share.readonly_note'), ENT_QUOTES, 'UTF-8');
$footerNoteLabel = htmlspecialchars(t('share.footer_note'), ENT_QUOTES, 'UTF-8');
$searchPlaceholderLabel = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$allColumnsLabel = htmlspecialchars(t('grid.all_columns'), ENT_QUOTES, 'UTF-8');
$exportCsvLabel = htmlspecialchars(t('grid.export_csv'), ENT_QUOTES, 'UTF-8');
$loadMoreLabel = htmlspecialchars(t('grid.load_more'), ENT_QUOTES, 'UTF-8');
$truncatedNoticeLabel = htmlspecialchars(t('grid.truncated_notice'), ENT_QUOTES, 'UTF-8');
$cannotLoadLabel = htmlspecialchars(t('grid.cannot_load_table'), ENT_QUOTES, 'UTF-8');
$appName = htmlspecialchars(settings_value('app_name', 'OpenSparrow'), ENT_QUOTES, 'UTF-8');

$pageTitle = $displayName . ' | ' . $appName;

$moduleGraph = os_fe_module_graph();

$pageContent = <<<HTML
<main class="share-page">
    <section id="gridSection" class="share-grid-section">
        <div class="share-toolbar">
            <h2 id="gridTitle" data-cy="grid-title">{$escapedDisplayName}</h2>
            <input id="globalSearch" type="search" placeholder="{$searchPlaceholderLabel}" />
            <select id="columnFilter"><option value="">{$allColumnsLabel}</option></select>
            <button id="exportCsv">{$exportCsvLabel}</button>
            <button id="clearFilters" hidden>{$allColumnsLabel}</button>
        </div>
        <div id="filterPills" class="filter-pills"></div>
        <div id="grid" data-cy="grid"></div>
        <div id="pagination" class="pagination share-pagination"></div>
        <p class="share-footer">{$footerNoteLabel}</p>
    </section>
</main>
HTML;

$shareI18n = [
    'grid.search_placeholder' => t('grid.search_placeholder'),
    'grid.all_columns' => t('grid.all_columns'),
    'grid.export_csv' => t('grid.export_csv'),
    'grid.load_more' => t('grid.load_more'),
    'grid.select_column' => t('grid.select_column'),
    'grid.showing' => t('grid.showing'),
    'grid.total_in_db' => t('grid.total_in_db'),
    'grid.rows_per_page' => t('grid.rows_per_page'),
    'grid.truncated_notice' => t('grid.truncated_notice'),
    'grid.cannot_load_table' => t('grid.cannot_load_table'),
    'grid.cannot_load_more' => t('grid.cannot_load_more'),
    'grid.sort_priority' => t('grid.sort_priority'),
    'pagination.prev' => t('pagination.prev'),
    'pagination.next' => t('pagination.next'),
    'pagination.page_of' => t('pagination.page_of'),
    'common.remove_filter' => t('common.remove_filter'),
    'share.readonly_note' => t('share.readonly_note'),
];

$extraScripts = os_inline_globals([
    'USER_ROLE'        => 'viewer',
    'SCHEMA_TABLES'    => [$sharedTable],
    'SHARE_TABLE'      => $sharedTable,
    'SHARE_TOKEN'      => $token,
    'SHARE_SCHEMA'     => $shareSchema,
    'SHARE_I18N'       => $shareI18n,
], $cspNonce)
    . os_module_script('assets/js/share.js', $cspNonce);

$extraMeta = '<meta name="robots" content="noindex, nofollow">';
$sharedNote = $sharedNoteLabel;

include __DIR__ . '/../templates/share_layout.php';
throw ResponseException::sent();
