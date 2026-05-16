<?php
// session_debug.php — TEMPORARY diagnostic tool. DELETE AFTER USE.
// Access: https://demo.opensparrow.org/session_debug.php

require __DIR__ . '/includes/config.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => SECURE_COOKIES,
    'httponly' => true,
    'samesite' => SESSION_SAMESITE,
]);

session_start();

header('Content-Type: text/plain; charset=utf-8');

$sp = session_save_path() ?: sys_get_temp_dir();
$sid = session_id();
$sessFile = $sp . '/sess_' . $sid;

echo "=== SESSION DIAGNOSTIC ===\n\n";

echo "--- PHP Configuration ---\n";
echo "PHP Version:           " . PHP_VERSION . "\n";
echo "session.save_handler:  " . ini_get('session.save_handler') . "\n";
echo "session.save_path:     '" . ini_get('session.save_path') . "'\n";
echo "session.name:          " . ini_get('session.name') . "\n";
echo "session.cookie_path:   " . ini_get('session.cookie_path') . "\n";
echo "session.cookie_domain: '" . ini_get('session.cookie_domain') . "'\n";
echo "session.cookie_secure: " . ini_get('session.cookie_secure') . "\n";
echo "session.cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
echo "session.cookie_samesite: " . ini_get('session.cookie_samesite') . "\n";
echo "session.use_strict_mode: " . ini_get('session.use_strict_mode') . "\n";
echo "session.use_only_cookies: " . ini_get('session.use_only_cookies') . "\n";
echo "session.gc_maxlifetime:  " . ini_get('session.gc_maxlifetime') . "\n";
echo "open_basedir:          " . (ini_get('open_basedir') ?: '(none)') . "\n";

echo "\n--- OpenSparrow Config ---\n";
echo "SECURE_COOKIES:        " . (SECURE_COOKIES ? 'true' : 'false') . "\n";
echo "SESSION_SAMESITE:      " . SESSION_SAMESITE . "\n";
echo "SESSION_MAX_LIFETIME:  " . SESSION_MAX_LIFETIME . "\n";

echo "\n--- Request Environment ---\n";
echo "REQUEST_URI:           " . ($_SERVER['REQUEST_URI'] ?? '?') . "\n";
echo "HTTPS:                 " . ($_SERVER['HTTPS'] ?? '(unset)') . "\n";
echo "SERVER_PORT:           " . ($_SERVER['SERVER_PORT'] ?? '?') . "\n";
echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(none)') . "\n";
echo "HTTP_X_FORWARDED_SSL:  " . ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '(none)') . "\n";
echo "HTTP_CF_VISITOR:       " . ($_SERVER['HTTP_CF_VISITOR'] ?? '(none)') . "\n";
echo "HTTP_CF_RAY:           " . ($_SERVER['HTTP_CF_RAY'] ?? '(none)') . "\n";

echo "\n--- Current Session ---\n";
echo "Session ID:            $sid\n";
echo "Session status:        " . session_status() . " (1=disabled, 2=active)\n";
echo "Save path resolved:    $sp\n";
echo "Session file:          $sessFile\n";
echo "File exists:           " . (file_exists($sessFile) ? 'YES' : 'NO') . "\n";
if (file_exists($sessFile)) {
    echo "File size:             " . filesize($sessFile) . " bytes\n";
    echo "File readable:         " . (is_readable($sessFile) ? 'YES' : 'NO') . "\n";
    echo "File mtime:            " . date('Y-m-d H:i:s', filemtime($sessFile)) . "\n";
    echo "File content (first 500 bytes):\n" . substr(file_get_contents($sessFile), 0, 500) . "\n";
}
echo "Save path writable:    " . (is_writable($sp) ? 'YES' : 'NO') . "\n";

echo "\n--- Cookies Received ---\n";
foreach ($_COOKIE as $k => $v) {
    echo "  $k = " . substr($v, 0, 100) . (strlen($v) > 100 ? '...' : '') . "\n";
}

echo "\n--- \$_SESSION Contents ---\n";
echo json_encode($_SESSION, JSON_PRETTY_PRINT) . "\n";

echo "\n--- Session Files in Save Path ---\n";
if (is_dir($sp)) {
    $files = @glob($sp . '/sess_*');
    if ($files) {
        $cnt = count($files);
        echo "Total session files: $cnt\n";
        echo "Most recent 5:\n";
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, 0, 5) as $f) {
            echo "  " . basename($f) . " (size=" . filesize($f) . ", mtime=" . date('H:i:s', filemtime($f)) . ")\n";
        }
    } else {
        echo "(no session files found or permission denied)\n";
    }
} else {
    echo "Save path is not a directory!\n";
}

echo "\n=== TEST SESSION WRITE/READ ===\n";
$_SESSION['debug_timestamp'] = time();
$_SESSION['debug_random'] = bin2hex(random_bytes(8));
session_write_close();
echo "Wrote test data to session.\n";
echo "Now reload this page — debug_timestamp and debug_random should persist.\n";
echo "If they DON'T persist on reload, session writing is BROKEN.\n";

echo "\n=== END DIAGNOSTIC ===\n";
echo "DELETE THIS FILE AFTER USE!\n";
