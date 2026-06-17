<?php

// api/rag.php — RAG knowledge base query endpoint
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// Auth gate: session + UA enforcement; CSRF on POST; set_time_limit(240) — higher than Ollama timeout
// actions: tags (GET, distinct KB tags), files (GET), query (POST, the RAG question)
// Delegates retrieval/prompt/LLM call to rag_helpers.php and rate-limit/concurrency to rag_throttle.php; returns JSON answer + suggested follow-ups

declare(strict_types=1);

set_time_limit(240); // Set execution limit higher than Ollama timeout to prevent early termination

require_once __DIR__ . '/../../includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Hard session-lifetime + User-Agent enforcement (centralised in session.php).
enforce_session_json();

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

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/rag_helpers.php';
require_once __DIR__ . '/../../includes/rag_throttle.php';

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

// GET: list all documents available for direct selection
if ($action === 'files' && $method === 'GET') {
    try {
        $conn  = db_connect();
        $tRag  = sys_table('rag_files');
        $res   = @pg_query(
            $conn,
            "SELECT id, filename, tags, file_size, length(content) AS char_count FROM {$tRag} ORDER BY filename"
        );
        $files = [];
        if ($res) {
            while ($r = pg_fetch_assoc($res)) {
                $files[] = [
                    'id'         => (int) $r['id'],
                    'filename'   => $r['filename'],
                    'tags'       => pg_text_array_to_php($r['tags'] ?? '{}'),
                    'file_size'  => (int) ($r['file_size'] ?? 0),
                    'char_count' => (int) ($r['char_count'] ?? 0),
                ];
            }
        }
        $cfg = rag_config();
        exit(json_encode([
            'files'             => $files,
            'conversation_turns' => (int) ($cfg['conversation_turns'] ?? 0),
        ]));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['error' => 'Failed to load files.']));
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
        $rawFileIds  = array_map('intval', (array) ($body['file_ids'] ?? []));
        $fileIds     = array_values(array_filter($rawFileIds, fn($id) => $id > 0));
        $pageContext = mb_substr(trim((string) ($body['page_context'] ?? '')), 0, RAG_PAGE_CONTEXT_MAX_CHARS);
        $language    = mb_substr(trim((string) ($body['language'] ?? '')), 0, 10);
        $rawHistory  = (array) ($body['history'] ?? []);

        if ($query === '') {
            http_response_code(400);
            exit(json_encode(['error' => 'Query is required.']));
        }
        if (mb_strlen($query) > 2000) {
            http_response_code(400);
            exit(json_encode(['error' => 'Query too long (max 2000 characters).']));
        }

        $cfg = rag_config();

        $maxTurns = max(0, min(10, (int) ($cfg['conversation_turns'] ?? 0)));
        $history  = [];
        if ($maxTurns > 0 && !empty($rawHistory)) {
            foreach ($rawHistory as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $role    = (string) ($item['role'] ?? '');
                $content = mb_substr(trim((string) ($item['content'] ?? '')), 0, 2000);
                if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                    continue;
                }
                $history[] = ['role' => $role, 'content' => $content];
            }
            $history = array_slice($history, -($maxTurns * 2));
        }

        if (DEMO_MODE) {
            exit(json_encode([
                'answer'  => '[Demo mode] Ollama integration is disabled. This is a placeholder answer.',
                'sources' => [],
            ]));
        }

        // Per-user rate limit: shed excess load before touching the database or Ollama.
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!rag_rate_limit_ok($userId, RAG_RATE_LIMIT_PER_MIN)) {
            http_response_code(429);
            exit(json_encode(['error' => 'Rate limit exceeded. Please wait a moment before asking again.']));
        }

        // Global concurrency cap: fail fast instead of blocking a PHP-FPM worker on Ollama.
        $semaphore = rag_semaphore_acquire(RAG_MAX_CONCURRENT);
        if (RAG_MAX_CONCURRENT > 0 && $semaphore === null) {
            http_response_code(503);
            exit(json_encode(['error' => 'The assistant is busy right now. Please try again in a few seconds.']));
        }
        // Release the slot even if the request aborts or times out mid-generation.
        register_shutdown_function('rag_semaphore_release', $semaphore);

        $conn        = db_connect();
        $limit       = (int) ($cfg['max_context_files'] ?? 3);
        $tagFallback = false;

        if (!empty($fileIds)) {
            $tRag    = sys_table('rag_files');
            $idArray = '{' . implode(',', $fileIds) . '}';
            $res     = @pg_query_params(
                $conn,
                "SELECT id AS file_id, filename, content, tags,
                        NULL::int4 AS chunk_id, -1 AS chunk_index, 'file'::text AS source_type
                 FROM {$tRag}
                 WHERE id = ANY(\$1::int[])
                 ORDER BY filename",
                [$idArray]
            );
            $files = [];
            if ($res) {
                while ($row = pg_fetch_assoc($res)) {
                    $files[] = $row;
                }
            }
        } elseif (!empty($tags)) {
            $files = rag_retrieve($conn, $query, $tags, $limit);
            if (empty($files)) {
                $files       = rag_retrieve($conn, $query, [], $limit);
                $tagFallback = !empty($files);
            }
        } else {
            // No tags and no file IDs selected: do not pull in any documents.
            // The model answers from the page/grid context alone (if provided).
            $files = [];
        }

        $prompt = rag_build_prompt($query, $files, $pageContext, $language, $history);
        $result = rag_call_ollama(
            (string) $cfg['ollama_url'],
            (string) $cfg['ollama_model'],
            $prompt,
            (int) ($cfg['ollama_timeout'] ?? 120),
            (bool) ($cfg['ollama_ssl_verify'] ?? true)
        );

        $seen    = [];
        $sources = [];
        foreach ($files as $f) {
            if (!isset($seen[$f['filename']])) {
                $seen[$f['filename']] = true;
                $sources[] = [
                    'filename' => $f['filename'],
                    'tags'     => pg_text_array_to_php($f['tags'] ?? '{}'),
                ];
            }
        }

        $parsed      = rag_extract_suggestions($result['response']);
        $answer      = $parsed['answer'];
        $suggestions = $parsed['suggestions'];

        rag_log_query($conn, [
            'query'             => $query,
            'tags'              => $tags,
            'matched_files'     => count($files),
            'prompt_tokens'     => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'total_ms'          => $result['total_ms'],
            'model'             => (string) $cfg['ollama_model'],
            'user_id'           => $_SESSION['user_id'] ?? null,
            'prompt_snapshot'   => $prompt,
            'sources'           => $files,
        ]);

        exit(json_encode(['answer' => $answer, 'sources' => $sources, 'tag_fallback' => $tagFallback, 'suggestions' => $suggestions]));
    } catch (Throwable $e) {
        error_log('[api_rag][query] ' . $e->getMessage());
        http_response_code(500);
        exit(json_encode(['error' => 'The assistant failed to answer. Please try again.']));
    }
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action.']));
