<?php
// Adding project ownership validation to project management.
$page_title = "Manage Project";
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/projectPublicationValidator.php';
require_once 'includes/pageSecurity.php';

require_admin();
$current_admin = get_current_admin();

// Get project ID from URL
$project_id = (int)($_GET['id'] ?? 0);
if (!$project_id) {
    header('Location: projects.php?error=invalid_project');
    exit;
}

// Check project management permission using RBAC
if (!hasPagePermission('manage_projects')) {
    header('Location: projects.php?error=' . urlencode('You do not have permission to manage projects.'));
    exit();
}

// Fetch project details
$project = get_project_by_id($project_id);
if (!$project || !is_array($project)) {
    header('Location: projects.php');
    exit();
}

// Check if the current admin has permission to manage this specific project
if (!can_manage_project($project_id)) {
    header('Location: projects.php?error=' . urlencode('You do not have permission to manage this project.'));
    exit();
}

// Initialize variables
$success = $error = null;
$steps = [];

// Handle all form submissions (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $result = null;

        try {
            switch ($action) {
                case 'update_step':
                    $step_id = intval($_POST['step_id'] ?? 0);
                    $status = $_POST['status'] ?? '';
                    $step_name = sanitize_input($_POST['step_name'] ?? '');
                    $description = sanitize_input($_POST['description'] ?? '');
                    $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

                    if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                        throw new Exception('Invalid step status.');
                    }
                    if (empty($step_name)) {
                        throw new Exception('Step name cannot be empty.');
                    }

                    $pdo->beginTransaction();

                    // Get old values for audit trail
                    $old_stmt = $pdo->prepare("SELECT * FROM project_steps WHERE id = ? AND project_id = ?");
                    $old_stmt->execute([$step_id, $project_id]);
                    $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$old_values) {
                        throw new Exception('Step not found or access denied.');
                    }

                    // Fetch the current step to check dates
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
                    if ($stmt->execute($params)) {
                        // Log detailed audit trail
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

                        // Insert into admin_activity_log
                        $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log 
                            (admin_id, activity_type, target_type, target_id, activity_description, additional_data, ip_address, user_agent, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                        $log_stmt->execute([
                            $current_admin['id'] ?? 0,
                            'step_update',
                            'project_steps',
                            $step_id,
                            $activity_description,
                            $activity_details,
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);

                        // Recalculate project progress using new system
                        require_once '../includes/projectProgressCalculator.php';
                        update_project_progress_and_status($project_id);

                        $pdo->commit();
                        log_activity("Updated project step for project ID: $project_id", $current_admin['id'] ?? 0);
                        $success = 'Step updated successfully.';
                    } else {
                        throw new Exception('Failed to update step.');
                    }
                    break;

                case 'update_status_only':
                    $step_id = intval($_POST['step_id'] ?? 0);
                    $status = $_POST['status'] ?? '';

                    if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                        throw new Exception('Invalid step status.');
                    }

                    $pdo->beginTransaction();

                    // Fetch the current step to check dates
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
                    $stmt->execute($params);

                    require_once '../includes/projectProgressCalculator.php';
                    $progress_result = update_project_progress_and_status($project_id);
                    $pdo->commit();
                    log_activity("Updated step status for project ID: $project_id", $current_admin['id'] ?? 0);
                    $success = 'Step status updated successfully.';
                    break;

                case 'add_step':
                    $step_name = sanitize_input($_POST['step_name'] ?? '');
                    $description = sanitize_input($_POST['description'] ?? '');
                    $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

                    if (empty($step_name)) {
                        throw new Exception('Step name is required.');
                    }

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(step_number), 0) + 1 FROM project_steps WHERE project_id = ?");
                    $stmt->execute([$project_id]);
                    $step_number = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("INSERT INTO project_steps (project_id, step_number, step_name, description, expected_end_date) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$project_id, $step_number, $step_name, $description, $expected_end_date]);

                    update_project_progress($project_id);
                    $pdo->commit();
                    log_activity("Added project step for project ID: $project_id", $current_admin['id'] ?? 0);
                    $success = 'Step added successfully.';
                    break;

                case 'delete_step':
                    $step_id = intval($_POST['step_id'] ?? 0);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id = ? AND project_id = ?");
                    $stmt->execute([$step_id, $project_id]);

                    // Renumber remaining steps to maintain order
                    $stmt = $pdo->prepare("SELECT id FROM project_steps WHERE project_id = ? ORDER BY step_number");
                    $stmt->execute([$project_id]);
                    $remaining_steps = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($remaining_steps as $index => $remaining_step_id) {
                        $stmt_update = $pdo->prepare("UPDATE project_steps SET step_number = ? WHERE id = ?");
                        $stmt_update->execute([$index + 1, $remaining_step_id]);
                    }

                    update_project_progress($project_id);
                    $pdo->commit();
                    log_activity("Deleted project step for project ID: $project_id", $current_admin['id'] ?? 0);
                    $success = 'Step deleted successfully.';
                    break;

                case 'update_visibility':
                    $visibility = $_POST['visibility'] ?? '';
                    if (!in_array($visibility, ['private', 'published'])) {
                        throw new Exception('Invalid visibility option.');
                    }

                    // Always validate the project before any visibility change
                    $validator = new ProjectPublicationValidator($pdo);

                    // Get validation status before making any changes
                    $validation_status = $validator->getSecurityValidationReport($project_id) ?? [];
                    $validation_errors = $validator->getErrors() ?? [];

                    // SECURITY ENFORCEMENT: This cannot be bypassed
                    if (!$validator->enforcePublicationSecurity($project_id, $visibility)) {
                        $validator->logPublicationAttempt($project_id, $current_admin['id'] ?? 0, false, $validation_errors);

                        $error = "SECURITY BLOCK: Cannot publish project. Please fix the following issues:<br><ul>";
                        foreach ($validation_errors as $validation_error) {
                            $error .= "<li>" . htmlspecialchars($validation_error) . "</li>";
                        }
                        $error .= "</ul>";

                        // Log the security violation with full details
                        log_activity("SECURITY VIOLATION: Attempted to bypass publication validation for project ID: $project_id. User: " . ($current_admin['email'] ?? 'unknown'), $current_admin['id'] ?? 0);
                        error_log("Publication bypass attempt blocked: " . json_encode($validation_status));
                        break;
                    }

                    if ($visibility === 'published') {
                        $validator->logPublicationAttempt($project_id, $current_admin['id'] ?? 0, true);
                    }

                    // Double-check with proper null checks
                    if (
                            $visibility === 'private' &&
                            !empty($project) &&
                            ($project['visibility'] ?? '') === 'published' &&
                            !($validation_status['is_valid'] ?? false)
                        ) {
                            log_activity(
                                "WARNING: Making published project private due to validation issues. Project ID: $project_id",
                                $current_admin['id'] ?? 0
                            );
                        }

                    $stmt = $pdo->prepare("UPDATE projects SET visibility = ? WHERE id = ?");
                    $stmt->execute([$visibility, $project_id]);
                    log_activity("Updated project visibility to '$visibility' for project ID: $project_id", $current_admin['id'] ?? 0);
                    $success = 'Project visibility updated successfully.';
                    break;
                case 'delete_project':
                    $project_name_to_delete = $project['project_name'] ?? 'Unknown Project';

                    $pdo->beginTransaction();
                    // Delete associated records first
                    $pdo->prepare("DELETE FROM project_steps WHERE project_id = ?")->execute([$project_id]);
                    $pdo->prepare("DELETE FROM feedback WHERE project_id = ?")->execute([$project_id]);

                    // Delete the project itself
                    $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
                    $stmt->execute([$project_id]);
                    $pdo->commit();

                    log_activity("Project deleted: " . $project_name_to_delete, $current_admin['id'] ?? 0);
                    header('Location: projects.php?success=' . urlencode('Project deleted successfully'));
                    exit();

                default:
                    $error = 'Invalid action specified.';
            }
        } catch (Exception $e) {
            error_log("Project management error: " . $e->getMessage());
            $error = $e->getMessage();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        // After a POST action, refresh data to show the latest state
        $project = get_project_by_id($project_id);
    }
}

