<?php

declare(strict_types=1);

// includes/admin/backup.php — admin api.php module: table snapshots (backup_tables).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Create a timestamped copy of selected tables (structure + data, no indexes/constraints)
if ($action === 'backup_tables') {
    header('Content-Type: application/json');
    // CREATE TABLE ... AS SELECT is a write — gated like the other DDL actions
    // (create_table / add_column / schema_add_table in schema.php).
    require_not_demo('Disabled in Demo Mode.', 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $tables = $input['tables'] ?? [];
    if (empty($tables) || !is_array($tables)) {
        echo json_encode(['status' => 'error', 'error' => 'No tables provided.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $prefix = date('YmdHi');
        $results = [];
        foreach ($tables as $t) {
            $tableName  = $t['name']   ?? '';
            $schemaName = $t['schema'] ?? '';
            if (empty($tableName) || empty($schemaName)) {
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Missing table or schema name.'];
                continue;
            }
            $backupName  = $prefix . '_' . $tableName;
            $safeSchema  = pg_escape_identifier($conn, $schemaName);
            $safeSource  = pg_escape_identifier($conn, $tableName);
            $safeBackup  = pg_escape_identifier($conn, $backupName);
            $sql = "CREATE TABLE $safeSchema.$safeBackup AS SELECT * FROM $safeSchema.$safeSource";
            $res = @pg_query($conn, $sql);
            if ($res) {
                $rows = pg_affected_rows($res);
                $results[] = ['table' => $tableName, 'backup' => $backupName, 'status' => 'success', 'rows' => $rows];
            } else {
                error_log('[admin_api][backup_tables] ' . pg_last_error($conn));
                $results[] = ['table' => $tableName, 'status' => 'error', 'message' => 'Database error. Check server logs.'];
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
