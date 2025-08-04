<?php
require_once '../config.php'; 
require_once '../includes/functions.php';
require_once '../includes/commentFilter.php';

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get and validate input
    $project_id = intval($_POST['project_id'] ?? 0);
    $citizen_name = trim($_POST['citizen_name'] ?? '');
    $citizen_email = trim($_POST['citizen_email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $parent_comment_id = intval($_POST['parent_comment_id'] ?? 0);
    $original_comment_id = intval($_POST['original_comment_id'] ?? 0);
    $is_admin_reply = isset($_POST['is_admin_reply']) && $_POST['is_admin_reply'] == '1';
    $admin_id = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : null;

    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Project ID is required']);
        exit;
    }

    // Validate based on whether it's an admin reply or citizen comment
    if (!$is_admin_reply) {
        if (empty($citizen_name)) {
            echo json_encode(['success' => false, 'message' => 'Name is required']);
            exit;
        }

        if (empty($citizen_email)) {
            echo json_encode(['success' => false, 'message' => 'Email address is required']);
            exit;
        }

        if (!filter_var($citizen_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a valid email address']);
            exit;
        }
    } else {
        // For admin replies, ensure admin_id is provided
        if (!$admin_id) {
            echo json_encode(['success' => false, 'message' => 'Admin ID is required for admin replies']);
            exit;
        }
    }

    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Comment message is required']);
        exit;
    }

    // Check if project exists
    $stmt = $pdo->prepare("SELECT id, project_name FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();

    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit;
    }

    // User info
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // For non-admin replies, check for duplicates
    if (!$is_admin_reply) {
        $checkDup = $pdo->prepare("
            SELECT COUNT(*) FROM feedback 
            WHERE project_id = ? 
            AND user_ip = ? 
            AND citizen_name = ?
            AND message = ? 
            AND created_at > (NOW() - INTERVAL 30 SECOND)
        ");
        $checkDup->execute([$project_id, $user_ip, $citizen_name, $message]);

        if ($checkDup->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'You have already submitted this exact comment recently. Please wait a moment before posting again.'
            ]);
            exit;
        }
    }

    // Filter comment (if available) - skip filtering for admin replies
    $filter_result = ['status' => 'approved', 'message' => 'Comment submitted successfully', 'details' => ['reason' => 'admin_reply']];

    if (!$is_admin_reply) {
        $filter_result = ['status' => 'pending_review', 'message' => 'Comment submitted for review', 'details' => ['reason' => 'no_filter']];

        try {
            if (class_exists('CommentFilter')) {
                $commentFilter = new CommentFilter();
                $filter_result = $commentFilter->filterComment($message);

                // Log filtering results for debugging
                error_log("Comment filter result: " . json_encode($filter_result));
            }

            if ($filter_result['status'] === 'rejected') {
                echo json_encode(['success' => false, 'message' => $filter_result['message']]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Comment filtering failed: " . $e->getMessage());
            $filter_result = ['status' => 'pending_review', 'message' => 'Comment submitted for review', 'details' => ['reason' => 'filter_error']];
        }
    }

    $db_status = match ($filter_result['status']) {
        'approved' => 'approved',
        'pending_review', 'pending' => 'pending',
        default => 'pending'
    };

    // Determine comment type and subject
    $comment_type = 'user_comment';
    $subject = 'Project Comment';

    if ($is_admin_reply) {
        $comment_type = 'admin_response';
        $subject = 'Admin Response';
        $db_status = 'approved'; // Admin responses are automatically approved
    } elseif ($parent_comment_id > 0) {
        $comment_type = 'user_reply';
        $subject = 'Reply to comment';
    }

    // Generate unique comment owner ID for tracking (only for non-admin comments)
    $comment_owner_id = null;
    if (!$is_admin_reply) {
        $comment_owner_id = md5($citizen_email . $user_ip . time());
    }

    // Add filtering metadata
    $filtering_metadata = isset($filter_result['details']) ? json_encode($filter_result['details']) : null;

    // For admin replies, set citizen fields to null and don't set citizen_email
    if ($is_admin_reply) {
        $citizen_name = null;
        $citizen_email = null;
        $comment_owner_id = null;
    }

    // Insert comment with proper structure
    $stmt = $pdo->prepare("
        INSERT INTO feedback (
            project_id, citizen_name, citizen_email, subject, message, 
            status, comment_type, parent_comment_id, original_comment_id, 
            comment_owner_id, admin_id, user_ip, user_agent, filtering_metadata, created_at
        ) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $result = $stmt->execute([
        $project_id,
        $citizen_name,
        $citizen_email,
        $subject,
        $message,
        $db_status,
        $comment_type,
        $parent_comment_id > 0 ? $parent_comment_id : null,
        $original_comment_id > 0 ? $original_comment_id : null,
        $comment_owner_id,
        $admin_id,
        $user_ip,
        $user_agent,
        $filtering_metadata
    ]);

    if ($result) {
        $comment_id = $pdo->lastInsertId();
        $author_name = $is_admin_reply ? 'Admin' : $citizen_name;
        log_activity('comment_submitted', "New comment submitted for project: {$project['project_name']} by {$author_name}", null, 'comment', $comment_id);

        $success_message = match ($db_status) {
            'approved' => $is_admin_reply ? 'Admin response posted successfully!' : 'Your comment has been posted successfully!',
            'pending' => 'Your comment has been submitted for review and will be published after approval.',
            default => $filter_result['message'] ?? 'Your comment has been submitted successfully!'
        };

        echo json_encode([
            'success' => true,
            'message' => $success_message,
            'status' => $db_status
        ]);
    } else {
        error_log("Failed to insert comment: " . implode(', ', $stmt->errorInfo()));
        echo json_encode(['success' => false, 'message' => 'Failed to save comment. Please try again.']);
    }

} catch (PDOException $e) {
    error_log("Database error in feedback.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
} catch (Exception $e) {
    error_log("General error in feedback.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
}
?>