<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap(['csrf' => 'manual']);

require_once __DIR__ . '/../../includes/config_store.php';

require_once __DIR__ . '/../../includes/images.php';

function validateImageTarget($conn, string $table, int $recordId): array
{
    if ($table === '' || $recordId <= 0) {
        jsonError('related_table and related_id are required for gallery uploads.', 400);
    }

    $schema = config_get('schema');
    $cfg    = is_array($schema) ? images_config($schema, $table) : null;
    if ($cfg === null) {
        jsonError('This table does not accept images.', 403);
    }

    $tableCfg = $schema['tables'][$table];
    if (!can_access_record($conn, $tableCfg, $table, $recordId, (int)$_SESSION['user_id'])) {
        jsonError('Forbidden', 403);
    }

    if (images_count($conn, $table, $recordId) >= $cfg['max_per_record']) {
        jsonError('Image limit reached for this record.', 409);
    }

    return ['table' => $table, 'id' => $recordId];
}

function loadConfig(): array
{
    $decoded = config_get('files');
    if (!is_array($decoded)) {
        jsonError('Files configuration not found', 500);
    }
    return $decoded;
}

function saveConfig(array $config): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $result = config_save('files', $config, null, $userId);
    if ($result['status'] !== 'ok') {
        jsonError($result['error'] ?? 'Could not save files configuration.', 500);
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')
) {
    jsonError('File is too large. Check php.ini settings.', 413);
}

['action' => $action, 'body' => $body] = os_api_action();

os_api_dispatch($action, [
    'list'                 => fn() => files_action_list($conn),
    'get_config'           => fn() => files_action_get_config(),
    'upload'               => fn() => files_action_upload($conn),
    'delete'               => fn() => files_action_delete($conn, $body),
    'mass_delete'          => fn() => files_action_mass_delete($conn, $body),
    'mass_tag'             => fn() => files_action_mass_tag($conn, $body),
    'update_meta'          => fn() => files_action_update_meta($conn, $body),
    'save_config'          => fn() => files_action_save_config($body),
    'get_relations_config' => fn() => files_action_get_relations_config(),
    'get_related_records'  => fn() => files_action_get_related_records($conn),
], 'api_files', 'Unknown action or empty request payload.');

