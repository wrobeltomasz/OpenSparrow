<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function rag_throttle_dir(): string
{
    $dir = __DIR__ . '/../storage/ratelimit';
    os_ensure_directory($dir, 0775);
    os_write_guard_file(
        $dir . '/.htaccess',
        "# Deny all direct web access to throttle state.\nDeny from all\n"
    );
    return $dir;
}

function rag_rate_limit_ok(int $userId, int $maxPerMinute): bool
{
    if ($maxPerMinute <= 0) {
        return true;
    }
    $now  = time();
    $file = rag_throttle_dir() . '/user_' . $userId . '.json';
    $fh   = @fopen($file, 'c+');
    if ($fh === false) {
        error_log('[rag] rate limit not enforced, throttle state unwritable: ' . $file . os_last_error_reason());
        return true;
    }
    $allowed = true;
    if (flock($fh, LOCK_EX)) {
        $raw    = stream_get_contents($fh);
        $stamps = (is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : [];

        $stamps = array_values(array_filter($stamps, static fn($t): bool => ($now - (int) $t) < 60));
        if (count($stamps) >= $maxPerMinute) {
            $allowed = false;
        } else {
            $stamps[] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($stamps));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $allowed;
}

function rag_semaphore_acquire(int $maxConcurrent)
{
    if ($maxConcurrent <= 0) {
        return null;
    }
    $dir = rag_throttle_dir();
    for ($i = 0; $i < $maxConcurrent; $i++) {
        $fh = @fopen($dir . '/sem_' . $i . '.lock', 'c');
        if ($fh === false) {
            continue;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            return $fh;
        }
        fclose($fh);
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
