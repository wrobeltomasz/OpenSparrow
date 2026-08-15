<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$conn = os_api_bootstrap(['role' => 'editor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

require_once __DIR__ . '/../../includes/config_store.php';
$schema = config_get('schema') ?? ['tables' => []];

function pgRegexEscape(string $s): string
{
    $special = ['.', '*', '+', '?', '[', ']', '{', '}', '(', ')', '|', '^', '$', '\\'];
    $result  = '';
    $len     = mb_strlen($s, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch      = mb_substr($s, $i, 1, 'UTF-8');
        $result .= in_array($ch, $special, true) ? '\\' . $ch : $ch;
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
    $len    = mb_strlen($lower, 'UTF-8');

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($lower, $i, 1, 'UTF-8');
        if (isset($map[$ch])) {
            $lowerVariants = preg_split('//u', $map[$ch], -1, PREG_SPLIT_NO_EMPTY);
            $upperVariants = array_map(fn($char) => mb_strtoupper($char, 'UTF-8'), $lowerVariants);
            $all = array_unique(array_merge($lowerVariants, $upperVariants));

            $escaped = implode('', array_map(function ($char) {
                return in_array($char, [']', '\\', '^', '-'], true) ? '\\' . $char : $char;
            }, $all));
            $result .= '[' . $escaped . ']';
        } else {
            $result .= pgRegexEscape($ch);
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
    } catch (\RuntimeException $e) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown table']));
    }

    require_table_access($tableName);

    $cols = $tableCfg['columns'] ?? [];
    if (!isset($cols[$colName]) || ($cols[$colName]['type'] ?? '') === 'virtual') {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid column']));
    }

    $schemaName = $tableCfg['schema'] ?? 'public';
    $tblSql     = pg_ident($schemaName) . '.' . pg_ident($tableName);
    $colSql     = pg_ident($colName);

    return [$tableCfg, $schemaName, $tableName, $colSql, $tblSql];
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
        exit(json_encode(['count' => 0, 'rows' => []]));
    }

    [$tableCfg, , $tableName, $colSql, $tblSql] = validateInput($body, $schema, $conn);

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
        $uid      = (int)$_SESSION['user_id'];
        $ownerCnt = owner_restriction_sql('_t.id', 2, 3);
        $ownerRow = owner_restriction_sql('_t.id', 3, 4);

        $cntRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} AS _t WHERE {$whereSql}{$ownerCnt}",
            [$pattern, $tableName, $uid]
        );
        if (!$cntRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($cntRes, 0, 0);
        pg_free_result($cntRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT _t.id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$tblSql} AS _t
             WHERE {$whereSql}{$ownerRow}
             LIMIT 20",
            [$pattern, $safeReplace, $tableName, $uid]
        );
    } else {
        $cntRes = @pg_query_params(
            $conn,
            "SELECT COUNT(*) FROM {$tblSql} WHERE {$whereSql}",
            [$pattern]
        );
        if (!$cntRes) {
            http_response_code(500);
            exit(json_encode(['error' => 'Database query failed.']));
        }
        $count = (int)pg_fetch_result($cntRes, 0, 0);
        pg_free_result($cntRes);

        $rowRes = @pg_query_params(
            $conn,
            "SELECT id, {$colSql} AS before_val, {$replaceExp} AS after_val
             FROM {$tblSql}
             WHERE {$whereSql}
             LIMIT 20",
            [$pattern, $safeReplace]
        );
    }

    if (!$rowRes) {
        http_response_code(500);
        exit(json_encode(['error' => 'Database query failed.']));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($rowRes)) {
        $rows[] = ['id' => $row['id'], 'before' => $row['before_val'], 'after' => $row['after_val']];
    }
    pg_free_result($rowRes);

    exit(json_encode(['count' => $count, 'rows' => $rows]));
}

if ($action === 'data_cleanup_apply' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $find      = (string)($body['find']    ?? '');
    $replace   = (string)($body['replace'] ?? '');
    $caseInsensitive = !empty($body['case_insensitive']);
    $wholeWord       = !empty($body['whole_word']);
    $ignoreAccents   = !empty($body['ignore_accents']);

    if ($find === '') {
        http_response_code(400);
        exit(json_encode(['error' => 'Find string required']));
    }

    [$tableCfg, , $tableName, $colSql, $tblSql] = validateInput($body, $schema, $conn);

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
        $uid      = (int)$_SESSION['user_id'];
        $ownerSql = owner_restriction_sql('_t.id', 3, 4);
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} AS _t SET {$colSql} = {$replaceExp} WHERE {$whereSql}{$ownerSql}",
            [$pattern, $safeReplace, $tableName, $uid]
        );
    } else {
        $res = @pg_query_params(
            $conn,
            "UPDATE {$tblSql} SET {$colSql} = {$replaceExp} WHERE {$whereSql}",
            [$pattern, $safeReplace]
        );
    }

    if (!$res) {
        @pg_query($conn, 'ROLLBACK');
        http_response_code(500);
        exit(json_encode(['error' => 'Database update failed.']));
    }

    $affected = pg_affected_rows($res);
    pg_free_result($res);
    @pg_query($conn, 'COMMIT');

    $uid = (int)$_SESSION['user_id'];
    log_user_action($conn, $uid, 'DATA_CLEANUP', $tableName, null);

    exit(json_encode(['updated' => $affected]));
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action']));
