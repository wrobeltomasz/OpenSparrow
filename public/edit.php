<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/m2m.php';

use App\Form\RenderContext;
use App\Support\ByteFormatter;

$pageMeta = os_page_bootstrap(['csp' => 'unsafe-style', 'redirect_admin' => false]);
$cspNonce = $pageMeta['nonce'];

['session' => $session, 'request' => $request, 'csrf' => $csrf, 'schemas' => $schemas,
 'fieldRegistry' => $fieldRegistry, 'mapper' => $mapper, 'records' => $records,
 'files' => $files, 'audit' => $audit, 'fkLoader' => $fkLoader] = os_boot_app();

$isReadOnly = $session->role() !== 'editor';

if ($isReadOnly && $request->isPost()) {
    http_response_code(403);
    die('Forbidden: Read-only access');
}

$table = $request->query('table');
$id    = $request->query('id');

if (!$schemas->hasTable($table)) {
    die('Invalid table.');
}

os_require_table_access((string) $table);

$tableCfg   = $schemas->table($table);
$rawSchema  = $schemas->raw();
$m2mConfigs = $rawSchema['tables'][$table]['many_to_many'] ?? [];
$imagesCfg  = images_config($rawSchema, $table);
$error      = '';

$rawTableCfg = $rawSchema['tables'][$table] ?? [];
if (!can_access_record($GLOBALS['conn'], $rawTableCfg, $table, (int)$id, $session->userId(), $session->role())) {
    http_response_code(404);
    die('Record not found.');
}

if ($request->isPost()) {
    if (!$csrf->isValid($request->post('csrf_token'))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    try {
        $data  = $mapper->fromPost($tableCfg, $request->postAll());

        $oldRecord = auto_capture_old_record($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$id);
        $records->update($tableCfg, $id, $data);
        $logId = $audit->log($session->userId(), 'UPDATE', $tableCfg->name, (int)$id);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$id, $logId);
        }
        evaluate_automation_rules($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$id, 'update', $session->userId(), $oldRecord);
        foreach ($m2mConfigs as $m2mIndex => $m2mCfg) {
            $selected = array_values(array_filter((array)($_POST['m2m_' . $m2mIndex] ?? []), 'ctype_digit'));
            m2m_sync($GLOBALS['conn'], $m2mCfg, (int)$id, $selected, $rawSchema);
        }
        if (($request->post('_save_action') ?? 'exit') === 'stay') {
            header('Location: edit.php?table=' . urlencode($table) . '&id=' . urlencode((string)$id) . '&saved=1');
        } else {
            header('Location: index.php?table=' . urlencode($table));
        }
        exit;
    } catch (\App\Form\ValidationException $e) {
        $error = $e->getMessage();
    } catch (\RuntimeException $e) {
        error_log('[edit.php] ' . $e->getMessage());
        $error = 'Database error. Please try again.';
    }
}

$row = $records->find($tableCfg, $id);
if ($row === null) {
    http_response_code(404);
    die('Record not found.');
}

$subtablesData = $records->subtables($tableCfg, $id);

$subtablesData = array_values(array_filter(
    $subtablesData,
    static fn(array $subtableData): bool => user_can_access_table((string) ($subtableData['config']['table'] ?? ''))
));
$relatedFiles  = $files->forRecord($tableCfg->name, $id);

$fkOptions  = [];
foreach ($tableCfg->foreignKeys as $colName => $fkCfg) {
    $fkOptions[$colName] = $fkLoader->load($fkCfg, $rawSchema);
}

$ctx = new RenderContext($isReadOnly, $fkOptions);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$formFields = [];
foreach ($tableCfg->visibleColumns() as $col) {
    if ($col->name === $tableCfg->primaryKey) {
        continue;
    }
    $isColRo = $col->readonly || $isReadOnly;
    $formFields[] = [
        'label'    => $col->displayName,
        'required' => $col->notNull && !$isColRo,
        'html'     => $fieldRegistry->for($col, $tableCfg->hasForeignKey($col->name))
            ->render($col, $row[$col->name] ?? '', $ctx),
    ];
}

$m2mGroups = [];
foreach ($m2mConfigs as $m2mIndex => $m2mCfg) {
    $m2mGroups[] = os_m2m_group(
        (int)$m2mIndex,
        $m2mCfg,
        m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema),
        m2m_selected($GLOBALS['conn'], $m2mCfg, (int)$id, $rawSchema),
        $isReadOnly
    );
}

