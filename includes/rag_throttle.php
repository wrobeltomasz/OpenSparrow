<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limit.php';

function rag_throttle_dir(): string
{
    return os_rate_limit_dir();
}

function rag_rate_limit_ok(int $userId, int $maxPerMinute): bool
{
    return os_rate_limit_ok('user_' . $userId, $maxPerMinute, 'rag');
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
