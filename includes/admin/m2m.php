<?php

declare(strict_types=1);

// includes/admin/m2m.php — admin api.php module: many-to-many relation management (list_m2m, create_m2m, delete_m2m).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'list_m2m') {
    header('Content-Type: application/json');
    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    if (!$schemaPath) {
        echo json_encode(['tables' => [], 'relationships' => []]);
        exit;
    }
    $schema = json_decode(file_get_contents($schemaPath), true);
    if (!is_array($schema['tables'] ?? null)) {
        echo json_encode(['tables' => [], 'relationships' => []]);
        exit;
    }

    $tables = [];
    $relationships = [];
    foreach ($schema['tables'] as $tName => $tCfg) {
        if (!empty($tCfg['hidden'])) {
            continue;
        }
        $tables[] = [
            'name'         => $tName,
            'display_name' => $tCfg['display_name'] ?? $tName,
            'columns'      => array_keys($tCfg['columns'] ?? []),
        ];
        foreach ($tCfg['many_to_many'] ?? [] as $i => $m2m) {
            $otherTable = $m2m['other_table'] ?? '';
            $relationships[] = [
                'table_a'         => $tName,
                'table_a_display' => $tCfg['display_name'] ?? $tName,
                'table_b'         => $otherTable,
                'table_b_display' => $schema['tables'][$otherTable]['display_name'] ?? $otherTable,
                'junction_table'  => $m2m['junction_table']  ?? '',
                'label'           => $m2m['label']           ?? '',
                'display_column'  => $m2m['display_column']  ?? '',
                'm2m_index'       => $i,
            ];
        }
    }
    echo json_encode(['tables' => $tables, 'relationships' => $relationships]);
    exit;
}

