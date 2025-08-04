<?php
// Core helper functions for the project management system
function pdo_select_one($pdo, $sql, $params = [], $context = '') {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Decrypt result using EncryptionManager if applicable
        if ($result && class_exists('EncryptionManager') && !empty($context)) {
            return EncryptionManager::processDataForReading($context, $result);
        }

        return $result;
    } catch (Exception $e) {
        error_log("SELECT_ONE ERROR in {$context}: " . $e->getMessage());
        return false;
    }
}

function pdo_select_all($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("SELECT_ALL ERROR: " . $e->getMessage());
        return false;
    }
}

function pdo_insert($pdo, $table, $data) {
    try {
        // Add audit trail data if table supports it
        if (table_has_audit_columns($table)) {
            $data = add_create_audit_data($data, $table);
        }

        // Process encryption if enabled
        if (class_exists('EncryptionManager')) {
            $data = EncryptionManager::processDataForStorage($table, $data);
        }

        $columns = array_keys($data);
        $placeholders = ':' . implode(', :', $columns);
        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $result = $stmt->execute();

        if ($result && table_needs_audit_trail($table)) {
            $record_id = $pdo->lastInsertId();
            $admin_id = $_SESSION['admin_id'] ?? null;
            if ($admin_id) {
                AuditTrail::logActivity(
                    $admin_id, 
                    'create', 
                    $table, 
                    $record_id, 
                    "Created new record in {$table}",
                    null, // additional_data
                    null, // old_values (none for create)
                    $data, // new_values
                    array_keys($data) // changed_fields (all fields for create)
                );
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("INSERT ERROR for table {$table}: " . $e->getMessage());
        return false;
    }
}

function pdo_update($pdo, $table, $data, $where) {
    try {
        // Get original data for audit trail
        $original_data = null;
        if (table_needs_audit_trail($table)) {
            $where_clause = [];
            $where_params = [];
            foreach ($where as $key => $value) {
                $where_clause[] = "`{$key}` = ?";
                $where_params[] = $value;
            }
            $select_sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where_clause);
            $stmt = $pdo->prepare($select_sql);
            $stmt->execute($where_params);
            $original_data = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Add audit trail data if table supports it
        if (table_has_audit_columns($table)) {
            $data = add_update_audit_data($data);
        }

        // Process encryption if enabled
        if (class_exists('EncryptionManager')) {
            $data = EncryptionManager::processDataForStorage($table, $data);
        }

        $set_clause = [];
        foreach (array_keys($data) as $column) {
            $set_clause[] = "`{$column}` = :{$column}";
        }

        $where_clause = [];
        foreach (array_keys($where) as $column) {
            $where_clause[] = "`{$column}` = :where_{$column}";
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $set_clause) . " WHERE " . implode(' AND ', $where_clause);

        $stmt = $pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        foreach ($where as $key => $value) {
            $stmt->bindValue(':where_' . $key, $value);
        }

        $result = $stmt->execute();

        if ($result && table_needs_audit_trail($table) && $original_data) {
            $admin_id = $_SESSION['admin_id'] ?? null;
            if ($admin_id) {
                $record_id = $original_data['id'] ?? 'unknown';

                // Determine changed fields
                $changed_fields = [];
                foreach ($data as $key => $new_value) {
                    if (isset($original_data[$key]) && $original_data[$key] != $new_value) {
                        $changed_fields[] = $key;
                    }
                }

                AuditTrail::logActivity(
                    $admin_id, 
                    'update', 
                    $table, 
                    $record_id, 
                    "Updated record in {$table}",
                    null, // additional_data
                    $original_data, // old_values
                    $data, // new_values
                    $changed_fields // changed_fields
                );
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("UPDATE ERROR for table {$table}: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || time() - $_SESSION['csrf_token_time'] > 1800) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }

    // Check if token has expired (30 minutes)
    if (time() - $_SESSION['csrf_token_time'] > 1800) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}


/**
 * Format currency for display
 */
function format_currency($amount) {
    return 'KSh ' . number_format($amount, 2);
}

/**
 * Calculate progress percentage
 */
function calculate_progress($completed, $total) {
    if ($total == 0) return 0;
    return round(($completed / $total) * 100, 2);
}

/**
 * Generate random string
 */
function generate_random_string($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if user has permission
 */
function has_permission($permission) {
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        return false;
    }

    if ($_SESSION['admin_role'] === 'super_admin') {
        return true;
    }

    return isset($_SESSION['admin_permissions']) && 
           is_array($_SESSION['admin_permissions']) && 
           in_array($permission, $_SESSION['admin_permissions']);
}

/**
 * Get current admin user data
 */
function get_current_admin() {
    if (!is_logged_in()) {
        return null;
    }

    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT id, name, email, role, last_login, last_ip FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin_data) {
            return EncryptionManager::processDataForReading('admins', $admin_data);
        }
    } catch (Exception $e) {
        error_log("Error fetching admin data: " . $e->getMessage());
    }

    // Fallback to session data
    return [
        'id' => $_SESSION['admin_id'],
        'email' => $_SESSION['admin_email'],
        'name' => $_SESSION['admin_name'],
        'role' => $_SESSION['admin_role'],
        'last_login' => null,
        'last_ip' => null
    ];
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/projectStepsTemplates.php';

// Include RBAC and Auth before defining functions to avoid conflicts
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/EncryptionManager.php';
require_once __DIR__ . '/auditTrail.php';

/**
 * Security Functions
 */
if (!function_exists('csrf_protect')) {
function csrf_protect() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}
}

/**
 * Enhanced PDO functions that automatically handle both encryption and audit trails
 */

// Check if table needs audit trail
function table_needs_audit_trail($table) {
    $audit_tables = [
        'admins', 'projects', 'project_steps', 'project_documents', 
        'project_transactions', 'total_budget', 'feedback', 'departments',
        'counties', 'sub_counties', 'wards', 'fund_sources', 
        'transaction_types', 'prepared_responses', 'project_comments'
    ];
    return in_array($table, $audit_tables);
}

// Check if table actually has audit columns
function table_has_audit_columns($table) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("DESCRIBE `{$table}`");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $required_columns = ['created_by', 'created_at', 'modified_by', 'modified_at'];

        foreach ($required_columns as $required_col) {
            if (!in_array($required_col, $columns)) {
                return false;
            }
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Add audit trail data to insert operations
function add_create_audit_data($data, $table = null) {
    $admin_id = $_SESSION['admin_id'] ?? null;
    $audit_data = $data;

    // Only add audit columns if table supports them
    if ($table && table_has_audit_columns($table)) {
        $audit_data['created_by'] = $admin_id;
        $audit_data['created_at'] = date('Y-m-d H:i:s');
        $audit_data['modified_by'] = $admin_id;
        $audit_data['modified_at'] = date('Y-m-d H:i:s');
    }

    return $audit_data;
}

// Add audit trail data to update operations
function add_update_audit_data($data) {
    $admin_id = $_SESSION['admin_id'] ?? null;
    $audit_data = $data;
    $audit_data['modified_by'] = $admin_id;
    $audit_data['modified_at'] = date('Y-m-d H:i:s');
    return $audit_data;
}

/**
 * PDO Helper functions for easy encryption integration with audit trail support
 */
function pdo_select($pdo, $sql, $params, $table, $fetch_all = true) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $fetch_all ? $stmt->fetchAll() : $stmt->fetch();

    // Process results for reading using EncryptionManager
    if (class_exists('EncryptionManager')) {
        if ($fetch_all && is_array($results)) {
            return array_map(function($row) use ($table) {
                return EncryptionManager::processDataForReading($table, $row);
            }, $results);
        } elseif ($results) {
            return EncryptionManager::processDataForReading($table, $results);
        }
    }

    return $results;
}

function pdo_decrypt_results($results, $table) {
    if (class_exists('EncryptionManager')) {
        if (is_array($results)) {
            return array_map(function($row) use ($table) {
                return EncryptionManager::processDataForReading($table, $row);
            }, $results);
        }
        return EncryptionManager::processDataForReading($table, $results);
    }
    return $results;
}

function pdo_encrypt_data($data, $table) {
    if (class_exists('EncryptionManager')) {
        return EncryptionManager::processDataForStorage($table, $data);
    }
    return $data;
}

/**
 * Advanced SQL injection prevention
 */
function secure_sql_prepare($query, $params = []) {
    global $pdo;

    try {
        $stmt = $pdo->prepare($query);

        foreach ($params as $key => $value) {
            $param_type = PDO::PARAM_STR;

            if (is_int($value)) {
                $param_type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $param_type = PDO::PARAM_BOOL;
            } elseif (is_null($value)) {
                $param_type = PDO::PARAM_NULL;
            }

            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $param_type);
            } else {
                $stmt->bindValue($key, $value, $param_type);
            }
        }

        return $stmt;
    } catch (PDOException $e) {
        error_log("SQL Prepare Error: " . $e->getMessage());
        throw new Exception("Database operation failed");
    }
}

/**
 * XSS Protection for output
 */
function safe_output($data, $allow_html = false) {
    if ($allow_html) {
        // Allow only safe HTML tags
        $allowed_tags = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6>';
        return strip_tags($data, $allowed_tags);
    }

    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

/**
 * Rate limiting with enhanced tracking
 */
function enhanced_rate_limit($action, $limit = 5, $time_window = 60, $block_duration = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => time(),
            'blocked_until' => 0
        ];
        return true;
    }

    $current = $_SESSION[$key];

    // Check if currently blocked
    if ($current['blocked_until'] > time()) {
        log_activity('rate_limit_blocked', "Blocked request for action: $action", null, 'security', null, [
            'ip' => $ip,
            'remaining_block_time' => $current['blocked_until'] - time()
        ]);
        return false;
    }

    // Reset if time window expired
    if (time() - $current['first_attempt'] > $time_window) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => time(),
            'blocked_until' => 0
        ];
        return true;
    }

    // Check if limit exceeded
    if ($current['attempts'] >= $limit) {
        $_SESSION[$key]['blocked_until'] = time() + $block_duration;

        log_activity('rate_limit_exceeded', "Rate limit exceeded for action: $action", null, 'security', null, [
            'ip' => $ip,
            'attempts' => $current['attempts'],
            'block_duration' => $block_duration
        ]);
        return false;
    }

    $_SESSION[$key]['attempts']++;
    return true;
}

