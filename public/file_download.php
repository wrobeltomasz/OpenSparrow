<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Exception\BadRequestException;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\UnauthorizedException;

os_register_exception_handler('html');
start_session();
send_security_headers('', false, 'download');

if (empty($_SESSION['user_id'])) {
    throw new UnauthorizedException('Unauthorised');
}

$queryParameters = os_request()->queryAll();
$uuid  = trim(os_query_string('uuid'));
$thumb = !empty($queryParameters['thumb']);
if ($uuid === '') {
    throw new BadRequestException('Missing uuid');
}

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
    throw new BadRequestException('Invalid uuid');
}

$conn = db_connect();

$sql = "
    SELECT name, storage_path, mime_type, deleted_at, related_table, related_id
    FROM " . sys_table('files') . "
    WHERE uuid = $1
";
$result = @pg_query_params($conn, $sql, [$uuid]);
if (!$result || pg_num_rows($result) === 0) {
    throw new NotFoundException('File not found in database');
}

$row = pg_fetch_assoc($result);
pg_free_result($result);

if ($row['deleted_at'] !== null) {
    throw new NotFoundException('File was deleted');
}

$relatedTable = $row['related_table'] ?? null;
$relatedId    = $row['related_id'] ?? null;
if ($relatedTable !== null && $relatedId !== null && $relatedId !== '') {
    require_once __DIR__ . '/../includes/config_store.php';
    $schema   = config_get('schema');
    $tableConfig = $schema['tables'][$relatedTable] ?? null;
    if (is_array($tableConfig)) {
        $userId  = (int) $_SESSION['user_id'];
        $role = $_SESSION['role'] ?? '';

        if (!user_can_access_table((string) $relatedTable)) {
            throw new NotFoundException('File not found in database');
        }
        if (!can_access_record($conn, $tableConfig, $relatedTable, (int) $relatedId, $userId, $role)) {
            throw new NotFoundException('File not found in database');
        }
    }
}

$filePath = os_storage_path($row['storage_path']);
$realBase = realpath(os_storage_path());
$realFile = realpath($filePath);
if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
    throw new ForbiddenException('Access denied');
}

if (!file_exists($realFile)) {
    throw new NotFoundException('Physical file is missing from storage');
}

$mime = $row['mime_type'];
$name = $row['name'];

if ($thumb && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
    serveThumbnail($realFile, $mime);
    throw ResponseException::sent();
}

$safeName = rawurlencode(basename(str_replace(["\r","\n","\0"], '', $name)));

$inlineSafe = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
if ($inlineSafe) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . $safeName);
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
}

header('Content-Length: ' . filesize($realFile));
header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);

readfile($realFile);
throw ResponseException::sent();

function serveThumbnail(string $path, string $mime): void
{
    if (!extension_loaded('gd')) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);
        readfile($path);
        return;
    }

    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => null,
    };

    if (!$source) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=' . FILE_CACHE_MAX_AGE);
        readfile($path);
        return;
    }

    $maxWidth = THUMBNAIL_MAX_WIDTH;
    $originalWidth = imagesx($source);
    $originalHeight = imagesy($source);

    if ($originalWidth <= $maxWidth) {
        $thumb = $source;
    } else {
        $ratio = $maxWidth / $originalWidth;
        $newH  = (int) round($originalHeight * $ratio);
        $thumb = imagecreatetruecolor($maxWidth, $newH);

        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $maxWidth, $newH, $originalWidth, $originalHeight);
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=' . THUMBNAIL_CACHE_MAX_AGE);

    match ($mime) {
        'image/jpeg' => imagejpeg($thumb, null, 80),
        'image/png'  => imagepng($thumb, null, 6),
        'image/gif'  => imagegif($thumb),
        'image/webp' => imagewebp($thumb, null, 80),
    };

    imagedestroy($thumb);
    if ($thumb !== $source) {
        imagedestroy($source);
    }
}
