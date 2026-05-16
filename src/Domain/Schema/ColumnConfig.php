<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class ColumnConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $displayName,
        public readonly bool $readonly = false,
        public readonly bool $notNull = false,
        public readonly bool $showInEdit = true,
        public readonly array $options = [],
        public readonly array $enumColors = [],
        public readonly ?string $validationRegexp = null,
        public readonly ?string $validationMessage = null,
    ) {
    }

    public function isVirtual(): bool
    {
        return $this->type === 'virtual';
    }

    public function isBool(): bool
    {
        return str_contains(strtolower($this->type), 'bool');
    }

    public function isDate(): bool
    {
        return str_contains(strtolower($this->type), 'date');
    }

    public function isTimestamp(): bool
    {
        return str_contains(strtolower($this->type), 'timestamp');
    }

    public function isEnum(): bool
    {
        $t = strtolower($this->type);
        return $t === 'enum' || str_starts_with($t, 'enum');
    }
}
