<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

set_time_limit(240);

require_once __DIR__ . '/../../includes/bootstrap.php';

os_api_bootstrap(['connect' => false]);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

require_once __DIR__ . '/../../includes/rag_helpers.php';
require_once __DIR__ . '/../../includes/rag_throttle.php';

if ($action === 'tags' && $method === 'GET') {
    try {
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $res  = @pg_query($conn, "SELECT DISTINCT unnest(tags) AS tag FROM {$tRag} ORDER BY tag");
        $tags = [];
        if ($res) {
            while ($row = pg_fetch_row($res)) {
                if ($row[0] !== null && $row[0] !== '') {
                    $tags[] = $row[0];
                }
            }
        }
        throw ResponseException::encoded(['tags' => $tags]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        throw new ServerErrorException('Failed to load tags.');
    }
}

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
            while ($row = pg_fetch_assoc($res)) {
                $files[] = [
                    'id'         => (int) $row['id'],
                    'filename'   => $row['filename'],
                    'tags'       => pg_text_array_to_php($row['tags'] ?? '{}'),
                    'file_size'  => (int) ($row['file_size'] ?? 0),
                    'char_count' => (int) ($row['char_count'] ?? 0),
                ];
            }
        }
        $cfg = rag_config();
        throw ResponseException::encoded([
            'files'             => $files,
            'conversation_turns' => (int) ($cfg['conversation_turns'] ?? 0),
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        throw new ServerErrorException('Failed to load files.');
    }
}

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
        $table       = mb_substr(trim((string) ($body['table'] ?? '')), 0, 255);
        $language    = mb_substr(trim((string) ($body['language'] ?? '')), 0, 10);
        $rawHistory  = (array) ($body['history'] ?? []);

        if ($query === '') {
            throw new BadRequestException('Query is required.');
        }

        if ($table !== '') {
            require_table_access($table);
        }
        if (mb_strlen($query) > 2000) {
            throw new BadRequestException('Query too long (max 2000 characters).');
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
            throw ResponseException::encoded([
                'answer'  => '[Demo mode] Ollama integration is disabled. This is a placeholder answer.',
                'sources' => [],
            ]);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!rag_rate_limit_ok($userId, RAG_RATE_LIMIT_PER_MIN)) {
            throw HttpException::fromStatus(429, 'Rate limit exceeded. Please wait a moment before asking again.');
        }

        $semaphore = rag_semaphore_acquire(RAG_MAX_CONCURRENT);
        if (RAG_MAX_CONCURRENT > 0 && $semaphore === null) {
            throw HttpException::fromStatus(503, 'The assistant is busy right now. Please try again in a few seconds.');
        }

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
            $files = [];
        }

        $aggregateView = '';
        if ($table !== '') {
            require_once __DIR__ . '/../../includes/config_store.php';
            $schema        = config_get('schema') ?? [];
            $aggregateView = rag_view_aggregate($conn, $schema, $table, $cfg);
        }

        $prompt = rag_build_prompt($query, $files, $pageContext, $language, $history, $aggregateView);
        $result = rag_call_ollama(
            (string) $cfg['ollama_url'],
            (string) $cfg['ollama_model'],
            $prompt,
            (int) ($cfg['ollama_timeout'] ?? 120),
            (bool) ($cfg['ollama_ssl_verify'] ?? true),
            secret_decrypt((string) ($cfg['ollama_api_key_enc'] ?? ''))
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
        $answer      = rag_strip_context_leaks($parsed['answer']);
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

        throw ResponseException::encoded([
            'answer'       => $answer,
            'sources'      => $sources,
            'tag_fallback' => $tagFallback,
            'suggestions'  => $suggestions,

            'no_answer'    => rag_is_no_answer($answer, $suggestions),
        ]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        error_log('[api_rag][query] ' . $e->getMessage());
        throw new ServerErrorException('The assistant failed to answer. Please try again.');
    }
}

throw new BadRequestException('Unknown action.');
