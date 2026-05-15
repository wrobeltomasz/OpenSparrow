<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/m2m.php';

use App\Form\RenderContext;
use App\Support\ByteFormatter;

if (!$session->has('user_id')) {
    header('Location: login.php');
    exit;
}

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

$tableCfg   = $schemas->table($table);
$rawSchema  = $schemas->raw();
$m2mConfigs = $rawSchema['tables'][$table]['many_to_many'] ?? [];
$error      = '';

if ($request->isPost()) {
    if (!$csrf->isValid($request->post('csrf_token'))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    try {
        $data  = $mapper->fromPost($tableCfg, $request->postAll());
        $records->update($tableCfg, $id, $data);
        $logId = $audit->log($session->userId(), 'UPDATE', $tableCfg->name, (int)$id);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$id, $logId);
        }
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
    } catch (\RuntimeException $e) {
        error_log('[edit.php] ' . $e->getMessage());
        $error = 'Database error. Please try again.';
    }
}

$row = $records->find($tableCfg, $id);
if ($row === null) {
    die('Record not found.');
}

$subtablesData = $records->subtables($tableCfg, $id);
$relatedFiles  = $files->forRecord($tableCfg->name, $id);

// Pre-load FK options for all FK columns — eliminates N+1 queries in the template.
$fkOptions  = [];
foreach ($tableCfg->foreignKeys as $colName => $fkCfg) {
    $fkOptions[$colName] = $fkLoader->load($fkCfg, $rawSchema);
}