function files_action_list($conn): void
{
    requireLogin();
    $page   = max(1, (int) ($_GET['page']   ?? 1));
    $limit  = min(FILES_PAGE_LIMIT_MAX, max(1, (int) ($_GET['limit'] ?? FILES_PAGE_LIMIT)));
    $offset = ($page - 1) * $limit;
    $type   = $_GET['type']   ?? 'all';
    $search = trim($_GET['search'] ?? '');

    $sortMap = [
        'type'       => 'f.type',
        'name'       => 'LOWER(f.name)',
        'display'    => "LOWER(COALESCE(NULLIF(f.display_name, ''), f.name))",
        'tags'       => "array_to_string(f.tags, ' ')",
        'size'       => 'f.size_bytes',
        'related'    => 'f.related_table',
        'created_at' => 'f.created_at',
    ];
    $orderExpr = $sortMap[$_GET['sort'] ?? 'created_at'] ?? 'f.created_at';
    $orderDir  = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $where  = ['f.deleted_at IS NULL'];
    $params = [];
    if ($type !== 'all') {
        $where[]  = 'f.type = $' . (count($params) + 1);
        $params[] = $type;
    }

    if ($search !== '') {
        $paramIdx = count($params) + 1;
        $where[]  = '(f.name ILIKE $' . $paramIdx . ' OR f.display_name ILIKE $' . $paramIdx . ' OR array_to_string(f.tags, \' \') ILIKE $' . $paramIdx . ')';
        $params[] = '%' . $search . '%';
    }

    $allowedTables = user_allowed_tables();
    if ($allowedTables !== null) {
        $where[]  = "(f.related_table IS NULL OR f.related_table = '' OR f.related_table = ANY($"
            . (count($params) + 1) . '::text[]))';
        $params[] = textListToPgArray($allowedTables);
    }

    $ownerRestricted = [];
    if (($_SESSION['role'] ?? '') !== 'admin') {
        foreach ((config_get('schema') ?? [])['tables'] ?? [] as $tName => $tCfg) {
            if (is_array($tCfg) && !empty($tCfg['owner_restricted'])) {
                $ownerRestricted[] = $tName;
            }
        }
    }
    if ($ownerRestricted !== []) {
        $tOwners  = sys_table('record_owners');
        $ownerIdx = count($params) + 1;
        $tblIdx   = count($params) + 2;
        $where[]  = "NOT EXISTS (SELECT 1 FROM {$tOwners} ro"
            . ' WHERE ro.table_name = f.related_table AND ro.record_id = f.related_id'
            . ' AND ro.is_current = true'
            . " AND ro.owner_id IS NOT NULL AND ro.owner_id != \${$ownerIdx}"
            . " AND f.related_table = ANY(\${$tblIdx}::text[]))";
        $params[] = (int) $_SESSION['user_id'];
        $params[] = textListToPgArray($ownerRestricted);
    }

    $whereSQL = implode(' AND ', $where);
    $countSQL = "SELECT COUNT(*) AS cnt FROM " . sys_table('files') . " f WHERE {$whereSQL}";
    $resCount = pg_query_params($conn, $countSQL, $params);
    if (!$resCount) {
        error_log('api_files files_action_list count failed: ' . pg_last_error($conn));
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
        ORDER BY {$orderExpr} {$orderDir}, f.id DESC
        LIMIT $" . (count($paramsList) + 1) . "
        OFFSET $" . (count($paramsList) + 2);
    $paramsList[] = $limit;
    $paramsList[] = $offset;
    $resList = pg_query_params($conn, $listSQL, $paramsList);
    if (!$resList) {
        error_log('api_files files_action_list list failed: ' . pg_last_error($conn));
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

function files_action_get_config(): void
{
    requireWrite();
    jsonSuccess(['config' => loadConfig()]);
}

function files_action_get_relations_config(): void
{
    requireLogin();
    $config    = loadConfig();
    $relations = $config['relations'] ?? [];
    jsonSuccess(['relations' => $relations]);
}

function files_action_upload($conn): void
{
    requireWrite();
    os_require_csrf('body');
    if (!isset($_FILES['file'])) {
        jsonError('No file received.', 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError(uploadErrorMessage((int) $file['error']), 400);
    }

    $config = loadConfig();
    $maxBytes = ($config['max_file_size_mb'] ?? FILES_MAX_SIZE_MB) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        jsonError('File exceeds maximum size.', 413);
    }

    $originalName = $file['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExts = array_intersect($config['allowed_extensions'] ?? [], array_keys(verifiableExtensionMap()));
    if (!in_array($ext, $allowedExts, true)) {
        jsonError('Extension is not allowed.', 415);
    }

    $type = detectType($ext);
    if (!in_array($type, $config['allowed_types'] ?? [], true)) {
        jsonError('File type category is not allowed.', 415);
    }

    $imageMode   = ($_POST['related_field'] ?? '') === IMAGES_FIELD;
    $imageTarget = null;

    $reqRelatedTable = trim($_POST['related_table'] ?? '');
    if ($reqRelatedTable !== '') {
        require_table_access($reqRelatedTable);
    }
    if ($imageMode) {
        if ($type !== 'image') {
            jsonError('Only image files can be added to a record gallery.', 415);
        }
        $imageTarget = validateImageTarget($conn, trim($_POST['related_table'] ?? ''), (int)($_POST['related_id'] ?? 0));
    }

    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!mimeMatchesExtension($ext, $mimeType)) {
            unlink($file['tmp_name']);
            jsonError('File content does not match its extension.', 415);
        }
    }

    $uuid        = generateUuid();
    $filename    = $uuid . '.' . $ext;
    $dir         = rtrim(__DIR__ . '/../../' . ($config['storage_path'] ?? 'storage/files'), '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);

        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }

    $destination = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonError('Failed to save physical file to disk.', 500);
    }

    $displayName = trim($_POST['display_name'] ?? '') ?: $originalName;
    $dbPath      = trim($config['storage_path'] ?? 'storage/files', '/') . '/' . $filename;

    $relatedTableReq = trim($_POST['related_table'] ?? '');
    $relatedId       = isset($_POST['related_id']) && $_POST['related_id'] !== '' ? (int)$_POST['related_id'] : null;
    $relatedTable    = null;
    $relatedField    = null;
    if ($imageMode) {
        $relatedTable = $imageTarget['table'];
        $relatedId    = $imageTarget['id'];
        $relatedField = IMAGES_FIELD;
    } elseif ($relatedTableReq && $relatedId) {
        $relations = $config['relations'] ?? [];
        foreach ($relations as $rel) {
            if ($rel['table'] === $relatedTableReq) {
                $relatedTable = $relatedTableReq;
                break;
            }
        }

        if (!$relatedTable) {
            $relatedId = null;
        }
    }

    $tagsPgArray = tagsToPgArray($_POST['tags'] ?? '');

    $sql = "
        INSERT INTO " . sys_table('files') . "
            (uuid, name, display_name, type, mime_type, extension, size_bytes, storage_path, uploaded_by, related_table, related_id, tags, related_field)
        VALUES
            ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)
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
        $tagsPgArray,
        $relatedField
    ];
    $res = pg_query_params($conn, $sql, $params);
    if (!$res) {
        error_log('api_files files_action_upload insert failed: ' . pg_last_error($conn));
        unlink($destination);
        jsonError('Database insert failed.', 500);
    }

    $row = pg_fetch_assoc($res);

    jsonSuccess(['file' => $row], 201);
}

