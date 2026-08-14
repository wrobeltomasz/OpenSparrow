<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// api/files.php — Files module API (upload, list, soft-delete, config)
// Auth gate: session + UA enforcement; CSRF where applicable; JSON via jsonError()/jsonSuccess()
// match() action routing: list, get_config, upload, delete, mass_delete, mass_tag, update_meta,
// save_config, get_relations_config, get_related_records
// Multipart upload with post_max_size-drop detection (-> 413); soft-delete (deleted_at); pagination
// Parameterized queries; sys_table('files')

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// csrf=manual: mutating actions validate the FormData/body token via os_require_csrf() themselves
$conn = os_api_bootstrap(['csrf' => 'manual']);

// jsonError(), jsonSuccess(), requireLogin() and requireWrite() are shared via includes/api_helpers.php

// Files-module config via the spw_config store (key "files").
require_once __DIR__ . '/../../includes/config_store.php';
// Record image galleries: IMAGES_FIELD discriminator + images_config()/images_count()
require_once __DIR__ . '/../../includes/images.php';

/**
 * Validate a gallery upload target: the table must have images enabled in the schema
 * config, the record must be accessible to the current user, and the per-record limit
 * must not be reached. Exits with a JSON error otherwise.
 *
 * @return array{table:string,id:int}
 */
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

// Catch server-level post_max_size drops. Runs before os_api_action(): when PHP
// discards an oversized multipart body, $_POST is empty and no action name survives
// to reach the dispatcher, so the upload would otherwise report a bare 400.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')
) {
    jsonError('File is too large. Check php.ini settings.', 413);
}

// os_api_action() reads the action from the JSON body or from $_POST depending on
// the content type — the upload actions post multipart FormData, not JSON.
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

// Handle list action
function files_action_list($conn): void
{

    requireLogin();
    $page   = max(1, (int) ($_GET['page']   ?? 1));
    $limit  = min(FILES_PAGE_LIMIT_MAX, max(1, (int) ($_GET['limit'] ?? FILES_PAGE_LIMIT)));
    $offset = ($page - 1) * $limit;
    $type   = $_GET['type']   ?? 'all';
    $search = trim($_GET['search'] ?? '');
    // Column sort (grid-parity) — identifiers come from a hardcoded whitelist, never from input
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
// Convert array to string for easy partial text matching
        $paramIdx = count($params) + 1;
        $where[]  = '(f.name ILIKE $' . $paramIdx . ' OR f.display_name ILIKE $' . $paramIdx . ' OR array_to_string(f.tags, \' \') ILIKE $' . $paramIdx . ')';
        $params[] = '%' . $search . '%';
    }

    // Per-user table scope: files attached to a table the user may not reach are
    // dropped from both the count and the page. Unattached files (related_table
    // NULL or empty) belong to no table and stay visible to every logged-in user.
    $allowedTables = user_allowed_tables();
    if ($allowedTables !== null) {
        $where[]  = "(f.related_table IS NULL OR f.related_table = '' OR f.related_table = ANY($"
            . (count($params) + 1) . '::text[]))';
        $params[] = textListToPgArray($allowedTables);
    }

    // Row-level ownership — the same rule assertFileAccess() applies to the write paths
    // and file_download.php to the download. A file inherits the visibility of the
    // record it hangs off, and until now that held everywhere EXCEPT here: the listing
    // handed out the name, display name, tags, uploader and related_id of attachments on
    // rows the caller does not own. Metadata only, since the bytes were already gated —
    // but it is what the ownership policy exists to withhold, and the write gate refuses
    // to touch those very files, so the listing was the one surface disagreeing.
    //
    // Scoped to the tables actually marked owner_restricted rather than applied to every
    // related_table: a table with no ownership policy has no owner to compare against,
    // and a blanket predicate would start hiding its files the moment somebody assigned
    // one. Read as ARRAY KEYS, so all-digit table names arrive as ints — textListToPgArray()
    // is what casts them back.
    //
    // owner_restriction_sql() does not fit: it binds table_name to a PARAMETER, because
    // every other caller filters one table's rows, whereas a single page of this listing
    // spans many. The predicate is correlated on f.related_table instead. Same policy
    // either way — unowned rows pass, rows owned by somebody else drop out. The explicit
    // IS NOT NULL is what that helper leaves to NULL-comparison semantics; spelled out
    // here because this one is read next to a correlated join. Unattached files have a
    // NULL related_id, match no owner row, and stay visible exactly as before.
    //
    // Admins are exempt, as can_access_record() exempts them and as user_allowed_tables()
    // already no-ops for them above: the admin Files module lists the whole library
    // through this same action.
    //
    // In SQL rather than after the fetch, so COUNT(*) and the LIMIT/OFFSET pagination
    // agree with what is actually visible — the same reason api.php's grid filters there.
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

