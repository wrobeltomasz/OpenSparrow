<?php
$tSearchPlaceholder = htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8');
$tAllColumns        = htmlspecialchars(t('grid.all_columns'), ENT_QUOTES, 'UTF-8');
$tClearFilters      = htmlspecialchars(t('grid.clear_filters'), ENT_QUOTES, 'UTF-8');
$tChooseAction      = htmlspecialchars(t('grid.choose_action'), ENT_QUOTES, 'UTF-8');
$tAddRow            = htmlspecialchars(t('grid.add_row'), ENT_QUOTES, 'UTF-8');
$tExportCsv         = htmlspecialchars(t('grid.export_csv'), ENT_QUOTES, 'UTF-8');
$tRefreshTable      = htmlspecialchars(t('grid.refresh_table'), ENT_QUOTES, 'UTF-8');
$tDataCleanup       = htmlspecialchars(t('data_cleanup.title'), ENT_QUOTES, 'UTF-8');
$tShortcutsHelp     = htmlspecialchars(t('shortcuts.help_title'), ENT_QUOTES, 'UTF-8');
$tAdd               = htmlspecialchars(t('common.add'), ENT_QUOTES, 'UTF-8');
$jsonFlags          = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$headerControls = <<<HTML
    <input id="globalSearch" data-cy="search" type="text" placeholder="{$tSearchPlaceholder}" />
    <select id="columnFilter" data-cy="column-filter"><option value="">{$tAllColumns}</option></select>
    <div id="filterBar"></div>
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
                    <option value=""><?= $tChooseAction ?></option>
                    <?php if (($userRole ?? '') === 'editor') : ?>
                    <option value="add"><?= $tAddRow ?></option>
                    <?php endif; ?>
                    <option value="export"><?= $tExportCsv ?></option>
                    <option value="refresh"><?= $tRefreshTable ?></option>
                    <?php if (($userRole ?? '') === 'editor') : ?>
                    <option value="data-cleanup"><?= $tDataCleanup ?></option>
                    <?php endif; ?>
                    <option value="keyboard-help"><?= $tShortcutsHelp ?></option>
                </select>

                <?php if (($userRole ?? '') === 'editor') : ?>
                <button id="addRow" data-cy="add" class="success"><?= $tAdd ?></button>
                <?php endif; ?>
                <button id="exportCsv" data-cy="export"><?= $tExportCsv ?></button>
                <?php if (($userRole ?? '') === 'editor') : ?>
                <button id="dataCleanupBtn" data-cy="data-cleanup"><?= $tDataCleanup ?></button>
                <?php endif; ?>
                <button id="kgHelpBtn" data-cy="keyboard-help" class="kg-help-btn"
                        title="<?= $tShortcutsHelp ?>">&#9000;</button>
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
    window.USER_ROLE = <?php echo json_encode($userRole ?? 'viewer', $jsonFlags); ?>;
    <?php
        $rawSchemaTpl = @file_get_contents(__DIR__ . '/../config/schema.json');
        $decodedSchemaTpl = $rawSchemaTpl ? @json_decode($rawSchemaTpl, true) : null;
        $schemaTableNames = is_array($decodedSchemaTpl['tables'] ?? null)
            ? array_keys($decodedSchemaTpl['tables'])
            : [];
    ?>
    window.SCHEMA_TABLES = <?php echo json_encode($schemaTableNames, $jsonFlags); ?>;
    document.addEventListener("DOMContentLoaded", () => {
        const mobileActions = document.getElementById("mobileActions");
        const clickById = id => { const b = document.getElementById(id); if (b) b.click(); };
        if (mobileActions) {
            mobileActions.addEventListener("change", e => {
                const action = e.target.value;
                if (action === "add") clickById("addRow");
                if (action === "export") clickById("exportCsv");
                if (action === "data-cleanup") clickById("dataCleanupBtn");
                if (action === "keyboard-help") clickById("kgHelpBtn");
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
