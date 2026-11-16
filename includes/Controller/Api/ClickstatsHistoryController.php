<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ResponseException;
use App\Service\AppContext;
use PgSql\Connection;

final class ClickstatsHistoryController
{
    private const HISTORY_LIMIT = 100;

    public function __construct(private readonly AppContext $context)
    {
    }

    public function handle(): void
    {
        if ($this->context->request()->method() !== 'GET') {
            http_response_code(405);
            throw ResponseException::sent();
        }

        $conn = $this->context->connection();
        $table = sys_table('clickstats');

        if (!@pg_query($conn, "SELECT 1 FROM {$table} LIMIT 0")) {
            jsonSuccess(['rows' => [], 'total' => 0]);
        }

        $userId = $this->context->session()->userId();

        $result = @pg_query_params(
            $conn,
            "SELECT id, element, page, table_name, record_id, created_at, COUNT(*) OVER() AS total
               FROM {$table}
              WHERE user_id = \$1
              ORDER BY created_at DESC, id DESC
              LIMIT " . self::HISTORY_LIMIT,
            [$userId]
        );
        if (!$result) {
            error_log('[api_clickstats_history] ' . pg_last_error($conn));
            jsonError('Database error.', 500);
        }

        $rows = [];
        $total = 0;
        while ($row = pg_fetch_assoc($result)) {
            if ($total === 0) {
                $total = (int) ($row['total'] ?? 0);
            }
            $rows[] = [
                'id'          => (int) $row['id'],
                'element'     => $row['element'],
                'page'        => $row['page'],
                'table_name'  => $row['table_name'],
                'record_id'   => $row['record_id'] !== null ? (int) $row['record_id'] : null,
                'created_at'  => $row['created_at'],
            ];
        }

        $this->attachRecordLinks($conn, $rows);

        jsonSuccess(['rows' => $rows, 'total' => $total]);
    }

    private function attachRecordLinks(Connection $conn, array &$rows): void
    {
        require_once __DIR__ . '/../../config_store.php';

        $schema = config_get('schema') ?? [];
        $userRecordsConfig = config_get('user_records') ?? [];
        $configuredColumns = is_array($userRecordsConfig['columns'] ?? null) ? $userRecordsConfig['columns'] : [];

        $idsByTable = [];
        foreach ($rows as $row) {
            $tableName = $row['table_name'];
            if (!is_string($tableName) || $tableName === '' || $row['record_id'] === null) {
                continue;
            }
            if (!user_can_access_table($tableName)) {
                continue;
            }
            $idsByTable[$tableName][(int) $row['record_id']] = true;
        }

        $labels = [];
        foreach ($idsByTable as $tableName => $recordIds) {
            $tableConfig = $schema['tables'][$tableName] ?? null;
            if ($tableConfig === null || !empty($tableConfig['hidden'])) {
                continue;
            }

            $rowsSql = sprintf(
                'SELECT id, %s AS label FROM %s.%s WHERE id = ANY($1::int[])',
                record_label_sql($tableConfig, $configuredColumns[$tableName] ?? []),
                pg_ident($tableConfig['schema'] ?? 'public'),
                pg_ident($tableName)
            );

            $labelResult = @pg_query_params(
                $conn,
                $rowsSql,
                ['{' . implode(',', array_keys($recordIds)) . '}']
            );
            if (!$labelResult) {
                error_log('[api_clickstats_history] label lookup failed: ' . pg_last_error($conn));
                continue;
            }

            $display = to_display_name($tableConfig);
            $icon = $this->tableIcon($tableConfig);
            while ($labelRow = pg_fetch_assoc($labelResult)) {
                $label = trim((string) ($labelRow['label'] ?? ''));
                $labels[$tableName][(int) $labelRow['id']] = [
                    'table_display' => $display,
                    'record_label'  => $label !== '' ? $label : null,
                    'icon'          => $icon,
                ];
            }
        }

        foreach ($rows as &$row) {
            $tableName = $row['table_name'];
            $recordId = $row['record_id'];
            $link = is_string($tableName) && $recordId !== null
                ? ($labels[$tableName][$recordId] ?? null)
                : null;

            $row['table_display'] = $link['table_display'] ?? null;
            $row['record_label']  = $link['record_label'] ?? null;
            $row['icon']          = $link['icon'] ?? null;
        }
        unset($row);
    }

    private function tableIcon(array $tableConfig): string
    {
        $icon = (string) ($tableConfig['icon'] ?? '');
        if (
            $icon !== ''
            && !str_contains($icon, '..')
            && preg_match('#^assets/[a-z0-9_\-/.]+\.(png|svg|gif|jpe?g|webp)$#i', $icon)
        ) {
            return $icon;
        }
        return 'assets/icons/material/table_chart_view.svg';
    }
}
