<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\SchemaRepositoryInterface;
use App\Domain\Schema\TableConfig;
use App\Form\RecordData;
use App\Persistence\ConnectionInterface;
use App\Persistence\Identifier;

final readonly class PgRecordRepository implements RecordRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $conn,
        private SchemaRepositoryInterface $schemas,
        private FkOptionsLoader $fkLoader,
    ) {
    }

    #[\Override]
    public function find(TableConfig $cfg, string|int $id): ?array
    {
        $cols       = array_unique(array_merge([$cfg->primaryKey], array_keys($cfg->dbColumns())));
        $selectList = implode(', ', array_map([Identifier::class, 'quote'], $cols));
        $sql        = sprintf(
            'SELECT %s FROM %s WHERE %s = $1',
            $selectList,
            Identifier::quoteQualified($cfg->schema, $cfg->name),
            Identifier::quote($cfg->primaryKey)
        );
        $res = $this->conn->execute($sql, [(string)$id]);
        $row = pg_fetch_assoc($res);
        return $row !== false ? $row : null;
    }

    #[\Override]
    public function update(TableConfig $cfg, string|int $id, RecordData $data): void
    {
        if ($data->isEmpty()) {
            return;
        }
        $updates = [];
        $params  = [];
        $i       = 1;
        foreach ($data->bindings as $b) {
            $updates[] = Identifier::quote($b['col']) . ' = ' . $b['bound']->placeholder($i);
            $params[]  = $b['bound']->value;
            $i++;
        }
        $params[] = (string)$id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = $%d',
            Identifier::quoteQualified($cfg->schema, $cfg->name),
            implode(', ', $updates),
            Identifier::quote($cfg->primaryKey),
            $i
        );
        $this->conn->execute($sql, $params);
    }

    #[\Override]
    public function insert(TableConfig $cfg, RecordData $data): string|int
    {
        if ($data->isEmpty()) {
            $sql = sprintf(
                'INSERT INTO %s DEFAULT VALUES RETURNING %s',
                Identifier::quoteQualified($cfg->schema, $cfg->name),
                Identifier::quote($cfg->primaryKey)
            );
            $res = $this->conn->exec($sql);
        } else {
            $cols   = [];
            $ph     = [];
            $params = [];
            $i      = 1;
            foreach ($data->bindings as $b) {
                $cols[]   = Identifier::quote($b['col']);
                $ph[]     = $b['bound']->placeholder($i);
                $params[] = $b['bound']->value;
                $i++;
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                Identifier::quoteQualified($cfg->schema, $cfg->name),
                implode(', ', $cols),
                implode(', ', $ph),
                Identifier::quote($cfg->primaryKey)
            );
            $res = $this->conn->execute($sql, $params);
        }
        $row = pg_fetch_assoc($res);
        if ($row === false) {
            throw new \RuntimeException('INSERT returned no row.');
        }
        return $row[$cfg->primaryKey];
    }

    #[\Override]
    public function subtables(TableConfig $cfg, string|int $parentId): array
    {
        $result    = [];
        $rawSchema = $this->schemas->raw();

        foreach ($cfg->subtables as $sub) {
            $sName = $sub['table'];
            if (!$this->schemas->hasTable($sName)) {
                continue;
            }
            $sTableCfg = $this->schemas->table($sName);
            $sFk       = $sub['foreign_key'];
            $sCols     = $sub['columns_to_show'] ?? ['id'];

            $selCols    = array_unique(array_merge(['id'], $sCols));
            $selColsSql = implode(', ', array_map([Identifier::class, 'quote'], $selCols));

            $sql = sprintf(
                'SELECT %s FROM %s WHERE %s = $1 ORDER BY id DESC',
                $selColsSql,
                Identifier::quoteQualified($sTableCfg->schema, $sName),
                Identifier::quote($sFk)
            );

            $sRes = $this->conn->execute($sql, [(string)$parentId]);
            $rows = [];
            while ($sr = pg_fetch_assoc($sRes)) {
                $rows[] = $sr;
            }
            pg_free_result($sRes);

            $rows = $this->fkLoader->expandDisplay($sTableCfg, $rows, $rawSchema);

            $result[] = [
                'config' => $sub,
                'rows'   => $rows,
                'schema' => $sTableCfg,
            ];
        }
        return $result;
    }
}
