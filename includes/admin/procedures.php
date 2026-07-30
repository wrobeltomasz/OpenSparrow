<?php

declare(strict_types=1);

// includes/admin/procedures.php — admin api.php module: PostgreSQL stored-procedure
// introspection (list_procedures) used by the Workflows editor to let an admin pick
// a procedure and map its IN parameters to workflow form fields.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'list_procedures') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        // Procedures only (not functions) in user schemas, with their IN parameters
        // in declaration order. Joined on specific_name so overloaded procedures stay
        // separate rows; the client labels each entry with its full argument list.
        $sql = "SELECT r.routine_schema,
                       r.routine_name,
                       r.specific_name,
                       p.parameter_name,
                       p.data_type,
                       p.ordinal_position
                  FROM information_schema.routines r
                  LEFT JOIN information_schema.parameters p
                         ON p.specific_name = r.specific_name
                        AND p.specific_schema = r.specific_schema
                        AND p.parameter_mode IN ('IN', 'INOUT')
                 WHERE r.routine_type = 'PROCEDURE'
                   AND r.routine_schema NOT IN ('pg_catalog', 'information_schema')
                   AND r.routine_schema NOT LIKE 'pg_toast%'
                   AND r.routine_schema NOT LIKE 'pg_temp%'
                 ORDER BY r.routine_schema, r.routine_name, r.specific_name, p.ordinal_position";

        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'list_procedures');
        }

        // Fold the flat parameter rows into one entry per procedure signature.
        $bySpecific = [];
        while ($row = pg_fetch_assoc($res)) {
            $key = (string)$row['specific_name'];
            if (!isset($bySpecific[$key])) {
                $bySpecific[$key] = [
                    'schema' => (string)$row['routine_schema'],
                    'name'   => (string)$row['routine_name'],
                    'params' => [],
                ];
            }
            // LEFT JOIN yields a single NULL-parameter row for zero-argument procedures.
            if ($row['parameter_name'] === null && $row['ordinal_position'] === null) {
                continue;
            }
            $bySpecific[$key]['params'][] = [
                'name'     => (string)($row['parameter_name'] ?? ''),
                'type'     => (string)($row['data_type'] ?? ''),
                'position' => (int)$row['ordinal_position'],
            ];
        }

        echo json_encode(['status' => 'ok', 'procedures' => array_values($bySpecific)]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
        exit;
    }
}
