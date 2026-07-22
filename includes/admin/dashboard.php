<?php

declare(strict_types=1);

// includes/admin/dashboard.php — admin api.php module: dashboard widget editor helpers.
// Action: dashboard_calculate — runs the widget's *unsaved* query (as currently edited
// in the admin form) against real data, so the operator can verify the WHERE conditions
// and aggregation before saving. Included by public/admin/api.php AFTER the admin-role
// gate, CSRF check and POST-method enforcement — never include or serve this file
// directly. Uses $action / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() helpers defined by the front controller.

if ($action === 'dashboard_calculate') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        require_once __DIR__ . '/../../includes/dashboard_query.php';

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new AdminApiMessage('Invalid request body.');
        }

        $table = (string)($body['table'] ?? '');
        $query = is_array($body['query'] ?? null) ? $body['query'] : [];
        $displayColumns = is_array($body['display_columns'] ?? null) ? $body['display_columns'] : [];

        if ($table === '') {
            throw new AdminApiMessage('No source table selected.');
        }

        $schemaCfg = config_get('schema') ?? [];
        try {
            $tableCfg = safe_table($schemaCfg, $table);
        } catch (Throwable $e) {
            throw new AdminApiMessage('Unknown table: ' . $table);
        }
        $schemaName = $tableCfg['schema'] ?? 'public';

        $conn = db_connect();
        $condSql = dashboard_conditions_sql($conn, $tableCfg, is_array($query['conditions'] ?? null) ? $query['conditions'] : []);
        $sqlWhere = $condSql === '' ? '' : ' WHERE ' . $condSql;

        $result = dashboard_run_widget_query($conn, $tableCfg, $schemaName, $table, $query, $displayColumns, $sqlWhere);

        if (isset($result['sql_error'])) {
            echo json_encode(['status' => 'error', 'error' => $result['sql_error']]);
            exit;
        }

        echo json_encode(['status' => 'success', 'data' => $result['data']]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
