<?php
// admin/index.php — Admin panel shell (HTML + JS module loader, role: admin only)
// First-run: redirects to ../setup.php if database.json is missing; allows access before spw_users exists so the operator can run "Initialize System Tables", otherwise requires login + admin role
// Renders the admin SPA; tabs/logic live in admin/js/* (loaded by app.js)

require_once __DIR__ . '/../../includes/session.php';

// First-run check: if database.json doesn't exist, redirect to setup wizard
if (!file_exists(__DIR__ . '/../../config/database.json')) {
    header('Location: ../setup.php');
    exit;
}

start_session();

// First-run bypass: if spw_users table doesn't exist yet the panel must be
// reachable so the operator can run "Initialize System Tables". Once the table
// exists and contains at least one admin account, normal auth applies.
$firstRun = false;
require_once __DIR__ . '/../../includes/db.php';
$_conn = @db_connect();
if (!$_conn) {
    $firstRun = true;
} else {
    $tUsers = sys_table('users');
    $chk = @pg_query($_conn, "SELECT 1 FROM $tUsers LIMIT 1");
    if ($chk === false) {
        $firstRun = true;
    }
}
unset($_conn, $chk);

// Redirect to login if not authenticated (skipped on first run)
if (!$firstRun && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Only admin role may access this panel (skipped on first run)
if (!$firstRun && ($_SESSION['role'] ?? '') !== 'admin') {
    $currentRole = $_SESSION['role'] ?? 'none';
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="../assets/css/styles.css">
    </head>
    <body class="admin-403-page">
        <div class="admin-403-card">
            <h1>Access Denied</h1>
            <p>Your account does not have permission to access the admin panel.</p>
            <p>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'unknown'); ?></strong></p>
            <p>Your role: <strong><?php echo htmlspecialchars($currentRole); ?></strong></p>
            <p>Required role: <strong>admin</strong></p>
            <p><a href="../logout.php">Log out</a> | <a href="../">Return to application</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="current-user-id" content="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">
    <title>Sparrow Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES); ?>">

    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime('style.css'); ?>">
</head>
<body>

<div id="mig-pending-banner" style="display:none; background:#fef3c7; border-bottom:1px solid #fbbf24; padding:10px 24px; font-size:13px; color:#78350f;">
    <strong>Upgrade notice:</strong> <span class="mig-pending-banner-text"></span>
</div>

<?php if ($firstRun) : ?>
<div class="first-run-banner">
    <strong>First-run setup mode.</strong>
    Go to <strong>System &rarr; Database</strong> and click <strong>Initialize System Tables</strong>.
    This will create the default admin account (<code>admin</code> / <code>admin</code>).
    Afterwards <a href="../login.php">log in</a> and change the password immediately.
</div>
<?php endif; ?>

<!-- Header -->
<header class="admin-header">
    <div class="admin-header-left">
        <a href="/" class="brand-logo">
            <img src="../assets/img/logo-blue.png" alt="Sparrow Logo">
        </a>
        <span class="brand-name">OpenSparrow Admin</span>
    </div>

    <div class="admin-header-right">
        <label class="debug-toggle-label">
            <input type="checkbox" id="debugToggle">
            Debug FE
        </label>

        <button id="btnSave" type="button" class="btn-save">Save config</button>

        <button class="admin-tab btn-header-icon" data-file="docs" title="Documentation">
            <img src="../assets/icons/book_3s.png" alt="Docs">
            <span>Docs</span>
        </button>

        <button onclick="window.location.href='../logout.php'" class="btn-header-logout">Logout</button>
    </div>
</header>

