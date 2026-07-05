<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\RenderContext;

/** Universal fallback — handles all text-like columns. Must be last in FieldTypeRegistry. */
final class TextField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $col, bool $hasForeignKey): bool
    {
        return true;
    }

    #[\Override]
    public function bind(string $colName, array $postData): BoundValue
    {
        $val = $postData[$colName] ?? null;
        if ($val === '' || $val === null) {
            $val = null;
        }
        return new BoundValue($val);
    }

    #[\Override]
    public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string
    {
        $val     = $ctx->isPrefilled($col->name) ? $ctx->prefilledValue($col->name) : (string)($currentValue ?? '');
        $locked  = $ctx->isLocked($col->name);
        $name    = htmlspecialchars($col->name, ENT_QUOTES, 'UTF-8');
        $reqAttr = ($col->notNull && !$locked) ? 'required' : '';
        $roAttr  = $locked ? 'readonly' : '';
        $patAttr = $col->validationRegexp !== null
            ? 'data-pattern="' . htmlspecialchars($col->validationRegexp, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        $msgAttr = $col->validationMessage !== null
            ? 'data-message="' . htmlspecialchars($col->validationMessage, ENT_QUOTES, 'UTF-8') . '"'
            : '';

        return '<input type="text" name="' . $name . '" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '" '
             . $reqAttr . ' ' . $roAttr . ' ' . $patAttr . ' ' . $msgAttr . ' />';
    }
}
