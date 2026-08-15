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
        foreach ($m2mConfigs as $m2mIndex => $m2mCfg) {
            $selected = array_values(array_filter((array)($_POST['m2m_' . $m2mIndex] ?? []), 'ctype_digit'));
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

$formFields = [];
foreach ($tableCfg->visibleColumns() as $col) {
    if ($col->name === $tableCfg->primaryKey || $col->readonly) {
        continue;
    }
    $isColRo = $isReadOnly || ($locked[$col->name] ?? false);
    $formFields[] = [
        'label'    => $col->displayName,
        'required' => $col->notNull && !$isColRo,
        'html'     => $fieldRegistry->for($col, $tableCfg->hasForeignKey($col->name))
            ->render($col, null, $ctx),
    ];
}

$m2mGroups = [];
foreach ($m2mConfigs as $m2mIndex => $m2mCfg) {
    $m2mGroups[] = os_m2m_group(
        (int)$m2mIndex,
        $m2mCfg,
        m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema),
        [],
        $isReadOnly
    );
}

$formHeading   = t('form.add_new_record', ['table' => $tableCfg->displayName]);
$formError     = $error;
$formCsrfToken = $csrf->token();
$cancelUrl     = 'index.php?table=' . urlencode((string)$table);
$formLabels    = [
    'add'    => t('form.add_record'),
    'cancel' => t('common.cancel'),
];

$pageTitle = 'OpenSparrow | Add Record - ' . $tableCfg->displayName;
ob_start();
include __DIR__ . '/../templates/create.php';
$pageContent = ob_get_clean();

$extraScripts = os_module_script('assets/js/edit/form-behaviours.js', $cspNonce)
    . os_module_script('assets/js/edit/m2m-picker.js', $cspNonce);
include __DIR__ . '/../templates/layout.php';
