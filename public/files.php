<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$page     = os_page_bootstrap();
$cspNonce = $page['nonce'];
$userRole = $page['role'];
$userCaps = $page['caps'];
$pageTitle = 'OpenSparrow | Files';
$canEdit   = !empty($userCaps['canEdit']);
$headerControls = os_header_search('fileSearch', t('files.search_placeholder'))
    . '<select id="fileTypeFilter">'
    . '<option value="all">' . htmlspecialchars(t('files.filter_all_types'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="image">' . htmlspecialchars(t('files.filter_images'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="pdf">' . htmlspecialchars(t('files.filter_pdfs'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="doc">' . htmlspecialchars(t('files.filter_documents'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="spreadsheet">'
        . htmlspecialchars(t('files.filter_spreadsheets'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '<option value="archive">' . htmlspecialchars(t('files.filter_archives'), ENT_QUOTES, 'UTF-8') . '</option>'
    . '</select>'
    . os_header_clear_filters();

$fileColumns = [
    ['sort' => 'type', 'label' => t('files.col_type'), 'tip' => t('files.tip_type')],
    ['sort' => 'name', 'label' => t('files.col_name'), 'tip' => t('files.tip_name')],
    ['sort' => 'display', 'label' => t('files.col_display'), 'tip' => t('files.tip_display')],
    ['sort' => 'tags', 'label' => t('files.col_tags'), 'tip' => t('files.tip_tags')],
    ['sort' => 'size', 'label' => t('files.col_size'), 'tip' => t('files.tip_size')],
    ['sort' => 'related', 'label' => t('files.col_related'), 'tip' => t('files.tip_related')],
    ['sort' => 'created_at', 'label' => t('files.col_uploaded'), 'tip' => t('files.tip_uploaded')],
];

$filesColspan = $canEdit ? 9 : 8;

$filesLabels = [
    'selectAll'           => t('files.select_all_files'),
    'selectAllToggle'     => t('files.select_all_toggle'),
    'loading'             => t('files.loading'),
    'refresh'             => t('files.refresh'),
    'uploadNew'           => t('files.upload_new'),
    'chooseFile'          => t('files.choose_file'),
    'phDisplayName'       => t('files.ph_display_name'),
    'phTags'              => t('files.ph_tags'),
    'optTargetTable'      => t('files.opt_target_table'),
    'optSelectTableFirst' => t('files.opt_select_table_first'),
    'upload'              => t('form.upload_file'),
];

ob_start();
include __DIR__ . '/../templates/files.php';
$pageContent = ob_get_clean();
ob_start();
?>
<?php echo os_inline_globals([
    'USER_CAPS'  => $userCaps,
    'CSRF_TOKEN' => $_SESSION['csrf_token'],
    'FILES_TEXT' => [
        'delete_error'   => t('files.delete_error'),
        'network_error'  => t('files.network_error'),
        'save_error'     => t('files.save_error'),
        'name_empty'     => t('files.name_empty'),
        'name_updated'   => t('files.name_updated'),
        'tags_updated'   => t('files.tags_updated'),
        'deleted_n'      => t('files.deleted_n'),
        'tagged_n'       => t('files.tagged_n'),
        'unknown'        => t('files.unknown'),
        'failed'         => t('files.failed'),
        'go_to_record'   => t('files.go_to_record'),
        'edit_tags'      => t('files.edit_tags'),
        'select_file'    => t('files.select_file'),
        'delete'         => t('common.delete'),
        'download'       => t('common.download'),
        'bulk_add_tags'  => t('files.bulk_add_tags'),
        'bulk_apply'     => t('files.bulk_apply'),
        'bulk_deselect'  => t('files.bulk_deselect_all'),
        'bulk_n_selected' => t('files.bulk_n_selected'),
        'bulk_tags_scope' => t('files.bulk_tags_scope'),
        'ph_tags'        => t('files.ph_tags'),
        'ph_tags_example' => t('files.ph_tags_example'),
        'applying'       => t('files.applying'),
        'error_generic'  => t('files.error_generic'),
        'rows_per_page'  => t('grid.rows_per_page'),
        'showing'        => t('grid.showing'),
        'pg_prev'        => t('pagination.prev'),
        'pg_next'        => t('pagination.next'),
        'page_of'        => t('pagination.page_of'),
        'loading'        => t('files.loading'),
        'no_files_match' => t('files.no_files_match'),
    ],
], $cspNonce); ?>

<script
    type="module"
    src="assets/js/files-page.js?v=<?php echo asset_version(__DIR__ . '/assets/js/files-page.js'); ?>"
    nonce="<?php echo $cspNonce; ?>"
></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
