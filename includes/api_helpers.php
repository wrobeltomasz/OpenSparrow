<?php

// Helper functions for API

function safe_table(array $schema, string $table): array
{
    if (!isset($schema['tables'][$table])) {
        throw new RuntimeException("Unknown table: {$table}");
    }
    return $schema['tables'][$table];
}

function column_list(array $tableCfg): array
{
    $cols = $tableCfg['columns'] ?? [];
    // Virtual columns don't exist in the database — exclude from SELECT
    return array_keys(array_filter($cols, fn($c) => ($c['type'] ?? '') !== 'virtual'));
}

function id_column(): string
{
    return 'id';
}

function pg_ident(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function to_display_name(array $tableCfg): string
{
    return $tableCfg['display_name'] ?? ($tableCfg['name'] ?? 'Unknown');
}

function map_fk_display(array $schema, array $tableCfg, array $rows, $conn = null): array
{
    if (empty($rows) || !isset($tableCfg['foreign_keys'])) {
        return $rows;
    }

    $conn = $conn ?? $GLOBALS['conn'] ?? null;
    if ($conn === null) {
        return $rows;
    }
    foreach ($tableCfg['foreign_keys'] as $fkCol => $fkCfg) {
        $fkValues = [];
        foreach ($rows as $row) {
            if (isset($row[$fkCol]) && $row[$fkCol] !== '' && $row[$fkCol] !== null) {
                $fkValues[] = $row[$fkCol];
            }
        }
        $fkValues = array_unique($fkValues);
        if (empty($fkValues)) {
            continue;
        }

        $refTable = safe_table($schema, $fkCfg['reference_table']);
        $refSchema = $refTable['schema'] ?? 'public';
        $refName   = $fkCfg['reference_table'];
        $refColId  = $fkCfg['reference_column'] ?? 'id';

        // Handle array of display columns dynamically
        $refDispRaw = $fkCfg['display_column'] ?? [$refColId];
        if (!is_array($refDispRaw)) {
            $refDispRaw = [$refDispRaw];
        }
        if (empty($refDispRaw)) {
            $refDispRaw = [$refColId];
        }

        // Escape all columns and merge them using CONCAT_WS for PostgreSQL
        $escapedDispCols = array_map('pg_ident', $refDispRaw);
        if (count($escapedDispCols) > 1) {
            $dispSql = "CONCAT_WS(' - ', " . implode(', ', $escapedDispCols) . ")";
        } else {
            $dispSql = $escapedDispCols[0];
        }

        $escapedVals = array_map(fn($v) => pg_escape_literal($conn, (string)$v), $fkValues);
        $inClause = implode(', ', $escapedVals);

        // Build the safe SQL query with concatenated display columns
        $sql = sprintf(
            'SELECT %s AS id, %s AS disp FROM %s.%s WHERE %s IN (%s)',
            pg_ident($refColId),
            $dispSql,
            pg_ident($refSchema),
            pg_ident($refName),
            pg_ident($refColId),
            $inClause
        );

        $map = [];
        $res = pg_query($conn, $sql);
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

function normalize_boolean($val): string
{
    $truthy = ['true', '1', 1, true, 't', 'T', 'TRUE'];
    return in_array($val, $truthy, true) ? 'TRUE' : 'FALSE';
}

function type_min_value(string $type)
{
    $t = strtolower($type);
    if (str_contains($t, 'bool')) {
        return 'FALSE';
    }
    if (str_contains($t, 'int') || str_contains($t, 'numeric') || str_contains($t, 'float')) {
        return 0;
    }
    if (str_contains($t, 'date') || str_contains($t, 'time')) {
        return '1970-01-01';
    }

    return '';
}

// Log action to db — returns the new log row id so callers can attach snapshots.
function log_user_action($conn, int $userId, string $action, ?string $targetTable = null, ?int $recordId = null): ?int
{
    $sql = 'INSERT INTO ' . sys_table('users_log')
         . ' (user_id, action, target_table, record_id) VALUES ($1, $2, $3, $4) RETURNING id';
    $res = @pg_query_params($conn, $sql, [$userId, $action, $targetTable, $recordId]);
    if ($res && ($row = pg_fetch_row($res))) {
        return (int) $row[0];
    }
    return null;
}

// Fetch a single record as a JSON string using row_to_json().
// row_to_json requires SELECT * to capture all columns dynamically regardless of schema.
function fetch_record_json($conn, string $schemaName, string $table, int $recordId): ?string
{
    $safeRef = pg_ident($schemaName) . '.' . pg_ident($table);
    $res = pg_query_params(
        $conn,
        "SELECT row_to_json(t) FROM (SELECT * FROM {$safeRef} WHERE id = \$1) t",
        [$recordId]
    );
    if (!$res) {
        return null;
    }
    $row = pg_fetch_row($res);
    return ($row && $row[0] !== null) ? $row[0] : null;
}

// Returns the current owner_id for a record, or null if no ownership row exists.
function get_record_owner_id($conn, string $table, int $recordId): ?int
{
    $t   = sys_table('record_owners');
    $res = @pg_query_params(
        $conn,
        "SELECT owner_id FROM $t WHERE table_name = \$1 AND record_id = \$2 AND is_current = true",
        [$table, $recordId]
    );
    if (!$res || pg_num_rows($res) === 0) {
        return null;
    }
    $row = pg_fetch_assoc($res);
    return $row['owner_id'] !== null ? (int)$row['owner_id'] : null;
}

// Record ownership: mark previous current row inactive, insert new current row.
function set_record_owner($conn, string $table, int $recordId, int $ownerId, int $changedBy): void
{
    $t = sys_table('record_owners');
    @pg_query_params($conn, "UPDATE $t SET is_current = false WHERE table_name = \$1 AND record_id = \$2 AND is_current = true", [$table, $recordId]);
    @pg_query_params($conn, "INSERT INTO $t (table_name, record_id, owner_id, changed_by, is_current) VALUES (\$1, \$2, \$3, \$4, true)", [$table, $recordId, $ownerId, $changedBy]);
}

// Save a JSONB snapshot of the current record state linked to a log entry.
function snapshot_record($conn, string $schemaName, string $table, int $recordId, int $logId): void
{
    $json = fetch_record_json($conn, $schemaName, $table, $recordId);
    if ($json === null) {
        return;
    }
    @pg_query_params(
        $conn,
        'INSERT INTO ' . sys_table('record_snapshots')
            . ' (log_id, table_name, record_id, snapshot) VALUES ($1, $2, $3, $4)',
        [$logId, $table, $recordId, $json]
    );
}
