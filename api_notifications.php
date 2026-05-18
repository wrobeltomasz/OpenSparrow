<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
start_session();
// Block unauthenticated access immediately to prevent IDOR
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/db.php';
header('Content-Type: application/json; charset=utf-8');
// Safely cast the authenticated user ID
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? 'get_count';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
        exit;
    }
}

try {
    $conn = db_connect();
// Fetch the count of unread notifications for the user
    if ($action === 'get_count') {
// Removed notify_date <= today to show upcoming notifications immediately
        $sql = 'SELECT COUNT(*) FROM ' . sys_table('users_notifications') . ' WHERE user_id = $1 AND is_read = FALSE';
        $res = pg_query_params($conn, $sql, [$userId]);
        $count = pg_fetch_result($res, 0, 0);
        echo json_encode(['status' => 'success', 'count' => (int)$count]);
        exit;
    }

    // Fetch the list of notifications for the dropdown menu
    if ($action === 'get_list') {
// Removed notify_date <= today to show upcoming notifications immediately
        $sql = 'SELECT * FROM ' . sys_table('users_notifications') . ' WHERE user_id = $1 ORDER BY is_read ASC, created_at DESC LIMIT ' . NOTIFICATIONS_DROPDOWN_LIMIT;
        $res = pg_query_params($conn, $sql, [$userId]);
        $notifications = pg_fetch_all($res) ?: [];
        echo json_encode(['status' => 'success', 'notifications' => $notifications]);
        exit;
    }

    // Mark a specific notification as read
    if ($action === 'mark_read') {
        $data = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($data['id'] ?? 0);
        if ($notifId > 0) {
            $sql = 'UPDATE ' . sys_table('users_notifications') . ' SET is_read = TRUE WHERE id = $1 AND user_id = $2';
            pg_query_params($conn, $sql, [$notifId, $userId]);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
        exit;
    }

    // Fallback for unknown actions
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
} catch (Throwable $e) {
// Return generic error message to prevent sensitive data leakage
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
