<?php
/**
 * Enhanced Audit Trail Management Class
 * Handles all audit trail operations, statistics, and encryption integration
 */

class AuditTrail {
    
    /**
     * Get audit statistics for the dashboard
     */
    public static function getAuditStats($days = 30) {
        global $pdo;
        
        try {
            $sql = "SELECT 
                        target_type as table_name,
                        COUNT(*) as total_operations,
                        SUM(CASE WHEN activity_type LIKE '%create%' THEN 1 ELSE 0 END) as creates,
                        SUM(CASE WHEN activity_type LIKE '%update%' THEN 1 ELSE 0 END) as updates,
                        SUM(CASE WHEN activity_type LIKE '%delete%' THEN 1 ELSE 0 END) as deletes,
                        SUM(CASE WHEN activity_type LIKE '%login%' THEN 1 ELSE 0 END) as logins,
                        SUM(CASE WHEN activity_type LIKE '%export%' THEN 1 ELSE 0 END) as exports
                    FROM admin_activity_log 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY target_type
                    ORDER BY total_operations DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$days]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process results for reading (decrypt if needed)
            if (class_exists('EncryptionManager')) {
                return EncryptionManager::processDataForReading('admin_activity_log', $results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Audit stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive security statistics
     */
    public static function getSecurityStats($days = 30) {
        global $pdo;
        
        try {
            $sql = "SELECT 
                        COUNT(*) as total_security_events,
                        SUM(CASE WHEN activity_type LIKE '%failed_login%' THEN 1 ELSE 0 END) as failed_logins,
                        SUM(CASE WHEN activity_type LIKE '%password_reset%' THEN 1 ELSE 0 END) as password_resets,
                        SUM(CASE WHEN activity_type LIKE '%account_locked%' THEN 1 ELSE 0 END) as account_lockouts,
                        SUM(CASE WHEN activity_type LIKE '%encryption%' THEN 1 ELSE 0 END) as encryption_events,
                        COUNT(DISTINCT admin_id) as unique_users,
                        COUNT(DISTINCT ip_address) as unique_ips
                    FROM admin_activity_log 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND (activity_type LIKE '%security%' OR activity_type LIKE '%login%' OR activity_type LIKE '%password%' OR activity_type LIKE '%encryption%')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$days]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Process result for reading (decrypt if needed)
            if (class_exists('EncryptionManager')) {
                return EncryptionManager::processDataForReading('admin_activity_log', $result);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Security stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive list of auditable tables
     */
    public static function getAuditableTables() {
        return [
            // Core admin tables
            'admins',
            'admin_activity_log',
            'login_attempts',
            'password_reset_tokens',
            'session_management',
            
            // Geographic and organizational data
            'departments', 
            'counties',
            'sub_counties',
            'wards',
            
            // Project management tables
            'projects',
            'project_steps',
            'project_transactions',
            'project_documents',
            'project_comments',
            'project_subscriptions',
            
            // Budget and financial tables
            'total_budget',
            'fund_sources',
            'transaction_types',
            
            // User feedback and communication
            'feedback',
            'feedback_notifications',
            'prepared_responses',
            
            // System and maintenance
            'import_logs',
            'publication_logs',
            'system_settings',
            'security_logs',
            'unified_logs'
        ];
    }
    
    /**
     * Check if a table has all required audit columns
     */
    public static function checkTableAuditColumns($table_name) {
        global $pdo;
        
        $required_columns = ['created_by', 'created_at', 'modified_by', 'modified_at'];
        $existing_columns = [];
        $missing_columns = [];
        
        try {
            // Get table structure
            $stmt = $pdo->prepare("DESCRIBE `{$table_name}`");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($required_columns as $required_col) {
                if (in_array($required_col, $columns)) {
                    $existing_columns[] = $required_col;
                } else {
                    $missing_columns[] = $required_col;
                }
            }
            
            return [
                'has_all_columns' => empty($missing_columns),
                'existing_columns' => $existing_columns,
                'missing_columns' => $missing_columns,
                'table_exists' => !empty($columns)
            ];
            
        } catch (Exception $e) {
            error_log("Table audit check error for {$table_name}: " . $e->getMessage());
            return [
                'has_all_columns' => false,
                'existing_columns' => [],
                'missing_columns' => $required_columns,
                'table_exists' => false
            ];
        }
    }
    
    /**
     * Enhanced audit trail logging with encryption support
     */
    public static function logActivity($admin_id, $activity_type, $target_type, $target_id, $description, $details = null, $old_values = null, $new_values = null, $changed_fields = null) {
        global $pdo;

        try {
            // Prepare data for potential encryption
            $audit_data = [
                'admin_id' => $admin_id,
                'activity_type' => $activity_type,
                'target_type' => $target_type,
                'target_id' => $target_id,
                'activity_description' => $description,
                'additional_data' => $details ? json_encode($details) : null,
                'old_values' => $old_values ? json_encode($old_values) : null,
                'new_values' => $new_values ? json_encode($new_values) : null,
                'changed_fields' => $changed_fields ? json_encode($changed_fields) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'session_id' => session_id() ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Apply encryption if available
            if (class_exists('EncryptionManager')) {
                $audit_data = EncryptionManager::processDataForStorage('admin_activity_log', $audit_data);
            }

            $sql = "INSERT INTO admin_activity_log 
                    (admin_id, activity_type, target_type, target_id, activity_description, 
                     additional_data, old_values, new_values, changed_fields, 
                     ip_address, user_agent, session_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $audit_data['admin_id'],
                $audit_data['activity_type'],
                $audit_data['target_type'],
                $audit_data['target_id'],
                $audit_data['activity_description'],
                $audit_data['additional_data'],
                $audit_data['old_values'],
                $audit_data['new_values'],
                $audit_data['changed_fields'],
                $audit_data['ip_address'],
                $audit_data['user_agent'],
                $audit_data['session_id'],
                $audit_data['created_at']
            ]);

            // Log to security_logs for critical events
            if (in_array($activity_type, ['login_success', 'login_failed', 'password_reset', 'account_locked', 'encryption_enabled', 'encryption_disabled'])) {
                self::logSecurityEvent($admin_id, $activity_type, $description, $details);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Enhanced audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log security-specific events
     */
    public static function logSecurityEvent($admin_id, $event_type, $description, $details = null) {
        global $pdo;
        
        try {
            $security_data = [
                'admin_id' => $admin_id,
                'event_type' => $event_type,
                'description' => $description,
                'details' => $details ? json_encode($details) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Apply encryption if available
            if (class_exists('EncryptionManager')) {
                $security_data = EncryptionManager::processDataForStorage('security_logs', $security_data);
            }
            
            $sql = "INSERT INTO security_logs 
                    (admin_id, event_type, description, details, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            return $stmt->execute(array_values($security_data));
            
        } catch (Exception $e) {
            error_log("Security event log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent audit activities with enhanced filtering and pagination
     */
    public static function getRecentActivities($limit = 50, $filters = [], $offset = 0) {
        global $pdo;

        try {
            // Build WHERE clause
            $whereConditions = [];
            $params = [];

            // Exclude routine activities to focus on audit-worthy events
            $auditWorthyTypes = [
                'create_%', 'update_%', 'delete_%', 'login_%', 'logout_%',
                'password_%', 'encryption_%', 'import_%', 'export_%',
                'admin_%', 'security_%', 'audit_%', 'system_%',
                'project_%', 'budget_%', 'document_%', 'feedback_%'
            ];

            $typeConditions = [];
            foreach ($auditWorthyTypes as $type) {
                $typeConditions[] = "aal.activity_type LIKE ?";
                $params[] = $type;
            }
            $whereConditions[] = "(" . implode(" OR ", $typeConditions) . ")";

            if (!empty($filters['date_from'])) {
                $whereConditions[] = "aal.created_at >= ?";
                $params[] = $filters['date_from'] . ' 00:00:00';
            }

            if (!empty($filters['date_to'])) {
                $whereConditions[] = "aal.created_at <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }

            if (!empty($filters['table'])) {
                $whereConditions[] = "aal.target_type = ?";
                $params[] = $filters['table'];
            }

            if (!empty($filters['admin'])) {
                $whereConditions[] = "aal.admin_id = ?";
                $params[] = $filters['admin'];
            }

            if (!empty($filters['action'])) {
                $whereConditions[] = "aal.activity_type LIKE ?";
                $params[] = "%{$filters['action']}%";
            }

            if (!empty($filters['severity'])) {
                if ($filters['severity'] === 'high') {
                    $whereConditions[] = "(aal.activity_type LIKE '%delete%' OR aal.activity_type LIKE '%encryption%' OR aal.activity_type LIKE '%failed%' OR aal.activity_type LIKE '%security%')";
                } elseif ($filters['severity'] === 'medium') {
                    $whereConditions[] = "(aal.activity_type LIKE '%update%' OR aal.activity_type LIKE '%login%' OR aal.activity_type LIKE '%admin%')";
                } elseif ($filters['severity'] === 'low') {
                    $whereConditions[] = "(aal.activity_type LIKE '%create%' OR aal.activity_type LIKE '%read%' OR aal.activity_type LIKE '%view%')";
                }
            }

            // Get total count for pagination
            $countSql = "SELECT COUNT(*) FROM admin_activity_log aal 
                        LEFT JOIN admins a ON aal.admin_id = a.id";
            if (!empty($whereConditions)) {
                $countSql .= " WHERE " . implode(" AND ", $whereConditions);
            }

            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalRecords = $countStmt->fetchColumn();

            // Get paginated results
            $sql = "SELECT 
                        aal.*,
                        a.name as admin_name,
                        a.email as admin_email
                    FROM admin_activity_log aal
                    LEFT JOIN admins a ON aal.admin_id = a.id";

            if (!empty($whereConditions)) {
                $sql .= " WHERE " . implode(" AND ", $whereConditions);
            }

            $sql .= " ORDER BY aal.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process results for reading (decrypt if needed)
            if (class_exists('EncryptionManager')) {
                $results = array_map(function($row) {
                    return EncryptionManager::processDataForReading('admin_activity_log', $row);
                }, $results);
            }

            return [
                'data' => $results,
                'total_records' => $totalRecords,
                'current_page' => floor($offset / $limit) + 1,
                'total_pages' => ceil($totalRecords / $limit),
                'limit' => $limit,
                'offset' => $offset
            ];

        } catch (Exception $e) {
            error_log("Get recent activities error: " . $e->getMessage());
            return [
                'data' => [],
                'total_records' => 0,
                'current_page' => 1,
                'total_pages' => 1,
                'limit' => $limit,
                'offset' => 0
            ];
        }
    }
    
    /**
     * Get audit trail for specific record
     */
    public static function getRecordAuditTrail($table, $record_id, $limit = 20) {
        global $pdo;
        
        try {
            $sql = "SELECT 
                        aal.*,
                        a.name as admin_name,
                        a.email as admin_email
                    FROM admin_activity_log aal
                    LEFT JOIN admins a ON aal.admin_id = a.id
                    WHERE aal.target_type = ? AND aal.target_id = ?
                    ORDER BY aal.created_at DESC 
                    LIMIT ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$table, $record_id, $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process results for reading (decrypt if needed)
            if (class_exists('EncryptionManager')) {
                return array_map(function($row) {
                    return EncryptionManager::processDataForReading('admin_activity_log', $row);
                }, $results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Get record audit trail error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add missing audit columns to a table
     */
    public static function addAuditColumnsToTable($table_name) {
        global $pdo;

        try {
            $status = self::checkTableAuditColumns($table_name);

            if (!$status['table_exists']) {
                return ['success' => false, 'message' => "Table $table_name does not exist"];
            }

            if ($status['has_all_columns']) {
                return ['success' => true, 'message' => "Table $table_name already has all audit columns"];
            }

            $pdo->beginTransaction();

            // Get table structure to find the last column
            $stmt = $pdo->query("DESCRIBE `$table_name`");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $lastColumn = end($columns)['Field'];

            foreach ($status['missing_columns'] as $column) {
                try {
                    switch ($column) {
                        case 'created_by':
                            $sql = "ALTER TABLE `$table_name` ADD COLUMN `created_by` INT(11) NULL";
                            break;
                        case 'created_at':
                            $sql = "ALTER TABLE `$table_name` ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP";
                            break;
                        case 'modified_by':
                            $sql = "ALTER TABLE `$table_name` ADD COLUMN `modified_by` INT(11) NULL";
                            break;
                        case 'modified_at':
                            $sql = "ALTER TABLE `$table_name` ADD COLUMN `modified_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
                            break;
                    }

                    $pdo->exec($sql);
                    error_log("Added audit column '$column' to table '$table_name'");
                } catch (Exception $colError) {
                    error_log("Failed to add column '$column' to table '$table_name': " . $colError->getMessage());
                    // Continue with other columns
                }
            }

            $pdo->commit();

            // Log the audit column addition
            self::logActivity(
                $_SESSION['admin_id'] ?? 1,
                'audit_setup',
                'system',
                0,
                "Added audit columns to table: $table_name",
                ['table' => $table_name, 'columns_added' => $status['missing_columns']]
            );

            return ['success' => true, 'message' => "Successfully processed audit columns for $table_name"];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Failed to add audit columns to $table_name: " . $e->getMessage());
            return ['success' => false, 'message' => "Failed to add audit columns: " . $e->getMessage()];
        }
    }
    
    /**
     * Fix all auditable tables by adding missing columns
     */
    public static function fixAllAuditableTables() {
        $results = [];
        $auditable_tables = self::getAuditableTables();
        
        foreach ($auditable_tables as $table) {
            $results[$table] = self::addAuditColumnsToTable($table);
        }
        
        return $results;
    }
    
    /**
     * Enhanced audit trail verification
     */
    public static function verifyAuditIntegrity() {
        global $pdo;
        
        $integrity_report = [
            'overall_status' => 'healthy',
            'issues' => [],
            'recommendations' => [],
            'statistics' => []
        ];
        
        try {
            // Check if audit table exists and has proper structure
            $audit_check = self::checkTableAuditColumns('admin_activity_log');
            if (!$audit_check['table_exists']) {
                $integrity_report['overall_status'] = 'critical';
                $integrity_report['issues'][] = 'Audit trail table does not exist';
                return $integrity_report;
            }
            
            // Check for recent audit activity
            $stmt = $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $recent_count = $stmt->fetchColumn();
            $integrity_report['statistics']['recent_24h'] = $recent_count;
            
            if ($recent_count == 0) {
                $integrity_report['issues'][] = 'No audit activity in the last 24 hours';
                $integrity_report['recommendations'][] = 'Verify audit logging is working properly';
            }
            
            // Check for missing audit columns in auditable tables
            $tables_without_audit = [];
            foreach (self::getAuditableTables() as $table) {
                $check = self::checkTableAuditColumns($table);
                if ($check['table_exists'] && !$check['has_all_columns']) {
                    $tables_without_audit[] = $table;
                }
            }
            
            if (!empty($tables_without_audit)) {
                $integrity_report['issues'][] = 'Tables missing audit columns: ' . implode(', ', $tables_without_audit);
                $integrity_report['recommendations'][] = 'Run fixAllAuditableTables() to add missing columns';
            }
            
            // Check for encryption integration
            if (class_exists('EncryptionManager')) {
                $encryption_status = EncryptionManager::getEncryptionStatus();
                $integrity_report['statistics']['encryption_enabled'] = $encryption_status['encryption_enabled'];
                
                if (!$encryption_status['encryption_enabled']) {
                    $integrity_report['recommendations'][] = 'Consider enabling encryption for sensitive audit data';
                }
            }
            
            // Determine overall status
            if (!empty($integrity_report['issues'])) {
                $integrity_report['overall_status'] = count($integrity_report['issues']) > 2 ? 'critical' : 'warning';
            }
            
        } catch (Exception $e) {
            $integrity_report['overall_status'] = 'error';
            $integrity_report['issues'][] = 'Error during integrity check: ' . $e->getMessage();
        }
        
        return $integrity_report;
    }

    /**
     * Export comprehensive audit trail data
     */
    public static function exportAuditData($filters = [], $format = 'csv') {
        global $pdo;
        
        try {
            $sql = "SELECT 
                        aal.created_at,
                        a.name as admin_name,
                        a.email as admin_email,
                        aal.activity_type,
                        aal.target_type,
                        aal.target_id,
                        aal.activity_description,
                        aal.ip_address,
                        aal.user_agent,
                        aal.session_id,
                        CASE 
                            WHEN aal.activity_type LIKE '%delete%' OR aal.activity_type LIKE '%encryption%' THEN 'High'
                            WHEN aal.activity_type LIKE '%update%' OR aal.activity_type LIKE '%login%' THEN 'Medium'
                            ELSE 'Low'
                        END as severity_level
                    FROM admin_activity_log aal
                    LEFT JOIN admins a ON aal.admin_id = a.id
                    WHERE 1=1";
            
            $params = [];
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND aal.created_at >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND aal.created_at <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['severity'])) {
                if ($filters['severity'] === 'high') {
                    $sql .= " AND (aal.activity_type LIKE '%delete%' OR aal.activity_type LIKE '%encryption%' OR aal.activity_type LIKE '%failed%')";
                }
            }
            
            $sql .= " ORDER BY aal.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process results for reading (decrypt if needed)
            if (class_exists('EncryptionManager')) {
                $results = array_map(function($row) {
                    return EncryptionManager::processDataForReading('admin_activity_log', $row);
                }, $results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Export enhanced audit data error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old audit entries (retention policy)
     */
    public static function cleanupOldAuditEntries($retention_days = 365) {
        global $pdo;
        
        try {
            $pdo->beginTransaction();
            
            // Archive old entries before deletion
            $archive_sql = "INSERT INTO admin_activity_log_archive 
                           SELECT * FROM admin_activity_log 
                           WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            
            $stmt = $pdo->prepare($archive_sql);
            $stmt->execute([$retention_days]);
            $archived_count = $stmt->rowCount();
            
            // Delete old entries
            $delete_sql = "DELETE FROM admin_activity_log 
                          WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            
            $stmt = $pdo->prepare($delete_sql);
            $stmt->execute([$retention_days]);
            $deleted_count = $stmt->rowCount();
            
            $pdo->commit();
            
            // Log the cleanup activity
            self::logActivity(
                $_SESSION['admin_id'] ?? 1,
                'audit_cleanup',
                'system',
                0,
                "Cleaned up old audit entries",
                [
                    'retention_days' => $retention_days,
                    'archived_count' => $archived_count,
                    'deleted_count' => $deleted_count
                ]
            );
            
            return [
                'success' => true,
                'archived' => $archived_count,
                'deleted' => $deleted_count
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Audit cleanup error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Enhanced audit trail logging function with automatic change detection
 */
function log_audit_trail($admin_id, $action, $table_name, $record_id, $old_values = null, $new_values = null, $ip_address = null) {
    // Determine changed fields if both old and new values exist
    $changed_fields = null;
    if ($old_values && $new_values && is_array($old_values) && is_array($new_values)) {
        $changed_fields = [];
        foreach ($new_values as $key => $new_value) {
            if (isset($old_values[$key]) && $old_values[$key] != $new_value) {
                $changed_fields[] = $key;
            }
        }
    }

    // Enhanced description with more context
    $description = $action . " on " . $table_name . " (ID: " . $record_id . ")";
    if ($changed_fields) {
        $description .= " - Fields changed: " . implode(', ', $changed_fields);
    }

    return AuditTrail::logActivity(
        $admin_id,
        $action,
        $table_name,
        $record_id,
        $description,
        [
            'ip_address' => $ip_address ?? $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
        ],
        $old_values,
        $new_values,
        $changed_fields
    );
}

/**
 * Enhanced audit verification with comprehensive checks
 */
function verify_audit_trail() {
    global $pdo;

    try {
        // Check if admin_activity_log table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'admin_activity_log'");
        $table_exists = $stmt->rowCount() > 0;

        if (!$table_exists) {
            error_log("AUDIT TRAIL VERIFICATION: Table 'admin_activity_log' does not exist!");
            return false;
        }

        // Check table structure
        $required_columns = ['admin_id', 'activity_type', 'target_type', 'target_id', 'activity_description', 
                           'ip_address', 'user_agent', 'session_id', 'created_at'];
        
        $stmt = $pdo->query("DESCRIBE admin_activity_log");
        $existing_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $missing_columns = array_diff($required_columns, $existing_columns);
        if (!empty($missing_columns)) {
            error_log("AUDIT TRAIL VERIFICATION: Missing columns: " . implode(', ', $missing_columns));
        }

        // Test insertion with enhanced data
        $test_result = AuditTrail::logActivity(
            1, 
            'system_verification', 
            'system', 
            999, 
            'Enhanced audit trail verification test',
            [
                'test_type' => 'comprehensive_verification',
                'timestamp' => time(),
                'encryption_available' => class_exists('EncryptionManager')
            ]
        );

        if ($test_result) {
            error_log("AUDIT TRAIL VERIFICATION: Enhanced test entry successful");
            
            // Run integrity check
            $integrity = AuditTrail::verifyAuditIntegrity();
            error_log("AUDIT TRAIL VERIFICATION: Integrity status - " . $integrity['overall_status']);
            
            return true;
        } else {
            error_log("AUDIT TRAIL VERIFICATION: Enhanced test entry failed");
            return false;
        }

    } catch (Exception $e) {
        error_log("AUDIT TRAIL VERIFICATION ERROR: " . $e->getMessage());
        return false;
    }
}

/**
 * Automatic audit trail trigger for database operations
 */
function auto_audit_trigger($table, $operation, $record_id, $old_data = null, $new_data = null) {
    $admin_id = $_SESSION['admin_id'] ?? 1;
    
    // Skip audit logging for audit tables to prevent recursion
    if (in_array($table, ['admin_activity_log', 'security_logs', 'unified_logs'])) {
        return;
    }
    
    // Only log for auditable tables
    if (!in_array($table, AuditTrail::getAuditableTables())) {
        return;
    }
    
    $activity_type = strtolower($operation) . '_' . $table;
    $description = ucfirst($operation) . " operation on " . $table . " record";
    
    return log_audit_trail($admin_id, $activity_type, $table, $record_id, $old_data, $new_data);
}
?>