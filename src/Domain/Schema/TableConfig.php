<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class TableConfig
{
    /**
     * @param array<string, ColumnConfig>          $columns
     * @param array<string, array<string, mixed>>  $foreignKeys
     * @param list<array<string, mixed>>           $subtables
     */
    public function __construct(
        public readonly string $name,
        public readonly string $schema,
        public readonly string $displayName,
        public readonly array $columns,
        public readonly array $foreignKeys,
        public readonly array $subtables,
        public readonly string $primaryKey = 'id',
        public readonly string $icon = '',
        public readonly string $source = 'postgres',
        public readonly string $mysqlPk = 'id',
    ) {
    }

    /** True when this table is routed to the external MySQL gateway, not PostgreSQL. */
    public function isMysql(): bool
    {
        return $this->source === 'mysql';
    }

    /** Columns shown in edit/create forms (respects show_in_edit; virtual columns excluded). */
    public function visibleColumns(): array
    {
        return array_filter($this->columns, fn(ColumnConfig $c) => $c->showInEdit && !$c->isVirtual());
    }

    /** Columns that may be written via POST — skips PK, readonly, and virtual. */
    public function writableColumns(): array
    {
        return array_filter(
            $this->columns,
            fn(ColumnConfig $c) => $c->name !== $this->primaryKey && !$c->readonly && !$c->isVirtual()
        );
    }

    /** Columns that exist in the database — excludes virtual (computed) columns. */
    public function dbColumns(): array
    {
        return array_filter($this->columns, fn(ColumnConfig $c) => !$c->isVirtual());
    }

    public function column(string $name): ColumnConfig
    {
        return $this->columns[$name]
            ?? throw new \InvalidArgumentException("Unknown column: {$name}");
    }

    public function hasForeignKey(string $colName): bool
    {
        return isset($this->foreignKeys[$colName]);
    }

    public function foreignKey(string $colName): array
    {
        return $this->foreignKeys[$colName]
            ?? throw new \InvalidArgumentException("No FK for column: {$colName}");
    }
}