$subtablePanels = [];
foreach ($subtablesData as $subtableIndex => $subtableData) {
    $sTable  = $subtableData['config']['table'];
    $sFk     = $subtableData['config']['foreign_key'];
    $sCols   = $subtableData['config']['columns_to_show'] ?? ['id'];
    $subtableLabel = $subtableData['config']['label'] ?? ($subtableData['schema']->displayName ?? $sTable);

    $sColumnsMap = [];
    foreach ($subtableData['schema']->columns as $sColName => $sColCfg) {
        $sColumnsMap[$sColName] = [
            'display_name' => $sColCfg->displayName,
            'type'         => $sColCfg->type,
            'enum_colors'  => $sColCfg->enumColors,
        ];
    }

    $sHeaders = [];
    foreach ($sCols as $column) {
        $sHeaders[] = $subtableData['schema']->columns[$column]->displayName ?? $column;
    }

    $sRows = [];
    foreach ($subtableData['rows'] as $row) {
        $sCells = [];
        foreach ($sCols as $column) {
            $sCells[] = (string)($row[$column . '__display'] ?? $row[$column] ?? '');
        }
        $sRows[] = [
            'json'    => (string) json_encode($row, $jsonFlags),
            'cells'   => $sCells,
            'editUrl' => 'edit.php?table=' . urlencode($sTable) . '&id=' . urlencode((string)$row['id']),
        ];
    }

    $subtablePanels[] = [
        'id'           => 'tab-sub-' . (int)$subtableIndex,
        'label'        => $subtableLabel,
        'icon'         => $subtableData['schema']->icon ?? '',
        'addUrl'       => 'create.php?table=' . urlencode($sTable) . '&' . urlencode($sFk) . '=' . urlencode((string)$id),
        'addLabel'     => t('form.add_subtable', ['label' => $subtableLabel]),
        'emptyText'    => t('form.no_records'),
        'actionsLabel' => t('common.actions'),
        'viewLabel'    => t('common.view'),
        'editLabel'    => t('common.edit'),
        'columnsJson'  => (string) json_encode($sColumnsMap, $jsonFlags),
        'headers'      => $sHeaders,
        'rows'         => $sRows,
    ];
}

$imagesPanel = null;
if ($imagesCfg) {
    $galleryImages = images_for_record($GLOBALS['conn'], $table, (int)$id);
    $imageItems    = [];
    foreach ($galleryImages as $galleryImage) {
        $galleryImageUrl = 'file_download.php?uuid=' . urlencode($galleryImage['uuid']);
        $imageItems[] = [
            'url'      => $galleryImageUrl,
            'thumbUrl' => $galleryImageUrl . '&thumb=1',
            'name'     => $galleryImage['display_name'] ?: $galleryImage['name'],
            'uuid'     => $galleryImage['uuid'],
        ];
    }
    $imagesPanel = [
        'label'       => $imagesCfg['label'] ?: t('images.label'),
        'countText'   => t('images.count', ['n' => count($galleryImages), 'max' => $imagesCfg['max_per_record']]),
        'items'       => $imageItems,
        'canUpload'   => !$isReadOnly && count($galleryImages) < $imagesCfg['max_per_record'],
        'deleteLabel' => t('images.delete'),
        'uploadLabel' => t('images.upload'),
        'emptyText'   => t('images.empty'),
        'limitText'   => t('images.limit_reached'),
    ];
}

$fileIcons = [
    'image'       => 'assets/icons/image.png',
    'pdf'         => 'assets/icons/picture_as_pdf.png',
    'doc'         => 'assets/icons/docs.png',
    'spreadsheet' => 'assets/icons/grid_on.png',
    'archive'     => 'assets/icons/folder_zip.png',
    'other'       => 'assets/icons/file_present.png',
];

$fileRows = [];
foreach ($relatedFiles as $rf) {
    $rawTags = $rf['tags'] ?? '';
    $tagsArr = [];
    if ($rawTags && $rawTags !== '{}') {
        foreach (explode(',', str_replace('"', '', trim($rawTags, '{}'))) as $tagItem) {
            $tagsArr[] = trim($tagItem);
        }
    }
    $fileRows[] = [
        'icon'        => $fileIcons[$rf['type']] ?? $fileIcons['other'],
        'type'        => ucfirst($rf['type']),
        'name'        => $rf['display_name'] ?: $rf['name'],
        'tags'        => $tagsArr,
        'size'        => ByteFormatter::humanize((int)$rf['size_bytes']),
        'date'        => date('Y-m-d', strtotime($rf['created_at'])),
        'downloadUrl' => 'file_download.php?uuid=' . urlencode($rf['uuid']),
    ];
}

