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
                    <h3 class="rag-sidebar-title">Filter by tag</h3>
                    <div id="ragTagList" class="rag-tag-list">
                        <span class="rag-tag-loading">Loading…</span>
                    </div>
                </div>
            </aside>

            <div class="rag-chat-panel">
                <h2 class="rag-chat-title">Knowledge Base</h2>
                <p class="rag-chat-desc">Ask questions answered from uploaded documents. Select tags to narrow the search.</p>

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
                        <button id="ragClearBtn" class="rag-clear-btn" type="button">Clear history</button>
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
</script>
<script type="module" src="assets/js/rag.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/rag.js'); ?>" nonce="<?php echo htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$extraScripts = ob_get_clean();
include __DIR__ . '/templates/layout.php';
