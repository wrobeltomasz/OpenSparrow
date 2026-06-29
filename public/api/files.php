<?php

// api/files.php — Files module API (upload, list, soft-delete, config)
// Auth gate: session + UA enforcement; CSRF where applicable; JSON via jsonError()/jsonSuccess()
// match() action routing: list, get_config, upload, delete, save_config, get_relations_config, get_related_records
// Multipart upload with post_max_size-drop detection (-> 413); soft-delete (deleted_at); pagination
// Parameterized queries; sys_table('files')

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_bootstrap.php';
$conn = api_bootstrap();

// jsonError(), jsonSuccess() and requireLogin() are shared via includes/api_helpers.php

// Editor validation helper (upload/delete requires editor role)
function requireEditor(): void
{

    if (($_SESSION['role'] ?? '') !== 'editor') {
        jsonError('Forbidden', 403);
    }
}

// CSRF validation helper for all mutating POST actions.
// Reads token from FormData ($_POST) or JSON body array.
function requireCsrfToken(array $body = []): void
{

    $tokenPost    = $_POST['csrf_token'] ?? $body['csrf_token'] ?? '';
    $tokenSession = $_SESSION['csrf_token'] ?? '';
    if (!$tokenSession || !hash_equals($tokenSession, (string)$tokenPost)) {
        jsonError('Invalid CSRF token.', 403);
    }
}

// Load config from JSON with size guard and corruption check
function loadConfig(): array
{

    $path = __DIR__ . '/../../config/files.json';
    if (!file_exists($path)) {
        jsonError('files.json not found', 500);
    }
    // Guard against unexpectedly large or corrupt config files
    if (filesize($path) > 524288) {
        jsonError('Configuration file too large.', 500);
    }
    $content = file_get_contents($path);
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        jsonError('Configuration file is corrupt.', 500);
    }
    return $decoded;
}

// Save config atomically via temp file + rename to prevent race conditions and partial writes
function saveConfig(array $config): void
{

    $path    = __DIR__ . '/../../config/files.json';
    $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json    = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($tmpPath, $json, LOCK_EX);
    rename($tmpPath, $path);
}

// MySQL Gateway PDO + identifier quoting live in the shared includes/mysql.php module
require_once __DIR__ . '/../../includes/mysql.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $body   = [];
// Catch server-level post_max_size drops
    if ($method === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'multipart/form-data')) {
            jsonError('File is too large. Check php.ini settings.', 413);
        }
    }

    // Safely extract action depending on content type
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body   = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $body['action'] ?? '';
        } else {
            $action = $_POST['action'] ?? '';
        }
    }

    if ($action === '') {
        jsonError('Unknown action or empty request payload.', 400);
    }

    match ($action) {
        'list'                 => actionList($conn),
        'get_config'           => actionGetConfig(),
        'upload'               => actionUpload($conn),
        'delete'               => actionDelete($conn, $body),
        'save_config'          => actionSaveConfig($body),
        'get_relations_config' => actionGetRelationsConfig(),
        'get_related_records'  => actionGetRelatedRecords($conn),
        default                => jsonError("Unknown action: {$action}", 400),
    };
} catch (Throwable $e) {
    error_log('[api_files] ' . $e->getMessage());
    jsonError('Internal server error.', 500);
}

