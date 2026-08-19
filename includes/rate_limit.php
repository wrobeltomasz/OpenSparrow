<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function os_rate_limit_dir(): string
{
    $directory = os_storage_path('ratelimit');
    os_ensure_directory($directory, 0775);
    os_write_guard_file(
        $directory . '/.htaccess',
        "Require all denied\n"
    );
    return $directory;
}

function os_rate_limit_ok(string $bucket, int $maxPerMinute, string $label = 'rate-limit'): bool
{
    if ($maxPerMinute <= 0) {
        return true;
    }
    $safeBucket = preg_replace('/[^A-Za-z0-9_.-]/', '_', $bucket);
    $now  = time();
    $file = os_rate_limit_dir() . '/' . $safeBucket . '.json';
    $fileHandle = @fopen($file, 'c+');
    if ($fileHandle === false) {
        error_log('[' . $label . '] request refused, throttle state unwritable: ' . $file . os_last_error_reason());
        return false;
    }
    $allowed = false;
    if (flock($fileHandle, LOCK_EX)) {
        $allowed   = true;
        $rawStamps = stream_get_contents($fileHandle);
        $stamps    = (is_string($rawStamps) && $rawStamps !== '') ? (json_decode($rawStamps, true) ?: []) : [];

        $stamps = array_values(array_filter($stamps, static fn($stamp): bool => ($now - (int) $stamp) < 60));
        if (count($stamps) >= $maxPerMinute) {
            $allowed = false;
        } else {
            $stamps[] = $now;
        }
        ftruncate($fileHandle, 0);
        rewind($fileHandle);
        fwrite($fileHandle, json_encode($stamps));
        fflush($fileHandle);
        flock($fileHandle, LOCK_UN);
    } else {
        error_log(
            '[' . $label . '] request refused, throttle state could not be locked: ' . $file . os_last_error_reason()
        );
    }
    fclose($fileHandle);
    return $allowed;
}
