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
        foreach ($m2mConfigs as $mi => $m2mCfg) {
            $selected = array_values(array_filter((array)($_POST['m2m_' . $mi] ?? []), 'ctype_digit'));
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
    static fn(array $sd): bool => user_can_access_table((string) ($sd['config']['table'] ?? ''))
));
$relatedFiles  = $files->forRecord($tableCfg->name, $id);

$fkOptions  = [];
foreach ($tableCfg->foreignKeys as $colName => $fkCfg) {
    $fkOptions[$colName] = $fkLoader->load($fkCfg, $rawSchema);
}

$ctx = new RenderContext($isReadOnly, $fkOptions);

$pageTitle = 'OpenSparrow | Edit Record - ' . $tableCfg->displayName;
ob_start();
?>

<main class="form-page">
    <h2><?= htmlspecialchars(t('form.edit_record', ['table' => $tableCfg->displayName])) ?></h2>

    <?php if ($request->query('saved') === '1') : ?>
        <div class="form-alert success">
            <?= t('form.saved_ok') ?>
        </div>
    <?php endif; ?>

    <?php if ($error) : ?>
        <div class="form-alert error">
            Error: <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="tab-list" role="tablist">
        <button class="tab-btn active" data-tab="tab-details" role="tab" aria-selected="true">
            <?php if ($tableCfg->icon) : ?>
                <img class="tab-icon" src="<?php echo htmlspecialchars($tableCfg->icon); ?>" alt="">
            <?php endif; ?>
            <?php echo htmlspecialchars($tableCfg->displayName); ?>
        </button>
        <?php foreach ($subtablesData as $si => $sd) : ?>
            <?php
            $siLabel = $sd['config']['label'] ?? ($sd['schema']->displayName ?? $sd['config']['table']);
            $siIcon  = $sd['schema']->icon ?? '';
            ?>
            <button class="tab-btn" data-tab="tab-sub-<?php echo (int)$si; ?>" role="tab" aria-selected="false">
                <?php if ($siIcon) : ?>
                    <img class="tab-icon" src="<?php echo htmlspecialchars($siIcon); ?>" alt="">
                <?php endif; ?>
                <?php echo htmlspecialchars($siLabel); ?>
            </button>
        <?php endforeach; ?>
        <?php if ($imagesCfg) : ?>
            <button class="tab-btn" data-tab="tab-images" role="tab" aria-selected="false">
                <img class="tab-icon" src="assets/icons/image.png" alt="">
                <?php echo htmlspecialchars($imagesCfg['label'] ?: t('images.label'), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        <?php endif; ?>
        <button class="tab-btn" data-tab="tab-files" role="tab" aria-selected="false">
            <img class="tab-icon" src="assets/icons/folder_open.png" alt="">
            <?= t('form.tab_files') ?>
        </button>
        <button class="tab-btn" data-tab="tab-comments" role="tab" aria-selected="false"><?= t('form.tab_comments') ?></button>
        <button class="tab-btn" data-tab="tab-history" role="tab" aria-selected="false"><?= t('form.tab_history') ?></button>
    </div>

    <div class="tab-panel active" id="tab-details" role="tabpanel">
    <div class="form-wrapper">
        <form method="POST" class="editor-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">

            <?php
            $pkVal = $row[$tableCfg->primaryKey] ?? null;
            if ($pkVal !== null) :
                ?>
            <div class="form-id-strip">
                <span class="form-id-label">ID</span>
                <span class="form-id-value"><?php echo htmlspecialchars((string)$pkVal); ?></span>
            </div>
            <?php endif; ?>

            <div class="form-grid">
            <?php foreach ($tableCfg->visibleColumns() as $col) : ?>
                <?php
                if ($col->name === $tableCfg->primaryKey) {
                    continue;
                }
                $val     = $row[$col->name] ?? '';
                $hasFk   = $tableCfg->hasForeignKey($col->name);
                $isColRo = $col->readonly || $isReadOnly;
                ?>
                <div class="form-group">
                    <label>
                        <?php echo htmlspecialchars($col->displayName); ?>
                        <?php if ($col->notNull && !$isColRo) : ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>
                    <?php echo $fieldRegistry->for($col, $hasFk)->render($col, $val, $ctx); ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if (!empty($m2mConfigs)) : ?>
            <div class="m2m-block">
                <?php foreach ($m2mConfigs as $mi => $m2mCfg) : ?>
                    <?php
                    $m2mOpts = m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema);
                    $m2mSel  = m2m_selected($GLOBALS['conn'], $m2mCfg, (int)$id, $rawSchema);
                    echo os_m2m_group((int)$mi, $m2mCfg, $m2mOpts, $m2mSel, $isReadOnly);
                    ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <input type="hidden" name="_save_action" id="saveAction" value="exit">
            <div class="form-actions">
                <?php if ($isReadOnly) : ?>
                    <button type="button" class="btn-save" disabled><?= t('form.update_record') ?></button>
                <?php else : ?>
                    <button type="submit" class="btn-save" data-save-action="stay"><?= t('form.save') ?></button>
                    <button type="submit" class="btn-cancel" data-save-action="exit"><?= t('form.save_exit') ?></button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" data-nav="index.php?table=<?php echo htmlspecialchars(urlencode($table), ENT_QUOTES, 'UTF-8'); ?>"><?= t('common.cancel') ?></button>
                <?php if (!$isReadOnly) : ?>
                    <button type="button" class="btn-delete" id="btnDeleteRecord"><?= t('common.delete') ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    </div>
    <?php foreach ($subtablesData as $si => $sd) : ?>
        <?php
        $sTable      = $sd['config']['table'];
        $sFk         = $sd['config']['foreign_key'];
        $sCols       = $sd['config']['columns_to_show'] ?? ['id'];
        $siLabel     = $sd['config']['label'] ?? ($sd['schema']->displayName ?? $sTable);
        $sColumnsMap = [];
        foreach ($sd['schema']->columns as $sColName => $sColCfg) {
            $sColumnsMap[$sColName] = [
                'display_name' => $sColCfg->displayName,
                'type'         => $sColCfg->type,
                'enum_colors'  => $sColCfg->enumColors,
            ];
        }
        ?>
    <div class="tab-panel" id="tab-sub-<?php echo (int)$si; ?>" role="tabpanel">
        <div class="subtable-container form-wrapper">
            <div class="ef-panel-head">
                <h3><?php echo htmlspecialchars($siLabel); ?></h3>
                <?php if (!$isReadOnly) : ?>
                    <a href="create.php?table=<?php echo urlencode($sTable); ?>&<?php echo urlencode($sFk); ?>=<?php echo urlencode((string)$id); ?>" class="btn-add">
                        <?= htmlspecialchars(t('form.add_subtable', ['label' => $siLabel])) ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($sd['rows'])) : ?>
                <p class="ef-empty"><?php echo htmlspecialchars(t('form.no_records'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else : ?>
                <div class="edit-subtable-wrapper">
                    <?php $sColumnsJson = htmlspecialchars(json_encode($sColumnsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                    <table data-columns='<?php echo $sColumnsJson; ?>'>
                        <thead>
                            <tr>
                                <?php foreach ($sCols as $c) : ?>
                                    <th><?php echo htmlspecialchars($sd['schema']->columns[$c]->displayName ?? $c); ?></th>
                                <?php endforeach; ?>
                                <th class="subtable-actions"><?= t('common.actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sd['rows'] as $r) : ?>
                                <?php $sRowJson = htmlspecialchars(json_encode($r, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                                <tr data-row='<?php echo $sRowJson; ?>' data-title="<?php echo htmlspecialchars($siLabel); ?>">
                                    <?php foreach ($sCols as $c) : ?>
                                        <?php $displayVal = $r[$c . '__display'] ?? $r[$c] ?? ''; ?>
                                        <td><?php echo htmlspecialchars((string)$displayVal); ?></td>
                                    <?php endforeach; ?>
                                    <td class="subtable-actions">
                                        <?php if ($isReadOnly) : ?>
                                            <a href="edit.php?table=<?php echo urlencode($sTable); ?>&id=<?php echo urlencode($r['id']); ?>" class="btn-action" style="pointer-events: none; opacity: 0.5;"><?= t('common.view') ?></a>
                                        <?php else : ?>
                                            <a href="edit.php?table=<?php echo urlencode($sTable); ?>&id=<?php echo urlencode($r['id']); ?>" class="btn-action"><?= t('common.edit') ?></a>
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

    <?php if ($imagesCfg) : ?>
        <?php $galleryImages = images_for_record($GLOBALS['conn'], $table, (int)$id); ?>
    <div class="tab-panel" id="tab-images" role="tabpanel">
    <div class="subtable-container form-wrapper">
        <div class="ef-panel-head">
            <?php $imgLabel = $imagesCfg['label'] ?: t('images.label'); ?>
            <h3 class="ef-panel-title"><?php echo htmlspecialchars($imgLabel, ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php
            $imgCountText = t('images.count', [
                'n'   => count($galleryImages),
                'max' => $imagesCfg['max_per_record'],
            ]);
            ?>
            <span class="img-count" id="imgCount"><?php
                echo htmlspecialchars($imgCountText, ENT_QUOTES, 'UTF-8');
            ?></span>
        </div>

        <?php if (!empty($galleryImages)) : ?>
            <div class="img-gallery">
                <?php foreach ($galleryImages as $gi) : ?>
                    <?php
                    $giUrl  = 'file_download.php?uuid=' . urlencode($gi['uuid']);
                    $giName = htmlspecialchars($gi['display_name'] ?: $gi['name'], ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="img-gallery-item">
                        <a href="<?php echo $giUrl; ?>" target="_blank" rel="noopener">
                            <img src="<?php echo $giUrl; ?>&amp;thumb=1" alt="<?php echo $giName; ?>" loading="lazy">
                        </a>
                        <div class="img-gallery-name"><?php echo $giName; ?></div>
                        <?php if (!$isReadOnly) : ?>
                            <button type="button" class="btn-action img-delete-btn"
                                    data-uuid="<?php echo htmlspecialchars($gi['uuid'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?= t('images.delete') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="ef-empty"><?= t('images.empty') ?></p>
        <?php endif; ?>

        <?php if (!$isReadOnly && count($galleryImages) < $imagesCfg['max_per_record']) : ?>
            <div class="img-upload-row">
                <input type="file" id="imageInput" accept="image/*" class="ef-upload-input">
                <button type="button" id="btnImageUpload" class="btn-action ef-upload-btn"><?= t('images.upload') ?></button>
                <span id="imageUploadStatus" class="ef-upload-status"></span>
            </div>
        <?php elseif (!$isReadOnly) : ?>
            <p class="ef-empty"><?= t('images.limit_reached') ?></p>
        <?php endif; ?>
    </div>
    </div>    
    <?php endif; ?>

    <div class="tab-panel" id="tab-files" role="tabpanel">
    <div class="subtable-container form-wrapper">
        <div class="ef-panel-head">
            <h3 class="ef-panel-title"><?= t('form.attached_files') ?></h3>
        </div>

        <?php if (!$isReadOnly) : ?>
            <div class="ef-upload-bar">
                <input type="file" id="inlineFileInput" class="ef-upload-input" />
                <input type="text" id="inlineFileName" placeholder="<?php echo htmlspecialchars(t('files.ph_display_name'), ENT_QUOTES, 'UTF-8'); ?>" class="ef-upload-text" />
                <input type="text" id="inlineFileTags" placeholder="<?php echo htmlspecialchars(t('files.ph_tags'), ENT_QUOTES, 'UTF-8'); ?>" class="ef-upload-text" list="tagSuggestions" />

                <datalist id="tagSuggestions">
                    <option value="Invoice">
                    <option value="Contract">
                    <option value="Image">
                    <option value="Report">
                </datalist>

                <button type="button" id="btnInlineUpload" class="btn-action ef-upload-btn"><?= t('form.upload_file') ?></button>
                <span id="inlineUploadStatus" class="ef-upload-status"></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($relatedFiles)) : ?>
            <div class="edit-subtable-wrapper">
                <table class="ef-files-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('files.col_type'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th><?php echo htmlspecialchars(t('files.col_display'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th><?php echo htmlspecialchars(t('files.col_tags'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th><?php echo htmlspecialchars(t('files.col_size'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th><?php echo htmlspecialchars(t('owners.col_date'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="ef-col-actions"><?php echo htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
                        </tr>
                    </thead>
                    <?php
                    $fileIcons = [
                        'image'       => 'assets/icons/image.png',
                        'pdf'         => 'assets/icons/picture_as_pdf.png',
                        'doc'         => 'assets/icons/docs.png',
                        'spreadsheet' => 'assets/icons/grid_on.png',
                        'archive'     => 'assets/icons/folder_zip.png',
                        'other'       => 'assets/icons/file_present.png',
                    ];
                    ?>
                    <tbody>
                        <?php foreach ($relatedFiles as $rf) : ?>
                            <?php
                            $iconPath = $fileIcons[$rf['type']] ?? $fileIcons['other'];

                            $rawTags = $rf['tags'] ?? '';
                            $tagsArr = [];
                            if ($rawTags && $rawTags !== '{}') {
                                $rawTags = trim($rawTags, '{}');
                                $tagsArr = explode(',', str_replace('"', '', $rawTags));
                            }
                            ?>
                            <tr>
                                <td class="ef-file-type">
                                    <div class="ef-file-type-inner">
                                        <img src="<?php echo htmlspecialchars($iconPath); ?>" alt="icon" class="ef-file-icon">
                                        <?php echo htmlspecialchars(ucfirst($rf['type'])); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($rf['display_name'] ?: $rf['name']); ?></td>
                                <td>
                                    <?php if (!empty($tagsArr)) : ?>
                                        <?php foreach ($tagsArr as $t) : ?>
                                            <span class="tag-badge"><?php echo htmlspecialchars(trim($t)); ?></span>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span class="ef-file-dash">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ef-file-meta"><?php echo ByteFormatter::humanize((int)$rf['size_bytes']); ?></td>
                                <td class="ef-file-meta"><?php echo htmlspecialchars(date('Y-m-d', strtotime($rf['created_at']))); ?></td>
                                <td>
                                    <a href="file_download.php?uuid=<?php echo urlencode($rf['uuid']); ?>" target="_blank" class="btn-action ef-download-btn">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="ef-empty"><?= t('form.no_files') ?></p>
        <?php endif; ?>
    </div>
    </div>
    <div class="tab-panel" id="tab-comments" role="tabpanel">
        <div id="c-panel" class="form-wrapper"></div>
    </div>
    <div class="tab-panel" id="tab-history" role="tabpanel">
        <div class="form-wrapper">
            <div id="ow-panel" class="owner-panel ow-panel">
                <h3 class="ow-section-title"><?= t('owners.section_owner') ?></h3>
                <div id="ow-current" class="ow-current"><?php echo htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div id="ow-change" class="ow-change" hidden>
                    <select id="ow-select" class="ow-select"></select>
                    <button id="ow-save" type="button" class="btn-action"><?php echo htmlspecialchars(t('owners.change_owner'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <span id="ow-status"></span>
                </div>
            </div>
            <div id="ow-history" class="ow-history-wrap">
                <h3 class="ow-section-title"><?= t('owners.section_history') ?></h3>
                <div id="ow-history-body" class="ow-history-body">Loading…</div>
            </div>
        </div>
    </div>
</main>
<?php
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
    // Tab switching
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

    // Save-action toggle — moved off inline onclick so it works under the page CSP
    // (nonces do not cover inline event-handler attributes). The [data-nav] cancel
    // button is wired by assets/js/edit/form-behaviours.js, shared with create.php.
    document.querySelectorAll('[data-save-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sa = document.getElementById('saveAction');
            if (sa) { sa.value = btn.dataset.saveAction; }
        });
    });

    // Delete record
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

    // Enum select colours and data-pattern validation are wired by
    // assets/js/edit/form-behaviours.js, shared with create.php.

    // Record image gallery — upload + delete (same api/files.php endpoints, gallery mode)
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

    // Inline file upload
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
