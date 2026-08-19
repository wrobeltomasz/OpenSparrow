<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\ApiRequest;
use App\Service\AppContext;
use PgSql\Connection;
use finfo;

final class FilesController
{
    private readonly Connection $conn;

    private readonly array $body;

    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context, private readonly ApiRequest $api)
    {
        $this->conn    = $context->connection();
        $this->body    = $api->bodyAll();
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        $request = $this->request;

        if (
            $request->isPost() && empty($request->postAll()) && empty($_FILES)
            && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0
            && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')
        ) {
            jsonError('File is too large. Check php.ini settings.', 413);
        }

        os_api_dispatch($this->api->action, [
            'list'                 => fn() => $this->listFiles(),
            'get_config'           => fn() => $this->getConfig(),
            'upload'               => fn() => $this->upload(),
            'delete'               => fn() => $this->deleteFile(),
            'mass_delete'          => fn() => $this->massDelete(),
            'mass_tag'             => fn() => $this->massTag(),
            'update_meta'          => fn() => $this->updateMeta(),
            'save_config'          => fn() => $this->saveConfigAction(),
            'get_relations_config' => fn() => $this->getRelationsConfig(),
            'get_related_records'  => fn() => $this->getRelatedRecords(),
        ], 'api_files', 'Unknown action or empty request payload.');
    }

    private function validateImageTarget(string $table, int $recordId): array
    {
        if ($table === '' || $recordId <= 0) {
            jsonError('related_table and related_id are required for gallery uploads.', 400);
        }

        $schema = config_get('schema');
        $config    = is_array($schema) ? images_config($schema, $table) : null;
        if ($config === null) {
            jsonError('This table does not accept images.', 403);
        }

        $tableConfig = $schema['tables'][$table];
        if (!can_access_record($this->conn, $tableConfig, $table, $recordId, $this->session->userId())) {
            jsonError('Forbidden', 403);
        }

        if (images_count($this->conn, $table, $recordId) >= $config['max_per_record']) {
            jsonError('Image limit reached for this record.', 409);
        }

        return ['table' => $table, 'id' => $recordId];
    }

    private function loadConfig(): array
    {
        $decoded = config_get('files');
        if (!is_array($decoded)) {
            jsonError('Files configuration not found', 500);
        }
        return $decoded;
    }

    private function saveConfig(array $config): void
    {
        $userId = $this->session->has('user_id') ? $this->session->userId() : null;
        $result = config_save('files', $config, null, $userId);
        if ($result['status'] !== 'ok') {
            jsonError($result['error'] ?? 'Could not save files configuration.', 500);
        }
    }

    private function listFiles(): void
    {
        requireLogin();
        $page   = max(1, (int) $this->request->query('page', '1'));
        $limit  = min(FILES_PAGE_LIMIT_MAX, max(1, (int) $this->request->query('limit', (string) FILES_PAGE_LIMIT)));
        $offset = ($page - 1) * $limit;
        $type   = $this->request->query('type', 'all');
        $search = trim($this->request->query('search'));

        $sortMap = [
            'type'       => 'f.type',
            'name'       => 'LOWER(f.name)',
            'display'    => "LOWER(COALESCE(NULLIF(f.display_name, ''), f.name))",
            'tags'       => "array_to_string(f.tags, ' ')",
            'size'       => 'f.size_bytes',
            'related'    => 'f.related_table',
            'created_at' => 'f.created_at',
        ];
        $orderExpr = $sortMap[$this->request->query('sort', 'created_at')] ?? 'f.created_at';
        $orderDirection  = strtolower($this->request->query('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $where  = ['f.deleted_at IS NULL'];
        $parameters = [];
        if ($type !== 'all') {
            $where[]  = 'f.type = $' . (count($parameters) + 1);
            $parameters[] = $type;
        }

        if ($search !== '') {
            $parameterIndex = count($parameters) + 1;
            $where[]  = '(f.name ILIKE $' . $parameterIndex . ' OR f.display_name ILIKE $' . $parameterIndex
                . ' OR array_to_string(f.tags, \' \') ILIKE $' . $parameterIndex . ')';
            $parameters[] = '%' . $search . '%';
        }

        $allowedTables = user_allowed_tables();
        if ($allowedTables !== null) {
            $where[]  = "(f.related_table IS NULL OR f.related_table = '' OR f.related_table = ANY($"
                . (count($parameters) + 1) . '::text[]))';
            $parameters[] = $this->textListToPgArray($allowedTables);
        }

        $ownerRestricted = [];
        if ($this->session->get('role', '') !== 'admin') {
            foreach ((config_get('schema') ?? [])['tables'] ?? [] as $tableName => $tableConfig) {
                if (is_array($tableConfig) && !empty($tableConfig['owner_restricted'])) {
                    $ownerRestricted[] = $tableName;
                }
            }
        }
        if ($ownerRestricted !== []) {
            $recordOwnersTable  = sys_table('record_owners');
            $ownerParameterIndex = count($parameters) + 1;
            $tableParameterIndex   = count($parameters) + 2;
            $where[]  = "NOT EXISTS (SELECT 1 FROM {$recordOwnersTable} ro"
                . ' WHERE ro.table_name = f.related_table AND ro.record_id = f.related_id'
                . ' AND ro.is_current = true'
                . " AND ro.owner_id IS NOT NULL AND ro.owner_id != \${$ownerParameterIndex}"
                . " AND f.related_table = ANY(\${$tableParameterIndex}::text[]))";
            $parameters[] = $this->session->userId();
            $parameters[] = $this->textListToPgArray($ownerRestricted);
        }

        $whereSQL = implode(' AND ', $where);
        $countSQL = "SELECT COUNT(*) AS cnt FROM " . sys_table('files') . " f WHERE {$whereSQL}";
        $countResult = pg_query_params($this->conn, $countSQL, $parameters);
        if (!$countResult) {
            error_log('api_files files_action_list count failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        $total = (int) pg_fetch_result($countResult, 0, 'cnt');
        $parametersList = $parameters;
        $listSQL    = "
        SELECT
            f.uuid, f.name, f.display_name, f.type, f.mime_type,
            f.size_bytes, f.created_at, f.related_table, f.related_id, f.tags,
            u.username AS uploaded_by_username
        FROM " . sys_table('files') . " f
        LEFT JOIN " . sys_table('users') . " u ON u.id = f.uploaded_by
        WHERE {$whereSQL}
        ORDER BY {$orderExpr} {$orderDirection}, f.id DESC
        LIMIT $" . (count($parametersList) + 1) . "
        OFFSET $" . (count($parametersList) + 2);
        $parametersList[] = $limit;
        $parametersList[] = $offset;
        $listResult = pg_query_params($this->conn, $listSQL, $parametersList);
        if (!$listResult) {
            error_log('api_files files_action_list list failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $files = [];
        while ($row = pg_fetch_assoc($listResult)) {
            $files[] = $row;
        }

        jsonSuccess([
            'files'       => $files,
            'total_count' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'page'        => $page,
        ]);
    }

    private function getConfig(): void
    {
        requireWrite();
        jsonSuccess(['config' => $this->loadConfig()]);
    }

    private function getRelationsConfig(): void
    {
        requireLogin();
        $config    = $this->loadConfig();
        $relations = $config['relations'] ?? [];
        jsonSuccess(['relations' => $relations]);
    }

    private function upload(): void
    {
        requireWrite();
        os_require_csrf('body');
        if (!isset($_FILES['file'])) {
            jsonError('No file received.', 400);
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonError($this->uploadErrorMessage((int) $file['error']), 400);
        }

        $config = $this->loadConfig();
        $maxBytes = ($config['max_file_size_mb'] ?? FILES_MAX_SIZE_MB) * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            jsonError('File exceeds maximum size.', 413);
        }

        $originalName = $file['name'];
        $extension          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowedExtensions = array_intersect(
            $config['allowed_extensions'] ?? [],
            array_keys($this->verifiableExtensionMap())
        );
        if (!in_array($extension, $allowedExtensions, true)) {
            jsonError('Extension is not allowed.', 415);
        }

        $type = $this->detectType($extension);
        if (!in_array($type, $config['allowed_types'] ?? [], true)) {
            jsonError('File type category is not allowed.', 415);
        }

        $request = os_request();

        $imageMode   = $request->post('related_field') === IMAGES_FIELD;
        $imageTarget = null;

        $requestedRelatedTable = trim((string) $request->post('related_table'));
        if ($requestedRelatedTable !== '') {
            require_table_access($requestedRelatedTable);
        }
        if ($imageMode) {
            if ($type !== 'image') {
                jsonError('Only image files can be added to a record gallery.', 415);
            }
            $imageTarget = $this->validateImageTarget(
                trim((string) $request->post('related_table')),
                (int) $request->post('related_id', 0)
            );
        }

        $mimeType = 'application/octet-stream';
        if (class_exists('finfo')) {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            if (!$this->mimeMatchesExtension($extension, $mimeType)) {
                unlink($file['tmp_name']);
                jsonError('File content does not match its extension.', 415);
            }
        }

        $uuid        = $this->generateUuid();
        $filename    = $uuid . '.' . $extension;
        $directory         = rtrim(os_storage_path($config['storage_path'] ?? 'storage/files'), '/');
        os_ensure_directory($directory, 0750);
        os_write_guard_file($directory . '/.htaccess', "Require all denied\n");

        $destination = $directory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            jsonError('Failed to save physical file to disk.', 500);
        }

        $displayName = trim((string) $request->post('display_name')) ?: $originalName;
        $dbPath      = trim($config['storage_path'] ?? 'storage/files', '/') . '/' . $filename;

        $requestedRelatedTable = trim((string) $request->post('related_table'));
        $relatedIdRaw    = $request->post('related_id');
        $relatedId       = $relatedIdRaw !== '' ? (int) $relatedIdRaw : null;
        $relatedTable    = null;
        $relatedField    = null;
        if ($imageMode) {
            $relatedTable = $imageTarget['table'];
            $relatedId    = $imageTarget['id'];
            $relatedField = IMAGES_FIELD;
        } elseif ($requestedRelatedTable && $relatedId) {
            $relations = $config['relations'] ?? [];
            foreach ($relations as $relativePath) {
                if ($relativePath['table'] === $requestedRelatedTable) {
                    $relatedTable = $requestedRelatedTable;
                    break;
                }
            }

            if (!$relatedTable) {
                $relatedId = null;
            }
        }

        $tagsPgArray = $this->tagsToPgArray((string) $request->post('tags'));

        $sql = "
        INSERT INTO " . sys_table('files') . "
            (uuid, name, display_name, type, mime_type, extension, size_bytes, storage_path,
             uploaded_by, related_table, related_id, tags, related_field)
        VALUES
            ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)
        RETURNING id, uuid
    ";
        $parameters = [
            $uuid,
            $originalName,
            $displayName,
            $type,
            $mimeType,
            $extension,
            $file['size'],
            $dbPath,
            $this->session->get('user_id'),
            $relatedTable,
            $relatedId,
            $tagsPgArray,
            $relatedField
        ];
        $queryResult = pg_query_params($this->conn, $sql, $parameters);
        if (!$queryResult) {
            error_log('api_files files_action_upload insert failed: ' . pg_last_error($this->conn));
            unlink($destination);
            jsonError('Database insert failed.', 500);
        }

        $row = pg_fetch_assoc($queryResult);

        jsonSuccess(['file' => $row], 201);
    }

    private function textListToPgArray(array $names): string
    {
        return '{' . implode(',', array_map(
            static fn(string $name): string => '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $name) . '"',
            array_map('strval', $names)
        )) . '}';
    }

    private function uuidListToPgArray(mixed $uuids): string
    {
        if (!is_array($uuids) || count($uuids) === 0 || count($uuids) > 500) {
            jsonError('uuids must be a non-empty array (max 500).', 400);
        }
        $clean = [];
        foreach ($uuids as $candidateUuid) {
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
            if (!is_string($candidateUuid) || !preg_match($uuidPattern, $candidateUuid)) {
                jsonError('Invalid uuid in list.', 400);
            }
            $clean[] = strtolower($candidateUuid);
        }
        return '{' . implode(',', array_unique($clean)) . '}';
    }

    private function assertFileAccess(string $pgUuids): void
    {
        $queryResult = pg_query_params(
            $this->conn,
            "SELECT DISTINCT related_table, related_id FROM " . sys_table('files')
            . " WHERE uuid = ANY($1) AND deleted_at IS NULL",
            [$pgUuids]
        );
        if (!$queryResult) {
            error_log('api_files assertFileAccess failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        $schema = config_get('schema');
        $userId = $this->session->userId();
        while ($row = pg_fetch_assoc($queryResult)) {
            $relatedTable = trim((string) ($row['related_table'] ?? ''));
            $rowRelatedId    = (string) ($row['related_id'] ?? '');
            if ($relatedTable === '' || $rowRelatedId === '') {
                continue;
            }
            $relatedTableConfig = $schema['tables'][$relatedTable] ?? null;
            if (!is_array($relatedTableConfig)) {
                error_log('api_files assertFileAccess: file attached to unconfigured table ' . $relatedTable);
                jsonError('File not found or already deleted.', 404);
            }
            if (
                !user_can_access_table($relatedTable)
                || !can_access_record(
                    $this->conn,
                    $relatedTableConfig,
                    $relatedTable,
                    (int) $rowRelatedId,
                    $userId
                )
            ) {
                jsonError('File not found or already deleted.', 404);
            }
        }
    }

    private function tagsToPgArray(string $tagsInput): ?string
    {
        $tagsInput = mb_substr(trim($tagsInput), 0, 500);
        if ($tagsInput === '') {
            return null;
        }
        $tagsList = array_slice(
            array_values(array_filter(array_map('trim', explode(',', $tagsInput)), fn($tag) => $tag !== '')),
            0,
            20
        );
        if (count($tagsList) === 0) {
            return null;
        }
        return '{' . implode(',', array_map(
            fn($tag) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $tag) . '"',
            $tagsList
        )) . '}';
    }

    private function massDelete(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);
        $pgUuids = $this->uuidListToPgArray($body['uuids'] ?? null);
        $this->assertFileAccess($pgUuids);
        $sql = "UPDATE " . sys_table('files') . "
            SET deleted_at = NOW()
            WHERE uuid = ANY($1) AND deleted_at IS NULL
            RETURNING id";
        $queryResult = pg_query_params($this->conn, $sql, [$pgUuids]);
        if (!$queryResult) {
            error_log('api_files files_action_mass_delete failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        jsonSuccess(['deleted' => pg_num_rows($queryResult)]);
    }

    private function massTag(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);
        $pgUuids = $this->uuidListToPgArray($body['uuids'] ?? null);
        $pgTags  = $this->tagsToPgArray((string) ($body['tags'] ?? ''));
        if ($pgTags === null) {
            jsonError('tags is required.', 400);
        }
        $this->assertFileAccess($pgUuids);
        $sql = "UPDATE " . sys_table('files') . "
            SET tags = (SELECT array_agg(DISTINCT t) FROM unnest(COALESCE(tags, '{}') || $2::text[]) AS t),
                updated_at = NOW()
            WHERE uuid = ANY($1) AND deleted_at IS NULL
            RETURNING id";
        $queryResult = pg_query_params($this->conn, $sql, [$pgUuids, $pgTags]);
        if (!$queryResult) {
            error_log('api_files files_action_mass_tag failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        jsonSuccess(['tagged' => pg_num_rows($queryResult)]);
    }

    private function updateMeta(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);
        $uuid = trim($body['uuid'] ?? '');
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            jsonError('Valid uuid is required.', 400);
        }
        $this->assertFileAccess('{' . strtolower($uuid) . '}');

        $sets   = [];
        $parameters = [];
        $index    = 1;

        if (array_key_exists('display_name', $body)) {
            $displayName = mb_substr(trim((string) $body['display_name']), 0, 255);
            if ($displayName === '') {
                jsonError('Display name cannot be empty.', 400);
            }
            $sets[]   = 'display_name = $' . $index++;
            $parameters[] = $displayName;
        }

        if (array_key_exists('tags', $body)) {
            $sets[]   = 'tags = $' . $index++ . '::text[]';
            $parameters[] = $this->tagsToPgArray((string) $body['tags']);
        }

        if (count($sets) === 0) {
            jsonError('Nothing to update.', 400);
        }

        $sets[]   = 'updated_at = NOW()';
        $parameters[] = $uuid;
        $sql = "UPDATE " . sys_table('files') . " SET " . implode(', ', $sets)
            . " WHERE uuid = $" . $index . " AND deleted_at IS NULL RETURNING uuid, name, display_name, tags";
        $queryResult = pg_query_params($this->conn, $sql, $parameters);
        if (!$queryResult) {
            error_log('api_files files_action_update_meta failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }
        if (pg_num_rows($queryResult) === 0) {
            jsonError('File not found or already deleted.', 404);
        }
        jsonSuccess(['file' => pg_fetch_assoc($queryResult)]);
    }

    private function deleteFile(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);
        $uuid = trim($body['uuid'] ?? '');
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            jsonError('Valid uuid is required.', 400);
        }
        $this->assertFileAccess('{' . strtolower($uuid) . '}');

        $sql = "UPDATE " . sys_table('files')
            . " SET deleted_at = NOW() WHERE uuid = $1 AND deleted_at IS NULL RETURNING id";
        $queryResult = pg_query_params($this->conn, $sql, [$uuid]);
        if (!$queryResult) {
            error_log('api_files files_action_delete failed: ' . pg_last_error($this->conn));
            jsonError('Database error.', 500);
        }

        if (pg_num_rows($queryResult) === 0) {
            jsonError('File not found or already deleted.', 404);
        }

        jsonSuccess(['deleted' => true]);
    }

    private function saveConfigAction(): void
    {
        $body = $this->body;

        requireWrite();
        os_require_csrf('body', $body);
        $current = $this->loadConfig();
        if (isset($body['max_file_size_mb'])) {
            $current['max_file_size_mb'] = max(1, (int) $body['max_file_size_mb']);
        }

        if (isset($body['storage_path'])) {
            $rawPath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $body['storage_path']);

            $rawPath = preg_replace('/\.{2,}/', '', $rawPath);

            $rawPath = preg_replace('/\/+/', '/', $rawPath);
            $rawPath = trim($rawPath, '/');

            if ($rawPath !== 'storage' && !str_starts_with($rawPath, 'storage/')) {
                $rawPath = 'storage/files';
            }
            $current['storage_path'] = $rawPath . '/';
        }

        if (isset($body['allowed_types']) && is_array($body['allowed_types'])) {
            $valid = ['image', 'pdf', 'doc', 'spreadsheet', 'archive', 'other'];
            $current['allowed_types'] = array_values(array_intersect($body['allowed_types'], $valid));
        }

        if (isset($body['relations']) && is_array($body['relations'])) {
            $current['relations'] = [];
            foreach ($body['relations'] as $relativePath) {
                if (!empty($relativePath['table'])) {
                    $current['relations'][] = [
                        'table' => trim((string)$relativePath['table']),
                        'col1'  => trim((string)($relativePath['col1'] ?? 'id')),
                        'col2'  => trim((string)($relativePath['col2'] ?? ''))
                    ];
                }
            }
        }

        unset($current['related_table'], $current['display_column_1'], $current['display_column_2']);
        $this->saveConfig($current);
        jsonSuccess(['config' => $current]);
    }

    private function getRelatedRecords(): void
    {
        requireLogin();
        $requestedTable = trim($this->request->query('table'));
        if (!$requestedTable) {
            jsonSuccess(['records' => []]);
        }
        require_table_access($requestedTable);

        $config    = $this->loadConfig();
        $relatedConfig = null;
        $relations = $config['relations'] ?? [];
        foreach ($relations as $relativePath) {
            if ($relativePath['table'] === $requestedTable) {
                $relatedConfig = $relativePath;
                break;
            }
        }

        if (!$relatedConfig || !preg_match('/^[a-zA-Z0-9_]+$/', $requestedTable)) {
            jsonSuccess(['records' => []]);
        }

        $col1 = $relatedConfig['col1'] ?: 'id';
        $col2 = $relatedConfig['col2'] ?: '';

        $schemaConfig  = config_get('schema');
        $pgSchema   = (is_array($schemaConfig)
            ? ($schemaConfig['tables'][$requestedTable]['schema'] ?? null)
            : null) ?? 'public';

        $columnsSql = "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = $1 AND table_name = $2";
        $columnsResult = pg_query_params($this->conn, $columnsSql, [$pgSchema, $requestedTable]);
        if (!$columnsResult) {
            error_log(
                'api_files files_action_get_related_records schema check failed: '
                . pg_last_error($this->conn)
            );
            jsonError('Database error.', 500);
        }

        $validColumns = [];
        while ($row = pg_fetch_assoc($columnsResult)) {
            $validColumns[] = $row['column_name'];
        }

        if (!$validColumns) {
            jsonSuccess(['records' => []]);
        }

        if (!in_array($col1, $validColumns, true)) {
            $col1 = 'id';
        }
        if ($col2 && !in_array($col2, $validColumns, true)) {
            $col2 = '';
        }

        $quotedTable = '"' . str_replace('"', '""', $requestedTable) . '"';
        $quotedCol1  = '"' . str_replace('"', '""', $col1) . '"';
        $sel2        = $col2 ? ', "' . str_replace('"', '""', $col2) . '"' : '';
        $quotedSchema = '"' . str_replace('"', '""', $pgSchema) . '"';
        $sql = "SELECT id, {$quotedCol1} AS val1 {$sel2} FROM {$quotedSchema}.{$quotedTable} "
            . "ORDER BY id DESC LIMIT 500";
        $queryResult = pg_query($this->conn, $sql);
        if (!$queryResult) {
            error_log(
                'api_files files_action_get_related_records query failed: '
                . pg_last_error($this->conn)
            );
            jsonError('Database error.', 500);
        }

        $records = [];
        while ($row = pg_fetch_assoc($queryResult)) {
            $label = $row['val1'];
            if ($col2 && isset($row[$col2])) {
                $label .= ' - ' . $row[$col2];
            }
            $label     = $label ? mb_substr((string)$label, 0, 100) . " (ID: {$row['id']})" : "ID: {$row['id']}";
            $records[] = ['id' => $row['id'], 'label' => $label];
        }

        jsonSuccess(['records' => $records]);
    }

    private function verifiableExtensionMap(): array
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
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                $octet,
            ],
            'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip', $octet],
            'rtf'  => ['application/rtf', 'text/rtf', 'text/plain'],
            'xls'  => ['application/vnd.ms-excel', $octet],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                $octet,
            ],
            'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', $octet],
            'csv'  => ['text/csv', 'text/plain', $octet],
            'zip'  => ['application/zip', $octet],
            'tar'  => ['application/x-tar', $octet],
            'gz'   => ['application/gzip', 'application/x-gzip', $octet],
        ];
    }

    private function mimeMatchesExtension(string $extension, string $mime): bool
    {
        $mime = strtolower(trim($mime));
        $map  = $this->verifiableExtensionMap();
        if (!isset($map[$extension])) {
            return false;
        }
        return in_array($mime, $map[$extension], true);
    }

    private function uploadErrorMessage(int $code): string
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

    private function detectType(string $extension): string
    {
        $map = [

            'image'       => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'pdf'         => ['pdf'],
            'doc'         => ['doc', 'docx', 'odt', 'rtf'],
            'spreadsheet' => ['xls', 'xlsx', 'ods', 'csv'],
            'archive'     => ['zip', 'tar', 'gz'],
        ];
        foreach ($map as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }
        return 'other';
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