// Handle get config action
function files_action_get_config(): void
{

    requireWrite();
    jsonSuccess(['config' => loadConfig()]);
}

// Provide relation definitions for frontend upload form
function files_action_get_relations_config(): void
{

    requireLogin();
    $config    = loadConfig();
    $relations = $config['relations'] ?? [];
    jsonSuccess(['relations' => $relations]);
}

// Handle file upload action
function files_action_upload($conn): void
{

    // Uploading is a write operation — restrict to write roles, matching
    // delete/save_config. Viewers are read-only; the UI hides the upload control
    // for them but the server must enforce it too.
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
    // Never honour a configured extension the server cannot content-verify. The
    // admin panel saves the Files config verbatim, so allowed_extensions is not a
    // trusted input on its own — intersecting it with verifiableExtensionMap()
    // keeps svg/php/html out no matter what is stored. This also holds when finfo
    // is missing, where the mimeMatchesExtension() sniff below never runs at all.
    $allowedExts = array_intersect($config['allowed_extensions'] ?? [], array_keys(verifiableExtensionMap()));
    if (!in_array($ext, $allowedExts, true)) {
        jsonError('Extension is not allowed.', 415);
    }

    $type = detectType($ext);
    if (!in_array($type, $config['allowed_types'] ?? [], true)) {
        jsonError('File type category is not allowed.', 415);
    }

    // Gallery mode: the upload targets a record's image gallery instead of the plain
    // attachment list. Validated against the schema config, not the Files relations.
    $imageMode   = ($_POST['related_field'] ?? '') === IMAGES_FIELD;
    $imageTarget = null;
    // related_table is request-supplied on both paths below (gallery and plain
    // attachment), so it is gated here once — attaching a file to a record in a
    // table the user has no access to must not be possible.
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

    // Read the REAL content type and reject any file whose bytes do not match the
    // extension the client claimed. Extension/category checks above are trivially
    // spoofable (rename virus.html -> photo.jpg); this closes both the spoofing gap
    // and a stored-content vector where a text/html payload named .jpg would later be
    // served inline by file_download.php. Only enforced when finfo is available.
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
    $relatedField    = null;
    if ($imageMode) {
        // Already validated (table has a gallery, record accessible, limit not reached)
        $relatedTable = $imageTarget['table'];
        $relatedId    = $imageTarget['id'];
        $relatedField = IMAGES_FIELD;
    } elseif ($relatedTableReq && $relatedId) {
// Validate that the requested table exists in config
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

    // Extract and format tags as PostgreSQL array — shared with mass_tag
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
// Return 201 Created on successful upload
    jsonSuccess(['file' => $row], 201);
}

// A PostgreSQL text[] literal from a list of names, for the two name-list predicates
// in files_action_list(). Both bind their list as a single parameter, so the literal has to be
// assembled here rather than expanded into placeholders.
//
// Quoting every element and escaping backslashes and double quotes is what stops a name
// containing either from closing the literal early. These names are configuration-
// supplied, never client-supplied, but a literal built by hand has to be correct on its
// own terms and not because of where its input happened to come from.
//
// strval on the way in, because a caller may hand over schema table names read as ARRAY
// KEYS, and PHP has already cast an all-digit one to an int by then — under
// declare(strict_types=1) that would be a TypeError against the string-typed closure.
//
// @param list<string|int> $names
function textListToPgArray(array $names): string
{
    return '{' . implode(',', array_map(
        static fn(string $n): string => '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $n) . '"',
        array_map('strval', $names)
    )) . '}';
}

// Validate a client-supplied UUID list and normalize it into a PG array literal.
// Every element must match the canonical UUID format — this both rejects garbage
// and guarantees the assembled literal needs no further escaping.
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

// A file inherits the visibility of the record it hangs off: a user who cannot reach
// that row — because the table is outside their access scope, or because the row
// belongs to someone else in an owner-restricted table — must not be able to touch its
// files either (mirrors file_download.php).
//
// This used to look at gallery rows only (`related_field = IMAGES_FIELD`), which left
// every plain attachment unchecked on delete, mass-delete, mass-tag and metadata edit
// — the read paths were gated while the write paths were not. It now covers every
// attachment, and consults the per-user table scope as well as record ownership.
//
// Unattached files (no related_table, or no related_id) belong to no record and stay
// editable by any logged-in user — the same rule files_action_list() applies when listing
// them. A file pointing at a table that is no longer in the schema config fails closed
// instead: there is nothing left to check ownership against, and a stuck orphan is a
// better outcome than an unguarded one. The error_log line is there to make that case
// diagnosable rather than mysterious.
//
// Fails closed with the same 404 the single-file paths use, so an inaccessible uuid is
// indistinguishable from a missing one. $pgUuids is a PG uuid[] literal.
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

// Normalize a comma-separated tag string into a PG text[] literal — capped to
// prevent oversized payloads; empty entries dropped; quotes/backslashes escaped.
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

// Handle bulk soft delete over a selection of files
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

// Handle bulk tagging — appends the given tags to each selected file (deduplicated)
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

// Handle single-file inline metadata edit (grid-parity: editable display name + tags).
// The physical file name (f.name) is immutable and is never modified here — only the
// display_name label and the tag list are editable, matching the frontend affordances.
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

    // Rename the display label only — the underlying file name stays fixed.
    if (array_key_exists('display_name', $body)) {
        $displayName = mb_substr(trim((string) $body['display_name']), 0, 255);
        if ($displayName === '') {
            jsonError('Display name cannot be empty.', 400);
        }
        $sets[]   = 'display_name = $' . $idx++;
        $params[] = $displayName;
    }

    // Replace the whole tag list (tagsToPgArray returns null for an empty string → clears tags).
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

// Handle soft delete action
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

// Handle config save action (supports multiple relations)
function files_action_save_config(array $body): void
{

    requireWrite();
    os_require_csrf('body', $body);
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

    // Resolve the table's actual PostgreSQL schema from the schema config (tables can live
    // outside the default app schema, e.g. a demo app's own schema) — mirrors the
    // $tableCfg['schema'] ?? 'public' pattern used across api.php/mass_edit.php/etc.
    $schemaCfg  = config_get('schema');
    $pgSchema   = (is_array($schemaCfg) ? ($schemaCfg['tables'][$reqTable]['schema'] ?? null) : null) ?? 'public';

// Validate columns directly from database schema
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

    // Table name and column names are validated against information_schema and a strict regex above.
    // pg_query_params does not support identifiers as parameters, so verified values are interpolated
    // with double-quote escaping as the standard PostgreSQL safe identifier quoting mechanism.
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

// Verify that the finfo-detected MIME type is consistent with the claimed extension.
// Each extension maps to the set of MIME types finfo legitimately reports for it
// (finfo is conservative and sometimes generic, e.g. application/octet-stream for
// modern Office/archive formats), so the allowlist is intentionally permissive on the
// generic side but still blocks the dangerous mismatches (text/html, scripts, executables
// masquerading as images). An unknown extension has no entry and is rejected.
/**
 * Extensions whose bytes the server can actually verify, mapped to the MIME types
 * finfo may report for them.
 *
 * This doubles as the ceiling on allowed_extensions. The Files config is written
 * verbatim by the admin panel — includes/admin/config_files.php stores whatever
 * JSON it is posted, with no per-field validation — so the stored allowlist alone
 * is not a trustworthy input. An extension absent from this map (svg, php, html…)
 * can never be content-checked, so it must never be accepted, whatever the config
 * says. See files_action_upload(), which intersects the two.
 */
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

// Translate a PHP $_FILES error code into an actionable message. The raw numeric
// code (esp. 6 = UPLOAD_ERR_NO_TMP_DIR, common on locked-down shared hosts where
// /tmp is blocked) is meaningless to an admin; these point at the actual cause.
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
