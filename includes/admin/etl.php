<?php

declare(strict_types=1);

// includes/admin/etl.php — admin api.php module: ETL (external source → PostgreSQL import).
// Actions: etl_load, etl_save, etl_test_connection, etl_preview, etl_target_schemas,
// etl_target_tables, run_etl, etl_log, etl_purge_log.
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $isDemoMode and require_not_demo() / admin_db_fail() /
// admin_error_message() defined by the front controller. Each block emits JSON and exits.

// Upgrade a pre-multi-source config (single top-level "connection" block) into the
// "sources" list shape, tagging any job without a source_id onto the migrated source.
// Idempotent — a no-op once "sources" already exists. Not persisted by etl_load itself;
// the shape sticks once the admin saves the config again.
function etl_migrate_legacy_connection(array $config): array
{
    if (!empty($config['sources']) || empty($config['connection'])) {
        unset($config['connection']);
        $config['sources'] = is_array($config['sources'] ?? null) ? $config['sources'] : [];
        $config['jobs']    = is_array($config['jobs'] ?? null) ? $config['jobs'] : [];
        return $config;
    }
    $legacy = (array) $config['connection'];
    $source = [
        'id'             => 'legacy',
        'name'           => 'Default source',
        'driver'         => $legacy['driver'] ?? 'mysql',
        'host'           => $legacy['host'] ?? '',
        'port'           => $legacy['port'] ?? 3306,
        'database'       => $legacy['database'] ?? '',
        'user'           => $legacy['user'] ?? '',
        'password'       => $legacy['password'] ?? '',
        'protocol'       => 'ftp',
        'remote_dir'     => '',
        'file_name'      => '',
        'csv_delimiter'  => ',',
        'csv_has_header' => true,
        'passive_mode'   => true,
    ];
    $config['sources'] = [$source];
    foreach ((array)($config['jobs'] ?? []) as $i => $job) {
        if (is_array($job) && empty($job['source_id'])) {
            $config['jobs'][$i]['source_id'] = 'legacy';
        }
    }
    unset($config['connection']);
    return $config;
}

// Look up the stored password for a source id, for resolving a masked '********'
// value sent back by the admin UI (test/preview against an already-saved source).
function etl_stored_source_password(string $sourceId): string
{
    if ($sourceId === '') {
        return '';
    }
    require_once __DIR__ . '/../config_store.php';
    $stored = etl_migrate_legacy_connection(config_get('etl') ?: []);
    foreach ((array)($stored['sources'] ?? []) as $src) {
        if (is_array($src) && (string)($src['id'] ?? '') === $sourceId) {
            return (string)($src['password'] ?? '');
        }
    }
    return '';
}

