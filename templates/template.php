<!doctype html>
<html lang="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8" />
    <title>OpenSparrow | Open source | PHP + vanilla JS + Postgres</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
    <link href="/assets/css/styles.css" rel="stylesheet" />
    <link href="/assets/css/mobile.css" rel="stylesheet" media="only screen and (max-width: 768px)" />
    <link rel="icon" type="image/x-icon" href="favicon.ico">
</head>
<body>

<?php
$tSearchPlaceholder = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$tAllColumns        = htmlspecialchars(t('grid.all_columns'), ENT_QUOTES, 'UTF-8');
$tClearFilters      = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$headerControls = <<<HTML
    <input id="globalSearch" data-cy="search" type="text" placeholder="{$tSearchPlaceholder}" />
    <select id="columnFilter" data-cy="column-filter"><option value="">{$tAllColumns}</option></select>
    <div id="filterBar" style="display:flex;gap:10px;"></div>
    <button id="clearFilters" title="{$tClearFilters}" style="display:none;">{$tClearFilters}</button>
HTML;
include __DIR__ . '/header.php';
?>
<main>
    <section id="gridSection">
        <h2 id="gridTitle" data-cy="grid-title">Table</h2>

        <div id="grid" data-cy="grid"></div>

        <div id="actions" class="actions">
            <div class="left">
                <select id="mobileActions">
                    <option value=""><?= htmlspecialchars(t('grid.choose_action'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php if (($userRole ?? '') === 'editor') : ?>
                    <option value="add"><?= htmlspecialchars(t('grid.add_row'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                    <option value="export"><?= htmlspecialchars(t('grid.export_csv'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="refresh"><?= htmlspecialchars(t('grid.refresh_table'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php if (($userRole ?? '') === 'editor') : ?>
                    <option value="data-cleanup"><?= htmlspecialchars(t('data_cleanup.title'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                    <option value="keyboard-help"><?= htmlspecialchars(t('shortcuts.help_title'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>

                <?php if (($userRole ?? '') === 'editor') : ?>
                <button id="addRow" data-cy="add" class="success"><?= htmlspecialchars(t('common.add'), ENT_QUOTES, 'UTF-8') ?></button>
                <?php endif; ?>
                <button id="exportCsv" data-cy="export"><?= htmlspecialchars(t('grid.export_csv'), ENT_QUOTES, 'UTF-8') ?></button>
                <?php if (($userRole ?? '') === 'editor') : ?>
                <button id="dataCleanupBtn" data-cy="data-cleanup"><?= htmlspecialchars(t('data_cleanup.title'), ENT_QUOTES, 'UTF-8') ?></button>
                <?php endif; ?>
                <button id="kgHelpBtn" data-cy="keyboard-help" class="kg-help-btn" title="<?= htmlspecialchars(t('shortcuts.help_title'), ENT_QUOTES, 'UTF-8') ?>">&#9000;</button>
            </div>

            <div id="pagination" data-cy="pagination" class="pagination"></div>
        </div>
    </section>
</main>
</div>

<pre id="debug"></pre>

<?php include 'templates/footer.php'; ?>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
    window.USER_ROLE = <?php echo json_encode($userRole ?? 'viewer', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    document.addEventListener("DOMContentLoaded", () => {
        const mobileActions = document.getElementById("mobileActions");
        if (mobileActions) {
            mobileActions.addEventListener("change", e => {
                const action = e.target.value;
                if (action === "add") { const b = document.getElementById("addRow"); if (b) b.click(); }
                if (action === "export") { const b = document.getElementById("exportCsv"); if (b) b.click(); }
                if (action === "data-cleanup") { const b = document.getElementById("dataCleanupBtn"); if (b) b.click(); }
                if (action === "keyboard-help") { const b = document.getElementById("kgHelpBtn"); if (b) b.click(); }
                if (action === "refresh") location.reload();
                mobileActions.value = "";
            });
        }
    });
</script>
<script type="module" src="assets/js/app.js?v=<?php echo @filemtime('assets/js/app.js'); ?>"></script>

</body>
</html>
