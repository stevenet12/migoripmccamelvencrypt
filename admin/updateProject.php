<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();
$current_admin = get_current_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: projects.php');
    exit();
}

// Validate CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || !verify_csrf_token($csrf_token)) {
    header("Location: editProject.php?id=" . ($_POST['project_id'] ?? 0) . "&error=" . urlencode('Security token expired. Please try again.'));
    exit();
}

// Get project ID and validate
$project_id = (int)($_POST['project_id'] ?? 0);
if (!$project_id) {
    header('Location: projects.php?error=invalid_project');
    exit();
}

// Get project details to check ownership
$project = get_project_by_id($project_id);
if (!$project) {
    header('Location: projects.php?error=' . urlencode('Project not found'));
    exit();
}

// Check permissions - super admin can edit all projects, others need to own the project
if ($current_admin['role'] !== 'super_admin') {
    // Check if user has edit_projects permission
    if (!hasPagePermission('edit_projects')) {
        header('Location: projects.php?error=' . urlencode('You do not have permission to edit projects'));
        exit();
    }

    // Check if this admin created the project
    if ($project['created_by'] != $current_admin['id']) {
        header('Location: projects.php?error=' . urlencode('You do not have permission to edit this project'));
        exit();
    }
}

try {
    $pdo->beginTransaction();

    // Validate required fields
    $required_fields = ['project_name', 'department_id', 'project_year', 'county_id', 'sub_county_id', 'ward_id'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Please fill in all required fields. Missing: $field");
        }
    }

    // Prepare update data
    $update_data = [
        'project_name' => trim(strip_tags($_POST['project_name'])),
        'description' => isset($_POST['description']) ? trim(strip_tags($_POST['description'])) : '',
        'department_id' => intval($_POST['department_id']),
        'project_year' => intval($_POST['project_year']),
        'county_id' => intval($_POST['county_id']),
        'sub_county_id' => intval($_POST['sub_county_id']),
        'ward_id' => intval($_POST['ward_id']),
        'location_address' => isset($_POST['location_address']) ? trim(strip_tags($_POST['location_address'])) : '',
        'location_coordinates' => isset($_POST['location_coordinates']) ? trim(strip_tags($_POST['location_coordinates'])) : '',
        'contractor_name' => isset($_POST['contractor_name']) ? trim(strip_tags($_POST['contractor_name'])) : '',
        'contractor_contact' => isset($_POST['contractor_contact']) ? trim(strip_tags($_POST['contractor_contact'])) : '',
        'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
        'expected_completion_date' => !empty($_POST['expected_completion_date']) ? $_POST['expected_completion_date'] : null,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Check for duplicate project name (excluding current project)
    $duplicate_check = pdo_select_one($pdo, 
        "SELECT COUNT(*) as count FROM projects WHERE LOWER(project_name) = LOWER(?) AND id != ?", 
        [trim(strip_tags($_POST['project_name'])), $project_id], 
        'projects'
    );

    if ($duplicate_check && $duplicate_check['count'] > 0) {
        throw new Exception('A project with this name already exists. Please choose a different name.');
    }

    // Update project using PDO helper
    $update_result = pdo_update($pdo, 'projects', $update_data, ['id' => $project_id]);

    if (!$update_result) {
        throw new Exception('Failed to update project');
    }

    $pdo->commit();

    // Log the activity
    log_activity("Project updated: " . $_POST['project_name'], $current_admin['id']);

    header("Location: manageProject.php?id=$project_id&success=" . urlencode('Project updated successfully'));
    exit();

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Project update error: " . $e->getMessage());
    header("Location: editProject.php?id=" . $project_id . "&error=" . urlencode($e->getMessage()));
    exit();
}
?>