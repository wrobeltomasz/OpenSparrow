<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (!class_exists('AdminApiMessage')) {
    final class AdminApiMessage extends RuntimeException
    {
    }
}

if (!function_exists('admin_error_message')) {
    function admin_error_message(Throwable $e): string
    {
        if ($e instanceof AdminApiMessage) {
            return $e->getMessage();
        }
        error_log('[admin_api][unhandled] ' . get_class($e) . ': ' . $e->getMessage());
        return 'Internal error. Check server logs for details.';
    }
}

if (!function_exists('admin_db_fail')) {
    function admin_db_fail($conn, string $context): void
    {
        $raw = $conn !== null ? pg_last_error($conn) : 'no connection';
        error_log('[admin_api][' . $context . '] ' . $raw);
        throw new AdminApiMessage('Database operation failed. Check server logs for details.');
    }
}