$filesPanel = [
    'title'         => t('form.attached_files'),
    'phDisplayName' => t('files.ph_display_name'),
    'phTags'        => t('files.ph_tags'),
    'uploadLabel'   => t('form.upload_file'),
    'downloadLabel' => t('files.download'),
    'emptyText'     => t('form.no_files'),
    'actionsLabel'  => t('common.actions'),
    'tagSuggestions' => ['Invoice', 'Contract', 'Image', 'Report'],
    'columns'       => [
        t('files.col_type'),
        t('files.col_display'),
        t('files.col_tags'),
        t('files.col_size'),
        t('owners.col_date'),
    ],
    'rows'          => $fileRows,
];

$historyPanel = [
    'ownerTitle'       => t('owners.section_owner'),
    'historyTitle'     => t('owners.section_history'),
    'changeOwnerLabel' => t('owners.change_owner'),
    'loadingText'      => t('common.loading'),
];

$tabs = [[
    'id'    => 'tab-details',
    'label' => $tableCfg->displayName,
    'icon'  => $tableCfg->icon ?: '',
]];
foreach ($subtablePanels as $panel) {
    $tabs[] = ['id' => $panel['id'], 'label' => $panel['label'], 'icon' => $panel['icon']];
}
if ($imagesPanel) {
    $tabs[] = ['id' => 'tab-images', 'label' => $imagesPanel['label'], 'icon' => 'assets/icons/image.png'];
}
$tabs[] = ['id' => 'tab-files', 'label' => t('form.tab_files'), 'icon' => 'assets/icons/folder_open.png'];
$tabs[] = ['id' => 'tab-comments', 'label' => t('form.tab_comments'), 'icon' => ''];
$tabs[] = ['id' => 'tab-history', 'label' => t('form.tab_history'), 'icon' => ''];

$formHeading   = t('form.edit_record', ['table' => $tableCfg->displayName]);
$formSaved     = $request->query('saved') === '1';
$formError     = $error;
$formCsrfToken = $csrf->token();
$formRecordId  = $row[$tableCfg->primaryKey] ?? null;
$cancelUrl     = 'index.php?table=' . urlencode((string)$table);
$formLabels    = [
    'saved'    => t('form.saved_ok'),
    'update'   => t('form.update_record'),
    'save'     => t('form.save'),
    'saveExit' => t('form.save_exit'),
    'cancel'   => t('common.cancel'),
    'delete'   => t('common.delete'),
];

$pageTitle = 'OpenSparrow | Edit Record - ' . $tableCfg->displayName;
ob_start();
include __DIR__ . '/../templates/edit.php';
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.CSRF_TOKEN      = <?php echo json_encode($csrf->token(), JSON_THROW_ON_ERROR); ?>;
    window.EDIT_TABLE      = <?php echo json_encode($tableCfg->name, JSON_THROW_ON_ERROR); ?>;
    window.EDIT_ID         = <?php echo json_encode((int)$id, JSON_THROW_ON_ERROR); ?>;
    window.CURRENT_USER_ID = <?php echo json_encode($session->userId(), JSON_THROW_ON_ERROR); ?>;
    window.USER_ROLE       = <?php echo json_encode($session->role(), JSON_THROW_ON_ERROR); ?>;
    window.IMAGE_TEXT      = <?php echo json_encode([
        'select_first'   => t('images.select_first'),
        'confirm_delete' => t('images.confirm_delete'),
    ], JSON_THROW_ON_ERROR); ?>;
</script>

