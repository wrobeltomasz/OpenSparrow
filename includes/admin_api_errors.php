<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin_api_errors.php — shared error-envelope helpers for admin/api.php
// and any other trusted internal caller that reuses admin-layer logic directly
// (e.g. public/setup_api.php calling demo_install_run() during first-run setup).
// AdminApiMessage: deliberate, user-facing message thrown by validation code.
// admin_db_fail(): logs the raw pg error and throws AdminApiMessage with a
// generic message, so file paths/SQL/credentials never reach the HTTP response.

if (!class_exists('AdminApiMessage')) {
    final class AdminApiMessage extends RuntimeException
    {
    }
}

if (!function_exists('admin_error_message')) {
    // Map a caught exception to a client-safe message.
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
