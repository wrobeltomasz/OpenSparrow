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

        $options      = $context->fkOptionsFor($column->name);
        $currentLabel = (string)($options[$value] ?? '');
        $listId       = 'fk_list_' . $name;

        $html  = '<input type="search" class="fk-search" list="' . $listId . '"'
            . ' data-fk-name="' . $name . '"'
            . ' value="' . htmlspecialchars($currentLabel, ENT_QUOTES, 'UTF-8') . '"'
            . ($locked ? ' disabled' : '')
            . ' ' . $requiredAttribute . '>';
        $html .= '<datalist id="' . $listId . '">';
        foreach ($options as $optionValue => $optionLabel) {
            $html .= '<option value="' . htmlspecialchars((string)$optionLabel, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-id="' . htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8') . '"></option>';
        }
        $html .= '</datalist>';
        $html .= '<input type="hidden" name="' . $name . '" value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />';

        return $html;
    }
}
