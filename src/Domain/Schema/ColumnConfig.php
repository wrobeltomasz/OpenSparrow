<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Domain\Schema;

final readonly class ColumnConfig
{
    public function __construct(
        public string $name,
        public string $type,
        public string $displayName,
        public bool $readonly = false,
        public bool $notNull = false,
        public bool $showInEdit = true,
        public array $options = [],
        public array $enumColors = [],
        public ?string $validationRegexp = null,
        public ?string $validationMessage = null,
    ) {
    }

    public function isVirtual(): bool
    {
        return $this->type === 'virtual';
    }

    public function isBool(): bool
    {
        return str_contains(strtolower($this->type), 'bool');
    }

    public function isDate(): bool
    {
        return str_contains(strtolower($this->type), 'date');
    }

    public function isTimestamp(): bool
    {
        return str_contains(strtolower($this->type), 'timestamp');
    }

    public function isEnum(): bool
    {
        $normalizedType = strtolower($this->type);
        return $normalizedType === 'enum' || str_starts_with($normalizedType, 'enum');
    }
}