// Handle list action
function actionList($conn): void
{

    requireLogin();
    $page   = max(1, (int) ($_GET['page']   ?? 1));
    $limit  = min(FILES_PAGE_LIMIT_MAX, max(1, (int) ($_GET['limit'] ?? FILES_PAGE_LIMIT)));
    $offset = ($page - 1) * $limit;
    $type   = $_GET['type']   ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $where  = ['f.deleted_at IS NULL'];
    $params = [];
    if ($type !== 'all') {
        $where[]  = 'f.type = $' . (count($params) + 1);
        $params[] = $type;
    }

    if ($search !== '') {
// Convert array to string for easy partial text matching
        $paramIdx = count($params) + 1;
        $where[]  = '(f.name ILIKE $' . $paramIdx . ' OR f.display_name ILIKE $' . $paramIdx . ' OR array_to_string(f.tags, \' \') ILIKE $' . $paramIdx . ')';
        $params[] = '%' . $search . '%';
    }

    $whereSQL = implode(' AND ', $where);
    $countSQL = "SELECT COUNT(*) AS cnt FROM " . sys_table('files') . " f WHERE {$whereSQL}";
    $resCount = pg_query_params($conn, $countSQL, $params);
    if (!$resCount) {
        error_log('api_files actionList count failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    $total = (int) pg_fetch_result($resCount, 0, 'cnt');
    $paramsList = $params;
    $listSQL    = "
        SELECT
            f.uuid, f.name, f.display_name, f.type, f.mime_type,
            f.size_bytes, f.created_at, f.related_table, f.related_id, f.tags,
            u.username AS uploaded_by_username
        FROM " . sys_table('files') . " f
        LEFT JOIN " . sys_table('users') . " u ON u.id = f.uploaded_by
        WHERE {$whereSQL}
        ORDER BY f.created_at DESC
        LIMIT $" . (count($paramsList) + 1) . "
        OFFSET $" . (count($paramsList) + 2);
    $paramsList[] = $limit;
    $paramsList[] = $offset;
    $resList = pg_query_params($conn, $listSQL, $paramsList);
    if (!$resList) {
        error_log('api_files actionList list failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $files = [];
    while ($row = pg_fetch_assoc($resList)) {
        $files[] = $row;
    }

    jsonSuccess([
        'files'       => $files,
        'total_count' => $total,
        'total_pages' => (int) ceil($total / $limit),
        'page'        => $page,
    ]);
}

// Handle get config action
function actionGetConfig(): void
{

    requireEditor();
    jsonSuccess(['config' => loadConfig()]);
}

// Provide relation definitions for frontend upload form
function actionGetRelationsConfig(): void
{

    requireLogin();
    $config    = loadConfig();
    $relations = $config['relations'] ?? [];
    jsonSuccess(['relations' => $relations]);
}

// Handle file upload action
function actionUpload($conn): void
{

    // Uploading is a write operation — restrict to the editor role, matching
    // delete/save_config. Viewers are read-only; the UI hides the upload control
    // for them but the server must enforce it too.
    requireEditor();
    requireCsrfToken();
    if (!isset($_FILES['file'])) {
        jsonError('No file received.', 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError('Upload failed with PHP error code: ' . $file['error'], 400);
    }

    $config = loadConfig();
    $maxBytes = ($config['max_file_size_mb'] ?? FILES_MAX_SIZE_MB) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        jsonError('File exceeds maximum size.', 413);
    }

    $originalName = $file['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts  = $config['allowed_extensions'] ?? [];
    if (!in_array($ext, $allowedExts, true)) {
        jsonError('Extension is not allowed.', 415);
    }

    $type = detectType($ext);
    if (!in_array($type, $config['allowed_types'] ?? [], true)) {
        jsonError('File type category is not allowed.', 415);
    }

    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    }

    $uuid        = generateUuid();
    $filename    = $uuid . '.' . $ext;
    $dir         = rtrim(__DIR__ . '/../../' . ($config['storage_path'] ?? 'storage/files'), '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
        // Deny direct web access on Apache — mirrors the protection on storage/files/.htaccess.
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }

    $destination = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonError('Failed to save physical file to disk.', 500);
    }

    $displayName = trim($_POST['display_name'] ?? '') ?: $originalName;
    $dbPath      = trim($config['storage_path'] ?? 'storage/files', '/') . '/' . $filename;
// Process related record data automatically linked to the configured tables
    $relatedTableReq = trim($_POST['related_table'] ?? '');
    $relatedId       = isset($_POST['related_id']) && $_POST['related_id'] !== '' ? (int)$_POST['related_id'] : null;
    $relatedTable    = null;
// Validate that the requested table exists in config
    if ($relatedTableReq && $relatedId) {
        $relations = $config['relations'] ?? [];
        foreach ($relations as $rel) {
            if ($rel['table'] === $relatedTableReq) {
                $relatedTable = $relatedTableReq;
                break;
            }
        }

        // Security fallback if table is not in the allowed relations list
        if (!$relatedTable) {
            $relatedId = null;
        }
    }

    // Extract and format tags as PostgreSQL array — capped to prevent oversized payloads
    $tagsInput   = mb_substr(trim($_POST['tags'] ?? ''), 0, 500);
    $tagsPgArray = null;
    if ($tagsInput !== '') {
        $tagsList    = array_slice(array_map('trim', explode(',', $tagsInput)), 0, 20);
        $tagsPgArray = '{' . implode(',', array_map(fn($t) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"', $tagsList)) . '}';
    }

    $sql = "
        INSERT INTO " . sys_table('files') . "
            (uuid, name, display_name, type, mime_type, extension, size_bytes, storage_path, uploaded_by, related_table, related_id, tags)
        VALUES
            ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
        RETURNING id, uuid
    ";
    $params = [
        $uuid,
        $originalName,
        $displayName,
        $type,
        $mimeType,
        $ext,
        $file['size'],
        $dbPath,
        $_SESSION['user_id'],
        $relatedTable,
        $relatedId,
        $tagsPgArray
    ];
    $res = pg_query_params($conn, $sql, $params);
    if (!$res) {
        error_log('api_files actionUpload insert failed: ' . pg_last_error($conn));
        unlink($destination);
        jsonError('Database insert failed.', 500);
    }

    $row = pg_fetch_assoc($res);
// Return 201 Created on successful upload
    jsonSuccess(['file' => $row], 201);
}

// Handle soft delete action
function actionDelete($conn, array $body): void
{

    requireEditor();
    requireCsrfToken($body);
    $uuid = trim($body['uuid'] ?? '');
    if (!$uuid) {
        jsonError('uuid is required.', 400);
    }

    $sql = "UPDATE " . sys_table('files') . " SET deleted_at = NOW() WHERE uuid = $1 AND deleted_at IS NULL RETURNING id";
    $res = pg_query_params($conn, $sql, [$uuid]);
    if (!$res) {
        error_log('api_files actionDelete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    if (pg_num_rows($res) === 0) {
        jsonError('File not found or already deleted.', 404);
    }

    jsonSuccess(['deleted' => true]);
}

// Handle config save action (supports multiple relations)
function actionSaveConfig(array $body): void
{

    requireEditor();
    requireCsrfToken($body);
    $current = loadConfig();
    if (isset($body['max_file_size_mb'])) {
        $current['max_file_size_mb'] = max(1, (int) $body['max_file_size_mb']);
    }

    if (isset($body['storage_path'])) {
// Allow only letters, numbers, dashes, underscores and slashes
        $raw = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $body['storage_path']);
// Remove double dot sequences
        $raw = preg_replace('/\.{2,}/', '', $raw);
// Normalize multiple slashes to a single slash
        $raw = preg_replace('/\/+/', '/', $raw);
        $raw = trim($raw, '/');
// Constrain to storage/ subtree — prevents uploads landing in web-accessible directories.
// "storage" alone is accepted; anything that merely *starts with* "storage" as a prefix
// (e.g. "storage-pub") is not, hence the explicit two-case check.
        if ($raw !== 'storage' && !str_starts_with($raw, 'storage/')) {
            $raw = 'storage/files';
        }
        $current['storage_path'] = $raw . '/';
    }

    if (isset($body['allowed_types']) && is_array($body['allowed_types'])) {
        $valid = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
        $current['allowed_types'] = array_values(array_intersect($body['allowed_types'], $valid));
    }

    // Process new multi-relations array
    if (isset($body['relations']) && is_array($body['relations'])) {
        $current['relations'] = [];
        foreach ($body['relations'] as $rel) {
            if (!empty($rel['table'])) {
                $current['relations'][] = [
                    'table' => trim((string)$rel['table']),
                    'col1'  => trim((string)($rel['col1'] ?? 'id')),
                    'col2'  => trim((string)($rel['col2'] ?? ''))
                ];
            }
        }
    }

    // Clean up legacy single-relation fields from old config if they exist
    unset($current['related_table'], $current['display_column_1'], $current['display_column_2']);
    saveConfig($current);
    jsonSuccess(['config' => $current]);
}

// Fetch records for dynamically selected relation table
function actionGetRelatedRecords($conn): void
{

    requireLogin();
    $reqTable = trim($_GET['table'] ?? '');
    if (!$reqTable) {
        jsonSuccess(['records' => []]);
    }

    $config    = loadConfig();
    $relConfig = null;
    $relations = $config['relations'] ?? [];
    foreach ($relations as $rel) {
        if ($rel['table'] === $reqTable) {
            $relConfig = $rel;
            break;
        }
    }

    if (!$relConfig || !preg_match('/^[a-zA-Z0-9_]+$/', $reqTable)) {
        jsonSuccess(['records' => []]);
    }

    $col1 = $relConfig['col1'] ?: 'id';
    $col2 = $relConfig['col2'] ?: '';

    // MySQL Gateway: route through PDO when this table is external
    $mgPath   = __DIR__ . '/../../config/mysql_gateway.json';
    $mgTables = [];
    if (file_exists($mgPath)) {
        $mgCfg    = json_decode(file_get_contents($mgPath), true);
        $mgTables = is_array($mgCfg) ? ($mgCfg['mysql_tables'] ?? []) : [];
    }
    if (in_array($reqTable, $mgTables, true)) {
        $pdo = mysql_pdo('api_files');
        if ($pdo === null) {
            jsonSuccess(['records' => []]);
        }
        $schemaCfg = [];
        $spPath = __DIR__ . '/../../config/schema.json';
        if (file_exists($spPath)) {
            $schemaCfg = json_decode(file_get_contents($spPath), true) ?? [];
        }
        $mysqlPk = (string)($schemaCfg['tables'][$reqTable]['mysql_pk'] ?? 'id');
        try {
            $stmtCols = $pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmtCols->execute([MYSQL_DB, $reqTable]);
            $validCols = array_column($stmtCols->fetchAll(), 'COLUMN_NAME');
        } catch (\PDOException $e) {
            jsonSuccess(['records' => []]);
        }
        $col1 = in_array($col1, $validCols, true) ? $col1 : $mysqlPk;
        $col2 = ($col2 && in_array($col2, $validCols, true)) ? $col2 : '';
        $pkBt   = mysql_bt($mysqlPk);
        $col1Bt = mysql_bt($col1);
        $tblBt  = mysql_bt(MYSQL_DB) . '.' . mysql_bt($reqTable);
        $sel2   = $col2 ? (', ' . mysql_bt($col2)) : '';
        $sql    = "SELECT {$pkBt} AS id, {$col1Bt} AS val1 {$sel2} FROM {$tblBt} ORDER BY {$pkBt} DESC LIMIT 500";
        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('[api_files][mysql] actionGetRelatedRecords: ' . $e->getMessage());
            jsonSuccess(['records' => []]);
        }
        $records = [];
        foreach ($rows as $row) {
            $label = $row['val1'];
            if ($col2 && isset($row[$col2])) {
                $label .= ' - ' . $row[$col2];
            }
            $label     = $label
                ? mb_substr((string)$label, 0, 100) . " (ID: {$row['id']})"
                : "ID: {$row['id']}";
            $records[] = ['id' => $row['id'], 'label' => $label];
        }
        jsonSuccess(['records' => $records]);
    }

// Validate columns directly from database schema
    $sqlCols = "SELECT column_name FROM information_schema.columns WHERE table_schema = $1 AND table_name = $2";
    $resCols = pg_query_params($conn, $sqlCols, [sys_schema(), $reqTable]);
    if (!$resCols) {
        error_log('api_files actionGetRelatedRecords schema check failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $validCols = [];
    while ($r = pg_fetch_assoc($resCols)) {
        $validCols[] = $r['column_name'];
    }

    if (!in_array($col1, $validCols, true)) {
        $col1 = 'id';
    }
    if ($col2 && !in_array($col2, $validCols, true)) {
        $col2 = '';
    }

    // Table name and column names are validated against information_schema and a strict regex above.
    // pg_query_params does not support identifiers as parameters, so verified values are interpolated
    // with double-quote escaping as the standard PostgreSQL safe identifier quoting mechanism.
    $quotedTable = '"' . str_replace('"', '""', $reqTable) . '"';
    $quotedCol1  = '"' . str_replace('"', '""', $col1) . '"';
    $sel2        = $col2 ? ', "' . str_replace('"', '""', $col2) . '"' : '';
    $quotedSchema = '"' . str_replace('"', '""', sys_schema()) . '"';
    $sql = "SELECT id, {$quotedCol1} AS val1 {$sel2} FROM {$quotedSchema}.{$quotedTable} ORDER BY id DESC LIMIT 500";
    $res = pg_query($conn, $sql);
    if (!$res) {
        error_log('api_files actionGetRelatedRecords query failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $records = [];
    while ($row = pg_fetch_assoc($res)) {
        $label = $row['val1'];
        if ($col2 && isset($row[$col2])) {
            $label .= ' - ' . $row[$col2];
        }
        $label     = $label ? mb_substr((string)$label, 0, 100) . " (ID: {$row['id']})" : "ID: {$row['id']}";
        $records[] = ['id' => $row['id'], 'label' => $label];
    }

    jsonSuccess(['records' => $records]);
}

// File type detection logic
function detectType(string $ext): string
{

    $map = [
        // SVG excluded from allowed images to prevent XSS via inline script in SVG content
        'image'       => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'pdf'         => ['pdf'],
        'doc'         => ['doc', 'docx', 'odt', 'rtf'],
        'spreadsheet' => ['xls', 'xlsx', 'ods', 'csv'],
        'archive'     => ['zip', 'tar', 'gz'],
    ];
    foreach ($map as $type => $exts) {
        if (in_array($ext, $exts, true)) {
            return $type;
        }
    }
    return 'other';
}

// Generate secure unique identifier
function generateUuid(): string
{

    $data    = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
