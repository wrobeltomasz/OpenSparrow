<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ForbiddenException;
use App\Exception\HttpException;
use App\Exception\NotFoundException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Exception\UnauthorizedException;

final class ExternalApiController
{
    private const RATE_LIMIT_PER_MIN = 60;

    private const IP_RATE_LIMIT_PER_MIN = 300;

    public function handle(): void
    {
        $this->enforceIpRateLimit();

        $key = $this->apiKey();
        if ($key === '') {
            throw new UnauthorizedException('Missing API key.', ['error' => 'Missing API key.']);
        }

        $api = $this->resolveApi($key);
        if ($api === null) {
            throw new UnauthorizedException('Invalid API key.', ['error' => 'Invalid API key.']);
        }
        if (empty($api['enabled'])) {
            throw new ForbiddenException('This API is disabled.', ['error' => 'This API is disabled.']);
        }

        $bucket = 'extapi_' . substr(hash('sha256', $key), 0, 16);
        if (!os_rate_limit_ok($bucket, self::RATE_LIMIT_PER_MIN, 'external-api')) {
            header('Retry-After: 60');
            throw HttpException::fromStatus(429, 'Too many requests. Please slow down.');
        }

        $schema = config_get('schema');
        $table = (string) ($api['table'] ?? '');
        if (!is_array($schema) || $table === '' || !isset($schema['tables'][$table])) {
            throw new NotFoundException('The configured table no longer exists.');
        }
        $tableConfig = $schema['tables'][$table];
        if (!empty($tableConfig['hidden']) || !empty($tableConfig['owner_restricted']) || is_system_table($table)) {
            throw new NotFoundException('The configured table no longer exists.');
        }
        $schemaName = $tableConfig['schema'] ?? 'public';

        $conn = db_connect();

        $availableColumns = array_merge(['id'], column_list($tableConfig));
        $columns = array_values(array_unique(array_filter(
            array_map('strval', (array) ($api['columns'] ?? [])),
            static fn($column) => $column !== '' && in_array($column, $availableColumns, true)
        )));
        if ($columns === []) {
            throw new NotFoundException('The configured columns no longer exist.');
        }
        $selectSql = implode(', ', array_map(pg_ident(...), $columns));

        $whereSql = '';
        $parameters = [];
        foreach ((array) ($api['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $filterColumn = (string) ($filter['column'] ?? '');
            if ($filterColumn === '' || !in_array($filterColumn, $availableColumns, true)) {
                continue;
            }
            $value = (string) ($filter['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $column = pg_ident($filterColumn);
            $operator = (string) ($filter['operator'] ?? 'eq');
            $parameterNumber = count($parameters) + 1;
            $clause = match ($operator) {
                'neq'      => sprintf('%s <> $%d', $column, $parameterNumber),
                'gt'       => sprintf('%s > $%d', $column, $parameterNumber),
                'gte'      => sprintf('%s >= $%d', $column, $parameterNumber),
                'lt'       => sprintf('%s < $%d', $column, $parameterNumber),
                'lte'      => sprintf('%s <= $%d', $column, $parameterNumber),
                'contains' => sprintf('%s::text ILIKE $%d', $column, $parameterNumber),
                default    => sprintf('%s = $%d', $column, $parameterNumber),
            };
            $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . $clause;
            $parameters[] = $operator === 'contains'
                ? '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%'
                : $value;
        }

        $limit = max(1, min(1000, (int) ($api['limit'] ?? 100)));

        $sql = sprintf(
            'SELECT %s FROM %s.%s%s ORDER BY %s DESC LIMIT %d',
            $selectSql,
            pg_ident($schemaName),
            pg_ident($table),
            $whereSql,
            pg_ident('id'),
            $limit
        );
        $startedAt = hrtime(true);
        $result = @pg_query_params($conn, $sql, $parameters);
        if (!$result) {
            error_log('[external_api] ' . pg_last_error($conn));
            throw new ServerErrorException('Database error.');
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        pg_free_result($result);

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1e6);
        external_api_stats_record($conn, $api, $durationMs, count($rows));

        throw ResponseException::json([
            'data'  => $rows,
            'total' => count($rows),
        ]);
    }

    private function enforceIpRateLimit(): void
    {
        $ip = client_ip();
        if ($ip === '') {
            return;
        }
        $bucket = 'extapi_ip_' . substr(hash('sha256', $ip), 0, 16);
        if (!os_rate_limit_ok($bucket, self::IP_RATE_LIMIT_PER_MIN, 'external-api-ip')) {
            header('Retry-After: 60');
            throw HttpException::fromStatus(429, 'Too many requests. Please slow down.');
        }
    }

    private function apiKey(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }
        return '';
    }

    private function resolveApi(string $key): ?array
    {
        $keyHash = secret_hash($key);
        $config = config_get('external_api');
        foreach ((array) ($config['apis'] ?? []) as $api) {
            if (!is_array($api) || ($api['key_enc'] ?? '') === '') {
                continue;
            }
            $storedHash = (string) ($api['key_hash'] ?? '');
            if ($storedHash !== '' && hash_equals($storedHash, $keyHash)) {
                return $api;
            }
        }
        return null;
    }
}
