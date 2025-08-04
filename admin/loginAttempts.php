<?php
require_once 'includes/pageSecurity.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Require authentication and super admin role only
require_admin();
$current_admin = get_current_admin();

// Force logout if not super admin
if ($current_admin['role'] !== 'super_admin') {
    force_logout_with_incident('unauthorized_login_attempts_access');
}

$page_title = "Login Attempt Trail";
$current_page = 'security';

// Get filters
$filters = [
    'status' => $_GET['status'] ?? '',
    'email' => $_GET['email'] ?? '',
    'ip_address' => $_GET['ip_address'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'page' => $_GET['page'] ?? 1
];

// Get login attempts with proper decryption
try {
    $per_page = 20;
    $attempts_data = get_login_attempts($filters, true, $per_page);
    $login_attempts = $attempts_data['attempts'] ?? [];
    $total_attempts = $attempts_data['total'] ?? 0;

    // Additional processing is already handled in get_login_attempts function
} catch (Exception $e) {
    error_log("Error getting login attempts: " . $e->getMessage());
    $login_attempts = [];
    $total_attempts = 0;
}

// Get statistics
$stats = get_login_attempt_stats(30); // Last 30 days

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
            <li class="text-gray-600 font-medium">Login Attempts Trail</li>
        </ol>
    </nav>
</div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check text-green-600"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Successful Logins</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($stats['successful_logins']); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-times text-red-600"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Failed Attempts</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($stats['failed_attempts']); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-percentage text-blue-600"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Success Rate</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['success_rate']; ?>%</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-purple-600"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Unique Users</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($stats['unique_users']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Filters</h3>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All Statuses</option>
                    <option value="success" <?php echo $filters['status'] === 'success' ? 'selected' : ''; ?>>Success</option>
                    <option value="fail" <?php echo $filters['status'] === 'fail' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="text" name="email" value="<?php echo htmlspecialchars($filters['email']); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                       placeholder="Search by email">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">IP Address</label>
                <input type="text" name="ip_address" value="<?php echo htmlspecialchars($filters['ip_address']); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                       placeholder="Search by IP">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($filters['start_date']); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($filters['end_date']); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors">
                    <i class="fas fa-search mr-2"></i>Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Login Attempts Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Login Attempts</h3>
        </div>

        <?php if (!empty($login_attempts)): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attempts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User Agent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Failure Reason</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($login_attempts as $attempt): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php 
                                        // Get user display name from available data
                                        $display_name = 'N/A';

                                        if (!empty($attempt['name'])) {
                                            // Use admin name from JOIN
                                            $display_name = $attempt['name'];
                                        } elseif (!empty($attempt['email'])) {
                                            // Use email and extract username part
                                            $email = $attempt['email'];
                                            if (strpos($email, '@') !== false) {
                                                $display_name = explode('@', $email)[0];
                                            } else {
                                                $display_name = $email;
                                            }
                                        }

                                        echo htmlspecialchars($display_name);
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php 
                                        $email = $attempt['email'] ?? 'N/A';
                                        // Email is automatically decrypted by pdo_select function
                                        echo htmlspecialchars($email);
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $attempt['status'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo ucfirst($attempt['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="font-medium">1</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php 
                                    $ip = $attempt['ip_address'] ?? 'Unknown';
                                    // IP is automatically decrypted by pdo_select function
                                    echo safe_output($ip);
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-500 max-w-xs truncate" title="<?php echo htmlspecialchars($attempt['user_agent'] ?? 'Unknown'); ?>">
                                        <?php 
                                        $user_agent = $attempt['user_agent'] ?? 'Unknown';
                                        // User agent is automatically decrypted by pdo_select function
                                        echo htmlspecialchars(substr($user_agent, 0, 50) . (strlen($user_agent) > 50 ? '...' : ''));
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo format_date($attempt['timestamp']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo $attempt['failure_reason'] ? htmlspecialchars($attempt['failure_reason']) : '-'; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php 
            $total_pages = $attempts_data['total_pages'] ?? 1; 
            $current_page = $attempts_data['current_page'] ?? 1;
            ?>
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to 
                            <?php echo min($current_page * $per_page, $total_attempts); ?> of 
                            <?php echo number_format($total_attempts); ?> results
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($current_page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($filters, ['page' => $current_page - 1])); ?>" 
                                   class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                    Previous
                                </a>
                            <?php endif; ?>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($filters, ['page' => $current_page + 1])); ?>" 
                                   class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                    Next
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No login attempts found</h3>
                <p class="text-gray-500">Try adjusting your search criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/adminFooter.php'; ?>
