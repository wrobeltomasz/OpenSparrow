<?php

declare(strict_types=1);

// RAG throttling: per-user rate limiting and a global concurrency semaphore.
// Both mechanisms are file-based so they require no database schema and release
// cleanly when a PHP worker dies mid-request (the OS frees flock handles on close).

// Returns the directory holding throttle state, creating it on first use.
// Drops a deny-all .htaccess as defense-in-depth for Apache deployments (Nginx
// deployments are covered by the server-level deny list).
function rag_throttle_dir(): string
{
    $dir = __DIR__ . '/../storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "# Deny all direct web access to throttle state.\nDeny from all\n");
    }
    return $dir;
}

// Enforces a per-user sliding-window rate limit. Returns true when the request is
// within the limit, false when the user exceeded $maxPerMinute in the last 60s.
function rag_rate_limit_ok(int $userId, int $maxPerMinute): bool
{
    if ($maxPerMinute <= 0) {
        return true;
    }
    $now  = time();
    $file = rag_throttle_dir() . '/user_' . $userId . '.json';
    $fh   = @fopen($file, 'c+');
    if ($fh === false) {
        // Fail open: never block users because of a transient filesystem issue.
        return true;
    }
    $allowed = true;
    if (flock($fh, LOCK_EX)) {
        $raw    = stream_get_contents($fh);
        $stamps = (is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
        // Drop timestamps that fall outside the 60-second sliding window.
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

// Acquires one of $maxConcurrent global slots. Returns an open file handle that
// holds the slot, or null when every slot is busy or concurrency control is off.
// The OS releases the slot when the handle closes, even on fatal error or timeout.
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

// Releases a slot previously obtained from rag_semaphore_acquire().
function rag_semaphore_release($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
