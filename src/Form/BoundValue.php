<?php

declare(strict_types=1);

namespace App\Form;

final readonly class BoundValue
{
    public function __construct(
        public mixed $value,
        public ?string $cast = null,
    ) {
    }

    public function placeholder(int $index): string
    {
        return $this->cast !== null ? "\${$index}::{$this->cast}" : "\${$index}";
    }
}
