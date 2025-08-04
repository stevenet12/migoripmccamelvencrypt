<?php
// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unwanted output
ob_start();

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/EncryptionManager.php';
require_once '../includes/projectSubscriptions.php';

// Set proper headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Standard JSON response function
function sendJsonResponse($data) {
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode($data);
    exit;
}

// Handle PHP errors as JSON
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Subscribe API Error: $message in $file on line $line");
    sendJsonResponse(['success' => false, 'message' => 'A server error occurred. Please try again.']);
});

// Handle uncaught exceptions
set_exception_handler(function($exception) {
    error_log("Subscribe API Exception: " . $exception->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'A server error occurred. Please try again.']);
});

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

// Rate limit: max 3 requests per 5 mins
if (!enhanced_rate_limit('subscribe', 3, 300)) {
    http_response_code(429);
    sendJsonResponse(['success' => false, 'message' => 'Too many subscription attempts. Please try again later.']);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$project_id = (int)($input['project_id'] ?? 0);
$email = trim($input['email'] ?? '');

// Validate
if (!$project_id || !$email) {
    sendJsonResponse(['success' => false, 'message' => 'Project ID and email are required']);
}

// Verify project exists and is public
$project = get_project_by_id($project_id);
if (!$project || $project['visibility'] !== 'published') {
    sendJsonResponse(['success' => false, 'message' => 'This project is not available for subscriptions']);
}

try {
    // Ensure required classes are loaded
    if (!class_exists('EncryptionManager')) {
        require_once '../includes/EncryptionManager.php';
    }
    
    EncryptionManager::init($pdo);
    
    // Pass BASE_URL if defined, otherwise let it use empty string
    $base_url = defined('BASE_URL') ? BASE_URL : '';
    $subscription_manager = new ProjectSubscriptionManager($pdo, $base_url);
    
    $result = $subscription_manager->subscribe(
        $project_id,
        $email,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    );

    sendJsonResponse($result);

} catch (Exception $e) {
    error_log("Subscription API error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Service temporarily unavailable. Please try again later.']);
}
?>