<script nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('DOMContentLoaded', function() {
    const tabBtns   = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    function activateTab(tabId) {
        tabBtns.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
        tabPanels.forEach(p => p.classList.remove('active'));
        const btn   = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
        const panel = document.getElementById(tabId);
        if (btn)   { btn.classList.add('active');   btn.setAttribute('aria-selected', 'true'); }
        if (panel) { panel.classList.add('active'); }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });

    const hash = window.location.hash.slice(1);
    if (hash && document.getElementById(hash)) {
        activateTab(hash);
    }

    document.querySelectorAll('[data-save-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sa = document.getElementById('saveAction');
            if (sa) { sa.value = btn.dataset.saveAction; }
        });
    });

    const btnDelete = document.getElementById('btnDeleteRecord');
    if (btnDelete) {
        btnDelete.addEventListener('click', async () => {
            if (!window.confirm(<?php echo json_encode(t('common.confirm_delete'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)) {
                return;
            }
            btnDelete.disabled = true;
            try {
                const res = await fetch('index.php?api=delete', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CSRF_TOKEN
                    },
                    body: JSON.stringify({ table: window.EDIT_TABLE, id: window.EDIT_ID })
                });
                let payload = null;
                try { payload = await res.json(); } catch (e) {}
                if (!res.ok || (payload && payload.error)) {
                    window.alert((payload && payload.error) || ('Delete failed (' + res.status + ')'));
                    btnDelete.disabled = false;
                    return;
                }
                window.location.href = 'index.php?table=' + encodeURIComponent(window.EDIT_TABLE);
            } catch (err) {
                window.alert('Network error during delete.');
                btnDelete.disabled = false;
            }
        });
    }

    const btnImgUpload = document.getElementById('btnImageUpload');
    if (btnImgUpload) {
        btnImgUpload.addEventListener('click', async () => {
            const input    = document.getElementById('imageInput');
            const statusEl = document.getElementById('imageUploadStatus');

            if (!input.files || !input.files.length) {
                statusEl.textContent = window.IMAGE_TEXT.select_first;
                statusEl.style.color = 'var(--error)';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('csrf_token', window.CSRF_TOKEN);
            formData.append('file', input.files[0]);
            formData.append('related_table', <?php echo json_encode($tableCfg->name); ?>);
            formData.append('related_id',    <?php echo json_encode($id); ?>);
            formData.append('related_field', '__image');

            statusEl.textContent = 'Uploading...';
            statusEl.style.color = 'var(--text)';
            btnImgUpload.disabled = true;

            try {
                const res  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.style.color = 'var(--error)';
                    btnImgUpload.disabled = false;
                }
            } catch (err) {
                statusEl.textContent = 'Network error during upload.';
                statusEl.style.color = 'var(--error)';
                btnImgUpload.disabled = false;
            }
        });
    }

    document.querySelectorAll('.img-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm(window.IMAGE_TEXT.confirm_delete)) return;
            btn.disabled = true;
            try {
                const res = await fetch('api/files.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', uuid: btn.dataset.uuid, csrf_token: window.CSRF_TOKEN }),
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                }
            } catch (err) {
                btn.disabled = false;
            }
        });
    });

    const btnUpload = document.getElementById('btnInlineUpload');
    if (btnUpload) {
        btnUpload.addEventListener('click', async () => {
            const fileInput  = document.getElementById('inlineFileInput');
            const nameInput  = document.getElementById('inlineFileName');
            const tagsInput  = document.getElementById('inlineFileTags');
            const statusEl   = document.getElementById('inlineUploadStatus');

            if (!fileInput.files || !fileInput.files.length) {
                statusEl.textContent = 'Please select a file to upload.';
                statusEl.style.color = 'var(--error)';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('csrf_token', window.CSRF_TOKEN);
            formData.append('file', fileInput.files[0]);
            if (nameInput.value.trim()) formData.append('display_name', nameInput.value.trim());
            if (tagsInput && tagsInput.value.trim()) formData.append('tags', tagsInput.value.trim());
            formData.append('related_table', <?php echo json_encode($tableCfg->name); ?>);
            formData.append('related_id',    <?php echo json_encode($id); ?>);

            statusEl.textContent = 'Uploading...';
            statusEl.style.color = 'var(--text)';
            btnUpload.disabled   = true;

            try {
                const res  = await fetch('api/files.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    statusEl.textContent = 'Uploaded successfully! Refreshing...';
                    statusEl.style.color = 'var(--ok)';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.style.color = 'var(--error)';
                    btnUpload.disabled   = false;
                }
            } catch (err) {
                statusEl.textContent = 'Network error during upload.';
                statusEl.style.color = 'var(--error)';
                btnUpload.disabled   = false;
            }
        });
    }
});
</script>

<script type="module" src="assets/js/comments.js?v=<?php echo @filemtime('assets/js/comments.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script type="module" src="assets/js/owners.js?v=<?php echo @filemtime('assets/js/owners.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script type="module" src="assets/js/edit/form-behaviours.js?v=<?php echo @filemtime('assets/js/edit/form-behaviours.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script type="module" src="assets/js/edit/subtable-tooltip.js?v=<?php echo @filemtime('assets/js/edit/subtable-tooltip.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script type="module" src="assets/js/edit/m2m-picker.js?v=<?php echo @filemtime('assets/js/edit/m2m-picker.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
