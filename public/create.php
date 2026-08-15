<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/m2m.php';

use App\Form\RenderContext;

$pageMeta = os_page_bootstrap(['csp' => 'unsafe-style', 'redirect_admin' => false]);
$cspNonce = $pageMeta['nonce'];

['session' => $session, 'request' => $request, 'csrf' => $csrf, 'schemas' => $schemas,
 'fieldRegistry' => $fieldRegistry, 'mapper' => $mapper, 'records' => $records,
 'audit' => $audit, 'fkLoader' => $fkLoader] = os_boot_app();

$isReadOnly = $session->role() !== 'editor';

if ($isReadOnly && $request->isPost()) {
    http_response_code(403);
    die('Forbidden: Read-only access');
}

$table = $request->query('table');

if (!$schemas->hasTable($table)) {
    die('Invalid table.');
}
os_require_table_access((string) $table);

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
        $newId  = $records->insert($tableCfg, $data);
        $userId = $session->userId();
        $logId  = $audit->log($userId, 'INSERT', $tableCfg->name, (int)$newId);
        if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
            snapshot_record($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$newId, $logId);
        }
        set_record_owner($GLOBALS['conn'], $tableCfg->name, (int)$newId, $userId, $userId);
        evaluate_automation_rules($GLOBALS['conn'], $tableCfg->schema, $tableCfg->name, (int)$newId, 'create', $userId);
        foreach ($m2mConfigs as $mi => $m2mCfg) {
            $selected = array_values(array_filter((array)($_POST['m2m_' . $mi] ?? []), 'ctype_digit'));
            m2m_sync($GLOBALS['conn'], $m2mCfg, (int)$newId, $selected, $rawSchema);
        }
        $fragment = images_config($rawSchema, $table) ? '#tab-images' : '#tab-files';
        header('Location: edit.php?table=' . urlencode($table) . '&id=' . $newId . $fragment);
        exit;
    } catch (\App\Form\ValidationException $e) {
        $error = $e->getMessage();
    } catch (\RuntimeException $e) {
        error_log('[create.php] ' . $e->getMessage());
        $error = 'Database error. Please try again.';
    }
}

$fkOptions = [];
foreach ($tableCfg->foreignKeys as $colName => $fkCfg) {
    $fkOptions[$colName] = $fkLoader->load($fkCfg, $rawSchema);
}

$prefilled = [];
$locked    = [];
foreach ($tableCfg->writableColumns() as $col) {
    if (isset($_GET[$col->name])) {
        $prefilled[$col->name] = (string)$_GET[$col->name];
        $locked[$col->name]    = true;
    }
}

$ctx = new RenderContext($isReadOnly, $fkOptions, $prefilled, $locked);

$pageTitle = 'OpenSparrow | Add Record - ' . $tableCfg->displayName;
ob_start();
?>

<main class="form-page">
    <h2><?= htmlspecialchars(t('form.add_new_record', ['table' => $tableCfg->displayName])) ?></h2>

    <?php if ($error) : ?>
        <div class="form-alert error">
            Error: <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="form-wrapper">
        <form method="POST" class="editor-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-grid">
            <?php foreach ($tableCfg->visibleColumns() as $col) : ?>
                <?php
                if ($col->name === $tableCfg->primaryKey || $col->readonly) {
                    continue;
                }
                $hasFk   = $tableCfg->hasForeignKey($col->name);
                $isColRo = $isReadOnly || ($locked[$col->name] ?? false);
                ?>
                <div class="form-group">
                    <label>
                        <?php echo htmlspecialchars($col->displayName); ?>
                        <?php if ($col->notNull && !$isColRo) : ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>
                    <?php echo $fieldRegistry->for($col, $hasFk)->render($col, null, $ctx); ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if (!empty($m2mConfigs)) : ?>
            <div class="m2m-block">
                <?php foreach ($m2mConfigs as $mi => $m2mCfg) : ?>
                    <?php
                    $m2mOpts = m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema);
                    echo os_m2m_group((int)$mi, $m2mCfg, $m2mOpts, [], $isReadOnly);
                    ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <?php if ($isReadOnly) : ?>
                    <button type="button" class="btn-save" disabled><?= t('form.add_record') ?></button>
                <?php else : ?>
                    <button type="submit" class="btn-save"><?= t('form.add_record') ?></button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" data-nav="index.php?table=<?php echo htmlspecialchars(urlencode($table), ENT_QUOTES, 'UTF-8'); ?>"><?= t('common.cancel') ?></button>
            </div>
        </form>
    </div>
</main>
<?php
$pageContent = ob_get_clean();

$extraScripts = os_module_script('assets/js/edit/form-behaviours.js', $cspNonce)
    . os_module_script('assets/js/edit/m2m-picker.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
