</div><!-- /admin-layout -->

<?php
// One cache-busting version for the whole admin module graph. app.js pulls in ~30
// sibling modules with bare specifiers, so versioning the entry file alone left every
// one of them frozen in the browser cache after an update — a shipped change simply
// never reached users, and the symptom ("this feature does nothing") gives no hint
// that stale JavaScript is the cause.
//
// The map rewrites every module URL to the SAME "?v=", which is what makes this safe:
// a module reachable under two different URLs is instantiated twice by the browser
// (duplicate listeners, split state: lost saves, phantom dirty flags). One version for
// the whole graph, derived from the newest file, keeps every specifier consistent.
// Built from globs so a new module is covered without touching this file.
$moduleGroups = [
    './js/'                   => glob(__DIR__ . '/../js/*.js') ?: [],
    '../assets/js/util/'      => glob(__DIR__ . '/../../assets/js/util/*.js') ?: [],
    '../assets/js/dashboard/' => glob(__DIR__ . '/../../assets/js/dashboard/*.js') ?: [],
];

$appJsVer = 0;
foreach ($moduleGroups as $files) {
    foreach ($files as $file) {
        $appJsVer = max($appJsVer, (int) @filemtime($file));
    }
}

$importMap = [];
foreach ($moduleGroups as $urlPrefix => $files) {
    foreach ($files as $file) {
        $spec              = $urlPrefix . basename($file);
        $importMap[$spec] = $spec . '?v=' . $appJsVer;
    }
}
?>
<script type="importmap">
    <?php echo json_encode(
        ['imports' => $importMap],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
    ); ?>
</script>
<script type="module" src="js/app.js?v=<?php echo $appJsVer; ?>"></script>
<script>
    // Collapsible nav sections
    document.querySelectorAll('.nav-section-header').forEach(function(header) {
        header.addEventListener('click', function() {
            header.closest('.nav-section').classList.toggle('open');
        });
    });

    // Left nav collapse — edge tab
    var navEdgeToggle = document.getElementById('navEdgeToggle');
    var adminNav      = document.getElementById('adminNav');
    var adminLayout   = document.querySelector('.admin-layout');

    function toggleNav() {
        var collapsed = adminNav.classList.toggle('collapsed');
        adminLayout.classList.toggle('nav-collapsed', collapsed);
        navEdgeToggle.innerHTML = collapsed ? '&#8250;' : '&#8249;';
    }
    navEdgeToggle.addEventListener('click', toggleNav);

    // Breadcrumb: update on tab click
    var breadcrumbLabels = {
        schema: 'Schema', dashboard: 'Dashboard', calendar: 'Calendar',
        files: 'Files', workflows: 'Workflows',
        users: 'Users', health: 'Health Check',
        backup: 'Backup Tables', docs: 'Documentation',
        performance: 'Performance',
        cron: 'Cron Notifications',
        views: 'Views',
        csv_import: 'CSV Import',
        rag: 'RAG Documents',
        automations: 'Automations',
        etl: 'ETL',
        anonymization: 'Data Anonymization',
        print: 'Printouts'
    };
    var breadcrumbCurrent = document.getElementById('breadcrumbCurrent');
    document.querySelectorAll('.admin-tab[data-file]').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var label = breadcrumbLabels[tab.dataset.file] || tab.dataset.file;
            breadcrumbCurrent.textContent = label;
        });
    });
</script>
</body>
</html>
