<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';
require_once '../includes/EncryptionManager.php';

EncryptionManager::init($pdo);

require_admin();
if (!hasPagePermission('manage_feedback')) {
    header('Location: index.php?error=access_denied');
    exit;
}

$current_admin = get_current_admin();

// Check if admin has any grievance comments to manage
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM feedback f
    JOIN projects p ON f.project_id = p.id
    WHERE f.status = 'grievance' AND p.created_by = ?
");
$stmt->execute([$current_admin['id']]);
if ($stmt->fetchColumn() == 0) {
    header('Location: feedback.php?info=no_grievances');
    exit;
}

log_activity('grievances_access', 'Accessed grievance management page', $current_admin['id']);

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $result = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        case 'resolve_grievance':
            $feedback_id = intval($_POST['feedback_id'] ?? 0);
            $response = sanitize_input($_POST['admin_response'] ?? '');

            if (empty($response)) {
                $result = ['success' => false, 'message' => 'Response cannot be empty'];
                break;
            }

            // Load grievance comment
            $stmt = $pdo->prepare("
                SELECT f.*, p.created_by, p.project_name 
                FROM feedback f
                JOIN projects p ON f.project_id = p.id
                WHERE f.id = ? AND f.status = 'grievance' AND p.created_by = ?
            ");
            $stmt->execute([$feedback_id, $current_admin['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $result = ['success' => false, 'message' => 'Grievance not found or access denied'];
                break;
            }

            // Properly decrypt the grievance data
            $grievance = EncryptionManager::processDataForReading('feedback', $row);
            
            // Validate that we have the required data after decryption
            if (empty($grievance['citizen_name']) || empty($grievance['message'])) {
                $result = ['success' => false, 'message' => 'Invalid grievance data - missing required fields'];
                break;
            }

            try {
                $pdo->beginTransaction();

                // Insert admin response as a reply comment using EncryptionManager
                $reply_data = [
                    'project_id' => $grievance['project_id'],
                    'citizen_name' => $current_admin['name'],
                    'citizen_email' => $current_admin['email'],
                    'subject' => 'Admin Response',
                    'message' => $response,
                    'status' => 'approved',
                    'comment_type' => 'admin_response',
                    'parent_comment_id' => $feedback_id,
                    'admin_id' => $current_admin['id'],
                    'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // Use EncryptionManager to handle encryption properly
                $result_insert = EncryptionManager::insertEncrypted($pdo, 'feedback', $reply_data);

                if (!$result_insert) {
                    throw new Exception('Failed to create admin response');
                }

                // Update original grievance status to resolved
                $stmt = $pdo->prepare("UPDATE feedback SET status = ?, admin_id = ?, updated_at = ? WHERE id = ?");
                $result_update = $stmt->execute(['resolved', $current_admin['id'], date('Y-m-d H:i:s'), $feedback_id]);

                if (!$result_update) {
                    throw new Exception('Failed to update grievance status');
                }

                // Send notification email with proper encryption handling
                $citizen_email = trim($grievance['citizen_email'] ?? '');
                $citizen_name = trim($grievance['citizen_name'] ?? '');
                $project_name = trim($grievance['project_name'] ?? '');
                
                // Debug logging for email processing
                error_log("Processing email for grievance #$feedback_id - Email: '$citizen_email', Name: '$citizen_name'");
                
                if (!empty($citizen_email) && filter_var($citizen_email, FILTER_VALIDATE_EMAIL)) {
                    $subject = "Response to Your Concern - " . htmlspecialchars($project_name);

                    // Create professional email template
                    $email_body = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <title>" . htmlspecialchars($subject) . "</title>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #1e40af; color: white; padding: 20px; text-align: center; }
                            .content { padding: 20px; background: #f9f9f9; }
                            .response-box { background: #e8f4fd; padding: 15px; border-left: 4px solid #1e40af; margin: 15px 0; }
                            .original-box { background: #f9f9f9; padding: 15px; border-left: 4px solid #ddd; margin: 15px 0; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>Migori County PMC</h2>
                                <p>Grievance Resolution Response</p>
                            </div>
                            <div class='content'>
                                <p>Dear " . htmlspecialchars($citizen_name) . ",</p>
                                <p>Thank you for bringing your concerns to our attention regarding the project: <strong>" . htmlspecialchars($project_name) . "</strong></p>

                                <h3>Your Original Message:</h3>
                                <div class='original-box'>
                                    " . nl2br(htmlspecialchars($grievance['message'])) . "
                                </div>

                                <h3>Our Response:</h3>
                                <div class='response-box'>
                                    " . nl2br(htmlspecialchars($response)) . "
                                </div>

                                <p>This grievance has been resolved. You can view our response on the project page as well.</p>
                                <p>We take all citizen concerns seriously and strive to address them promptly. If you have any additional questions or concerns, please don't hesitate to contact us.</p>
                                <p>Best regards,<br>
                                <strong>" . htmlspecialchars($current_admin['name']) . "</strong><br>
                                Migori County Project Committee</p>
                            </div>
                        </div>
                    </body>
                    </html>";

                    // Enhanced email headers for better delivery
                    $headers = array(
                        'MIME-Version: 1.0',
                        'Content-type: text/html; charset=UTF-8',
                        'From: Migori County PMC <noreply@migoricounty.go.ke>',
                        'Reply-To: ' . ($current_admin['email'] ?? 'noreply@migoricounty.go.ke'),
                        'X-Mailer: Migori PMC System',
                        'X-Priority: 3'
                    );

                    // Attempt to send email with PHP mail function
                    $email_sent = mail(
                        $citizen_email,
                        $subject,
                        $email_body,
                        implode("\r\n", $headers)
                    );

                    if ($email_sent) {
                        error_log("Grievance response email sent successfully to: " . $citizen_email);
                        log_activity('grievance_email_sent', "Grievance response email sent to {$citizen_email} for grievance #$feedback_id", $current_admin['id']);
                    } else {
                        error_log("Failed to send grievance response email to: " . $citizen_email);
                        log_activity('grievance_email_failed', "Failed to send grievance response email to {$citizen_email} for grievance #$feedback_id", $current_admin['id']);
                    }
                } else {
                    error_log("Invalid or missing email address for grievance #$feedback_id: '$citizen_email' (validation: " . (filter_var($citizen_email, FILTER_VALIDATE_EMAIL) ? 'valid' : 'invalid') . ")");
                    log_activity('grievance_email_invalid', "Invalid email address for grievance #$feedback_id: '$citizen_email'", $current_admin['id']);
                }

                $pdo->commit();
                log_activity('grievance_resolved', "Resolved grievance #$feedback_id for project: {$grievance['project_name']}", $current_admin['id']);
                $result = ['success' => true, 'message' => 'Grievance resolved successfully and response sent'];

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Grievance resolution error for grievance #$feedback_id: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $result = ['success' => false, 'message' => 'Failed to resolve grievance: ' . $e->getMessage()];
            }
            break;
    }

    echo json_encode($result);
    exit;
}

// Load all grievance comments
$stmt = $pdo->prepare("
    SELECT f.*, p.project_name, p.department_id, d.name as department_name
    FROM feedback f
    JOIN projects p ON f.project_id = p.id
    JOIN departments d ON p.department_id = d.id
    WHERE f.status = 'grievance' AND p.created_by = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$current_admin['id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grievances = [];
foreach ($rows as $row) {
    $grievances[] = EncryptionManager::processDataForReading('feedback', $row);
}

$page_title = "Grievance Management";
include 'includes/adminHeader.php';
?>

<!-- Breadcrumb -->
<div class="mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li class="text-gray-600 font-medium">
                <a href="index.php" class="text-gray-600 hover:text-gray-800">
                    <i class="fas fa-home mr-1"></i> Dashboard
                </a>
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">
                <a href="feedback.php" class="text-gray-600 hover:text-gray-800">Feedback</a>
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">Grievance Management</li>
        </ol>
    </nav>
</div>

<div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Grievance Management</h1>
            <p class="mt-1 text-sm text-gray-600">Handle citizen grievances requiring immediate attention</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="feedback.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                <i class="fas fa-arrow-left mr-2"></i>Back to Feedback
            </a>
        </div>
    </div>

    <!-- Grievances List -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle text-red-600 mr-3"></i>
                <h3 class="text-lg font-semibold text-red-900">
                    Active Grievances (<?php echo count($grievances); ?>)
                </h3>
            </div>
            <p class="text-sm text-red-700 mt-1">These require immediate attention and response</p>
        </div>

        <?php if (empty($grievances)): ?>
            <div class="p-12 text-center">
                <i class="fas fa-check-circle text-4xl text-green-400 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Active Grievances</h3>
                <p class="text-gray-600">All grievances have been handled.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($grievances as $grievance): ?>
                    <div class="p-6">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center mb-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 mr-3">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        Grievance
                                    </span>
                                    <span class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($grievance['project_name']); ?> • 
                                        <?php echo htmlspecialchars($grievance['department_name']); ?>
                                    </span>
                                </div>

                                <div class="mb-4">
                                    <h4 class="text-lg font-semibold text-gray-900 mb-2">
                                        <?php echo htmlspecialchars($grievance['subject'] ?: 'Project Grievance'); ?>
                                    </h4>
                                    <div class="text-sm text-gray-600 mb-2">
                                        <strong>From:</strong> <?php echo htmlspecialchars($grievance['citizen_name']); ?>
                                        <?php if ($grievance['citizen_email']): ?>
                                            (<?php echo htmlspecialchars($grievance['citizen_email']); ?>)
                                        <?php endif; ?>
                                    </div>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <p class="text-gray-800"><?php echo nl2br(htmlspecialchars($grievance['message'])); ?></p>
                                    </div>
                                </div>

                                <?php if (!empty($grievance['admin_response']) || $grievance['status'] === 'resolved'): ?>
                                    <div class="mt-4 p-4 bg-green-50 rounded-lg">
                                        <h5 class="font-medium text-green-900 mb-2">✓ Resolved - Your Response:</h5>
                                        <p class="text-green-800"><?php echo nl2br(htmlspecialchars($grievance['admin_response'] ?? 'No response text available')); ?></p>
                                        <div class="text-sm text-green-600 mt-2">
                                            Resolved on: <?php echo date('M j, Y g:i A', strtotime($grievance['updated_at'] ?? $grievance['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <button onclick="showResolveModal(<?php echo $grievance['id']; ?>, '<?php echo htmlspecialchars($grievance['citizen_name'], ENT_QUOTES); ?>')" 
                                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700">
                                        <i class="fas fa-check mr-2"></i>Resolve Grievance
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div class="ml-6 text-right">
                                <div class="text-sm text-gray-500">
                                    <?php echo date('M j, Y g:i A', strtotime($grievance['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-semibold text-gray-900">Resolve Grievance</h3>
                    <button onclick="closeResolveModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form id="resolveForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="resolve_grievance">
                    <input type="hidden" name="feedback_id" id="resolveGrievanceId">
                    <input type="hidden" name="ajax" value="1">

                    <div class="mb-4">
                        <p class="text-sm text-gray-600">
                            Resolving grievance from: <span id="resolveGrievanceAuthor" class="font-medium"></span>
                        </p>
                    </div>

                    <div class="mb-6">
                        <label for="adminResponse" class="block text-sm font-medium text-gray-700 mb-2">Your Resolution Response *</label>
                        <textarea name="admin_response" id="adminResponse" rows="6" required
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                  placeholder="Provide a detailed response addressing the citizen's concerns..."></textarea>
                        <div class="mt-2 text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            This response will be sent as an email to the citizen and will also appear as a public reply on the project page.
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeResolveModal()" 
                                class="px-6 py-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-6 py-3 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700">
                            <i class="fas fa-check mr-2"></i>Resolve & Send Response
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showResolveModal(grievanceId, authorName) {
    document.getElementById('resolveGrievanceId').value = grievanceId;
    document.getElementById('resolveGrievanceAuthor').textContent = authorName;
    document.getElementById('adminResponse').value = '';
    document.getElementById('resolveModal').classList.remove('hidden');
}

function closeResolveModal() {
    document.getElementById('resolveModal').classList.add('hidden');
}

// Resolve form submission
document.getElementById('resolveForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Resolving...';
    submitBtn.disabled = true;

    const formData = new FormData(this);

    fetch('grievances.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeResolveModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while resolving the grievance.');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});
</script>

<?php include 'includes/adminFooter.php'; ?>