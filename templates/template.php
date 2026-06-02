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
$pageTitle = 'OpenSparrow | Open source | PHP + vanilla JS + Postgres';
ob_start();
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

<pre id="debug"></pre>
<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo $cspNonce ?? ''; ?>">
    window.USER_ROLE = <?php echo json_encode($userRole ?? 'viewer', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    <?php
        $rawSchemaTpl = @file_get_contents(__DIR__ . '/../config/schema.json');
        $decodedSchemaTpl = $rawSchemaTpl ? @json_decode($rawSchemaTpl, true) : null;
        $schemaTableNames = is_array($decodedSchemaTpl['tables'] ?? null) ? array_keys($decodedSchemaTpl['tables']) : [];
    ?>
    window.SCHEMA_TABLES = <?php echo json_encode($schemaTableNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/layout.php';
