</div><!-- /admin-layout -->

<?php $appJsVer = @filemtime('js/app.js'); ?>
<!-- Import map: modules import "./app.js" (no query) while the entry tag uses the
     cache-busting "?v=" URL. Without this map the browser would treat those as two
     different modules and instantiate app.js twice (duplicate listeners, split
     state: lost saves, phantom dirty flags). Keep both URLs identical. -->
<script type="importmap">
    {"imports": {"./js/app.js": "./js/app.js?v=<?php echo $appJsVer; ?>"}}
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
