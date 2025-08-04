<?php
// This file includes page security and displays system settings for super admin only
require_once 'includes/pageSecurity.php';
$current_admin = get_current_admin();

// Force logout if not super admin
if ($current_admin['role'] !== 'super_admin') {
    force_logout_with_incident('unauthorized_system_settings_access');
}

$page_title = "System Settings";

// Get system statistics (simplified to prevent heavy loading)
$system_stats = [];
try {
    // Essential statistics only
    $system_stats['total_admins'] = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    $system_stats['active_admins'] = $pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = 1")->fetchColumn();
    $system_stats['total_projects'] = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $system_stats['active_projects'] = $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'ongoing'")->fetchColumn();
    
    // Recent security events only (24h)
    $system_stats['recent_security_events'] = $pdo->query("SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    
    // Pending feedback only
    $system_stats['pending_feedback'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")->fetchColumn();
    
} catch (Exception $e) {
    error_log("System stats error: " . $e->getMessage());
    $system_stats = [];
}

include 'includes/adminHeader.php';
?>

<!-- Breadcrumb -->
<div class="mb-4">
    <nav class="flex text-sm" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2">
            <li><a href="index.php" class="text-gray-500 hover:text-gray-700">Dashboard</a></li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">System Settings</li>
        </ol>
    </nav>
</div>

<div class="bg-white rounded-lg p-6 mb-6 shadow-sm border border-gray-200">

    <!-- System Overview Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-green-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-users text-green-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Active Admins</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $system_stats['active_admins'] ?? 'N/A'; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-purple-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-project-diagram text-purple-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Active Projects</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $system_stats['active_projects'] ?? 'N/A'; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-red-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-shield-alt text-red-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Security Events (24h)</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $system_stats['recent_security_events'] ?? 'N/A'; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-comments text-yellow-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Pending Feedback</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $system_stats['pending_feedback'] ?? 'N/A'; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Core System Management -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Core System Management</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- System Configuration -->
            <a href="settings.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                        <i class="fas fa-cogs text-blue-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-blue-600">System Configuration</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Configure core system settings and parameters</p>
            </a>

            <!-- Roles & Permissions -->
            <a href="rolesPermissions.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-green-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                        <i class="fas fa-user-shield text-green-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-green-600">Roles & Permissions</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Configure role-based access control and permission assignments</p>
            </a>

            <!-- Data Encryption -->
            <a href="dataEncryption.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-purple-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                        <i class="fas fa-lock text-purple-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-purple-600">Data Encryption</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Manage encryption keys and configure data security settings</p>
            </a>
        </div>
    </div>

    <!-- System Monitoring & Analytics -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">System Monitoring & Analytics</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Activity Logs -->
            <a href="activityLogs.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-orange-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-200 transition-colors">
                        <i class="fas fa-history text-orange-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-orange-600">Activity Logs</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Track all administrative actions and system activities</p>
            </a>

            <!-- Audit Trail -->
            <a href="auditTrail.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-red-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center group-hover:bg-red-200 transition-colors">
                        <i class="fas fa-search text-red-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-red-600">Audit Trail</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Comprehensive audit trail and security event tracking</p>
            </a>
        </div>
    </div>

    <!-- Communication & Content Management -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Communication & Content Management</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Subscription Manager -->
            <a href="subscriptionManager.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-teal-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center group-hover:bg-teal-200 transition-colors">
                        <i class="fas fa-bell text-teal-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-teal-600">Subscription Manager</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Manage user subscriptions and notification preferences</p>
            </a>

            <!-- Comment Filtering -->
            <a href="commentFiltering.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-yellow-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center group-hover:bg-yellow-200 transition-colors">
                        <i class="fas fa-filter text-yellow-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-yellow-600">Comment Filtering</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Configure content moderation and automated filtering rules</p>
            </a>

            <!-- Feedback Management -->
            <a href="feedback.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-pink-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center group-hover:bg-pink-200 transition-colors">
                        <i class="fas fa-comments text-pink-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-pink-600">Feedback Management</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Review and respond to citizen feedback and suggestions</p>
                <?php if ($system_stats['pending_feedback'] > 0): ?>
                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full mt-2">
                        <?php echo $system_stats['pending_feedback']; ?> pending
                    </span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Security & Login Management -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Security & Login Management</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Login Attempts -->
            <a href="loginAttempts.php" class="group block p-6 bg-white rounded-lg border border-gray-200 hover:border-red-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center group-hover:bg-red-200 transition-colors">
                        <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-red-600">Login Attempts</h3>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">Monitor failed login attempts and suspicious activities</p>
            </a>
        </div>
    </div>

    <!-- System Information -->
    <div class="mt-8 p-6 bg-gray-50 rounded-lg">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">System Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 text-sm">
            <div class="bg-white p-4 rounded-lg">
                <h4 class="font-medium text-gray-900 mb-2">Server Information</h4>
                <div class="space-y-1">
                    <div class="flex justify-between">
                        <span class="text-gray-600">PHP Version:</span>
                        <span class="font-mono text-gray-900"><?php echo PHP_VERSION; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Memory Limit:</span>
                        <span class="font-mono text-gray-900"><?php echo ini_get('memory_limit'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-4 rounded-lg">
                <h4 class="font-medium text-gray-900 mb-2">Database Information</h4>
                <div class="space-y-1">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Connection:</span>
                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">Active</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Status:</span>
                        <span class="font-mono text-gray-900">Online</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-4 rounded-lg">
                <h4 class="font-medium text-gray-900 mb-2">Session Information</h4>
                <div class="space-y-1">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Current User:</span>
                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($current_admin['name']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Role:</span>
                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">
                            <?php echo ucfirst($current_admin['role']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function clearSystemCache() {
    if (confirm('Are you sure you want to clear the system cache? This may temporarily slow down the system.')) {
        fetch('ajax/triggerMaintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({action: 'clear_cache'})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Cache cleared successfully!');
            } else {
                alert('Error clearing cache: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

function optimizeDatabase() {
    if (confirm('Are you sure you want to optimize the database? This may take some time.')) {
        fetch('ajax/triggerMaintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({action: 'optimize_database'})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Database optimized successfully!');
            } else {
                alert('Error optimizing database: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

function createBackup() {
    if (confirm('Create a database backup? This may take some time depending on database size.')) {
        fetch('ajax/triggerMaintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({action: 'create_backup'})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Backup created successfully!');
            } else {
                alert('Error creating backup: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

function viewBackups() {
    window.open('backups/', '_blank');
}
</script>

<?php include 'includes/adminFooter.php'; ?>