<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Schema\ColumnConfig;

final readonly class FieldTypeRegistry
{
    /**
     * @param list<FieldTypeInterface> $types Ordered by specificity; last entry must be a universal fallback.
     */
    public function __construct(private array $types)
    {
    }

    public function for(ColumnConfig $col, bool $hasForeignKey): FieldTypeInterface
    {
        return array_find($this->types, fn(FieldTypeInterface $t): bool => $t->supports($col, $hasForeignKey))
            ?? throw new \LogicException(
                "No FieldType supports column '{$col->name}' (type: {$col->type}). "
                . 'Ensure TextField is registered as the last fallback.'
            );
    }
}
