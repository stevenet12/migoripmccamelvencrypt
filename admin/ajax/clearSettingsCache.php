<?php
require_once '../includes/pageSecurity.php';
require_once '../../includes/systemSettings.php';

header('Content-Type: application/json');

$current_admin = get_current_admin();

// Check if user is super admin
if ($current_admin['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    // Clear the settings cache
    SystemSettings::clearCache();
    
    // Log the action
    if (function_exists('log_activity')) {
        log_activity('system_cache_cleared', 'Settings cache cleared', $current_admin['id'], 'system');
    }
    
    echo json_encode(['success' => true, 'message' => 'Settings cache cleared successfully']);
    
} catch (Exception $e) {
    error_log("Clear settings cache error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to clear cache: ' . $e->getMessage()]);
}
?>
