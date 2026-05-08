<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

use App\Form\RenderContext;

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

if (!$schemas->hasTable($table)) {
    die('Invalid table.');
}

$tableCfg = $schemas->table($table);
$error    = '';

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
        header('Location: index.php?table=' . urlencode($table));
        exit;
    } catch (\RuntimeException $e) {
        error_log('[create.php] ' . $e->getMessage());
        $error = 'Database error. Please try again.';
    }
}

// Pre-load FK options for all FK columns.
$fkOptions = [];
$rawSchema  = $schemas->raw();
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

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>OpenSparrow | Add Record - <?php echo htmlspecialchars($tableCfg->displayName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="/assets/css/styles.css" rel="stylesheet">
</head>
<body>
<?php include 'templates/header.php'; ?>

<main style="padding: 20px; max-width: 1060px; margin: 0 auto;">
    <h2>Add new record: <?php echo htmlspecialchars($tableCfg->displayName); ?></h2>

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

            <div class="form-actions">
                <?php if ($isReadOnly) : ?>
                    <button type="button" class="btn-save" disabled>Add Record</button>
                <?php else : ?>
                    <button type="submit" class="btn-save">Add Record</button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" onclick="window.location.href='index.php?table=<?php echo urlencode($table); ?>'">Cancel</button>
            </div>
        </form>
    </div>
</main>
</div>

<?php include 'templates/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Enum select color update
    document.querySelectorAll('select[data-enum-colors]').forEach(sel => {
        const colors = JSON.parse(sel.dataset.enumColors || '{}');
        const apply  = () => { sel.style.background = colors[sel.value] || ''; };
        sel.addEventListener('change', apply);
        apply();
    });

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
});
</script>

</body>
</html>
