<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';
require_once '../includes/EncryptionManager.php';

require_role('admin');
$current_admin = get_current_admin();

$page_title = "Feedback Management";

// Check if user has permission to manage feedback
if (!hasPagePermission('manage_feedback')) {
    header('Location: index.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Clear any previous output that might cause JSON parsing issues
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $result = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        case 'respond':
            error_log("Respond action called with data: " . json_encode($_POST));
            $result = respond_to_feedback($_POST);
            error_log("Response action result: " . json_encode($result));
            break;
        case 'approve':
            $result = approve_feedback($_POST['feedback_id']);
            break;
        case 'reject':
            $result = reject_feedback($_POST['feedback_id']);
            break;
        case 'delete':
            $result = delete_feedback($_POST['feedback_id']);
            break;
        case 'grievance':
            $result = mark_as_grievance($_POST['feedback_id']);
            break;
        case 'bulk_action':
            $result = handle_bulk_action($_POST);
            break;
        default:
            $result = ['success' => false, 'message' => 'Unknown action: ' . $action];
            break;
    }

    // Ensure clean JSON output
    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode($result);
    exit;
}

// Get filter parameters with pagination
$status = $_GET['status'] ?? '';
$project_id = $_GET['project_id'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$filters = array_filter([
    'status' => $status,
    'project_id' => $project_id,
    'search' => $search,
    'page' => $page,
    'per_page' => $per_page
]);

// Get comments with pagination (decrypted if needed)
function get_comments_with_pagination($filters = []) {
    global $pdo, $current_admin;

    $page = $filters['page'] ?? 1;
    $per_page = $filters['per_page'] ?? 20;
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT f.*, 
                   p.project_name, p.department_id, d.name as department_name,
                   a.name as admin_name, a.email as admin_email,
                   parent.citizen_name as parent_citizen_name,
                   parent.admin_id as parent_admin_id,
                   parent_admin.name as parent_admin_name,
                   parent.comment_type as parent_comment_type,
                   (SELECT COUNT(*) FROM feedback fr WHERE fr.parent_comment_id = f.id AND fr.comment_type = 'admin_response') as response_count
            FROM feedback f
            JOIN projects p ON f.project_id = p.id
            JOIN departments d ON p.department_id = d.id
            LEFT JOIN admins a ON f.admin_id = a.id
            LEFT JOIN feedback parent ON f.parent_comment_id = parent.id
            LEFT JOIN admins parent_admin ON parent.admin_id = parent_admin.id
            WHERE f.comment_type IN ('user_comment', 'user_reply')";

    $count_sql = "SELECT COUNT(DISTINCT f.id)
                  FROM feedback f
                  JOIN projects p ON f.project_id = p.id
                  JOIN departments d ON p.department_id = d.id
                  WHERE 1=1";

    $params = [];

    if (!empty($filters['status'])) {
        $sql .= " AND f.status = ?";
        $count_sql .= " AND f.status = ?";
        $params[] = $filters['status'];
    }

    if (!empty($filters['project_id'])) {
        $sql .= " AND f.project_id = ?";
        $count_sql .= " AND f.project_id = ?";
        $params[] = $filters['project_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (f.subject LIKE ? OR f.message LIKE ? OR f.citizen_name LIKE ?)";
        $count_sql .= " AND (f.subject LIKE ? OR f.message LIKE ? OR f.citizen_name LIKE ?)";
        $search_term = '%' . $filters['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if ($current_admin['role'] !== 'super_admin') {
        $sql .= " AND p.created_by = ?";
        $count_sql .= " AND p.created_by = ?";
        $params[] = $current_admin['id'];
    }

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetchColumn();

    $sql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decrypt feedback fields if needed
    $data = EncryptionManager::processDataForReading('feedback', $data);

    // Process parent comment names and decrypt them
    foreach ($data as &$comment) {
        // Determine and decrypt parent user name
        if (!empty($comment['parent_comment_id'])) {
            if (!empty($comment['parent_admin_id'])) {
                // Parent is admin response - admin name needs separate decryption
                if (!empty($comment['parent_admin_name'])) {
                    // The parent_admin_name field needs to be decrypted using admins table context
                    $admin_data = ['name' => $comment['parent_admin_name']];
                    $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
                    $comment['parent_user_name'] = $admin_data['name'];
                } else {
                    $comment['parent_user_name'] = 'Admin';
                }
            } else {
                // Parent is user comment - decrypt the parent citizen name using feedback table context
                if (!empty($comment['parent_citizen_name'])) {
                    $parent_feedback_data = ['citizen_name' => $comment['parent_citizen_name']];
                    $parent_feedback_data = EncryptionManager::processDataForReading('feedback', $parent_feedback_data);
                    $comment['parent_user_name'] = $parent_feedback_data['citizen_name'];
                } else {
                    $comment['parent_user_name'] = 'User';
                }
            }
        }

        $comment['admin_responses'] = get_admin_responses($comment['id']);
    }

    return [
        'data' => $data ?: [],
        'total' => $total ?: 0,
        'page' => $page ?: 1,
        'per_page' => $per_page ?: 20,
        'total_pages' => $total > 0 ? ceil($total / $per_page) : 1
    ];
}

// Function to fetch admin responses for a given comment
function get_admin_responses($comment_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT f.*, a.name as response_admin_name
        FROM feedback f
        LEFT JOIN admins a ON f.admin_id = a.id
        WHERE f.parent_comment_id = ? AND f.comment_type = 'admin_response'
        ORDER BY f.created_at ASC
    ");
    $stmt->execute([$comment_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decrypt admin names in responses
    foreach ($responses as &$response) {
        if (!empty($response['response_admin_name'])) {
            $admin_data = ['name' => $response['response_admin_name']];
            $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
            $response['response_admin_name'] = $admin_data['name'];
        }

        // Decrypt feedback data
        $response = EncryptionManager::processDataForReading('feedback', $response);
    }

    return $responses;
}

// Respond to feedback (admin response is encrypted if mode is enabled)
function respond_to_feedback($data) {
    global $pdo, $current_admin;

    try {
        $feedback_id = intval($data['feedback_id'] ?? $data['comment_id'] ?? 0);
        $response = trim($data['admin_response'] ?? '');

        if (!$feedback_id) {
            return ['success' => false, 'message' => 'Invalid feedback ID'];
        }

        if (empty($response)) {
            return ['success' => false, 'message' => 'Response cannot be empty'];
        }

        // Get original comment details
        $check_stmt = $pdo->prepare("SELECT id, project_id, comment_type, original_comment_id FROM feedback WHERE id = ?");
        $check_stmt->execute([$feedback_id]);
        $original_comment = $check_stmt->fetch();
        if (!$original_comment) {
            return ['success' => false, 'message' => 'Comment not found'];
        }

        // Determine the original comment ID (for replies, use their original_comment_id)
        $original_id = $original_comment['comment_type'] === 'user_reply' && $original_comment['original_comment_id'] 
            ? $original_comment['original_comment_id'] 
            : $feedback_id;

        // Create new admin response entry
        $stmt = $pdo->prepare("
            INSERT INTO feedback (
                project_id, citizen_name, citizen_email, subject, message, 
                status, comment_type, parent_comment_id, original_comment_id, 
                admin_id, user_ip, user_agent, created_at
            ) 
            VALUES (?, ?, ?, ?, ?, 'approved', 'admin_response', ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $original_comment['project_id'],
            $current_admin['name'] ?? 'Admin',
            $current_admin['email'] ?? 'admin@system.local',
            'Admin Response',
            $response,
            $feedback_id, // parent_comment_id - the comment being replied to
            $original_id, // original_comment_id - the root comment
            $current_admin['id'],
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Admin System'
        ]);

        if ($result) {
            $response_id = $pdo->lastInsertId();

            // Update original comment status to 'responded' if it's a direct response
            if ($original_comment['comment_type'] === 'user_comment') {
                $update_stmt = $pdo->prepare("UPDATE feedback SET status = 'responded' WHERE id = ?");
                $update_stmt->execute([$feedback_id]);
            }

            // Log the activity
            log_activity('feedback_responded', "Admin responded to comment ID: $feedback_id with response ID: $response_id", $current_admin['id'], 'feedback', $response_id);
            return ['success' => true, 'message' => 'Response sent successfully'];
        }

        return ['success' => false, 'message' => 'Failed to save response'];

    } catch (Exception $e) {
        error_log("Feedback response error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while saving the response: ' . $e->getMessage()];
    }
}

function approve_feedback($feedback_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE feedback SET status = 'approved', updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([intval($feedback_id)])) {
            return ['success' => true, 'message' => 'Comment approved'];
        }
        return ['success' => false, 'message' => 'Failed to approve comment'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error approving comment'];
    }
}

function reject_feedback($feedback_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE feedback SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([intval($feedback_id)])) {
            return ['success' => true, 'message' => 'Comment rejected'];
        }
        return ['success' => false, 'message' => 'Failed to reject comment'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error rejecting comment'];
    }
}

function delete_feedback($feedback_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM feedback WHERE id = ?");
        if ($stmt->execute([intval($feedback_id)])) {
            return ['success' => true, 'message' => 'Comment deleted'];
        }
        return ['success' => false, 'message' => 'Failed to delete comment'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error deleting comment'];
    }
}

function mark_as_grievance($feedback_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE feedback SET status = 'grievance', updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([intval($feedback_id)])) {
            return ['success' => true, 'message' => 'Marked as grievance'];
        }
        return ['success' => false, 'message' => 'Failed to mark as grievance'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error marking as grievance'];
    }
}

function handle_bulk_action($data) {
    $action = $data['bulk_action'] ?? '';
    $selected_ids = $data['selected_comments'] ?? [];

    if (empty($action) || empty($selected_ids)) {
        return ['success' => false, 'message' => 'No action or comments selected'];
    }

    $success_count = 0;
    foreach ($selected_ids as $id) {
        $result = false;
        switch ($action) {
            case 'approve':
                $result = approve_feedback($id);
                break;
            case 'reject':
                $result = reject_feedback($id);
                break;
            case 'delete':
                $result = delete_feedback($id);
                break;
            case 'grievance':
                $result = mark_as_grievance($id);
                break;
        }
        if ($result['success']) $success_count++;
    }

    return ['success' => true, 'message' => "$success_count comments processed"];
}

// Get feedback statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM feedback");
    $total_feedback = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'");
    $pending_feedback = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Feedback page error: " . $e->getMessage());
    $total_feedback = 0;
    $pending_feedback = 0;
}

// Get feedback list
$feedback_data = get_comments_with_pagination($filters);
$feedback_list = $feedback_data['data'] ?? [];
$total_feedback = $feedback_data['total'] ?? 0;
$total_pages = $feedback_data['total_pages'] ?? 1;
$projects = get_projects();

// Get prepared responses using the centralized function
$prepared_responses = get_prepared_responses();

// Add fallback responses if none exist
if (empty($prepared_responses)) {
    $prepared_responses = [
        ['id' => 0, 'name' => 'Thank You', 'content' => 'Thank you for your feedback. We will review this matter and get back to you.'],
        ['id' => 0, 'name' => 'Under Review', 'content' => 'Your concern has been noted and will be addressed by the relevant department.'],
        ['id' => 0, 'name' => 'Appreciation', 'content' => 'We appreciate your input on this project. Your feedback is valuable to us.']
    ];
}

include 'includes/adminHeader.php';
?>


<style>
/* Mobile-first responsive design */
.feedback-container {
    background: #f8f9fa;
}

.main-card {
    background: #fffef7 !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin: 0;
    padding: 0;
}

.card-header {
    background: #fffef7 !important;
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    border-radius: 8px 8px 0 0;
}

.card-content {
    background: #fffef7 !important;
    padding: 1rem;
}

.stats-header {
    background: #fffef7 !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    padding: 1rem;
    margin-bottom: 1rem;
}

.stats-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.stat-card {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 0.5rem;
    text-align: center;
    flex: 1;
    min-width: 80px;
}

.filter-section {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 1rem;
}

.comment-item {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
}

.comment-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #374151;
    font-weight: bold;
    font-size: 0.875rem;
}

.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.status-responded { background: #dbeafe; color: #1e40af; }
.status-grievance { background: #f3f4f6; color: #374151; }

.quick-action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 4px;
    border: 1px solid;
    cursor: pointer;
    margin-right: 0.25rem;
    margin-bottom: 0.25rem;
}

.btn-approve { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.btn-reject { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.btn-reply { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.btn-grievance { background: #f3f4f6; color: #374151; border-color: #d1d5db; }
.btn-delete { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

.bulk-actions {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .feedback-container {
        padding: 0.5rem;
    }

    .card-header, .card-content {
        padding: 0.75rem;
    }

    .stats-grid {
        gap: 0.25rem;
    }

    .stat-card {
        padding: 0.375rem;
        font-size: 0.875rem;
    }

    .stat-card .text-lg {
        font-size: 1rem;
    }

    .comment-item {
        padding: 0.5rem;
    }

    .filter-section {
        padding: 0.5rem;
    }

    .stats-header {
        padding: 0.75rem;
        margin-bottom: 0.5rem;
    }
}
</style>

<!-- Breadcrumb -->
<div class="mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li class="text-gray-600 font-medium">
                <i class="fas fa-home mr-1"></i> Dashboard
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">Feedback</li>
        </ol>
    </nav>
</div>

<div class="feedback-container">
    <!-- Page Header -->
    <div class="stats-header">
        <div class="flex flex-col md:flex-row items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900 mb-1">Feedback Management</h1>
                <p class="text-sm text-gray-600">Monitor and respond to community feedback</p>
                <?php if ($current_admin['role'] !== 'super_admin'): ?>
                    <p class="text-xs text-blue-600 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>
                        You can only manage comments on projects you created
                    </p>
                <?php endif; ?>
            </div>
            <div class="text-center mt-2 md:mt-0">
                <div class="text-2xl font-bold text-blue-600"><?php echo number_format($total_feedback ?: 0); ?></div>
                <div class="text-xs text-gray-600"><?php echo $pending_feedback; ?> pending</div>
            </div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="main-card">
        <!-- Status Overview -->
        <div class="card-content">
            <?php
            // Get status counts
            try {
                $status_counts = [];
                $where_clause = "WHERE 1=1";
                $params = [];

                if ($current_admin['role'] !== 'super_admin') {
                    $where_clause .= " AND p.created_by = ?";
                    $params[] = $current_admin['id'];
                }

                $stmt = $pdo->prepare("
                    SELECT f.status, COUNT(*) as count
                    FROM feedback f
                    JOIN projects p ON f.project_id = p.id
                    $where_clause
                    GROUP BY f.status
                ");
                $stmt->execute($params);
                $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                error_log("Status counts error: " . $e->getMessage());
                $status_counts = [];
            }
            ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="text-lg font-bold text-yellow-600"><?php echo $status_counts['pending'] ?? 0; ?></div>
                    <div class="text-xs text-yellow-700">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="text-lg font-bold text-green-600"><?php echo $status_counts['approved'] ?? 0; ?></div>
                    <div class="text-xs text-green-700">Approved</div>
                </div>
                <div class="stat-card">
                    <div class="text-lg font-bold text-blue-600"><?php echo $status_counts['responded'] ?? 0; ?></div>
                    <div class="text-xs text-blue-700">Responded</div>
                </div>
                <div class="stat-card">
                    <div class="text-lg font-bold text-red-600"><?php echo $status_counts['rejected'] ?? 0; ?></div>
                    <div class="text-xs text-red-700">Rejected</div>
                </div>
                <div class="stat-card">
                    <div class="text-lg font-bold text-gray-600"><?php echo $status_counts['grievance'] ?? 0; ?></div>
                    <div class="text-xs text-gray-700">Grievances</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search comments..." 
                               class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500">

                        <select name="status" class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="responded" <?php echo $status === 'responded' ? 'selected' : ''; ?>>Responded</option>
                            <option value="grievance" <?php echo $status === 'grievance' ? 'selected' : ''; ?>>Grievance</option>
                        </select>

                        <select name="project_id" class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>" <?php echo $project_id == $proj['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="flex space-x-2">
                            <button type="submit" class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                Filter
                            </button>
                            <a href="feedback.php" class="px-3 py-2 border border-gray-300 text-sm rounded text-gray-700 hover:bg-gray-50">
                                Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <div id="bulkActionsBar" class="hidden bulk-actions">
                <form id="bulkActionForm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center space-x-3">
                            <label class="flex items-center">
                                <input type="checkbox" id="selectAll" class="mr-2">
                                <span class="text-sm font-medium">Select All</span>
                            </label>
                            <span id="selectedCount" class="text-sm text-gray-600">0 selected</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <select name="bulk_action" id="bulkActionSelect" class="px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Choose Action</option>
                                <option value="approve">Approve</option>
                                <option value="reject">Reject</option>
                                <option value="grievance">Mark as Grievance</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                Apply
                            </button>
                            <button type="button" onclick="toggleBulkActions()" class="px-3 py-2 border border-gray-300 text-sm rounded text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Actions Header -->
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold text-gray-900">
                    <?php echo number_format($total_feedback ?: 0); ?> Comment<?php echo $total_feedback !== 1 ? 's' : ''; ?>
                </h3>
                <button onclick="toggleBulkActions()" class="px-3 py-2 border border-gray-300 text-sm rounded text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-tasks mr-1"></i>Bulk Actions
                </button>
            </div>

            <!-- Comments List -->
            <?php if (empty($feedback_list)): ?>
                <div class="comment-item text-center py-8">
                    <i class="fas fa-comments text-3xl text-gray-400 mb-2"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No Comments Found</h3>
                    <p class="text-gray-600">No comments match your current filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($feedback_list as $comment): ?>
                    <div class="comment-item" data-comment-id="<?php echo $comment['id']; ?>">
                        <div class="flex items-start space-x-3">
                            <!-- Checkbox -->
                            <label class="bulk-checkbox hidden">
                                <input type="checkbox" name="comment_ids[]" value="<?php echo $comment['id']; ?>" class="comment-checkbox">
                            </label>

                            <!-- Avatar -->
                            <div class="comment-avatar">
                                <?php echo strtoupper(substr($comment['citizen_name'] ?: 'A', 0, 1)); ?>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2">
                                    <div>
                                        <span class="font-medium text-gray-900 text-sm">
                                            <?php 
                                            // Determine display name based on comment type
                                            if ($comment['comment_type'] === 'admin_response' || !empty($comment['admin_id'])) {
                                                // For admin responses, get admin name from admin_id
                                                if (!empty($comment['admin_name'])) {
                                                    echo htmlspecialchars($comment['admin_name']);
                                                } else {
                                                    echo 'Admin';
                                                }
                                            } else {
                                                // For user comments, use citizen_name
                                                echo htmlspecialchars($comment['citizen_name']);
                                            }
                                            ?>
                                        </span>
                                        <?php if ($comment['parent_comment_id']): ?>
                                            <span class="text-xs text-gray-500 ml-2">
                                                → replying to <?php echo htmlspecialchars($comment['parent_user_name'] ?: 'comment'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center space-x-2 mt-1 sm:mt-0">
                                        <span class="status-badge status-<?php echo $comment['status']; ?>">
                                            <?php echo ucfirst($comment['status']); ?>
                                        </span>
                                        <?php if (!empty($comment['admin_response'])): ?>
                                            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">
                                                <i class="fas fa-reply mr-1"></i>Replied
                                            </span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500">
                                            <?php echo date('M j', strtotime($comment['created_at'])); ?>
                                       </span>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <div class="text-sm text-gray-600 mb-1">
                                        <strong>Project:</strong> <?php echo htmlspecialchars($comment['project_name']); ?>
                                    </div>
                                    <button onclick="toggleCommentContent(<?php echo $comment['id']; ?>)" 
                                            class="text-sm text-blue-600 hover:text-blue-800">
                                        View Comment <?php if (!empty($comment['admin_responses'])): ?>(<?php echo count($comment['admin_responses']); ?> response<?php echo count($comment['admin_responses']) > 1 ? 's' : ''; ?>)<?php endif; ?>
                                    </button>
                                    <div id="content-<?php echo $comment['id']; ?>" class="hidden mt-2 p-3 bg-gray-50 rounded">
                                <div class="text-sm text-gray-900">
                                    <strong>Comment:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($comment['message'])); ?>
                                </div>

                                <?php if (!empty($comment['admin_responses'])): ?>
                                    <div class="mt-3 space-y-2">
                                        <h5 class="text-sm font-medium text-gray-700">Admin Responses:</h5>
                                        <?php foreach ($comment['admin_responses'] as $response): ?>
                                            <div class="p-3 bg-blue-50 border border-blue-200 rounded">
                                                <div class="flex items-center justify-between mb-1">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-user-shield text-blue-600 mr-1"></i>
                                                        <span class="text-sm font-medium text-blue-800">
                                                            <?php echo htmlspecialchars($response['response_admin_name'] ?? 'Admin'); ?>
                                                        </span>
                                                    </div>
                                                    <span class="text-xs text-blue-600">
                                                        <?php echo date('M j, Y H:i', strtotime($response['created_at'])); ?>
                                                    </span>
                                                </div>
                                                <div class="text-sm text-blue-900">
                                                    <?php echo nl2br(htmlspecialchars($response['message'])); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                                <!-- Quick Actions -->
                                <div class="flex flex-wrap gap-1">
                                    <?php if ($comment['status'] === 'pending'): ?>
                                        <button onclick="quickAction('approve', <?php echo $comment['id']; ?>)" class="quick-action-btn btn-approve">
                                            Approve
                                        </button>
                                        <button onclick="quickAction('reject', <?php echo $comment['id']; ?>)" class="quick-action-btn btn-reject">
                                            Reject
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="showResponseModal(<?php echo $comment['id']; ?>, '<?php echo htmlspecialchars($comment['citizen_name'], ENT_QUOTES); ?>')" 
                                            class="quick-action-btn btn-reply">
                                        Reply
                                    </button>
                                    <button onclick="quickAction('grievance', <?php echo $comment['id']; ?>)" class="quick-action-btn btn-grievance">
                                        Grievance
                                    </button>
                                    <button onclick="quickAction('delete', <?php echo $comment['id']; ?>)" class="quick-action-btn btn-delete">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-200">
                        <div class="text-sm text-gray-700">
                            Showing <?php echo (($page - 1) * $per_page) + 1; ?> to 
                            <?php echo min($page * $per_page, $total_feedback); ?> of 
                            <?php echo number_format($total_feedback ?: 0); ?> results
                        </div>
                        <nav class="flex items-center space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="px-3 py-1 border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50">‹</a>
                            <?php endif; ?>
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="px-3 py-1 border <?php echo $i === $page ? 'border-blue-500 bg-blue-50 text-blue-600' : 'border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded text-sm">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="px-3 py-1 border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50">›</a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Response Modal -->
<div id="responseModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-900">Reply to Comment</h3>
                    <button onclick="closeResponseModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="responseForm">
                    <input type="hidden" id="responseCommentId" name="feedback_id">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="mb-3">
                        <span id="responseCommentAuthor" class="text-sm text-gray-600"></span>
                    </div>
                    <div class="mb-3">
                        <label for="adminResponse" class="block text-sm font-medium text-gray-700 mb-1">Response</label>
                        <textarea id="adminResponse" name="admin_response" rows="4" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Type your response here..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quick Responses</label>
                        <div class="space-y-1 max-h-32 overflow-y-auto">
                            <?php foreach ($prepared_responses as $response): ?>
                                <?php 
                                $content = $response['content'] ?? $response['response_text'] ?? '';
                                $name = $response['name'] ?? 'Response';
                                if (!empty($content)): 
                                ?>
                                    <button type="button" onclick="insertResponse('<?php echo htmlspecialchars($content, ENT_QUOTES); ?>')" 
                                            class="text-left w-full px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 rounded border border-blue-200 hover:border-blue-300 transition-colors">
                                        <div class="font-medium text-blue-800"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="text-xs text-blue-600 truncate"><?php echo htmlspecialchars(substr($content, 0, 60)) . (strlen($content) > 60 ? '...' : ''); ?></div>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex items-center justify-end space-x-2">
                        <button type="button" onclick="closeResponseModal()" 
                                class="px-3 py-2 border border-gray-300 text-sm rounded text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                            Send Response
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle content visibility
function toggleCommentContent(commentId) {
    const content = document.getElementById(`content-${commentId}`);
    content.classList.toggle('hidden');
}

// Quick actions
function quickAction(action, commentId) {
    if (!confirm(`Are you sure you want to ${action} this comment?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', action);
    formData.append('feedback_id', commentId);
    formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');

    fetch('feedback.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

// Response modal
function showResponseModal(commentId, authorName) {
    document.getElementById('responseCommentId').value = commentId;
    document.getElementById('responseCommentAuthor').textContent = `Responding to comment by ${authorName}`;
    document.getElementById('adminResponse').value = '';
    document.getElementById('responseModal').classList.remove('hidden');
}

function closeResponseModal() {
    document.getElementById('responseModal').classList.add('hidden');
}

function insertResponse(text) {
    document.getElementById('adminResponse').value = text;
}

// Handle response form submission
document.getElementById('responseForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;

    // Disable button and show loading
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';

    const formData = new FormData(this);
    formData.append('ajax', '1');
    formData.append('action', 'respond');
    formData.append('feedback_id', document.getElementById('responseCommentId').value);

    fetch('feedback.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Get response as text first to check for HTML errors
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
                return data;
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Server returned invalid JSON response');
            }
        });
    })
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            closeResponseModal();
            location.reload();
        } else {
            alert('❌ ' + (data.message || 'Failed to send response'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ An error occurred while sending the response. Please try again.');
    })
    .finally(() => {
        // Re-enable button
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// Bulk actions
function toggleBulkActions() {
    const bulkBar = document.getElementById('bulkActionsBar');
    const checkboxes = document.querySelectorAll('.bulk-checkbox');

    if (bulkBar.classList.contains('hidden')) {
        bulkBar.classList.remove('hidden');
        checkboxes.forEach(cb => cb.classList.remove('hidden'));
    } else {
        bulkBar.classList.add('hidden');
        checkboxes.forEach(cb => cb.classList.add('hidden'));
        document.querySelectorAll('.comment-checkbox').forEach(cb => cb.checked = false);
        updateSelectedCount();
    }
}

// Select all functionality
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.comment-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

// Update selected count
function updateSelectedCount() {
    const selected = document.querySelectorAll('.comment-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = `${selected} selected`;
}

// Individual checkbox change
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('comment-checkbox')) {
        updateSelectedCount();
    }
});

// Bulk action form submission
document.getElementById('bulkActionForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const selected = document.querySelectorAll('.comment-checkbox:checked');
    const action = document.getElementById('bulkActionSelect').value;

    if (selected.length === 0) {
        alert('Please select at least one comment');
        return;
    }

    if (!action) {
        alert('Please select an action');
        return;
    }

    if (!confirm(`Are you sure you want to ${action} ${selected.length} comment(s)?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'bulk_action');
    formData.append('bulk_action', action);
    formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');

    selected.forEach(cb => {
        formData.append('selected_comments[]', cb.value);
    });

    fetch('feedback.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
});
</script>

<?php include 'includes/adminFooter.php'; ?>