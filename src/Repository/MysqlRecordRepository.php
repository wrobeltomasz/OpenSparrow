<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\TableConfig;
use App\Form\BoundValue;
use App\Form\RecordData;
use App\Persistence\MysqlConnection;
use App\Persistence\MysqlIdentifier;

/**
 * RecordRepository backed by the external MySQL gateway. Serves basic field
 * CRUD for tables routed to MySQL via config/mysql_gateway.json.
 *
 * The platform's logical primary key is always "id" (see id_column()); the
 * physical MySQL column comes from TableConfig::$mysqlPk and is aliased to "id"
 * on read.
 *
 * Type coercion: MySQL has no native boolean (booleans are tinyint(1)) and is
 * strict about datetime literals. The form layer is shaped for PostgreSQL, so
 * this repository casts at the driver boundary using the schema (TableConfig):
 * read maps tinyint(1) to a PHP bool (forms/JSON see true/false as on PG);
 * write maps bool to 1/0 and rewrites "2026-06-14T12:17" (datetime-local) to
 * the space-separated form MySQL requires.
 *
 * PostgreSQL-side relations (subtables, FK display expansion, m2m) are
 * out of scope here — see RoutingRecordRepository and the edit/create guards.
 */
final readonly class MysqlRecordRepository implements RecordRepositoryInterface
{
    public function __construct(private MysqlConnection $conn)
    {
    }

    #[\Override]
    public function find(TableConfig $cfg, string|int $id): ?array
    {
        $cols   = array_unique(array_merge([$cfg->primaryKey], array_keys($cfg->dbColumns())));
        $select = implode(', ', array_map(fn(string $c) => $this->selectExpr($cfg, $c), $cols));
        $sql    = sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $select,
            MysqlIdentifier::quote($cfg->name),
            MysqlIdentifier::quote($cfg->mysqlPk)
        );
        $stmt = $this->conn->execute($sql, [(string)$id]);
        $row  = $stmt->fetch();
        return is_array($row) ? $this->castRow($cfg, $row) : null;
    }

    #[\Override]
    public function update(TableConfig $cfg, string|int $id, RecordData $data): void
    {
        if ($data->isEmpty()) {
            return;
        }
        $updates = [];
        $params  = [];
        foreach ($data->bindings as $b) {
            $updates[] = MysqlIdentifier::quote($b['col']) . ' = ?';
            $params[]  = $this->toMysql($cfg, $b['col'], $b['bound']);
        }
        $params[] = (string)$id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            MysqlIdentifier::quote($cfg->name),
            implode(', ', $updates),
            MysqlIdentifier::quote($cfg->mysqlPk)
        );
        $this->conn->execute($sql, $params);
    }

    #[\Override]
    public function insert(TableConfig $cfg, RecordData $data): string|int
    {
        $cols   = [];
        $ph     = [];
        $params = [];
        foreach ($data->bindings as $b) {
            $value = $this->toMysql($cfg, $b['col'], $b['bound']);
            // Omit NULL-valued columns so MySQL applies the column's own default
            // (e.g. `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`). A
            // blank form field maps to NULL upstream, but binding an explicit NULL
            // would violate a NOT NULL column even when it has a usable default.
            if ($value === null) {
                continue;
            }
            $cols[]   = MysqlIdentifier::quote($b['col']);
            $ph[]     = '?';
            $params[] = $value;
        }
        if ($cols === []) {
            // Nothing explicit to set — let every column take its default.
            $sql = sprintf('INSERT INTO %s () VALUES ()', MysqlIdentifier::quote($cfg->name));
            $this->conn->execute($sql);
            return $this->conn->lastInsertId();
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            MysqlIdentifier::quote($cfg->name),
            implode(', ', $cols),
            implode(', ', $ph)
        );
        $this->conn->execute($sql, $params);
        return $this->conn->lastInsertId();
    }

    #[\Override]
    public function subtables(TableConfig $cfg, string|int $parentId): array
    {
        // Subtables join across PostgreSQL relations; not supported for MySQL tables.
        return [];
    }

    /** Emit `real_pk` AS `id` for the logical PK; plain `col` otherwise. */
    private function selectExpr(TableConfig $cfg, string $col): string
    {
        if ($col === $cfg->primaryKey && $cfg->mysqlPk !== $cfg->primaryKey) {
            return MysqlIdentifier::quote($cfg->mysqlPk)
                . ' AS ' . MysqlIdentifier::quote($cfg->primaryKey);
        }
        return MysqlIdentifier::quote($col);
    }

    /**
     * Cast a freshly-fetched MySQL row back toward the PostgreSQL value shape
     * the form/grid/JSON layers expect. Currently that means tinyint(1) booleans
     * become real PHP bools; everything else (datetimes, numbers) is already in
     * a form those layers accept.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castRow(TableConfig $cfg, array $row): array
    {
        foreach ($cfg->columns as $name => $col) {
            if ($col->isBool() && array_key_exists($name, $row) && $row[$name] !== null) {
                $row[$name] = $this->toBool($row[$name]);
            }
        }
        return $row;
    }

    /**
     * Translate a BoundValue (shaped for PostgreSQL) into a MySQL-bindable
     * scalar, driven by the column type from the schema rather than trusting the
     * upstream form layer. Booleans become tinyint(1) 1/0; timestamp/datetime
     * values get the datetime-local "T" separator normalised to a space.
     */
    private function toMysql(TableConfig $cfg, string $col, BoundValue $bound): mixed
    {
        if ($bound->value === null) {
            return null;
        }
        $column = $cfg->columns[$col] ?? null;

        if (($column !== null && $column->isBool()) || $bound->cast === 'boolean') {
            return $this->toBool($bound->value) ? 1 : 0;
        }

        if ($column !== null && $column->isTimestamp()) {
            return $this->toMysqlDateTime((string) $bound->value);
        }

        return $bound->value;
    }

    /** Interpret any of PG/MySQL/form truthy spellings as a bool. */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'on', 'yes'], true);
    }

    /**
     * Normalise an HTML5 datetime-local literal to the strict
     * "YYYY-MM-DD HH:MM:SS" MySQL accepts: swap the "T" separator for a space and
     * drop any fractional-seconds or timezone suffix the input might carry.
     */
    private function toMysqlDateTime(string $value): string
    {
        $v = str_replace('T', ' ', trim($value));
        $v = (string) preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $v);
        $v = (string) preg_replace('/\s*([+-]\d{2}(:\d{2})?|Z)$/', '', $v);
        return trim($v);
    }
}