if ($action === 'create_m2m') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA     = $body['table_a']       ?? '';
    $tableB     = $body['table_b']       ?? '';
    $jt         = $body['junction_table'] ?? '';
    $selfFk     = $body['self_fk']       ?? '';
    $otherFk    = $body['other_fk']      ?? '';
    $label      = $body['label']         ?? '';
    $displayCol = $body['display_column'] ?? 'name';

    // Validate identifiers: only a-z, 0-9, underscore
    $identRe = '/^[a-z][a-z0-9_]*$/';
    foreach (['tableA' => $tableA, 'tableB' => $tableB, 'jt' => $jt, 'selfFk' => $selfFk, 'otherFk' => $otherFk] as $field => $val) {
        if (!preg_match($identRe, $val)) {
            echo json_encode(['status' => 'error', 'error' => "Invalid identifier: $val"]);
            exit;
        }
    }
    if ($tableA === $tableB) {
        echo json_encode(['status' => 'error', 'error' => 'Tables must be different.']);
        exit;
    }

    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    $schema     = json_decode(file_get_contents($schemaPath), true);
    if (!isset($schema['tables'][$tableA]) || !isset($schema['tables'][$tableB])) {
        echo json_encode(['status' => 'error', 'error' => 'One or both tables not found in schema.']);
        exit;
    }

    // Check for duplicate M2M entry
    foreach ($schema['tables'][$tableA]['many_to_many'] ?? [] as $existing) {
        if (($existing['junction_table'] ?? '') === $jt) {
            echo json_encode(['status' => 'error', 'error' => "M2M via $jt already exists on $tableA."]);
            exit;
        }
    }

    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $pgSchema = $schema['tables'][$tableA]['schema'] ?? 'public';

        // Create junction table in PostgreSQL
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS "%s"."%s" (
                id         SERIAL PRIMARY KEY,
                %s         INT NOT NULL REFERENCES "%s"."%s"(id) ON DELETE CASCADE,
                %s         INT NOT NULL REFERENCES "%s"."%s"(id) ON DELETE CASCADE,
                UNIQUE(%s, %s)
            )',
            $pgSchema,
            $jt,
            pg_ident($selfFk),
            $pgSchema,
            $tableA,
            pg_ident($otherFk),
            $pgSchema,
            $tableB,
            pg_ident($selfFk),
            pg_ident($otherFk)
        );
        $res = @pg_query($conn, $sql);
        if (!$res) {
            $err = pg_last_error($conn);
            echo json_encode(['status' => 'error', 'error' => 'PostgreSQL: ' . $err]);
            exit;
        }

        // Add hidden junction table entry to schema.json (if not exists)
        if (!isset($schema['tables'][$jt])) {
            $schema['tables'][$jt] = [
                'display_name' => str_replace('_', '–', $jt),
                'schema'       => $pgSchema,
                'hidden'       => true,
                'columns'      => [
                    'id'      => ['display_name' => 'ID',   'type' => 'number', 'not_null' => true, 'readonly' => true, 'show_in_grid' => true, 'show_in_edit' => true],
                    $selfFk   => ['display_name' => ucfirst(str_replace('_', ' ', $selfFk)),  'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                    $otherFk  => ['display_name' => ucfirst(str_replace('_', ' ', $otherFk)), 'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                ],
                'foreign_keys' => [
                    $selfFk  => ['reference_table' => $tableA, 'reference_column' => 'id', 'display_column' => 'id'],
                    $otherFk => ['reference_table' => $tableB, 'reference_column' => 'id', 'display_column' => $displayCol],
                ],
                'subtables' => [],
            ];
        }

        // Add many_to_many entry to table_a
        if (!isset($schema['tables'][$tableA]['many_to_many']) || !is_array($schema['tables'][$tableA]['many_to_many'])) {
            $schema['tables'][$tableA]['many_to_many'] = [];
        }
        $schema['tables'][$tableA]['many_to_many'][] = [
            'label'          => $label ?: ucfirst($tableB),
            'junction_table' => $jt,
            'self_fk'        => $selfFk,
            'other_fk'       => $otherFk,
            'other_table'    => $tableB,
            'display_column' => $displayCol,
        ];

        // Save schema.json
        $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($schemaPath, $encoded) === false) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to write schema.json.']);
            exit;
        }

        echo json_encode(['status' => 'success', 'junction_table' => $jt]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'delete_m2m') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');

    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $tableA        = $body['table_a']      ?? '';
    $m2mIndex      = (int)($body['m2m_index'] ?? -1);
    $junctionTable = $body['junction_table'] ?? '';
    $dropTable     = !empty($body['drop_table']);

    if (!preg_match('/^[a-z][a-z0-9_]*$/', $tableA)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid table_a.']);
        exit;
    }

    $schemaPath = realpath(__DIR__ . '/../../config/schema.json');
    $schema     = json_decode(file_get_contents($schemaPath), true);

    if (!isset($schema['tables'][$tableA]['many_to_many'][$m2mIndex])) {
        echo json_encode(['status' => 'error', 'error' => 'M2M entry not found.']);
        exit;
    }

    // Remove the M2M entry
    array_splice($schema['tables'][$tableA]['many_to_many'], $m2mIndex, 1);
    if (empty($schema['tables'][$tableA]['many_to_many'])) {
        unset($schema['tables'][$tableA]['many_to_many']);
    }

    try {
        if ($dropTable && preg_match('/^[a-z][a-z0-9_]*$/', $junctionTable)) {
            require_once __DIR__ . '/../../includes/db.php';
            $conn     = db_connect();
            $pgSchema = $schema['tables'][$junctionTable]['schema'] ?? 'public';
            @pg_query($conn, sprintf('DROP TABLE IF EXISTS %s.%s', pg_ident($pgSchema), pg_ident($junctionTable)));
        }

        // Remove hidden junction table entry from schema.json
        if ($junctionTable && isset($schema['tables'][$junctionTable]['hidden']) && $schema['tables'][$junctionTable]['hidden'] === true) {
            // Only remove if no other table's M2M still references this junction
            $stillUsed = false;
            foreach ($schema['tables'] as $tCfg) {
                foreach ($tCfg['many_to_many'] ?? [] as $m) {
                    if (($m['junction_table'] ?? '') === $junctionTable) {
                        $stillUsed = true;
                        break 2;
                    }
                }
            }
            if (!$stillUsed) {
                unset($schema['tables'][$junctionTable]);
            }
        }

        $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($schemaPath, $encoded) === false) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to write schema.json.']);
            exit;
        }

        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
