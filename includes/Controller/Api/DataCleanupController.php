<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\BadRequestException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Service\AppContext;
use PgSql\Connection;
use RuntimeException;

final class DataCleanupController
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

        if ($action === 'data_cleanup_preview' && $method === 'POST') {
            $this->preview();
        }

        if ($action === 'data_cleanup_apply' && $method === 'POST') {
            $this->apply();
        }

        throw new BadRequestException('Unknown action');
    }

    private function pgRegexEscape(string $text): string
    {
        $special = ['.', '*', '+', '?', '[', ']', '{', '}', '(', ')', '|', '^', '$', '\\'];
        $result  = '';
        $length     = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $character      = mb_substr($text, $i, 1, 'UTF-8');
            $result .= in_array($character, $special, true) ? '\\' . $character : $character;
        }
        return $result;
    }

    private function buildAccentPattern(string $text): string
    {
        $map = [
            'a' => 'aàáâãäåą',
            'c' => 'cćçč',
            'd' => 'dď',
            'e' => 'eèéêëę',
            'g' => 'gğ',
            'i' => 'iìíîï',
            'l' => 'lłľ',
            'n' => 'nñňń',
            'o' => 'oòóôõöøő',
            'r' => 'rř',
            's' => 'sśşšß',
            't' => 'tťþ',
            'u' => 'uùúûüů',
            'y' => 'yý',
            'z' => 'zźżž',
        ];

        $result = '';
        $lower  = mb_strtolower($text, 'UTF-8');
        $length    = mb_strlen($lower, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $character = mb_substr($lower, $i, 1, 'UTF-8');
            if (isset($map[$character])) {
                $lowerVariants = preg_split('//u', $map[$character], -1, PREG_SPLIT_NO_EMPTY);
                $upperVariants = array_map(fn($char) => mb_strtoupper($char, 'UTF-8'), $lowerVariants);
                $all = array_unique(array_merge($lowerVariants, $upperVariants));

                $escaped = implode('', array_map(function ($char) {
                    return in_array($char, [']', '\\', '^', '-'], true) ? '\\' . $char : $char;
                }, $all));
                $result .= '[' . $escaped . ']';
            } else {
                $result .= $this->pgRegexEscape($character);
            }
        }
        return $result;
    }

    private function validateInput(array $body): array
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
        if (!isset($columns[$colName]) || ($columns[$colName]['type'] ?? '') === 'virtual') {
            throw new BadRequestException('Invalid column');
        }

        $schemaName = $tableCfg['schema'] ?? 'public';
        $qualifiedTable     = pg_ident($schemaName) . '.' . pg_ident($tableName);
        $colSql     = pg_ident($colName);

        return [$tableCfg, $schemaName, $tableName, $colSql, $qualifiedTable];
    }

    private function buildExpressions(
        string $find,
        string $replace,
        bool $caseInsensitive,
        bool $wholeWord,
        bool $ignoreAccents
    ): array {
        $pattern = $ignoreAccents ? $this->buildAccentPattern($find) : $this->pgRegexEscape($find);

        if ($wholeWord) {
            $pattern = '\\y' . $pattern . '\\y';
        }

        $flags   = $caseInsensitive ? 'ig' : 'g';
        $whereOp = $caseInsensitive ? '~*' : '~';

        $safeReplace = str_replace('\\', '\\\\', $replace);

        return [$pattern, $flags, $whereOp, $safeReplace];
    }

    private function preview(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $find      = (string)($body['find']    ?? '');
        $replace   = (string)($body['replace'] ?? '');
        $caseInsensitive = !empty($body['case_insensitive']);
        $wholeWord       = !empty($body['whole_word']);
        $ignoreAccents   = !empty($body['ignore_accents']);

        if ($find === '') {
            throw ResponseException::encoded(['count' => 0, 'rows' => []]);
        }

        [$tableCfg, , $tableName, $colSql, $qualifiedTable] = $this->validateInput($body);

        [$pattern, $flags, $whereOp, $safeReplace] = $this->buildExpressions(
            $find,
            $replace,
            $caseInsensitive,
            $wholeWord,
            $ignoreAccents
        );

        $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
        $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

        if (!empty($tableCfg['owner_restricted'])) {
            $userId      = $this->session->userId();
            $ownerCondition = owner_restriction_sql('_t.id', 2, 3);
            $ownerRow = owner_restriction_sql('_t.id', 3, 4);

            $countResult = @pg_query_params(
                $this->conn,
                "SELECT COUNT(*) FROM {$qualifiedTable} AS _t WHERE {$whereSql}{$ownerCondition}",
                [$pattern, $tableName, $userId]
            );
            if (!$countResult) {
                throw new ServerErrorException('Database query failed.');
            }
            $count = (int)pg_fetch_result($countResult, 0, 0);
            pg_free_result($countResult);

            $rowResult = @pg_query_params(
                $this->conn,
                "SELECT _t.id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$qualifiedTable} AS _t
             WHERE {$whereSql}{$ownerRow}
             LIMIT 20",
                [$pattern, $safeReplace, $tableName, $userId]
            );
        } else {
            $countResult = @pg_query_params(
                $this->conn,
                "SELECT COUNT(*) FROM {$qualifiedTable} WHERE {$whereSql}",
                [$pattern]
            );
            if (!$countResult) {
                throw new ServerErrorException('Database query failed.');
            }
            $count = (int)pg_fetch_result($countResult, 0, 0);
            pg_free_result($countResult);

            $rowResult = @pg_query_params(
                $this->conn,
                "SELECT id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$qualifiedTable}
             WHERE {$whereSql}
             LIMIT 20",
                [$pattern, $safeReplace]
            );
        }

        if (!$rowResult) {
            throw new ServerErrorException('Database query failed.');
        }

        $rows = [];
        while ($row = pg_fetch_assoc($rowResult)) {
            $rows[] = ['id' => $row['id'], 'before' => $row['before_val'], 'after' => $row['after_val']];
        }
        pg_free_result($rowResult);

        throw ResponseException::encoded(['count' => $count, 'rows' => $rows]);
    }

    private function apply(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $find      = (string)($body['find']    ?? '');
        $replace   = (string)($body['replace'] ?? '');
        $caseInsensitive = !empty($body['case_insensitive']);
        $wholeWord       = !empty($body['whole_word']);
        $ignoreAccents   = !empty($body['ignore_accents']);

        if ($find === '') {
            throw new BadRequestException('Find string required');
        }

        [$tableCfg, , $tableName, $colSql, $qualifiedTable] = $this->validateInput($body);

        [$pattern, $flags, $whereOp, $safeReplace] = $this->buildExpressions(
            $find,
            $replace,
            $caseInsensitive,
            $wholeWord,
            $ignoreAccents
        );

        $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
        $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

        @pg_query($this->conn, 'BEGIN');

        if (!empty($tableCfg['owner_restricted'])) {
            $userId      = $this->session->userId();
            $ownerSql = owner_restriction_sql('_t.id', 3, 4);
            $queryResult = @pg_query_params(
                $this->conn,
                "UPDATE {$qualifiedTable} AS _t SET {$colSql} = {$replaceExp} WHERE {$whereSql}{$ownerSql}",
                [$pattern, $safeReplace, $tableName, $userId]
            );
        } else {
            $queryResult = @pg_query_params(
                $this->conn,
                "UPDATE {$qualifiedTable} SET {$colSql} = {$replaceExp} WHERE {$whereSql}",
                [$pattern, $safeReplace]
            );
        }

        if (!$queryResult) {
            @pg_query($this->conn, 'ROLLBACK');
            throw new ServerErrorException('Database update failed.');
        }

        $affected = pg_affected_rows($queryResult);
        pg_free_result($queryResult);
        @pg_query($this->conn, 'COMMIT');

        $userId = $this->session->userId();
        log_user_action($this->conn, $userId, 'DATA_CLEANUP', $tableName, null);

        throw ResponseException::encoded(['updated' => $affected]);
    }
}
