<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\RenderContext;

final class TimestampField implements FieldTypeInterface
{
    public function supports(ColumnConfig $col, bool $hasForeignKey): bool
    {
        return $col->isTimestamp();
    }

    public function bind(string $colName, array $postData): BoundValue
    {
        $val = $postData[$colName] ?? null;
        if ($val === '' || $val === null) {
            return new BoundValue(null);
        }
        // Convert datetime-local format (2026-05-07T20:55:15) to PostgreSQL format
        return new BoundValue(str_replace('T', ' ', (string) $val));
    }

    public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string
    {
        $raw    = $ctx->isPrefilled($col->name) ? $ctx->prefilledValue($col->name) : (string)($currentValue ?? '');
        $val    = $this->toDatetimeLocal($raw);
        $locked = $ctx->isLocked($col->name);
        $name   = htmlspecialchars($col->name, ENT_QUOTES, 'UTF-8');
        $reqAttr = ($col->notNull && !$locked) ? 'required' : '';
        $roAttr  = $locked ? 'readonly' : '';

        return '<input type="datetime-local" step="1" name="' . $name . '" value="'
             . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '" '
             . $reqAttr . ' ' . $roAttr . ' />';
    }

    private function toDatetimeLocal(string $val): string
    {
        if ($val === '') {
            return '';
        }
        $v = str_replace(' ', 'T', $val);
        // Strip milliseconds: 2026-05-07T20:55:15.208 → 2026-05-07T20:55:15
        $v = (string) preg_replace('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.\d+/', '$1', $v);
        // Strip timezone offset/Z
        $v = (string) preg_replace('/([+-]\d{2}(:\d{2})?|Z)$/', '', $v);
        return $v;
    }
}