// Get project steps after any potential updates
$stmt = $pdo->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();

include 'includes/adminHeader.php';
?>

<!-- Breadcrumb -->
<div class="mb-4">
    <nav class="flex text-sm" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2">
            <li><a href="index.php" class="text-gray-500 hover:text-gray-700">Dashboard</a></li>
            <li class="text-gray-400">/</li>
            <li><a href="projects.php" class="text-gray-500 hover:text-gray-700">Projects</a></li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">Manage Project</li>
        </ol>
    </nav>
</div>

<!-- Page Header -->
<div class="mb-6">
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
        <div class="flex flex-col md:flex-row items-start justify-between">
            <div class="mb-4 md:mb-0">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Manage Project</h1>
                <p class="text-gray-600">Editing project: <span class="font-semibold"><?php echo htmlspecialchars($project['project_name']); ?></span></p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="editProject.php?id=<?php echo $project['id']; ?>" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    <i class="fas fa-edit mr-2"></i> Edit Details
                </a>
                <button type="button" data-modal-target="deleteProjectModal" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                    <i class="fas fa-trash mr-2"></i> Delete Project
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="bg-white rounded-lg p-6 mb-6 shadow-sm border border-gray-200">
    <div class="space-y-6">

        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
        <div class="rounded-md bg-green-50 p-4 border border-green-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($success); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
        <div class="rounded-md bg-red-50 p-4 border border-red-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Project Overview Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4 mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                        <span class="mt-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo get_status_badge_class($project['status']); ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </div>
                    <div class="w-full md:w-1/3">
                        <dt class="text-sm font-medium text-gray-500">Progress</dt>
                        <dd class="text-sm text-gray-900 mt-1">
                            <div class="flex items-center">
                                <div class="flex-1">
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="h-2.5 rounded-full <?php echo get_progress_color_class($project['progress_percentage']); ?>" style="width: <?php echo $project['progress_percentage']; ?>%"></div>
                                    </div>
                                </div>
                                <span class="ml-3 text-sm font-medium"><?php echo round($project['progress_percentage']); ?>%</span>
                            </div>
                        </dd>
                    </div>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-8">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Department</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($project['department_name']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Location</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($project['ward_name'] . ', ' . $project['sub_county_name']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Visibility</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $project['visibility'] === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo $project['visibility'] === 'published' ? 'Public' : 'Private'; ?>
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Project Visibility Card -->
        <?php 
        $validation_status = get_project_validation_status($project_id);
        $status_class = get_publication_status_class($project);
        $button_class = get_publication_button_class($project);
        ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 <?php echo $status_class; ?>">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Project Visibility</h3>
                        <p class="text-sm text-gray-600 mt-1">Control whether this project is visible on the public-facing website.</p>
                    </div>
                    <div class="flex items-center">
                        <?php if ($project['visibility'] === 'published'): ?>
                            <i class="fas fa-eye text-green-600 text-xl"></i>
                            <span class="ml-2 text-sm font-semibold text-green-800">Public</span>
                        <?php else: ?>
                            <i class="fas fa-eye-slash text-red-600 text-xl"></i>
                            <span class="ml-2 text-sm font-semibold text-red-800">Private</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$validation_status['is_valid'] && $project['visibility'] === 'private'): ?>
            <div class="p-4 bg-yellow-50 border-b border-yellow-200">
                <div class="flex">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-1"></i>
                    <div class="ml-3">
                        <h4 class="text-sm font-medium text-yellow-800">Publication Requirements Not Met</h4>
                        <p class="text-xs text-yellow-700 mt-1">This project cannot be published until the following issues are resolved:</p>
                        <ul class="text-xs text-yellow-700 mt-2 list-disc list-inside">
                            <?php foreach ($validation_status['errors'] as $error): ?>
                                <li>
                                    <?php if (is_array($error)): ?>
                                        <?php echo htmlspecialchars($error['message']); ?>
                                        <a href="<?php echo htmlspecialchars($error['link']); ?>" class="text-blue-600 hover:text-blue-800 underline ml-1">
                                            <?php echo htmlspecialchars($error['link_text']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($error); ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="text-sm text-gray-700">
                        Current Status: 
                        <span class="font-semibold <?php echo $project['visibility'] === 'published' ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo $project['visibility'] === 'published' ? 'Published (Public)' : 'Private (Admins Only)'; ?>
                        </span>
                    </div>
                    <button type="button" 
                            onclick="openVisibilityConfirmModal()" 
                            class="w-full sm:w-auto inline-flex justify-center items-center px-6 py-3 border border-transparent text-sm font-medium rounded-md text-white transition-colors
                            <?php 
                            if ($project['visibility'] === 'private' && !$validation_status['is_valid']) {
                                echo 'bg-gray-400 cursor-not-allowed opacity-50';
                            } else {
                                echo $button_class;
                            }
                            ?>"
                            data-validation-valid="<?php echo $validation_status['is_valid'] ? 'true' : 'false'; ?>"
                            data-current-visibility="<?php echo $project['visibility']; ?>"
                            <?php if ($project['visibility'] === 'private' && !$validation_status['is_valid']): ?>
                                disabled
                                title="Cannot publish: Please fix validation issues first"
                            <?php endif; ?>
                        >
                        <?php if ($project['visibility'] === 'published'): ?>
                            <i class="fas fa-eye-slash mr-2"></i>Make Private
                        <?php else: ?>
                            <i class="fas fa-eye mr-2"></i>Publish Project
                        <?php endif; ?>
                    </button>
                </div>

                <?php if ($validation_status['is_valid'] && $project['visibility'] === 'private'): ?>
                <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-md">
                    <div class="flex">
                        <i class="fas fa-check-circle text-green-600"></i>
                        <span class="ml-2 text-sm text-green-800 font-medium">Project meets all publication requirements and can be made public.</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Project Steps Management Card -->
        <div id="project-steps-section" class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                    <h3 class="text-lg font-semibold text-gray-900">Project Steps</h3>
                    <button type="button" data-modal-target="stepModal" data-modal-action="add" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i> Add Step
                    </button>
                </div>
            </div>

            <?php if (empty($steps)): ?>
                <div class="text-center p-12">
                    <i class="fas fa-list-ol text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900">No Steps Defined</h3>
                    <p class="text-gray-600 mt-1 mb-4">This project doesn't have any steps yet. Add the first one to get started.</p>
                    <button type="button" data-modal-target="stepModal" data-modal-action="add" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i> Add First Step
                    </button>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($steps as $step): ?>
                        <div class="p-6" id="step-container-<?php echo $step['id']; ?>">
                            <!-- Normal Step Display -->
                            <div id="step-display-<?php echo $step['id']; ?>">
                                <div class="flex flex-col md:flex-row md:justify-between gap-6">
                                    <!-- Step Details -->
                                    <div class="flex-grow">
                                        <div class="flex items-center mb-3">
                                            <span class="flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold text-white <?php echo $step['status'] === 'completed' ? 'bg-green-500' : ($step['status'] === 'in_progress' ? 'bg-blue-500' : ($step['status'] === 'skipped' ? 'bg-gray-500' : 'bg-yellow-500')); ?>">
                                                <?php echo $step['step_number']; ?>
                                            </span>
                                            <div class="ml-4">
                                                <h4 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($step['step_name']); ?></h4>
                                                <span class="text-xs font-medium inline-flex items-center px-2 py-0.5 rounded <?php echo $step['status'] === 'completed' ? 'bg-green-100 text-green-800' : ($step['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800' : ($step['status'] === 'skipped' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $step['status'])); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <?php if (!empty($step['description'])): ?>
                                        <p class="text-sm text-gray-600 mb-4 ml-12"><?php echo nl2br(htmlspecialchars($step['description'])); ?></p>
                                        <?php endif; ?>

                                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-2 text-sm ml-12">
                                            <?php if ($step['start_date']): ?><div><dt class="font-medium text-gray-500">Started:</dt><dd class="text-gray-900"><?php echo format_date($step['start_date']); ?></dd></div><?php endif; ?>
                                            <?php if ($step['expected_end_date']): ?><div><dt class="font-medium text-gray-500">Expected:</dt><dd class="text-gray-900"><?php echo format_date($step['expected_end_date']); ?></dd></div><?php endif; ?>
                                            <?php if ($step['actual_end_date']): ?><div><dt class="font-medium text-gray-500">Completed:</dt><dd class="text-gray-900"><?php echo format_date($step['actual_end_date']); ?></dd></div><?php endif; ?>
                                        </dl>
                                    </div>

                                    <!-- Step Actions -->
                                    <div class="flex-shrink-0 flex flex-col items-stretch justify-center gap-3 md:ml-6 md:border-l md:pl-6 border-gray-200">
                                        <form method="POST" class="w-full">
                                             <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                             <input type="hidden" name="action" value="update_status_only">
                                             <input type="hidden" name="step_id" value="<?php echo $step['id']; ?>">
                                             <label class="sr-only" for="status-<?php echo $step['id']; ?>">Update Status</label>
                                             <select name="status" id="status-<?php echo $step['id']; ?>" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                                                <option value="pending" <?php echo $step['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="in_progress" <?php echo $step['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $step['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="skipped" <?php echo $step['status'] === 'skipped' ? 'selected' : ''; ?>>Skipped</option>
                                            </select>
                                        </form>

                                        <button type="button" onclick="showInlineEditForm(<?php echo $step['id']; ?>)" class="w-full inline-flex items-center justify-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors" title="Edit Step Details">
                                            <i class="fas fa-edit fa-fw mr-2"></i>Edit
                                        </button>
                                        <button type="button" onclick="openDeleteStepModal(<?php echo $step['id']; ?>)" class="w-full inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200" title="Delete Step">
                                            <i class="fas fa-trash fa-fw mr-2"></i>Delete
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Inline Edit Form (Hidden by default) -->
                            <div id="step-edit-<?php echo $step['id']; ?>" class="hidden">
                                <form method="POST" class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="update_step">
                                    <input type="hidden" name="step_id" value="<?php echo $step['id']; ?>">

                                    <div class="space-y-4">
                                        <h5 class="text-lg font-medium text-gray-900 mb-4">Edit Step Details</h5>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Step Name</label>
                                            <input type="text" name="step_name" value="<?php echo htmlspecialchars($step['step_name']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                            <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($step['description']); ?></textarea>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Expected End Date</label>
                                                <input type="date" name="expected_end_date" value="<?php echo $step['expected_end_date']; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                                <select name="status" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                    <option value="pending" <?php echo $step['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="in_progress" <?php echo $step['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="completed" <?php echo $step['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="skipped" <?php echo $step['status'] === 'skipped' ? 'selected' : ''; ?>>Skipped</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="flex flex-col sm:flex-row gap-3 pt-4">
                                            <button type="submit" class="flex-1 sm:flex-none inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors font-medium">
                                                <i class="fas fa-save mr-2"></i>Save Changes
                                            </button>
                                            <button type="button" onclick="hideInlineEditForm(<?php echo $step['id']; ?>)" class="flex-1 sm:flex-none inline-flex items-center justify-center px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition-colors font-medium">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Step Modal -->
<div id="stepModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50 transition-opacity" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4 text-center sm:block sm:p-0">
        <!-- Background element, appears when modal is open -->
        <div class="fixed inset-0" aria-hidden="true"></div>
        <!-- Modal panel -->
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <form id="stepForm" method="POST" data-no-reset="true">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" id="stepAction" value="add_step">
                <input type="hidden" name="step_id" id="stepId" value="">

                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Add New Step</h3>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="step_name" class="block text-sm font-medium text-gray-700">Step Name *</label>
                            <input type="text" name="step_name" id="step_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="description" id="description" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                        </div>
                        <div>
                            <label for="expected_end_date" class="block text-sm font-medium text-gray-700">Expected End Date</label>
                            <input type="date" name="expected_end_date" id="expected_end_date" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                         <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select name="status" id="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" id="stepSubmitButton" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Add Step
                    </button>
                    <button type="button" data-modal-close="stepModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Step Confirmation Modal -->
<div id="deleteStepModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mx-auto mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-900">Delete Step</h3>
                <p class="text-sm text-gray-600 mt-2">Are you sure you want to delete this step? This action cannot be undone.</p>
            </div>
            <form method="POST" class="flex justify-end space-x-3 p-4 bg-gray-50 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_step">
                <input type="hidden" name="step_id" id="delete_step_id" value="">
                <button type="button" data-modal-close="deleteStepModal" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Delete Step</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete Project Confirmation Modal -->
<div id="deleteProjectModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mx-auto mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-900">Delete Project</h3>
                <p class="text-sm text-gray-600 mt-2">Are you sure you want to permanently delete this project? All associated steps and feedback will also be removed. This action cannot be undone.</p>
            </div>
            <form method="POST" class="flex justify-end space-x-3 p-4 bg-gray-50 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_project">
                <button type="button" data-modal-close="deleteProjectModal" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Yes, Delete Project</button>
            </form>
        </div>
    </div>
</div>

<!-- Visibility Change Confirmation Modal -->
<div id="visibilityConfirmModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center mr-4" id="modal-icon-container">
                        <!-- Icon will be set by JavaScript -->
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900" id="modal-title">
                            <!-- Title will be set by JavaScript -->
                        </h3>
                        <p class="text-sm text-gray-600" id="modal-subtitle">
                            <!-- Subtitle will be set by JavaScript -->
                        </p>
                    </div>
                </div>
                <div class="mb-6">
                    <p class="text-sm text-gray-700" id="modal-description">
                        <!-- Description will be set by JavaScript -->
                    </p>
                    <div class="mt-3 p-3 rounded-md" id="modal-warning">
                        <!-- Warning content will be set by JavaScript -->
                    </div>
                </div>
            </div>
            <form method="POST" class="flex justify-end space-x-3 p-4 bg-gray-50 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_visibility">
                <input type="hidden" name="visibility" id="new_visibility" value="">
                <button type="button" onclick="closeVisibilityConfirmModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white" id="confirm-button">
                    <!-- Button text will be set by JavaScript -->
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle scroll to sections from validation error links
    if (window.location.hash) {
        const targetElement = document.querySelector(window.location.hash);
        if (targetElement) {
            setTimeout(() => {
                targetElement.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'start' 
                });
                
                // Highlight the section briefly
                targetElement.classList.add('bg-blue-50', 'border-blue-200');
                setTimeout(() => {
                    targetElement.classList.remove('bg-blue-50', 'border-blue-200');
                }, 2000);
                
                // If it's the steps section, also highlight the add button
                if (window.location.hash === '#project-steps-section') {
                    const addButton = document.querySelector('[data-modal-target="stepModal"]');
                    if (addButton) {
                        addButton.classList.add('ring-4', 'ring-blue-300');
                        setTimeout(() => {
                            addButton.classList.remove('ring-4', 'ring-blue-300');
                        }, 2000);
                    }
                }
            }, 100);
        }
    }

    // --- Modal Control ---
    const modalTriggers = document.querySelectorAll('[data-modal-target]');
    const modalClosers = document.querySelectorAll('[data-modal-close]');

    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const modalId = trigger.getAttribute('data-modal-target');
            const modal = document.getElementById(modalId);
            if (modal) {
                // Handle step modal specifically
                if(modalId === 'stepModal') {
                    const action = trigger.getAttribute('data-modal-action');
                    if (action === 'edit') {
                        const stepData = JSON.parse(trigger.getAttribute('data-step'));
                        openEditStepModal(stepData);
                    } else {
                        openAddStepModal();
                    }
                }
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';

                // Focus on first input after modal opens
                setTimeout(() => {
                    const firstInput = modal.querySelector('input, textarea, select');
                    if (firstInput) {
                        firstInput.focus();
                    }
                }, 100);
            }
        });
    });

    modalClosers.forEach(closer => {
        closer.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeModal(closer);
        });
    });

    // Close modal on escape key press
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const openModals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
            openModals.forEach(modal => {
                closeModal(modal);
            });
        }
    });

    // Close modal when clicking on backdrop
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('fixed') && e.target.classList.contains('inset-0')) {
            closeModal(e.target);
        }
    });

    function closeModal(element) {
        const modal = element.closest ? element.closest('.fixed.inset-0') : element;
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';

            // Reset forms in modal
            const forms = modal.querySelectorAll('form');
            forms.forEach(form => {
                if (form.getAttribute('data-no-reset') !== 'true') {
                    form.reset();
                }
            });
        }
    }

    // --- Step Modal Logic ---
    const stepModal = document.getElementById('stepModal');
    const stepForm = document.getElementById('stepForm');
    const modalTitle = document.getElementById('modal-title');
    const stepAction = document.getElementById('stepAction');
    const stepSubmitButton = document.getElementById('stepSubmitButton');

    function openAddStepModal() {
        stepForm.reset();
        modalTitle.textContent = 'Add New Step';
        stepAction.value = 'add_step';
        document.getElementById('stepId').value = '';
        stepSubmitButton.textContent = 'Add Step';

        // Ensure all form inputs are enabled and focusable
        setTimeout(() => {
            const inputs = stepForm.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.disabled = false;
                input.removeAttribute('readonly');
            });

            // Focus on first input
            const firstInput = stepForm.querySelector('input[name="step_name"]');
            if (firstInput) {
                firstInput.focus();
            }
        }, 100);
    }

    function openEditStepModal(stepData) {
        stepForm.reset();
        modalTitle.textContent = 'Edit Step';
        stepAction.value = 'update_step';
        stepSubmitButton.textContent = 'Save Changes';

        // Set form values
        document.getElementById('stepId').value = stepData.id;
        document.getElementById('step_name').value = stepData.step_name;
        document.getElementById('description').value = stepData.description || '';
        document.getElementById('expected_end_date').value = stepData.expected_end_date || '';
        document.getElementById('status').value = stepData.status || 'pending';

        // Ensure all form inputs are enabled and focusable
        setTimeout(() => {
            const inputs = stepForm.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.disabled = false;
                input.removeAttribute('readonly');
            });

            // Focus on first input
            const firstInput = stepForm.querySelector('input[name="step_name"]');
            if (firstInput) {
                firstInput.focus();
            }
        }, 100);
    }

    // Function to open the delete confirmation modal for a step
    window.openDeleteStepModal = function(stepIdToDelete) {
        document.getElementById('delete_step_id').value = stepIdToDelete;
        document.getElementById('deleteStepModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    // Visibility confirmation modal functions
    window.openVisibilityConfirmModal = function() {
        const button = event.target.closest('button');
        const currentVisibility = button.getAttribute('data-current-visibility');
        const isValidationValid = button.getAttribute('data-validation-valid') === 'true';

        // Don't proceed if button is disabled
        if (button.disabled) {
            return;
        }

        const modal = document.getElementById('visibilityConfirmModal');
        const iconContainer = document.getElementById('modal-icon-container');
        const title = document.getElementById('modal-title');
        const subtitle = document.getElementById('modal-subtitle');
        const description = document.getElementById('modal-description');
        const warning = document.getElementById('modal-warning');
        const newVisibilityInput = document.getElementById('new_visibility');
        const confirmButton = document.getElementById('confirm-button');

        if (currentVisibility === 'published') {
            // Making private
            iconContainer.className = 'w-12 h-12 rounded-full flex items-center justify-center mr-4 bg-red-100';
            iconContainer.innerHTML = '<i class="fas fa-eye-slash text-2xl text-red-600"></i>';
            title.textContent = 'Make Project Private';
            subtitle.textContent = 'Hide project from public view';
            description.textContent = 'This will immediately hide the project from the public website. Only administrators will be able to view it.';
            warning.className = 'mt-3 p-3 rounded-md bg-red-50 border border-red-200';
            warning.innerHTML = '<div class="flex"><i class="fas fa-info-circle text-red-600 mt-0.5"></i><div class="ml-2 text-sm text-red-800"><strong>Note:</strong> The project will no longer be visible to the public and citizens won\'t be able to view its details or provide feedback.</div></div>';
            newVisibilityInput.value = 'private';
            confirmButton.className = 'px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700';
            confirmButton.innerHTML = '<i class="fas fa-eye-slash mr-2"></i>Make Private';
        } else {
            // Making public
            iconContainer.className = 'w-12 h-12 rounded-full flex items-center justify-center mr-4 bg-green-100';
            iconContainer.innerHTML = '<i class="fas fa-eye text-2xl text-green-600"></i>';
            title.textContent = 'Publish Project';
            subtitle.textContent = 'Make project visible to the public';
            description.textContent = 'This will make the project visible on the public website. Citizens will be able to view project details, progress, and provide feedback.';

            if (isValidationValid) {
                warning.className = 'mt-3 p-3 rounded-md bg-green-50 border border-green-200';
                warning.innerHTML = '<div class="flex"><i class="fas fa-check-circle text-green-600 mt-0.5"></i><div class="ml-2 text-sm text-green-800"><strong>Ready to publish:</strong> This project meets all publication requirements and can be safely made public.</div></div>';
            } else {
                warning.className = 'mt-3 p-3 rounded-md bg-yellow-50 border border-yellow-200';
                warning.innerHTML = '<div class="flex"><i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i><div class="ml-2 text-sm text-yellow-800"><strong>Warning:</strong> Backend validation will prevent publication. Please fix validation issues first.</div></div>';
            }

            newVisibilityInput.value = 'published';
            confirmButton.className = 'px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700';
            confirmButton.innerHTML = '<i class="fas fa-eye mr-2"></i>Publish Project';
        }

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    window.closeVisibilityConfirmModal = function() {
        document.getElementById('visibilityConfirmModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    // Enhanced inline step editing with API support
    window.showInlineEditForm = function(stepId) {
        // Hide the display view
        document.getElementById('step-display-' + stepId).classList.add('hidden');
        // Show the edit form
        document.getElementById('step-edit-' + stepId).classList.remove('hidden');

        // Focus on the first input field
        const firstInput = document.querySelector('#step-edit-' + stepId + ' input[name="step_name"]');
        if (firstInput) {
            firstInput.focus();
        }
    }

    window.hideInlineEditForm = function(stepId) {
        // Show the display view
        document.getElementById('step-display-' + stepId).classList.remove('hidden');
        // Hide the edit form
        document.getElementById('step-edit-' + stepId).classList.add('hidden');
    }

    // Handle inline form submissions via API
    document.addEventListener('submit', function(e) {
        if (e.target.matches('#step-edit-* form')) {
            e.preventDefault();
            handleStepUpdate(e.target);
        }
    });

    // Handle status dropdown changes
    document.addEventListener('change', function(e) {
        if (e.target.matches('select[name="status"]') && e.target.closest('form')) {
            const form = e.target.closest('form');
            if (form.querySelector('input[name="action"][value="update_status_only"]')) {
                e.preventDefault();
                handleStatusUpdate(form);
            }
        }
    });

    function handleStepUpdate(form) {
        const formData = new FormData(form);
        const stepId = formData.get('step_id');
        const projectId = <?php echo $project_id; ?>;

        // Add project ID
        formData.append('project_id', projectId);

        // Show loading state
        const submitButton = form.querySelector('button[type="submit"]');
        const originalText = submitButton.innerHTML;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        submitButton.disabled = true;

        fetch('api/updateStep.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showNotification('Step updated successfully', 'success');
                // Reload page to show updated data
                window.location.reload();
            } else {
                throw new Error(data.message || 'Failed to update step');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification(error.message || 'Failed to update step', 'error');

            // Reset button state
            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        });
    }

    function handleStatusUpdate(form) {
        const formData = new FormData(form);
        const projectId = <?php echo $project_id; ?>;

        // Add project ID
        formData.append('project_id', projectId);

        // Show loading state on select
        const select = form.querySelector('select[name="status"]');
        select.disabled = true;

        fetch('api/updateStep.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Status updated successfully', 'success');
                // Reload page to show updated data
                setTimeout(() => window.location.reload(), 1000);
            } else {
                throw new Error(data.message || 'Failed to update status');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification(error.message || 'Failed to update status', 'error');
            select.disabled = false;
        });
    }

    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 p-4 rounded-md shadow-lg transition-all duration-300 ${
            type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'
        }`;
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        document.body.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
});

// Load sub-counties based on county selection
function loadSubCounties(countyId, selectedSubCountyId = null) {
    const subCountySelect = document.getElementById('subCountyId');
    const wardSelect = document.getElementById('wardId');

    if (!subCountySelect || !wardSelect) return;

    subCountySelect.innerHTML = '<option value="">Select Sub County</option>';
    wardSelect.innerHTML = '<option value="">Select Ward</option>';

    if (countyId) {
        fetch(`../api/locations.php?action=sub_counties&county_id=${countyId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.data.forEach(subCounty => {
                        const option = document.createElement('option');
                        option.value = subCounty.id;
                        option.textContent = subCounty.name;
                        if (selectedSubCountyId && subCounty.id == selectedSubCountyId) {
                            option.selected = true;
                        }
                        subCountySelect.appendChild(option);
                    });

                    // If we have a selected sub-county, load its wards immediately
                    if (selectedSubCountyId) {
                        loadWards(selectedSubCountyId, <?php echo $project['ward_id']; ?>);
                    }
                }
            })
            .catch(error => console.error('Error loading sub-counties:', error));
    }
}

