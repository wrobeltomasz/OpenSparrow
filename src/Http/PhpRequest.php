<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Http;

final class PhpRequest
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function query(string $key, string $default = ''): string
    {
        return (string)($_GET[$key] ?? $default);
    }

    public function post(string $key, string $default = ''): string
    {
        return (string)($_POST[$key] ?? $default);
    }

    public function postAll(): array
    {
        return $_POST;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }
}
