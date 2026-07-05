<?php

declare(strict_types=1);

namespace App\Form;

final readonly class RenderContext
{
    /**
     * @param array<string, array<string|int, string>> $fkOptions  colName => [value => label]
     * @param array<string, string>                    $prefilled   colName => value (for create)
     * @param array<string, bool>                      $locked      colName => true if field is locked
     */
    public function __construct(
        public bool $readOnly,
        public array $fkOptions = [],
        public array $prefilled = [],
        public array $locked = [],
    ) {
    }

    public function fkOptionsFor(string $colName): array
    {
        return $this->fkOptions[$colName] ?? [];
    }

    public function isPrefilled(string $colName): bool
    {
        return isset($this->prefilled[$colName]);
    }

    public function prefilledValue(string $colName): string
    {
        return $this->prefilled[$colName] ?? '';
    }

    /** True if field should render as disabled + hidden input (cannot be edited by user). */
    public function isLocked(string $colName): bool
    {
        return $this->readOnly || ($this->locked[$colName] ?? false);
    }
}
