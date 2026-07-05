<?php

declare(strict_types=1);

namespace App\Form;

final readonly class RecordData
{
    /**
     * @param list<array{col: string, bound: BoundValue}> $bindings
     */
    public function __construct(public array $bindings)
    {
    }

    public function isEmpty(): bool
    {
        return empty($this->bindings);
    }
}
