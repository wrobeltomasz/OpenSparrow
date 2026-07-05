<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// rag_helpers.php — RAG (Retrieval-Augmented Generation) core logic
// Implements: config loading, PostgreSQL text array conversion, document chunking, full-text search retrieval (tsvector/tsquery), prompt building (with page context and conversation history), Ollama API calls (cURL), query logging, and suggestion extraction (FOLLOW_UP: markers)
// Supports hybrid chunk-level and file-level retrieval; uses sys_table('rag_*') for system tables; respects chunking settings from rag.json
// Called by api_rag.php

declare(strict_types=1);

function rag_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'ollama_url'         => get_env('OLLAMA_URL', 'http://localhost:11434'),
        'ollama_model'       => get_env('OLLAMA_MODEL', 'llama3'),
        'max_context_files'  => 3,
        'max_file_size_mb'   => 10,
        'ollama_timeout'     => 120,
        'ollama_ssl_verify'  => true,
        'chunk_size'         => 1000,
        'chunk_overlap'      => 200,
        'use_chunks'         => true,
        'conversation_turns' => 0,
        'chat_enabled'       => true,
    ];
    $path = __DIR__ . '/../config/rag.json';
    if (!is_file($path)) {
        $cfg = $defaults;
        return $cfg;
    }
    $raw = @json_decode((string) file_get_contents($path), true);
    $cfg = is_array($raw) ? array_merge($defaults, $raw) : $defaults;
    return $cfg;
}

function pg_text_array_to_php(string $pgArray): array
{
    $pgArray = trim($pgArray);
    if ($pgArray === '' || $pgArray === '{}') {
        return [];
    }
    $inner = substr($pgArray, 1, -1);
    if ($inner === '') {
        return [];
    }
    // Explicit '\\' escape: PostgreSQL array output escapes quotes with a
    // backslash, and PHP 8.4 deprecates omitting the $escape parameter.
    return str_getcsv($inner, ',', '"', '\\');
}

function php_array_to_pg_text(array $arr): string
{
    if (empty($arr)) {
        return '{}';
    }
    $escaped = array_map(function (string $s): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}

function rag_chunk_text(string $text, int $chunkSize = 1000, int $overlap = 200): array
{
    $text = preg_replace('/\r\n|\r/', "\n", trim($text));
    if ($text === '') {
        return [];
    }

    $paragraphs = preg_split('/\n{2,}/', $text);
    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs)));

    $chunks  = [];
    $current = '';

    foreach ($paragraphs as $para) {
        if (mb_strlen($para) > $chunkSize) {
            if ($current !== '') {
                $chunks[]  = $current;
                $tailStart = max(0, mb_strlen($current) - $overlap);
                $current   = mb_substr($current, $tailStart);
            }
            $sentences = preg_split('/(?<=[.!?…])\s+/', $para, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > $chunkSize) {
                    $chunks[]  = $current;
                    $tailStart = max(0, mb_strlen($current) - $overlap);
                    $current   = mb_substr($current, $tailStart) . ' ' . $sentence;
                } else {
                    $current .= ($current !== '' ? ' ' : '') . $sentence;
                }
            }
            continue;
        }

        $sep = $current !== '' ? "\n\n" : '';
        if ($current !== '' && mb_strlen($current) + mb_strlen($sep . $para) > $chunkSize) {
            $chunks[]    = $current;
            $tailStart   = max(0, mb_strlen($current) - $overlap);
            $overlapText = mb_substr($current, $tailStart);
            $current     = $overlapText !== '' ? $overlapText . "\n\n" . $para : $para;
        } else {
            $current .= $sep . $para;
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return array_values(array_filter(array_map('trim', $chunks)));
}


