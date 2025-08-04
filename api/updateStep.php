<?php
// Ensure no output before JSON response
ob_start();

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Clear any output that might have been generated
ob_clean();

// Set headers early
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Require admin authentication without including pageSecurity.php
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$current_admin = get_current_admin();
if (!$current_admin) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Get and validate input
$project_id = intval($_POST['project_id'] ?? 0);
$step_id = intval($_POST['step_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$project_id || !$step_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Project ID and Step ID are required']);
    exit;
}

// Check permissions
if (!hasPagePermission('manage_projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to manage projects']);
    exit;
}

// Check if the current admin can manage this specific project
if (!can_manage_project($project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to manage this project']);
    exit;
}

try {
    $pdo->beginTransaction();

    switch ($action) {
        case 'update_step':
            $status = $_POST['status'] ?? '';
            $step_name = sanitize_input($_POST['step_name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

            if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                throw new Exception('Invalid step status');
            }
            if (empty($step_name)) {
                throw new Exception('Step name cannot be empty');
            }

            // Get old values for audit trail
            $old_stmt = $pdo->prepare("SELECT * FROM project_steps WHERE id = ? AND project_id = ?");
            $old_stmt->execute([$step_id, $project_id]);
            $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$old_values) {
                throw new Exception('Step not found or access denied');
            }

            // Check current step dates
            $stmt_check = $pdo->prepare("SELECT start_date, actual_end_date FROM project_steps WHERE id = ?");
            $stmt_check->execute([$step_id]);
            $current_step = $stmt_check->fetch();

            $sql = "UPDATE project_steps SET status = ?, step_name = ?, description = ?, expected_end_date = ?, updated_at = NOW()";
            $params = [$status, $step_name, $description, $expected_end_date];

            if ($status === 'completed' && empty($current_step['actual_end_date'])) {
                $sql .= ", actual_end_date = CURDATE()";
            } elseif ($status === 'in_progress' && empty($current_step['start_date'])) {
                $sql .= ", start_date = COALESCE(start_date, CURDATE())";
            } elseif ($status !== 'completed') {
                $sql .= ", actual_end_date = NULL";
            }

            $sql .= " WHERE id = ? AND project_id = ?";
            $params[] = $step_id;
            $params[] = $project_id;

            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update step');
            }

            // Log audit trail
            $activity_description = "Updated project step: {$step_name} (ID: {$step_id})";
            $activity_details = json_encode([
                'step_id' => $step_id,
                'project_id' => $project_id,
                'old_values' => $old_values,
                'new_values' => [
                    'step_name' => $step_name,
                    'description' => $description,
                    'expected_end_date' => $expected_end_date,
                    'status' => $status
                ],
                'action' => 'step_update'
            ]);

            $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log 
                (admin_id, activity_type, target_type, target_id, activity_description, activity_details, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $log_stmt->execute([
                $current_admin['id'],
                'step_update',
                'project_steps',
                $step_id,
                $activity_description,
                $activity_details,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            // Update project progress
            require_once '../../includes/projectProgressCalculator.php';
            update_project_progress_and_status($project_id);
            
            break;

        case 'update_status_only':
            $status = $_POST['status'] ?? '';

            if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                throw new Exception('Invalid step status');
            }

            // Check current step dates
            $stmt_check = $pdo->prepare("SELECT start_date, actual_end_date FROM project_steps WHERE id = ?");
            $stmt_check->execute([$step_id]);
            $current_step = $stmt_check->fetch();

            $sql = "UPDATE project_steps SET status = ?";
            $params = [$status];

            if ($status === 'completed' && empty($current_step['actual_end_date'])) {
                $sql .= ", actual_end_date = CURDATE()";
            } elseif ($status === 'in_progress' && empty($current_step['start_date'])) {
                $sql .= ", start_date = COALESCE(start_date, CURDATE())";
            }

            $sql .= " WHERE id = ? AND project_id = ?";
            $params[] = $step_id;
            $params[] = $project_id;

            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update step status');
            }

            // Update project progress
            require_once '../../includes/projectProgressCalculator.php';
            update_project_progress_and_status($project_id);
            
            break;

        default:
            throw new Exception('Invalid action specified');
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Step updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Step update API error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Error $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    error_log("Step update API fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred']);
}

// Ensure clean output
ob_end_flush();
?>