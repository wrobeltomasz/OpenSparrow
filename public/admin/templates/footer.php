</div><!-- /admin-layout -->

<?php
// One cache-busting version for the whole admin module graph: app.js pulls in ~30
// sibling modules with bare specifiers, so versioning the entry file alone left every
// one of them frozen in the browser cache after an update. The entry tag below must
// use the same version as the map — app.js is both the entry AND an import target,
// and a module reachable under two URLs gets instantiated twice (duplicate listeners,
// split state: lost saves, phantom dirty flags).
// Admin pages send no CSP, hence the empty nonce.
require_once __DIR__ . '/../../../includes/page_helpers.php';
$adminGraph = os_module_graph([
    './js/'                   => __DIR__ . '/../js',
    '../assets/js/util/'      => __DIR__ . '/../../assets/js/util',
    '../assets/js/dashboard/' => __DIR__ . '/../../assets/js/dashboard',
]);
$appJsVer = $adminGraph['version'];
echo os_import_map($adminGraph['imports']);
?>
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
