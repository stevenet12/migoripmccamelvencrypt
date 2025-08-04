<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Require admin access
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Only super admins can trigger maintenance
if ($_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $maintenance_result = schedule_database_maintenance();
        
        echo json_encode([
            'success' => true,
            'data' => $maintenance_result
        ]);
        
    } catch (Exception $e) {
        error_log("Database maintenance trigger error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to trigger maintenance'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
