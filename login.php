<?php
// Strict error reporting for development
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Disable in production

// Set security headers before any output
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com");

// File includes with absolute paths for security
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Initialize secure session
try {
    init_secure_session();
} catch (Exception $e) {
    error_log("Session initialization failed: " . $e->getMessage());
    header("Location: /500.php");
    exit();
}

// Redirect if already logged in
if (is_logged_in()) {
    $redirect_url = BASE_URL . 'admin/';
    if (!headers_sent()) {
        header('Location: ' . $redirect_url);
        exit;
    }
    echo '<script>window.location.href="' . htmlspecialchars($redirect_url) . '";</script>';
    exit;
}

// Initialize variables
$error = '';
$timeout = isset($_GET['timeout']);
$logged_out = isset($_GET['logged_out']);
$email = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token if implemented
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
        error_log("CSRF token validation failed");
    } else {
        $email = sanitize_input($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter both Email and password';
        } else {
            try {
                // Rate limiting check
                if (!check_login_attempts($email)) {
                    $error = 'Too many login attempts. Please try again later.';
                    error_log("Login rate limited for: " . $email);
                } else {
                    $result = login_user($email, $password);
                    if ($result['success']) {
                        // Redirect to admin area (session handling is done in login_user)
                        $redirect_url = BASE_URL . 'admin/';
                        header('Location: ' . $redirect_url);
                        exit;
                    } else {
                        $error = $result['message'];
                        sleep(2); // Slow down brute force attempts
                    }
                }
            } catch (Exception $e) {
                error_log("Login system error: " . $e->getMessage());
                $error = 'Login system error. Please try again.';
            }
        }
    }
}

// Generate new CSRF token for the form
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Admin Login - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
    <!-- Preload resources -->
    <link rel="preload" href="https://cdn.tailwindcss.com" as="script">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    
    <!-- CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            light: '#3b82f6',
                            dark: '#2563eb'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900">
    <div class="min-h-full flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div class="text-center">
                <div class="mx-auto h-16 w-16 flex items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/50 mb-4">
                    <i class="fas fa-user-shield text-blue-600 dark:text-blue-400 text-2xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                    Admin Portal
                </h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <?php echo htmlspecialchars(APP_NAME); ?>
                </p>
            </div>

            <!-- Status Messages -->
            <?php if ($logged_out): ?>
                <div class="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 pt-0.5">
                            <i class="fas fa-check-circle text-green-500 dark:text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-green-800 dark:text-green-200">
                                Logout Successful
                            </h3>
                            <p class="text-sm text-green-700 dark:text-green-300 mt-1">
                                You have been securely logged out.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($timeout): ?>
                <div class="rounded-lg bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 pt-0.5">
                            <i class="fas fa-exclamation-triangle text-yellow-500 dark:text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                Session Expired
                            </h3>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                                Your session has timed out. Please login again.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 pt-0.5">
                            <i class="fas fa-exclamation-circle text-red-500 dark:text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                Login Failed
                            </h3>
                            <p class="text-sm text-red-700 dark:text-red-300 mt-1">
                                <?php echo htmlspecialchars($error); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="mt-6 space-y-6" method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Email Address
                        </label>
                        <input id="email" name="email" type="email" required 
                               class="block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-800 dark:text-white px-4 py-2"
                               placeholder="your@email.com"
                               value="<?php echo htmlspecialchars($email); ?>"
                               autocomplete="username">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Password
                        </label>
                        <input id="password" name="password" type="password" required 
                               class="block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-800 dark:text-white px-4 py-2"
                               placeholder="••••••••"
                               autocomplete="current-password">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-sm">
                        <a href="forgotPassword.php" class="font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300 transition-colors">
                            Forgot password?
                        </a>
                    </div>
                </div>

                <div>
                    <button type="submit" 
                            class="w-full flex justify-center items-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                        <i class="fas fa-lock-open mr-2"></i>
                        Sign In
                    </button>
                </div>
            </form>

            <div class="mt-8 text-center text-xs text-gray-500 dark:text-gray-400">
                <p>By logging in, you agree to our security policies and monitoring.</p>
                <p class="text-center">Please contact your system administrator for login credentials.</p>
                <p class="mt-1"><?php echo htmlspecialchars(APP_NAME); ?> © <?php echo date('Y'); ?></p>

            </div>
        </div>
    </div>

    <script>
        // Focus on email field on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });

        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>