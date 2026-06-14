<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class JsonSchemaRepository implements SchemaRepositoryInterface
{
    private array $rawData;
    /** @var array<string, TableConfig> */
    private array $cache = [];
    /** @var list<string> Tables routed to the external MySQL gateway. */
    private array $mysqlTables;

    public function __construct(string $path, ?string $gatewayPath = null)
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read schema file: {$path}");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid schema JSON in: {$path}");
        }
        $this->rawData     = $data;
        $this->mysqlTables = $this->loadMysqlTables($gatewayPath ?? dirname($path) . '/mysql_gateway.json');
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
            source: in_array($name, $this->mysqlTables, true) ? 'mysql' : 'postgres',
            mysqlPk: $cfg['mysql_pk'] ?? 'id',
        );
    }

    /**
     * Read the gateway routing list (config/mysql_gateway.json -> mysql_tables).
     * Missing/invalid file degrades to no MySQL tables — same tolerant behaviour
     * as api.php::mysql_gateway_tables().
     *
     * @return list<string>
     */
    private function loadMysqlTables(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['mysql_tables']) || !is_array($data['mysql_tables'])) {
            return [];
        }
        return array_values(array_filter($data['mysql_tables'], 'is_string'));
    }
}