if ($action === 'etl_load') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config_store.php';
    $defaults = [
        'enabled'   => false,
        'frequency' => 'daily',
        'sources'   => [],
        'jobs'      => [],
    ];
    $row    = config_get_row('etl');
    $config = is_array($row['value'] ?? null) ? array_merge($defaults, $row['value']) : $defaults;
    $config = etl_migrate_legacy_connection($config);
    // Backfill target_schema on jobs saved before schema selection existed.
    require_once __DIR__ . '/../db.php';
    foreach ($config['jobs'] as $i => $job) {
        if (is_array($job) && trim((string)($job['target_schema'] ?? '')) === '') {
            $config['jobs'][$i]['target_schema'] = sys_schema();
        }
    }
    // Backfill empty source ids from a past etl_save bug (empty string survived the
    // `?? bin2hex(...)` fallback, since `??` only triggers on null/unset). Jobs that
    // pointed at that blank id are relinked so they keep working after the id is fixed.
    foreach ($config['sources'] as $i => $src) {
        if (is_array($src) && trim((string)($src['id'] ?? '')) === '') {
            $oldId = (string)($src['id'] ?? '');
            $newId = bin2hex(random_bytes(8));
            $config['sources'][$i]['id'] = $newId;
            foreach ($config['jobs'] as $j => $job) {
                if (is_array($job) && (string)($job['source_id'] ?? '') === $oldId) {
                    $config['jobs'][$j]['source_id'] = $newId;
                }
            }
        }
    }
    // Never echo stored passwords back to the client.
    foreach ($config['sources'] as $i => $src) {
        if (isset($src['password'])) {
            $config['sources'][$i]['password'] = ($src['password'] !== '') ? '********' : '';
        }
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
    require_once __DIR__ . '/../etl_engine.php';
    $existing = etl_migrate_legacy_connection(config_get('etl') ?: []);

    $existingPassById = [];
    foreach ((array)($existing['sources'] ?? []) as $es) {
        if (is_array($es) && ($es['id'] ?? '') !== '') {
            $existingPassById[(string)$es['id']] = (string)($es['password'] ?? '');
        }
    }

    $validFrequencies = ['manual', 'daily', 'weekly', 'monthly'];
    $validDrivers      = array_keys(etl_source_drivers());

    $sources     = [];
    $validSourceIds = [];
    foreach ((array)($data['sources'] ?? []) as $src) {
        if (!is_array($src)) {
            continue;
        }
        $name = trim((string)($src['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id     = trim((string)($src['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }
        $driver = strtolower(trim((string)($src['driver'] ?? 'mysql')));
        if (!in_array($driver, $validDrivers, true)) {
            $driver = 'mysql';
        }
        // Password: a masked value ('********') means "keep the stored one" for this source id.
        $newPass = (string)($src['password'] ?? '');
        if ($newPass === '********') {
            $newPass = $existingPassById[$id] ?? '';
        }
        $protocol = strtolower(trim((string)($src['protocol'] ?? 'ftp')));
        $csvDelim = (string)($src['csv_delimiter'] ?? ',');
        $csvDelim = ($csvDelim !== '') ? substr($csvDelim, 0, 1) : ',';
        $sources[] = [
            'id'             => $id,
            'name'           => $name,
            'driver'         => $driver,
            'host'           => trim((string)($src['host'] ?? '')),
            'port'           => (int)($src['port'] ?? 0) ?: etl_source_drivers()[$driver],
            'database'       => trim((string)($src['database'] ?? '')),
            'user'           => trim((string)($src['user'] ?? '')),
            'password'       => $newPass,
            // csv_ftp-only fields; harmless (ignored) for database drivers.
            'protocol'       => in_array($protocol, ['ftp', 'ftps'], true) ? $protocol : 'ftp',
            'remote_dir'     => trim((string)($src['remote_dir'] ?? '')),
            'file_name'      => trim((string)($src['file_name'] ?? '')),
            'csv_delimiter'  => $csvDelim,
            'csv_has_header' => (bool)($src['csv_has_header'] ?? true),
            'passive_mode'   => (bool)($src['passive_mode'] ?? true),
        ];
        $validSourceIds[] = $id;
    }

    $config = [
        'enabled'   => (bool)($data['enabled'] ?? false),
        'frequency' => in_array($data['frequency'] ?? '', $validFrequencies, true) ? $data['frequency'] : 'daily',
        'sources'   => $sources,
        'jobs'      => [],
    ];

    $existingJobsById = [];
    foreach ((array)($existing['jobs'] ?? []) as $ej) {
        if (is_array($ej) && ($ej['id'] ?? '') !== '') {
            $existingJobsById[(string)$ej['id']] = $ej;
        }
    }

    $sourceDriverById = [];
    foreach ($sources as $s) {
        $sourceDriverById[$s['id']] = $s['driver'];
    }

    $validModes = ['full_refresh', 'append', 'upsert'];
    foreach ((array)($data['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $name   = trim((string)($job['name'] ?? ''));
        $target = trim((string)($job['target_table'] ?? ''));
        $schema = trim((string)($job['target_schema'] ?? ''));
        if ($name === '' || $target === '' || $schema === '') {
            continue;
        }
        $id = trim((string)($job['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $sourceId = trim((string)($job['source_id'] ?? ''));
        if (!in_array($sourceId, $validSourceIds, true)) {
            $sourceId = $validSourceIds[0] ?? '';
        }

        // Remote-file (csv_ftp) sources have no SQL query — the whole file is read.
        // Database sources require a non-empty read-only SELECT.
        $isRemoteFileJob = etl_source_is_remote_file_driver($sourceDriverById[$sourceId] ?? '');
        $query = trim((string)($job['source_query'] ?? ''));
        if (!$isRemoteFileJob && $query === '') {
            continue;
        }

        $columnMap = [];
        foreach ((array)($job['column_map'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $src = trim((string)($m['source'] ?? ''));
            $tgt = trim((string)($m['target'] ?? ''));
            if ($src !== '' && $tgt !== '') {
                $columnMap[] = ['source' => $src, 'target' => $tgt];
            }
        }

        $config['jobs'][] = [
            'id'                        => $id,
            'name'                      => $name,
            'source_id'                 => $sourceId,
            'source_query'              => $query,
            'target_schema'             => $schema,
            'target_table'              => $target,
            'load_mode'                 => in_array($job['load_mode'] ?? '', $validModes, true) ? $job['load_mode'] : 'full_refresh',
            'upsert_key'                => array_values(array_filter(array_map(
                static fn($k) => trim((string)$k),
                (array)($job['upsert_key'] ?? [])
            ), static fn($k) => $k !== '')),
            'enabled'                   => (bool)($job['enabled'] ?? true),
            'batch_size'                => max(50, min(5000, (int)($job['batch_size'] ?? 500) ?: 500)),
            'incremental_column'        => trim((string)($job['incremental_column'] ?? '')),
            'incremental_initial_value' => trim((string)($job['incremental_initial_value'] ?? '')),
            'column_map'                => $columnMap,
            // Watermark progress is written by the cron worker, never by the admin form —
            // always carry the previously-stored value forward so a config edit can't reset it.
            'last_watermark'            => $existingJobsById[$id]['last_watermark'] ?? null,
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
    // Resolve a masked/empty password to the stored one (matched by source id) so
    // "Test" works without re-entering it for an already-saved source.
    if (($conn['password'] ?? '') === '********' || ($conn['password'] ?? '') === '') {
        $conn['password'] = etl_stored_source_password((string)($conn['id'] ?? ''));
    }

    if (etl_source_is_remote_file_driver(strtolower(trim((string)($conn['driver'] ?? ''))))) {
        $ftp = etl_ftp_connect($conn, 'etl:test');
        if ($ftp === null) {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Could not connect — check host, port, protocol, directory, user and password.',
            ]);
            exit;
        }
        $fileName = trim((string)($conn['file_name'] ?? ''));
        $fileExists = ($fileName === '')
            || @ftp_size($ftp, $fileName) !== -1
            || in_array($fileName, (array)@ftp_nlist($ftp, '.'), true);
        if (!$fileExists) {
            @ftp_close($ftp);
            echo json_encode([
                'status' => 'error',
                'error'  => 'Connected, but the file "' . $fileName . '" was not found in the configured directory.',
            ]);
            exit;
        }
        @ftp_close($ftp);
        echo json_encode(['status' => 'success', 'message' => 'Connection OK.']);
        exit;
    }

    $pdo = etl_source_pdo($conn, 'etl:test');
    if ($pdo === null) {
        echo json_encode(['status' => 'error', 'error' => 'Could not connect — check driver, host, database, user and password.']);
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
        $connIn['password'] = etl_stored_source_password((string)($connIn['id'] ?? ''));
    }

    if (etl_source_is_remote_file_driver(strtolower(trim((string)($connIn['driver'] ?? ''))))) {
        $rows = etl_fetch_csv_rows($connIn, 'etl:preview', 20);
        if ($rows === null) {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Could not fetch/parse the source CSV file — check connection, path and file name.',
            ]);
            exit;
        }
        $columns = empty($rows) ? [] : array_keys($rows[0]);
        echo json_encode(['status' => 'success', 'columns' => $columns, 'rows' => $rows]);
        exit;
    }

    if (($err = etl_validate_source_query($query)) !== null) {
        echo json_encode(['status' => 'error', 'error' => $err]);
        exit;
    }
    $pdo = etl_source_pdo($connIn, 'etl:preview');
    if ($pdo === null) {
        echo json_encode(['status' => 'error', 'error' => 'Source connection is not configured or unavailable.']);
        exit;
    }
    try {
        // Run the validated read-only query as-is (do not wrap it in a derived table —
        // that is invalid SQL for a WITH/CTE query, which the validator permits) and cap
        // the result to 20 rows by fetching incrementally rather than appending LIMIT
        // (which would clash with a query that already has its own LIMIT).
        $stmt = $pdo->query($query);
        $rows = [];
        while (count($rows) < 20 && ($row = $stmt->fetch()) !== false) {
            $rows[] = $row;
        }
        $stmt->closeCursor();
    } catch (\PDOException $e) {
        error_log('[etl][preview] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'error' => 'Preview query failed.']);
        exit;
    }
    $columns = empty($rows) ? [] : array_keys($rows[0]);
    echo json_encode(['status' => 'success', 'columns' => $columns, 'rows' => $rows]);
    exit;
}

if ($action === 'etl_target_schemas') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $sql  = 'SELECT schema_name FROM information_schema.schemata '
            . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema') "
            . "AND schema_name NOT LIKE 'pg\\_toast%' AND schema_name NOT LIKE 'pg\\_temp%' "
            . 'ORDER BY schema_name';
        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'etl_target_schemas');
        }
        $schemas = [];
        while ($row = pg_fetch_assoc($res)) {
            $schemas[] = $row['schema_name'];
        }
        echo json_encode(['status' => 'success', 'schemas' => $schemas]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'etl_target_tables') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn   = db_connect();
        $schema = trim((string)($_GET['schema'] ?? ''));
        if ($schema === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid schema.']);
            exit;
        }
        // Excludes spw_* system tables — the ETL target picker is for application
        // data tables, not the internal schema/config/audit tables.
        $res = @pg_query_params(
            $conn,
            "SELECT table_name FROM information_schema.tables "
                . "WHERE table_schema = $1 AND table_type = 'BASE TABLE' "
                . "AND table_name NOT LIKE 'spw\\_%' ESCAPE '\\' "
                . 'ORDER BY table_name',
            [$schema]
        );
        if (!$res) {
            admin_db_fail($conn, 'etl_target_tables');
        }
        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $tables[] = $row['table_name'];
        }
        echo json_encode(['status' => 'success', 'tables' => $tables]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'run_etl') {
    header('Content-Type: application/json');
    require_not_demo('Demo mode — writes disabled.');
    require_once __DIR__ . '/etl_common.php';
    $data  = json_decode((string) file_get_contents('php://input'), true);
    $jobId = trim((string)($data['job_id'] ?? ''));
    etl_admin_run_cron_script(__DIR__ . '/../../cron/cron_etl.php', $jobId, 'ETL cron script not found.');
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
