<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';

// Security and access control
require_role('admin');
$current_admin = get_current_admin();

// Page configuration
$page_title = "Advanced Analytics Dashboard";
include 'includes/adminHeader.php';

// Log dashboard access
log_activity('dashboard_access', 'Accessed PMC analytics dashboard', $current_admin['id']);

/**
 * Initialize dashboard data with default values
 */
function initialize_dashboard_data() {
    return [
        'stats' => [
            'total_projects' => 0,
            'planning_projects' => 0,
            'ongoing_projects' => 0,
            'completed_projects' => 0,
            'suspended_projects' => 0,
            'cancelled_projects' => 0
        ],
        'financial_stats' => [
            'base_budget' => 0,
            'total_budget_increases' => 0,
            'total_budget' => 0,
            'total_expenditure' => 0,
            'total_allocated' => 0,
            'total_disbursed' => 0,
            'remaining_funds' => 0,
            'avg_budget_per_project' => 0
        ],
        'progress_stats' => [
            'avg_progress' => 0,
            'projects_over_50' => 0,
            'projects_over_75' => 0,
            'stalled_projects' => 0,
            'projects_0_25' => 0,
            'projects_26_50' => 0,
            'projects_51_75' => 0,
            'projects_76_100' => 0,
            'projects_completed' => 0,
            'projects_near_completion' => 0
        ],
        'feedback_stats' => [
            'total_feedback' => 0,
            'pending_feedback' => 0,
            'reviewed_feedback' => 0,
            'responded_feedback' => 0,
            'grievances_count' => 0,
            'avg_rating' => 0
        ],
        'department_performance' => [],
        'monthly_trends' => [],
        'location_stats' => [],
        'budget_expenditure' => [],
        'recent_activities' => [],
        'projects_by_status' => [],
        'weekly_progress' => [],
        'transaction_stats' => [],
        'dept_efficiency' => []
    ];
}



 //Fetch project statistics

function fetch_project_statistics($pdo, $role_filter, $role_params) {
    $stats = [];
    $statuses = ['planning', 'ongoing', 'completed', 'suspended', 'cancelled'];

    // Total projects
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $role_filter);
    $stmt->execute($role_params);
    $stats['total_projects'] = (int)$stmt->fetchColumn();

    // Projects by status
    foreach ($statuses as $status) {
        $filter = $role_filter ? $role_filter . " AND status = ?" : " WHERE status = ?";
        $params = $role_filter ? array_merge($role_params, [$status]) : [$status];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $filter);
        $stmt->execute($params);
        $stats["{$status}_projects"] = (int)$stmt->fetchColumn();
    }

    return $stats;
}

/**
 * Fetch financial statistics
 */
function fetch_financial_statistics($pdo, $role_filter, $role_params, $total_projects) {
    $stats = [];

    // Base budget
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_budget), 0) as base_budget
        FROM projects " . $role_filter . "
        AND total_budget IS NOT NULL AND total_budget > 0
    ");
    $stmt->execute($role_params);
    $budget_data = $stmt->fetch();
    $stats['base_budget'] = (float)($budget_data['base_budget'] ?? 0);

    // Budget increases
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(pt.amount), 0) as budget_increases
        FROM project_transactions pt
        " . ($role_filter ? "JOIN projects p ON pt.project_id = p.id " . $role_filter . " AND" : "WHERE") . " 
        pt.transaction_type = 'budget_increase' AND pt.transaction_status = 'active'
    ");
    $stmt->execute($role_params);
    $increase_data = $stmt->fetch();
    $stats['total_budget_increases'] = (float)($increase_data['budget_increases'] ?? 0);

    // Calculate total budget
    $stats['total_budget'] = $stats['base_budget'] + $stats['total_budget_increases'];

    // Expenditure data
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pt.transaction_type = 'expenditure' AND pt.transaction_status = 'active' THEN pt.amount ELSE 0 END), 0) as total_expenditure,
            COALESCE(SUM(CASE WHEN pt.transaction_type = 'disbursement' AND pt.transaction_status = 'active' THEN pt.amount ELSE 0 END), 0) as total_disbursed
        FROM project_transactions pt
        " . ($role_filter ? "JOIN projects p ON pt.project_id = p.id " . $role_filter : "")
    );
    $stmt->execute($role_params);
    $transaction_data = $stmt->fetch();

    $stats['total_expenditure'] = (float)($transaction_data['total_expenditure'] ?? 0);
    $stats['total_allocated'] = $stats['total_budget'];
    $stats['total_disbursed'] = (float)($transaction_data['total_disbursed'] ?? 0);
    $stats['remaining_funds'] = $stats['total_allocated'] - $stats['total_expenditure'];
    $stats['avg_budget_per_project'] = $total_projects > 0 ? 
        round($stats['total_budget'] / $total_projects, 2) : 0;

    return $stats;
}

 //Fetch progress statistics
