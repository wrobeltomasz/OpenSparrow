<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\HttpException;
use App\Exception\ResponseException;

require_once __DIR__ . '/../automations.php';
require_once __DIR__ . '/../crypto.php';

if ($action === 'automations_runs' && os_request()->method() === 'GET') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn  = db_connect();
        $automationRunsTable = sys_table('automation_runs');
        $ruleId = trim(os_request()->query('rule_id'));

        if ($ruleId !== '') {
            $result = @pg_query_params(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $automationRunsTable WHERE rule_id = \$1 ORDER BY executed_at DESC LIMIT 100",
                [$ruleId]
            );
        } else {
            $result = @pg_query(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $automationRunsTable ORDER BY executed_at DESC LIMIT 200"
            );
        }

        $runs = [];
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $runs[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'runs' => $runs]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

function auto_redact_secrets(array $rules): array
{
    foreach ($rules as &$rule) {
        foreach ((array) ($rule['actions'] ?? []) as $actionIndex => $ruleAction) {
            if (!is_array($ruleAction) || ($ruleAction['type'] ?? '') !== 'webhook') {
                continue;
            }
            $hasSecret = ($ruleAction['secret_enc'] ?? '') !== '' || ($ruleAction['secret'] ?? '') !== '';
            unset($ruleAction['secret_enc'], $ruleAction['secret']);
            $ruleAction['secret_configured'] = $hasSecret;

            $names      = array_keys((array) ($ruleAction['headers_enc'] ?? []));
            $legacy     = (array) ($ruleAction['headers'] ?? []);
            $configured = [];
            foreach ($names as $name) {
                $configured[(string) $name] = (string) $ruleAction['headers_enc'][$name] !== '';
            }
            foreach ($legacy as $name => $value) {
                $configured[(string) $name] ??= (string) $value !== '';
            }
            unset($ruleAction['headers_enc']);

            $ruleAction['headers']            = (object) array_map(static fn(): string => '', $configured);
            $ruleAction['headers_configured'] = (object) $configured;
            $ruleAction['payload']            = (object) ((array) ($ruleAction['payload'] ?? []));

            $rule['actions'][$actionIndex] = $ruleAction;
        }
    }
    unset($rule);
    return $rules;
}

