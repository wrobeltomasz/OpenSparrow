<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final readonly class ApiRequest
{
    public function __construct(
        public string $method,
        public string $action,
        private array $body,
    ) {
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function bodyAll(): array
    {
        return $this->body;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === $method;
    }

    public function isWrite(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