function rag_store_chunks(\PgSql\Connection $conn, int $fileId, string $content, array $cfg): int
{
    $tChunks   = sys_table('rag_chunks');
    $chunkSize = (int) ($cfg['chunk_size'] ?? 1000);
    $overlap   = (int) ($cfg['chunk_overlap'] ?? 200);

    $chunks = rag_chunk_text($content, $chunkSize, $overlap);
    if (empty($chunks)) {
        return 0;
    }

    @pg_query_params($conn, "DELETE FROM {$tChunks} WHERE file_id = \$1", [$fileId]);

    $stored = 0;
    foreach ($chunks as $i => $chunk) {
        $res = @pg_query_params(
            $conn,
            "INSERT INTO {$tChunks} (file_id, chunk_index, content) VALUES (\$1, \$2, \$3)",
            [$fileId, $i, $chunk]
        );
        if ($res) {
            $stored++;
        }
    }

    return $stored;
}

// Shared full-text ranking expression: derives a tsquery from query parameter $1,
// OR-ing its lexemes and falling back to plainto_tsquery for unindexable input.
function rag_tsquery_expr(): string
{
    return "(SELECT COALESCE(string_agg(lexeme, ' | ')::tsquery, plainto_tsquery('english', \$1))"
        . " FROM unnest(to_tsvector('english', \$1)))";
}

function rag_retrieve(\PgSql\Connection $conn, string $query, array $tags, int $limit = 3): array
{
    $cfg     = rag_config();
    $limit   = max(1, min(10, $limit ?: (int) ($cfg['max_context_files'] ?? 3)));
    $tRag    = sys_table('rag_files');
    $tChunks = sys_table('rag_chunks');
    $query   = trim($query);

    if ($query === '') {
        return [];
    }

    $useChunks = (bool) ($cfg['use_chunks'] ?? true);
    $tsq       = rag_tsquery_expr();

    static $chunksExist = null;
    if ($chunksExist === null) {
        $chunksExist = (bool) @pg_query($conn, "SELECT 1 FROM {$tChunks} LIMIT 0");
    }

    $res = false;

    if ($useChunks && $chunksExist) {
        if (!empty($tags)) {
            $tagLiteral = php_array_to_pg_text(array_values($tags));
            $sql = "SELECT content, filename, tags, file_id, chunk_id, chunk_index, source_type FROM (
                SELECT c.content, f.filename, f.tags,
                       f.id AS file_id, c.id AS chunk_id, c.chunk_index,
                       'chunk'::text AS source_type,
                       ts_rank(to_tsvector('english', c.content), {$tsq}) AS rank
                FROM {$tChunks} c JOIN {$tRag} f ON f.id = c.file_id
                WHERE f.tags && \$2::text[]
                  AND to_tsvector('english', c.content) @@ {$tsq}
                UNION ALL
                SELECT f.content, f.filename, f.tags,
                       f.id AS file_id, NULL::int4 AS chunk_id, -1 AS chunk_index,
                       'file'::text AS source_type,
                       ts_rank(to_tsvector('english', f.content), {$tsq}) AS rank
                FROM {$tRag} f
                WHERE NOT EXISTS (SELECT 1 FROM {$tChunks} cx WHERE cx.file_id = f.id)
                  AND f.tags && \$2::text[]
                  AND to_tsvector('english', f.content) @@ {$tsq}
            ) combined ORDER BY rank DESC LIMIT \$3";
            $res = @pg_query_params($conn, $sql, [$query, $tagLiteral, $limit]);
        } else {
            $sql = "SELECT content, filename, tags, file_id, chunk_id, chunk_index, source_type FROM (
                SELECT c.content, f.filename, f.tags,
                       f.id AS file_id, c.id AS chunk_id, c.chunk_index,
                       'chunk'::text AS source_type,
                       ts_rank(to_tsvector('english', c.content), {$tsq}) AS rank
                FROM {$tChunks} c JOIN {$tRag} f ON f.id = c.file_id
                WHERE to_tsvector('english', c.content) @@ {$tsq}
                UNION ALL
                SELECT f.content, f.filename, f.tags,
                       f.id AS file_id, NULL::int4 AS chunk_id, -1 AS chunk_index,
                       'file'::text AS source_type,
                       ts_rank(to_tsvector('english', f.content), {$tsq}) AS rank
                FROM {$tRag} f
                WHERE NOT EXISTS (SELECT 1 FROM {$tChunks} cx WHERE cx.file_id = f.id)
                  AND to_tsvector('english', f.content) @@ {$tsq}
            ) combined ORDER BY rank DESC LIMIT \$2";
            $res = @pg_query_params($conn, $sql, [$query, $limit]);
        }
    } else {
        if (!empty($tags)) {
            $tagLiteral = php_array_to_pg_text(array_values($tags));
            $sql = "SELECT f.id AS file_id, NULL::int4 AS chunk_id, -1 AS chunk_index,
                           'file'::text AS source_type, f.filename, f.content, f.tags
                    FROM {$tRag} f
                    WHERE f.tags && \$2::text[]
                      AND to_tsvector('english', f.content) @@ {$tsq}
                    ORDER BY ts_rank(to_tsvector('english', f.content), {$tsq}) DESC
                    LIMIT \$3";
            $res = @pg_query_params($conn, $sql, [$query, $tagLiteral, $limit]);
        } else {
            $sql = "SELECT f.id AS file_id, NULL::int4 AS chunk_id, -1 AS chunk_index,
                           'file'::text AS source_type, f.filename, f.content, f.tags
                    FROM {$tRag} f
                    WHERE to_tsvector('english', f.content) @@ {$tsq}
                    ORDER BY ts_rank(to_tsvector('english', f.content), {$tsq}) DESC
                    LIMIT \$2";
            $res = @pg_query_params($conn, $sql, [$query, $limit]);
        }
    }

    if (!$res) {
        return [];
    }

    $files = [];
    while ($row = pg_fetch_assoc($res)) {
        $files[] = $row;
    }
    return $files;
}