if ($action === 'automations_list' && os_request()->method() === 'GET') {
    try {
        echo json_encode(['status' => 'success', 'automations' => auto_redact_secrets(auto_cfg_read())]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'automations_save' && os_request()->method() === 'POST') {
    if (DEMO_MODE) {
        throw HttpException::fromStatus(
            403,
            'Demo mode — writes disabled.',
            ['status' => 'error', 'error' => 'Demo mode — writes disabled.'],
        );
    }
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $name         = trim((string) ($body['name'] ?? ''));
        $enabled      = !empty($body['enabled']);
        $triggerTable = trim((string) ($body['trigger_table'] ?? ''));
        $triggerEvent = trim((string) ($body['trigger_event'] ?? ''));
        $conditions   = $body['conditions'] ?? ['type' => 'AND', 'rules' => []];
        $actions      = is_array($body['actions'] ?? null) ? $body['actions'] : [];
        $id           = isset($body['id']) && $body['id'] !== null && $body['id'] !== ''
            ? (string) $body['id']
            : null;

        if ($name === '') {
            admin_err('Name is required.');
        }
        if (!in_array($triggerEvent, ['create', 'update', 'delete'], true)) {
            admin_err('Invalid trigger_event.');
        }

        foreach ((array) $actions as $index => $ruleAction) {
            $actionType = is_array($ruleAction) ? (string) ($ruleAction['type'] ?? '') : '';
            $label = 'Action ' . ($index + 1);
            if ($actionType === 'webhook') {
                $url    = trim((string) ($ruleAction['url'] ?? ''));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
                    echo json_encode([
                        'status' => 'error',
                        'error'  => $label . ' (webhook): a valid http(s) URL is required.',
                    ]);
                    throw ResponseException::sent();
                }
                $allowedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
                if (!in_array(strtoupper((string) ($ruleAction['method'] ?? 'POST')), $allowedMethods, true)) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): method must be one of ' . implode(', ', $allowedMethods) . '.',
                    ]);
                    throw ResponseException::sent();
                }

                foreach (array_keys((array) ($ruleAction['headers'] ?? [])) as $headerName) {
                    $headerName = trim((string) $headerName);
                    if ($headerName === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $headerName) !== 1) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): invalid header name "' . $headerName . '".',
                        ]);
                        throw ResponseException::sent();
                    }
                    if (in_array(strtolower($headerName), AUTO_WEBHOOK_RESERVED_HEADERS, true)) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): header "' . $headerName . '" is reserved.',
                        ]);
                        throw ResponseException::sent();
                    }
                }
                $retries = (int) ($ruleAction['retries'] ?? 0);
                if ($retries < 0 || $retries > 2) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): retries must be between 0 and 2.',
                    ]);
                    throw ResponseException::sent();
                }
            }
            if ($actionType === 'email') {
                $recips = $ruleAction['recipients'] ?? [];
                if (is_string($recips)) {
                    $recips = array_map('trim', explode(',', $recips));
                }
                $recips = array_filter((array) $recips, static fn($row) => trim((string) $row) !== '');
                if ($recips === []) {
                    echo json_encode([
                        'status' => 'error',
                        'error'  => $label . ' (email): at least one recipient is required.',
                    ]);
                    throw ResponseException::sent();
                }
                if (trim((string) ($ruleAction['subject'] ?? '')) === '') {
                    admin_err($label . ' (email): subject is required.');
                }
            }
        }

        $list  = auto_cfg_read();
        $found = false;

        $previousActions = [];
        if ($id !== null) {
            foreach ($list as $item) {
                if (($item['id'] ?? '') === $id) {
                    $previousActions = (array) ($item['actions'] ?? []);
                    break;
                }
            }
        }
        foreach ((array) $actions as $index => $ruleAction) {
            if (!is_array($ruleAction) || ($ruleAction['type'] ?? '') !== 'webhook') {
                continue;
            }
            $submitted = (string) ($ruleAction['secret'] ?? '');
            unset($ruleAction['secret'], $ruleAction['secret_configured']);

            if ($submitted !== '') {
                $ruleAction['secret_enc'] = secret_encrypt($submitted);
            } elseif (empty($ruleAction['secret_clear'])) {
                $previous = is_array($previousActions[$index] ?? null) ? $previousActions[$index] : [];
                if (($previous['type'] ?? '') === 'webhook') {
                    if (($previous['secret_enc'] ?? '') !== '') {
                        $ruleAction['secret_enc'] = $previous['secret_enc'];
                    } elseif (($previous['secret'] ?? '') !== '') {
                        $ruleAction['secret_enc'] = secret_encrypt((string) $previous['secret']);
                    }
                }
            }

            $previous        = is_array($previousActions[$index] ?? null) ? $previousActions[$index] : [];
            $previousEncryptedHeaders = ($previous['type'] ?? '') === 'webhook'
                ? (array) ($previous['headers_enc'] ?? [])
                : [];
            $previousPlain   = ($previous['type'] ?? '') === 'webhook' ? (array) ($previous['headers'] ?? []) : [];
            $encryptedHeaders  = [];
            foreach ((array) ($ruleAction['headers'] ?? []) as $headerName => $headerValue) {
                $headerName = trim((string) $headerName);
                if ($headerName === '') {
                    continue;
                }
                $headerValue = (string) $headerValue;
                if ($headerValue !== '') {
                    $encryptedHeaders[$headerName] = secret_encrypt($headerValue);
                } elseif (($previousEncryptedHeaders[$headerName] ?? '') !== '') {
                    $encryptedHeaders[$headerName] = (string) $previousEncryptedHeaders[$headerName];
                } elseif (($previousPlain[$headerName] ?? '') !== '') {
                    $encryptedHeaders[$headerName] = secret_encrypt((string) $previousPlain[$headerName]);
                } else {
                    $encryptedHeaders[$headerName] = '';
                }
            }
            $ruleAction['headers_enc'] = $encryptedHeaders;
            unset($ruleAction['headers'], $ruleAction['headers_configured'], $ruleAction['secret_clear']);
            $actions[$index] = $ruleAction;
        }

        $entry = [
            'id'            => $id ?? ('auto_' . bin2hex(random_bytes(6))),
            'name'          => $name,
            'enabled'       => $enabled,
            'trigger_table' => $triggerTable,
            'trigger_event' => $triggerEvent,
            'conditions'    => $conditions,
            'actions'       => $actions,
        ];

        if ($id) {
            foreach ($list as &$item) {
                if (($item['id'] ?? '') === $id) {
                    $item  = $entry;
                    $found = true;
                    break;
                }
            }
            unset($item);
        }

        if (!$found) {
            $list[] = $entry;
        }

        auto_cfg_write($list);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}

if ($action === 'automations_delete' && os_request()->method() === 'POST') {
    if (DEMO_MODE) {
        throw HttpException::fromStatus(
            403,
            'Demo mode — writes disabled.',
            ['status' => 'error', 'error' => 'Demo mode — writes disabled.'],
        );
    }
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (string) ($body['id'] ?? '');
        if ($id === '') {
            admin_err('Invalid id.');
        }

        $list    = auto_cfg_read();
        $filtered = array_filter($list, static fn(array $item) => ($item['id'] ?? '') !== $id);
        auto_cfg_write($filtered);
        echo json_encode(['status' => 'success']);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($exception)]);
    }
    throw ResponseException::sent();
}
