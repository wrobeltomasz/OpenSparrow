<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\TableConfig;
use App\Form\RecordData;

/**
 * Routes each record operation to the PostgreSQL or MySQL repository based on
 * TableConfig::$source — the "traffic controller" for the src/ page layer.
 *
 * It implements RecordRepositoryInterface itself, so edit.php / create.php keep
 * calling $records->find()/insert()/update() unchanged and never learn which
 * database served the request.
 */
final class RoutingRecordRepository implements RecordRepositoryInterface
{
    public function __construct(
        private readonly RecordRepositoryInterface $postgres,
        private readonly ?RecordRepositoryInterface $mysql = null,
    ) {
    }

    public function find(TableConfig $cfg, string|int $id): ?array
    {
        return $this->route($cfg)->find($cfg, $id);
    }

    public function update(TableConfig $cfg, string|int $id, RecordData $data): void
    {
        $this->route($cfg)->update($cfg, $id, $data);
    }

    public function insert(TableConfig $cfg, RecordData $data): string|int
    {
        return $this->route($cfg)->insert($cfg, $data);
    }

    public function subtables(TableConfig $cfg, string|int $parentId): array
    {
        return $this->route($cfg)->subtables($cfg, $parentId);
    }

    private function route(TableConfig $cfg): RecordRepositoryInterface
    {
        if (!$cfg->isMysql()) {
            return $this->postgres;
        }
        if ($this->mysql === null) {
            throw new \RuntimeException(
                "Table '{$cfg->name}' is routed to MySQL, but no MySQL connection is configured."
            );
        }
        return $this->mysql;
    }
}
