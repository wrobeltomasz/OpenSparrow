<?php

// file_download.php
// OpenSparrow Secure File Download Proxy
// Usage: file_download.php?uuid=<uuid>
// Usage: file_download.php?uuid=<uuid>&thumb=1

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/api_helpers.php';
start_session();
send_security_headers('', false, 'download');
// Block access without active session
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorised');
}

$uuid  = trim($_GET['uuid'] ?? '');
$thumb = !empty($_GET['thumb']);
if ($uuid === '') {
    http_response_code(400);
    exit('Missing uuid');
}

// UUID format sanity check to prevent path traversal
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    exit('Invalid uuid');
}

// Connect to database
$conn = db_connect();
// Fetch record from database
$sql = "
    SELECT name, storage_path, mime_type, deleted_at, related_table, related_id
    FROM " . sys_table('files') . "
    WHERE uuid = $1
";
$res = @pg_query_params($conn, $sql, [$uuid]);
if (!$res || pg_num_rows($res) === 0) {
    http_response_code(404);
    exit('File not found in database');
}

$row = pg_fetch_assoc($res);
pg_free_result($res);
// Block access if file was soft deleted
if ($row['deleted_at'] !== null) {
    http_response_code(404);
    exit('File was deleted');
}

// Row-level authorization: when the file is attached to a record, enforce the same
// ownership policy used by the rest of the app (can_access_record). Files tied to no
// record, or to a table without owner_restriction, stay accessible to any logged-in
// user. A failed check returns the same 404 as a missing file — existence is never
// disclosed.
$relatedTable = $row['related_table'] ?? null;
$relatedId    = $row['related_id'] ?? null;
if ($relatedTable !== null && $relatedId !== null && $relatedId !== '') {
    $schemaJson = @file_get_contents(__DIR__ . '/config/schema.json');
    $schema     = $schemaJson !== false ? json_decode($schemaJson, true) : null;
    $tableCfg   = $schema['tables'][$relatedTable] ?? null;
    if (is_array($tableCfg)) {
        $uid  = (int) $_SESSION['user_id'];
        $role = $_SESSION['role'] ?? '';
        if (!can_access_record($conn, $tableCfg, $relatedTable, (int) $relatedId, $uid, $role)) {
            http_response_code(404);
            exit('File not found in database');
        }
    }
}

// Construct absolute physical path
$filePath = __DIR__ . '/' . $row['storage_path'];
$realBase = realpath(__DIR__ . '/storage');
$realFile = realpath($filePath);
if ($realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('Access denied');
}

if (!file_exists($realFile)) {
    http_response_code(404);
    exit('Physical file is missing from storage');
}

$mime = $row['mime_type'];
$name = $row['name'];
// Serve thumbnail if requested and file is an image (excluding SVG to prevent XSS via thumbnail endpoint)
if ($thumb && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
    serveThumbnail($realFile, $mime);
    exit;
}

// Clean and encode filename safely using RFC 5987
$safeName = rawurlencode(basename(str_replace(["\r","\n","\0"], '', $name)));
// Force download for SVG to prevent XSS execution
if ($mime === 'image/svg+xml') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
// Set headers for standard file download or inline preview
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . $safeName);
}

header('Content-Length: ' . filesize($realFile));
header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);
// Output physical file content
readfile($realFile);
exit;
// Thumbnail generation helper function
function serveThumbnail(string $path, string $mime): void
{
    // Serve original if GD library is not installed
    if (!extension_loaded('gd')) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);
        readfile($path);
        return;
    }

    // Create image resource based on mime type
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => null,
    };
// Serve original if format is unsupported by GD like SVG
    if (!$src) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);
        readfile($path);
        return;
    }

    $maxW = THUMBNAIL_MAX_WIDTH;
    $origW = imagesx($src);
    $origH = imagesy($src);
// Keep original if smaller than max width
    if ($origW <= $maxW) {
        $thumb = $src;
    } else {
        $ratio = $maxW / $origW;
        $newH  = (int) round($origH * $ratio);
        $thumb = imagecreatetruecolor($maxW, $newH);
    // Preserve transparency for PNG images
        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $maxW, $newH, $origW, $origH);
    }

    // Set cache headers for thumbnails
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=' . THUMBNAIL_CACHE_MAX_AGE);
// Render scaled image
    match ($mime) {
        'image/jpeg' => imagejpeg($thumb, null, 80),
        'image/png'  => imagepng($thumb, null, 6),
        'image/gif'  => imagegif($thumb),
        'image/webp' => imagewebp($thumb, null, 80),
    };
// Free memory
    imagedestroy($thumb);
    if ($thumb !== $src) {
        imagedestroy($src);
    }
}
