<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="current-user-id" content="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">
    <title>Sparrow Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES); ?>">

    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/buttons.css">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime('style.css'); ?>">
</head>
<body>

<div id="mig-pending-banner" style="display:none; background:#fef3c7; border-bottom:1px solid #fbbf24; padding:10px 24px; color:#78350f;">
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