function rag_build_prompt(string $query, array $files, string $pageContext = '', string $language = '', array $history = []): string
{
    $langHint = $language !== '' ? "Respond in the language with locale code: {$language}.\n" : '';
    $ctxBlock = $pageContext !== '' ? "Current page data:\n{$pageContext}\n\n" : '';
    $noAnswer = 'I cannot find this information in the provided context.';

    $preamble = "You are a strict technical assistant for the OpenSparrow platform. "
        . "Your only task is to answer the user's question using EXCLUSIVELY"
        . " the provided context below. The context may consist of BOTH current page data"
        . " (a live table grid with record IDs) AND documentation chunks from files.\n\n"
        . "CRITICAL RULES:\n"
        . "1. Rely ONLY on the clear facts directly mentioned in the provided context"
        . " (current page data and/or documentation chunks). \n"
        . "2. Do NOT use your own pre-trained knowledge, do not assume, and do not extrapolate.\n"
        . "3. If the provided context does not contain the exact answer to the question,"
        . " you must reply with this exact phrase and nothing else: \"{$noAnswer}\"\n"
        . "4. After your answer, on a new line output exactly (no extra text on that line):\n"
        . "   FOLLOW_UP: [\"short question 1?\", \"short question 2?\"]\n"
        . "   List 2-3 brief follow-up questions the user might naturally ask next based on your answer."
        . " If you replied with \"{$noAnswer}\", output: FOLLOW_UP: []\n"
        . "5. When your answer references a specific data record that is explicitly identified in the context"
        . " (table name and numeric id both present), append a reference marker at the end of that sentence:\n"
        . "   Format: [View: table_name:id]\n"
        . "   Example: The contract was signed on 2025-03-01. [View: contracts:42]\n"
        . "6. NEVER invent, guess, or assume table names or record identifiers."
        . " Only include a [View: ...] marker when both the exact table name and the exact numeric id"
        . " are explicitly stated in the provided context.\n"
        . $langHint;

    $historyBlock  = '';
    $questionLabel = 'Question';
    if (!empty($history)) {
        $lines = [];
        foreach ($history as $turn) {
            $role    = $turn['role'] === 'assistant' ? 'Assistant' : 'User';
            $lines[] = $role . ': ' . $turn['content'];
        }
        $historyBlock  = "\nConversation history:\n" . implode("\n", $lines) . "\n";
        $questionLabel = 'Current question';
    }

    if (empty($files)) {
        $context = $ctxBlock !== '' ? $ctxBlock : "(No context available.)\n";
        return "{$preamble}\nContext:\n{$context}{$historyBlock}\n{$questionLabel}:\n{$query}";
    }

    $context = $ctxBlock;
    foreach ($files as $i => $file) {
        $context .= '--- Document ' . ($i + 1) . ': ' . $file['filename'] . " ---\n"
            . $file['content'] . "\n\n";
    }

    return "{$preamble}\nContext:\n{$context}{$historyBlock}\n{$questionLabel}:\n{$query}";
}

