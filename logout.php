<?php
// Strict error reporting for development
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Disable in production

// Set security headers before any output
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// File includes with absolute paths for security
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Initialize secure session with strict parameters
try {
    init_secure_session();
} catch (Exception $e) {
    error_log("Session initialization failed: " . $e->getMessage());
    header("Location: /500.php");
    exit();
}

// Enhanced logout processing
try {
    // Log the logout activity if user is logged in
    if (is_logged_in()) {
        $current_admin = get_current_admin();
        if ($current_admin) {
            $log_data = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'session_id' => session_id()
            ];
            
            security_log('admin_logout', $current_admin['id'], $log_data);
            
            // Additional security measure: log session destruction
            if (session_status() === PHP_SESSION_ACTIVE) {
                security_log('session_destroyed', $current_admin['id'], [
                    'session_data' => json_encode($_SESSION)
                ]);
            }
        }
    }

    // Perform logout with multiple security layers
    logout_user();

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    // Completely destroy the session
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

    // CSRF protection - generate new token if continuing to login page
    $new_csrf_token = bin2hex(random_bytes(32));
    if (!isset($_SESSION)) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => true,
            'cookie_samesite' => 'Strict'
        ]);
    }
    $_SESSION['csrf_token'] = $new_csrf_token;
    session_write_close();

    // Safe redirect with anti-header-injection
    $login_url = 'login.php';
    $query_string = 'logged_out=1&csrf=' . urlencode($new_csrf_token);
    $redirect_url = filter_var("$login_url?$query_string", FILTER_SANITIZE_URL);
    
    header("Location: $redirect_url", true, 303);
    exit();

} catch (Exception $e) {
    error_log("Logout process failed: " . $e->getMessage());
    header("Location: /500.php");
    exit();
}
?>