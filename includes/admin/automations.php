<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// includes/admin/automations.php — admin api.php module: workflow automations CRUD (automations_runs,
// automations_list, automations_save, automations_delete).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// Rule-engine helpers: AUTO_WEBHOOK_RESERVED_HEADERS (save validation) and the
// secret encryption convention shared with the runtime.
require_once __DIR__ . '/../automations.php';
require_once __DIR__ . '/../crypto.php';

// GET: list automation run history
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// ── Automations CRUD (JSON-backed) ───────────────────────────────────────────

/**
 * Strip webhook credentials out of rules before they leave the server: the signing
 * secret and every custom header value. The editor only ever learns *whether* each
 * is set, never its value — mirrors the ollama_api_key_configured convention in
 * includes/admin/rag.php. Header names stay visible so the editor can list them.
 */
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
            // Values blanked; the editor renders the names and a "saved" placeholder.
            // Cast to object: an empty PHP array encodes as JSON `[]`, which reaches
            // the editor as a JS Array — string keys assigned to it are dropped by
            // JSON.stringify() on save, silently losing every header the user adds.
            $act['headers']            = (object) array_map(static fn(): string => '', $configured);
            $act['headers_configured'] = (object) $configured;
            $act['payload']            = (object) ((array) ($act['payload'] ?? []));

            $rule['actions'][$i] = $act;
        }
    }
    unset($rule);
    return $rules;
}

// GET: list all automation rules
if ($action === 'automations_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        echo json_encode(['status' => 'success', 'automations' => auto_redact_secrets(auto_cfg_read())]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// POST: create or update automation rule
if ($action === 'automations_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (DEMO_MODE) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
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
            echo json_encode(['status' => 'error', 'error' => 'Name is required.']);
            exit;
        }
        if (!in_array($triggerEvent, ['create', 'update', 'delete'], true)) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid trigger_event.']);
            exit;
        }

        // Per-action validation for outbound action types (webhook, email).
        foreach ((array) $actions as $idx => $act) {
            $aType = is_array($act) ? (string) ($act['type'] ?? '') : '';
            $label = 'Action ' . ($idx + 1);
            if ($aType === 'webhook') {
                $url    = trim((string) ($act['url'] ?? ''));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
                    echo json_encode(['status' => 'error', 'error' => $label . ' (webhook): a valid http(s) URL is required.']);
                    exit;
                }
                $allowedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
                if (!in_array(strtoupper((string) ($act['method'] ?? 'POST')), $allowedMethods, true)) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): method must be one of ' . implode(', ', $allowedMethods) . '.',
                    ]);
                    exit;
                }
                // Header names must be RFC 7230 tokens, and the rule may not
                // override headers the transport controls (signature, content type…).
                foreach (array_keys((array) ($act['headers'] ?? [])) as $hName) {
                    $hName = trim((string) $hName);
                    if ($hName === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $hName) !== 1) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): invalid header name "' . $hName . '".',
                        ]);
                        exit;
                    }
                    if (in_array(strtolower($hName), AUTO_WEBHOOK_RESERVED_HEADERS, true)) {
                        echo json_encode([
                            'ok'    => false,
                            'error' => $label . ' (webhook): header "' . $hName . '" is reserved.',
                        ]);
                        exit;
                    }
                }
                $retries = (int) ($act['retries'] ?? 0);
                if ($retries < 0 || $retries > 2) {
                    echo json_encode([
                        'ok'    => false,
                        'error' => $label . ' (webhook): retries must be between 0 and 2.',
                    ]);
                    exit;
                }
            }
            if ($aType === 'email') {
                $recips = $act['recipients'] ?? [];
                if (is_string($recips)) {
                    $recips = array_map('trim', explode(',', $recips));
                }
                $recips = array_filter((array) $recips, static fn($r) => trim((string) $r) !== '');
                if ($recips === []) {
                    echo json_encode(['status' => 'error', 'error' => $label . ' (email): at least one recipient is required.']);
                    exit;
                }
                if (trim((string) ($act['subject'] ?? '')) === '') {
                    echo json_encode(['status' => 'error', 'error' => $label . ' (email): subject is required.']);
                    exit;
                }
            }
        }

        $list  = auto_cfg_read();
        $found = false;

        // Webhook secrets never travel back to the editor (see auto_redact_secrets),
        // so a blank field means "keep what is stored", not "clear it". Match the
        // previous version of this rule by action position to carry the value over.
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
                    // Re-encrypt legacy plaintext secrets on the first re-save.
                    if (($prev['secret_enc'] ?? '') !== '') {
                        $act['secret_enc'] = $prev['secret_enc'];
                    } elseif (($prev['secret'] ?? '') !== '') {
                        $act['secret_enc'] = secret_encrypt((string) $prev['secret']);
                    }
                }
            }
            // Header values are credentials too. Same rule as the secret: a blank
            // value keeps whatever is stored under that name, so the editor can show
            // the header list without ever receiving the values. Renaming a header
            // therefore drops its value — it has to be retyped.
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
                    // Re-encrypt a legacy plaintext header on the first re-save.
                    $headersEnc[$hName] = secret_encrypt((string) $prevPlain[$hName]);
                } else {
                    // Name declared in the editor, no value yet.
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
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// POST: delete automation rule
if ($action === 'automations_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (DEMO_MODE) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (string) ($body['id'] ?? '');
        if ($id === '') {
            echo json_encode(['status' => 'error', 'error' => 'Invalid id.']);
            exit;
        }

        $list    = auto_cfg_read();
        $filtered = array_filter($list, static fn(array $item) => ($item['id'] ?? '') !== $id);
        auto_cfg_write($filtered);
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
