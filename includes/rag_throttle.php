<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function rag_throttle_dir(): string
{
    $directory = __DIR__ . '/../storage/ratelimit';
    os_ensure_directory($directory, 0775);
    os_write_guard_file(
        $directory . '/.htaccess',
        "# Deny all direct web access to throttle state.\nDeny from all\n"
    );
    return $directory;
}

function rag_rate_limit_ok(int $userId, int $maxPerMinute): bool
{
    if ($maxPerMinute <= 0) {
        return true;
    }
    $now  = time();
    $file = rag_throttle_dir() . '/user_' . $userId . '.json';
    $fileHandle   = @fopen($file, 'c+');
    if ($fileHandle === false) {
        error_log('[rag] request refused, throttle state unwritable: ' . $file . os_last_error_reason());
        return false;
    }
    $allowed = false;
    if (flock($fileHandle, LOCK_EX)) {
        $allowed = true;
        $rawStamps    = stream_get_contents($fileHandle);
        $stamps = (is_string($rawStamps) && $rawStamps !== '') ? (json_decode($rawStamps, true) ?: []) : [];

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
        error_log('[rag] request refused, throttle state could not be locked: ' . $file . os_last_error_reason());
    }
    fclose($fileHandle);
    return $allowed;
}

function rag_semaphore_acquire(int $maxConcurrent)
{
    if ($maxConcurrent <= 0) {
        return null;
    }
    $directory = rag_throttle_dir();
    for ($slotIndex = 0; $slotIndex < $maxConcurrent; $slotIndex++) {
        $fileHandle = @fopen($directory . '/sem_' . $slotIndex . '.lock', 'c');
        if ($fileHandle === false) {
            continue;
        }
        if (flock($fileHandle, LOCK_EX | LOCK_NB)) {
            return $fileHandle;
        }
        fclose($fileHandle);
    }
    return null;
}

function rag_semaphore_release($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