function rag_extract_suggestions(string $response): array
{
    // The FOLLOW_UP marker can appear anywhere — including inline on the same line
    // as the answer when the model ignores the "new line" instruction. Match it
    // regardless of position so the block is ALWAYS stripped and never leaks into
    // the visible answer, even when its payload is malformed.
    if (!preg_match('/FOLLOW_UP:/', $response)) {
        return ['answer' => trim($response), 'suggestions' => []];
    }

    // Everything before the first marker is the answer; everything after is the block.
    [$answer, $block] = array_pad(preg_split('/FOLLOW_UP:/', $response, 2), 2, '');
    $answer = trim((string) $answer);
    $block  = trim((string) $block);

    $suggestions = [];

    if (preg_match('/\[.*\]/s', $block, $m)) {
        // Preferred format: a JSON array — FOLLOW_UP: ["q1", "q2"]
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) {
            $suggestions = $parsed;
        } elseif (preg_match_all('/"([^"]*)"/', $m[0], $qm)) {
            // Malformed JSON (e.g. a stray quote): salvage the quoted strings.
            $suggestions = $qm[1];
        }
    } else {
        // Plain text, bullet list, or numbered list fallback.
        foreach (preg_split('/\r?\n/', $block) as $line) {
            $suggestions[] = preg_replace('/^(?:[-*]|\d+[.)])\s*/', '', trim((string) $line));
        }
    }

    // Keep only non-empty entries that contain at least one letter (drops salvage
    // noise such as a lone ", " left behind by malformed JSON), capped at three.
    $suggestions = array_slice(
        array_values(array_filter(
            array_map('trim', array_map('strval', $suggestions)),
            fn($q) => $q !== '' && preg_match('/\p{L}/u', $q) === 1
        )),
        0,
        3
    );

    return ['answer' => $answer, 'suggestions' => $suggestions];
}

function rag_call_ollama(string $ollamaUrl, string $model, string $prompt, int $timeout = 120, bool $sslVerify = true): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for Ollama integration.');
    }

    $url     = rtrim($ollamaUrl, '/') . '/api/generate';
    $payload = json_encode(['model' => $model, 'prompt' => $prompt, 'stream' => false]);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_SSL_VERIFYPEER  => $sslVerify,
        CURLOPT_SSL_VERIFYHOST  => $sslVerify ? 2 : 0,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Ollama unreachable: ' . $curlErr);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Ollama returned invalid response.');
    }
    if (!empty($data['error'])) {
        throw new RuntimeException('Ollama error: ' . $data['error']);
    }
    if (!isset($data['response'])) {
        throw new RuntimeException('Unexpected Ollama response format.');
    }

    return [
        'response'          => (string) $data['response'],
        'prompt_tokens'     => (int) ($data['prompt_eval_count'] ?? 0),
        'completion_tokens' => (int) ($data['eval_count'] ?? 0),
        'total_ms'          => (int) round(($data['total_duration'] ?? 0) / 1_000_000),
    ];
}

