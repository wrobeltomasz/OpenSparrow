<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final readonly class ColumnConfig
{
    public function __construct(
        public string $name,
        public string $type,
        public string $displayName,
        public bool $readonly = false,
        public bool $notNull = false,
        public bool $showInEdit = true,
        public array $options = [],
        public array $enumColors = [],
        public ?string $validationRegexp = null,
        public ?string $validationMessage = null,
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
