<?php

// rag.php — Knowledge base chat interface
// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
start_session();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > SESSION_MAX_LIFETIME) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$sessionUserAgent = $_SESSION['user_agent'] ?? null;
$currentUserAgent = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
if ($sessionUserAgent !== null && !hash_equals($sessionUserAgent, $currentUserAgent)) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cspNonce = bin2hex(random_bytes(16));
send_security_headers($cspNonce);

$userRole = $_SESSION['role'] ?? 'viewer';
$pageTitle = 'OpenSparrow | Knowledge Base';
$extraCss  = '<link href="/assets/css/rag.css" rel="stylesheet">';
ob_start();
?>

<main>
    <section id="ragSection" class="rag-section">

        <div class="rag-layout">

            <aside class="rag-sidebar">
                <div class="rag-sidebar-inner">
                    <h3 class="rag-sidebar-title">Documents</h3>
                    <div id="ragFileList" class="rag-tag-list">
                        <span class="rag-tag-loading">Loading…</span>
                    </div>
                    <div id="ragTokenWarn" class="rag-token-warning" hidden></div>
                    <p class="rag-sidebar-hint">Select files before asking a question.</p>
                </div>
            </aside>

            <div class="rag-chat-panel">
                <h2 class="rag-chat-title">Knowledge Base</h2>
                <p class="rag-chat-desc">Select one or more documents on the left, then ask your question.</p>

                <div id="ragConversation" class="rag-conversation" role="log" aria-live="polite" aria-label="Conversation history"></div>

                <div class="rag-input-area">
                    <textarea
                        id="ragQuery"
                        class="rag-textarea"
                        placeholder="Ask a question…"
                        rows="3"
                        maxlength="2000"
                        aria-label="Your question"
                    ></textarea>
                    <div class="rag-input-actions">
                        <button id="ragSendBtn" class="rag-send-btn" type="button">Send</button>
                        <button id="ragStopBtn" class="rag-stop-btn" type="button" disabled>Stop</button>
                        <button id="ragClearBtn" class="rag-clear-btn" type="button">Clear history</button>
                        <span id="ragMemoryPill" class="rag-memory-pill" hidden></span>
                    </div>
                </div>

            </div>

        </div>

    </section>
</main>
<?php
$pageContent = ob_get_clean();
ob_start();
?>
<script nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'], JSON_THROW_ON_ERROR); ?>;
    <?php
        $rawSchemaRag = @file_get_contents(__DIR__ . '/config/schema.json');
        $decodedSchemaRag = $rawSchemaRag ? @json_decode($rawSchemaRag, true) : null;
        $ragSchemaTableNames = is_array($decodedSchemaRag['tables'] ?? null) ? array_keys($decodedSchemaRag['tables']) : [];
    ?>
    window.SCHEMA_TABLES = <?php echo json_encode($ragSchemaTableNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script type="module" src="assets/js/rag.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/rag.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/templates/layout.php';
