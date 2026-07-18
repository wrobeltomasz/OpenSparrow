<?php

declare(strict_types=1);

// includes/admin/etl.php — admin api.php module: ETL (MySQL → PostgreSQL import).
// Actions: etl_load, etl_save, etl_test_connection, etl_preview, run_etl,
// etl_log, etl_purge_log.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $isDemoMode and require_not_demo() / admin_db_fail() /
// admin_error_message() defined by the front controller. Each block emits JSON and exits.

if ($action === 'etl_load') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'    => false,
        'frequency'  => 'daily',
        'connection' => ['host' => '', 'port' => 3306, 'database' => '', 'user' => '', 'password' => ''],
        'jobs'       => [],
    ];
    $row    = config_get_row('etl');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    // Never echo the stored password back to the client.
    if (isset($config['connection']['password'])) {
        $config['connection']['password'] = ($config['connection']['password'] !== '') ? '********' : '';
    }
    echo json_encode(['status' => 'success', 'config' => $config, 'version' => $row['version'] ?? 0]);
    exit;
}

if ($action === 'etl_save') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON.']);
        exit;
    }
    require_once __DIR__ . '/../config_store.php';
    $existing = config_get('etl');
    $prevPass = (string)($existing['connection']['password'] ?? '');

    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];
    $conn = (array)($data['connection'] ?? []);
    // Password: a masked value ('********') means "keep the stored one".
    $newPass = (string)($conn['password'] ?? '');
    if ($newPass === '********') {
        $newPass = $prevPass;
    }

    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true) ? $data['frequency'] : 'daily',
        'connection' => [
            'host'     => trim((string)($conn['host'] ?? '')),
            'port'     => (int)($conn['port'] ?? 3306) ?: 3306,
            'database' => trim((string)($conn['database'] ?? '')),
            'user'     => trim((string)($conn['user'] ?? '')),
            'password' => $newPass,
        ],
        'jobs' => [],
    ];

    $validModes = ['full_refresh', 'append', 'upsert'];
    foreach ((array)($data['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $name   = trim((string)($job['name'] ?? ''));
        $query  = trim((string)($job['source_query'] ?? ''));
        $target = trim((string)($job['target_table'] ?? ''));
        if ($name === '' || $query === '' || $target === '') {
            continue;
        }
        $config['jobs'][] = [
            'id'           => (string)($job['id'] ?? bin2hex(random_bytes(8))),
            'name'         => $name,
            'source_query' => $query,
            'target_table' => $target,
            'load_mode'    => in_array($job['load_mode'] ?? '', $validModes, true) ? $job['load_mode'] : 'full_refresh',
            'upsert_key'   => array_values(array_filter(array_map(
                static fn($k) => trim((string)$k),
                (array)($job['upsert_key'] ?? [])
            ), static fn($k) => $k !== '')),
            'enabled'      => (bool)($job['enabled'] ?? true),
        ];
    }

    $expectedVersion = isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : null;
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $result = config_save('etl', $config, $expectedVersion, $userId);
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

if ($action === 'etl_test_connection') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../etl_engine.php';
    require_once __DIR__ . '/../config_store.php';
    $data = json_decode((string) file_get_contents('php://input'), true);
    $conn = is_array($data['connection'] ?? null) ? $data['connection'] : [];
    // Resolve a masked/empty password to the stored one so "Test" works without re-entry.
    if (($conn['password'] ?? '') === '********' || ($conn['password'] ?? '') === '') {
        $stored = config_get('etl');
        $conn['password'] = (string)($stored['connection']['password'] ?? '');
    }
    $pdo = etl_mysql_pdo($conn, 'etl:test');
    if ($pdo === null) {
        echo json_encode(['status' => 'error', 'error' => 'Could not connect — check host, database, user and password.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'Connection OK.']);
    exit;
}

if ($action === 'etl_preview') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../etl_engine.php';
    require_once __DIR__ . '/../config_store.php';
    $data     = json_decode((string) file_get_contents('php://input'), true);
    $connIn   = is_array($data['connection'] ?? null) ? $data['connection'] : [];
    $query    = trim((string)($data['source_query'] ?? ''));
    if (($connIn['password'] ?? '') === '********' || ($connIn['password'] ?? '') === '') {
        $stored = config_get('etl');
        $connIn['password'] = (string)($stored['connection']['password'] ?? '');
    }
    if (($err = etl_validate_source_query($query)) !== null) {
        echo json_encode(['status' => 'error', 'error' => $err]);
        exit;
    }
    $pdo = etl_mysql_pdo($connIn, 'etl:preview');
    if ($pdo === null) {
        echo json_encode(['status' => 'error', 'error' => 'MySQL source connection is not configured or unavailable.']);
        exit;
    }
    try {
        $stmt = $pdo->query('SELECT * FROM (' . $query . ') AS _etl_preview LIMIT 20');
        $rows = $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log('[etl][preview] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'error' => 'Preview query failed.']);
        exit;
    }
    $columns = empty($rows) ? [] : array_keys($rows[0]);
    echo json_encode(['status' => 'success', 'columns' => $columns, 'rows' => $rows]);
    exit;
}

if ($action === 'run_etl') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    $cronScript = realpath(__DIR__ . '/../../cron/cron_etl.php');
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => 'ETL cron script not found.']);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $data  = json_decode((string) file_get_contents('php://input'), true);
    $jobId = trim((string)($data['job_id'] ?? ''));
    $args  = 'admin';
    if ($jobId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $jobId)) {
        $args .= ' ' . escapeshellarg($jobId);
    }
    $lines = [];
    $code  = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' ' . $args . ' 2>&1', $lines, $code);
    echo json_encode(['status' => 'success', 'output' => implode("\n", $lines)]);
    exit;
}

if ($action === 'etl_log') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tLog = sys_table('etl_log');
        if (!@pg_query($conn, "SELECT 1 FROM {$tLog} LIMIT 0")) {
            echo json_encode([
                'status' => 'success',
                'rows'   => [],
                'note'   => 'Run Initialize System Tables to create the log table.',
            ]);
            exit;
        }
        $res = @pg_query(
            $conn,
            "SELECT id, job_id, job_name, triggered_by, status, rows_read, rows_written, error_message,
                    started_at, finished_at,
                    EXTRACT(EPOCH FROM (COALESCE(finished_at, now()) - started_at)) AS duration_sec
             FROM {$tLog} ORDER BY started_at DESC LIMIT 50"
        );
        if (!$res) {
            admin_db_fail($conn, 'etl_log');
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

if ($action === 'etl_purge_log') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $days = max(1, (int)(json_decode((string) file_get_contents('php://input'), true)['days'] ?? 90));
        $tLog = sys_table('etl_log');
        $res  = @pg_query_params(
            $conn,
            "DELETE FROM {$tLog} WHERE started_at < NOW() - (\$1 || ' days')::interval",
            [$days]
        );
        if (!$res) {
            admin_db_fail($conn, 'etl_purge_log');
        }
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
