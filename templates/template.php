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

// Single source of truth for the grid actions row, rendered below into both
// the mobile <select> and the desktop buttons so the role gate isn't repeated per target.
$gridActions = [
    [
        'value' => 'add',
        'optionLabel' => $tAddRow,
        'role' => 'editor',
        'button' => ['id' => 'addRow', 'cy' => 'add', 'class' => 'success', 'label' => $tAdd],
    ],
    [
        'value' => 'export',
        'optionLabel' => $tExportCsv,
        'role' => null,
        'button' => ['id' => 'exportCsv', 'cy' => 'export', 'class' => null, 'label' => $tExportCsv],
    ],
    [
        'value' => 'refresh',
        'optionLabel' => $tRefreshTable,
        'role' => null,
        'button' => null,
    ],
    [
        'value' => 'data-cleanup',
        'optionLabel' => $tDataCleanup,
        'role' => 'editor',
        'button' => ['id' => 'dataCleanupBtn', 'cy' => 'data-cleanup', 'class' => null, 'label' => $tDataCleanup],
    ],
    [
        'value' => 'keyboard-help',
        'optionLabel' => $tShortcutsHelp,
        'role' => null,
        'button' => [
            'id' => 'kgHelpBtn',
            'cy' => 'keyboard-help',
            'class' => 'kg-help-btn',
            'label' => '&#9000;',
            'title' => $tShortcutsHelp,
        ],
    ],
];
$gridActionAllowed = fn ($a) => !$a['role'] || ($userRole ?? '') === $a['role'];
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
                    <?php foreach ($gridActions as $a) : ?>
                        <?php if (!$gridActionAllowed($a)) {
                            continue;
                        } ?>
                    <option value="<?= $a['value'] ?>"><?= $a['optionLabel'] ?></option>
                    <?php endforeach; ?>
                </select>

                <?php foreach ($gridActions as $a) : ?>
                    <?php if (!$a['button'] || !$gridActionAllowed($a)) {
                        continue;
                    } ?>
                    <?php
                    $b = $a['button'];
                    $bAttrs = ($b['class'] ? ' class="' . $b['class'] . '"' : '')
                        . (isset($b['title']) ? ' title="' . $b['title'] . '"' : '');
                    ?>
                <button id="<?= $b['id'] ?>" data-cy="<?= $b['cy'] ?>"<?= $bAttrs ?>><?= $b['label'] ?></button>
                <?php endforeach; ?>
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
        require_once __DIR__ . '/../includes/config_store.php';
        $decodedSchemaTpl = config_get('schema');
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