function textListToPgArray(array $names): string
{
    return '{' . implode(',', array_map(
        static fn(string $n): string => '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $n) . '"',
        array_map('strval', $names)
    )) . '}';
}

function uuidListToPgArray(mixed $uuids): string
{
    if (!is_array($uuids) || count($uuids) === 0 || count($uuids) > 500) {
        jsonError('uuids must be a non-empty array (max 500).', 400);
    }
    $clean = [];
    foreach ($uuids as $u) {
        if (!is_string($u) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u)) {
            jsonError('Invalid uuid in list.', 400);
        }
        $clean[] = strtolower($u);
    }
    return '{' . implode(',', array_unique($clean)) . '}';
}

function assertFileAccess($conn, string $pgUuids): void
{
    $res = pg_query_params(
        $conn,
        "SELECT DISTINCT related_table, related_id FROM " . sys_table('files')
        . " WHERE uuid = ANY($1) AND deleted_at IS NULL",
        [$pgUuids]
    );
    if (!$res) {
        error_log('api_files assertFileAccess failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $schema = config_get('schema');
    $userId = (int) $_SESSION['user_id'];
    while ($row = pg_fetch_assoc($res)) {
        $rTable = trim((string) ($row['related_table'] ?? ''));
        $rId    = (string) ($row['related_id'] ?? '');
        if ($rTable === '' || $rId === '') {
            continue;
        }
        $tblCfg = $schema['tables'][$rTable] ?? null;
        if (!is_array($tblCfg)) {
            error_log('api_files assertFileAccess: file attached to unconfigured table ' . $rTable);
            jsonError('File not found or already deleted.', 404);
        }
        if (
            !user_can_access_table($rTable)
            || !can_access_record($conn, $tblCfg, $rTable, (int) $rId, $userId)
        ) {
            jsonError('File not found or already deleted.', 404);
        }
    }
}

function tagsToPgArray(string $tagsInput): ?string
{
    $tagsInput = mb_substr(trim($tagsInput), 0, 500);
    if ($tagsInput === '') {
        return null;
    }
    $tagsList = array_slice(
        array_values(array_filter(array_map('trim', explode(',', $tagsInput)), fn($t) => $t !== '')),
        0,
        20
    );
    if (count($tagsList) === 0) {
        return null;
    }
    return '{' . implode(',', array_map(fn($t) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"', $tagsList)) . '}';
}

function files_action_mass_delete($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $pgUuids = uuidListToPgArray($body['uuids'] ?? null);
    assertFileAccess($conn, $pgUuids);
    $sql = "UPDATE " . sys_table('files') . "
            SET deleted_at = NOW()
            WHERE uuid = ANY($1) AND deleted_at IS NULL
            RETURNING id";
    $res = pg_query_params($conn, $sql, [$pgUuids]);
    if (!$res) {
        error_log('api_files files_action_mass_delete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    jsonSuccess(['deleted' => pg_num_rows($res)]);
}

function files_action_mass_tag($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $pgUuids = uuidListToPgArray($body['uuids'] ?? null);
    $pgTags  = tagsToPgArray((string) ($body['tags'] ?? ''));
    if ($pgTags === null) {
        jsonError('tags is required.', 400);
    }
    assertFileAccess($conn, $pgUuids);
    $sql = "UPDATE " . sys_table('files') . "
            SET tags = (SELECT array_agg(DISTINCT t) FROM unnest(COALESCE(tags, '{}') || $2::text[]) AS t),
                updated_at = NOW()
            WHERE uuid = ANY($1) AND deleted_at IS NULL
            RETURNING id";
    $res = pg_query_params($conn, $sql, [$pgUuids, $pgTags]);
    if (!$res) {
        error_log('api_files files_action_mass_tag failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    jsonSuccess(['tagged' => pg_num_rows($res)]);
}

function files_action_update_meta($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $uuid = trim($body['uuid'] ?? '');
    if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        jsonError('Valid uuid is required.', 400);
    }
    assertFileAccess($conn, '{' . strtolower($uuid) . '}');

    $sets   = [];
    $params = [];
    $idx    = 1;

    if (array_key_exists('display_name', $body)) {
        $displayName = mb_substr(trim((string) $body['display_name']), 0, 255);
        if ($displayName === '') {
            jsonError('Display name cannot be empty.', 400);
        }
        $sets[]   = 'display_name = $' . $idx++;
        $params[] = $displayName;
    }

    if (array_key_exists('tags', $body)) {
        $sets[]   = 'tags = $' . $idx++ . '::text[]';
        $params[] = tagsToPgArray((string) $body['tags']);
    }

    if (count($sets) === 0) {
        jsonError('Nothing to update.', 400);
    }

    $sets[]   = 'updated_at = NOW()';
    $params[] = $uuid;
    $sql = "UPDATE " . sys_table('files') . " SET " . implode(', ', $sets)
        . " WHERE uuid = $" . $idx . " AND deleted_at IS NULL RETURNING uuid, name, display_name, tags";
    $res = pg_query_params($conn, $sql, $params);
    if (!$res) {
        error_log('api_files files_action_update_meta failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }
    if (pg_num_rows($res) === 0) {
        jsonError('File not found or already deleted.', 404);
    }
    jsonSuccess(['file' => pg_fetch_assoc($res)]);
}

function files_action_delete($conn, array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $uuid = trim($body['uuid'] ?? '');
    if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        jsonError('Valid uuid is required.', 400);
    }
    assertFileAccess($conn, '{' . strtolower($uuid) . '}');

    $sql = "UPDATE " . sys_table('files') . " SET deleted_at = NOW() WHERE uuid = $1 AND deleted_at IS NULL RETURNING id";
    $res = pg_query_params($conn, $sql, [$uuid]);
    if (!$res) {
        error_log('api_files files_action_delete failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    if (pg_num_rows($res) === 0) {
        jsonError('File not found or already deleted.', 404);
    }

    jsonSuccess(['deleted' => true]);
}

function files_action_save_config(array $body): void
{
    requireWrite();
    os_require_csrf('body', $body);
    $current = loadConfig();
    if (isset($body['max_file_size_mb'])) {
        $current['max_file_size_mb'] = max(1, (int) $body['max_file_size_mb']);
    }

    if (isset($body['storage_path'])) {
        $raw = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $body['storage_path']);

        $raw = preg_replace('/\.{2,}/', '', $raw);

        $raw = preg_replace('/\/+/', '/', $raw);
        $raw = trim($raw, '/');

        if ($raw !== 'storage' && !str_starts_with($raw, 'storage/')) {
            $raw = 'storage/files';
        }
        $current['storage_path'] = $raw . '/';
    }

    if (isset($body['allowed_types']) && is_array($body['allowed_types'])) {
        $valid = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
        $current['allowed_types'] = array_values(array_intersect($body['allowed_types'], $valid));
    }

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

    unset($current['related_table'], $current['display_column_1'], $current['display_column_2']);
    saveConfig($current);
    jsonSuccess(['config' => $current]);
}

function files_action_get_related_records($conn): void
{
    requireLogin();
    $reqTable = trim($_GET['table'] ?? '');
    if (!$reqTable) {
        jsonSuccess(['records' => []]);
    }
    require_table_access($reqTable);

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

    $schemaCfg  = config_get('schema');
    $pgSchema   = (is_array($schemaCfg) ? ($schemaCfg['tables'][$reqTable]['schema'] ?? null) : null) ?? 'public';

    $sqlCols = "SELECT column_name FROM information_schema.columns WHERE table_schema = $1 AND table_name = $2";
    $resCols = pg_query_params($conn, $sqlCols, [$pgSchema, $reqTable]);
    if (!$resCols) {
        error_log('api_files files_action_get_related_records schema check failed: ' . pg_last_error($conn));
        jsonError('Database error.', 500);
    }

    $validCols = [];
    while ($r = pg_fetch_assoc($resCols)) {
        $validCols[] = $r['column_name'];
    }

    if (!$validCols) {
        jsonSuccess(['records' => []]);
    }

    if (!in_array($col1, $validCols, true)) {
        $col1 = 'id';
    }
    if ($col2 && !in_array($col2, $validCols, true)) {
        $col2 = '';
    }

    $quotedTable = '"' . str_replace('"', '""', $reqTable) . '"';
    $quotedCol1  = '"' . str_replace('"', '""', $col1) . '"';
    $sel2        = $col2 ? ', "' . str_replace('"', '""', $col2) . '"' : '';
    $quotedSchema = '"' . str_replace('"', '""', $pgSchema) . '"';
    $sql = "SELECT id, {$quotedCol1} AS val1 {$sel2} FROM {$quotedSchema}.{$quotedTable} ORDER BY id DESC LIMIT 500";
    $res = pg_query($conn, $sql);
    if (!$res) {
        error_log('api_files files_action_get_related_records query failed: ' . pg_last_error($conn));
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

function verifiableExtensionMap(): array
{
    $octet = 'application/octet-stream';
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', $octet],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', $octet],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip', $octet],
        'rtf'  => ['application/rtf', 'text/rtf', 'text/plain'],
        'xls'  => ['application/vnd.ms-excel', $octet],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', $octet],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', $octet],
        'csv'  => ['text/csv', 'text/plain', $octet],
        'zip'  => ['application/zip', $octet],
        'tar'  => ['application/x-tar', $octet],
        'gz'   => ['application/gzip', 'application/x-gzip', $octet],
    ];
}

function mimeMatchesExtension(string $ext, string $mime): bool
{
    $mime = strtolower(trim($mime));
    $map  = verifiableExtensionMap();
    if (!isset($map[$ext])) {
        return false;
    }
    return in_array($mime, $map[$ext], true);
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'File is too large (exceeds the server upload limit).',
        UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded — please retry.',
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR =>
            'Server temporary directory is missing or misconfigured '
            . '(set upload_tmp_dir to a writable path in .user.ini / the hosting panel).',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk (check temp dir permissions).',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        default               => 'Upload failed (PHP error code ' . $code . ').',
    };
}

function detectType(string $ext): string
{
    $map = [

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

function generateUuid(): string
{
    $data    = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
