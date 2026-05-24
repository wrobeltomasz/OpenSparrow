<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? 'viewer') === 'admin') {
    header('Location: admin/');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cspNonce  = bin2hex(random_bytes(16));
$viewName  = substr($_GET['view'] ?? '', 0, 64);
$userRole  = $_SESSION['role'] ?? 'viewer';

send_security_headers($cspNonce, true, 'unsafe-style');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <title>OpenSparrow — Views</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <link href="assets/css/styles.css" rel="stylesheet">
    <link href="assets/css/mobile.css" rel="stylesheet" media="only screen and (max-width: 768px)">
    <link href="assets/css/views.css" rel="stylesheet">
</head>
<body>
<?php
$headerControls = '<input id="globalSearch" type="text" placeholder="' . htmlspecialchars(t('grid.search_placeholder'), ENT_QUOTES, 'UTF-8') . '" />';
include __DIR__ . '/templates/header.php';
?>
<main>
    <section id="viewSection">
        <div id="viewBreadcrumb" class="vw-breadcrumb"></div>
        <div id="viewContainer" class="vw-container">
            <div class="vw-loading"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>
</main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>

<script nonce="<?php echo $cspNonce; ?>">
    window.VIEWS_INITIAL = <?php echo json_encode($viewName ?: null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.CSRF_TOKEN    = <?php echo json_encode($_SESSION['csrf_token']); ?>;
</script>
<script type="module" src="assets/js/views.js?v=<?php echo @filemtime('assets/js/views.js'); ?>" nonce="<?php echo $cspNonce; ?>"></script>
</body>
</html>
