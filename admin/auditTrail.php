<?php
require_once 'includes/pageSecurity.php';
require_once '../includes/auditTrail.php';
require_once '../includes/EncryptionManager.php';

$current_admin = get_current_admin();

// Force logout if not super admin
if ($current_admin['role'] !== 'super_admin') {
    force_logout_with_incident('unauthorized_audit_trail_access');
}

// Initialize encryption
EncryptionManager::init($pdo);

$page_title = "Audit Trail Management";

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'fix_audit_tables':
            $results = AuditTrail::fixAllAuditableTables();
            echo json_encode(['success' => true, 'results' => $results]);
            break;

        case 'export_audit_data':
            // Process and decrypt data before exporting
            $filters = [
                'date_from' => $_POST['date_from'] ?? '',
                'date_to' => $_POST['date_to'] ?? '',
                'severity' => $_POST['severity'] ?? ''
            ];
            $data = AuditTrail::exportAuditData($filters);
            
            // Decrypt sensitive fields in the exported data
            foreach ($data as &$row) {
                $row = EncryptionManager::processDataForReading('admin_activity_log', $row);
                if (isset($row['admin_name'])) {
                    $admin_data = ['name' => $row['admin_name']];
                    $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
                    $row['admin_name'] = $admin_data['name'];
                }
                if (isset($row['admin_email'])) {
                    $admin_data = ['email' => $row['admin_email']];
                    $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
                    $row['admin_email'] = $admin_data['email'];
                }
                if (isset($row['activity_description'])) {
                    $row['activity_description'] = EncryptionManager::decryptIfNeeded($row['activity_description']);
                }
                if (isset($row['additional_data'])) {
                    $row['additional_data'] = EncryptionManager::decryptIfNeeded($row['additional_data']);
                }
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'cleanup_old_entries':
            $retention_days = intval($_POST['retention_days'] ?? 365);
            $result = AuditTrail::cleanupOldAuditEntries($retention_days);
            echo json_encode($result);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

// Get audit statistics
$audit_stats = AuditTrail::getAuditStats(30);
$security_stats = AuditTrail::getSecurityStats(30);
$integrity_report = AuditTrail::verifyAuditIntegrity();

// Get pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Get recent activities with filters and pagination
$filters = [];
if (isset($_GET['filter_date_from'])) $filters['date_from'] = $_GET['filter_date_from'];
if (isset($_GET['filter_date_to'])) $filters['date_to'] = $_GET['filter_date_to'];
if (isset($_GET['filter_table'])) $filters['table'] = $_GET['filter_table'];
if (isset($_GET['filter_admin'])) $filters['admin'] = $_GET['filter_admin'];
if (isset($_GET['filter_action'])) $filters['action'] = $_GET['filter_action'];
if (isset($_GET['filter_severity'])) $filters['severity'] = $_GET['filter_severity'];

$activities_result = AuditTrail::getRecentActivities($limit, $filters, $offset);
$recent_activities = $activities_result['data'];
$pagination = [
    'current_page' => $activities_result['current_page'],
    'total_pages' => $activities_result['total_pages'],
    'total_records' => $activities_result['total_records'],
    'limit' => $activities_result['limit']
];

// Process and decrypt activity data (already handled in getRecentActivities method)
// Additional processing if needed
foreach ($recent_activities as &$activity) {
    // Ensure all fields are properly decrypted
    if (class_exists('EncryptionManager')) {
        $activity = EncryptionManager::processDataForReading('admin_activity_log', $activity);

        // Specifically decrypt admin name if present
        if (isset($activity['admin_name'])) {
            $admin_data = ['name' => $activity['admin_name']];
            $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
            $activity['admin_name'] = $admin_data['name'];
        }
    }
}

$auditable_tables = AuditTrail::getAuditableTables();

// Get all admins for filter
try {
    $stmt = $pdo->query("SELECT id, name FROM admins ORDER BY name");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt admin names
    foreach ($admins as &$admin) {
        $admin = EncryptionManager::processDataForReading('admins', $admin);
    }
} catch (Exception $e) {
    $admins = [];
}

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
                <li><span class="text-gray-900">Audit Trail</span></li>
            </ol>
        </nav>
    </div>

<div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Audit Trail Management</h1>
        <p class="text-gray-600 mt-2">Comprehensive audit trail monitoring and analysis</p>

        <!-- Integrity Status -->
        <div class="mt-4 p-4 rounded-lg <?php 
            echo $integrity_report['overall_status'] === 'healthy' ? 'bg-green-50 border border-green-200' :
                ($integrity_report['overall_status'] === 'warning' ? 'bg-yellow-50 border border-yellow-200' : 'bg-red-50 border border-red-200');
        ?>">
            <div class="flex items-center">
                <i class="fas <?php 
                    echo $integrity_report['overall_status'] === 'healthy' ? 'fa-check-circle text-green-500' :
                        ($integrity_report['overall_status'] === 'warning' ? 'fa-exclamation-triangle text-yellow-500' : 'fa-exclamation-circle text-red-500');
                ?> text-lg mr-3"></i>
                <div>
                    <h3 class="font-semibold <?php 
                        echo $integrity_report['overall_status'] === 'healthy' ? 'text-green-800' :
                            ($integrity_report['overall_status'] === 'warning' ? 'text-yellow-800' : 'text-red-800');
                    ?>">
                        Audit System Status: <?php echo ucfirst($integrity_report['overall_status']); ?>
                    </h3>
                    <?php if (!empty($integrity_report['issues'])): ?>
                        <ul class="text-sm mt-1 <?php 
                            echo $integrity_report['overall_status'] === 'healthy' ? 'text-green-700' :
                                ($integrity_report['overall_status'] === 'warning' ? 'text-yellow-700' : 'text-red-700');
                        ?>">
                            <?php foreach ($integrity_report['issues'] as $issue): ?>
                                <li>• <?php echo htmlspecialchars($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Dashboard -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-blue-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-history text-blue-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Recent Activities (24h)</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $integrity_report['statistics']['recent_24h'] ?? 0; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-green-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-shield-alt text-green-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Security Events</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $security_stats['total_security_events'] ?? 0; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-purple-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-database text-purple-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Auditable Tables</p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo count($auditable_tables); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-yellow-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-lock text-yellow-600 text-sm"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-600">Encryption Status</p>
                    <p class="text-lg font-semibold text-gray-900">
                        <?php echo ($integrity_report['statistics']['encryption_enabled'] ?? false) ? 'Enabled' : 'Disabled'; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Management Actions -->
    <div class="mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Management Actions</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <button onclick="fixAuditTables()" 
                    class="p-4 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg text-left">
                <div class="flex items-center">
                    <i class="fas fa-tools text-blue-600 text-lg mr-3"></i>
                    <div>
                        <div class="font-medium text-gray-900">Fix Audit Tables</div>
                        <div class="text-sm text-gray-600">Add missing audit columns</div>
                    </div>
                </div>
            </button>

            <button onclick="exportAuditData()" 
                    class="p-4 bg-green-50 hover:bg-green-100 border border-green-200 rounded-lg text-left">
                <div class="flex items-center">
                    <i class="fas fa-download text-green-600 text-lg mr-3"></i>
                    <div>
                        <div class="font-medium text-gray-900">Export Data</div>
                        <div class="text-sm text-gray-600">Download audit trail</div>
                    </div>
                </div>
            </button>

            <button onclick="cleanupOldEntries()" 
                    class="p-4 bg-orange-50 hover:bg-orange-100 border border-orange-200 rounded-lg text-left">
                <div class="flex items-center">
                    <i class="fas fa-trash-alt text-orange-600 text-lg mr-3"></i>
                    <div>
                        <div class="font-medium text-gray-900">Cleanup Old Entries</div>
                        <div class="text-sm text-gray-600">Archive old audit data</div>
                    </div>
                </div>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-6">
        <form method="GET" class="bg-gray-50 p-4 rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-7 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                    <input type="date" name="filter_date_from" 
                           value="<?php echo htmlspecialchars($_GET['filter_date_from'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                    <input type="date" name="filter_date_to" 
                           value="<?php echo htmlspecialchars($_GET['filter_date_to'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Table</label>
                    <select name="filter_table" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Tables</option>
                        <?php foreach ($auditable_tables as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" 
                                    <?php echo ($_GET['filter_table'] ?? '') === $table ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($table); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Admin</label>
                    <select name="filter_admin" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Admins</option>
                        <?php foreach ($admins as $admin): ?>
                            <option value="<?php echo $admin['id']; ?>" 
                                    <?php echo ($_GET['filter_admin'] ?? '') == $admin['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($admin['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Activity Type</label>
                    <select name="filter_action" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Types</option>
                        <option value="create" <?php echo ($_GET['filter_action'] ?? '') === 'create' ? 'selected' : ''; ?>>Create</option>
                        <option value="update" <?php echo ($_GET['filter_action'] ?? '') === 'update' ? 'selected' : ''; ?>>Update</option>
                        <option value="delete" <?php echo ($_GET['filter_action'] ?? '') === 'delete' ? 'selected' : ''; ?>>Delete</option>
                        <option value="login" <?php echo ($_GET['filter_action'] ?? '') === 'login' ? 'selected' : ''; ?>>Login</option>
                        <option value="admin" <?php echo ($_GET['filter_action'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="security" <?php echo ($_GET['filter_action'] ?? '') === 'security' ? 'selected' : ''; ?>>Security</option>
                        <option value="export" <?php echo ($_GET['filter_action'] ?? '') === 'export' ? 'selected' : ''; ?>>Export</option>
                        <option value="import" <?php echo ($_GET['filter_action'] ?? '') === 'import' ? 'selected' : ''; ?>>Import</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Severity</label>
                    <select name="filter_severity" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Levels</option>
                        <option value="high" <?php echo ($_GET['filter_severity'] ?? '') === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo ($_GET['filter_severity'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo ($_GET['filter_severity'] ?? '') === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" 
                            class="w-full px-4 py-2 bg-blue-600 text-white font-medium rounded-md hover:bg-blue-700">
                        Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Recent Activities -->
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Audit Activities</h3>
                <div class="text-sm text-gray-600">
                    Showing <?php echo min(($pagination['current_page'] - 1) * $pagination['limit'] + 1, $pagination['total_records']); ?> 
                    to <?php echo min($pagination['current_page'] * $pagination['limit'], $pagination['total_records']); ?> 
                    of <?php echo $pagination['total_records']; ?> records
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Activity</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Target</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recent_activities)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">No activities found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <?php
                            $severity = 'low';
                            if (strpos($activity['activity_type'], 'delete') !== false || strpos($activity['activity_type'], 'encryption') !== false || strpos($activity['activity_type'], 'failed') !== false) {
                                $severity = 'high';
                            } elseif (strpos($activity['activity_type'], 'update') !== false || strpos($activity['activity_type'], 'login') !== false) {
                                $severity = 'medium';
                            }
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($activity['admin_name'] ?? 'System'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($activity['activity_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($activity['target_type']); ?>
                                    <?php if ($activity['target_id']): ?>
                                        <span class="text-gray-500">#<?php echo $activity['target_id']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo htmlspecialchars(substr($activity['activity_description'], 0, 100)); ?>
                                    <?php if (strlen($activity['activity_description']) > 100): ?>...<?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        <?php 
                                        echo $severity === 'high' ? 'bg-red-100 text-red-800' : 
                                            ($severity === 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800');
                                        ?>">
                                        <?php echo ucfirst($severity); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Page <?php echo $pagination['current_page']; ?> of <?php echo $pagination['total_pages']; ?>
                </div>
                
                <div class="flex items-center space-x-2">
                    <?php if ($pagination['current_page'] > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">
                            First
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] - 1])); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">
                            Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $pagination['current_page'] - 2);
                    $end_page = min($pagination['total_pages'], $pagination['current_page'] + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="px-3 py-2 text-sm border rounded-md <?php echo $i == $pagination['current_page'] ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">
                            Next
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['total_pages']])); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">
                            Last
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function fixAuditTables() {
    if (!confirm('This will add missing audit columns to all auditable tables. Continue?')) return;

    const formData = new FormData();
    formData.append('action', 'fix_audit_tables');
    formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
    formData.append('ajax', '1');

    fetch('auditTrail.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let message = 'Audit table fix completed:\n';
            for (const [table, result] of Object.entries(data.results)) {
                message += `${table}: ${result.message}\n`;
            }
            alert(message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

function exportAuditData() {
    const dateFrom = prompt('Enter start date (YYYY-MM-DD) or leave empty:');
    const dateTo = prompt('Enter end date (YYYY-MM-DD) or leave empty:');

    const formData = new FormData();
    formData.append('action', 'export_audit_data');
    formData.append('date_from', dateFrom || '');
    formData.append('date_to', dateTo || '');
    formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
    formData.append('ajax', '1');

    fetch('auditTrail.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const csv = convertToCSV(data.data);
            downloadCSV(csv, 'audit_trail_export.csv');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during export');
    });
}

function cleanupOldEntries() {
    const retentionDays = prompt('Enter retention period in days (default: 365):', '365');
    if (!retentionDays || isNaN(retentionDays)) return;

    if (!confirm(`This will archive audit entries older than ${retentionDays} days. Continue?`)) return;

    const formData = new FormData();
    formData.append('action', 'cleanup_old_entries');
    formData.append('retention_days', retentionDays);
    formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
    formData.append('ajax', '1');

    fetch('auditTrail.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Cleanup completed:\nArchived: ${data.archived} entries\nDeleted: ${data.deleted} entries`);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during cleanup');
    });
}

function convertToCSV(data) {
    if (!data.length) return 'No data available';

    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(header => `"${(row[header] || '').toString().replace(/"/g, '""')}"`).join(','))
    ].join('\n');

    return csvContent;
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php include 'includes/adminFooter.php'; ?>