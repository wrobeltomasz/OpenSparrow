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

    $workflowConfig = config_get('workflows') ?? [];
    $procedureConfig  = null;
    foreach ($workflowConfig['workflows'] ?? [] as $workflow) {
        if (($workflow['id'] ?? '') !== $workflowId) {
            continue;
        }

        if (!workflow_tables_in_scope($workflow)) {
            jsonError('Forbidden: no access to this workflow.', 403);
        }
        $procedureConfig = $workflow['steps'][$stepIndex]['procedure'] ?? null;
        break;
    }

    if (!is_array($procedureConfig) || empty($procedureConfig['enabled'])) {
        throw new BadRequestException('No procedure configured for this step.');
    }

    $procedureSchema = trim((string)($procedureConfig['schema'] ?? ''));
    $procedureName   = trim((string)($procedureConfig['name'] ?? ''));
    if ($procedureSchema === '' || $procedureName === '') {
        throw new BadRequestException('Procedure configuration is incomplete.');
    }

    $parameters = [];
    foreach ($procedureConfig['params'] ?? [] as $parameter) {
        if (($parameter['source'] ?? '') === 'literal') {
            $value = (string)($parameter['value'] ?? '');
        } else {
            $sourceStep  = (string)($parameter['step'] ?? '');
            $sourceField = (string)($parameter['field'] ?? '');
            $value    = $stepValues[$sourceStep][$sourceField] ?? null;
            if (is_bool($value)) {
                $value = $value ? 't' : 'f';
            } elseif ($value !== null) {
                $value = (string)$value;
            }
        }
        $parameters[] = ($value === '' || $value === null) ? null : $value;
    }

    $placeholders = [];
    for ($parameterIndex = 1; $parameterIndex <= count($parameters); $parameterIndex++) {
        $placeholders[] = '$' . $parameterIndex;
    }
    $callSql = 'CALL ' . pg_ident($procedureSchema) . '.' . pg_ident($procedureName)
        . '(' . implode(', ', $placeholders) . ')';

    if (!@pg_send_query_params($conn, $callSql, $parameters)) {
        throw new ServerErrorException('Could not execute the procedure.');
    }

    $procedureResult = pg_get_result($conn);
    $sqlError  = $procedureResult ? pg_result_error_field($procedureResult, PGSQL_DIAG_MESSAGE_PRIMARY) : null;

    while (pg_get_result($conn)) {
        continue;
    }

    if ($sqlError !== null && $sqlError !== '') {
        error_log('[workflow_procedure] ' . $procedureSchema . '.' . $procedureName . ': ' . $sqlError);
        throw new BadRequestException((string) $sqlError);
    }

    log_user_action($conn, $context->userId, 'CALL_PROCEDURE');
    throw ResponseException::encoded(['success' => true]);
}
