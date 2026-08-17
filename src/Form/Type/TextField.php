<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\RenderContext;

final class TextField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $column, bool $hasForeignKey): bool
    {
        return true;
    }

    #[\Override]
    public function bind(string $columnName, array $postData): BoundValue
    {
        $value = $postData[$columnName] ?? null;
        if ($value === '' || $value === null) {
            $value = null;
        }
        return new BoundValue($value);
    }

    #[\Override]
    public function render(ColumnConfig $column, mixed $currentValue, RenderContext $context): string
    {
        $value     = $context->isPrefilled($column->name)
            ? $context->prefilledValue($column->name)
            : (string)($currentValue ?? '');
        $locked  = $context->isLocked($column->name);
        $name    = htmlspecialchars($column->name, ENT_QUOTES, 'UTF-8');
        $requiredAttribute = ($column->notNull && !$locked) ? 'required' : '';
        $readonlyAttribute  = $locked ? 'readonly' : '';
        $patternAttribute = $column->validationRegexp !== null
            ? 'data-pattern="' . htmlspecialchars($column->validationRegexp, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        $messageAttribute = $column->validationMessage !== null
            ? 'data-message="' . htmlspecialchars($column->validationMessage, ENT_QUOTES, 'UTF-8') . '"'
            : '';

        return '<input type="text" name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" '
             . $requiredAttribute . ' ' . $readonlyAttribute . ' '
             . $patternAttribute . ' ' . $messageAttribute . ' />';
    }
}
