<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form;

final readonly class RenderContext
{
    public function __construct(
        public bool $readOnly,
        public array $fkOptions = [],
        public array $prefilled = [],
        public array $locked = [],
    ) {
    }

    public function fkOptionsFor(string $columnName): array
    {
        return $this->fkOptions[$columnName] ?? [];
    }

    public function isPrefilled(string $columnName): bool
    {
        return isset($this->prefilled[$columnName]);
    }

    public function prefilledValue(string $columnName): string
    {
        return $this->prefilled[$columnName] ?? '';
    }

    public function isLocked(string $columnName): bool
    {
        return $this->readOnly || ($this->locked[$columnName] ?? false);
    }
}
