<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function frontapi_workflow_procedure(FrontApiContext $ctx): never
{
    $conn = $ctx->conn;

    $body       = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $workflowId = (string)($body['workflow_id'] ?? '');
    $stepIndex  = (int)($body['step_index'] ?? -1);
    $stepValues = is_array($body['step_values'] ?? null) ? $body['step_values'] : [];

    require_access('workflows', $workflowId);

    $wfConfig = config_get('workflows') ?? [];
    $procCfg  = null;
    foreach ($wfConfig['workflows'] ?? [] as $wf) {
        if (($wf['id'] ?? '') !== $workflowId) {
            continue;
        }

        if (!workflow_tables_in_scope($wf)) {
            jsonError('Forbidden: no access to this workflow.', 403);
        }
        $procCfg = $wf['steps'][$stepIndex]['procedure'] ?? null;
        break;
    }

    if (!is_array($procCfg) || empty($procCfg['enabled'])) {
        http_response_code(400);
        exit(json_encode(['error' => 'No procedure configured for this step.']));
    }

    $procSchema = trim((string)($procCfg['schema'] ?? ''));
    $procName   = trim((string)($procCfg['name'] ?? ''));
    if ($procSchema === '' || $procName === '') {
        http_response_code(400);
        exit(json_encode(['error' => 'Procedure configuration is incomplete.']));
    }

    $params = [];
    foreach ($procCfg['params'] ?? [] as $param) {
        if (($param['source'] ?? '') === 'literal') {
            $value = (string)($param['value'] ?? '');
        } else {
            $srcStep  = (string)($param['step'] ?? '');
            $srcField = (string)($param['field'] ?? '');
            $value    = $stepValues[$srcStep][$srcField] ?? null;
            if (is_bool($value)) {
                $value = $value ? 't' : 'f';
            } elseif ($value !== null) {
                $value = (string)$value;
            }
        }
        $params[] = ($value === '' || $value === null) ? null : $value;
    }

    $placeholders = [];
    for ($p = 1; $p <= count($params); $p++) {
        $placeholders[] = '$' . $p;
    }
    $callSql = 'CALL ' . pg_ident($procSchema) . '.' . pg_ident($procName)
        . '(' . implode(', ', $placeholders) . ')';

    if (!@pg_send_query_params($conn, $callSql, $params)) {
        http_response_code(500);
        exit(json_encode(['error' => 'Could not execute the procedure.']));
    }

    $procRes = pg_get_result($conn);
    $sqlErr  = $procRes ? pg_result_error_field($procRes, PGSQL_DIAG_MESSAGE_PRIMARY) : null;

    while (pg_get_result($conn)) {
        continue;
    }

    if ($sqlErr !== null && $sqlErr !== '') {
        error_log('[workflow_procedure] ' . $procSchema . '.' . $procName . ': ' . $sqlErr);
        http_response_code(400);
        exit(json_encode(['error' => $sqlErr]));
    }

    log_user_action($conn, $ctx->userId, 'CALL_PROCEDURE');
    exit(json_encode(['success' => true]));
}
