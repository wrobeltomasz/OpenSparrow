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
    public function find(TableConfig $config, string|int $id): ?array
    {
        $columns       = array_unique(array_merge([$config->primaryKey], array_keys($config->dbColumns())));
        $selectList = implode(', ', array_map([Identifier::class, 'quote'], $columns));
        $sql        = sprintf(
            'SELECT %s FROM %s WHERE %s = $1',
            $selectList,
            Identifier::quoteQualified($config->schema, $config->name),
            Identifier::quote($config->primaryKey)
        );
        $queryResult = $this->conn->execute($sql, [(string)$id]);
        $row = pg_fetch_assoc($queryResult);
        return $row !== false ? $row : null;
    }

    #[\Override]
    public function update(TableConfig $config, string|int $id, RecordData $data): void
    {
        if ($data->isEmpty()) {
            return;
        }
        $updates = [];
        $parameters  = [];
        $placeholderIndex       = 1;
        foreach ($data->bindings as $binding) {
            $updates[] = Identifier::quote($binding['col']) . ' = ' . $binding['bound']->placeholder($placeholderIndex);
            $parameters[]  = $binding['bound']->value;
            $placeholderIndex++;
        }
        $parameters[] = (string)$id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = $%d',
            Identifier::quoteQualified($config->schema, $config->name),
            implode(', ', $updates),
            Identifier::quote($config->primaryKey),
            $placeholderIndex
        );
        $this->conn->execute($sql, $parameters);
    }

    #[\Override]
    public function insert(TableConfig $config, RecordData $data): string|int
    {
        if ($data->isEmpty()) {
            $sql = sprintf(
                'INSERT INTO %s DEFAULT VALUES RETURNING %s',
                Identifier::quoteQualified($config->schema, $config->name),
                Identifier::quote($config->primaryKey)
            );
            $queryResult = $this->conn->exec($sql);
        } else {
            $columns   = [];
            $placeholders     = [];
            $parameters = [];
            $placeholderIndex      = 1;
            foreach ($data->bindings as $binding) {
                $columns[]   = Identifier::quote($binding['col']);
                $placeholders[]     = $binding['bound']->placeholder($placeholderIndex);
                $parameters[] = $binding['bound']->value;
                $placeholderIndex++;
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                Identifier::quoteQualified($config->schema, $config->name),
                implode(', ', $columns),
                implode(', ', $placeholders),
                Identifier::quote($config->primaryKey)
            );
            $queryResult = $this->conn->execute($sql, $parameters);
        }
        $row = pg_fetch_assoc($queryResult);
        if ($row === false) {
            throw new \RuntimeException('INSERT returned no row.');
        }
        return $row[$config->primaryKey];
    }

    #[\Override]
    public function subtables(TableConfig $config, string|int $parentId): array
    {
        $result    = [];
        $rawSchema = $this->schemas->raw();

        foreach ($config->subtables as $subtable) {
            $subtableName = $subtable['table'];
            if (!$this->schemas->hasTable($subtableName)) {
                continue;
            }
            $subtableConfig = $this->schemas->table($subtableName);
            $foreignKey       = $subtable['foreign_key'];

            $selectColumns    = array_unique(array_merge(['id'], array_keys($subtableConfig->dbColumns())));
            $selectColumnsSql = implode(', ', array_map([Identifier::class, 'quote'], $selectColumns));

            $sql = sprintf(
                'SELECT %s FROM %s WHERE %s = $1 ORDER BY id DESC',
                $selectColumnsSql,
                Identifier::quoteQualified($subtableConfig->schema, $subtableName),
                Identifier::quote($foreignKey)
            );

            $subtableResult = $this->conn->execute($sql, [(string)$parentId]);
            $rows = [];
            while ($subtableRow = pg_fetch_assoc($subtableResult)) {
                $rows[] = $subtableRow;
            }
            pg_free_result($subtableResult);

            $rows = $this->fkLoader->expandDisplay($subtableConfig, $rows, $rawSchema);

            $result[] = [
                'config' => $subtable,
                'rows'   => $rows,
                'schema' => $subtableConfig,
            ];
        }
        return $result;
    }
}