<!-- Main layout -->
<div class="admin-layout">

    <!-- Navigation sidebar -->
    <nav class="admin-nav" id="adminNav">
        <div class="nav-sections">

            <!-- OVERVIEW -->
            <div class="nav-section open">
                <div class="nav-section-items" style="padding-top:4px;">
                    <button class="admin-tab active" data-file="overview">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Overview
                    </button>
                </div>
            </div>

            <!-- DATA MANAGEMENT -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/data_table.png" alt="">
                    <span class="nav-section-label">Data Management</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="add_table">
                        <img class="nav-item-icon" src="../assets/icons/build.png" alt="">
                        Add Table
                    </button>
                    <button class="admin-tab" data-file="board">
                        <img class="nav-item-icon" src="../assets/icons/account_tree.png" alt="">
                        Board
                    </button>
                    <button class="admin-tab" data-file="calendar">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Calendar
                    </button>
                    <button class="admin-tab" data-file="csv_import">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        CSV Import
                    </button>
                    <button class="admin-tab" data-file="dashboard">
                        <img class="nav-item-icon" src="../assets/icons/ballot.png" alt="">
                        Dashboard
                    </button>
                    <button class="admin-tab" data-file="fdw">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        External Databases
                    </button>
                    <button class="admin-tab" data-file="files">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        Files
                    </button>
                    <button class="admin-tab" data-file="menu">
                        <img class="nav-item-icon" src="../assets/icons/table_edit.png" alt="">
                        Menu Preview
                    </button>
                    <button class="admin-tab" data-file="print">
                        <img class="nav-item-icon" src="../assets/icons/picture_as_pdf.png" alt="">
                        Printouts
                    </button>
                    <button class="admin-tab" data-file="schema">
                        <img class="nav-item-icon" src="../assets/icons/data_table.png" alt="">
                        Schema
                    </button>
                    <button class="admin-tab" data-file="erd">
                        <img class="nav-item-icon" src="../assets/icons/account_tree.png" alt="">
                        Schema Map
                    </button>
                    <button class="admin-tab" data-file="views">
                        <img class="nav-item-icon" src="../assets/icons/table_chart_view.png" alt="">
                        Views
                    </button>
                </div>
            </div>

            <!-- WORKFLOWS -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/build.png" alt="">
                    <span class="nav-section-label">Workflows</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="automations">
                        <img class="nav-item-icon" src="../assets/icons/automation.png" alt="">
                        Automations
                    </button>
                    <button class="admin-tab" data-file="workflows">
                        <img class="nav-item-icon" src="../assets/icons/build.png" alt="">
                        Workflow Manager
                    </button>
                </div>
            </div>

            <!-- KNOWLEDGE BASE -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/menu_book.png" alt="">
                    <span class="nav-section-label">Knowledge Base</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="rag">
                        <img class="nav-item-icon" src="../assets/icons/docs.png" alt="">
                        RAG Documents
                    </button>
                </div>
            </div>

            <!-- SYSTEM -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/database.png" alt="">
                    <span class="nav-section-label">System</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="anonymization">
                        <img class="nav-item-icon" src="../assets/icons/fact_check.png" alt="">
                        Anonymization
                    </button>
                    <button class="admin-tab" data-file="audit">
                        <img class="nav-item-icon" src="../assets/icons/fact_check.png" alt="">
                        Audit &amp; Snapshots
                    </button>
                    <button class="admin-tab" data-file="backup">
                        <img class="nav-item-icon" src="../assets/icons/inventory.png" alt="">
                        Backup Tables
                    </button>
                    <button class="admin-tab" data-file="cron">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Cron Notifications
                    </button>
                    <button class="admin-tab" data-file="database">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        Database
                    </button>
                    <button class="admin-tab" data-file="demo">
                        <img class="nav-item-icon" src="../assets/icons/playground.png" alt="">
                        Demo Systems
                    </button>
                    <button class="admin-tab" data-file="health">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Health Check
                    </button>
                    <button class="admin-tab" data-file="m2m">
                        <img class="nav-item-icon" src="../assets/icons/account_tree.png" alt="">
                        M2M Builder
                    </button>
                    <button class="admin-tab" data-file="migrations">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        Migrations
                    </button>
                    <button class="admin-tab" data-file="performance">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Performance
                    </button>
                    <button class="admin-tab" data-file="settings">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Settings
                    </button>
                    <button class="admin-tab" data-file="users">
                        <img class="nav-item-icon" src="../assets/icons/user_attributes.png" alt="">
                        Users
                    </button>
                </div>
            </div>

            <!-- CONFIGURATION -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/table_edit.png" alt="">
                    <span class="nav-section-label">Configuration</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button id="btnExport" type="button" class="nav-action-btn">
                        <img class="nav-item-icon" src="../assets/icons/download.png" alt="">
                        Export Config
                    </button>
                    <button id="btnImport" type="button" class="nav-action-btn">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        Import Config
                    </button>
                    <button id="btnRunCron" type="button" class="nav-action-btn">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Run Notifications Cron
                    </button>
                </div>
            </div>

        </div><!-- /nav-sections -->
    </nav><!-- /admin-nav -->

    <!-- Left nav edge collapse tab -->
    <button class="nav-edge-toggle" id="navEdgeToggle" title="Toggle navigation" aria-label="Toggle navigation">&#8249;</button>

    <!-- Main content area -->
    <div class="admin-main">

        <!-- Breadcrumb -->
        <div class="admin-breadcrumb">
            <span class="breadcrumb-root">Admin</span>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current" id="breadcrumbCurrent">Schema</span>
        </div>

        <!-- Editor area: workspace -->
        <div class="admin-content">

            <section class="admin-workspace" id="workspace">
                <div id="itemPanel" class="admin-item-panel"></div>
                <div id="editorForm"></div>
            </section>

        </div>

    </div><!-- /admin-main -->

</div><!-- /admin-layout -->

<!-- Hidden file input for import (must remain in DOM) -->
<input type="file" id="importFileInput" accept=".zip" style="display: none;">

<script type="module" src="js/app.js?v=<?php echo @filemtime('js/app.js'); ?>"></script>
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
        files: 'Files', menu: 'Menu Preview', workflows: 'Workflows',
        database: 'Database', users: 'Users', health: 'Health Check',
        backup: 'Backup Tables', audit: 'Audit & Snapshots', docs: 'Documentation',
        performance: 'Performance',
        cron: 'Cron Notifications',
        views: 'Views',
        csv_import: 'CSV Import',
        rag: 'RAG Documents',
        automations: 'Automations',
        fdw: 'External Databases',
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
