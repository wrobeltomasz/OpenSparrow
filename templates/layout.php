<?php
// templates/layout.php — unified page layout for all app pages.
//
// Variables (set before include):
//   $pageTitle     (string)  — <title> text
//   $cspNonce      (string)  — CSP nonce for inline scripts
//   $extraCss      (string)  — additional <link> or <style> tags for the <head>
//   $extraMeta     (string)  — additional <meta> tags for the <head>
//   $pageContent   (string)  — main HTML content (between header and footer)
//   $extraScripts  (string)  — <script> tags injected before </body>

$pageTitle    ??= 'OpenSparrow';
$cspNonce     ??= '';
$extraCss     ??= '';
$extraMeta    ??= '';
$pageContent  ??= '';
$extraScripts ??= '';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link href="/assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/styles.css') ?>" rel="stylesheet">
    <link href="/assets/css/buttons.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/buttons.css') ?>" rel="stylesheet">
    <link href="/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/mobile.css') ?>"
          rel="stylesheet" media="only screen and (max-width: 768px)">
    <?= $extraCss ?>
    <?= $extraMeta ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<?= $pageContent ?>
</div><!-- /.app-container -->
<?php include __DIR__ . '/footer.php'; ?>
<?= $extraScripts ?>
</body>
</html>
