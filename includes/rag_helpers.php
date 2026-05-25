<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

function rag_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'ollama_url'        => get_env('OLLAMA_URL', 'http://localhost:11434'),
        'ollama_model'      => get_env('OLLAMA_MODEL', 'llama3'),
        'max_context_files' => 3,
        'max_file_size_mb'  => 10,
        'ollama_timeout'    => 120,
        'ollama_ssl_verify' => true,
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
    return str_getcsv($inner, ',', '"');
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

function rag_retrieve($conn, string $query, array $tags, int $limit = 3): array
{
    $cfg   = rag_config();
    $limit = max(1, min(10, $limit ?: (int) ($cfg['max_context_files'] ?? 3)));
    $tRag  = sys_table('rag_files');
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    if (!empty($tags)) {
        $tagLiteral = php_array_to_pg_text(array_values($tags));
        $sql = "SELECT id, filename, content, tags
                FROM {$tRag}
                WHERE tags && \$2::text[]
                  AND to_tsvector('simple', content) @@ plainto_tsquery('simple', \$1)
                ORDER BY ts_rank(to_tsvector('simple', content), plainto_tsquery('simple', \$1)) DESC
                LIMIT \$3";
        $res = @pg_query_params($conn, $sql, [$query, $tagLiteral, $limit]);
    } else {
        $sql = "SELECT id, filename, content, tags
                FROM {$tRag}
                WHERE to_tsvector('simple', content) @@ plainto_tsquery('simple', \$1)
                ORDER BY ts_rank(to_tsvector('simple', content), plainto_tsquery('simple', \$1)) DESC
                LIMIT \$2";
        $res = @pg_query_params($conn, $sql, [$query, $limit]);
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

function rag_build_prompt(string $query, array $files, string $pageContext = '', string $language = ''): string
{
    $langHint = $language !== '' ? "Please respond in the language with locale code: {$language}. " : '';
    $ctxBlock = $pageContext !== '' ? "Current page data:\n{$pageContext}\n\n" : '';

    if (empty($files)) {
        return "You are a helpful assistant. {$langHint}{$ctxBlock}Answer the following question:\n\n{$query}";
    }

    $context = '';
    foreach ($files as $i => $file) {
        $context .= '--- Document ' . ($i + 1) . ': ' . $file['filename'] . " ---\n"
            . mb_substr($file['content'], 0, 4000) . "\n\n";
    }

    return "You are a helpful assistant. {$langHint}Answer the question using the provided documents"
        . ($pageContext !== '' ? " and the current page data" : '') . ". "
        . "If the answer is not found, say so clearly.\n\n"
        . $ctxBlock
        . "Documents:\n{$context}Question: {$query}\n\nAnswer:";
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

function rag_log_query($conn, array $data): void
{
    $tRagQueries = sys_table('rag_queries');
    $tags        = php_array_to_pg_text(array_values($data['tags'] ?? []));
    @pg_query_params(
        $conn,
        "INSERT INTO {$tRagQueries}
            (query, tags, matched_files, prompt_tokens, completion_tokens, total_ms, model, user_id)
         VALUES (\$1, \$2::text[], \$3, \$4, \$5, \$6, \$7, \$8)",
        [
            mb_substr((string) ($data['query'] ?? ''), 0, 2000),
            $tags,
            (int) ($data['matched_files'] ?? 0),
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['completion_tokens'] ?? 0),
            (int) ($data['total_ms'] ?? 0),
            mb_substr((string) ($data['model'] ?? ''), 0, 255),
            isset($data['user_id']) ? (int) $data['user_id'] : null,
        ]
    );
}
