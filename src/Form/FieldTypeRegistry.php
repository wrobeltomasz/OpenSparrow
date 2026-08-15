<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form;

use App\Domain\Schema\ColumnConfig;

final readonly class FieldTypeRegistry
{
    public function __construct(private array $types)
    {
    }

    public function for(ColumnConfig $column, bool $hasForeignKey): FieldTypeInterface
    {
        return array_find(
            $this->types,
            fn(FieldTypeInterface $fieldType): bool => $fieldType->supports($column, $hasForeignKey)
        )
            ?? throw new \LogicException(
                "No FieldType supports column '{$column->name}' (type: {$column->type}). "
                . 'Ensure TextField is registered as the last fallback.'
            );
    }
}
