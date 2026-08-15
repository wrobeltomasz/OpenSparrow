<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

function frontapi_workflow_procedure(FrontApiContext $context): never
{
    $conn = $context->conn;

    $body       = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $workflowId = (string)($body['workflow_id'] ?? '');
    $stepIndex  = (int)($body['step_index'] ?? -1);
    $stepValues = is_array($body['step_values'] ?? null) ? $body['step_values'] : [];

    require_access('workflows', $workflowId);

    $wfConfig = config_get('workflows') ?? [];
    $procCfg  = null;
    foreach ($wfConfig['workflows'] ?? [] as $workflow) {
        if (($workflow['id'] ?? '') !== $workflowId) {
            continue;
        }

        if (!workflow_tables_in_scope($workflow)) {
            jsonError('Forbidden: no access to this workflow.', 403);
        }
        $procCfg = $workflow['steps'][$stepIndex]['procedure'] ?? null;
        break;
    }

    if (!is_array($procCfg) || empty($procCfg['enabled'])) {
        throw new BadRequestException('No procedure configured for this step.');
    }

    $procSchema = trim((string)($procCfg['schema'] ?? ''));
    $procName   = trim((string)($procCfg['name'] ?? ''));
    if ($procSchema === '' || $procName === '') {
        throw new BadRequestException('Procedure configuration is incomplete.');
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
    for ($parameterIndex = 1; $parameterIndex <= count($params); $parameterIndex++) {
        $placeholders[] = '$' . $parameterIndex;
    }
    $callSql = 'CALL ' . pg_ident($procSchema) . '.' . pg_ident($procName)
        . '(' . implode(', ', $placeholders) . ')';

    if (!@pg_send_query_params($conn, $callSql, $params)) {
        throw new ServerErrorException('Could not execute the procedure.');
    }

    $procedureResult = pg_get_result($conn);
    $sqlErr  = $procedureResult ? pg_result_error_field($procedureResult, PGSQL_DIAG_MESSAGE_PRIMARY) : null;

    while (pg_get_result($conn)) {
        continue;
    }

    if ($sqlErr !== null && $sqlErr !== '') {
        error_log('[workflow_procedure] ' . $procSchema . '.' . $procName . ': ' . $sqlErr);
        throw new BadRequestException((string) $sqlErr);
    }

    log_user_action($conn, $context->userId, 'CALL_PROCEDURE');
    throw ResponseException::encoded(['success' => true]);
}
