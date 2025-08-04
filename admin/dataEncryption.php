<?php
require_once '../includes/auth.php';
require_once '../includes/EncryptionManager.php';

// Only super admins can access this
if (!hasPagePermission('manage_encryption')) {
    header('Location: index.php');
    exit;
}

// Initialize EncryptionManager
EncryptionManager::init($pdo);

$message = '';
$messageType = '';

// Handle encryption/decryption operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        try {
            $adminId = $_SESSION['admin_id'];

            switch ($_POST['action']) {
                case 'encrypt_all':
                    $result = EncryptionManager::encryptAllSensitiveData($adminId);
                    $message = $result['message'];
                    $messageType = 'success';
                    break;

                case 'decrypt_all':
                    $result = EncryptionManager::decryptAllSensitiveData($adminId);
                    $message = $result['message'];
                    $messageType = 'success';
                    break;

                default:
                    $message = 'Invalid action';
                    $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current encryption status
$encryptionStatus = EncryptionManager::getEncryptionStatus();

include 'includes/adminHeader.php';
?>
<!-- Breadcrumb -->
<div class="mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li class="text-gray-600 font-medium">
                <i class="fas fa-home mr-1"></i> Dashboard
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">
                <a href="systemSettings.php" class="text-gray-600 hover:text-gray-800">system settings</a>
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">Encryption Manager</li>
        </ol>
    </nav>
</div>

<div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200"> 
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Data Encryption Management</h1>
                    <p class="text-gray-600">Manage system-wide data encryption settings</p>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold <?php echo $encryptionStatus['encryption_enabled'] ? 'text-green-600' : 'text-red-600'; ?> mb-1">
                        <i class="fas fa-<?php echo $encryptionStatus['encryption_enabled'] ? 'lock' : 'unlock'; ?>"></i>
                    </div>
                    <div class="text-sm text-gray-600">
                        <?php echo $encryptionStatus['encryption_enabled'] ? 'Encrypted' : 'Decrypted'; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 rounded-md p-4 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle text-green-400' : 'exclamation-circle text-red-400'; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm <?php echo $messageType === 'success' ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Current Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-database text-blue-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Encryption Mode</p>
                            <p class="text-sm text-gray-500">
                                <?php echo $encryptionStatus['encryption_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-shield-alt text-green-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Sensitive Fields</p>
                            <p class="text-sm text-gray-500">
                                <?php echo $encryptionStatus['total_sensitive_fields']; ?> fields protected
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-clock text-yellow-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Last Updated</p>
                            <p class="text-sm text-gray-500">
                                <?php echo $encryptionStatus['last_updated'] ? date('M j, Y g:i A', strtotime($encryptionStatus['last_updated'])) : 'Never'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Encryption Controls</h2>

            <div class="space-y-4">
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-md font-medium text-gray-900">Encrypt All Sensitive Data</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Encrypt all sensitive data in the database. This will make the data unreadable without the encryption key.
                            </p>
                        </div>
                        <div class="ml-4">
                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to encrypt all sensitive data? This operation cannot be undone without decryption.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="encrypt_all">
                                <button type="submit" 
                                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        <?php echo $encryptionStatus['encryption_enabled'] ? 'disabled' : ''; ?>>
                                    <i class="fas fa-lock mr-2"></i>
                                    Encrypt All Data
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-md font-medium text-gray-900">Decrypt All Sensitive Data</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Decrypt all sensitive data in the database. This will make the data readable in plain text.
                            </p>
                        </div>
                        <div class="ml-4">
                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to decrypt all sensitive data? This will make sensitive information readable in plain text.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="decrypt_all">
                                <button type="submit" 
                                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        <?php echo !$encryptionStatus['encryption_enabled'] ? 'disabled' : ''; ?>
                                >
                                    <i class="fas fa-unlock mr-2"></i>
                                    Decrypt All Data
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">Security Notice</h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Encryption and decryption operations affect all sensitive data in the database</li>
                            <li>These operations cannot be reversed without the opposite action</li>
                            <li>Only perform these operations during maintenance windows</li>
                            <li>Ensure you have recent database backups before proceeding</li>
                            <li>All operations are logged for security audit purposes</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/adminFooter.php'; ?>