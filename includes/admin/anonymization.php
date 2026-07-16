<?php

declare(strict_types=1);

// includes/admin/anonymization.php — admin api.php module: GDPR anonymization (anonymization_load/save,
// run_anonymization, preview_anonymization,
// anonymization_log, anonymization_purge_log).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'anonymization_load') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'    => false,
        'frequency'  => 'daily',
        'dictionary' => ['pesel', 'nip', 'email', 'phone', 'address', 'imie', 'nazwisko', 'name'],
        'rules'      => [],
    ];
    $row    = config_get_row('anonymization');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    echo json_encode(['status' => 'success', 'config' => $config, 'version' => $row['version'] ?? 0]);
    exit;
}

if ($action === 'anonymization_save') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid JSON.']);
        exit;
    }
    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];
    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true)
            ? $data['frequency']
            : 'daily',
        'dictionary' => array_values(array_filter(
            array_map('trim', (array)($data['dictionary'] ?? []))
        )),
        'rules'     => [],
    ];
    foreach ((array)($data['rules'] ?? []) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $t  = trim((string)($rule['table']       ?? ''));
        $c  = trim((string)($rule['column']      ?? ''));
        $dc = trim((string)($rule['date_column'] ?? ''));
        $d  = (int)($rule['days'] ?? 0);
        $r  = (string)($rule['replacement'] ?? '');
        if ($t === '' || $c === '' || $dc === '' || $d < 1) {
            continue;
        }
        $config['rules'][] = [
            'table'       => $t,
            'date_column' => $dc,
            'days'        => $d,
            'column'      => $c,
            'replacement' => $r,
        ];
    }
    require_once __DIR__ . '/../config_store.php';
    // Optimistic lock: the editor echoes back the version it loaded (the field is
    // stripped here — the whitelist rebuild above never copies it into $config).
    $expectedVersion = isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : null;
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $result = config_save('anonymization', $config, $expectedVersion, $userId);
    if ($result['status'] === 'conflict') {
        echo json_encode([
            'status' => 'error',
            'error'  => 'Config was modified by someone else — reload and retry.',
        ]);
        exit;
    }
    if ($result['status'] !== 'ok') {
        echo json_encode(['status' => 'error', 'error' => $result['error'] ?? 'Failed to save config.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'version' => $result['version']]);
    exit;
}

if ($action === 'run_anonymization') {
    header('Content-Type: application/json');
    $cronScript = realpath(__DIR__ . '/../../cron/cron_anonymization.php');
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => 'Anonymization cron script not found.']);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $lines      = [];
    $returnCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' admin 2>&1', $lines, $returnCode);
    echo json_encode(['status' => 'success', 'output' => implode("\n", $lines)]);
    exit;
}

if ($action === 'preview_anonymization') {
    header('Content-Type: application/json');
    // Dry run — read-only COUNT(*), modifies no data, so it is allowed even in demo mode.
    $cronScript = realpath(__DIR__ . '/../../cron/cron_anonymization.php');
    if ($cronScript === false || !is_readable($cronScript)) {
        echo json_encode(['status' => 'error', 'error' => 'Anonymization cron script not found.']);
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(['status' => 'error', 'error' => 'exec() is disabled on this server.']);
        exit;
    }
    $lines      = [];
    $returnCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($cronScript) . ' admin dry 2>&1', $lines, $returnCode);
    echo json_encode(['status' => 'success', 'output' => implode("\n", $lines)]);
    exit;
}

if ($action === 'anonymization_log') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn  = db_connect();
        $tLog    = sys_table('anonymization_log');
        $tReport = sys_table('anonymization_report');
        $probe   = @pg_query($conn, "SELECT 1 FROM {$tLog} LIMIT 0");
        if (!$probe) {
            echo json_encode([
                'status' => 'success',
                'rows'   => [],
                'note'   => 'Run Initialize System Tables to create the log table.',
            ]);
            exit;
        }
        $cols = 'l.id, l.started_at, l.finished_at, l.status, l.triggered_by, l.rules_processed, '
              . 'l.rows_anonymized, l.error_message';
        $dur  = 'EXTRACT(EPOCH FROM (COALESCE(l.finished_at, now()) - l.started_at)) AS duration_sec';
        // Reports live in their own table (spw_anonymization_report); join the latest one per run.
        $res  = @pg_query(
            $conn,
            "SELECT {$cols}, r.report, {$dur}
             FROM {$tLog} l
             LEFT JOIN {$tReport} r ON r.log_id = l.id
             ORDER BY l.started_at DESC LIMIT 50"
        );
        if (!$res) {
            // Backward compatibility: report table not yet created (migration 2.9_anonymization_report).
            $res = @pg_query(
                $conn,
                "SELECT {$cols}, {$dur} FROM {$tLog} l ORDER BY l.started_at DESC LIMIT 50"
            );
        }
        if (!$res) {
            admin_db_fail($conn, 'anonymization_log');
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

if ($action === 'anonymization_purge_log') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $days    = max(1, (int)(json_decode((string) file_get_contents('php://input'), true)['days'] ?? 90));
        $tLog    = sys_table('anonymization_log');
        $tReport = sys_table('anonymization_report');
        $res     = @pg_query_params(
            $conn,
            "DELETE FROM {$tLog} WHERE started_at < NOW() - (\$1 || ' days')::interval",
            [$days]
        );
        if (!$res) {
            admin_db_fail($conn, 'anonymization_purge_log');
        }
        // Keep the report table in sync (best-effort: it may not exist on older installs).
        @pg_query_params(
            $conn,
            "DELETE FROM {$tReport} WHERE created_at < NOW() - (\$1 || ' days')::interval",
            [$days]
        );
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// ── RAG Knowledge Base ────────────────────────────────────────────────────────
