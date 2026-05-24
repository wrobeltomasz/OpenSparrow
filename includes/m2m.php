<?php

declare(strict_types=1);

/**
 * Many-to-many helper functions.
 * Each function reads config from the many_to_many entry in schema.json.
 * All SQL identifiers go through pg_ident() to prevent injection.
 */

/**
 * Fetch all selectable options from the "other" side of the M2M relation.
 * Returns array of ['id' => string, 'label' => string].
 */
function m2m_options(mixed $conn, array $cfg, array $rawSchema): array
{
    $otherTable = $cfg['other_table'] ?? '';

    // Auto-detect from junction FK config when other_table is omitted
    if ($otherTable === '') {
        $jt  = $cfg['junction_table'] ?? '';
        $ofk = $cfg['other_fk']       ?? '';
        $otherTable = $rawSchema['tables'][$jt]['foreign_keys'][$ofk]['reference_table'] ?? '';
    }

    if ($otherTable === '') {
        return [];
    }

    $pgSchema   = $rawSchema['tables'][$otherTable]['schema'] ?? 'public';
    $displayCol = $cfg['display_column'] ?? 'id';

    $sql = sprintf(
        'SELECT "id", %s AS label FROM %s.%s ORDER BY %s',
        pg_ident($displayCol),
        pg_ident($pgSchema),
        pg_ident($otherTable),
        pg_ident($displayCol)
    );

    $res = @pg_query($conn, $sql);
    if (!$res) {
        error_log('[m2m_options] ' . pg_last_error($conn));
        return [];
    }

    $rows = [];
    while ($r = pg_fetch_assoc($res)) {
        $rows[] = ['id' => (string)$r['id'], 'label' => (string)$r['label']];
    }
    return $rows;
}

/**
 * Fetch IDs currently linked to a record in the junction table.
 * Returns array of string IDs.
 */
function m2m_selected(mixed $conn, array $cfg, int $recordId, array $rawSchema): array
{
    $jt       = $cfg['junction_table'] ?? '';
    $selfFk   = $cfg['self_fk']        ?? '';
    $otherFk  = $cfg['other_fk']       ?? '';

    if (!$jt || !$selfFk || !$otherFk) {
        return [];
    }

    $pgSchema = $rawSchema['tables'][$jt]['schema'] ?? 'public';

    $sql = sprintf(
        'SELECT %s FROM %s.%s WHERE %s = $1',
        pg_ident($otherFk),
        pg_ident($pgSchema),
        pg_ident($jt),
        pg_ident($selfFk)
    );

    $res = @pg_query_params($conn, $sql, [$recordId]);
    if (!$res) {
        error_log('[m2m_selected] ' . pg_last_error($conn));
        return [];
    }

    $ids = [];
    while ($r = pg_fetch_assoc($res)) {
        $ids[] = (string)$r[$otherFk];
    }
    return $ids;
}

/**
 * Replace all junction rows for a record with the new selection.
 * Runs atomically: DELETE all + INSERT selected, rolled back on any failure.
 * Returns true on success.
 */
function m2m_sync(mixed $conn, array $cfg, int $recordId, array $selectedIds, array $rawSchema): bool
{
    $jt      = $cfg['junction_table'] ?? '';
    $selfFk  = $cfg['self_fk']        ?? '';
    $otherFk = $cfg['other_fk']       ?? '';

    if (!$jt || !$selfFk || !$otherFk) {
        return false;
    }

    $pgSchema = $rawSchema['tables'][$jt]['schema'] ?? 'public';

    pg_query($conn, 'BEGIN');

    $del = sprintf(
        'DELETE FROM %s.%s WHERE %s = $1',
        pg_ident($pgSchema),
        pg_ident($jt),
        pg_ident($selfFk)
    );
    if (!@pg_query_params($conn, $del, [$recordId])) {
        pg_query($conn, 'ROLLBACK');
        error_log('[m2m_sync] delete failed: ' . pg_last_error($conn));
        return false;
    }

    foreach ($selectedIds as $otherId) {
        if (!ctype_digit((string)$otherId)) {
            continue; // skip non-integer values
        }
        $ins = sprintf(
            'INSERT INTO %s.%s (%s, %s) VALUES ($1, $2)',
            pg_ident($pgSchema),
            pg_ident($jt),
            pg_ident($selfFk),
            pg_ident($otherFk)
        );
        if (!@pg_query_params($conn, $ins, [$recordId, $otherId])) {
            pg_query($conn, 'ROLLBACK');
            error_log('[m2m_sync] insert failed: ' . pg_last_error($conn));
            return false;
        }
    }

    pg_query($conn, 'COMMIT');
    return true;
}
