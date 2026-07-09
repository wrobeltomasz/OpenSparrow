<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\TableConfig;
use App\Persistence\PgConnection;
use App\Persistence\Identifier;

final readonly class FkOptionsLoader
{
    public function __construct(private PgConnection $conn)
    {
    }

    /**
     * Return a [value => displayLabel] map for a foreign key select widget.
     *
     * @param array<string, mixed> $fkCfg     raw FK config from schema JSON
     * @param array<string, mixed> $rawSchema  full raw schema for ref-table schema lookup
     * @return array<string|int, string>
     */
    public function load(array $fkCfg, array $rawSchema): array
    {
        $refTable  = $fkCfg['reference_table'];
        $refPk     = $fkCfg['reference_column'] ?? 'id';
        $refSchema = $rawSchema['tables'][$refTable]['schema'] ?? 'public';

        $dispRaw = is_array($fkCfg['display_column'] ?? null)
            ? $fkCfg['display_column']
            : [$fkCfg['display_column'] ?? $refPk];
        if (empty($dispRaw)) {
            $dispRaw = [$refPk];
        }

        $refColsSql  = implode(', ', array_map([Identifier::class, 'quote'], $dispRaw));
        $orderColSql = Identifier::quote($dispRaw[0]);
        $sql = sprintf(
            'SELECT %s, %s FROM %s ORDER BY %s ASC',
            Identifier::quote($refPk),
            $refColsSql,
            Identifier::quoteQualified($refSchema, $refTable),
            $orderColSql
        );

        $res     = $this->conn->exec($sql);
        $options = [];
        while ($row = pg_fetch_assoc($res)) {
            $parts = [];
            foreach ($dispRaw as $dc) {
                if (isset($row[$dc]) && $row[$dc] !== '') {
                    $parts[] = $row[$dc];
                }
            }
            $options[$row[$refPk]] = implode(' - ', $parts) ?: $row[$refPk];
        }
        return $options;
    }

    /**
     * Annotate a result-set with __display suffixed keys for FK columns.
     * Replaces the global map_fk_display() for OOP callers.
     *
     * @param array<string, mixed> $rawSchema
     */
    public function expandDisplay(TableConfig $cfg, array $rows, array $rawSchema): array
    {
        if (empty($rows) || empty($cfg->foreignKeys)) {
            return $rows;
        }

        foreach ($cfg->foreignKeys as $fkCol => $fkCfg) {
            $fkValues = array_unique(
                array_filter(array_column($rows, $fkCol), fn($v) => $v !== null && $v !== '')
            );
            if (empty($fkValues)) {
                continue;
            }

            $refTable  = $fkCfg['reference_table'];
            $refPk     = $fkCfg['reference_column'] ?? 'id';
            $refSchema = $rawSchema['tables'][$refTable]['schema'] ?? 'public';

            $dispRaw = is_array($fkCfg['display_column'] ?? null)
                ? $fkCfg['display_column']
                : [$fkCfg['display_column'] ?? $refPk];
            if (empty($dispRaw)) {
                $dispRaw = [$refPk];
            }

            $escapedCols = array_map([Identifier::class, 'quote'], $dispRaw);
            $dispSql     = count($escapedCols) > 1
                ? 'CONCAT_WS(\' - \', ' . implode(', ', $escapedCols) . ')'
                : $escapedCols[0];

            $escapedVals = array_map(
                fn($v) => pg_escape_literal($this->conn->native(), (string)$v),
                $fkValues
            );

            $sql = sprintf(
                'SELECT %s AS id, %s AS disp FROM %s WHERE %s IN (%s)',
                Identifier::quote($refPk),
                $dispSql,
                Identifier::quoteQualified($refSchema, $refTable),
                Identifier::quote($refPk),
                implode(', ', $escapedVals)
            );

            $map = [];
            $res = pg_query($this->conn->native(), $sql);
            if ($res) {
                while ($r = pg_fetch_assoc($res)) {
                    $map[$r['id']] = $r['disp'];
                }
                pg_free_result($res);
            }

            foreach ($rows as &$row) {
                if (isset($row[$fkCol]) && array_key_exists($row[$fkCol], $map)) {
                    $row[$fkCol . '__display'] = $map[$row[$fkCol]];
                }
            }
            unset($row);
        }
        return $rows;
    }
}
