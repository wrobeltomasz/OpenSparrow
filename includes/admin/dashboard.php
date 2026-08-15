<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

if ($action === 'dashboard_calculate') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/api_helpers.php';
        require_once __DIR__ . '/../../includes/dashboard_query.php';

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new AdminApiMessage('Invalid request body.');
        }

        $table = (string)($body['table'] ?? '');
        $query = is_array($body['query'] ?? null) ? $body['query'] : [];
        $displayColumns = is_array($body['display_columns'] ?? null) ? $body['display_columns'] : [];

        if ($table === '') {
            throw new AdminApiMessage('No source table selected.');
        }

        $schemaCfg = config_get('schema') ?? [];
        try {
            $tableCfg = safe_table($schemaCfg, $table);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            throw new AdminApiMessage('Unknown table: ' . $table);
        }
        $schemaName = $tableCfg['schema'] ?? 'public';

        $conn = db_connect();
        $condSql = dashboard_conditions_sql(
            $conn,
            $tableCfg,
            is_array($query['conditions'] ?? null) ? $query['conditions'] : []
        );
        $sqlWhere = $condSql === '' ? '' : ' WHERE ' . $condSql;

        $result = dashboard_run_widget_query($conn, $tableCfg, $schemaName, $table, $query, $displayColumns, $sqlWhere);

        if (isset($result['sql_error'])) {
            admin_err($result['sql_error']);
        }

        echo json_encode(['status' => 'success', 'data' => $result['data']]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