/**
 * Content Security Policy headers
 */
function set_security_headers() {
    // Prevent clickjacking
    header("X-Frame-Options: DENY");

    // Prevent MIME type sniffing
    header("X-Content-Type-Options: nosniff");

    // XSS Protection
    header("X-XSS-Protection: 1; mode=block");

    // Referrer Policy
    header("Referrer-Policy: strict-origin-when-cross-origin");

    // Content Security Policy (basic)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
           "img-src 'self' data: https:; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "connect-src 'self';";

    header("Content-Security-Policy: $csp");

    // Feature Policy
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
}

/**
 * Data Formatting Functions
 */
function format_date($date) {
    return date('M d, Y', strtotime($date));
}

/**
 * Create URL-friendly slug from project name
 */
function create_url_slug($text) {
    // Remove HTML tags
    $text = strip_tags($text);

    // Convert to lowercase
    $text = strtolower($text);

    // Replace spaces and special characters with hyphens
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);

    // Remove multiple consecutive hyphens
    $text = preg_replace('/-+/', '-', $text);

    // Remove leading/trailing hyphens
    $text = trim($text, '-');

    return $text;
}

/**
 * Generate WordPress-style project URL with title slug
 */
function generate_project_url($project_id, $project_title) {
    // Create URL-friendly slug from project title
    $slug = strtolower(trim($project_title));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    // Ensure slug is not empty
    if (empty($slug)) {
        $slug = 'project-' . $project_id;
    }

    return BASE_URL . "projectDetails/{$project_id}/{$slug}/";
}

/**
 * Generate project slug from title
 */
