<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';
require_once '../includes/EncryptionManager.php';
require_once '../includes/auditTrail.php';

require_admin();
if (!hasPagePermission('manage_documents')) {
    header('Location: index.php?error=access_denied');
    exit;
}

EncryptionManager::init($pdo);
$current_admin = get_current_admin();
log_activity('document_manager_access', 'Accessed document manager', $current_admin['id']);

$document_types = [
    "Project Approval Letter", "Tender Notice", "Signed Contract Agreement", "Award Notification",
    "Site Visit Report", "Completion Certificate", "Tender Opening Minutes", "PMC Appointment Letter",
    "Budget Approval Form", "PMC Workplan", "Supervision Report", "Final Joint Inspection Report", "Other"
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $action = $_POST['action'];

        if ($action === 'upload' || $action === 'edit_document') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $document_type = sanitize_input($_POST['document_type'] ?? 'Other');
        $document_title = sanitize_input($_POST['document_title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $edit_document_id = intval($_POST['edit_document_id'] ?? 0);

        // Validate required fields
        if (empty($document_title)) {
            $error = 'Document title is required';
        } elseif ($project_id && ($current_admin['role'] === 'super_admin' || owns_project($project_id, $current_admin['id']))) {
            
            if ($action === 'edit_document' && $edit_document_id > 0) {
                // Edit existing document
                try {
                    $pdo->beginTransaction();

                    // Get current document details for audit
                    $stmt = $pdo->prepare("SELECT pd.*, p.project_name, p.created_by as project_owner 
                                         FROM project_documents pd 
                                         JOIN projects p ON pd.project_id = p.id
                                         WHERE pd.id = ? AND pd.document_status = 'active'");
                    $stmt->execute([$edit_document_id]);
                    $document = $stmt->fetch();

                    if (!$document) {
                        throw new Exception('Document not found or has been deleted');
                    }

                    // Check permissions
                    if ($current_admin['role'] !== 'super_admin' && $document['project_owner'] != $current_admin['id']) {
                        throw new Exception('Access denied - you can only edit documents from your own projects');
                    }

                    // Decrypt old document data for comparison
                    $old_doc_decrypted = EncryptionManager::processDataForReading('project_documents', $document);

                    // Prepare new data for encryption
                    $new_data = [
                        'document_title' => $document_title,
                        'document_type' => $document_type,
                        'description' => $description
                    ];
                    $encrypted_data = EncryptionManager::processDataForStorage('project_documents', $new_data);

                    // Update document details
                    $stmt = $pdo->prepare("UPDATE project_documents SET 
                        document_title = ?, document_type = ?, description = ?, modified_by = ?, modified_at = NOW() 
                        WHERE id = ?");
                    $result = $stmt->execute([
                        $encrypted_data['document_title'],
                        $encrypted_data['document_type'],
                        $encrypted_data['description'],
                        $current_admin['id'], 
                        $edit_document_id
                    ]);

                    if ($result && $stmt->rowCount() > 0) {
                        // Enhanced audit trail logging
                        AuditTrail::logActivity(
                            $current_admin['id'],
                            'document_update',
                            'project_documents',
                            $edit_document_id,
                            "Updated document: {$document_title} (Project: {$document['project_name']})",
                            [
                                'project_id' => $document['project_id'],
                                'admin_name' => $current_admin['name'],
                                'admin_email' => $current_admin['email'],
                                'updated_fields' => ['document_title', 'document_type', 'description'],
                                'changes' => [
                                    'title' => ['from' => $old_doc_decrypted['document_title'], 'to' => $document_title],
                                    'type' => ['from' => $old_doc_decrypted['document_type'], 'to' => $document_type],
                                    'description' => ['from' => $old_doc_decrypted['description'], 'to' => $description]
                                ]
                            ],
                            $old_doc_decrypted,
                            $new_data,
                            ['document_title', 'document_type', 'description']
                        );

                        log_activity('document_edited', "Updated document '{$document_title}' in project '{$document['project_name']}'", $current_admin['id']);

                        $pdo->commit();
                        $success = 'Document updated successfully';
                    } else {
                        throw new Exception('Failed to update document - no changes made');
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Document edit error: " . $e->getMessage());
                    $error = 'Error updating document: ' . $e->getMessage();
                }
            } elseif (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                // Upload new document
                $upload_result = secure_file_upload($_FILES['document']);
                if ($upload_result['success']) {
                    try {
                        $data = [
                            'project_id' => $project_id,
                            'document_type' => $document_type,
                            'document_title' => $document_title,
                            'description' => $description,
                            'filename' => $upload_result['filename'],
                            'original_name' => $upload_result['original_name'],
                            'file_size' => $upload_result['file_size'],
                            'mime_type' => $upload_result['mime_type'],
                            'uploaded_by' => $current_admin['id']
                        ];

                        $data = EncryptionManager::processDataForStorage('project_documents', $data);

                        $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, document_type, document_title, description, filename, original_name, file_size, mime_type, uploaded_by, document_status, version_number, is_public, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, 1, NOW())");
                        $stmt->execute([
                            $data['project_id'], $data['document_type'], $data['document_title'],
                            $data['description'], $data['filename'], $data['original_name'],
                            $data['file_size'], $data['mime_type'], $data['uploaded_by']
                        ]);

                        $doc_id = $pdo->lastInsertId();

                        // Log to audit trail
                        AuditTrail::logActivity(
                            $current_admin['id'],
                            'document_upload',
                            'project_documents',
                            $doc_id,
                            "Uploaded document: $document_title ($document_type) for project #$project_id",
                            [
                                'project_id' => $project_id,
                                'document_type' => $document_type,
                                'file_size' => $upload_result['file_size'],
                                'mime_type' => $upload_result['mime_type']
                            ],
                            null,
                            $data
                        );

                        log_activity('document_upload', "Uploaded document: $document_title", $current_admin['id']);

                        $success = 'Document uploaded successfully';
                    } catch (Exception $e) {
                        error_log("Document upload error: " . $e->getMessage());
                        $error = 'Failed to save document information';
                    }
                } else {
                    $error = $upload_result['message'];
                }
            } else {
                $error = 'No file selected or upload failed';
            }
        } else {
            $error = 'Access denied or invalid project';
        }
    }

    if ($action === 'delete_document') {
        $document_id = (int)$_POST['document_id'];
        $deletion_reason = sanitize_input($_POST['deletion_reason'] ?? 'Manual deletion by admin');

        if (!$document_id) {
            $error = 'Invalid document ID';
        } else {
            try {
                $pdo->beginTransaction();

                // Get document details for audit trail - Use project_documents table consistently
                $stmt = $pdo->prepare("SELECT pd.*, p.project_name, p.created_by as project_owner 
                                     FROM project_documents pd 
                                     JOIN projects p ON pd.project_id = p.id
                                     WHERE pd.id = ? AND pd.document_status = 'active'");
                $stmt->execute([$document_id]);
                $document = $stmt->fetch();

                if (!$document) {
                    throw new Exception('Document not found or already deleted');
                }

                // Check permissions
                if ($current_admin['role'] !== 'super_admin' && $document['project_owner'] != $current_admin['id']) {
                    throw new Exception('Access denied - you can only delete documents from your own projects');
                }

                // Decrypt document data for audit
                $doc_decrypted = EncryptionManager::processDataForReading('project_documents', $document);

                // Mark document as deleted instead of actually deleting
                $stmt = $pdo->prepare("UPDATE project_documents SET 
                    document_status = 'deleted', modified_by = ?, modified_at = NOW() 
                    WHERE id = ?");
                $result = $stmt->execute([$current_admin['id'], $document_id]);

                if ($result && $stmt->rowCount() > 0) {
                    // Enhanced audit trail logging
                    AuditTrail::logActivity(
                        $current_admin['id'],
                        'document_delete',
                        'project_documents',
                        $document_id,
                        "Deleted document: {$doc_decrypted['document_title']} (Project: {$document['project_name']})",
                        [
                            'project_id' => $document['project_id'],
                            'document_type' => $doc_decrypted['document_type'],
                            'document_title' => $doc_decrypted['document_title'],
                            'filename' => $doc_decrypted['filename'],
                            'original_name' => $doc_decrypted['original_name'],
                            'file_size' => $document['file_size'],
                            'admin_name' => $current_admin['name'],
                            'admin_email' => $current_admin['email'],
                            'deletion_reason' => $deletion_reason
                        ],
                        $doc_decrypted,
                        ['document_status' => 'deleted', 'modified_by' => $current_admin['id']]
                    );

                    // Also log to activity logs
                    log_activity('document_deleted', "Deleted document '{$doc_decrypted['document_title']}' from project '{$document['project_name']}'", $current_admin['id']);

                    $pdo->commit();
                    $success = 'Document deleted successfully';
                } else {
                    throw new Exception('Failed to delete document - no rows affected');
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Document deletion error: " . $e->getMessage());
                $error = 'Error deleting document: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'edit_document_details') {
        $document_id = (int)$_POST['document_id'];
        $new_title = sanitize_input($_POST['new_title'] ?? '');
        $new_type = sanitize_input($_POST['new_type'] ?? '');
        $new_description = sanitize_input($_POST['new_description'] ?? '');
        $edit_reason = sanitize_input($_POST['edit_reason'] ?? 'Document details updated by admin');

        if (!$document_id) {
            $error = 'Invalid document ID';
        } elseif (empty($new_title)) {
            $error = 'Document title is required';
        } else {
            try {
                $pdo->beginTransaction();

                // Get current document details - Use project_documents table consistently
                $stmt = $pdo->prepare("SELECT pd.*, p.project_name, p.created_by as project_owner 
                                     FROM project_documents pd 
                                     JOIN projects p ON pd.project_id = p.id
                                     WHERE pd.id = ? AND pd.document_status = 'active'");
                $stmt->execute([$document_id]);
                $document = $stmt->fetch();

                if (!$document) {
                    throw new Exception('Document not found or has been deleted');
                }

                // Check permissions
                if ($current_admin['role'] !== 'super_admin' && $document['project_owner'] != $current_admin['id']) {
                    throw new Exception('Access denied - you can only edit documents from your own projects');
                }

                // Decrypt old document data for comparison
                $old_doc_decrypted = EncryptionManager::processDataForReading('project_documents', $document);

                // Validate title length
                $new_title = trim($new_title);
                if (strlen($new_title) < 3) {
                    throw new Exception('Document title must be at least 3 characters long');
                }

                // Prepare new data for encryption
                $new_data = [
                    'document_title' => $new_title,
                    'document_type' => $new_type ?: $old_doc_decrypted['document_type'],
                    'description' => $new_description
                ];
                $encrypted_data = EncryptionManager::processDataForStorage('project_documents', $new_data);

                // Update document details
                $stmt = $pdo->prepare("UPDATE project_documents SET 
                    document_title = ?, document_type = ?, description = ?, modified_by = ?, modified_at = NOW() 
                    WHERE id = ?");
                $result = $stmt->execute([
                    $encrypted_data['document_title'],
                    $encrypted_data['document_type'],
                    $encrypted_data['description'],
                    $current_admin['id'], 
                    $document_id
                ]);

                if ($result && $stmt->rowCount() > 0) {
                    // Enhanced audit trail logging with detailed change tracking
                    AuditTrail::logActivity(
                        $current_admin['id'],
                        'document_update',
                        'project_documents',
                        $document_id,
                        "Updated document: {$new_title} (Project: {$document['project_name']})",
                        [
                            'project_id' => $document['project_id'],
                            'admin_name' => $current_admin['name'],
                            'admin_email' => $current_admin['email'],
                            'edit_reason' => $edit_reason,
                            'updated_fields' => ['document_title', 'document_type', 'description'],
                            'changes' => [
                                'title' => ['from' => $old_doc_decrypted['document_title'], 'to' => $new_title],
                                'type' => ['from' => $old_doc_decrypted['document_type'], 'to' => $new_type],
                                'description' => ['from' => $old_doc_decrypted['description'], 'to' => $new_description]
                            ]
                        ],
                        $old_doc_decrypted,
                        $new_data,
                        ['document_title', 'document_type', 'description']
                    );

                    // Also log to activity logs
                    log_activity('document_edited', "Updated document '{$new_title}' in project '{$document['project_name']}'", $current_admin['id']);

                    $pdo->commit();
                    $success = 'Document updated successfully';
                } else {
                    throw new Exception('Failed to update document - no changes made');
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Document edit error: " . $e->getMessage());
                $error = 'Error updating document: ' . $e->getMessage();
            }
        }
    }

        switch ($action) {
            case 'edit':
                $doc_id = intval($_POST['document_id'] ?? 0);
                $document_title = sanitize_input($_POST['document_title'] ?? '');
                $document_type = sanitize_input($_POST['document_type'] ?? '');
                $description = sanitize_input($_POST['description'] ?? '');

                if ($doc_id) {
                    try {
                        // Get old values for audit
                        $stmt = $pdo->prepare("SELECT * FROM project_documents WHERE id = ? AND document_status = 'active'");
                        $stmt->execute([$doc_id]);
                        $old_doc = $stmt->fetch();

                        if ($old_doc && ($current_admin['role'] === 'super_admin' || owns_project($old_doc['project_id'], $current_admin['id']))) {
                            // Decrypt old values for comparison
                            $old_doc_decrypted = EncryptionManager::processDataForReading('project_documents', $old_doc);

                            $new_data = [
                                'document_title' => $document_title,
                                'document_type' => $document_type,
                                'description' => $description
                            ];

                            $encrypted_data = EncryptionManager::processDataForStorage('project_documents', $new_data);

                            $stmt = $pdo->prepare("UPDATE project_documents SET document_title = ?, document_type = ?, description = ?, modified_by = ?, modified_at = NOW() WHERE id = ?");
                            $stmt->execute([
                                $encrypted_data['document_title'],
                                $encrypted_data['document_type'],
                                $encrypted_data['description'],
                                $current_admin['id'],
                                $doc_id
                            ]);

                            // Enhanced audit trail logging with detailed change tracking
                            AuditTrail::logActivity(
                                $current_admin['id'],
                                'document_update',
                                'project_documents',
                                $doc_id,
                                "Updated document: {$document_title} (Project ID: {$old_doc['project_id']})",
                                [
                                    'project_id' => $old_doc['project_id'],
                                    'admin_name' => $current_admin['name'],
                                    'admin_email' => $current_admin['email'],
                                    'updated_fields' => ['document_title', 'document_type', 'description'],
                                    'changes' => [
                                        'title' => ['from' => $old_doc_decrypted['document_title'], 'to' => $document_title],
                                        'type' => ['from' => $old_doc_decrypted['document_type'], 'to' => $document_type],
                                        'description' => ['from' => $old_doc_decrypted['description'], 'to' => $description]
                                    ]
                                ],
                                $old_doc_decrypted,
                                $new_data,
                                ['document_title', 'document_type', 'description']
                            );

                            log_activity('document_update', "Updated document: {$document_title} by {$current_admin['name']}", $current_admin['id']);

                            $success = 'Document updated successfully';
                        } else {
                            $error = 'Document not found or access denied';
                        }
                    } catch (Exception $e) {
                        error_log("Document edit error: " . $e->getMessage());
                        $error = 'Failed to update document';
                    }
                }
                break;

            case 'delete':
                $doc_id = intval($_POST['document_id'] ?? 0);

                if ($doc_id) {
                    try {
                        // Get document details for audit
                        $stmt = $pdo->prepare("SELECT * FROM project_documents WHERE id = ? AND document_status = 'active'");
                        $stmt->execute([$doc_id]);
                        $doc = $stmt->fetch();

                        if ($doc && ($current_admin['role'] === 'super_admin' || owns_project($doc['project_id'], $current_admin['id']))) {
                            // Decrypt document data for audit
                            $doc_decrypted = EncryptionManager::processDataForReading('project_documents', $doc);

                            // Mark as deleted instead of actual deletion
                            $stmt = $pdo->prepare("UPDATE project_documents SET document_status = 'deleted', modified_by = ?, modified_at = NOW() WHERE id = ?");
                            $stmt->execute([$current_admin['id'], $doc_id]);

                            // Enhanced audit trail logging
                            AuditTrail::logActivity(
                                $current_admin['id'],
                                'document_delete',
                                'project_documents',
                                $doc_id,
                                "Deleted document: {$doc_decrypted['document_title']} (Project ID: {$doc['project_id']})",
                                [
                                    'project_id' => $doc['project_id'],
                                    'document_type' => $doc_decrypted['document_type'],
                                    'document_title' => $doc_decrypted['document_title'],
                                    'filename' => $doc_decrypted['filename'],
                                    'original_name' => $doc_decrypted['original_name'],
                                    'file_size' => $doc['file_size'],
                                    'admin_name' => $current_admin['name'],
                                    'admin_email' => $current_admin['email'],
                                    'deletion_reason' => 'Manual deletion by admin'
                                ],
                                $doc_decrypted,
                                ['document_status' => 'deleted', 'modified_by' => $current_admin['id']]
                            );

                            log_activity('document_delete', "Deleted document: {$doc_decrypted['document_title']} by {$current_admin['name']}", $current_admin['id']);

                            $success = 'Document deleted successfully';
                        } else {
                            $error = 'Document not found or access denied';
                        }
                    } catch (Exception $e) {
                        error_log("Document delete error: " . $e->getMessage());
                        $error = 'Failed to delete document';
                    }
                }
                break;
        }
    }
}

// Get project ID from URL parameter
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$edit_doc_id = isset($_GET['edit_doc']) ? (int)$_GET['edit_doc'] : 0;
$current_project = null;
$edit_document = null;

// Get projects accessible to current admin
$accessible_projects = [];
if ($current_admin['role'] === 'super_admin') {
    $stmt = $pdo->query("SELECT id, project_name FROM projects ORDER BY project_name");
    $accessible_projects = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, project_name FROM projects WHERE created_by = ? ORDER BY project_name");
    $stmt->execute([$current_admin['id']]);
    $accessible_projects = $stmt->fetchAll();
}

// Get current project details if project_id is provided
if ($project_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $current_project = $stmt->fetch();

    if (!$current_project || ($current_admin['role'] !== 'super_admin' && $current_project['created_by'] != $current_admin['id'])) {
        $current_project = null;
        $project_id = 0;
    }
}

// Get document data for editing if edit_doc_id is provided
if ($edit_doc_id > 0) {
    $stmt = $pdo->prepare("SELECT pd.*, p.project_name, p.created_by as project_owner 
                          FROM project_documents pd 
                          JOIN projects p ON pd.project_id = p.id
                          WHERE pd.id = ? AND pd.document_status = 'active'");
    $stmt->execute([$edit_doc_id]);
    $edit_document = $stmt->fetch();

    if ($edit_document) {
        // Check permissions
        if ($current_admin['role'] !== 'super_admin' && $edit_document['project_owner'] != $current_admin['id']) {
            $edit_document = null;
            $edit_doc_id = 0;
        } else {
            // Decrypt document data for editing
            $edit_document = EncryptionManager::processDataForReading('project_documents', $edit_document);
            $project_id = $edit_document['project_id'];
            $current_project = [
                'id' => $edit_document['project_id'],
                'project_name' => $edit_document['project_name']
            ];
        }
    } else {
        $edit_doc_id = 0;
    }
}


// Get comprehensive statistics
$stats = [];

// Total documents
$sql = "SELECT COUNT(*) FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active'";
$params = [];
if ($current_admin['role'] !== 'super_admin') {
    $sql .= " AND p.created_by = ?";
    $params[] = $current_admin['id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats['total_documents'] = $stmt->fetchColumn();

// Documents by type
$sql = "SELECT pd.document_type, COUNT(*) as count FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active'";
if ($current_admin['role'] !== 'super_admin') {
    $sql .= " AND p.created_by = ?";
}
$sql .= " GROUP BY pd.document_type ORDER BY count DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats['by_type'] = $stmt->fetchAll();

// Documents by project (top 5)
$sql = "SELECT p.project_name, COUNT(*) as count FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active'";
if ($current_admin['role'] !== 'super_admin') {
    $sql .= " AND p.created_by = ?";
}
$sql .= " GROUP BY p.id, p.project_name ORDER BY count DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats['by_project'] = $stmt->fetchAll();

// Total file size
$sql = "SELECT SUM(pd.file_size) FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active'";
if ($current_admin['role'] !== 'super_admin') {
    $sql .= " AND p.created_by = ?";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats['total_size'] = $stmt->fetchColumn() ?: 0;

// Recent uploads (last 7 days)
$sql = "SELECT COUNT(*) FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active' AND pd.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
if ($current_admin['role'] !== 'super_admin') {
    $sql .= " AND p.created_by = ?";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats['recent_uploads'] = $stmt->fetchColumn();

// Get filter parameters
$selected_project = intval($_GET['project_id'] ?? $project_id);
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Check if document_status column exists
$has_document_status = false;
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM project_transaction_documents LIKE 'document_status'");
    $has_document_status = $check_column->rowCount() > 0;
} catch (Exception $e) {
    // Fallback if we can't check
    $has_document_status = false;
}

// Get documents with filtering
$status_filter = $has_document_status ? " AND (ptd.document_status IS NULL OR ptd.document_status != 'deleted')" : "";

$documents_sql = "
    SELECT ptd.id, ptd.original_filename, ptd.file_size, ptd.mime_type, ptd.created_at,
           ptd.uploaded_by, uploader.name as uploader_name,
           pt.id as transaction_id, pt.transaction_type, pt.amount, pt.description,
           p.id as project_id, p.project_name
           " . ($has_document_status ? ", ptd.document_status, ptd.deletion_reason, ptd.deleted_by, ptd.deleted_at" : "") . "
    FROM project_transaction_documents ptd
    JOIN project_transactions pt ON ptd.transaction_id = pt.id
    JOIN projects p ON pt.project_id = p.id
    LEFT JOIN admins uploader ON ptd.uploaded_by = uploader.id
    WHERE p.created_by = ?" . $status_filter;

$count_sql = "
    SELECT COUNT(DISTINCT ptd.id)
    FROM project_transaction_documents ptd
    JOIN project_transactions pt ON ptd.transaction_id = pt.id
    JOIN projects p ON pt.project_id = p.id
    WHERE p.created_by = ?" . $status_filter;

$params = [$current_admin['id']];

// Build documents query
if ($selected_project > 0) {
    // Show all documents for selected project
    $documents_sql = "
        SELECT pd.*, p.project_name, a.name as uploader_name
        FROM project_documents pd
        JOIN projects p ON pd.project_id = p.id
        LEFT JOIN admins a ON pd.uploaded_by = a.id
        WHERE pd.document_status = 'active' AND pd.project_id = ?
    ";
    $count_sql = "SELECT COUNT(*) FROM project_documents pd WHERE pd.document_status = 'active' AND pd.project_id = ?";
    $doc_params = [$selected_project];
    $count_params = [$selected_project];

    // Check access
    if ($current_admin['role'] !== 'super_admin') {
        $documents_sql .= " AND p.created_by = ?";
        $count_sql .= " AND EXISTS (SELECT 1 FROM projects WHERE id = pd.project_id AND created_by = ?)";
        $doc_params[] = $current_admin['id'];
        $count_params[] = $current_admin['id'];
    }
} else {
    // Show latest 5 documents when no project selected
    $documents_sql = "
        SELECT pd.*, p.project_name, a.name as uploader_name
        FROM project_documents pd
        JOIN projects p ON pd.project_id = p.id
        LEFT JOIN admins a ON pd.uploaded_by = a.id
        WHERE pd.document_status = 'active'
    ";
    $count_sql = "SELECT COUNT(*) FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.document_status = 'active'";
    $doc_params = [];
    $count_params = [];

    if ($current_admin['role'] !== 'super_admin') {
        $documents_sql .= " AND p.created_by = ?";
        $count_sql .= " AND p.created_by = ?";
        $doc_params[] = $current_admin['id'];
        $count_params[] = $current_admin['id'];
    }

    $per_page = 5; // Limit to 5 latest documents
}

// Add search functionality
if (!empty($search)) {
    $search_term = '%' . $search . '%';
    $documents_sql .= " AND (pd.document_title LIKE ? OR pd.original_name LIKE ? OR p.project_name LIKE ?)";
    $count_sql .= " AND (pd.document_title LIKE ? OR pd.original_name LIKE ? OR p.project_name LIKE ?)";
    $doc_params = array_merge($doc_params, [$search_term, $search_term, $search_term]);
    $count_params = array_merge($count_params, [$search_term, $search_term, $search_term]);
}

// Get total count
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_documents = $count_stmt->fetchColumn();
$total_pages = ceil($total_documents / $per_page);

// Get documents
$documents_sql .= " ORDER BY pd.created_at DESC LIMIT ? OFFSET ?";
$doc_params[] = $per_page;
$doc_params[] = $offset;

$stmt = $pdo->prepare($documents_sql);
$stmt->execute($doc_params);
$documents = $stmt->fetchAll();

// Decrypt sensitive data
foreach ($documents as &$doc) {
    $doc = EncryptionManager::processDataForReading('project_documents', $doc);
    if (!empty($doc['uploader_name'])) {
        $doc['uploader_name'] = EncryptionManager::decryptIfNeeded($doc['uploader_name']);
    }
}
unset($doc);

$page_title = $current_project ? "Document Manager - " . $current_project['project_name'] : "Document Manager";
include 'includes/adminHeader.php';
?>

<!-- Breadcrumb -->
<div class="mb-4">
    <nav class="flex text-sm" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2">
            <li><a href="index.php" class="text-gray-500 hover:text-gray-700">Dashboard</a></li>
            <li class="text-gray-400">/</li>
            <?php if ($current_project): ?>
                <li><a href="manageProject.php?id=<?php echo $current_project['id']; ?>" class="text-gray-500 hover:text-gray-700"><?php echo htmlspecialchars($current_project['project_name']); ?></a></li>
                <li class="text-gray-400">/</li>
            <?php endif; ?>
            <li class="text-gray-600 font-medium">Documents</li>
        </ol>
    </nav>
</div>

<!-- Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 bg-green-50 border-l-4 border-green-400 p-4">
        <p class="text-green-700"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4">
        <p class="text-red-700"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="mb-6 bg-green-50 border-l-4 border-green-400 p-4">
        <p class="text-green-700"><?php echo htmlspecialchars($success); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4">
        <p class="text-red-700"><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<!-- Upload Section -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
    <div class="p-6 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">
            <?php echo $edit_document ? 'Edit Document' : 'Upload Document'; ?>
        </h3>
    </div>
    <div class="p-6">
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="<?php echo $edit_document ? 'edit_document' : 'upload'; ?>">
            <input type="hidden" name="project_id" id="project_id" value="<?php echo $project_id; ?>">
            <?php if ($edit_document): ?>
                <input type="hidden" name="edit_document_id" value="<?php echo $edit_doc_id; ?>">
            <?php endif; ?>

            <!-- Project Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Project *</label>
                <div class="relative">
                    <input type="text" id="project_search" 
                           placeholder="Search for a project..." 
                           value="<?php echo $current_project ? htmlspecialchars($current_project['project_name']) : ''; ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm"
                           autocomplete="off">
                    <div id="project_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                        <!-- Search results will appear here -->
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Document Type *</label>
                    <select name="document_type" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">Select Document Type</option>
                        <?php foreach ($document_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                    <?php echo ($edit_document && $edit_document['document_type'] === $type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Document Title *</label>
                    <input type="text" name="document_title" required 
                           value="<?php echo $edit_document ? htmlspecialchars($edit_document['document_title']) : ''; ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea name="description" rows="3" 
                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="Enter document description"><?php echo $edit_document ? htmlspecialchars($edit_document['description']) : ''; ?></textarea>
            </div>

            <?php if ($edit_document): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-800">
                        <strong>Currently editing:</strong> <?php echo htmlspecialchars($edit_document['original_name']); ?>
                        <br><span class="text-xs">Upload a new file to replace the current document, or leave empty to keep the existing file.</span>
                    </p>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Document File <?php echo $edit_document ? '(Optional - leave empty to keep current file)' : '*'; ?>
                </label>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg">
                    <div class="space-y-1 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div class="flex text-sm text-gray-600">
                            <label for="document" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500">
                                <span>Upload a file</span>
                                <input id="document" name="document" type="file" class="sr-only" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" <?php echo $edit_document ? '' : 'required'; ?>>
                            </label>
                            <p class="pl-1">or drag and drop</p>
                        </div>
                        <p class="text-xs text-gray-500">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG up to 20MB</p>
                        <p id="file-name-display" class="text-sm text-gray-700 mt-2"></p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <?php if ($edit_document): ?>
                    <a href="documentManager.php<?php echo $project_id ? '?project_id=' . $project_id : ''; ?>" 
                       class="inline-flex items-center px-6 py-3 border border-gray-300 text-sm font-medium rounded-lg shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Cancel
                    </a>
                <?php endif; ?>
                <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-<?php echo $edit_document ? 'save' : 'upload'; ?> mr-2"></i>
                    <?php echo $edit_document ? 'Update Document' : 'Upload Document'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Filter and Search -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
    <div class="p-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search documents..." 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
            </div>

            <select name="project_id" class="px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Projects</option>
                <?php foreach ($accessible_projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php echo $selected_project == $project['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="flex space-x-3">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-4 py-3 rounded-lg hover:bg-blue-700 text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i> Filter
                </button>
                <a href="documentManager.php" class="px-4 py-3 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-medium">
                    Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Documents List -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="p-6 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">
            <?php if ($selected_project > 0): ?>
                Project Documents (<?php echo number_format($total_documents); ?>)
            <?php else: ?>
                Latest Documents (<?php echo number_format(min(5, $total_documents)); ?> of <?php echo number_format($total_documents); ?>)
            <?php endif; ?>
        </h3>
    </div>

    <?php if (empty($documents)): ?>
        <div class="p-12 text-center">
            <i class="fas fa-file-alt text-4xl text-gray-400 mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Documents Found</h3>
            <p class="text-gray-600">
                <?php if ($selected_project > 0): ?>
                    No documents found for this project.
                <?php else: ?>
                    Upload your first document to get started.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Uploaded By</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($documents as $doc): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <i class="fas fa-file text-gray-400 mr-3"></i>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($doc['document_title'] ?? $doc['original_name']); ?>
                                        </div>
                                        <?php if ($doc['description']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo htmlspecialchars($doc['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($doc['document_type'] ?? 'Other'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($doc['project_name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo format_bytes($doc['file_size']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($doc['uploader_name'] ?? 'Unknown'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <div class="flex items-center space-x-2" style="position: relative !important; z-index: 100 !important;">
                                    <!-- View/Download -->
                                    <a href="../api/viewDocument.php?type=project&id=<?php echo $doc['id']; ?>" 
                                       target="_blank" 
                                       class="text-blue-600 hover:text-blue-900 bg-blue-50 px-2 py-1 rounded text-xs font-medium">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>

                                    <a href="downloadDocument.php?id=<?php echo $doc['id']; ?>" 
                                       class="text-green-600 hover:text-green-900 bg-green-50 px-2 py-1 rounded text-xs font-medium ml-1">
                                        <i class="fas fa-download mr-1"></i>Download
                                    </a>

                                    <!-- Edit -->
                                    <a href="documentManager.php?edit_doc=<?php echo $doc['id']; ?><?php echo $selected_project ? '&project_id=' . $selected_project : ''; ?>" 
                                       class="text-yellow-600 hover:text-yellow-900 bg-yellow-50 px-2 py-1 rounded text-xs font-medium">
                                        <i class="fas fa-edit mr-1"></i>Edit
                                    </a>

                                    <!-- Delete -->
                                    <button type="button"
                                            onclick="deleteDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['document_title'], ENT_QUOTES); ?>')" 
                                            class="text-red-600 hover:text-red-900 bg-red-50 px-2 py-1 rounded text-xs font-medium"
                                            style="position: relative !important; z-index: 200 !important; pointer-events: auto !important;">
                                        <i class="fas fa-trash mr-1"></i>Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Showing <?php echo $offset + 1; ?> to 
                        <?php echo min($offset + $per_page, $total_documents); ?> of 
                        <?php echo number_format($total_documents); ?> results
                    </div>
                    <nav class="flex items-center space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                                ‹ Previous
                            </a>
                        <?php endif; ?>

                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-3 py-2 border <?php echo $i === $page ? 'border-blue-500 bg-blue-50 text-blue-600' : 'border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-lg text-sm">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                                Next ›
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Document Modal -->
<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Document</h3>
            <form id="editForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="edit_document_details">
                <input type="hidden" name="document_id" id="edit_document_id">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Document Title</label>
                    <input type="text" name="document_title" id="edit_document_title" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Document Type</label>
                    <select name="document_type" id="edit_document_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($document_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea name="description" id="edit_description" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                        Update Document
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php include 'includes/adminFooter.php'; ?>