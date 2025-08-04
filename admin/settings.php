<?php
require_once 'includes/pageSecurity.php';
require_once '../includes/systemSettings.php';

$current_admin = get_current_admin();

// Force logout if not super admin
if ($current_admin['role'] !== 'super_admin') {
    force_logout_with_incident('unauthorized_system_settings_access');
}

$page_title = "System Settings Configuration";

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        $updated_count = 0;
        $errors = [];
        
        foreach ($_POST['settings'] as $key => $value) {
            if (SystemSettings::set($key, $value, $current_admin['id'])) {
                $updated_count++;
            } else {
                $errors[] = "Failed to update setting: $key";
            }
        }
        
        if ($updated_count > 0 && empty($errors)) {
            $message = "Successfully updated $updated_count settings.";
            $message_type = 'success';
        } elseif (!empty($errors)) {
            $message = "Some settings failed to update: " . implode(', ', $errors);
            $message_type = 'error';
        }
    }
}

// Get all current settings
$all_settings = SystemSettings::getAll();

include 'includes/adminHeader.php';
?>

    <!-- Breadcrumbs -->
    <div class="mb-6">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="index.php" class="text-gray-500 hover:text-gray-700">Dashboard</a></li>
                <li><span class="text-gray-400">/</span></li>
                <li><a href="systemSettings.php" class="text-gray-500 hover:text-gray-700">System Settings</a></li>
                <li><span class="text-gray-400">/</span></li>
                <li><span class="text-gray-900">Configuration</span></li>
            </ol>
        </nav>
    </div>
    
<div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">System Settings Configuration</h1>
        <p class="text-gray-600 mt-2">Manage core system settings and configurations</p>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Settings Form -->
    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_settings">
        
        <!-- Core System Settings -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Core System Settings</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Site Name -->
                <?php if (isset($all_settings['site_name'])): ?>
                <div>
                    <label for="site_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Site Name
                    </label>
                    <input type="text" 
                           id="site_name" 
                           name="settings[site_name]" 
                           value="<?php echo htmlspecialchars($all_settings['site_name']['value']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($all_settings['site_name']['description']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Security Settings -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Security Settings</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Max Login Attempts -->
                <?php if (isset($all_settings['max_login_attempts'])): ?>
                <div>
                    <label for="max_login_attempts" class="block text-sm font-medium text-gray-700 mb-2">
                        Maximum Login Attempts
                    </label>
                    <input type="number" 
                           id="max_login_attempts" 
                           name="settings[max_login_attempts]" 
                           value="<?php echo $all_settings['max_login_attempts']['value']; ?>"
                           min="1" max="20"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($all_settings['max_login_attempts']['description']); ?></p>
                </div>
                <?php endif; ?>

                <!-- Session Timeout -->
                <?php if (isset($all_settings['session_timeout'])): ?>
                <div>
                    <label for="session_timeout" class="block text-sm font-medium text-gray-700 mb-2">
                        Session Timeout (seconds)
                    </label>
                    <input type="number" 
                           id="session_timeout" 
                           name="settings[session_timeout]" 
                           value="<?php echo $all_settings['session_timeout']['value']; ?>"
                           min="300" max="86400"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($all_settings['session_timeout']['description']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- System Control Settings -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">System Control</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Maintenance Mode -->
                <?php if (isset($all_settings['maintenance_mode'])): ?>
                <div>
                    <label for="maintenance_mode" class="block text-sm font-medium text-gray-700 mb-2">
                        Maintenance Mode
                    </label>
                    <select id="maintenance_mode" 
                            name="settings[maintenance_mode]" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="0" <?php echo !$all_settings['maintenance_mode']['value'] ? 'selected' : ''; ?>>Disabled</option>
                        <option value="1" <?php echo $all_settings['maintenance_mode']['value'] ? 'selected' : ''; ?>>Enabled</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($all_settings['maintenance_mode']['description']); ?></p>
                </div>
                <?php endif; ?>

                <!-- Email Notifications -->
                <?php if (isset($all_settings['email_notifications'])): ?>
                <div>
                    <label for="email_notifications" class="block text-sm font-medium text-gray-700 mb-2">
                        Email Notifications
                    </label>
                    <select id="email_notifications" 
                            name="settings[email_notifications]" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="1" <?php echo $all_settings['email_notifications']['value'] ? 'selected' : ''; ?>>Enabled</option>
                        <option value="0" <?php echo !$all_settings['email_notifications']['value'] ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($all_settings['email_notifications']['description']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end">
            <button type="submit" 
                    class="px-6 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Update Settings
            </button>
        </div>
    </form>

    <!-- Current Settings Display -->
    <div class="mt-8 bg-gray-50 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Current Settings Overview</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Setting</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Value</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Updated</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($all_settings as $key => $setting): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($key); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php 
                            if ($setting['type'] === 'boolean') {
                                echo $setting['value'] ? '<span class="text-green-600">Enabled</span>' : '<span class="text-red-600">Disabled</span>';
                            } else {
                                echo htmlspecialchars($setting['value']);
                            }
                            ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                <?php echo htmlspecialchars($setting['type']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $setting['updated_at'] ? date('Y-m-d H:i:s', strtotime($setting['updated_at'])) : 'Never'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-8 bg-yellow-50 rounded-lg p-6 border border-yellow-200">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
        <div class="flex flex-wrap gap-4">
            <button onclick="clearSettingsCache()" 
                    class="px-4 py-2 bg-yellow-600 text-white font-medium rounded-lg hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                Clear Settings Cache
            </button>
            <a href="systemSettings.php" 
               class="px-4 py-2 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500">
                Back to System Settings
            </a>
        </div>
    </div>
</div>

<script>
function clearSettingsCache() {
    if (confirm('Are you sure you want to clear the settings cache?')) {
        fetch('ajax/clearSettingsCache.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Settings cache cleared successfully!');
                location.reload();
            } else {
                alert('Error clearing cache: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}
</script>

<?php include 'includes/adminFooter.php'; ?>