function create_project_slug($title) {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

/**
 * Check if current admin owns a specific project
 */
function owns_project($project_id, $admin_id = null) {
    global $pdo;

    if ($admin_id === null) {
        $admin_id = $_SESSION['admin_id'] ?? null;
    }

    if (!$admin_id) {
        return false;
    }

    // Super admins can access all projects
    if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
        return true;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM projects WHERE id = ? AND created_by = ?");
    $stmt->execute([$project_id, $admin_id]);
    return $stmt->fetch() ? true : false;
}

/**
 * Build role-based filter for database queries
 */
function build_role_filter($admin) {
    if ($admin['role'] === 'super_admin') {
        return [
            'filter' => '',
            'params' => []
        ];
    } elseif ($admin['role'] === 'admin' || $admin['role'] === 'county_admin') {
        return [
            'filter' => ' WHERE created_by = ?',
            'params' => [$admin['id']]
        ];
    } else {
        return [
            'filter' => ' WHERE created_by = ?',
            'params' => [$admin['id']]
        ];
    }
}

function get_status_badge_class($status) {
    $classes = [
        'planning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        'ongoing' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        'suspended' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
        'cancelled' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
}

function get_progress_color_class($percentage) {
    if ($percentage >= 80) return 'bg-green-500';
    if ($percentage >= 60) return 'bg-blue-500';
    if ($percentage >= 40) return 'bg-yellow-500';
    if ($percentage >= 20) return 'bg-orange-500';
    return 'bg-red-500';
}

function get_status_text_class($status) {
    switch ($status) {
        case 'completed': return 'text-green-600 dark:text-green-400';
        case 'ongoing': return 'text-blue-600 dark:text-blue-400';
        case 'planning': return 'text-yellow-600 dark:text-yellow-400';
        case 'suspended': return 'text-red-600 dark:text-red-400';
        case 'cancelled': return 'text-gray-600 dark:text-gray-400';
        default: return 'text-gray-600 dark:text-gray-400';
    }
}

function get_feedback_status_badge_class($status) {
    $classes = [
        'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        'approved' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
        'reviewed' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'responded' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
}

/**
 * Budget Functions
 */
function get_project_budget($project_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM total_budget WHERE project_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1");
        $stmt->execute([$project_id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get project budget error: " . $e->getMessage());
        return null;
    }
}

function get_project_budget_summary($project_id) {
    global $pdo;

    try {
        // Get approved budget
        $budget_stmt = $pdo->prepare("SELECT budget_amount FROM total_budget WHERE project_id = ? AND approval_status = 'approved' AND is_active = 1 ORDER BY version DESC LIMIT 1");
        $budget_stmt->execute([$project_id]);
        $approved_budget = $budget_stmt->fetchColumn() ?: 0;

        // Get allocations, disbursements, and expenditures (only active transactions)
        $trans_stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'allocation' AND transaction_status = 'active' THEN amount ELSE 0 END), 0) as allocated,
                COALESCE(SUM(CASE WHEN transaction_type = 'disbursement' AND transaction_status = 'active' THEN amount ELSE 0 END), 0) as disbursed,
                COALESCE(SUM(CASE WHEN transaction_type = 'expenditure' AND transaction_status = 'active' THEN amount ELSE 0 END), 0) as spent
            FROM project_transactions 
            WHERE project_id = ?
        ");
        $trans_stmt->execute([$project_id]);
        $transactions = $trans_stmt->fetch();

        $allocated = $transactions['allocated'] ?: 0;
        $disbursed = $transactions['disbursed'] ?: 0;
        $spent = $transactions['spent'] ?: 0;

        return [
            'approved_budget' => $approved_budget,
            'allocated' => $allocated,
            'disbursed' => $disbursed,
            'spent' => $spent,
            'remaining' => $allocated - $disbursed - $spent,
            'available_for_disbursement' => $allocated - $disbursed,
            'utilization_percentage' => $allocated > 0 ? (($disbursed + $spent) / $allocated) * 100 : 0
        ];
    } catch (Exception $e) {
        error_log("Get project budget summary error: " . $e->getMessage());
        return [
            'approved_budget' => 0,
            'allocated' => 0,
            'disbursed' => 0,
            'spent' => 0,
            'remaining' => 0,
            'available_for_disbursement' => 0,
            'utilization_percentage' => 0
        ];
    }
}

/**
 * Get transaction history with all status changes
 */
function get_transaction_history($project_id, $include_deleted = true) {
    global $pdo;

    try {
        $sql = "SELECT pt.*, 
                       creator.name as created_by_name,
                       modifier.name as modified_by_name,
                       ptd.original_filename as document_filename
                FROM project_transactions pt
                LEFT JOIN admins creator ON pt.created_by = creator.id
                LEFT JOIN admins modifier ON pt.modified_by = modifier.id
                LEFT JOIN project_transaction_documents ptd ON pt.id = ptd.transaction_id
                WHERE pt.project_id = ?";

        $params = [$project_id];

        if (!$include_deleted) {
            $sql .= " AND pt.transaction_status != 'deleted'";
        }

        $sql .= " ORDER BY pt.created_at DESC";

        $stmt = pdo_select($pdo, $sql, $params, 'project_transactions', true);
        return $stmt;

    } catch (Exception $e) {
        error_log("Get transaction history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create transaction with history tracking
 */
function create_transaction_with_history($project_id, $transaction_data, $admin_id) {
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Use pdo_insert for automatic encryption
        $result = pdo_insert($pdo, 'project_transactions', [
            'project_id' => $project_id,
            'transaction_type' => $transaction_data['transaction_type'],
            'amount' => $transaction_data['amount'],
            'description' => $transaction_data['description'],
            'transaction_date' => $transaction_data['transaction_date'],
            'reference_number' => $transaction_data['reference_number'],
            'fund_source' => $transaction_data['fund_source'],
            'funding_category' => $transaction_data['funding_category'],
            'voucher_number' => $transaction_data['voucher_number'] ?? null,
            'disbursement_method' => $transaction_data['disbursement_method'] ?? null,
            'receipt_number' => $transaction_data['receipt_number'] ?? null,
            'bank_receipt_reference' => $transaction_data['bank_receipt_reference'] ?? null,
            'created_by' => $admin_id,
            'transaction_status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new Exception('Failed to create transaction');
        }

        $transaction_id = $pdo->lastInsertId();

        // Log the activity
        log_activity('transaction_created', 
                    "Created {$transaction_data['transaction_type']} transaction of KES " . number_format($transaction_data['amount']), 
                    $admin_id, 'transaction', $transaction_id);

        $pdo->commit();
        return ['success' => true, 'transaction_id' => $transaction_id];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Create transaction with history error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Edit transaction with history preservation
 */
function edit_transaction_with_history($transaction_id, $new_data, $edit_reason, $admin_id) {
    global $pdo;
    try {
        $pdo->beginTransaction();        // Get the original transaction with decryption
        $original = pdo_select_one($pdo, "SELECT * FROM project_transactions WHERE id = ?", [$transaction_id], 'project_transactions');

        if (!$original) {
            throw new Exception('Transaction not found');
        }

        // Create a history entry of the original using pdo_insert
        pdo_insert($pdo, 'project_transactions', [
            'project_id' => $original['project_id'],
            'transaction_type' => $original['transaction_type'],
            'amount' => $original['amount'],
            'description' => $original['description'],
            'transaction_date' => $original['transaction_date'],
            'reference_number' => $original['reference_number'],
            'fund_source' => $original['fund_source'],
            'funding_category' => $original['funding_category'],
            'voucher_number' => $original['voucher_number'],
            'disbursement_method' => $original['disbursement_method'],
            'receipt_number' => $original['receipt_number'],
            'bank_receipt_reference' => $original['bank_receipt_reference'],
            'created_by' => $original['created_by'],
            'created_at' => $original['created_at'],
            'transaction_status' => 'edited',
            'original_transaction_id' => $transaction_id,
            'edit_reason' => $edit_reason,
            'modified_by' => $admin_id,
            'modified_at' => date('Y-m-d H:i:s')
        ]);

        // Update the original transaction using pdo_update
        $result = pdo_update($pdo, 'project_transactions', [
            'transaction_type' => $new_data['transaction_type'],
            'amount' => $new_data['amount'],
            'description' => $new_data['description'],
            'transaction_date' => $new_data['transaction_date'],
            'reference_number' => $new_data['reference_number'],
            'fund_source' => $new_data['fund_source'],
            'funding_category' => $new_data['funding_category'],
            'voucher_number' => $new_data['voucher_number'] ?? null,
            'disbursement_method' => $new_data['disbursement_method'] ?? null,
            'receipt_number' => $new_data['receipt_number'] ?? null,
            'bank_receipt_reference' => $new_data['bank_receipt_reference'] ?? null,
            'modified_by' => $admin_id,
            'modified_at' => date('Y-m-d H:i:s')
        ], ['id' => $transaction_id]);

        if (!$result) {
            throw new Exception('Failed to update transaction');
        }

        // Log the activity
        log_activity('transaction_edited', 
                    "Edited transaction ID $transaction_id. Reason: $edit_reason", 
                    $admin_id, 'transaction', $transaction_id);

        $pdo->commit();
        return ['success' => true];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Edit transaction with history error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Soft delete transaction with history preservation
 */
function delete_transaction_with_history($transaction_id, $deletion_reason, $admin_id) {
    global $pdo;

    try {
        $result = pdo_update($pdo, 'project_transactions', [
            'transaction_status' => 'deleted',
            'deletion_reason' => $deletion_reason,
            'modified_by' => $admin_id,
            'modified_at' => date('Y-m-d H:i:s')
        ], ['id' => $transaction_id]);

        if (!$result) {
            throw new Exception('Failed to delete transaction');
        }

        // Log the activity
        log_activity('transaction_deleted', 
                    "Deleted transaction ID $transaction_id. Reason: $deletion_reason", 
                    $admin_id, 'transaction', $transaction_id);

        return ['success' => true];

    } catch (Exception $e) {
        error_log("Delete transaction with history error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Project Functions
 */
function get_projects($filters = []) {
    global $pdo;

    $sql = "SELECT p.*, d.name as department_name, w.name as ward_name, 
                   sc.name as sub_county_name, c.name as county_name
            FROM projects p
            JOIN departments d ON p.department_id = d.id
            JOIN wards w ON p.ward_id = w.id
            JOIN sub_counties sc ON p.sub_county_id = sc.id
            JOIN counties c ON p.county_id = c.id
            WHERE p.visibility = 'published'";

    $params = [];

    if (!empty($filters['search'])) {
        $sql .= " AND (p.project_name LIKE ? OR p.description LIKE ?)";
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    // Status filter
    if (!empty($filters['status'])) {
        $sql .= " AND p.status = ?";
        $params[] = $filters['status'];
    }

    // Department filter
    if (!empty($filters['department'])) {
        $sql .= " AND p.department_id = ?";
        $params[] = $filters['department'];
    }

    // Ward filter
    if (!empty($filters['ward'])) {
        $sql .= " AND p.ward_id = ?";
        $params[] = $filters['ward'];
    }

    // Sub-county filter
    if (!empty($filters['sub_county'])) {
        $sql .= " AND p.sub_county_id = ?";
        $params[] = $filters['sub_county'];
    }

    // Year filter
    if (!empty($filters['year'])) {
        $sql .= " AND p.project_year = ?";
        $params[] = $filters['year'];
    }

    // Budget range filters
    if (!empty($filters['min_budget'])) {
        $sql .= " AND p.total_budget >= ?";
        $params[] = $filters['min_budget'];
    }

    if (!empty($filters['max_budget'])) {
        $sql .= " AND p.total_budget <= ?";
        $params[] = $filters['max_budget'];
    }

    // Date range filters
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $sql .= " AND p.created_at BETWEEN ? AND ?";
        $params[] = $filters['start_date'] . ' 00:00:00';
        $params[] = $filters['end_date'] . ' 23:59:59';
    } elseif (!empty($filters['start_date'])) {
        $sql .= " AND p.created_at >= ?";
        $params[] = $filters['start_date'] . ' 00:00:00';
    } elseif (!empty($filters['end_date'])) {
        $sql .= " AND p.created_at <= ?";
        $params[] = $filters['end_date'] . ' 23:59:59';
    }

    $sql .= " ORDER BY p.created_at DESC";

    if (!empty($filters['limit'])) {
        $sql .= " LIMIT ?";
        $params[] = $filters['limit'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function get_all_projects($filters = [], $paginate = false, $per_page = 10) {
    global $pdo;

    $sql = "SELECT p.*, d.name as department_name, w.name as ward_name, w.name as ward,
                   sc.name as sub_county_name, sc.name as sub_county, c.name as county_name
            FROM projects p
            JOIN departments d ON p.department_id = d.id
            JOIN wards w ON p.ward_id = w.id
            JOIN sub_counties sc ON p.sub_county_id = sc.id
            JOIN counties c ON p.county_id = c.id
            WHERE 1=1";

    $count_sql = "SELECT COUNT(*) as total FROM projects p WHERE 1=1";
    $params = $count_params = [];

    // Add role-based filtering if created_by is specified
    if (!empty($filters['created_by'])) {
        $sql .= " AND p.created_by = ?";
        $count_sql .= " AND p.created_by = ?";
        $params[] = $filters['created_by'];
        $count_params[] = $filters['created_by'];
    }

    // Search filter
    if (!empty($filters['search'])) {
        $search_term = '%' . $filters['search'] . '%';
        $sql .= " AND (p.project_name LIKE ? OR p.description LIKE ?)";
        $count_sql .= " AND (p.project_name LIKE ? OR p.description LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $count_params[] = $search_term;
        $count_params[] = $search_term;
    }

    // Status filter
    if (!empty($filters['status'])) {
        $sql .= " AND p.status = ?";
        $count_sql .= " AND p.status = ?";
        $params[] = $filters['status'];
        $count_params[] = $filters['status'];
    }

    // Department filter
    if (!empty($filters['department'])) {
        $sql .= " AND p.department_id = ?";
        $count_sql .= " AND p.department_id = ?";
        $params[] = $filters['department'];
        $count_params[] = $filters['department'];
    }

    // County filter
    if (!empty($filters['county'])) {
        $sql .= " AND p.county_id = ?";
        $count_sql .= " AND p.county_id = ?";
        $params[] = $filters['county'];
        $count_params[] = $filters['county'];
    }

    // Ward filter
    if (!empty($filters['ward'])) {
        $sql .= " AND p.ward_id = ?";
        $count_sql .= " AND p.ward_id = ?";
        $params[] = $filters['ward'];
        $count_params[] = $filters['ward'];
    }

    // Sub County filter
    if (!empty($filters['sub_county'])) {
        $sql .= " AND p.sub_county_id = ?";
        $count_sql .= " AND p.sub_county_id = ?";
        $params[] = $filters['sub_county'];
        $count_params[] = $filters['sub_county'];
    }

    // Year filter
    if (!empty($filters['year'])) {
        $sql .= " AND p.project_year = ?";
        $count_sql .= " AND p.project_year = ?";
        $params[] = $filters['year'];
        $count_params[] = $filters['year'];
    }

    // Visibility filter
    if (!empty($filters['visibility'])) {
        $sql .= " AND p.visibility = ?";
        $count_sql .= " AND p.visibility = ?";
        $params[] = $filters['visibility'];
        $count_params[] = $filters['visibility'];
    }

    $sql .= " ORDER BY p.created_at DESC";

    if ($paginate) {
        $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total count for pagination
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($count_params);
        $total_projects = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        return [
            'projects' => $projects,
            'total' => $total_projects,
            'per_page' => $per_page,
            'total_pages' => ceil($total_projects / $per_page)
        ];

    } catch (PDOException $e) {
        error_log("Database error in get_all_projects: " . $e->getMessage());
        return [
            'projects' => [],
            'total' => 0,
            'per_page' => $per_page,
            'total_pages' => 0
        ];
    }
}



function get_project_by_id($id) {
    global $pdo;
    $sql = "SELECT p.id,
                   p.project_name,
                   p.description,
                   p.department_id,
                   p.project_year,
                   p.county_id,
                   p.sub_county_id,
                   p.ward_id,
                   p.location_address,
                   p.location_coordinates,
                   p.start_date,
                   p.expected_completion_date,
                   p.actual_completion_date,
                   p.contractor_name,
                   p.contractor_phone,
                   p.contractor_email,
                   p.contractor_contact,
                   p.status,
                   p.visibility,
                   p.step_status,
                   p.progress_percentage,
                   p.total_steps,
                   p.completed_steps,
                   p.created_by,
                   p.created_at,
                   p.updated_at,
                   p.average_rating,
                   p.total_ratings,
                   p.allocated_budget,
                   p.spent_budget,
                   p.budget_status,
                   p.total_budget,
                   p.last_step_milestone,
                   p.last_financial_milestone,
                   d.name as department_name, 
                   w.name as ward_name, 
                   sc.name as sub_county_name, 
                   c.name as county_name
            FROM projects p
            LEFT JOIN departments d ON p.department_id = d.id
            LEFT JOIN wards w ON p.ward_id = w.id
            LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id
            LEFT JOIN counties c ON p.county_id = c.id
            WHERE p.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $result = $stmt->fetch();

    if ($result && class_exists('EncryptionManager')) {
        $result = EncryptionManager::processDataForReading('projects', $result);
    }

    return $result;
}


/**
 * Project Details
 */
function get_project($project_id) {
    global $pdo;

    $sql = "SELECT p.*, d.name as department_name, w.name as ward_name,
                   sc.name as sub_county_name, c.name as county_name
            FROM projects p
            JOIN departments d ON p.department_id = d.id
            JOIN wards w ON p.ward_id = w.id
            JOIN sub_counties sc ON p.sub_county_id = sc.id
            JOIN counties c ON p.county_id = c.id
            WHERE p.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetch();
}

function get_project_years() {
    global $pdo;
    $stmt = $pdo->query("SELECT DISTINCT project_year FROM projects ORDER BY project_year DESC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get Migori sub-counties only
 */
function get_migori_sub_counties() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT sc.* FROM sub_counties sc 
                          JOIN counties c ON sc.county_id = c.id 
                          WHERE c.name = 'Migori' ORDER BY sc.name");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Location Functions
 */
function get_counties() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM counties ORDER BY name");
    return $stmt->fetchAll();
}

function get_sub_counties($county_id = null) {
    global $pdo;
    if ($county_id) {
        $stmt = $pdo->prepare("SELECT * FROM sub_counties WHERE county_id = ? ORDER BY name");
        $stmt->execute([$county_id]);
    } else {
        $stmt = $pdo->query("SELECT sc.*, c.name as county_name FROM sub_counties sc JOIN counties c ON sc.county_id = c.id ORDER BY c.name, sc.name");
    }
    return $stmt->fetchAll();
}

function get_wards($sub_county_id = null) {
    global $pdo;
    if ($sub_county_id) {
        $stmt = $pdo->prepare("SELECT * FROM wards WHERE sub_county_id = ? ORDER BY name");
        $stmt->execute([$sub_county_id]);
    } else {
        $stmt = $pdo->query("SELECT w.*, sc.name as sub_county_name FROM wards w JOIN sub_counties sc ON w.sub_county_id = sc.id ORDER BY sc.name, w.name");
    }
    return $stmt->fetchAll();
}

function get_departments() {
    $cache_key = 'departments_list';
    //$cached = CacheManager::get($cache_key);

    //if ($cached !== null) {
     //   return $cached;
    //}

    global $pdo;
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    $result = $stmt->fetchAll();

    //CacheManager::set($cache_key, $result, 86400);
    return $result;
}

/**
 * Project Steps Functions
 */

/**
 * Log step editing activity for audit trail
 */
function log_step_edit_activity($admin_id, $step_id, $project_id, $old_values, $new_values) {
    global $pdo;

    try {
        $activity_description = "Edited project step: {$new_values['step_name']} (ID: {$step_id})";
        $additional_data = json_encode([
            'step_id' => $step_id,
            'project_id' => $project_id,
            'action' => 'step_edit',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Determine changed fields
        $changed_fields = [];
        foreach ($new_values as $key => $new_value) {
            if (isset($old_values[$key]) && $old_values[$key] != $new_value) {
                $changed_fields[] = $key;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO admin_activity_log 
            (admin_id, activity_type, target_type, target_id, activity_description, additional_data, old_values, new_values, changed_fields, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        return $stmt->execute([
            $admin_id,
            'step_edit',
            'project_steps',
            $step_id,
            $activity_description,
            $additional_data,
            json_encode($old_values),
            json_encode($new_values),
            json_encode($changed_fields),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

    } catch (Exception $e) {
        error_log("Failed to log step edit activity: " . $e->getMessage());
        return false;
    }
}
function create_project_steps($project_id, $department_name) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_steps WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $existing_steps = $stmt->fetchColumn();

        if ($existing_steps > 0) {
            return ['success' => false, 'message' => 'Project already has steps defined'];
        }

        $steps_template = get_default_project_steps($department_name);

        if (empty($steps_template)) {
            return ['success' => false, 'message' => 'No step template found for this department'];
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO project_steps (project_id, step_name, step_description, step_order, status, expected_duration_days) VALUES (?, ?, ?, ?, 'pending', ?)");

        foreach ($steps_template as $index => $step) {
            $stmt->execute([
                $project_id,
                $step['name'],
                $step['description'],
                $index + 1,
                $step['duration'] ?? 30
            ]);
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Project steps created successfully'];

    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Create project steps error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create project steps'];
    }
}

/**
 * Get project steps by project ID
 */
function get_project_steps($project_id) {
    global $pdo;

    $sql = "SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_order ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

function calculate_project_progress($project_id) {
    global $pdo;

    try {
        // Step progress (50% of total)
        $stmt = $pdo->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_order");
        $stmt->execute([$project_id]);
        $steps = $stmt->fetchAll();

        $total_steps = count($steps);
        $step_score = 0;
        foreach ($steps as $step) {
            if ($step['status'] === 'completed') $step_score += 1;
            elseif ($step['status'] === 'in_progress') $step_score += 0.5;
        }
        $step_progress = ($total_steps > 0) ? ($step_score / $total_steps) * 50 : 0;

        // Budget progress (50% of total) - handle missing table gracefully
        $budget_progress = 0;
        try {
            // Get project total budget and transactions
            $stmt = $pdo->prepare("SELECT total_budget FROM projects WHERE id = ?");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            $total_budget = $project['total_budget'] ?? 0;

            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN transaction_type = 'allocation' THEN amount ELSE 0 END) as allocated,
                    SUM(CASE WHEN transaction_type = 'expenditure' THEN amount ELSE 0 END) as spent
                FROM project_transactions 
                WHERE project_id = ?
            ");
            $stmt->execute([$project_id]);
            $transactions = $stmt->fetch();

            $allocated = $transactions['allocated'] ?? 0;
            $spent = $transactions['spent'] ?? 0;

            // Calculate budget utilization as percentage of allocated vs spent
            if ($allocated > 0) {
                $budget_utilization = min($spent / $allocated, 1);
                $budget_progress = $budget_utilization * 50;
            } elseif ($total_budget > 0 && $spent > 0) {
                // Fallback: use spent vs total budget
                $budget_utilization = min($spent / $total_budget, 1);
                $budget_progress = $budget_utilization * 50;
            }
        } catch (PDOException $e) {
            // If project_transactions table doesn't exist, budget progress is 0
            $budget_progress = 0;
        }

        return round($step_progress + $budget_progress, 1);

    } catch (Exception $e) {
        error_log("Calculate project progress error: " . $e->getMessage());
        return 0;
    }
}

function update_step_status($step_id, $status, $completion_date = null) {
    global $pdo;

    try {
        if ($completion_date === null && $status === 'completed') {
            $completion_date = date('Y-m-d');
        }

        $sql = "UPDATE project_steps SET status = ?, completion_date = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$status, $completion_date, $step_id]);

        if ($result) {
            $stmt = $pdo->prepare("SELECT project_id FROM project_steps WHERE id = ?");
            $stmt->execute([$step_id]);
            $project_id = $stmt->fetchColumn();

            $progress = calculate_project_progress($project_id);
            $stmt = $pdo->prepare("UPDATE projects SET progress_percentage = ? WHERE id = ?");
            $stmt->execute([$progress, $project_id]);
        }

        return $result;
    } catch (Exception $e) {
        error_log("Update step status error: " . $e->getMessage());
        return false;
    }
}

function update_project_progress($project_id) {
    if (file_exists(__DIR__ . '/projectProgressCalculator.php')) {
        require_once __DIR__ . '/projectProgressCalculator.php';
        return update_project_progress_and_status($project_id);
    }
    return calculate_project_progress($project_id);
}

/**
 * Get project documents by project ID
 */
function get_project_documents($project_id) {
    global $pdo;

    $sql = "SELECT * FROM project_documents WHERE project_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

/**
 * Get project team members
 */
function get_project_team_members($project_id) {
    global $pdo;

    $sql = "SELECT tm.*, a.name as admin_name, a.email as admin_email 
            FROM team_members tm
            JOIN admins a ON tm.admin_id = a.id
            WHERE tm.project_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}


function get_comment_replies_limited($parent_id, $limit = 3, $user_ip = null, $user_agent = null) {
    global $pdo;

    try {
        if (!$user_ip) {
            $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
        }
        if (!$user_agent) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }

        $sql = "SELECT f.*, 
                       a.name as admin_name,
                       a.email as admin_email,
                       CASE WHEN f.user_ip = ? AND f.user_agent = ? AND f.status = 'pending' THEN 1 ELSE 0 END as is_user_pending,
                       CASE WHEN f.subject = 'Admin Response' OR f.citizen_name = '' OR f.citizen_name IS NULL THEN 1 ELSE 0 END as is_admin_comment
                FROM feedback f
                LEFT JOIN admins a ON f.responded_by = a.id 
                WHERE f.parent_comment_id = ? 
                AND (f.status IN ('approved', 'reviewed', 'responded') OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?))
                ORDER BY f.created_at ASC
                LIMIT ?";

        return pdo_select($pdo, $sql, [$user_ip, $user_agent, $parent_id, $user_ip, $user_agent, $limit], 'feedback', true);

    } catch (Exception $e) {
        error_log("Get comment replies limited error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get project comments using feedback table with proper structure and encryption support
 */
function get_project_comments($project_id, $filter_ip = false, $filter_user_agent = false) {
    global $pdo;

    try {
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Get main comments (parent_comment_id = 0 or NULL) with their reply counts
        $sql = "SELECT f.*, 
                       a.name as admin_name,
                       a.email as admin_email,
                       CASE WHEN f.user_ip = ? AND f.user_agent = ? AND f.status = 'pending' THEN 1 ELSE 0 END as is_user_pending,
                       CASE WHEN f.subject = 'Admin Response' OR f.responded_by IS NOT NULL THEN 1 ELSE 0 END as is_admin_comment,
                       (SELECT COUNT(*) FROM feedback rf WHERE rf.parent_comment_id = f.id AND rf.status IN ('approved', 'reviewed', 'responded')) as total_replies
                FROM feedback f
                LEFT JOIN admins a ON f.responded_by = a.id 
                WHERE f.project_id = ? 
                AND (f.parent_comment_id = 0 OR f.parent_comment_id IS NULL)
                AND (f.status IN ('approved', 'reviewed', 'responded') OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?))";

        $params = [$user_ip, $user_agent, $project_id, $user_ip, $user_agent];

        if ($filter_ip) {
            $sql .= " AND f.user_ip = ?";
            $params[] = $user_ip;
        }

        if ($filter_user_agent) {
            $sql .= " AND f.user_agent = ?";
            $params[] = $user_agent;
        }

        $sql .= " ORDER BY f.created_at DESC";

        $comments = pdo_select($pdo, $sql, $params, 'feedback', true);

        // Get replies for each comment (limited to 3 initially)
        foreach ($comments as &$comment) {
            $comment['replies'] = get_comment_replies_limited($comment['id'], 3, $user_ip, $user_agent);
        }

        return $comments;

    } catch (Exception $e) {
        error_log("Get project comments error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create project comment with security measures
 */
function create_project_comment($project_id, $admin_id, $comment) {
    global $pdo;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // Sanitize the comment
    $comment = htmlspecialchars($comment, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

    $sql = "INSERT INTO project_comments (project_id, admin_id, comment, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $admin_id, $comment, $ip_address, $user_agent]);
}

/**
 * Project Milestone Functions
 */
function get_project_milestones($project_id) {
    global $pdo;

    $sql = "SELECT * FROM project_milestones WHERE project_id = ? ORDER BY milestone_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

/**
 * Get feedback entries related to a project
 */
function get_project_feedback($project_id) {
    global $pdo;

    $sql = "SELECT f.*, a.name as admin_name, a.email as admin_email
            FROM feedback f
            JOIN admins a ON f.admin_id = a.id
            WHERE f.project_id = ?
            ORDER BY f.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

/**
 * Get feedback entries with pagination
 */
function get_all_feedback($filters = [], $paginate = false, $per_page = 10) {
    global $pdo;

    $sql = "SELECT f.*, a.name as admin_name, p.project_name, p.project_name as project
            FROM feedback f
            JOIN admins a ON f.admin_id = a.id
            JOIN projects p ON f.project_id = p.id
            WHERE 1=1";

    $count_sql = "SELECT COUNT(*) as total FROM feedback WHERE 1=1";
    $params = $count_params = [];

    // Project filter
    if (!empty($filters['project_id'])) {
        $sql .= " AND f.project_id = ?";
        $count_sql .= " AND project_id = ?";
        $params[] = $filters['project_id'];
        $count_params[] = $filters['project_id'];
    }

    // Status filter
    if (!empty($filters['status'])) {
        $sql .= " AND f.status = ?";
        $count_sql .= " AND status = ?";
        $params[] = $filters['status'];
        $count_params[] = $filters['status'];
    }

    // Admin filter
    if (!empty($filters['admin_id'])) {
        $sql .= " AND f.admin_id = ?";
        $count_sql .= " AND admin_id = ?";
        $params[] = $filters['admin_id'];
        $count_params[] = $filters['admin_id'];
    }

    $sql .= " ORDER BY f.created_at DESC";

    if ($paginate) {
        $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $per_page;
        $params[':offset'] = $offset;
    }

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => &$val) {
        if (is_int($val)) {
            $stmt->bindParam($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindParam($key, $val, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
    $feedback = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare($count_sql);
    foreach ($count_params as $key => &$val) {
        if (is_int($val)) {
            $count_stmt->bindParam($key + 1, $val, PDO::PARAM_INT);
        } else {
            $count_stmt->bindParam($key + 1, $val, PDO::PARAM_STR);
        }
    }
    $count_stmt->execute($count_params);
    $total_feedback = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    return [
        'feedback' => $feedback,
        'total' => $total_feedback,
        'per_page' => $per_page,
    ];
}

/**
 * Enhanced notification system with better email handling
 */
function notify_project_subscribers($project_id, $type, $message) {
    global $pdo;

    try {
        // Ensure ProjectSubscriptionManager is available
        if (!class_exists('ProjectSubscriptionManager')) {
            require_once __DIR__ . '/projectSubscriptions.php';
        }

        error_log("[NOTIFICATION] Starting notification for project $project_id, type: $type");        // Get project details
        $stmt = $pdo->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();

        if (!$project) {
            error_log("[NOTIFICATION] Project $project_id not found");
            return false;
        }

        // Use the ProjectSubscriptionManager to send notifications
        $subscription_manager = new ProjectSubscriptionManager($pdo);
        $result = $subscription_manager->sendProjectUpdate($project_id, $type, $message);

        error_log("[NOTIFICATION] ProjectSubscriptionManager result: " . ($result ? 'success' : 'failed'));
        return $result;

    } catch (Exception $e) {
        error_log("[NOTIFICATION] Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Comments and Feedback Functions with Encryption
 */
function add_project_comment($project_id, $comment_text, $user_name, $user_email = null, $parent_id = 0) {
    global $pdo;

    try {
        if (empty($project_id) || empty($comment_text) || empty($user_name)) {
            error_log("Comment validation failed - Project ID: $project_id, User: '$user_name', Comment length: " . strlen($comment_text));
            return ['success' => false, 'message' => 'Missing required fields'];
        }

        $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $subject = $parent_id > 0 ? "Reply to comment" : "Project Comment";

        $data = [
            'project_id' => $project_id,
            'citizen_name' => $user_name,
            'citizen_email' => !empty($user_email) ? $user_email : null,
            'subject' => $subject,
            'message' => $comment_text,
            'status' => 'pending',
            'parent_comment_id' => $parent_id > 0 ? $parent_id : null,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $stmt = $pdo->prepare("INSERT INTO feedback (project_id, citizen_name, citizen_email, subject, message, status, parent_comment_id, user_ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            $data['project_id'],
            $data['citizen_name'],
            $data['citizen_email'],
            $data['subject'],
            $data['message'],
            $data['status'],
            $data['parent_comment_id'],
            $data['user_ip'],
            $data['user_agent'],
            $data['created_at']
        ]);

        if ($result) {
            $comment_id = $pdo->lastInsertId();
            error_log("Comment successfully inserted with ID: $comment_id for project: $project_id by user: $user_name");
            log_activity("New comment submitted for project ID: $project_id by $user_name from IP: $user_ip");
            return ['success' => true, 'message' => 'Comment submitted successfully and is pending approval'];
        } else {
            error_log("Failed to insert comment");
            return ['success' => false, 'message' => 'Database error: Failed to save comment'];
        }
    } catch (Exception $e) {
        error_log("Add comment error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to submit comment. Please try again.'];
    }
}


/**
 * Get all roles from database
 */
function get_all_roles() {
    global $pdo;

    try {
        $sql = "SELECT * FROM roles ORDER BY name";
        return pdo_select($pdo, $sql, [], 'roles', true);
    } catch (Exception $e) {
        error_log("Get all roles error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create secure URL slug
 */
function create_secure_slug($text, $max_length = 50) {
    // Remove HTML tags and special characters
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Convert to lowercase
    $text = strtolower($text);

    // Replace spaces and special characters with hyphens
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);

    // Trim and limit length
    $text = trim($text, '-');
    if (strlen($text) > $max_length) {
        $text = substr($text, 0, $max_length);
        $text = rtrim($text, '-');
    }

    return $text ?: 'untitled';
}

/**
 * Validate project access for current user
 */
function validate_project_access($project_id, $admin_id = null) {
    global $pdo;

    if (!$admin_id) {
        $admin_id = $_SESSION['admin_id'] ?? null;
    }

    if (!$admin_id) {
        return false;
    }

    // Super admin can access all projects
    if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
        return true;
    }

    try {
        $project = pdo_select_one($pdo, "SELECT created_by FROM projects WHERE id = ?", [$project_id], 'projects');

        if (!$project) {
            return false;
        }

        return $project['created_by'] == $admin_id;
    } catch (Exception $e) {
        error_log("Validate project access error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get admin statistics for dashboard
 */
function get_admin_dashboard_stats($admin_id = null) {
    global $pdo;

    if (!$admin_id) {
        $admin_id = $_SESSION['admin_id'] ?? null;
    }

    $is_super_admin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';

    try {
        $stats = [];

        // Projects count
        if ($is_super_admin) {
            $stats['total_projects'] = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM projects", [], 'projects')['count'] ?? 0;
            $stats['ongoing_projects'] = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM projects WHERE status = 'ongoing'", [], 'projects')['count'] ?? 0;
        } else {
            $stats['total_projects'] = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM projects WHERE created_by = ?", [$admin_id], 'projects')['count'] ?? 0;
            $stats['ongoing_projects'] = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM projects WHERE status = 'ongoing' AND created_by = ?", [$admin_id], 'projects')['count'] ?? 0;
        }

        // Feedback count
        if ($is_super_admin) {
            $stats['pending_feedback'] = pdo_select_one($pdo, "SELECT COUNT(*) as count FROM feedback WHERE status = 'pending'", [], 'feedback')['count'] ?? 0;
        } else {
            $stats['pending_feedback'] = pdo_select_one($pdo, "
                SELECT COUNT(*) as count FROM feedback f 
                JOIN projects p ON f.project_id = p.id 
                WHERE f.status = 'pending' AND p.created_by = ?
            ", [$admin_id], 'feedback')['count'] ?? 0;
        }

        return $stats;

    } catch (Exception $e) {
        error_log("Get admin dashboard stats error: " . $e->getMessage());
        return [
            'total_projects' => 0,
            'ongoing_projects' => 0,
            'pending_feedback' => 0
        ];
    }
}

function get_comment_replies_count($parent_id) {
    global $pdo;

    try {
        $sql = "SELECT COUNT(*) FROM feedback 
                WHERE parent_comment_id = ? 
                AND status IN ('approved', 'reviewed', 'responded')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$parent_id]);
        return $stmt->fetchColumn();

    } catch (Exception $e) {
        error_log("Get comment replies count error: " . $e->getMessage());
        return 0;
    }
}

function get_approved_comments_count($project_id) {
    global $pdo;

    try {
        $sql = "SELECT COUNT(*) FROM feedback 
                WHERE project_id = ? 
                AND status IN ('approved', 'reviewed', 'responded')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id]);
        return $stmt->fetchColumn();

    } catch (Exception $e) {
        error_log("Get approved comments count error: " . $e->getMessage());
        return 0;
    }
}

function get_feedback_for_admin($filters = [], $page = 1, $per_page = 20) {
    global $pdo;
    $offset = ($page - 1) * $per_page;

    try {
        $sql = "SELECT f.*, 
                       p.project_name,
                       a.name as admin_name,
                       a.email as admin_email
                FROM feedback f
                LEFT JOIN projects p ON f.project_id = p.id
                LEFT JOIN admins a ON f.responded_by = a.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND f.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['project_id'])) {
            $sql .= " AND f.project_id = ?";
            $params[] = $filters['project_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (f.message LIKE ? OR f.citizen_name LIKE ? OR p.project_name LIKE ?)";
            $search_param = '%' . $filters['search'] . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }

        $sql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;

        return pdo_select($pdo, $sql, $params, 'feedback');

     } catch (Exception $e) {
        error_log("Get feedback for admin error: " . $e->getMessage());
        return [];
    }
}

function get_feedback_count($filters = []) {
    global $pdo;

    try {
        $sql = "SELECT COUNT(*) FROM feedback f
                LEFT JOIN projects p ON f.project_id = p.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND f.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['project_id'])) {
            $sql .= " AND f.project_id = ?";
            $params[] = $filters['project_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (f.message LIKE ? OR f.citizen_name LIKE ? OR p.project_name LIKE ?)";
            $search_param = '%' . $filters['search'] . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();

    } catch (Exception $e) {
        error_log("Get feedback count error: " . $e->getMessage());
        return 0;
    }
}

function get_paginated_activities($page = 1, $per_page = 25) {
    global $pdo;
    $offset = ($page - 1) * $per_page;

    try {
        $sql = "SELECT al.*, a.name as admin_name, a.email as admin_email 
                FROM admin_activity_log al 
                LEFT JOIN admins a ON al.admin_id = a.id 
                ORDER BY al.created_at DESC 
                LIMIT ? OFFSET ?";

        return pdo_select($pdo, $sql, [$per_page, $offset], 'admin_activity_log', true);
    } catch (Exception $e) {
        // Fallback to activity_logs table
        try {
            $sql = "SELECT al.*, a.name as admin_name, a.email as admin_email 
                    FROM activity_logs al 
                    LEFT JOIN admins a ON al.admin_id = a.id 
                    ORDER BY al.created_at DESC 
                    LIMIT ? OFFSET ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$per_page, $offset]);
            return $stmt->fetchAll();
        } catch (Exception $e2) {
            error_log("Get paginated activities error: " . $e2->getMessage());
            return [];
        }
    }
}

function get_total_activities_count() {
    global $pdo;

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_activity_log");
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM activity_logs");
            return $stmt->fetchColumn();
        } catch (Exception $e2) {
            error_log("Get total activities count error: " . $e2->getMessage());
            return 0;
        }
    }
}

function get_comments_for_admin($filters = []) {
    global $pdo;

    try {
        $sql = "SELECT f.*, 
                       p.project_name,
                       a.name as admin_name,
                       a.email as admin_email,
                       f.responded_by IS NOT NULL as is_admin_comment,
                       parent.citizen_name as parent_user_name
                FROM feedback f
                LEFT JOIN projects p ON f.project_id = p.id
                LEFT JOIN admins a ON f.responded_by = a.id
                LEFT JOIN feedback parent ON f.parent_comment_id = parent.id
                WHERE (f.subject LIKE '%comment%' OR f.subject LIKE '%Comment%')";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND f.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['project_id'])) {
            $sql .= " AND f.project_id = ?";
            $params[] = $filters['project_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (f.message LIKE ? OR f.citizen_name LIKE ? OR p.project_name LIKE ?)";
            $search_param = '%' . $filters['search'] . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }

        $sql .= " ORDER BY f.created_at DESC";

        return pdo_select($pdo, $sql, $params, 'feedback');

    } catch (Exception $e) {
        error_log("Get comments for admin error: " . $e->getMessage());
        return [];
    }
}

function moderate_comment($comment_id, $status, $admin_id = null) {
    global $pdo;

    try {
        if (!in_array($status, ['approved', 'rejected'])) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        $result = pdo_update($pdo, 'feedback', [
            'status' => $status,
            'responded_by' => $admin_id,
            'responded_at' => date('Y-m-d H:i:s')
        ], ['id' => $comment_id]);

        return ['success' => true, 'message' => 'Comment ' . $status . ' successfully'];

    } catch (Exception $e) {
        error_log("Moderate comment error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to moderate comment'];
    }
}

/**
 * Prepared Responses Functions
 */
function add_prepared_response($response_text) {
    global $pdo;

    try {
        $sql = "INSERT INTO feedback_templates (response_text, created_at) VALUES (?, NOW())";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$response_text]);

        if ($result) {
            return ['success' => true, 'message' => 'Prepared response added successfully'];
        } else {
            error_log("Failed to insert prepared response - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to save prepared response'];
        }
    } catch (Exception $e) {
        error_log("Add prepared response error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add prepared response. Please try again.'];
    }
}

function delete_prepared_response($response_id) {
    global $pdo;

    try {
        $sql = "DELETE FROM feedback_templates WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$response_id]);

        if ($result) {
            return ['success' => true, 'message' => 'Prepared response deleted successfully'];
        } else {
            error_log("Failed to delete prepared response - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to delete prepared response'];
        }
    } catch (Exception $e) {
        error_log("Delete prepared response error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete prepared response. Please try again.'];
    }
}

function update_prepared_response($response_id, $response_text) {
    global $pdo;

    try {
        $sql = "UPDATE feedback_templates SET response_text = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$response_text, $response_id]);

        if ($result) {
            return ['success' => true, 'message' => 'Prepared response updated successfully'];
        } else {
            error_log("Failed to update prepared response - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to update prepared response'];
        }
    } catch (Exception $e) {
        error_log("Update prepared response error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update prepared response. Please try again.'];
    }
}

/**
 * Secure file upload function
 */
function secure_file_upload($file, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], $max_size = 20971520) {
    // Initialize result array
    $result = [
        'success' => false,
        'message' => '',
        'filename' => '',
        'original_name' => $file['name'] ?? '',
        'file_size' => $file['size'] ?? 0,
        'mime_type' => '',
        'file_path' => ''
    ];

    // Check if file was uploaded
    if (!isset($file) || !isset($file['error'])) {
        $result['message'] = 'No file was uploaded';
        return $result;
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $result['message'] = $error_messages[$file['error']] ?? 'Unknown upload error';
        return $result;
    }

    // Validate file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_types)) {
        $result['message'] = 'Invalid file type. Allowed types: ' . implode(', ', $allowed_types);
        return $result;
    }

    // Validate file size
    if ($file['size'] > $max_size) {
        $result['message'] = 'File too large. Maximum size: ' . format_bytes($max_size);
        return $result;
    }

    // Get MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Create upload directory if it doesn't exist
    $upload_path = __DIR__ . '/../uploads/';
    if (!is_dir($upload_path)) {
        if (!mkdir($upload_path, 0755, true)) {
            $result['message'] = 'Failed to create upload directory';
            return $result;
        }
    }

    // Generate secure filename
    $filename = uniqid('doc_', true) . '.' . $extension;
    $file_path = $upload_path . $filename;

    // Move the file
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Set secure permissions
        chmod($file_path, 0644);

        // Return success with file information
        return [
            'success' => true,
            'message' => 'File uploaded successfully',
            'filename' => $filename,
            'original_name' => basename($file['name']),
            'file_size' => $file['size'],
            'mime_type' => $mime_type,
            'file_path' => $file_path
        ];
    }

    $result['message'] = 'Failed to save uploaded file';
    return $result;
}

// Helper function to format bytes
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function handle_file_upload($file, $allowed_types = ['csv', 'xlsx']) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File too large'];
    }
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = UPLOAD_PATH . $filename;
    if (!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    }
    return ['success' => false, 'message' => 'Failed to save file'];
}

/**
 * Check if admin has grievances to manage
 */
function has_admin_grievances($admin_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM feedback f
            JOIN projects p ON f.project_id = p.id
            WHERE f.status = 'grievance' AND p.created_by = ?
        ");
        $stmt->execute([$admin_id]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Check admin grievances error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email notification with template
 */
function send_email_notification($to_email, $subject, $message, $admin_id = null) {
    global $pdo;

    try {
        // Ensure email is decrypted if it was passed encrypted
        if (DataEncryption::isEncrypted($to_email)) {
            $to_email = DataEncryption::decrypt($to_email);
        }

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address after decryption: $to_email");
            return false;
        }

        // Get admin details for sender info
        $admin_name = 'Migori County PMC';
        $admin_email = SITE_EMAIL ?? 'noreply@migoricounty.go.ke';

        if ($admin_id) {
            try {
                $admin_data = pdo_select_one($pdo, 
                    "SELECT name, email FROM admins WHERE id = ?", 
                    [$admin_id], 
                    'admins'
                );
                if ($admin_data) {
                    $admin_name = $admin_data['name'];
                    // Ensure admin email is also decrypted if needed
                    if (!empty($admin_data['email']) && filter_var($admin_data['email'], FILTER_VALIDATE_EMAIL)) {
                        $admin_email = $admin_data['email'];
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching admin data for email: " . $e->getMessage());
            }
        }

        // Create email template
        $email_template = get_email_template($subject, $message, $admin_name);

        $headers = [
            'From: ' . $admin_email,
            'Reply-To: ' . $admin_email,
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'X-Mailer: PHP/' . phpversion()
        ];

        $result = mail($to_email, $subject, $email_template, implode("\r\n", $headers));

        if (!$result) {
            error_log("Failed to send email to: $to_email");
        } else {
            // Log successful email (use original encrypted email for logging if applicable)
            log_activity('email_sent', "Email sent with subject: $subject", $admin_id);
        }

        return $result;

    } catch (Exception $e) {
        error_log("Email notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get email template with Migori County branding
 */
function get_email_template($subject, $message, $sender_name = 'Migori County PMC') {
    $logo_url = BASE_URL . 'migoriLogo.png';
    $site_url = BASE_URL;

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>$subject</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 20px; text-align: center; }
            .header img { max-width: 80px; height: auto; margin-bottom: 10px; }
            .header h1 { margin: 0; font-size: 24px; }
            .header p { margin: 5px 0 0 0; font-size: 14px; opacity: 0.9; }
            .content { padding: 30px; }
            .message-box { background: #f8f9fa; border-left: 4px solid #1e40af; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #e9ecef; }
            .footer p { margin: 0; font-size: 12px; color: #666; }
            .btn { display: inline-block; background: #1e40af; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 15px 0; }
            .btn:hover { background: #1e3a8a; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <img src='$logo_url' alt='Migori County Logo'>
                <h1>Migori County</h1>
                <p>Project Management Committee</p>
            </div>
            <div class='content'>
                <h2>$subject</h2>
                <div class='message-box'>
                    $message
                </div>
                <p>Best regards,<br>
                <strong>$sender_name</strong><br>
                Migori County Government</p>

                <a href='$site_url' class='btn'>Visit Our Portal</a>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " Migori County Government. All rights reserved.</p>
                <p>This is an automated message from the Project Management System.</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Check if current admin has specific permission
 * This function wraps the RBAC system for backward compatibility
 */
function verify_admin_permission($permission) {
    if (!isset($_SESSION['admin_id'])) {
        return false;
    }

    // Super admin has all permissions
    if ($_SESSION['admin_role'] === 'super_admin') {
        return true;
    }

    return SecureRBAC::hasPermission($_SESSION['admin_id'], $permission);
}

/**
 * Function to log user activities
 */
function log_activity($activity_type, $activity_description, $admin_id = null, $target_type = null, $target_id = null, $additional_data = null) {
    global $pdo;

    // Use current admin if admin_id is null
    $admin_id = $admin_id ?? ($_SESSION['admin_id'] ?? null);

    // Context data to JSON
    $context_json = json_encode($additional_data);

    try {
        $sql = "
            INSERT INTO admin_activity_log 
            (admin_id, activity_type, activity_description, target_type, target_id, ip_address, user_agent, additional_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            $admin_id,
            $activity_type,
            $activity_description,
            $target_type,
            $target_id,
            $ip_address,
            $user_agent,
            $context_json
        ]);
    } catch (PDOException $e) {
        error_log("Logging activity failed: " . $e->getMessage());
    }
}

/**
 * Fetch Activity Logs
 */
function get_activity_logs($filters = [], $paginate = false, $per_page = 10) {
    global $pdo;

    $sql = "SELECT al.*, a.name as admin_name FROM activity_logs al LEFT JOIN admins a ON al.admin_id = a.id WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM activity_logs WHERE 1=1";
    $params = $count_params = [];

    if (!empty($filters['activity_type'])) {
        $sql .= " AND al.activity_type = ?";
        $count_sql .= " AND activity_type = ?";
        $params[] = $filters['activity_type'];
        $count_params[] = $filters['activity_type'];
    }

    if (!empty($filters['category'])) {
        $sql .= " AND al.category = ?";
        $count_sql .= " AND category = ?";
        $params[] = $filters['category'];
        $count_params[] = $filters['category'];
    }

    if (!empty($filters['admin_id'])) {
        $sql .= " AND al.admin_id = ?";
        $count_sql .= " AND admin_id = ?";
        $params[] = $filters['admin_id'];
        $count_params[] = $filters['admin_id'];
    }

    // Date range filter
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $sql .= " AND al.created_at BETWEEN ? AND ?";
        $count_sql .= " AND created_at BETWEEN ? AND ?";
        $params[] = $filters['start_date'];
        $params[] = $filters['end_date'];
        $count_params[] = $filters['start_date'];
        $count_params[] = $filters['end_date'];
    } elseif (!empty($filters['start_date'])) {
        $sql .= " AND al.created_at >= ?";
        $count_sql .= " AND created_at >= ?";
        $params[] = $filters['start_date'];
        $count_params[] = $filters['start_date'];
    } elseif (!empty($filters['end_date'])) {
        $sql .= " AND al.created_at <= ?";
        $count_sql .= " AND created_at <= ?";
        $params[] = $filters['end_date'];
        $count_params[] = $filters['end_date'];
    }

    $sql .= " ORDER BY al.created_at DESC";

    if ($paginate) {
        $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $per_page;
        $params[':offset'] = $offset;
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => &$val) {
        if (is_int($val)) {
            $stmt->bindParam($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindParam($key, $val, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $logs = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare($count_sql);
    foreach ($count_params as $key => &$val) {
        if (is_int($val)) {
            $count_stmt->bindParam($key + 1, $val, PDO::PARAM_INT);
        } else {
            $count_stmt->bindParam($key + 1, $val, PDO::PARAM_STR);
        }
    }
    $count_stmt->execute($count_params);
    $total_logs = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    return [
        'logs' => $logs,
        'total' => $total_logs,
        'per_page' => $per_page,
    ];
}

/**
 * Return all available system permissions grouped by category
 */
function get_available_permissions() {
    return [
        'project' => [
            'view_projects' => ['description' => 'View all projects'],
            'create_projects' => ['description' => 'Create new projects'],
            'edit_projects' => ['description' => 'Edit existing projects'],
            'delete_projects' => ['description' => 'Delete projects'],
        ],
        'feedback' => [
            'manage_feedback' => ['description' => 'Manage feedback messages'],
        ],
        'reports' => [
            'view_reports' => ['description' => 'View system reports'],
        ],
        'admin' => [
            'manage_admins' => ['description' => 'Manage admins and permissions'],
            'view_logs' => ['description' => 'View audit and activity logs'],
        ],
        'general' => [
            'dashboard_access' => ['description' => 'Access system dashboard'],
        ]
    ];
}


/**
 * Get recent activities for dashboard
 */
function get_recent_activities($limit = 10) {
    global $pdo;

    try {
        $sql = "SELECT al.*, a.name as admin_name 
                FROM activity_logs al 
                LEFT JOIN admins a ON al.admin_id = a.id 
                ORDER BY al.created_at DESC 
                LIMIT ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$limit]);
        $activities = $stmt->fetchAll();

        // Format the activities for display
        $formatted_activities = [];
        foreach ($activities as $activity) {
            $formatted_activities[] = [
                'admin_name' => $activity['admin_name'] ?? 'System',
                'activity_description' => $activity['description'] ?? $activity['activity_type'],
                'created_at' => $activity['created_at'],
                'category' => $activity['category'] ?? 'general'
            ];
        }

        return $formatted_activities;
    } catch (Exception $e) {
        error_log("Get recent activities error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get system configuration settings
 */
function get_system_config() {
    global $pdo;

    $sql = "SELECT * FROM system_config";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get system config error: " . $e->getMessage());
        return [];
    }
}

/**
 * Update system configuration setting
 */
function update_system_config($setting_name, $setting_value) {
    global $pdo;

    try {
        $sql = "UPDATE system_config SET setting_value = ? WHERE setting_name = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$setting_value, $setting_name]);

        if ($result) {
            return ['success' => true, 'message' => 'System setting updated successfully'];
        } else {
            error_log("Failed to update system setting - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to update system setting'];
        }
    } catch (Exception $e) {
        error_log("Update system config error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update system setting. Please try again.'];
    }
}

/**
 * Add system configuration setting
 */
function add_system_config($setting_name, $setting_value) {
    global $pdo;

    try {
        $sql = "INSERT INTO system_config (setting_name, setting_value) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$setting_name, $setting_value]);

        if ($result) {
            return ['success' => true, 'message' => 'System setting added successfully'];
        } else {
            error_log("Failed to add system setting - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to add system setting'];
        }
    } catch (Exception $e) {
        error_log("Add system config error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add system setting. Please try again.'];
    }
}

/**
 * Delete system configuration setting
 */
function delete_system_config($setting_name) {
    global $pdo;

    try {
        $sql = "DELETE FROM system_config WHERE setting_name = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$setting_name]);

        if ($result) {
            return ['success' => true, 'message' => 'System setting deleted successfully'];
        } else {
            error_log("Failed to delete system setting - SQL error: " . implode(', ', $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Database error: Failed to delete system setting'];
        }
    } catch (Exception $e) {
        error_log("Delete system config error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete system setting. Please try again.'];
    }
}

/**
 * JSON Response Helper Function
 */
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Enhanced sanitize_input function with multiple data types
 */
function sanitize_input($data, $type = 'string') {
    if (is_array($data)) {
        return array_map(function($item) use ($type) {
            return sanitize_input($item, $type);
        }, $data);
    }

    if ($data === null) {
        return null;
    }

    // Convert to string if not already
    $data = (string) $data;
    $data = trim($data);

    // Remove null bytes and control characters
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);

    switch ($type) {
        case 'email':
            $data = filter_var($data, FILTER_SANITIZE_EMAIL);
            $data = filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : '';
            break;
        case 'int':
            $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
            $data = filter_var($data, FILTER_VALIDATE_INT) !== false ? (int)$data : 0;
            break;
        case 'float':
            $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $data = filter_var($data, FILTER_VALIDATE_FLOAT) !== false ? (float)$data : 0.0;
            break;
        case 'url':
            $data = filter_var($data, FILTER_SANITIZE_URL);
            $data = filter_var($data, FILTER_VALIDATE_URL) ? $data : '';
            break;
        case 'alpha':
            $data = preg_replace('/[^a-zA-Z]/', '', $data);
            break;
        case 'alphanumeric':
            $data = preg_replace('/[^a-zA-Z0-9]/', '', $data);
            break;
        case 'filename':
            $data = preg_replace('/[^a-zA-Z0-9._-]/', '', $data);
            $data = ltrim($data, '.');
            break;
        case 'sql':
            // For SQL LIKE queries
            $data = str_replace(['%', '_'], ['\%', '\_'], $data);
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
            break;
        default:
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }

    return $data;
}

/**
 * Function to validate user input and sanitize data
 */
function validate_and_sanitize_input($input, $data_type = 'string', $min_length = 0, $max_length = 255) {
    // Check if input is empty
    if (empty($input)) {
        return ''; // Or return null if appropriate
    }

    // Trim whitespace
    $input = trim($input);

    // Data type validation and sanitization
    switch ($data_type) {
        case 'string':
            // Check length constraints
            if (strlen($input) < $min_length || strlen($input) > $max_length) {
                return ''; // Or return null if appropriate
            }
            // Sanitize the string
            $input = filter_var($input, FILTER_SANITIZE_STRING);
            break;

        case 'email':
            // Validate email format
            if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
                return ''; // Or return null if appropriate
            }
            // Sanitize email
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            break;

        case 'int':
            // Validate integer format
            if (!filter_var($input, FILTER_VALIDATE_INT)) {
                return 0; // Or return null if appropriate
            }
            // Sanitize integer
            $input = filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            break;

        case 'float':
            // Validate float format
            if (!filter_var($input, FILTER_VALIDATE_FLOAT)) {
                return 0.0;
            }
            // Sanitize float
            $input = filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            break;
        default:
            // If data type is not supported, return empty string
            return '';
    }

    // Return the validated and sanitized input
    return $input;
}


/**
 * Function to get system user by ID
 */
function get_system_user_by_id($user_id) {
    global $pdo;

    try {
        $sql = "SELECT * FROM admins WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get system user by ID error: " . $e->getMessage());
        return null;
    }
}

/**
 * Function to get all system users
 */
function get_all_system_users() {
    global $pdo;

    try {
        $sql = "SELECT * FROM admins ORDER BY name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get all system users error: " . $e->getMessage());
        return [];
    }
}

/**
 * Function to get permission by ID
 */
function get_permission_by_id($permission_id) {
    global $pdo;

    try {
        $sql = "SELECT * FROM permissions WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$permission_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get permission by ID error: " . $e->getMessage());
        return null;
    }
}

/**
 * Function to get all permissions
 */
function get_all_permissions() {
    global $pdo;

    try {
        $sql = "SELECT * FROM permissions ORDER BY name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get all permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Function to get role by ID
 */
function get_role_by_id($role_id) {
    global $pdo;

    try {
        $sql = "SELECT * FROM roles WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$role_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get role by ID error: " . $e->getMessage());
        return null;
    }
}

function get_prepared_responses() {
    global $pdo;

    try {
        // Check if prepared_responses table exists first
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'prepared_responses'");
        $stmt->execute();
        $table_exists = $stmt->fetchColumn();

        if ($table_exists) {
            // Try prepared_responses table first
            $sql = "SELECT id, name, content, category FROM prepared_responses WHERE is_active = 1 ORDER BY name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($results)) {
                // Process for reading with encryption if enabled
                if (class_exists('EncryptionManager')) {
                    foreach ($results as &$result) {
                        $result = EncryptionManager::processDataForReading('prepared_responses', $result);
                    }
                }
                return $results;
            }
        }
    } catch (Exception $e) {
        error_log("Error checking prepared_responses table: " . $e->getMessage());
    }

    // Return empty array if no table exists or no data found
    return [];
}

/**
 * Get login attempts with pagination and filtering
 */
function get_login_attempts($filters = [], $decrypt = true, $per_page = 20) {
    global $pdo;

    try {
        $page = max(1, intval($filters['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        // Base query with JOIN to get admin names
        $sql = "SELECT la.*, a.name as admin_name 
                FROM login_attempts la 
                LEFT JOIN admins a ON la.user_id = a.id 
                WHERE 1=1";

        $count_sql = "SELECT COUNT(*) as total FROM login_attempts la WHERE 1=1";
        $params = [];
        $count_params = [];

        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND la.status = ?";
            $count_sql .= " AND status = ?";
            $params[] = $filters['status'];
            $count_params[] = $filters['status'];
        }

        if (!empty($filters['email'])) {
            $sql .= " AND la.email LIKE ?";
            $count_sql .= " AND email LIKE ?";
            $search_email = '%' . $filters['email'] . '%';
            $params[] = $search_email;
            $count_params[] = $search_email;
        }

        if (!empty($filters['ip_address'])) {
            $sql .= " AND la.ip_address LIKE ?";
            $count_sql .= " AND ip_address LIKE ?";
            $search_ip = '%' . $filters['ip_address'] . '%';
            $params[] = $search_ip;
            $count_params[] = $search_ip;
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND la.timestamp >= ?";
            $count_sql .= " AND timestamp >= ?";
            $params[] = $filters['start_date'] . ' 00:00:00';
            $count_params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND la.timestamp <= ?";
            $count_sql .= " AND timestamp <= ?";
            $params[] = $filters['end_date'] . ' 23:59:59';
            $count_params[] = $filters['end_date'] . ' 23:59:59';
        }

        // Get total count
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($count_params);
        $total = $count_stmt->fetchColumn();

        // Add ordering and pagination
        $sql .= " ORDER BY la.timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;

        // Execute main query
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decrypt data if needed and encryption is enabled
        if ($decrypt && class_exists('EncryptionManager')) {
            foreach ($attempts as &$attempt) {
                $attempt = EncryptionManager::processDataForReading('login_attempts', $attempt);
                // Also decrypt admin name if available
                if (isset($attempt['admin_name']) && !empty($attempt['admin_name'])) {
                    $attempt['admin_name'] = EncryptionManager::decryptIfNeeded($attempt['admin_name']);
                }
            }
        }

        return [
            'attempts' => $attempts,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ];

    } catch (Exception $e) {
        error_log("Get login attempts error: " . $e->getMessage());
        return [
            'attempts' => [],
            'total' => 0,
            'current_page' => 1,
            'per_page' => $per_page,
            'total_pages' => 0
        ];
    }
}

/**
 * Get login attempt statistics
 */
function get_login_attempt_stats($days = 30) {
    global $pdo;

    try {
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = "SELECT 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_logins,
                    SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as failed_attempts,
                    COUNT(DISTINCT email) as unique_users
                FROM login_attempts 
                WHERE timestamp >= ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate success rate
        $success_rate = 0;
        if ($stats['total_attempts'] > 0) {
            $success_rate = round(($stats['successful_logins'] / $stats['total_attempts']) * 100, 1);
        }

        return [
            'total_attempts' => $stats['total_attempts'] ?? 0,
            'successful_logins' => $stats['successful_logins'] ?? 0,
            'failed_attempts' => $stats['failed_attempts'] ?? 0,
            'unique_users' => $stats['unique_users'] ?? 0,
            'success_rate' => $success_rate
        ];

    } catch (Exception $e) {
        error_log("Get login attempt stats error: " . $e->getMessage());
        return [
            'total_attempts' => 0,
            'successful_logins' => 0,
            'failed_attempts' => 0,
            'unique_users' => 0,
            'success_rate' => 0
        ];
    }
}

/**
 * Check if user can manage specific project
 */
function can_manage_project($project_id) {
    global $pdo;

    if (!isset($_SESSION['admin_id'])) {
        return false;
    }

    $current_admin = get_current_admin();

    // Super admin can manage all projects
    if ($current_admin['role'] === 'super_admin') {
        return true;
    }

    // Check if admin has manage_projects permission
    if (!hasPagePermission('manage_projects')) {
        return false;
    }

    // Check if this admin created the project
    try {
        $stmt = $pdo->prepare("SELECT created_by FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();

        if (!$project) {
            return false;
        }

        return $project['created_by'] == $current_admin['id'];
    } catch (Exception $e) {
        error_log("Error checking project ownership: " . $e->getMessage());
        return false;
    }
}
