<?php

declare(strict_types=1);

// includes/admin/rag.php — admin api.php module: RAG knowledge base (rag_list, rag_upload, rag_delete, rag_rechunk,
// rag_rechunk_all,
// rag_settings, rag_settings_save, rag_test_query, rag_ollama_check, rag_stats).
// Included by public/admin/api.php AFTER the admin-role gate, CSRF check and
// POST-method enforcement — never include or serve this file directly.
// Uses $action / $file / $isDemoMode and the AdminApiMessage / admin_error_message()
// / admin_db_fail() / require_not_demo() helpers defined by the front controller.
// Every action block emits its own JSON response and exits.

if ($action === 'rag_list') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn       = db_connect();
        $tRag       = sys_table('rag_files');
        $tRagChunks = sys_table('rag_chunks');
        $cChk       = (bool) @pg_query($conn, "SELECT 1 FROM {$tRagChunks} LIMIT 0");
        $chunkExpr  = $cChk
            ? "(SELECT COUNT(*) FROM {$tRagChunks} c WHERE c.file_id = f.id) AS chunk_count"
            : '0 AS chunk_count';
        $res        = @pg_query($conn, "SELECT f.id, f.filename, f.tags, f.file_size, f.uploaded_by, f.created_at, {$chunkExpr} FROM {$tRag} f ORDER BY f.created_at DESC");
        if (!$res) {
            admin_db_fail($conn, 'rag_list');
        }
        $files = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['chunk_count'] = (int) ($row['chunk_count'] ?? 0);
            $files[] = $row;
        }
        echo json_encode(['status' => 'success', 'files' => $files]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_upload') {
    header('Content-Type: application/json');
    require_not_demo();
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['file']['error'] ?? -1;
            throw new AdminApiMessage('File upload error (code ' . $code . ').');
        }

        $uploadedName = (string) ($_FILES['file']['name'] ?? '');
        $tmpPath      = (string) ($_FILES['file']['tmp_name'] ?? '');
        $fileSize     = (int)   ($_FILES['file']['size'] ?? 0);

        $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            throw new AdminApiMessage('Only .txt files are accepted.');
        }

        $rawTagsJson = $_POST['tags'] ?? '[]';
        $tags = @json_decode($rawTagsJson, true);
        if (!is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_filter(array_map('trim', $tags), fn($t) => $t !== ''));

        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $ragCfg     = rag_config();
        $maxMb      = (int) ($ragCfg['max_file_size_mb'] ?? 10);
        $maxBytes   = $maxMb * 1024 * 1024;

        if ($fileSize > $maxBytes) {
            throw new AdminApiMessage("File too large. Maximum size is {$maxMb} MB.");
        }

        $content = file_get_contents($tmpPath);
        if ($content === false) {
            throw new AdminApiMessage('Could not read uploaded file.');
        }

        // Reject non-UTF-8 or binary content
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new AdminApiMessage('File is not valid UTF-8 text.');
        }
        // Reject files with high density of non-printable bytes (binary detection)
        $nonPrintable = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', $content);
        if ($nonPrintable > 0 && ($nonPrintable / max(1, strlen($content))) > 0.05) {
            throw new AdminApiMessage('File appears to contain binary content and was rejected.');
        }

        $tagLiteral  = php_array_to_pg_text($tags);
        $filename    = basename($uploadedName);
        $uploadedBy  = (int) ($_SESSION['user_id'] ?? 0);

        $res = @pg_query_params(
            $conn,
            "INSERT INTO {$tRag} (filename, content, tags, file_size, uploaded_by) VALUES (\$1, \$2, \$3::text[], \$4, \$5) RETURNING id",
            [$filename, $content, $tagLiteral, $fileSize, $uploadedBy]
        );
        if (!$res) {
            admin_db_fail($conn, 'rag_upload');
        }
        $row    = pg_fetch_assoc($res);
        $fileId = (int) $row['id'];
        if ((bool) ($ragCfg['use_chunks'] ?? true)) {
            rag_store_chunks($conn, $fileId, $content, $ragCfg);
        }
        echo json_encode(['status' => 'success', 'id' => $fileId]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_delete') {
    header('Content-Type: application/json');
    require_not_demo();
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new AdminApiMessage('Invalid document ID.');
        }
        $res = @pg_query_params($conn, "DELETE FROM {$tRag} WHERE id = \$1", [$id]);
        if (!$res) {
            admin_db_fail($conn, 'rag_delete');
        }
        echo json_encode(['status' => 'success', 'deleted' => pg_affected_rows($res)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_rechunk') {
    header('Content-Type: application/json');
    require_not_demo();
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new AdminApiMessage('Invalid document ID.');
        }
        $res = @pg_query_params($conn, "SELECT content FROM {$tRag} WHERE id = \$1", [$id]);
        if (!$res) {
            admin_db_fail($conn, 'rag_rechunk');
        }
        $row = pg_fetch_assoc($res);
        if (!$row) {
            throw new AdminApiMessage('Document not found.');
        }
        $cfg    = rag_config();
        $stored = rag_store_chunks($conn, $id, (string) $row['content'], $cfg);
        echo json_encode(['status' => 'success', 'chunks' => $stored]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_rechunk_all') {
    header('Content-Type: application/json');
    require_not_demo();
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $conn = db_connect();
        $tRag = sys_table('rag_files');
        $cfg  = rag_config();

        $res = @pg_query($conn, "SELECT id, content FROM {$tRag} ORDER BY id ASC");
        if (!$res) {
            admin_db_fail($conn, 'rag_rechunk_all');
        }

        $processed = 0;
        while ($row = pg_fetch_assoc($res)) {
            rag_store_chunks($conn, (int) $row['id'], (string) $row['content'], $cfg);
            $processed++;
        }

        echo json_encode(['status' => 'success', 'processed' => $processed]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_settings') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $cfg = rag_config();
        unset($cfg['__cached']);
        echo json_encode(['status' => 'success', 'settings' => $cfg]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_settings_save') {
    header('Content-Type: application/json');
    require_not_demo();
    try {
        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $ollamaUrl        = trim((string) ($body['ollama_url'] ?? ''));
        $model            = trim((string) ($body['ollama_model'] ?? ''));
        $maxCtx           = max(1, min(20, (int) ($body['max_context_files'] ?? 3)));
        $maxSizeMb        = max(1, min(100, (int) ($body['max_file_size_mb'] ?? 10)));
        $timeout          = max(10, min(600, (int) ($body['ollama_timeout'] ?? 120)));
        $sslVerify        = isset($body['ssl_verify']) ? (bool) $body['ssl_verify'] : true;
        $useChunks        = isset($body['use_chunks']) ? (bool) $body['use_chunks'] : true;
        $convTurns        = max(0, min(10, (int) ($body['conversation_turns'] ?? 0)));
        $chatEnabled      = isset($body['chat_enabled']) ? (bool) $body['chat_enabled'] : true;

        if ($chatEnabled) {
            if ($ollamaUrl === '' || $model === '') {
                throw new AdminApiMessage('ollama_url and ollama_model are required when chat is enabled.');
            }
            if (!filter_var($ollamaUrl, FILTER_VALIDATE_URL)) {
                throw new AdminApiMessage('ollama_url must be a valid URL.');
            }
        }

        require_once __DIR__ . '/../config_store.php';

        // Preserve keys not exposed in the UI (chunk_size, chunk_overlap, etc.)
        $existingCfg = config_get('rag') ?? [];

        $cfg = array_merge($existingCfg, [
            'ollama_url'         => $ollamaUrl,
            'ollama_model'       => $model,
            'max_context_files'  => $maxCtx,
            'max_file_size_mb'   => $maxSizeMb,
            'ollama_timeout'     => $timeout,
            'ollama_ssl_verify'  => $sslVerify,
            'use_chunks'         => $useChunks,
            'conversation_turns' => $convTurns,
            'chat_enabled'       => $chatEnabled,
        ]);
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $result = config_save('rag', $cfg, null, $userId);
        if ($result['status'] !== 'ok') {
            throw new AdminApiMessage($result['error'] ?? 'Could not save RAG configuration.');
        }
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

if ($action === 'rag_test_query') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $query    = trim((string) ($body['query'] ?? ''));
        $tags     = array_values(array_filter(array_map('trim', (array) ($body['tags'] ?? [])), fn($t) => $t !== ''));
        $language = mb_substr(trim((string) ($body['language'] ?? '')), 0, 10);

        if ($query === '') {
            throw new AdminApiMessage('Query is required.');
        }

        $cfg   = rag_config();
        $conn  = db_connect();
        $limit = (int) ($cfg['max_context_files'] ?? 3);
        $files = rag_retrieve($conn, $query, $tags, $limit);
        $prompt = rag_build_prompt($query, $files, '', $language);

        if (DEMO_MODE) {
            $ollamaResult = ['response' => '[Demo mode] Ollama disabled. Matched ' . count($files) . ' document(s).', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_ms' => 0];
        } else {
            $ollamaResult = rag_call_ollama(
                (string) $cfg['ollama_url'],
                (string) $cfg['ollama_model'],
                $prompt,
                (int) ($cfg['ollama_timeout'] ?? 120),
                (bool) ($cfg['ollama_ssl_verify'] ?? true)
            );
        }

        $sources = array_map(fn($f) => [
            'filename' => $f['filename'],
            'tags'     => pg_text_array_to_php($f['tags'] ?? '{}'),
        ], $files);

        $parsed = rag_extract_suggestions($ollamaResult['response']);
        $resp   = ['status' => 'success', 'answer' => $parsed['answer'], 'sources' => $sources];
        if (!empty($body['return_prompt'])) {
            $resp['prompt'] = $prompt;
        }
        echo json_encode($resp);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// POST: proxy to Ollama /api/tags — returns available local models + version
if ($action === 'rag_ollama_check') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/rag_helpers.php';
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $cfg       = rag_config();
        $ollamaUrl = trim((string) ($body['ollama_url'] ?? $cfg['ollama_url'] ?? 'http://localhost:11434'));
        $sslVerify = isset($body['ssl_verify'])
            ? (bool) $body['ssl_verify']
            : (bool) ($cfg['ollama_ssl_verify'] ?? true);

        if ($ollamaUrl === '') {
            throw new AdminApiMessage('ollama_url is required.');
        }
        if (!function_exists('curl_init')) {
            throw new AdminApiMessage('cURL extension required.');
        }

        // Fetch model list
        $tagsUrl = rtrim($ollamaUrl, '/') . '/api/tags';
        $ch      = curl_init($tagsUrl);
        if ($ch === false) {
            throw new AdminApiMessage('Failed to initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new AdminApiMessage('Cannot reach Ollama: ' . ($curlErr ?: 'no response'));
        }
        if ($httpCode !== 200) {
            throw new AdminApiMessage('Ollama returned HTTP ' . $httpCode . '.');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AdminApiMessage('Unexpected response from Ollama.');
        }

        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $models[] = [
                'name'     => (string) ($m['name'] ?? ''),
                'size'     => (int)    ($m['size'] ?? 0),
                'modified' => (string) ($m['modified_at'] ?? $m['modified'] ?? ''),
            ];
        }

        // Also try /api/version for server info
        $version = '';
        $vCh = curl_init(rtrim($ollamaUrl, '/') . '/api/version');
        if ($vCh !== false) {
            curl_setopt_array($vCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ]);
            $vResp = curl_exec($vCh);
            curl_close($vCh);
            if ($vResp !== false) {
                $vData = @json_decode($vResp, true);
                $version = (string) ($vData['version'] ?? '');
            }
        }

        echo json_encode(['status' => 'success', 'models' => $models, 'version' => $version]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}

// GET: RAG query statistics summary + recent queries with source attribution
if ($action === 'rag_stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn             = db_connect();
        $tRagQueries      = sys_table('rag_queries');
        $tRagQuerySources = sys_table('rag_query_sources');

        $summaryRes = @pg_query(
            $conn,
            "SELECT COUNT(*) AS total_queries,
                    COALESCE(ROUND(AVG(total_ms)), 0) AS avg_ms,
                    COALESCE(ROUND(AVG(prompt_tokens)), 0) AS avg_prompt_tokens,
                    COALESCE(ROUND(AVG(completion_tokens)), 0) AS avg_completion_tokens
             FROM {$tRagQueries}"
        );
        $summary = $summaryRes ? (pg_fetch_assoc($summaryRes) ?: []) : [];

        $hasPromptCol = false;
        $colChk = @pg_query($conn, "SELECT 1 FROM information_schema.columns WHERE table_name = 'spw_rag_queries' AND column_name = 'prompt_snapshot' LIMIT 1");
        if ($colChk && pg_num_rows($colChk) > 0) {
            $hasPromptCol = true;
        }
        $promptSelect = $hasPromptCol ? ', prompt_snapshot' : ', NULL AS prompt_snapshot';

        $recentRes = @pg_query(
            $conn,
            "SELECT id, query, tags, matched_files, model, prompt_tokens, completion_tokens, total_ms, created_at{$promptSelect}
             FROM {$tRagQueries}
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $recent = [];
        $ids    = [];
        if ($recentRes) {
            while ($r = pg_fetch_assoc($recentRes)) {
                $recent[] = $r;
                $ids[]    = (int) $r['id'];
            }
        }

        $sourcesByQuery = [];
        if (!empty($ids)) {
            $srcChk = @pg_query($conn, "SELECT 1 FROM {$tRagQuerySources} LIMIT 0");
            if ($srcChk !== false) {
                $idsList = implode(',', $ids);
                $srcRes  = @pg_query(
                    $conn,
                    "SELECT query_id, file_id, chunk_id, chunk_index, filename, snippet, source_type, rank_position
                     FROM {$tRagQuerySources}
                     WHERE query_id IN ({$idsList})
                     ORDER BY query_id, rank_position ASC"
                );
                if ($srcRes) {
                    while ($s = pg_fetch_assoc($srcRes)) {
                        $qid = (int) $s['query_id'];
                        if (!isset($sourcesByQuery[$qid])) {
                            $sourcesByQuery[$qid] = [];
                        }
                        $sourcesByQuery[$qid][] = $s;
                    }
                }
            }
        }

        foreach ($recent as &$row) {
            $row['sources'] = $sourcesByQuery[(int) $row['id']] ?? [];
        }
        unset($row);

        echo json_encode(['status' => 'success', 'summary' => $summary, 'recent' => $recent]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'error' => admin_error_message($e)]);
    }
    exit;
}
