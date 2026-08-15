<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\JsonSchemaRepository;
use App\Domain\Schema\TableConfig;
use App\Form\RecordData;
use App\Persistence\PgConnection;
use App\Persistence\Identifier;

final readonly class PgRecordRepository implements RecordRepositoryInterface
{
    public function __construct(
        private PgConnection $conn,
        private JsonSchemaRepository $schemas,
        private FkOptionsLoader $fkLoader,
    ) {
    }

    #[\Override]
    public function find(TableConfig $cfg, string|int $id): ?array
    {
        $columns       = array_unique(array_merge([$cfg->primaryKey], array_keys($cfg->dbColumns())));
        $selectList = implode(', ', array_map([Identifier::class, 'quote'], $columns));
        $sql        = sprintf(
            'SELECT %s FROM %s WHERE %s = $1',
            $selectList,
            Identifier::quoteQualified($cfg->schema, $cfg->name),
            Identifier::quote($cfg->primaryKey)
        );
        $queryResult = $this->conn->execute($sql, [(string)$id]);
        $row = pg_fetch_assoc($queryResult);
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
        $placeholderIndex       = 1;
        foreach ($data->bindings as $binding) {
            $updates[] = Identifier::quote($binding['col']) . ' = ' . $binding['bound']->placeholder($placeholderIndex);
            $params[]  = $binding['bound']->value;
            $placeholderIndex++;
        }
        $params[] = (string)$id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = $%d',
            Identifier::quoteQualified($cfg->schema, $cfg->name),
            implode(', ', $updates),
            Identifier::quote($cfg->primaryKey),
            $placeholderIndex
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
            $queryResult = $this->conn->exec($sql);
        } else {
            $columns   = [];
            $placeholders     = [];
            $params = [];
            $placeholderIndex      = 1;
            foreach ($data->bindings as $binding) {
                $columns[]   = Identifier::quote($binding['col']);
                $placeholders[]     = $binding['bound']->placeholder($placeholderIndex);
                $params[] = $binding['bound']->value;
                $placeholderIndex++;
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                Identifier::quoteQualified($cfg->schema, $cfg->name),
                implode(', ', $columns),
                implode(', ', $placeholders),
                Identifier::quote($cfg->primaryKey)
            );
            $queryResult = $this->conn->execute($sql, $params);
        }
        $row = pg_fetch_assoc($queryResult);
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

        foreach ($cfg->subtables as $subtable) {
            $subtableName = $subtable['table'];
            if (!$this->schemas->hasTable($subtableName)) {
                continue;
            }
            $subtableCfg = $this->schemas->table($subtableName);
            $foreignKey       = $subtable['foreign_key'];

            $selectColumns    = array_unique(array_merge(['id'], array_keys($subtableCfg->dbColumns())));
            $selectColumnsSql = implode(', ', array_map([Identifier::class, 'quote'], $selectColumns));

            $sql = sprintf(
                'SELECT %s FROM %s WHERE %s = $1 ORDER BY id DESC',
                $selectColumnsSql,
                Identifier::quoteQualified($subtableCfg->schema, $subtableName),
                Identifier::quote($foreignKey)
            );

            $subtableResult = $this->conn->execute($sql, [(string)$parentId]);
            $rows = [];
            while ($subtableRow = pg_fetch_assoc($subtableResult)) {
                $rows[] = $subtableRow;
            }
            pg_free_result($subtableResult);

            $rows = $this->fkLoader->expandDisplay($subtableCfg, $rows, $rawSchema);

            $result[] = [
                'config' => $subtable,
                'rows'   => $rows,
                'schema' => $subtableCfg,
            ];
        }
        return $result;
    }
}
