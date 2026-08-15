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

final class EnumField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $column, bool $hasForeignKey): bool
    {
        return $column->isEnum();
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

        if ($locked) {
            $color     = $column->enumColors[$value] ?? null;
            $bgStyle   = $color ? 'background:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';' : 'background:#e2e8f0;';
            $textColor = '#333';
            if ($color) {
                $hexColor = ltrim($color, '#');
                if (strlen($hexColor) === 6) {
                    $brightness = (hexdec(substr($hexColor, 0, 2)) * 299
                                 + hexdec(substr($hexColor, 2, 2)) * 587
                                 + hexdec(substr($hexColor, 4, 2)) * 114) / 1000;
                    $textColor  = $brightness > 128 ? '#333' : '#fff';
                }
            }
            $display = $value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '&mdash;';
            $html    = '<span class="enum-badge" style="' . $bgStyle . 'color:' . $textColor . ';">' . $display . '</span>';
            $html   .= '<input type="hidden" name="' . $name . '" value="'
                     . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />';
            return $html;
        }

        $colorsJson = htmlspecialchars((string)json_encode($column->enumColors), ENT_QUOTES, 'UTF-8');
        $initBg     = $column->enumColors[$value] ?? '';
        $initStyle  = $initBg ? 'background:' . htmlspecialchars($initBg, ENT_QUOTES, 'UTF-8') . ';' : '';

        $html  = '<select name="' . $name . '" ' . $requiredAttribute
            . ' data-enum-colors="' . $colorsJson . '" style="' . $initStyle . '">';
        $html .= '<option value="">-- Select --</option>';
        foreach ($column->options as $option) {
            $optionValue   = (string)$option;
            $selected = $value === $optionValue ? 'selected' : '';
            $optBg    = $column->enumColors[$optionValue] ?? '';
            $optStyle = $optBg
                ? ' style="background:' . htmlspecialchars($optBg, ENT_QUOTES, 'UTF-8') . ';"'
                : '';
            $html    .= '<option value="' . htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8') . '"'
                      . $optStyle . ' ' . $selected . '>'
                      . htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')
                      . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
