<?php
// Set proper HTTP response code
http_response_code(500);

// Check if we have error details from the server
$error_message = isset($_SERVER['REDIRECT_STATUS']) ? 'Server Error' : 'Internal Server Error';
$error_details = '';

// In development environment, show more details
$is_dev = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');
if ($is_dev) {
    $error_details = isset($_SERVER['REDIRECT_ERROR_NOTES']) ? $_SERVER['REDIRECT_ERROR_NOTES'] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error - Migori County</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .error-highlight {
            background: linear-gradient(120deg, #ef444430 0%, #dc262630 100%);
            background-repeat: no-repeat;
            background-size: 100% 30%;
            background-position: 0 88%;
        }
        .error-details {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Minimal Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-3 flex items-center">
            <?php 
            // Define BASE_URL if not already defined
            if (!defined('BASE_URL')) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $script = $_SERVER['SCRIPT_NAME'];
                $path = dirname($script);
                if ($path === '/') $path = '';
                define('BASE_URL', $protocol . $host . $path . '/');
            }
            ?>
            <a href="<?php echo BASE_URL; ?>index.php" class="flex items-center">
                <img src="<?php echo BASE_URL; ?>migoriLogo.png" alt="Logo" class="h-8 w-8 mr-2">
                <span class="font-semibold text-gray-800">Migori County</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow flex items-center">
        <div class="container mx-auto px-4 py-8 max-w-md">
            <div class="text-center mb-6">
                <span class="inline-block text-6xl font-bold text-red-600">500</span>
                <h1 class="mt-2 text-2xl font-bold text-gray-800 error-highlight">
                    Server Error
                </h1>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
                <div class="mb-4">
                    <i class="fas fa-server text-red-500 text-4xl mb-3"></i>
                    <p class="text-gray-600">
                        We're experiencing technical difficulties. Our team has been notified.
                    </p>
                </div>

                <?php if ($error_details): ?>
                <details class="error-details p-3 rounded mb-4 text-left">
                    <summary class="font-medium text-red-600 cursor-pointer">
                        Technical Details
                    </summary>
                    <pre class="mt-2 text-xs text-gray-600 overflow-auto"><?php echo htmlspecialchars($error_details); ?></pre>
                </details>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="<?php echo BASE_URL; ?>index.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
                        <i class="fas fa-home mr-1"></i> Return Home
                    </a>
                    <button onclick="window.location.reload()" class="px-4 py-2 border border-gray-300 rounded hover:bg-gray-50 transition">
                        <i class="fas fa-sync-alt mr-1"></i> Try Again
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Simple Footer -->
    <footer class="bg-white py-4 border-t">
        <div class="container mx-auto px-4 text-center text-sm text-gray-500">
            &copy; <?php echo date('Y'); ?> Migori County Government
        </div>
    </footer>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Error Reporting Script -->
    <script>
        // Send error details to analytics if available
        window.addEventListener('load', function() {
            const errorDetails = <?php echo json_encode($error_message); ?>;
            if (typeof gtag === 'function') {
                gtag('event', 'exception', {
                    description: errorDetails,
                    fatal: true
                });
            }
        });
    </script>
</body>
</html>