$ctx = new RenderContext($isReadOnly, $fkOptions);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>OpenSparrow | Edit Record - <?php echo htmlspecialchars($tableCfg->displayName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link href="/assets/css/mobile.css" rel="stylesheet" media="only screen and (max-width: 768px)">
    <style>
        .tag-badge { background: #e2e8f0; color: #475569; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid #cbd5e1; margin-right: 4px; display: inline-block; }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<main style="padding: 20px; max-width: 1060px; margin: 0 auto;">
    <h2>Edit record in <?php echo htmlspecialchars($tableCfg->displayName); ?></h2>

    <?php if ($request->query('saved') === '1') : ?>
        <div style="color: #166534; margin-bottom: 15px; padding: 10px 14px; border: 1px solid #86efac; background: #dcfce7; border-radius: 6px; font-weight: 500;">
            Record saved successfully.
        </div>
    <?php endif; ?>

    <?php if ($error) : ?>
        <div style="color: red; margin-bottom: 15px; padding: 10px; border: 1px solid red; background: #fee; border-radius: 6px;">
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
        <button class="tab-btn" data-tab="tab-files" role="tab" aria-selected="false">
            <img class="tab-icon" src="assets/icons/folder_open.png" alt="">
            Files
        </button>
        <button class="tab-btn" data-tab="tab-comments" role="tab" aria-selected="false">Comments</button>
        <button class="tab-btn" data-tab="tab-history" role="tab" aria-selected="false">Record History</button>
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
                if ($col->name === $tableCfg->primaryKey) continue;
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
            <div style="border-top:1px solid var(--border-light); margin:20px 0 4px; padding-top:18px;">
                <?php foreach ($m2mConfigs as $mi => $m2mCfg) : ?>
                <?php
                $m2mOpts = m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema);
                $m2mSel  = m2m_selected($GLOBALS['conn'], $m2mCfg, (int)$id, $rawSchema);
                ?>
                <div style="margin-bottom:18px;">
                    <div style="font-weight:600; font-size:13px; color:var(--text); margin-bottom:10px;">
                        <?php echo htmlspecialchars($m2mCfg['label'] ?? 'Related'); ?>
                    </div>
                    <?php if (empty($m2mOpts)) : ?>
                        <p style="color:var(--muted); font-size:13px; margin:0;">No options available.</p>
                    <?php else : ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px 24px;">
                        <?php foreach ($m2mOpts as $opt) : ?>
                        <label style="display:flex; align-items:center; gap:6px; font-size:14px; color:var(--text); cursor:pointer;">
                            <input type="checkbox"
                                name="m2m_<?php echo (int)$mi; ?>[]"
                                value="<?php echo htmlspecialchars($opt['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php if (in_array($opt['id'], $m2mSel, true)) echo 'checked'; ?>
                                <?php if ($isReadOnly) echo 'disabled'; ?>>
                            <?php echo htmlspecialchars($opt['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <input type="hidden" name="_save_action" id="saveAction" value="exit">
            <div class="form-actions">
                <?php if ($isReadOnly) : ?>
                    <button type="button" class="btn-save" disabled>Update Record</button>
                <?php else : ?>
                    <button type="submit" class="btn-save"
                        onclick="document.getElementById('saveAction').value='stay'">Save</button>
                    <button type="submit" class="btn-cancel"
                        onclick="document.getElementById('saveAction').value='exit'">Save &amp; Exit</button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" onclick="window.location.href='index.php?table=<?php echo urlencode($table); ?>'">Cancel</button>
            </div>
        </form>
    </div>
    </div><!-- /tab-panel#tab-details -->

    <?php foreach ($subtablesData as $si => $sd) : ?>
    <?php
        $sTable  = $sd['config']['table'];
        $sFk     = $sd['config']['foreign_key'];
        $sCols   = $sd['config']['columns_to_show'] ?? ['id'];
        $siLabel = $sd['config']['label'] ?? ($sd['schema']->displayName ?? $sTable);
    ?>
    <div class="tab-panel" id="tab-sub-<?php echo (int)$si; ?>" role="tabpanel">
        <div class="subtable-container" style="margin-top: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3><?php echo htmlspecialchars($siLabel); ?></h3>
                <?php if (!$isReadOnly) : ?>
                    <a href="create.php?table=<?php echo urlencode($sTable); ?>&<?php echo urlencode($sFk); ?>=<?php echo urlencode((string)$id); ?>" class="btn-add">
                        + Add <?php echo htmlspecialchars($siLabel); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($sd['rows'])) : ?>
                <p style="color: var(--muted); font-size: 14px; margin-top: 10px;">No records found.</p>
            <?php else : ?>
                <div class="edit-subtable-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($sCols as $c) : ?>
                                    <th><?php echo htmlspecialchars($sd['schema']->columns[$c]->displayName ?? $c); ?></th>
                                <?php endforeach; ?>
                                <th style="width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sd['rows'] as $r) : ?>
                                <tr>
                                    <?php foreach ($sCols as $c) : ?>
                                        <?php $displayVal = $r[$c . '__display'] ?? $r[$c] ?? ''; ?>
                                        <td><?php echo htmlspecialchars((string)$displayVal); ?></td>
                                    <?php endforeach; ?>
                                    <td class="subtable-actions">
                                        <?php if ($isReadOnly) : ?>
                                            <a href="edit.php?table=<?php echo urlencode($sTable); ?>&id=<?php echo urlencode($r['id']); ?>" class="btn-action" style="pointer-events: none; opacity: 0.5;">View</a>
                                        <?php else : ?>
                                            <a href="edit.php?table=<?php echo urlencode($sTable); ?>&id=<?php echo urlencode($r['id']); ?>" class="btn-action">Edit</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div><!-- /tab-panel#tab-sub-<?php echo (int)$si; ?> -->
    <?php endforeach; ?>

    <div class="tab-panel" id="tab-files" role="tabpanel">
    <div class="subtable-container" style="margin-top: 10px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="color: #0f172a; margin: 0;">Attached Files</h3>
        </div>

        <?php if (!$isReadOnly) : ?>
            <div style="background: #f8fafc; padding: 15px; border: 1px dashed #cbd5e1; border-radius: 6px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="file" id="inlineFileInput" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; background: #fff;" />
                <input type="text" id="inlineFileName" placeholder="Optional display name" style="padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; width: 180px;" />
                <input type="text" id="inlineFileTags" placeholder="Tags (comma separated)" style="padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; width: 180px;" list="tagSuggestions" />

                <datalist id="tagSuggestions">
                    <option value="Invoice">
                    <option value="Contract">
                    <option value="Image">
                    <option value="Report">
                </datalist>

                <button type="button" id="btnInlineUpload" class="btn-action" style="background: #0ea5e9; padding: 7px 16px; border: none; border-radius: 4px; color: #fff; cursor: pointer; font-weight: bold;">Upload File</button>
                <span id="inlineUploadStatus" style="font-size: 13px; font-weight: 500; margin-left: 10px;"></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($relatedFiles)) : ?>
            <div class="edit-subtable-wrapper">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; background: #f8fafc;">
                            <th style="padding: 10px;">Type</th>
                            <th style="padding: 10px;">Name</th>
                            <th style="padding: 10px;">Tags</th>
                            <th style="padding: 10px;">Size</th>
                            <th style="padding: 10px;">Date</th>
                            <th style="padding: 10px; width: 100px;">Actions</th>
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
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 10px; font-weight: bold; color: #64748b; font-size: 12px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <img src="<?php echo htmlspecialchars($iconPath); ?>" alt="icon" style="width: 20px; height: 20px; opacity: 0.8;">
                                        <?php echo htmlspecialchars(strtoupper($rf['type'])); ?>
                                    </div>
                                </td>
                                <td style="padding: 10px; font-weight: 500;"><?php echo htmlspecialchars($rf['display_name'] ?: $rf['name']); ?></td>
                                <td style="padding: 10px;">
                                    <?php if (!empty($tagsArr)) : ?>
                                        <?php foreach ($tagsArr as $t) : ?>
                                            <span class="tag-badge"><?php echo htmlspecialchars(trim($t)); ?></span>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span style="color:#cbd5e1">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; color: #64748b;"><?php echo ByteFormatter::humanize((int)$rf['size_bytes']); ?></td>
                                <td style="padding: 10px; color: #64748b;"><?php echo htmlspecialchars(date('Y-m-d', strtotime($rf['created_at']))); ?></td>
                                <td style="padding: 10px;">
                                    <a href="file_download.php?uuid=<?php echo urlencode($rf['uuid']); ?>" target="_blank" class="btn-action" style="background: #0ea5e9; padding: 4px 10px; border-radius: 4px; color: #fff; text-decoration: none; font-size: 12px; display: inline-block;">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p style="color: #64748b; font-size: 14px; margin-top: 10px;">No files attached to this record.</p>
        <?php endif; ?>
    </div>
    </div><!-- /tab-panel#tab-files -->

    <div class="tab-panel" id="tab-comments" role="tabpanel">
        <div id="c-panel"></div>
    </div><!-- /tab-panel#tab-comments -->

    <div class="tab-panel" id="tab-history" role="tabpanel">
        <div id="ow-panel" class="owner-panel" style="margin-top: 20px; padding: 16px 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc;">
            <h3 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Record Owner</h3>
            <div id="ow-current" style="font-size: 14px; color: #0f172a; margin-bottom: 12px;">Loading…</div>
            <div id="ow-change" hidden style="align-items: center; gap: 10px; flex-wrap: wrap;">
                <select id="ow-select" style="padding: 6px 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 13px; background: #fff;"></select>
                <button id="ow-save" type="button" class="btn-action">Change Owner</button>
                <span id="ow-status" style="font-size: 13px;"></span>
            </div>
        </div>
        <div id="ow-history" style="margin-top: 20px;">
            <h3 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Assignment History</h3>
            <div id="ow-history-body" style="font-size: 14px; color: #64748b;">Loading…</div>
        </div>
    </div><!-- /tab-panel#tab-history -->

</main>
</div>

<?php include 'templates/footer.php'; ?>

<script>
    window.CSRF_TOKEN      = <?php echo json_encode($csrf->token(), JSON_THROW_ON_ERROR); ?>;
    window.EDIT_TABLE      = <?php echo json_encode($tableCfg->name, JSON_THROW_ON_ERROR); ?>;
    window.EDIT_ID         = <?php echo json_encode((int)$id, JSON_THROW_ON_ERROR); ?>;
    window.CURRENT_USER_ID = <?php echo json_encode($session->userId(), JSON_THROW_ON_ERROR); ?>;
    window.USER_ROLE       = <?php echo json_encode($session->role(), JSON_THROW_ON_ERROR); ?>;
</script>

<script>
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

    // Enum select color update
    document.querySelectorAll('select[data-enum-colors]').forEach(sel => {
        const colors = JSON.parse(sel.dataset.enumColors || '{}');
        const apply  = () => { sel.style.background = colors[sel.value] || ''; };
        sel.addEventListener('change', apply);
        apply();
    });

    // RegExp validation
    const inputs = document.querySelectorAll('input[data-pattern]');
    inputs.forEach(input => {
        const validate = () => {
            if (!input.value) { input.setCustomValidity(''); return; }
            try {
                const regex = new RegExp(input.dataset.pattern);
                input.setCustomValidity(regex.test(input.value) ? '' : (input.dataset.message || 'Invalid format'));
            } catch (e) {
                console.error('Invalid RegExp in schema:', input.dataset.pattern, e);
            }
        };
        input.addEventListener('input', validate);
        validate();
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
                statusEl.style.color = 'red';
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
            statusEl.style.color = '#334155';
            btnUpload.disabled   = true;

            try {
                const res  = await fetch('api_files.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    statusEl.textContent = 'Uploaded successfully! Refreshing...';
                    statusEl.style.color = '#10b981';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                    statusEl.style.color = 'red';
                    btnUpload.disabled   = false;
                }
            } catch (err) {
                statusEl.textContent = 'Network error during upload.';
                statusEl.style.color = 'red';
                btnUpload.disabled   = false;
            }
        });
    }
});
</script>

<script type="module" src="assets/js/comments.js?v=<?php echo @filemtime('assets/js/comments.js'); ?>"></script>
<script type="module" src="assets/js/owners.js?v=<?php echo @filemtime('assets/js/owners.js'); ?>"></script>

</body>
</html>
