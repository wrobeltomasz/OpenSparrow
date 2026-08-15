<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap(['role' => 'editor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

require_once __DIR__ . '/../../includes/config_store.php';
$schema = config_get('schema') ?? ['tables' => []];

function pgRegexEscape(string $text): string
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

function buildAccentPattern(string $text): string
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
            $result .= pgRegexEscape($character);
        }
    }
    return $result;
}

function validateInput(array $body, array $schema, $conn): array
{
    $tableName = $body['table']  ?? '';
    $colName   = $body['column'] ?? '';

    try {
        $tableCfg = safe_table($schema, $tableName);
    } catch (\RuntimeException $exception) {
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

function buildExpressions(
    string $find,
    string $replace,
    bool $caseInsensitive,
    bool $wholeWord,
    bool $ignoreAccents
): array {
    $pattern = $ignoreAccents ? buildAccentPattern($find) : pgRegexEscape($find);

    if ($wholeWord) {
        $pattern = '\\y' . $pattern . '\\y';
    }

    $flags   = $caseInsensitive ? 'ig' : 'g';
    $whereOp = $caseInsensitive ? '~*' : '~';

    $safeReplace = str_replace('\\', '\\\\', $replace);

    return [$pattern, $flags, $whereOp, $safeReplace];
}

if ($action === 'data_cleanup_preview' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $find      = (string)($body['find']    ?? '');
    $replace   = (string)($body['replace'] ?? '');
    $caseInsensitive = !empty($body['case_insensitive']);
    $wholeWord       = !empty($body['whole_word']);
    $ignoreAccents   = !empty($body['ignore_accents']);

    if ($find === '') {
        throw ResponseException::encoded(['count' => 0, 'rows' => []]);
    }

    [$tableCfg, , $tableName, $colSql, $qualifiedTable] = validateInput($body, $schema, $conn);

    [$pattern, $flags, $whereOp, $safeReplace] = buildExpressions(
        $find,
        $replace,
        $caseInsensitive,
        $wholeWord,
        $ignoreAccents
    );

    $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
    $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

    if (!empty($tableCfg['owner_restricted'])) {
        $userId      = (int)$_SESSION['user_id'];
        $ownerCnt = owner_restriction_sql('_t.id', 2, 3);
        $ownerRow = owner_restriction_sql('_t.id', 3, 4);

        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$qualifiedTable} AS _t WHERE {$whereSql}{$ownerCnt}",
            [$pattern, $tableName, $userId]
        );
        if (!$countResult) {
            throw new ServerErrorException('Database query failed.');
        }
        $count = (int)pg_fetch_result($countResult, 0, 0);
        pg_free_result($countResult);

        $rowResult = @pg_query_params(
            $conn,
            "SELECT _t.id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$qualifiedTable} AS _t
             WHERE {$whereSql}{$ownerRow}
             LIMIT 20",
            [$pattern, $safeReplace, $tableName, $userId]
        );
    } else {
        $countResult = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$qualifiedTable} WHERE {$whereSql}",
            [$pattern]
        );
        if (!$countResult) {
            throw new ServerErrorException('Database query failed.');
        }
        $count = (int)pg_fetch_result($countResult, 0, 0);
        pg_free_result($countResult);

        $rowResult = @pg_query_params(
            $conn,
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

if ($action === 'data_cleanup_apply' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $find      = (string)($body['find']    ?? '');
    $replace   = (string)($body['replace'] ?? '');
    $caseInsensitive = !empty($body['case_insensitive']);
    $wholeWord       = !empty($body['whole_word']);
    $ignoreAccents   = !empty($body['ignore_accents']);

    if ($find === '') {
        throw new BadRequestException('Find string required');
    }

    [$tableCfg, , $tableName, $colSql, $qualifiedTable] = validateInput($body, $schema, $conn);

    [$pattern, $flags, $whereOp, $safeReplace] = buildExpressions(
        $find,
        $replace,
        $caseInsensitive,
        $wholeWord,
        $ignoreAccents
    );

    $whereSql   = "{$colSql} {$whereOp} \$1 AND {$colSql} IS NOT NULL";
    $replaceExp = "regexp_replace({$colSql}, \$1, \$2, '{$flags}')";

    @pg_query($conn, 'BEGIN');

    if (!empty($tableCfg['owner_restricted'])) {
        $userId      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 3, 4);
        $queryResult = @pg_query_params(
            $conn,
            "UPDATE {$qualifiedTable} AS _t SET {$colSql} = {$replaceExp} WHERE {$whereSql}{$ownerSql}",
            [$pattern, $safeReplace, $tableName, $userId]
        );
    } else {
        $queryResult = @pg_query_params(
            $conn,
            "UPDATE {$qualifiedTable} SET {$colSql} = {$replaceExp} WHERE {$whereSql}",
            [$pattern, $safeReplace]
        );
    }

    if (!$queryResult) {
        @pg_query($conn, 'ROLLBACK');
        throw new ServerErrorException('Database update failed.');
    }

    $affected = pg_affected_rows($queryResult);
    pg_free_result($queryResult);
    @pg_query($conn, 'COMMIT');

    $userId = (int)$_SESSION['user_id'];
    log_user_action($conn, $userId, 'DATA_CLEANUP', $tableName, null);

    throw ResponseException::encoded(['updated' => $affected]);
}

throw new BadRequestException('Unknown action');