function fetch_progress_statistics($pdo, $role_filter, $role_params, $completed_projects) {
    $stats = [
        'avg_progress' => 0,
        'projects_over_50' => 0,
        'projects_over_75' => 0,
        'stalled_projects' => 0,
        'projects_0_25' => 0,
        'projects_26_50' => 0,
        'projects_51_75' => 0,
        'projects_76_100' => 0,
        'projects_completed' => $completed_projects,
        'projects_near_completion' => 0
    ];

    // Average progress
    $stmt = $pdo->prepare("SELECT AVG(COALESCE(progress_percentage, 0)) FROM projects" . $role_filter);
    $stmt->execute($role_params);
    $avg_result = $stmt->fetchColumn();
    $stats['avg_progress'] = round((float)($avg_result ?: 0), 1);

    // Progress ranges
    $ranges = [
        'over_50' => "progress_percentage > 50",
        'over_75' => "progress_percentage > 75",
        'stalled' => "(progress_percentage = 0 OR progress_percentage IS NULL) AND status = 'ongoing'",
        '0_25' => "progress_percentage BETWEEN 0 AND 25",
        '26_50' => "progress_percentage BETWEEN 26 AND 50",
        '51_75' => "progress_percentage BETWEEN 51 AND 75",
        '76_100' => "progress_percentage BETWEEN 76 AND 100",
        'near_completion' => "progress_percentage >= 90"
    ];

    foreach ($ranges as $key => $condition) {
        $filter = $role_filter ? $role_filter . " AND " . $condition : " WHERE " . $condition;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $filter);
        $stmt->execute($role_params);

        $stat_key = strpos($key, '_') !== false ? "projects_" . $key : "projects_" . $key;
        $stats[$stat_key] = (int)$stmt->fetchColumn();
    }

    return $stats;
}

/**
 * Fetch feedback statistics
 */
