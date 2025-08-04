<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/security.php';


 //Check if user is logged in
 
function is_logged_in() {
    init_secure_session();
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require authenticated admin
 */
function require_admin() {
    init_secure_session();

    if (!is_logged_in()) {
        header('Location: ' . determine_redirect_url('login.php'));
        exit;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        logout_user();
        header('Location: ' . determine_redirect_url('login.php?timeout=1'));
        exit;
    }

    if (!isset($_SESSION['last_regenerate']) || (time() - $_SESSION['last_regenerate'] > 300)) {
        session_regenerate_id(true);
        $_SESSION['last_regenerate'] = time();
    }

    $_SESSION['last_activity'] = time();
    validate_session_consistency();
}

/**
 * Validate session consistency
 */
function validate_session_consistency() {
    // More lenient user agent checking for production
    if (isset($_SESSION['user_agent'])) {
        $current_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $session_agent = $_SESSION['user_agent'];

        // Only check if both exist and are significantly different
        if (!empty($current_agent) && !empty($session_agent)) {
            // Allow minor variations in user agent
            $similarity = similar_text($current_agent, $session_agent, $percent);
            if ($percent < 80) { // Only flag if less than 80% similar
                security_log('user_agent_mismatch', $_SESSION['admin_id'] ?? null);
                // Don't force logout, just log for now
                // force_logout('security');
            }
        }
    } else {
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // Handle various IP address scenarios for production
    if (isset($_SESSION['ip_address'])) {
        $current_ip = get_real_ip_address();
        $session_ip = $_SESSION['ip_address'];

        if (!is_local_ip($current_ip) && !is_local_ip($session_ip)) {
            $current_subnet = get_ip_subnet($current_ip);
            $session_subnet = get_ip_subnet($session_ip);

            if ($current_subnet !== $session_subnet) {
                security_log('ip_mismatch', $_SESSION['admin_id'] ?? null, [
                    'current_ip' => $current_ip,
                    'session_ip' => $session_ip
                ]);
                // Don't force logout for IP changes in production
            }
        }
    } else {
        $_SESSION['ip_address'] = get_real_ip_address();
    }
}


/**
 * Login user with credentials
 */
function login_user($email, $password) {
    global $pdo;

    if (!check_login_attempts($email)) {
        record_login_attempt($email, 'fail', null, 'Too many attempts');
        return ['success' => false, 'message' => 'Too many login attempts. Please try again later.'];
    }

    try {
        // Get user data handling encryption properly
        $user = null;
        try {
            // Check if encryption is enabled
            if (EncryptionManager::getEncryptionMode()) {
                // When encryption is enabled, we need to get all users and decrypt to find match
                $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, email_verified FROM admins");
                $stmt->execute();
                $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Decrypt and find matching user
                foreach ($allUsers as $userData) {
                    $decryptedUser = EncryptionManager::processDataForReading('admins', $userData);
                    if (strtolower($decryptedUser['email']) === strtolower($email)) {
                        $user = $decryptedUser;
                        break;
                    }
                }
            } else {
                // When encryption is disabled, direct query works
                $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, email_verified FROM admins WHERE LOWER(email) = LOWER(?)");
                $stmt->execute([$email]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userData) {
                    $user = EncryptionManager::processDataForReading('admins', $userData);
                }
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
        }

        if (!$user) {
            record_login_attempt($email, 'fail', null, 'User not found');
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        record_login_attempt($email, 'fail', null, 'System error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Login system error. Please try again.'];
    }

    if (!$user['email_verified']) {
        record_login_attempt($email, 'fail', $user['id'], 'Email not verified');
        return ['success' => false, 'message' => 'Please verify your email address before logging in. Check your email for the activation link.'];
    }

    if ($user && password_verify($password, $user['password_hash'])) {
        // Use PASSWORD_DEFAULT for better compatibility
        $preferred_algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        if (password_needs_rehash($user['password_hash'], $preferred_algo)) {
            $new_hash = password_hash($password, $preferred_algo);
            $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")
               ->execute([$new_hash, $user['id']]);
        }

        // Record successful login attempt
        record_login_attempt($email, 'success', $user['id'], null);
        clear_login_attempts($email);

        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_name'] = $user['name'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regenerate'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($user['role'] === 'super_admin') {
            $all_permissions = [];
            foreach (SecureRBAC::getPermissionCategories() as $category => $perms) {
                $all_permissions = array_merge($all_permissions, array_keys($perms));
            }
            $_SESSION['permissions'] = $all_permissions;
        } else {
            $_SESSION['permissions'] = SecureRBAC::getAdminPermissions($user['id']);
        }

        error_log("User {$user['id']} logged in with permissions: " . implode(', ', $_SESSION['permissions']));

        // Use pdo_update to respect encryption mode
        pdo_update($pdo, 'admins', [
            'last_login' => date('Y-m-d H:i:s'),
            'last_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ], ['id' => $user['id']]);

        return ['success' => true];
    }

    record_login_attempt($email, 'fail', $user['id'] ?? null, 'Invalid password');
    return ['success' => false, 'message' => 'Invalid credentials'];
}

/**
 * Logout user and clear session
 */
function logout_user() {
    init_secure_session();

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}


/**
 * Check if user has required role
 */
function has_role($required_role) {
    if (!is_logged_in()) {
        return false;
    }

    $role_hierarchy = ['viewer' => 1, 'admin' => 2, 'super_admin' => 3];
    $current_role = $_SESSION['admin_role'];

    return isset($role_hierarchy[$current_role]) && 
           isset($role_hierarchy[$required_role]) && 
           $role_hierarchy[$current_role] >= $role_hierarchy[$required_role];
}

/**
 * Require specific role
 */
function require_role($required_role) {
    require_admin();

    if (!has_role($required_role)) {
        security_log('insufficient_permissions', $_SESSION['admin_id'], [
            'required_role' => $required_role,
            'current_role' => $_SESSION['admin_role']
        ]);
        force_logout('insufficient_permissions');
    }

    verify_role_consistency();
}

/**
 * Security utility functions
 */
function force_logout($reason = 'security') {
    security_log('forced_logout', $_SESSION['admin_id'] ?? null, ['reason' => $reason]);
    logout_user();
    header('Location: ' . determine_redirect_url("login.php?error=$reason"));
    exit;
}

function verify_role_consistency() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin_data = $stmt->fetch();
        $db_role = $admin_data['role'] ?? null;

        if ($db_role !== $_SESSION['admin_role']) {
            security_log('role_tampering', $_SESSION['admin_id'], [
                'session_role' => $_SESSION['admin_role'],
                'db_role' => $db_role
            ]);
            force_logout('security');
        }

        if (!isset($_SESSION['permissions']) || empty($_SESSION['permissions'])) {
            if ($db_role === 'super_admin') {
                $all_permissions = [];
                foreach (SecureRBAC::getPermissionCategories() as $category => $perms) {
                    $all_permissions = array_merge($all_permissions, array_keys($perms));
                }
                $_SESSION['permissions'] = $all_permissions;
            } else {
                $_SESSION['permissions'] = SecureRBAC::getAdminPermissions($_SESSION['admin_id']);
            }
        }
    } catch (PDOException $e) {
        security_log('database_error', null, ['error' => 'Database connection issue']);
        force_logout('system');
    }
}

function check_login_attempts($email) {
    global $pdo;

    try {
        // Use EncryptionManager to check encryption mode
        if (!class_exists('EncryptionManager')) {
            require_once __DIR__ . '/EncryptionManager.php';
        }
        
        EncryptionManager::init($pdo);
        $encryption_enabled = EncryptionManager::getEncryptionMode();

        if ($encryption_enabled) {
            // When encryption is enabled, we need to get all attempts and decrypt to find matches
            $stmt = $pdo->prepare("
                SELECT email, status, timestamp
                FROM login_attempts 
                WHERE status = 'fail'
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $failed_count = 0;
            foreach ($attempts as $attempt) {
                try {
                    $decrypted_email = EncryptionManager::decryptIfNeeded($attempt['email']);
                    if (strtolower($decrypted_email) === strtolower($email)) {
                        $failed_count++;
                    }
                } catch (Exception $e) {
                    // If decryption fails, skip this attempt
                    continue;
                }
            }
            
            // Check if there are too many failed attempts
            if ($failed_count >= 5) {
                return false; // Too many attempts in last hour
            }
        } else {
            // When encryption is disabled, direct query works
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempt_count
                FROM login_attempts 
                WHERE LOWER(email) = LOWER(?)
                AND status = 'fail'
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$email]);
            $result = $stmt->fetch();
            
            // Check if there are too many failed attempts
            if ($result && $result['attempt_count'] >= 5) {
                return false; // Too many attempts in last hour
            }
        }

        return true;

    } catch (Exception $e) {
        error_log("Error checking login attempts: " . $e->getMessage());
        return true; // Allow login if there's an error checking
    }
}


//Logs a login attempt.
function log_login_attempt(string $email, string $status, ?int $user_id = null, ?string $failure_reason = null): void
{
    global $pdo;

    try {
        // Get IP address and user agent
        $ip_address = get_real_ip_address();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // Process data for storage using EncryptionManager
        $data_to_log = EncryptionManager::processDataForStorage('login_attempts', [
            'email' => $email,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ]);

        // Prepare the SQL statement
        $sql = "INSERT INTO login_attempts (email, timestamp, status, user_id, ip_address, user_agent, failure_reason) VALUES (?, NOW(), ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        // Execute with proper parameters
        $result = $stmt->execute([
            $data_to_log['email'],
            $status,
            $user_id,
            $data_to_log['ip_address'],
            $data_to_log['user_agent'],
            $failure_reason
        ]);

        if (!$result) {
            error_log("Failed to insert login attempt record");
        } else {
            error_log("Login attempt recorded successfully for email: " . $email . " with status: " . $status);
        }

    } catch (PDOException $e) {
        error_log("Database error when logging login attempt: " . $e->getMessage());
        error_log("SQL: " . ($sql ?? 'No SQL'));
        error_log("Parameters: " . json_encode([
            'email' => $email,
            'status' => $status,
            'user_id' => $user_id,
            'ip_address' => $ip_address ?? 'unknown',
            'user_agent' => substr($user_agent ?? 'unknown', 0, 50),
            'failure_reason' => $failure_reason
        ]));
    } catch (Exception $e) {
        error_log("General error when logging login attempt: " . $e->getMessage());
    }
}


function record_login_attempt($email, $status = 'fail', $user_id = null, $failure_reason = null) {
    // Call the standardized log_login_attempt function from functions.php
    log_login_attempt($email, $status, $user_id, $failure_reason);

    // Log security event for failed attempts
    if ($status === 'fail') {
        security_log('login_attempt_failed', $user_id, [
            'email' => $email,
            'ip_address' => get_real_ip_address(),
            'failure_reason' => $failure_reason,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 200)
        ]);
    }
}

function clear_login_attempts($email) {
    global $pdo;

    try {
        // No need to clear attempts since each is logged individually
        // Rate limiting is based on counting recent failed attempts
        // This function can be used for cleanup if needed in the future

    } catch (PDOException $e) {
        error_log("Failed to clear login attempts: " . $e->getMessage());
    }
}

function determine_redirect_url($path) {
    $base_path = '';

    // Check if we're in admin directory
    if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false) {
        $base_path = '../';
    }

    // Handle different server configurations
    if (isset($_SERVER['HTTP_HOST'])) {
        return $base_path . $path;
    }

    return $path;
}

function is_local_ip($ip) {
    return $ip === '127.0.0.1' || $ip === '::1';
}

function get_ip_subnet($ip) {
    return substr($ip, 0, strrpos($ip, '.'));
}

function security_log($event_type, $user_id = null, $details = []) {
    global $pdo;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    try {
        // Use EncryptionManager to handle encryption based on current mode
        $data = EncryptionManager::processDataForStorage('security_logs', [
            'event_type' => $event_type,
            'user_id' => $user_id,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'details' => json_encode($details),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $stmt = $pdo->prepare("INSERT INTO security_logs (event_type, user_id, ip_address, user_agent, details, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['event_type'],
            $data['user_id'],
            $data['ip_address'],
            $data['user_agent'],
            $data['details'],
            $data['timestamp']
        ]);
    } catch (Exception $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}

/**
 * Permission checking functions
 */
function has_session_permission($permission_key) {
    if (!is_logged_in()) {
        return false;
    }

    if ($_SESSION['admin_role'] === 'super_admin') {
        return true;
    }

    return isset($_SESSION['permissions']) && in_array($permission_key, $_SESSION['permissions']);
}


/**
 * Get real IP address considering proxies
 */
function get_real_ip_address() {
    // Check for various proxy headers
    $ip_headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($ip_headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated IPs (common with proxies)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}