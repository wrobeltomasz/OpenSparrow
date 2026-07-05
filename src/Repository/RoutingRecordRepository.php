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
    /** @var \Closure(): ?RecordRepositoryInterface */
    private \Closure $mysqlFactory;
    private bool $mysqlResolved = false;
    private ?RecordRepositoryInterface $mysql = null;

    public function __construct(
        private readonly RecordRepositoryInterface $postgres,
        RecordRepositoryInterface|\Closure|null $mysql = null,
    ) {
        if ($mysql instanceof \Closure) {
            // Lazy: connect only when a MySQL-routed table is first accessed, so
            // PostgreSQL-only requests never open (or wait on) the MySQL gateway.
            $this->mysqlFactory = $mysql;
            return;
        }
        // Eager instance (or null) — wrap it so resolution is uniform.
        $this->mysql         = $mysql;
        $this->mysqlResolved = true;
        $this->mysqlFactory  = static fn(): ?RecordRepositoryInterface => $mysql;
    }

    #[\Override]
    public function find(TableConfig $cfg, string|int $id): ?array
    {
        return $this->route($cfg)->find($cfg, $id);
    }

    #[\Override]
    public function update(TableConfig $cfg, string|int $id, RecordData $data): void
    {
        $this->route($cfg)->update($cfg, $id, $data);
    }

    #[\Override]
    public function insert(TableConfig $cfg, RecordData $data): string|int
    {
        return $this->route($cfg)->insert($cfg, $data);
    }

    #[\Override]
    public function subtables(TableConfig $cfg, string|int $parentId): array
    {
        return $this->route($cfg)->subtables($cfg, $parentId);
    }

    private function mysql(): ?RecordRepositoryInterface
    {
        if ($this->mysqlResolved) {
            return $this->mysql;
        }
        // Resolve the lazy MySQL connection at most once per request. Mark it
        // resolved up-front so a throwing factory cannot leave a half-initialised
        // state or trigger a reconnect storm on the next record operation.
        $this->mysqlResolved = true;
        try {
            $this->mysql = ($this->mysqlFactory)();
        } catch (\Throwable $e) {
            // The MySQL gateway is optional; a failure building it must never crash
            // the request with an unexpected exception type. Log the detail and fall
            // back to the "no connection" path — route() then throws a clean
            // RuntimeException for MySQL-routed tables only, while PostgreSQL tables
            // (which never call this method) are wholly unaffected.
            error_log(
                '[RoutingRecordRepository] MySQL connection factory failed: '
                . $e->getMessage() . ' | ' . $e->getTraceAsString()
            );
            $this->mysql = null;
        }
        return $this->mysql;
    }

    private function route(TableConfig $cfg): RecordRepositoryInterface
    {
        if (!$cfg->isMysql()) {
            return $this->postgres;
        }
        $mysql = $this->mysql();
        if ($mysql === null) {
            throw new \RuntimeException(
                "Table '{$cfg->name}' is routed to MySQL, but no MySQL connection is configured."
            );
        }
        return $mysql;
    }
}