function loadWards(subCountyId, selectedWardId = null) {
    const wardSelect = document.getElementById('wardId');
    if (!wardSelect) return;

    wardSelect.innerHTML = '<option value="">Loading wards...</option>';
    wardSelect.disabled = true;

    if (subCountyId) {
        fetch(`../api/locations.php?action=wards&sub_county_id=${subCountyId}`)
            .then(response => response.json())
            .then(data => {
                wardSelect.innerHTML = '<option value="">Select Ward</option>';
                wardSelect.disabled = false;

                if (data.success) {
                    data.data.forEach(ward => {
                        const option = document.createElement('option');
                        option.value = ward.id;
                        option.textContent = ward.name;
                        if (selectedWardId && ward.id == selectedWardId) {
                            option.selected = true;
                        }
                        wardSelect.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error loading wards:', error);
                wardSelect.innerHTML = '<option value="">Error loading wards</option>';
                wardSelect.disabled = false;
            });
    } else {
        wardSelect.disabled = false;
    }
}

// Load the dependent dropdowns when page loads
document.addEventListener('DOMContentLoaded', function() {
    const countyId = <?php echo $project['county_id']; ?>;
    const subCountyId = <?php echo $project['sub_county_id']; ?>;
    const wardId = <?php echo $project['ward_id']; ?>;

    console.log('Loading location data:', { countyId, subCountyId, wardId });

    if (countyId) {
        // Load sub-counties first
        loadSubCounties(countyId, subCountyId);
    }
});
</script>

<?php include 'includes/adminFooter.php'; ?>