<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\BadRequestException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\AppContext;
use PgSql\Connection;
use RuntimeException;

final class MassEditController
{
    private readonly Connection $conn;

    private readonly array $schema;

    private readonly PhpRequest $request;

    private readonly SessionInterface $session;

    public function __construct(AppContext $context)
    {
        $this->conn    = $context->connection();
        $this->schema  = config_get('schema') ?? ['tables' => []];
        $this->request = $context->request();
        $this->session = $context->session();
    }

    public function handle(): void
    {
        $method = $this->request->method();
        $action = $this->request->query('action');

        if ($action === 'mass_edit_preview' && $method === 'POST') {
            $this->preview();
        }

        if ($action === 'mass_edit_apply' && $method === 'POST') {
            $this->apply();
        }

        if ($action === 'mass_duplicate' && $method === 'POST') {
            $this->duplicate();
        }

        if ($action === 'mass_delete' && $method === 'POST') {
            $this->delete();
        }

        throw new BadRequestException('Unknown action');
    }

    private function validateTableColumn(array $body): array
    {
        $tableName = $body['table']  ?? '';
        $colName   = $body['column'] ?? '';

        try {
            $tableCfg = safe_table($this->schema, $tableName);
        } catch (RuntimeException $exception) {
            throw new BadRequestException('Unknown table');
        }

        require_table_access($tableName);

        $columns = $tableCfg['columns'] ?? [];

        if ($colName === 'id') {
            throw new BadRequestException('Cannot edit id column');
        }

        if (!isset($columns[$colName])) {
            throw new BadRequestException('Invalid column');
        }

        if (($columns[$colName]['type'] ?? '') === 'virtual') {
            throw new BadRequestException('Cannot edit virtual columns');
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
        $colSql     = pg_ident($colName);

        return [$tableCfg, $tableName, $columns[$colName], $colSql, $qualifiedTable];
    }

    private function validatedTable(array $body): array
    {
        $tableName = $body['table'] ?? '';

        try {
            $tableCfg = safe_table($this->schema, $tableName);
        } catch (RuntimeException $exception) {
            throw new BadRequestException('Unknown table');
        }

        require_table_access($tableName);

        return [$tableCfg, $tableName];
    }

    private function sanitizeRowIds(mixed $rawIds): array
    {
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        foreach ($rawIds as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT);
            if ($validatedId !== false && $validatedId > 0) {
                $ids[] = $validatedId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function pgIntArray(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }

    private function selectedRows(): array
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $rowIds = $this->sanitizeRowIds($body['row_ids'] ?? []);

        if (empty($rowIds)) {
            throw new BadRequestException('No rows selected');
        }

        return [$body, $rowIds];
    }

    private function preview(): void
    {
        [$body, $rowIds] = $this->selectedRows();

        [$tableCfg, $tableName, , $colSql, $qualifiedTable] = $this->validateTableColumn($body);

        $rowIdsArray = $this->pgIntArray($rowIds);

        if (!empty($tableCfg['owner_restricted'])) {
            $userId      = $this->session->userId();
            $ownerSql = owner_restriction_sql('_t.id', 2, 3);

            $countResult = @pg_query_params(
                $this->conn,
                "SELECT COUNT(*) FROM {$qualifiedTable} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
                [$rowIdsArray, $tableName, $userId]
            );
            if (!$countResult) {
                throw new ServerErrorException('Database query failed.');
            }
            $count = (int)pg_fetch_result($countResult, 0, 0);
            pg_free_result($countResult);

            $rowResult = @pg_query_params(
                $this->conn,
                "SELECT _t.id, {$colSql} AS current_val
             FROM {$qualifiedTable} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             ORDER BY _t.id
             LIMIT 10",
                [$rowIdsArray, $tableName, $userId]
            );
        } else {
            $countResult = @pg_query_params(
                $this->conn,
                "SELECT COUNT(*) FROM {$qualifiedTable} WHERE id = ANY(\$1::int[])",
                [$rowIdsArray]
            );
            if (!$countResult) {
                throw new ServerErrorException('Database query failed.');
            }
            $count = (int)pg_fetch_result($countResult, 0, 0);
            pg_free_result($countResult);

            $rowResult = @pg_query_params(
                $this->conn,
                "SELECT id, {$colSql} AS current_val
             FROM {$qualifiedTable}
             WHERE id = ANY(\$1::int[])
             ORDER BY id
             LIMIT 10",
                [$rowIdsArray]
            );
        }

        if (!$rowResult) {
            throw new ServerErrorException('Database query failed.');
        }

        $rows = [];
        while ($row = pg_fetch_assoc($rowResult)) {
            $rows[] = ['id' => (int)$row['id'], 'current' => $row['current_val']];
        }
        pg_free_result($rowResult);

        throw ResponseException::encoded(['count' => $count, 'rows' => $rows]);
    }

    private function apply(): void
    {
        [$body, $rowIds] = $this->selectedRows();

        $value = array_key_exists('value', $body)
            ? ($body['value'] === null ? null : (string)$body['value'])
            : null;

        [$tableCfg, $tableName, $colCfg, $colSql, $qualifiedTable] = $this->validateTableColumn($body);

        if (($regexpError = validate_column_regexp($colCfg, $value)) !== null) {
            throw HttpException::fromStatus(422, (string) $regexpError);
        }

        $rowIdsArray = $this->pgIntArray($rowIds);

        @pg_query($this->conn, 'BEGIN');

        if (!empty($tableCfg['owner_restricted'])) {
            $userId      = $this->session->userId();
            $ownerSql = owner_restriction_sql('_t.id', 3, 4);
            $result = @pg_query_params(
                $this->conn,
                "UPDATE {$qualifiedTable} AS _t SET {$colSql} = \$2 WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
                [$rowIdsArray, $value, $tableName, $userId]
            );
        } else {
            $result = @pg_query_params(
                $this->conn,
                "UPDATE {$qualifiedTable} SET {$colSql} = \$2 WHERE id = ANY(\$1::int[])",
                [$rowIdsArray, $value]
            );
        }

        if (!$result) {
            @pg_query($this->conn, 'ROLLBACK');
            throw new ServerErrorException('Database update failed.');
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);
        @pg_query($this->conn, 'COMMIT');

        $userId = $this->session->userId();
        log_user_action($this->conn, $userId, 'MASS_EDIT', $tableName, null);

        throw ResponseException::encoded(['updated' => $affected]);
    }

    private function duplicate(): void
    {
        [$body, $rowIds] = $this->selectedRows();

        [$tableCfg, $tableName] = $this->validatedTable($body);

        $duplicateColumns = [];
        foreach ($tableCfg['columns'] as $colName => $colCfg) {
            if ($colName === 'id') {
                continue;
            }
            if (strtolower($colCfg['type'] ?? '') === 'virtual') {
                continue;
            }
            $duplicateColumns[] = $colName;
        }

        if (empty($duplicateColumns)) {
            throw HttpException::fromStatus(422, 'No columns to duplicate');
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
        $colIdents  = implode(', ', array_map('pg_ident', $duplicateColumns));
        $rowIdsArray   = $this->pgIntArray($rowIds);

        $userId        = $this->session->userId();

        @pg_query($this->conn, 'BEGIN');

        if (!empty($tableCfg['owner_restricted'])) {
            $ownerSql = owner_restriction_sql('_t.id', 2, 3);
            $result = @pg_query_params(
                $this->conn,
                "INSERT INTO {$qualifiedTable} ({$colIdents})
             SELECT {$colIdents} FROM {$qualifiedTable} AS _t
             WHERE _t.id = ANY(\$1::int[]){$ownerSql}
             RETURNING id",
                [$rowIdsArray, $tableName, $userId]
            );
        } else {
            $result = @pg_query_params(
                $this->conn,
                "INSERT INTO {$qualifiedTable} ({$colIdents})
             SELECT {$colIdents} FROM {$qualifiedTable}
             WHERE id = ANY(\$1::int[])
             RETURNING id",
                [$rowIdsArray]
            );
        }

        if (!$result) {
            @pg_query($this->conn, 'ROLLBACK');
            $pgError    = pg_last_error($this->conn);
            $isUnique = stripos($pgError, 'unique') !== false || stripos($pgError, 'unikaln') !== false;
            throw HttpException::fromStatus(
                422,
                $isUnique ? 'unique_violation' : 'Database duplicate failed.',
                [
                    'error'     => $isUnique ? 'unique_violation' : 'Database duplicate failed.',
                    'is_unique' => $isUnique,
                ]
            );
        }

        $newIds = [];
        while ($row = pg_fetch_row($result)) {
            $newIds[] = (int)$row[0];
        }
        $duplicated = count($newIds);
        pg_free_result($result);

        if (!empty($tableCfg['owner_restricted'])) {
            foreach ($newIds as $newId) {
                set_record_owner($this->conn, $tableName, $newId, $userId, $userId);
            }
        }

        @pg_query($this->conn, 'COMMIT');

        log_user_action($this->conn, $userId, 'MASS_DUPLICATE', $tableName, null);

        throw ResponseException::encoded(['duplicated' => $duplicated]);
    }

    private function delete(): void
    {
        [$body, $rowIds] = $this->selectedRows();

        [$tableCfg, $tableName] = $this->validatedTable($body);

        $schemaName = $tableCfg['schema'] ?? 'public';
        $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
        $rowIdsArray   = $this->pgIntArray($rowIds);

        @pg_query($this->conn, 'BEGIN');

        if (!empty($tableCfg['owner_restricted'])) {
            $userId      = $this->session->userId();
            $ownerSql = owner_restriction_sql('_t.id', 2, 3);
            $result = @pg_query_params(
                $this->conn,
                "DELETE FROM {$qualifiedTable} AS _t WHERE _t.id = ANY(\$1::int[]){$ownerSql}",
                [$rowIdsArray, $tableName, $userId]
            );
        } else {
            $result = @pg_query_params(
                $this->conn,
                "DELETE FROM {$qualifiedTable} WHERE id = ANY(\$1::int[])",
                [$rowIdsArray]
            );
        }

        if (!$result) {
            @pg_query($this->conn, 'ROLLBACK');
            throw new ServerErrorException('Database delete failed.');
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);
        @pg_query($this->conn, 'COMMIT');

        $userId = $this->session->userId();
        log_user_action($this->conn, $userId, 'MASS_DELETE', $tableName, null);

        throw ResponseException::encoded(['deleted' => $affected]);
    }
}
