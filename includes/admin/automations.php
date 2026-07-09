<?php

declare(strict_types=1);

// includes/admin/automations.php — admin api.php module: workflow automations CRUD (automations_runs,
// automations_list, automations_save, automations_delete).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

// GET: list automation run history
if ($action === 'automations_runs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
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
        echo json_encode(['ok' => true, 'runs' => $runs]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => admin_error_message($e)]);
    }
    exit;
}

// ── Automations CRUD (JSON-backed) ───────────────────────────────────────────

// GET: list all automation rules
if ($action === 'automations_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    try {
        echo json_encode(['ok' => true, 'automations' => auto_cfg_read()]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => admin_error_message($e)]);
    }
    exit;
}

// POST: create or update automation rule
if ($action === 'automations_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $name         = trim((string) ($body['name'] ?? ''));
        $enabled      = !empty($body['enabled']);
        $triggerTable = trim((string) ($body['trigger_table'] ?? ''));
        $triggerEvent = trim((string) ($body['trigger_event'] ?? ''));
        $conditions   = $body['conditions'] ?? ['type' => 'AND', 'rules' => []];
        $actions      = $body['actions'] ?? [];
        $id           = isset($body['id']) && $body['id'] !== null && $body['id'] !== ''
            ? (string) $body['id']
            : null;

        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Name is required.']);
            exit;
        }
        if (!in_array($triggerEvent, ['create', 'update', 'delete'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid trigger_event.']);
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
                    echo json_encode(['ok' => false, 'error' => $label . ' (webhook): a valid http(s) URL is required.']);
                    exit;
                }
                if (!in_array(strtoupper((string) ($act['method'] ?? 'POST')), ['POST', 'PUT'], true)) {
                    echo json_encode(['ok' => false, 'error' => $label . ' (webhook): method must be POST or PUT.']);
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
                    echo json_encode(['ok' => false, 'error' => $label . ' (email): at least one recipient is required.']);
                    exit;
                }
                if (trim((string) ($act['subject'] ?? '')) === '') {
                    echo json_encode(['ok' => false, 'error' => $label . ' (email): subject is required.']);
                    exit;
                }
            }
        }

        $list  = auto_cfg_read();
        $found = false;

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
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => admin_error_message($e)]);
    }
    exit;
}

// POST: delete automation rule
if ($action === 'automations_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (string) ($body['id'] ?? '');
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Invalid id.']);
            exit;
        }

        $list    = auto_cfg_read();
        $filtered = array_filter($list, static fn(array $item) => ($item['id'] ?? '') !== $id);
        auto_cfg_write($filtered);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => admin_error_message($e)]);
    }
    exit;
}
