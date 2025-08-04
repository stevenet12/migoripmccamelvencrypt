<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Require admin authentication
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    json_response(['success' => false, 'message' => 'Authentication required']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_response(['success' => false, 'message' => 'Method not allowed']);
}

try {
    $project_id = (int)$_POST['project_id'];
    $transaction_type = sanitize_input($_POST['transaction_type']);
    $amount = (float)$_POST['amount'];
    $description = sanitize_input($_POST['description']);
    $reference_number = sanitize_input($_POST['reference_number']);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $fund_source = sanitize_input($_POST['fund_source'] ?? 'County Development Fund');
    $funding_category = sanitize_input($_POST['funding_category'] ?? 'development');
    $voucher_number = sanitize_input($_POST['voucher_number'] ?? '');
    $disbursement_method = sanitize_input($_POST['disbursement_method'] ?? 'bank_transfer');

    // Validate inputs
    if ($project_id <= 0) {
        json_response(['success' => false, 'message' => 'Invalid project ID']);
    }

    // Validate transaction type against database using PDO helper
    $transaction_type_data = pdo_select_one($pdo, "SELECT type_code, affects_budget, affects_expenditure FROM transaction_types WHERE type_code = ? AND is_active = 1", [$transaction_type], 'transaction_types');

    if (!$transaction_type_data) {
        json_response(['success' => false, 'message' => 'Invalid or inactive transaction type']);
    }

    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'Amount must be greater than 0']);
    }

    if (empty($description)) {
        json_response(['success' => false, 'message' => 'Description is required']);
    }

    if (empty($reference_number)) {
        json_response(['success' => false, 'message' => 'Reference number is required']);
    }

    // Check if project exists using PDO helper
    $project = pdo_select_one($pdo, "SELECT id FROM projects WHERE id = ?", [$project_id], 'projects');
    if (!$project) {
        json_response(['success' => false, 'message' => 'Project not found']);
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Insert transaction using PDO helper
    $transaction_data = [
        'project_id' => $project_id,
        'transaction_type' => $transaction_type,
        'amount' => $amount,
        'description' => $description,
        'reference_number' => $reference_number,
        'transaction_date' => $transaction_date,
        'fund_source' => $fund_source,
        'funding_category' => $funding_category,
        'voucher_number' => $voucher_number,
        'disbursement_method' => $disbursement_method,
        'created_by' => $_SESSION['admin_id']
    ];

    $result = pdo_insert($pdo, 'project_transactions', $transaction_data);

    if (!$result) {
        throw new Exception('Failed to insert transaction');
    }

    $transaction_id = $pdo->lastInsertId();

    // Handle document upload if provided
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $upload_result = secure_file_upload($_FILES['document'], ['pdf', 'jpg', 'jpeg', 'png'], 10485760); // 10MB

        if ($upload_result['success']) {
            // Insert transaction document using PDO helper
            $document_data = [
                'transaction_id' => $transaction_id,
                'file_path' => $upload_result['filename'],
                'original_filename' => $upload_result['original_name'],
                'file_size' => $_FILES['document']['size']
            ];

            pdo_insert($pdo, 'project_transaction_documents', $document_data);
        }
    }

    // Update project progress based on new transaction using the enhanced calculator
    require_once '../includes/projectProgressCalculator.php';

    // Calculate the new progress immediately after transaction is inserted
    $new_progress = calculate_complete_project_progress($project_id);

    // Update the projects table directly with the calculated progress using PDO helper
    $progress_update = pdo_update($pdo, 'projects', ['progress_percentage' => $new_progress], ['id' => $project_id]);

    if (!$progress_update) {
        throw new Exception('Failed to update project progress');
    }

    // Also run the full progress update function for status changes (without starting new transaction)
    $progress_result = update_project_progress_and_status($project_id, false, false);
    $enhanced_progress = $progress_result['progress'] ?? $new_progress;

    // Verify the progress was actually updated using PDO helper
    $current_project = pdo_select_one($pdo, "SELECT progress_percentage FROM projects WHERE id = ?", [$project_id], 'projects');
    $current_progress = $current_project['progress_percentage'] ?? 0;

    error_log("Transaction added - Project ID: $project_id, New Progress: $new_progress, DB Progress: $current_progress");

    // Log activity
    log_activity(
        'transaction_added',
        "Added {$transaction_type} of KES " . number_format($amount) . " for project ID {$project_id}",
        $_SESSION['admin_id'],
        'project',
        $project_id,
        ['transaction_id' => $transaction_id, 'amount' => $amount, 'type' => $transaction_type]
    );

    $pdo->commit();

    json_response([
        'success' => true, 
        'message' => ucfirst($transaction_type) . ' added successfully',
        'transaction_id' => $transaction_id,
        'new_progress' => $enhanced_progress
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Add transaction error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to add transaction: ' . $e->getMessage()]);
}

// Enhanced progress calculation function
function calculate_project_progress($project_id, $conn) {
    // Step progress (50% of total)
    $steps = pdo_select($conn, "SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number", [$project_id], 'project_steps');

    $total_steps = count($steps);
    $step_score = 0;
    foreach ($steps as $step) {
        if ($step['status'] === 'completed') $step_score += 1;
        elseif ($step['status'] === 'in_progress') $step_score += 0.5;
    }
    $step_progress = ($total_steps > 0) ? ($step_score / $total_steps) * 50 : 0;

    // Budget progress (50% of total)
    $transactions = pdo_select_one($conn, "
        SELECT 
            SUM(CASE WHEN transaction_type = 'allocation' THEN amount ELSE 0 END) as allocated,
            SUM(CASE WHEN transaction_type = 'expenditure' THEN amount ELSE 0 END) as spent
        FROM project_transactions 
        WHERE project_id = ?
    ", [$project_id], 'project_transactions');

    $allocated = $transactions['allocated'] ?? 0;
    $spent = $transactions['spent'] ?? 0;
    $budget_progress = ($allocated > 0) ? min($spent / $allocated, 1) * 50 : 0;

    return round($step_progress + $budget_progress, 1);
}
?>
