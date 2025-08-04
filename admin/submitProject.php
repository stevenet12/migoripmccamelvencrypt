<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();

$current_admin = get_current_admin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verify_csrf_token($csrf_token)) {
        error_log("CSRF token validation failed. Token: " . ($csrf_token ? 'present' : 'missing') . ", Session ID: " . session_id());
        
        // Generate a new token for the next attempt
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        
        $error = 'Security token expired or invalid. Please try submitting the form again.';
        header("Location: createProject.php?error=" . urlencode($error));
        exit();
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_project') {
            $result = handle_project_creation($_POST);
            if ($result['success']) {
                $project_id = $result['project_id'];

                // Check if user has permission to manage projects, otherwise redirect to projects list
                if (hasPagePermission('manage_projects') || hasPagePermission('view_projects')) {
                    if (hasPagePermission('manage_projects')) {
                        header("Location: manageProject.php?id=$project_id&created=1");
                    } else {
                        header("Location: projects.php?created=1&project_id=$project_id");
                    }
                } else {
                    // Fallback to dashboard with success message
                    header("Location: index.php?created=1&project_name=" . urlencode($result['project_name'] ?? 'New Project'));
                }
                exit();
            } else {
                $error = $result['message'];
                header("Location: createProject.php?error=" . urlencode($error));
                exit();
            }
        }
    }
} else {
    // Redirect if not POST request
    header("Location: createProject.php");
    exit();
}

function build_contractor_contact_string($data) {
    $contractor_parts = [];

    if (!empty($data['contractor_name'])) {
        $contractor_parts[] = 'Name: ' . trim(strip_tags($data['contractor_name']));
    }

    if (!empty($data['contractor_phone'])) {
        $contractor_parts[] = 'Phone: ' . trim(strip_tags($data['contractor_phone']));
    }

    if (!empty($data['contractor_email'])) {
        $contractor_parts[] = 'Email: ' . trim(strip_tags($data['contractor_email']));
    }

    return implode(' | ', $contractor_parts);
}

function handle_project_creation($data) {
    global $pdo, $current_admin;

    try {
        // Validate required fields
        $required_fields = ['project_name', 'department_id', 'project_year', 'county_id', 'sub_county_id', 'ward_id'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Please fill in all required fields. Missing: $field"];
            }
        }

        $pdo->beginTransaction();

        // Check for duplicate project name using PDO helper
        $duplicate_check = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM projects WHERE LOWER(project_name) = LOWER(?)", [trim(strip_tags($data['project_name']))], 'projects');
        if ($duplicate_check && $duplicate_check['count'] > 0) {
            return ['success' => false, 'message' => 'A project with this name already exists. Please choose a different name.'];
        }

        // Prepare project data for insertion
        $total_budget = null;
        if (!empty($data['total_budget']) && is_numeric($data['total_budget'])) {
            $total_budget = floatval($data['total_budget']);
        }

        $project_data = [
            'project_name' => trim(strip_tags($data['project_name'])),
            'description' => isset($data['description']) ? trim(strip_tags($data['description'])) : '',
            'department_id' => intval($data['department_id']),
            'project_year' => intval($data['project_year']),
            'county_id' => intval($data['county_id']),
            'sub_county_id' => intval($data['sub_county_id']),
            'ward_id' => intval($data['ward_id']),
            'location_address' => isset($data['location_address']) ? trim(strip_tags($data['location_address'])) : '',
            'location_coordinates' => isset($data['location_coordinates']) ? trim(strip_tags($data['location_coordinates'])) : '',
            'contractor_contact' => build_contractor_contact_string($data),
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : null,
            'expected_completion_date' => !empty($data['expected_completion_date']) ? $data['expected_completion_date'] : null,
            'total_budget' => $total_budget,
            'status' => 'planning',
            'visibility' => 'private',
            'step_status' => 'awaiting',
            'progress_percentage' => 0,
            'total_steps' => 0,
            'completed_steps' => 0,
            'created_by' => $current_admin['id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Insert project using PDO helper with audit data
        $project_data_with_audit = add_create_audit_data($project_data, 'projects');
        $project_insert_result = pdo_insert($pdo, 'projects', $project_data_with_audit);
        if (!$project_insert_result) {
            throw new Exception('Failed to create project');
        }

        $project_id = $pdo->lastInsertId();

        // Create project steps if provided
        $total_steps = 0;
        $completed_steps = 0;

        if (!empty($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $index => $step) {
                if (!empty($step['name'])) {
                    $step_status = 'pending';
                    $actual_end_date = null;

                    // Process expected date if provided
                    if (!empty($step['expected_date'])) {
                        $expected_date = $step['expected_date'];
                        if (strtotime($expected_date) <= time()) {
                            $step_status = 'completed';
                            $actual_end_date = $expected_date;
                            $completed_steps++;
                        }
                    } else {
                        $expected_date = null;
                    }

                    $step_data = [
                        'project_id' => $project_id,
                        'step_number' => $index + 1,
                        'step_name' => trim(strip_tags($step['name'])),
                        'description' => isset($step['description']) ? trim(strip_tags($step['description'])) : '',
                        'status' => $step_status,
                        'expected_end_date' => $expected_date,
                        'actual_end_date' => $actual_end_date
                    ];

                    // Insert step using PDO helper
                    pdo_insert($pdo, 'project_steps', $step_data);
                    $total_steps++;
                }
            }
        }

        // Calculate progress percentage
        $progress_percentage = ($total_steps > 0) ? round(($completed_steps / $total_steps) * 100, 2) : 0;

        // Update project status based on progress
        $project_status = 'planning';
        $step_status = 'awaiting';

        if ($progress_percentage > 0 && $progress_percentage < 100) {
            $project_status = 'ongoing';
            $step_status = 'running';
        } elseif ($progress_percentage == 100) {
            $project_status = 'completed';
            $step_status = 'completed';
        }

        // Update project with step counts and progress using PDO helper
        $update_data = [
            'total_steps' => $total_steps,
            'completed_steps' => $completed_steps,
            'progress_percentage' => $progress_percentage,
            'status' => $project_status,
            'step_status' => $step_status
        ];

        pdo_update($pdo, 'projects', $update_data, ['id' => $project_id]);

        // Insert budget data into total_budget table if budget is provided
        if ($total_budget && $total_budget > 0) {
            $fiscal_year = $data['project_year'] . '/' . ($data['project_year'] + 1);
            
            $budget_data = [
                'project_id' => $project_id,
                'budget_amount' => $total_budget,
                'budget_type' => 'initial',
                'budget_source' => 'County Development Fund',
                'fiscal_year' => $fiscal_year,
                'approval_status' => 'approved',
                'created_by' => $current_admin['id']
            ];

            pdo_insert($pdo, 'total_budget', $budget_data);
        }

        $pdo->commit();
        log_activity("Project created: " . $data['project_name'], $current_admin['id']);

        return [
            'success' => true, 
            'message' => 'Project created successfully', 
            'project_id' => $project_id,
            'project_name' => $data['project_name']
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Project creation error: " . $e->getMessage() . " | Data: " . json_encode([
            'project_name' => $data['project_name'] ?? 'N/A',
            'department_id' => $data['department_id'] ?? 'N/A',
            'admin_id' => $current_admin['id'] ?? 'N/A'
        ]));
        return ['success' => false, 'message' => "Failed to create project: " . $e->getMessage()];
    }
}
?>