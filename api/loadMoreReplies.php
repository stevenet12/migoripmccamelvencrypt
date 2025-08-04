<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/EncryptionManager.php';

header('Content-Type: application/json');

EncryptionManager::init($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $project_id = intval($_GET['project_id'] ?? 0);
    $parent_id = intval($_GET['parent_id'] ?? 0);
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 10);

    if (!$parent_id) {
        json_response(['success' => false, 'message' => 'Parent comment ID required'], 400);
    }

    $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $sql = "SELECT f.*, 
                   a.name as admin_name, a.email as admin_email,
                   CASE WHEN f.user_ip = ? AND f.user_agent = ? AND f.status = 'pending' THEN 1 ELSE 0 END as is_user_pending,
                   CASE WHEN f.comment_type = 'admin_response' OR f.admin_id IS NOT NULL THEN 1 ELSE 0 END as is_admin_comment
            FROM feedback f
            LEFT JOIN admins a ON f.admin_id = a.id 
            WHERE f.parent_comment_id = ? 
            AND (
                f.status IN ('approved', 'reviewed', 'responded') 
                OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?)
                OR f.comment_type = 'admin_response'
            )
            ORDER BY f.created_at ASC
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_ip, $user_agent, $parent_id, $user_ip, $user_agent, $limit, $offset]);
    $replies = $stmt->fetchAll();

    // Decrypt the data properly for both feedback and admin fields
    $decrypted_replies = array_map(function($reply) {
        // First decrypt feedback data
        $reply = EncryptionManager::processDataForReading('feedback', $reply);

        // Then decrypt admin data if present
        if (!empty($reply['admin_name'])) {
            $admin_data = ['name' => $reply['admin_name']];
            $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
            $reply['admin_name'] = $admin_data['name'];
        }

        return $reply;
    }, $replies);

    $formatted_replies = [];
    foreach ($decrypted_replies as $reply) {
        $reply_is_admin = ($reply['comment_type'] === 'admin_response') || !empty($reply['admin_id']);
        $reply_display_name = $reply_is_admin ? ($reply['admin_name'] ?? 'Admin') : $reply['citizen_name'];
        $reply_is_user_pending = isset($reply['is_user_pending']) ? $reply['is_user_pending'] : false;

        $formatted_replies[] = [
            'id' => $reply['id'],
            'message' => $reply['message'],
            'created_at' => $reply['created_at'],
            'display_name' => $reply_display_name,
            'citizen_name' => $reply['citizen_name'],
            'admin_name' => $reply['admin_name'],
            'comment_type' => $reply['comment_type'],
            'is_admin' => $reply_is_admin,
            'is_user_pending' => $reply_is_user_pending,
            'time_ago' => time_ago($reply['created_at'])
        ];
    }

    $count_sql = "SELECT COUNT(*) FROM feedback 
                  WHERE parent_comment_id = ?
                  AND (
                      status IN ('approved', 'reviewed', 'responded') 
                      OR (status = 'pending' AND user_ip = ? AND user_agent = ?)
                      OR comment_type = 'admin_response'
                  )";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$parent_id, $user_ip, $user_agent]);
    $total_count = $count_stmt->fetchColumn();
    $remaining = max(0, $total_count - ($offset + $limit));

    json_response([
        'success' => true,
        'replies' => $formatted_replies,
        'remaining' => $remaining,
        'has_more' => $remaining > 0
    ]);

} catch (Exception $e) {
    error_log("Load replies error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to load replies'], 500);
}

function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . ' minutes ago';
    if ($time < 86400) return floor($time / 3600) . ' hours ago';
    if ($time < 2592000) return floor($time / 86400) . ' days ago';
    if ($time < 31536000) return floor($time / 2592000) . ' months ago';
    return floor($time / 31536000) . ' years ago';
}