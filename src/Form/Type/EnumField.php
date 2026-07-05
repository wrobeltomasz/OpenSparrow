<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\RenderContext;

final class EnumField implements FieldTypeInterface
{
    #[\Override]
    public function supports(ColumnConfig $col, bool $hasForeignKey): bool
    {
        return $col->isEnum();
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

        if ($locked) {
            $color     = $col->enumColors[$val] ?? null;
            $bgStyle   = $color ? 'background:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';' : 'background:#e2e8f0;';
            $textColor = '#333';
            if ($color) {
                $hex = ltrim($color, '#');
                if (strlen($hex) === 6) {
                    $brightness = (hexdec(substr($hex, 0, 2)) * 299
                                 + hexdec(substr($hex, 2, 2)) * 587
                                 + hexdec(substr($hex, 4, 2)) * 114) / 1000;
                    $textColor  = $brightness > 128 ? '#333' : '#fff';
                }
            }
            $display = $val !== '' ? htmlspecialchars($val, ENT_QUOTES, 'UTF-8') : '&mdash;';
            $html    = '<span class="enum-badge" style="' . $bgStyle . 'color:' . $textColor . ';">' . $display . '</span>';
            $html   .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '" />';
            return $html;
        }

        $colorsJson = htmlspecialchars((string)json_encode($col->enumColors), ENT_QUOTES, 'UTF-8');
        $initBg     = $col->enumColors[$val] ?? '';
        $initStyle  = $initBg ? 'background:' . htmlspecialchars($initBg, ENT_QUOTES, 'UTF-8') . ';' : '';

        $html  = '<select name="' . $name . '" ' . $reqAttr . ' data-enum-colors="' . $colorsJson . '" style="' . $initStyle . '">';
        $html .= '<option value="">-- Select --</option>';
        foreach ($col->options as $opt) {
            $optStr   = (string)$opt;
            $selected = $val === $optStr ? 'selected' : '';
            $html    .= '<option value="' . htmlspecialchars($optStr, ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>'
                      . htmlspecialchars($optStr, ENT_QUOTES, 'UTF-8')
                      . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
