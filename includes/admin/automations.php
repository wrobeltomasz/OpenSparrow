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

if ($action === 'automations_runs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn  = db_connect();
        $tRuns = sys_table('automation_runs');
        $ruleId = trim((string) ($_GET['rule_id'] ?? ''));

        if ($ruleId !== '') {
            $res = @pg_query_params(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $tRuns WHERE rule_id = \$1 ORDER BY executed_at DESC LIMIT 100",
                [$ruleId]
            );
        } else {
            $res = @pg_query(
                $conn,
                "SELECT id, rule_id, rule_name, table_name, record_id, event, status, error_msg, executed_at
                 FROM $tRuns ORDER BY executed_at DESC LIMIT 200"
            );
        }

        $runs = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $runs[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'runs' => $runs]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}

function auto_redact_secrets(array $rules): array
{
    foreach ($rules as &$rule) {
        foreach ((array) ($rule['actions'] ?? []) as $i => $act) {
            if (!is_array($act) || ($act['type'] ?? '') !== 'webhook') {
                continue;
            }
            $hasSecret = ($act['secret_enc'] ?? '') !== '' || ($act['secret'] ?? '') !== '';
            unset($act['secret_enc'], $act['secret']);
            $act['secret_configured'] = $hasSecret;

            $names      = array_keys((array) ($act['headers_enc'] ?? []));
            $legacy     = (array) ($act['headers'] ?? []);
            $configured = [];
            foreach ($names as $name) {
                $configured[(string) $name] = (string) $act['headers_enc'][$name] !== '';
            }
            foreach ($legacy as $name => $val) {
                $configured[(string) $name] ??= (string) $val !== '';
            }
            unset($act['headers_enc']);

            $act['headers']            = (object) array_map(static fn(): string => '', $configured);
            $act['headers_configured'] = (object) $configured;
            $act['payload']            = (object) ((array) ($act['payload'] ?? []));

            $rule['actions'][$i] = $act;
        }
    }
    unset($rule);
    return $rules;
}

if ($action === 'automations_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        echo json_encode(['status' => 'success', 'automations' => auto_redact_secrets(auto_cfg_read())]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}

if ($action === 'automations_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

        foreach ((array) $actions as $idx => $act) {
            $aType = is_array($act) ? (string) ($act['type'] ?? '') : '';
            $label = 'Action ' . ($idx + 1);
            if ($aType === 'webhook') {
                $url    = trim((string) ($act['url'] ?? ''));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
                    echo json_encode([
                        'status' => 'error',
                        'error'  => $label . ' (webhook): a valid http(s) URL is required.',
                    ]);
                    throw ResponseException::sent();
                }
                $allowedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
                if (!in_array(strtoupper((string) ($act['method'] ?? 'POST')), $allowedMethods, true)) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): method must be one of ' . implode(', ', $allowedMethods) . '.',
                    ]);
                    throw ResponseException::sent();
                }

                foreach (array_keys((array) ($act['headers'] ?? [])) as $hName) {
                    $hName = trim((string) $hName);
                    if ($hName === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $hName) !== 1) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): invalid header name "' . $hName . '".',
                        ]);
                        throw ResponseException::sent();
                    }
                    if (in_array(strtolower($hName), AUTO_WEBHOOK_RESERVED_HEADERS, true)) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): header "' . $hName . '" is reserved.',
                        ]);
                        throw ResponseException::sent();
                    }
                }
                $retries = (int) ($act['retries'] ?? 0);
                if ($retries < 0 || $retries > 2) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): retries must be between 0 and 2.',
                    ]);
                    throw ResponseException::sent();
                }
            }
            if ($aType === 'email') {
                $recips = $act['recipients'] ?? [];
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
                if (trim((string) ($act['subject'] ?? '')) === '') {
                    admin_err($label . ' (email): subject is required.');
                }
            }
        }

        $list  = auto_cfg_read();
        $found = false;

        $prevActions = [];
        if ($id !== null) {
            foreach ($list as $item) {
                if (($item['id'] ?? '') === $id) {
                    $prevActions = (array) ($item['actions'] ?? []);
                    break;
                }
            }
        }
        foreach ((array) $actions as $idx => $act) {
            if (!is_array($act) || ($act['type'] ?? '') !== 'webhook') {
                continue;
            }
            $submitted = (string) ($act['secret'] ?? '');
            unset($act['secret'], $act['secret_configured']);

            if ($submitted !== '') {
                $act['secret_enc'] = secret_encrypt($submitted);
            } elseif (empty($act['secret_clear'])) {
                $prev = is_array($prevActions[$idx] ?? null) ? $prevActions[$idx] : [];
                if (($prev['type'] ?? '') === 'webhook') {
                    if (($prev['secret_enc'] ?? '') !== '') {
                        $act['secret_enc'] = $prev['secret_enc'];
                    } elseif (($prev['secret'] ?? '') !== '') {
                        $act['secret_enc'] = secret_encrypt((string) $prev['secret']);
                    }
                }
            }

            $prev        = is_array($prevActions[$idx] ?? null) ? $prevActions[$idx] : [];
            $prevEnc     = ($prev['type'] ?? '') === 'webhook' ? (array) ($prev['headers_enc'] ?? []) : [];
            $prevPlain   = ($prev['type'] ?? '') === 'webhook' ? (array) ($prev['headers'] ?? []) : [];
            $headersEnc  = [];
            foreach ((array) ($act['headers'] ?? []) as $hName => $hVal) {
                $hName = trim((string) $hName);
                if ($hName === '') {
                    continue;
                }
                $hVal = (string) $hVal;
                if ($hVal !== '') {
                    $headersEnc[$hName] = secret_encrypt($hVal);
                } elseif (($prevEnc[$hName] ?? '') !== '') {
                    $headersEnc[$hName] = (string) $prevEnc[$hName];
                } elseif (($prevPlain[$hName] ?? '') !== '') {
                    $headersEnc[$hName] = secret_encrypt((string) $prevPlain[$hName]);
                } else {
                    $headersEnc[$hName] = '';
                }
            }
            $act['headers_enc'] = $headersEnc;
            unset($act['headers'], $act['headers_configured'], $act['secret_clear']);
            $actions[$idx] = $act;
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}

if ($action === 'automations_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    throw ResponseException::sent();
}
