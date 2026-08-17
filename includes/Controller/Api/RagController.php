<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\BadRequestException;
use App\Exception\ControlFlowException;
use App\Exception\HttpException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;
use App\Service\AppContext;
use Throwable;

final class RagController
{
    public function __construct(private readonly AppContext $context)
    {
    }

    public function handle(): void
    {
        $request = $this->context->request();
        $action  = $request->query('action');
        $method  = $request->method();

        if ($action === 'tags' && $method === 'GET') {
            $this->tags();
        }

        if ($action === 'files' && $method === 'GET') {
            $this->files();
        }

        if ($action === 'query' && $method === 'POST') {
            $this->query();
        }

        throw new BadRequestException('Unknown action.');
    }

    private function tags(): void
    {
        try {
            $conn = $this->context->connection();
            $ragFilesTable = sys_table('rag_files');
            $queryResult  = @pg_query(
                $conn,
                "SELECT DISTINCT unnest(tags) AS tag FROM {$ragFilesTable} ORDER BY tag"
            );
            $tags = [];
            if ($queryResult) {
                while ($row = pg_fetch_row($queryResult)) {
                    if ($row[0] !== null && $row[0] !== '') {
                        $tags[] = $row[0];
                    }
                }
            }
            throw ResponseException::encoded(['tags' => $tags]);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            throw new ServerErrorException('Failed to load tags.');
        }
    }

    private function files(): void
    {
        try {
            $conn  = $this->context->connection();
            $ragFilesTable  = sys_table('rag_files');
            $queryResult   = @pg_query(
                $conn,
                "SELECT id, filename, tags, file_size, length(content) AS char_count"
                . " FROM {$ragFilesTable} ORDER BY filename"
            );
            $files = [];
            if ($queryResult) {
                while ($row = pg_fetch_assoc($queryResult)) {
                    $files[] = [
                        'id'         => (int) $row['id'],
                        'filename'   => $row['filename'],
                        'tags'       => pg_text_array_to_php($row['tags'] ?? '{}'),
                        'file_size'  => (int) ($row['file_size'] ?? 0),
                        'char_count' => (int) ($row['char_count'] ?? 0),
                    ];
                }
            }
            $config = rag_config();
            throw ResponseException::encoded([
                'files'             => $files,
                'conversation_turns' => (int) ($config['conversation_turns'] ?? 0),
            ]);
        } catch (ControlFlowException $signal) {
            throw $signal;
        } catch (Throwable $exception) {
            throw new ServerErrorException('Failed to load files.');
        }
    }

    private function query(): void
    {
        try {
            $body        = json_decode(file_get_contents('php://input'), true) ?? [];
            $query       = trim((string) ($body['query'] ?? ''));
            $tags        = array_values(
                array_filter(
                    array_map('trim', (array) ($body['tags'] ?? [])),
                    fn($tag) => $tag !== ''
                )
            );
            $rawFileIds  = array_map('intval', (array) ($body['file_ids'] ?? []));
            $fileIds     = array_values(array_filter($rawFileIds, fn($id) => $id > 0));
            $pageContext = mb_substr(
                trim((string) ($body['page_context'] ?? '')),
                0,
                RAG_PAGE_CONTEXT_MAX_CHARS
            );
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

            $config = rag_config();

            $maxTurns = max(0, min(10, (int) ($config['conversation_turns'] ?? 0)));
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

            $userId = $this->context->session()->userId();
            if (!rag_rate_limit_ok($userId, RAG_RATE_LIMIT_PER_MIN)) {
                throw HttpException::fromStatus(
                    429,
                    'Rate limit exceeded. Please wait a moment before asking again.'
                );
            }

            $semaphore = rag_semaphore_acquire(RAG_MAX_CONCURRENT);
            if (RAG_MAX_CONCURRENT > 0 && $semaphore === null) {
                throw HttpException::fromStatus(
                    503,
                    'The assistant is busy right now. Please try again in a few seconds.'
                );
            }

            register_shutdown_function('rag_semaphore_release', $semaphore);

            $conn        = $this->context->connection();
            $limit       = (int) ($config['max_context_files'] ?? 3);
            $tagFallback = false;

            if (!empty($fileIds)) {
                $ragFilesTable    = sys_table('rag_files');
                $idArray = '{' . implode(',', $fileIds) . '}';
                $queryResult     = @pg_query_params(
                    $conn,
                    "SELECT id AS file_id, filename, content, tags,
                        NULL::int4 AS chunk_id, -1 AS chunk_index, 'file'::text AS source_type
                 FROM {$ragFilesTable}
                 WHERE id = ANY(\$1::int[])
                 ORDER BY filename",
                    [$idArray]
                );
                $files = [];
                if ($queryResult) {
                    while ($row = pg_fetch_assoc($queryResult)) {
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
                require_once __DIR__ . '/../../config_store.php';
                $schema        = config_get('schema') ?? [];
                $aggregateView = rag_view_aggregate($conn, $schema, $table, $config);
            }

            $prompt = rag_build_prompt($query, $files, $pageContext, $language, $history, $aggregateView);
            $result = rag_call_ollama(
                (string) $config['ollama_url'],
                (string) $config['ollama_model'],
                $prompt,
                (int) ($config['ollama_timeout'] ?? 120),
                (bool) ($config['ollama_ssl_verify'] ?? true),
                secret_decrypt((string) ($config['ollama_api_key_enc'] ?? ''))
            );

            $seen    = [];
            $sources = [];
            foreach ($files as $file) {
                if (!isset($seen[$file['filename']])) {
                    $seen[$file['filename']] = true;
                    $sources[] = [
                        'filename' => $file['filename'],
                        'tags'     => pg_text_array_to_php($file['tags'] ?? '{}'),
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
                'model'             => (string) $config['ollama_model'],
                'user_id'           => $this->context->session()->get('user_id'),
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
        } catch (Throwable $exception) {
            error_log('[api_rag][query] ' . $exception->getMessage());
            throw new ServerErrorException('The assistant failed to answer. Please try again.');
        }
    }
}
