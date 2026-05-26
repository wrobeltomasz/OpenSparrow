<?php

// api_rag.php — RAG knowledge base query endpoint
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

set_time_limit(240); // Set execution limit higher than Ollama timeout to prevent early termination

require_once __DIR__ . '/includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > SESSION_MAX_LIFETIME) {
    session_destroy();
    http_response_code(401);
    exit(json_encode(['error' => 'Session expired']));
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        exit(json_encode(['error' => 'CSRF token mismatch.']));
    }
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/api_helpers.php';
require_once __DIR__ . '/includes/rag_helpers.php';

// GET: distinct tags available in the knowledge base
if ($action === 'tags' && $method === 'GET') {
    try {
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $res  = @pg_query($conn, "SELECT DISTINCT unnest(tags) AS tag FROM {$tRag} ORDER BY tag");
        $tags = [];
        if ($res) {
            while ($r = pg_fetch_row($res)) {
                if ($r[0] !== null && $r[0] !== '') {
                    $tags[] = $r[0];
                }
            }
        }
        exit(json_encode(['tags' => $tags]));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['error' => 'Failed to load tags.']));
    }
}

// POST: run a RAG query against the knowledge base
if ($action === 'query' && $method === 'POST') {
    try {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $query       = trim((string) ($body['query'] ?? ''));
        $tags        = array_values(
            array_filter(
                array_map('trim', (array) ($body['tags'] ?? [])),
                fn($t) => $t !== ''
            )
        );
        $pageContext = mb_substr(trim((string) ($body['page_context'] ?? '')), 0, 3000);
        $language    = mb_substr(trim((string) ($body['language'] ?? '')), 0, 10);

        if ($query === '') {
            http_response_code(400);
            exit(json_encode(['error' => 'Query is required.']));
        }
        if (mb_strlen($query) > 2000) {
            http_response_code(400);
            exit(json_encode(['error' => 'Query too long (max 2000 characters).']));
        }

        $cfg = rag_config();

        if (DEMO_MODE) {
            exit(json_encode([
                'answer'  => '[Demo mode] Ollama integration is disabled. This is a placeholder answer.',
                'sources' => [],
            ]));
        }

        $conn  = db_connect();
        $limit = (int) ($cfg['max_context_files'] ?? 3);
        $files = rag_retrieve($conn, $query, $tags, $limit);

        $prompt = rag_build_prompt($query, $files, $pageContext, $language);
        $result = rag_call_ollama(
            (string) $cfg['ollama_url'],
            (string) $cfg['ollama_model'],
            $prompt,
            (int) ($cfg['ollama_timeout'] ?? 120),
            (bool) ($cfg['ollama_ssl_verify'] ?? true)
        );

        $sources = array_map(fn($f) => [
            'filename' => $f['filename'],
            'tags'     => pg_text_array_to_php($f['tags'] ?? '{}'),
        ], $files);

        rag_log_query($conn, [
            'query'             => $query,
            'tags'              => $tags,
            'matched_files'     => count($files),
            'prompt_tokens'     => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'total_ms'          => $result['total_ms'],
            'model'             => (string) $cfg['ollama_model'],
            'user_id'           => $_SESSION['user_id'] ?? null,
        ]);

        exit(json_encode(['answer' => $result['response'], 'sources' => $sources]));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['error' => $e->getMessage()]));
    }
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action.']));
