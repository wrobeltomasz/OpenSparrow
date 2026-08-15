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

final class TimestampField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $column, bool $hasForeignKey): bool
    {
        return $column->isTimestamp();
    }

    #[\Override]
    public function bind(string $colName, array $postData): BoundValue
    {
        $value = $postData[$colName] ?? null;
        if ($value === '' || $value === null) {
            return new BoundValue(null);
        }

        return new BoundValue(str_replace('T', ' ', (string) $value));
    }

    #[\Override]
    public function render(ColumnConfig $column, mixed $currentValue, RenderContext $context): string
    {
        $rawValue    = $context->isPrefilled($column->name)
            ? $context->prefilledValue($column->name)
            : (string)($currentValue ?? '');
        $value    = $this->toDatetimeLocal($rawValue);
        $locked = $context->isLocked($column->name);
        $name   = htmlspecialchars($column->name, ENT_QUOTES, 'UTF-8');
        $requiredAttribute = ($column->notNull && !$locked) ? 'required' : '';
        $readonlyAttribute  = $locked ? 'readonly' : '';

        return '<input type="datetime-local" step="1" name="' . $name . '" value="'
             . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" '
             . $requiredAttribute . ' ' . $readonlyAttribute . ' />';
    }

    private function toDatetimeLocal(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $normalized = str_replace(' ', 'T', $value);

        $normalized = (string) preg_replace('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.\d+/', '$1', $normalized);

        $normalized = (string) preg_replace('/([+-]\d{2}(:\d{2})?|Z)$/', '', $normalized);
        return $normalized;
    }
}
