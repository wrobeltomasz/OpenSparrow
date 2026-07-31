<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form;

final readonly class BoundValue
{
    public function __construct(
        public mixed $value,
        public ?string $cast = null,
    ) {
    }

    public function placeholder(int $index): string
    {
        return $this->cast !== null ? "\${$index}::{$this->cast}" : "\${$index}";
    }
}
