<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/frontapi/workflow_procedure.php — frontend API route module:
// POST ?api=workflow_procedure (CALL a configured PostgreSQL procedure).
//
// Deliberately NOT part of the mutating write group: that group resolves
// $body['table'] through safe_table(), and this request targets no schema table.
// It therefore takes a plain FrontApiContext and gates itself on the workflow scope
// instead. CSRF for POST is already enforced by os_api_bootstrap(); the admin and
// viewer roles are blocked by the front controller's gates.
//
// Pinned by tests/Security/AccessScopeEndpointGuardTest.

/**
 * Runs the procedure configured on one workflow step.
 */
function frontapi_workflow_procedure(FrontApiContext $ctx): never
{
    $conn = $ctx->conn;

    $body       = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $workflowId = (string)($body['workflow_id'] ?? '');
    $stepIndex  = (int)($body['step_index'] ?? -1);
    $stepValues = is_array($body['step_values'] ?? null) ? $body['step_values'] : [];

    // Per-user workflow scope. The workflow id is request-supplied and selects what
    // runs, so it is gated here: hiding a workflow from the menu and the list while
    // leaving this endpoint open would make the whole scope cosmetic — a direct POST
    // would still fire the procedure.
    require_access('workflows', $workflowId);

    // The procedure identity comes exclusively from the stored configuration —
    // never from the request — so a client can only trigger what an admin
    // already whitelisted by configuring it on this step.
    $wfConfig = config_get('workflows') ?? [];
    $procCfg  = null;
    foreach ($wfConfig['workflows'] ?? [] as $wf) {
        if (($wf['id'] ?? '') !== $workflowId) {
            continue;
        }
        // The step-table half of the rule, the same one the api=workflows list and
        // the menu apply. Both of those DISPLAY a workflow; this one FIRES it, and
        // the gate above only covers the id — so without this a user granted a
        // workflow whose steps target tables they were never granted could not see
        // it anywhere, yet could still POST its id and run the procedure against
        // those tables. This half only sees ids that match a configured workflow;
        // anything else falls through to the 400 below. Note that the id gate above
        // answers first and, for a RESTRICTED user, answers 403 for every id outside
        // their allow-list — a workflow that does not exist included. So the
        // "unknown is 400, not yours is 403" split that os_request_scope_violation()
        // preserves holds here only for unrestricted users. That is deliberate: both
        // cases are 403, so nothing is disclosed, and narrowing it further would mean
        // telling a restricted caller which workflow ids exist.
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

    // Resolve positional arguments. Field values come from the wizard's buffered
    // step snapshots; literals from the configuration. Empty string becomes NULL
    // so non-text parameters (int, date, …) do not fail on an untouched field.
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

    // No explicit transaction: a PROCEDURE may COMMIT internally, which errors
    // out inside an open transaction block. Async send + pg_get_result gives us a
    // result handle even on failure, so we can return only the RAISE message
    // (MESSAGE_PRIMARY) instead of the full pg_last_error() context.
    if (!@pg_send_query_params($conn, $callSql, $params)) {
        http_response_code(500);
        exit(json_encode(['error' => 'Could not execute the procedure.']));
    }

    $procRes = pg_get_result($conn);
    $sqlErr  = $procRes ? pg_result_error_field($procRes, PGSQL_DIAG_MESSAGE_PRIMARY) : null;
    // Drain any further results so the connection stays usable for later requests.
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
