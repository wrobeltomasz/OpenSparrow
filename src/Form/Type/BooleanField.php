<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\RenderContext;

final class BooleanField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $col, bool $hasForeignKey): bool
    {
        return $col->isBool();
    }

    #[\Override]
    public function bind(string $colName, array $postData): BoundValue
    {
        $val = isset($postData[$colName]) ? 'true' : 'false';
        return new BoundValue($val, 'boolean');
    }

    #[\Override]
    public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string
    {
        $val    = $ctx->isPrefilled($col->name) ? $ctx->prefilledValue($col->name) : (string)($currentValue ?? '');
        $truthy = ['t', 'true', '1', 'on'];
        $checked = (in_array(strtolower($val), $truthy, true) || $currentValue === true) ? 'checked' : '';
        $locked  = $ctx->isLocked($col->name);
        $name    = htmlspecialchars($col->name, ENT_QUOTES, 'UTF-8');

        $html = '<input type="checkbox"'
              . ($locked ? '' : ' name="' . $name . '"')
              . ($locked ? ' disabled' : '')
              . ' ' . $checked . ' />';

        if ($locked) {
            $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '" />';
        }
        return $html;
    }
}