function fetch_feedback_statistics($pdo, $current_admin) {
    $stats = [
        'total_feedback' => 0,
        'pending_feedback' => 0,
        'reviewed_feedback' => 0,
        'responded_feedback' => 0,
        'grievances_count' => 0,
        'avg_rating' => 0
    ];

    if ($current_admin['role'] === 'super_admin') {
        // Super admin sees all feedback - these are COUNT queries so encryption doesn't affect results
        $stats['total_feedback'] = $pdo->query("SELECT COUNT(*) FROM feedback")->fetchColumn();
        $stats['pending_feedback'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")->fetchColumn();
        $stats['reviewed_feedback'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'reviewed'")->fetchColumn();
        $stats['responded_feedback'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'responded'")->fetchColumn();
        $stats['grievances_count'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'grievance'")->fetchColumn();
        $stats['avg_rating'] = $pdo->query("SELECT AVG(rating) FROM feedback WHERE rating IS NOT NULL")->fetchColumn() ?: 0;
    } else {
        // Regular admin only sees feedback for their projects
        $queries = [
            'total_feedback' => "SELECT COUNT(*) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ?",
            'pending_feedback' => "SELECT COUNT(*) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ? AND f.status = 'pending'",
            'reviewed_feedback' => "SELECT COUNT(*) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ? AND f.status = 'reviewed'",
            'responded_feedback' => "SELECT COUNT(*) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ? AND f.status = 'responded'",
            'grievances_count' => "SELECT COUNT(*) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ? AND f.status = 'grievance'",
            'avg_rating' => "SELECT AVG(f.rating) FROM feedback f JOIN projects p ON f.project_id = p.id WHERE p.created_by = ? AND f.rating IS NOT NULL"
        ];

        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$current_admin['id']]);
            $stats[$key] = $key === 'avg_rating' ? (float)($stmt->fetchColumn() ?: 0) : (int)$stmt->fetchColumn();
        }
    }

    return $stats;
}

/**
 * Fetch department performance data
 */
function fetch_department_performance($pdo) {
    return $pdo->query("
        SELECT d.name, 
               COUNT(p.id) as project_count,
               COALESCE(AVG(p.progress_percentage), 0) as avg_progress,
               COALESCE(SUM(p.total_budget), 0) as total_budget,
               COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as completed_count
        FROM departments d 
        LEFT JOIN projects p ON d.id = p.department_id 
        GROUP BY d.id, d.name 
        HAVING project_count > 0
        ORDER BY project_count DESC
        LIMIT 10
    ")->fetchAll();
}

/**
 * Fetch monthly trends data
 */
function fetch_monthly_trends($pdo, $role_filter, $role_params) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as projects_created,
            COALESCE(SUM(total_budget), 0) as monthly_budget,
            COALESCE(AVG(total_budget), 0) as avg_monthly_budget
        FROM projects 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        " . ($role_filter ? " AND " . str_replace("WHERE ", "", $role_filter) : "") . "
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    $stmt->execute($role_params);
    return $stmt->fetchAll();
}

/**
 * Fetch location statistics
 */
function fetch_location_statistics($pdo) {
    return $pdo->query("
        SELECT 
            sc.name as sub_county,
            COUNT(p.id) as project_count,
            AVG(p.progress_percentage) as avg_progress,
            SUM(p.total_budget) as total_budget
        FROM sub_counties sc
        LEFT JOIN projects p ON sc.id = p.sub_county_id
        GROUP BY sc.id, sc.name
        HAVING project_count > 0
        ORDER BY project_count DESC
        LIMIT 10
    ")->fetchAll();
}

/**
 * Fetch budget vs expenditure data
 */
function fetch_budget_expenditure($pdo) {
    return $pdo->query("
        SELECT 
            d.name as department,
            SUM(p.total_budget) as allocated_budget,
            COALESCE(SUM(t.amount), 0) as total_expenditure
        FROM departments d
        LEFT JOIN projects p ON d.id = p.department_id
        LEFT JOIN project_transactions t ON p.id = t.project_id AND t.transaction_type = 'expenditure' AND t.transaction_status = 'active'
        GROUP BY d.id, d.name
        HAVING allocated_budget > 0
        ORDER BY allocated_budget DESC
    ")->fetchAll();
}

/**
 * Fetch projects by status for charts
 */
function fetch_projects_by_status($pdo, $role_filter, $role_params) {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM projects 
        " . $role_filter . "
        GROUP BY status
    ");
    $stmt->execute($role_params);
    return $stmt->fetchAll();
}

/**
 * Fetch weekly progress data
 */
function fetch_weekly_progress($pdo, $role_filter, $role_params) {
    $stmt = $pdo->prepare("
        SELECT 
            WEEK(updated_at) as week_num,
            AVG(progress_percentage) as avg_progress
        FROM projects 
        WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
        " . ($role_filter ? " AND " . str_replace("WHERE ", "", $role_filter) : "") . "
        GROUP BY WEEK(updated_at)
        ORDER BY week_num
    ");
    $stmt->execute($role_params);
    return $stmt->fetchAll();
}

/**
 * Fetch transaction statistics
 */
function fetch_transaction_statistics($pdo, $role_filter, $role_params) {
    $stmt = $pdo->prepare("
        SELECT 
            transaction_type,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount
        FROM project_transactions pt
        " . ($role_filter ? "JOIN projects p ON pt.project_id = p.id " . $role_filter : "") . "
        WHERE pt.transaction_status = 'active'
        GROUP BY transaction_type
    ");
    $stmt->execute($role_params);
    return $stmt->fetchAll();
}

/**
 * Fetch department efficiency metrics (super admin only)
 */
function fetch_department_efficiency($pdo) {
    return $pdo->query("
        SELECT 
            d.name as department_name,
            COUNT(p.id) as total_projects,
            AVG(p.progress_percentage) as avg_progress,
            SUM(p.total_budget) as total_budget,
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as completed_projects,
            COUNT(CASE WHEN p.status = 'ongoing' THEN 1 END) as ongoing_projects,
            DATEDIFF(NOW(), MIN(p.created_at)) as days_since_first_project
        FROM departments d
        LEFT JOIN projects p ON d.id = p.department_id
        GROUP BY d.id, d.name
        HAVING total_projects > 0
        ORDER BY avg_progress DESC, completed_projects DESC
    ")->fetchAll();
}

// Main data fetching logic
try {
    $dashboard_data = initialize_dashboard_data();
    $role = build_role_filter($current_admin);

    // Fetch all data
    $dashboard_data['stats'] = fetch_project_statistics($pdo, $role['filter'], $role['params']);
    $dashboard_data['financial_stats'] = fetch_financial_statistics($pdo, $role['filter'], $role['params'], $dashboard_data['stats']['total_projects']);
    $dashboard_data['progress_stats'] = fetch_progress_statistics($pdo, $role['filter'], $role['params'], $dashboard_data['stats']['completed_projects']);
    $dashboard_data['feedback_stats'] = fetch_feedback_statistics($pdo, $current_admin);
    $dashboard_data['department_performance'] = fetch_department_performance($pdo);
    $dashboard_data['monthly_trends'] = fetch_monthly_trends($pdo, $role['filter'], $role['params']);
    $dashboard_data['location_stats'] = fetch_location_statistics($pdo);
    $dashboard_data['budget_expenditure'] = fetch_budget_expenditure($pdo);
    $dashboard_data['recent_activities'] = get_recent_activities(10);
    $dashboard_data['projects_by_status'] = fetch_projects_by_status($pdo, $role['filter'], $role['params']);
    $dashboard_data['weekly_progress'] = fetch_weekly_progress($pdo, $role['filter'], $role['params']);
    $dashboard_data['transaction_stats'] = fetch_transaction_statistics($pdo, $role['filter'], $role['params']);

    if ($current_admin['role'] === 'super_admin') {
        $dashboard_data['dept_efficiency'] = fetch_department_efficiency($pdo);
    }

    // Get recent admins with automatic decryption
    $recent_admins = EncryptionHelper::selectDecrypted($pdo, 
        "SELECT id, name, email, role, last_login FROM admins ORDER BY created_at DESC LIMIT 5",
        [], 'admins');

    // Get recent feedback with automatic decryption
    $recent_feedback = EncryptionHelper::selectDecrypted($pdo,
        "SELECT f.*, p.project_name FROM feedback f 
         LEFT JOIN projects p ON f.project_id = p.id 
         ORDER BY f.created_at DESC LIMIT 5",
        [], 'feedback');

    // Extract variables for template
    extract($dashboard_data);

} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $dashboard_data = initialize_dashboard_data();
    extract($dashboard_data);
}
?>

<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <!-- Breadcrumb -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2 text-sm">
                    <li class="text-gray-600 font-medium">
                        <a href="index.php" class="text-blue-600 hover:text-blue-800 font-medium">
                        <i class="fas fa-home mr-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="text-gray-400">/</li>
                    <li class="text-gray-600 font-medium">Analytics</li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <div class="mb-8">
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <div class="flex flex-col md:flex-row items-start justify-between">
                    <div class="mb-4 md:mb-0">
                        <h1 class="text-2xl font-bold text-gray-900 mb-2">PMC Advanced Analytics</h1>
                        <p class="text-gray-600">Comprehensive project management insights and performance metrics</p>
                        <p class="text-sm text-gray-500 mt-2">
                            Last updated: <?php echo date('F d, Y \a\t H:i A'); ?>
                        </p>
                    </div>
                    <div class="text-center md:text-right">
                        <div class="text-3xl font-bold text-blue-600 mb-1"><?php echo number_format($stats['total_projects']); ?></div>
                        <div class="text-sm text-gray-600 mb-3">Total Projects</div>
                        <div class="text-xs text-gray-500">Across all departments</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Performance Indicators -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Financial Overview -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-coins text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Budget</p>
                        <p class="text-2xl font-bold text-gray-900">KES <?php echo number_format($financial_stats['total_budget']); ?></p>
                        <p class="text-xs text-gray-500 mt-1">Avg: KES <?php echo number_format($financial_stats['avg_budget_per_project']); ?> per project</p>
                    </div>
                </div>
            </div>

            <!-- Progress Performance -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-chart-line text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Average Progress</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo round($progress_stats['avg_progress'], 1); ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?php echo $progress_stats['projects_over_75']; ?> projects >75% complete</p>
                    </div>
                </div>
            </div>

            <!-- Community Engagement -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-users text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Community Feedback</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $feedback_stats['total_feedback']; ?></p>
                        <p class="text-xs text-gray-500 mt-1">Rating: <?php echo round($feedback_stats['avg_rating'], 1); ?>/5.0 ⭐</p>
                    </div>
                </div>
            </div>

            <!-- Expenditure Efficiency -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-chart-pie text-orange-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Expenditure</p>
                        <p class="text-2xl font-bold text-gray-900">KES <?php echo number_format($financial_stats['total_expenditure']); ?></p>
                        <p class="text-xs text-gray-500 mt-1">
                            <?php 
                            $expenditure_rate = $financial_stats['total_budget'] > 0 ? 
                                round(($financial_stats['total_expenditure'] / $financial_stats['total_budget']) * 100, 1) : 0;
                            echo $expenditure_rate; 
                            ?>% of budget utilized
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Project Status Distribution and Department Performance -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-chart-pie mr-2 text-blue-600"></i>
                        Project Status Distribution
                    </h3>
                </div>
                <div class="p-6">
                    <div class="flex justify-center">
                        <canvas id="statusDistributionChart" width="300" height="300"></canvas>
                    </div>
                </div>
            </div>

            <!-- Department Performance Leaderboard -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 lg:col-span-2">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-trophy mr-2 text-yellow-600"></i>
                        Department Performance Leaderboard
                    </h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3 max-h-80 overflow-y-auto">
                        <?php foreach ($department_performance as $index => $dept): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex items-center space-x-4">
                                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($dept['name']); ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo $dept['project_count']; ?> projects • <?php echo round($dept['avg_progress'], 1); ?>% avg progress</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-green-600">KES <?php echo number_format($dept['total_budget']); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo $dept['completed_count']; ?> completed</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Analytics Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Budget vs Expenditure Analysis -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-balance-scale mr-2 text-green-600"></i>
                        Budget vs Expenditure by Department
                    </h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="budgetExpenditureChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Monthly Project Trends -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-trending-up mr-2 text-purple-600"></i>
                        Monthly Project Creation Trends
                    </h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="monthlyTrendsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Analytics and Progress Tracking -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Geographic Distribution -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-map-marked-alt mr-2 text-red-600"></i>
                        Top Performing Sub-Counties
                    </h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3 max-h-80 overflow-y-auto">
                        <?php foreach ($location_stats as $location): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                                <div>
                                    <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($location['sub_county']); ?></h4>
                                    <p class="text-sm text-gray-600"><?php echo $location['project_count']; ?> projects</p>
                                </div>
                                <div class="text-right">
                                    <div class="flex items-center space-x-2">
                                        <div class="progress-ring" style="--progress: <?php echo round($location['avg_progress'] * 3.6); ?>deg; width: 40px; height: 40px;">
                                            <span class="text-xs font-bold relative z-10"><?php echo round($location['avg_progress']); ?>%</span>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-500 mt-1">KES <?php echo number_format($location['total_budget'] / 1000000, 1); ?>M</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Progress Analytics -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-tasks mr-2 text-indigo-600"></i>
                        Progress Analytics
                    </h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-2xl font-bold text-green-600"><?php echo $progress_stats['projects_over_50']; ?></div>
                            <div class="text-sm text-gray-600">Projects >50%</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $progress_stats['projects_over_75']; ?></div>
                            <div class="text-sm text-gray-600">Projects >75%</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-2xl font-bold text-orange-600"><?php echo $progress_stats['stalled_projects']; ?></div>
                            <div class="text-sm text-gray-600">Stalled Projects</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-2xl font-bold text-purple-600"><?php echo round($progress_stats['avg_progress'], 1); ?>%</div>
                            <div class="text-sm text-gray-600">Average Progress</div>
                        </div>
                    </div>
                    <div class="chart-container" style="height: 200px;">
                        <canvas id="progressDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feedback Analytics and Recent Activities -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Community Feedback Analysis -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-comments mr-2 text-blue-600"></i>
                        Community Feedback Analytics
                    </h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-xl font-bold text-blue-600"><?php echo $feedback_stats['total_feedback']; ?></div>
                            <div class="text-sm text-gray-600">Total Feedback</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="text-xl font-bold text-orange-600"><?php echo $feedback_stats['pending_feedback']; ?></div>
                            <div class="text-sm text-gray-600">Pending Review</div>
                        </div>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="feedbackStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Activities Feed -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-clock mr-2 text-green-600"></i>
                        Recent System Activities
                    </h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3 max-h-80 overflow-y-auto">
                        <?php foreach (array_slice($recent_activities, 0, 8) as $activity): ?>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center flex-shrink-0">
                                    <span class="text-white font-bold text-xs">
                                        <?php echo strtoupper(substr($activity['admin_name'] ?? 'S', 0, 1)); ?>
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($activity['admin_name'] ?? 'System'); ?>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($activity['activity_description']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?php echo format_date($activity['created_at']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        // Pass PHP data to JavaScript
        window.dashboardData = {
            projects_by_status: <?php echo json_encode($projects_by_status); ?>,
            budget_expenditure: <?php echo json_encode($budget_expenditure); ?>,
            monthly_trends: <?php echo json_encode($monthly_trends); ?>,
            progress_stats: {
                projects_0_25: <?php echo $progress_stats['projects_0_25']; ?>,
                projects_26_50: <?php echo $progress_stats['projects_26_50']; ?>,
                projects_51_75: <?php echo $progress_stats['projects_51_75']; ?>,
                projects_76_100: <?php echo $progress_stats['projects_76_100']; ?>
            },
            feedback_stats: {
                pending_feedback: <?php echo $feedback_stats['pending_feedback']; ?>,
                reviewed_feedback: <?php echo $feedback_stats['reviewed_feedback']; ?>,
                responded_feedback: <?php echo $feedback_stats['responded_feedback']; ?>
            }
        };

        // Initialize charts when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Status Distribution Pie Chart
            const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: window.dashboardData.projects_by_status.map(item => item.status),
                    datasets: [{
                        data: window.dashboardData.projects_by_status.map(item => item.count),
                        backgroundColor: [
                            '#3B82F6', // Blue
                            '#10B981', // Green
                            '#F59E0B', // Amber
                            '#EF4444', // Red
                            '#8B5CF6'  // Purple
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Progress Distribution Bar Chart
            const progressCtx = document.getElementById('progressDistributionChart').getContext('2d');
            new Chart(progressCtx, {
                type: 'bar',
                data: {
                    labels: ['0-25%', '26-50%', '51-75%', '76-100%'],
                    datasets: [{
                        label: 'Projects by Progress Range',
                        data: [
                            window.dashboardData.progress_stats.projects_0_25,
                            window.dashboardData.progress_stats.projects_26_50,
                            window.dashboardData.progress_stats.projects_51_75,
                            window.dashboardData.progress_stats.projects_76_100
                        ],
                        backgroundColor: [
                            '#EF4444', // Red
                            '#F59E0B', // Amber
                            '#3B82F6', // Blue
                            '#10B981'  // Green
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Budget vs Expenditure Bar Chart
            const budgetCtx = document.getElementById('budgetExpenditureChart').getContext('2d');
            new Chart(budgetCtx, {
                type: 'bar',
                data: {
                    labels: window.dashboardData.budget_expenditure.map(item => item.department),
                    datasets: [
                        {
                            label: 'Allocated Budget',
                            data: window.dashboardData.budget_expenditure.map(item => item.allocated_budget),
                            backgroundColor: '#3B82F6' // Blue
                        },
                        {
                            label: 'Total Expenditure',
                            data: window.dashboardData.budget_expenditure.map(item => item.total_expenditure),
                            backgroundColor: '#10B981' // Green
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Monthly Trends Line Chart
            const trendsCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
            new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: window.dashboardData.monthly_trends.map(item => item.month),
                    datasets: [{
                        label: 'Projects Created',
                        data: window.dashboardData.monthly_trends.map(item => item.projects_created),
                        borderColor: '#3B82F6', // Blue
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Feedback Status Doughnut Chart
            const feedbackCtx = document.getElementById('feedbackStatusChart').getContext('2d');
            new Chart(feedbackCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Reviewed', 'Responded'],
                    datasets: [{
                        data: [
                            window.dashboardData.feedback_stats.pending_feedback,
                            window.dashboardData.feedback_stats.reviewed_feedback,
                            window.dashboardData.feedback_stats.responded_feedback
                        ],
                        backgroundColor: [
                            '#F59E0B', // Amber
                            '#3B82F6', // Blue
                            '#10B981'  // Green
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });
        </script>

        <!-- Progress Ring CSS -->
        <style>
        .progress-ring {
            position: relative;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: conic-gradient(
                #3B82F6 var(--progress),
                #E5E7EB var(--progress)
            );
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .progress-ring::before {
            content: '';
            position: absolute;
            width: 80%;
            height: 80%;
            border-radius: 50%;
            background: white;
        }
        .progress-ring span {
            z-index: 1;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        </style>
    </div>


 <?php include 'includes/adminFooter.php'; ?>