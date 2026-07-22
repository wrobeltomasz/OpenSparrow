<?php

declare(strict_types=1);

// includes/admin/etl_flow.php — admin api.php module: ETL Flows (ordered chains of
// existing ETL jobs, run sequentially, stop on first failing step).
// Actions: etl_flow_load, etl_flow_save, run_etl_flow, etl_flow_log, etl_flow_purge_log.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $isDemoMode and require_not_demo() / admin_db_fail() /
// admin_error_message() defined by the front controller. Each block emits JSON and exits.

if ($action === 'etl_flow_load') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'   => false,
        'frequency' => 'daily',
        'flows'     => [],
    ];
    $row    = config_get_row('etl_flows');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    $config['flows'] = is_array($config['flows'] ?? null) ? $config['flows'] : [];

    $etlJobs = (array)(config_get('etl')['jobs'] ?? []);
    $jobs    = [];
    foreach ($etlJobs as $job) {
        if (is_array($job) && (string)($job['id'] ?? '') !== '') {
            $jobs[] = [
                'id'      => (string)$job['id'],
                'name'    => (string)($job['name'] ?? $job['id']),
                'enabled' => (bool)($job['enabled'] ?? true),
            ];
        }
    }

    echo json_encode([
        'status'  => 'success',
        'config'  => $config,
        'jobs'    => $jobs,
        'version' => $row['version'] ?? 0,
    ]);
    exit;
}

if ($action === 'etl_flow_save') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON.']);
        exit;
    }
    require_once __DIR__ . '/../config_store.php';
    $existing = config_get('etl_flows');
    $existing = is_array($existing) ? $existing : [];

    $existingFlowsById = [];
    foreach ((array)($existing['flows'] ?? []) as $ef) {
        if (is_array($ef) && ($ef['id'] ?? '') !== '') {
            $existingFlowsById[(string)$ef['id']] = $ef;
        }
    }

    $etlJobs = (array)(config_get('etl')['jobs'] ?? []);
    $validJobIds = [];
    foreach ($etlJobs as $job) {
        if (is_array($job) && (string)($job['id'] ?? '') !== '') {
            $validJobIds[] = (string)$job['id'];
        }
    }

    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];

    $flows = [];
    foreach ((array)($data['flows'] ?? []) as $flow) {
        if (!is_array($flow)) {
            continue;
        }
        $name = trim((string)($flow['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id = trim((string)($flow['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $steps = [];
        foreach ((array)($flow['steps'] ?? []) as $jobId) {
            $jobId = trim((string)$jobId);
            if ($jobId !== '' && in_array($jobId, $validJobIds, true)) {
                $steps[] = $jobId;
            }
        }

        $prev = $existingFlowsById[$id] ?? [];
        $flows[] = [
            'id'              => $id,
            'name'            => $name,
            'enabled'         => (bool)($flow['enabled'] ?? true),
            'steps'           => $steps,
            // Written only by the cron worker — always carry the previous value forward,
            // matching etl_save's "last_watermark" preservation rule.
            'last_run_status' => $prev['last_run_status'] ?? null,
            'last_run_at'     => $prev['last_run_at'] ?? null,
        ];
    }

    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true) ? $data['frequency'] : 'daily',
        'flows'     => $flows,
    ];

    $expectedVersion = isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : null;
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $result = config_save('etl_flows', $config, $expectedVersion, $userId);
    if ($result['status'] === 'conflict') {
        echo json_encode(['status' => 'error', 'error' => 'Config was modified by someone else — reload and retry.']);
        exit;
    }
    if ($result['status'] !== 'ok') {
        echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'Failed to save config.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'version' => $result['version']]);
    exit;
}

if ($action === 'run_etl_flow') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    require_once __DIR__ . '/etl_common.php';
    $data   = json_decode((string) file_get_contents('php://input'), true);
    $flowId = trim((string)($data['flow_id'] ?? ''));
    etl_admin_run_cron_script(__DIR__ . '/../../cron/cron_etl_flow.php', $flowId, 'ETL Flow cron script not found.');
}

if ($action === 'etl_flow_log') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn    = db_connect();
        $tRunLog = sys_table('etl_flow_run_log');
        if (!@pg_query($conn, "SELECT 1 FROM {$tRunLog} LIMIT 0")) {
            echo json_encode([
                'status' => 'success',
                'rows'   => [],
                'note'   => 'Run Initialize System Tables to create the log table.',
            ]);
            exit;
        }
        $flowId = trim((string)($_GET['flow_id'] ?? ''));
        $sql = "SELECT id, flow_id, flow_name, triggered_by, status, failed_step_index, error_message,
                       started_at, finished_at,
                       EXTRACT(EPOCH FROM (COALESCE(finished_at, now()) - started_at)) AS duration_sec
                FROM {$tRunLog}";
        $params = [];
        if ($flowId !== '') {
            $sql      .= ' WHERE flow_id = $1';
            $params[] = $flowId;
        }
        $sql .= ' ORDER BY started_at DESC LIMIT 50';
        $res = @pg_query_params($conn, $sql, $params);
        if (!$res) {
            admin_db_fail($conn, 'etl_flow_log');
        }
        $rows = [];
        while ($row = pg_fetch_assoc($res)) {
            $rows[] = $row;
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'etl_flow_purge_log') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn     = db_connect();
        $days     = max(1, (int)(json_decode((string) file_get_contents('php://input'), true)['days'] ?? 90));
        $tRunLog  = sys_table('etl_flow_run_log');
        $tStepLog = sys_table('etl_flow_step_log');
        $res      = @pg_query_params(
            $conn,
            "DELETE FROM {$tRunLog} WHERE started_at < NOW() - (\$1 || ' days')::interval",
            [$days]
        );
        if (!$res) {
            admin_db_fail($conn, 'etl_flow_purge_log');
        }
        // Step rows normally cascade away with their parent run (FK ON DELETE CASCADE);
        // this also sweeps any orphans left over from an interrupted/legacy delete.
        @pg_query($conn, "DELETE FROM {$tStepLog} WHERE flow_run_id NOT IN (SELECT id FROM {$tRunLog})");
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
