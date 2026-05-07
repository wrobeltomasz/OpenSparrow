<?php
// admin/index.php

require __DIR__ . '/../includes/config.php';

// First-run check: if database.json doesn't exist, redirect to setup wizard
if (!file_exists(__DIR__ . '/../includes/database.json')) {
    header('Location: ../setup.php');
    exit;
}

// Set secure session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => SECURE_COOKIES,
    'httponly' => true,
    'samesite' => SESSION_SAMESITE,
]);

session_start();

// First-run bypass: if spw_users table doesn't exist yet the panel must be
// reachable so the operator can run "Initialize System Tables". Once the table
// exists and contains at least one admin account, normal auth applies.
$firstRun = false;
require_once __DIR__ . '/../includes/db.php';
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
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { margin: 0; font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; text-align: center; }
            .card { background: white; padding: 40px 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-width: 380px; border: 1px solid #e2e8f0; }
            h1 { color: #ef4444; font-size: 22px; margin-bottom: 10px; }
            p { color: #64748b; font-size: 14px; }
            a { color: #3b82f6; text-decoration: none; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Access Denied</h1>
            <p>Your account does not have permission to access the admin panel.</p>
            <p><a href="../">Return to application</a></p>
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

            <!-- DATA MANAGEMENT -->
            <div class="nav-section open">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/data_table.png" alt="">
                    <span class="nav-section-label">Data Management</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab active" data-file="schema">
                        <img class="nav-item-icon" src="../assets/icons/data_table.png" alt="">
                        Schema
                    </button>
                    <button class="admin-tab" data-file="dashboard">
                        <img class="nav-item-icon" src="../assets/icons/ballot.png" alt="">
                        Dashboard
                    </button>
                    <button class="admin-tab" data-file="calendar">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Calendar
                    </button>
                    <button class="admin-tab" data-file="files">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        Files
                    </button>
                    <button class="admin-tab" data-file="menu">
                        <img class="nav-item-icon" src="../assets/icons/table_edit.png" alt="">
                        Menu Preview
                    </button>
                    <button class="admin-tab" data-file="add_table">
                        <img class="nav-item-icon" src="../assets/icons/build.png" alt="">
                        Add Table
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
                    <button class="admin-tab" data-file="workflows">
                        <img class="nav-item-icon" src="../assets/icons/build.png" alt="">
                        Workflow Manager
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
                    <button class="admin-tab" data-file="database">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        Database
                    </button>
                    <button class="admin-tab" data-file="users">
                        <img class="nav-item-icon" src="../assets/icons/user_attributes.png" alt="">
                        Users
                    </button>
                    <button class="admin-tab" data-file="health">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Health Check
                    </button>
                    <button class="admin-tab" data-file="backup">
                        <img class="nav-item-icon" src="../assets/icons/inventory.png" alt="">
                        Backup Tables
                    </button>
                    <button class="admin-tab" data-file="audit">
                        <img class="nav-item-icon" src="../assets/icons/fact_check.png" alt="">
                        Audit &amp; Snapshots
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

        <!-- Editor area: workspace + right item list sidebar -->
        <div class="admin-content">

            <section class="admin-workspace" id="workspace">
                <h2 style="margin-top: 0;">Select an item to edit</h2>
                <div id="editorForm"></div>
            </section>

            <!-- Right sidebar edge collapse tab -->
            <button class="sidebar-edge-toggle" id="sidebarEdgeToggle" title="Toggle item list" aria-label="Toggle item list">&#8250;</button>

            <aside class="admin-sidebar" id="sidebar">
                <h3 id="sidebarTitle">Tables</h3>
                <ul id="itemList"></ul>
            </aside>

        </div>

    </div><!-- /admin-main -->

</div><!-- /admin-layout -->

<!-- Hidden file input for import (must remain in DOM) -->
<input type="file" id="importFileInput" accept=".zip" style="display: none;">

<script type="module" src="app.js?v=<?php echo @filemtime('app.js'); ?>"></script>
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

    // Right sidebar collapse — edge tab
    var sidebarEdgeToggle = document.getElementById('sidebarEdgeToggle');
    var adminSidebar      = document.getElementById('sidebar');

    function toggleSidebar() {
        var collapsed = adminSidebar.classList.toggle('collapsed');
        sidebarEdgeToggle.innerHTML = collapsed ? '&#8249;' : '&#8250;';
    }
    sidebarEdgeToggle.addEventListener('click', toggleSidebar);

    // Breadcrumb: update on tab click
    var breadcrumbLabels = {
        schema: 'Schema', dashboard: 'Dashboard', calendar: 'Calendar',
        files: 'Files', menu: 'Menu Preview', workflows: 'Workflows',
        database: 'Database', users: 'Users', health: 'Health Check',
        backup: 'Backup Tables', audit: 'Audit & Snapshots', docs: 'Documentation'
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
