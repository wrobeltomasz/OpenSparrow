<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Http;

final class PhpSession implements SessionInterface
{
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    #[\Override]
    public function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    #[\Override]
    public function role(): string
    {
        return (string)($_SESSION['role'] ?? 'viewer');
    }
}
