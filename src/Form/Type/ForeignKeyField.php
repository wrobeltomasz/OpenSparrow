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

final class ForeignKeyField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $column, bool $hasForeignKey): bool
    {
        return $hasForeignKey;
    }

    #[\Override]
    public function bind(string $colName, array $postData): BoundValue
    {
        $value = $postData[$colName] ?? null;
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

        $html  = '<select name="' . $name . '" ' . ($locked ? 'disabled' : '') . ' ' . $requiredAttribute . '>';
        $html .= '<option value="">-- Select --</option>';
        foreach ($context->fkOptionsFor($column->name) as $optValue => $optLabel) {
            $selected = (string)$value === (string)$optValue ? 'selected' : '';
            $html    .= '<option value="' . htmlspecialchars((string)$optValue, ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>'
                      . htmlspecialchars((string)$optLabel, ENT_QUOTES, 'UTF-8')
                      . '</option>';
        }
        $html .= '</select>';
        if ($locked) {
            $html .= '<input type="hidden" name="' . $name . '" value="'
                   . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />';
        }
        return $html;
    }
}
