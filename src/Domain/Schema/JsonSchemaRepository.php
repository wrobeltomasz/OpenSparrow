<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class JsonSchemaRepository
{
    private array $rawData;
    /** @var array<string, TableConfig> */
    private array $cache = [];

    /**
     * @param string|array $source Path to a schema JSON file, or the already
     *                             decoded schema config (e.g. from the spw_config store).
     */
    public function __construct(string|array $source)
    {
        if (is_array($source)) {
            $data = $source;
        } else {
            $json = file_get_contents($source);
            if ($json === false) {
                throw new \RuntimeException("Cannot read schema file: {$source}");
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid schema JSON in: {$source}");
            }
        }
        $this->rawData = $data;
    }

    public function table(string $name): TableConfig
    {
        if (!$this->hasTable($name)) {
            throw new \InvalidArgumentException("Unknown table: {$name}");
        }
        return $this->cache[$name] ??= $this->build($name, $this->rawData['tables'][$name]);
    }

    public function hasTable(string $name): bool
    {
        return isset($this->rawData['tables'][$name]);
    }

    public function all(): array
    {
        $result = [];
        foreach (array_keys($this->rawData['tables'] ?? []) as $name) {
            $result[$name] = $this->table($name);
        }
        return $result;
    }

    public function raw(): array
    {
        return $this->rawData;
    }

    private function build(string $name, array $cfg): TableConfig
    {
        $columns = [];
        foreach ($cfg['columns'] ?? [] as $colName => $colCfg) {
            $columns[$colName] = new ColumnConfig(
                name: $colName,
                type: $colCfg['type'] ?? 'text',
                displayName: $colCfg['display_name'] ?? $colName,
                readonly: !empty($colCfg['readonly']),
                notNull: !empty($colCfg['not_null']),
                showInEdit: ($colCfg['show_in_edit'] ?? true) !== false,
                options: $colCfg['options'] ?? [],
                enumColors: $colCfg['enum_colors'] ?? [],
                validationRegexp: $colCfg['validation_regexp'] ?? null,
                validationMessage: $colCfg['validation_message'] ?? null,
            );
        }
        return new TableConfig(
            name: $name,
            schema: $cfg['schema'] ?? 'public',
            displayName: $cfg['display_name'] ?? $name,
            columns: $columns,
            foreignKeys: $cfg['foreign_keys'] ?? [],
            subtables: $cfg['subtables'] ?? [],
            primaryKey: 'id',
            icon: $cfg['icon'] ?? '',
        );
    }
}