function rag_log_query(\PgSql\Connection $conn, array $data): void
{
    $tRagQueries      = sys_table('rag_queries');
    $tRagQuerySources = sys_table('rag_query_sources');
    $tags             = php_array_to_pg_text(array_values($data['tags'] ?? []));

    static $hasPromptCol = null;
    if ($hasPromptCol === null) {
        $colRes      = @pg_query($conn, "SELECT 1 FROM information_schema.columns WHERE table_name = 'spw_rag_queries' AND column_name = 'prompt_snapshot' LIMIT 1");
        $hasPromptCol = ($colRes && pg_num_rows($colRes) > 0);
    }

    $baseParams = [
        mb_substr((string) ($data['query'] ?? ''), 0, 2000),
        $tags,
        (int) ($data['matched_files'] ?? 0),
        (int) ($data['prompt_tokens'] ?? 0),
        (int) ($data['completion_tokens'] ?? 0),
        (int) ($data['total_ms'] ?? 0),
        mb_substr((string) ($data['model'] ?? ''), 0, 255),
        isset($data['user_id']) ? (int) $data['user_id'] : null,
    ];

    if ($hasPromptCol) {
        $baseParams[] = mb_substr((string) ($data['prompt_snapshot'] ?? ''), 0, 50000) ?: null;
        $qRes = @pg_query_params(
            $conn,
            "INSERT INTO {$tRagQueries}
                (query, tags, matched_files, prompt_tokens, completion_tokens, total_ms, model, user_id, prompt_snapshot)
             VALUES (\$1, \$2::text[], \$3, \$4, \$5, \$6, \$7, \$8, \$9)
             RETURNING id",
            $baseParams
        );
    } else {
        $qRes = @pg_query_params(
            $conn,
            "INSERT INTO {$tRagQueries}
                (query, tags, matched_files, prompt_tokens, completion_tokens, total_ms, model, user_id)
             VALUES (\$1, \$2::text[], \$3, \$4, \$5, \$6, \$7, \$8)
             RETURNING id",
            $baseParams
        );
    }

    if (!$qRes) {
        return;
    }
    $qRow    = pg_fetch_assoc($qRes);
    $queryId = (int) ($qRow['id'] ?? 0);
    if ($queryId <= 0) {
        return;
    }

    $sources = $data['sources'] ?? [];
    if (empty($sources)) {
        return;
    }

    static $hasSourcesTable = null;
    if ($hasSourcesTable === null) {
        $hasSourcesTable = (bool) @pg_query($conn, "SELECT 1 FROM {$tRagQuerySources} LIMIT 0");
    }
    if (!$hasSourcesTable) {
        return;
    }

    foreach ($sources as $pos => $src) {
        $fileId   = isset($src['file_id']) ? (int) $src['file_id'] : 0;
        $chunkId  = (isset($src['chunk_id']) && $src['chunk_id'] !== null) ? (int) $src['chunk_id'] : null;
        $chunkIdx = isset($src['chunk_index']) ? (int) $src['chunk_index'] : -1;
        $filename = mb_substr((string) ($src['filename'] ?? ''), 0, 255);
        $snippet  = mb_substr((string) ($src['content'] ?? ''), 0, 400);
        $srcType  = in_array($src['source_type'] ?? '', ['chunk', 'file'], true)
            ? $src['source_type'] : 'file';
        if ($fileId <= 0) {
            continue;
        }
        @pg_query_params(
            $conn,
            "INSERT INTO {$tRagQuerySources}
                (query_id, file_id, chunk_id, chunk_index, filename, snippet, source_type, rank_position)
             VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8)",
            [$queryId, $fileId, $chunkId, $chunkIdx, $filename, $snippet, $srcType, (int) $pos]
        );
    }
}
