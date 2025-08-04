<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/EncryptionManager.php';
require_once '../includes/auditTrail.php';

require_admin();

$current_admin = get_current_admin();

try {
    // Check if document ID is provided
    if (!isset($_GET['id'])) {
        throw new Exception('Missing document ID');
    }

    $document_id = (int)$_GET['id'];

    // Initialize encryption
    EncryptionManager::init($pdo);

    // Get project document with access check
    $stmt = $pdo->prepare("
        SELECT pd.*, p.project_name, p.created_by
        FROM project_documents pd
        LEFT JOIN projects p ON pd.project_id = p.id
        WHERE pd.id = ? AND pd.document_status = 'active'
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        throw new Exception('Document not found');
    }

    // Check access permissions
    if ($current_admin['role'] !== 'super_admin' && $document['created_by'] != $current_admin['id']) {
        throw new Exception('Access denied');
    }

    // Process encrypted data
    $document = EncryptionManager::processDataForReading('project_documents', $document);

    $file_path = '../uploads/' . $document['filename'];
    $filename = $document['original_name'] ?? $document['original_filename'];

    // Check if file exists
    if (!file_exists($file_path)) {
        throw new Exception('File not found on server');
    }

    // Log download activity
    AuditTrail::logActivity(
        $current_admin['id'],
        'document_download',
        'project_documents',
        $document_id,
        "Downloaded document: " . $document['document_title'],
        [
            'project_id' => $document['project_id'],
            'filename' => $filename,
            'file_size' => filesize($file_path)
        ]
    );

    log_activity('document_download', "Downloaded document: " . $document['document_title'], $current_admin['id']);

    // Get file info
    $file_info = pathinfo($file_path);
    $file_extension = strtolower($file_info['extension']);

    // Set appropriate content type
    $content_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain'
    ];

    $content_type = $content_types[$file_extension] ?? 'application/octet-stream';

    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set headers for file download
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . filesize($file_path));
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    // Output file content
    readfile($file_path);
    exit;

} catch (Exception $e) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code(404);
    header('Content-Type: text/html');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Download Error</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error { color: #dc3545; }
        </style>
    </head>
    <body>
        <h1 class="error">Download Error</h1>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <a href="documentManager.php">Back to Document Manager</a>
    </body>
    </html>';
}
?>