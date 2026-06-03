<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/m2m.php';

use App\Form\RenderContext;

if (!$session->has('user_id')) {
    header('Location: login.php');
    exit;
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_redirect();

// This page echoes user-supplied data into a form; send the same security headers
// (CSP, X-Frame-Options, etc.) every other page sets. 'unsafe-style' mode because
// the form markup uses inline style attributes for dynamic values.
$cspNonce = bin2hex(random_bytes(16));
send_security_headers($cspNonce, true, 'unsafe-style');

$isReadOnly = $session->role() !== 'editor';

if ($isReadOnly && $request->isPost()) {
    http_response_code(403);
    die('Forbidden: Read-only access');
}

$table = $request->query('table');

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
        header('Location: index.php?table=' . urlencode($table));
        exit;
    } catch (\RuntimeException $e) {
        error_log('[create.php] ' . $e->getMessage());
        $error = 'Database error. Please try again.';
    }
}

// Pre-load FK options for all FK columns.
$fkOptions = [];
foreach ($tableCfg->foreignKeys as $colName => $fkCfg) {
    $fkOptions[$colName] = $fkLoader->load($fkCfg, $rawSchema);
}

// Detect GET-prefilled (locked) fields — used for subtable FK pre-population.
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

<main style="padding: 20px; max-width: 1060px; margin: 0 auto;">
    <h2><?= htmlspecialchars(t('form.add_new_record', ['table' => $tableCfg->displayName])) ?></h2>

    <?php if ($error) : ?>
        <div style="color: red; margin-bottom: 15px; padding: 10px; border: 1px solid red; background: #fee; border-radius: 6px;">
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
            <div style="border-top:1px solid var(--border-light); margin:20px 0 4px; padding-top:18px;">
                <?php foreach ($m2mConfigs as $mi => $m2mCfg) : ?>
                    <?php $m2mOpts = m2m_options($GLOBALS['conn'], $m2mCfg, $rawSchema); ?>
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
                                <?php if ($isReadOnly) {
                                    echo 'disabled';
                                } ?>>
                            <?php echo htmlspecialchars($opt['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
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
$extraScripts = '<script nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '">
document.addEventListener(\'DOMContentLoaded\', function() {
    document.querySelectorAll(\'[data-nav]\').forEach(btn => {
        btn.addEventListener(\'click\', () => { window.location.href = btn.dataset.nav; });
    });
    document.querySelectorAll(\'select[data-enum-colors]\').forEach(sel => {
        const colors = JSON.parse(sel.dataset.enumColors || \'{}\');
        const apply  = () => { sel.style.background = colors[sel.value] || \'\'; };
        sel.addEventListener(\'change\', apply);
        apply();
    });
    document.querySelectorAll(\'input[data-pattern]\').forEach(input => {
        const validate = () => {
            if (!input.value) { input.setCustomValidity(\'\'); return; }
            try {
                const regex = new RegExp(input.dataset.pattern);
                input.setCustomValidity(regex.test(input.value) ? \'\' : (input.dataset.message || \'Invalid format\'));
            } catch (e) {
                console.error(\'Invalid RegExp in schema:\', input.dataset.pattern, e);
            }
        };
        input.addEventListener(\'input\', validate);
        validate();
    });
});
</script>';
include __DIR__ . '/templates/layout.php';
