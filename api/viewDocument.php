<?php
require_once '../config.php';
require_once '../includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Check if required parameters are provided
    if (!isset($_GET['type']) || !isset($_GET['id'])) {
        throw new Exception('Missing required parameters');
    }

    $type = $_GET['type'];
    $document_id = (int)$_GET['id'];

    if ($type === 'project') {
        // Get project document
        $stmt = $pdo->prepare("
            SELECT pd.*, p.project_name 
            FROM project_documents pd
            LEFT JOIN projects p ON pd.project_id = p.id
            WHERE pd.id = ? AND pd.document_status = 'active'
        ");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch();

        if (!$document) {
            throw new Exception('Document not found');
        }

        // Process encrypted data
        if (class_exists('EncryptionManager')) {
            require_once '../includes/EncryptionManager.php';
            EncryptionManager::init($pdo);
            $document = EncryptionManager::processDataForReading('project_documents', $document);
        }

        $file_path = '../uploads/' . $document['filename'];
        $filename = $document['original_name'] ?? $document['original_filename'];

    } elseif ($type === 'transaction') {
        // Get transaction document
        $stmt = $pdo->prepare("
            SELECT ptd.*, pt.description, p.project_name
            FROM project_transaction_documents ptd
            LEFT JOIN project_transactions pt ON ptd.transaction_id = pt.id
            LEFT JOIN projects p ON pt.project_id = p.id
            WHERE ptd.id = ?
        ");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch();

        if (!$document) {
            throw new Exception('Document not found');
        }

        $file_path = '../uploads/' . $document['file_path'];
        $filename = $document['original_filename'];

    } else {
        throw new Exception('Invalid document type');
    }

    // Check if file exists
    if (!file_exists($file_path)) {
        throw new Exception('File not found on server');
    }

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

    // Set headers for file viewing
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . filesize($file_path));
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    // For PDF files, add additional headers to ensure proper viewing
    if ($file_extension === 'pdf') {
        header('Accept-Ranges: bytes');
    }

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
        <title>Document Not Found</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error { color: #dc3545; }
        </style>
    </head>
    <body>
        <h1 class="error">Document Not Found</h1>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <button onclick="window.close()">Close</button>
    </body>
    </html>';
}
?>