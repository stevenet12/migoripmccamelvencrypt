<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/EncryptionManager.php';

// Initialize EncryptionManager with PDO connection
EncryptionManager::init($pdo);

$project_id = 0;
$url_slug = '';

if (isset($_GET['id'])) {
    $project_id = (int)$_GET['id'];
    $url_slug = $_GET['slug'] ?? '';
} else {
    $uri = $_SERVER['REQUEST_URI'];
    $segments = explode('/', trim($uri, '/'));
    if (count($segments) >= 2 && $segments[0] === 'projectDetails' && is_numeric($segments[1])) {
        $project_id = (int)$segments[1];
        $url_slug = $segments[2] ?? '';
    }
}

// Get project details
$project = pdo_select_one($pdo, "SELECT p.*, d.name as department_name, sc.name as sub_county_name, 
                                      w.name as ward_name, c.name as county_name
                               FROM projects p 
                               LEFT JOIN departments d ON p.department_id = d.id
                               LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id  
                               LEFT JOIN wards w ON p.ward_id = w.id
                               LEFT JOIN counties c ON p.county_id = c.id
                               WHERE p.id = ?", [$project_id], 'projects');

if (!$project) {
    header("HTTP/1.0 404 Not Found");
    include '404.php';
    exit;
}

// Enforce canonical slug URL
$correct_slug = create_url_slug($project['project_name']);
$correct_url = generate_project_url($project_id, $project['project_name']);
if ($correct_slug && $url_slug !== $correct_slug) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: $correct_url");
    exit;
}

// Restrict private projects
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
if ($project['visibility'] === 'private' && !$is_admin) {
    header("HTTP/1.0 404 Not Found");
    include '404.php';
    exit;
}

// Get project steps
$project_steps = pdo_select($pdo, "SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number", [$project_id], 'project_steps', true);

// === Fetch Comments + Replies (With Encryption Support) ===
$user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// First, get all top-level comments with pagination (show 10 comments initially)
$comments_limit = 10;
$sql = "SELECT f.*, 
               a.name as admin_name,
               CASE WHEN f.user_ip = ? AND f.user_agent = ? AND f.status = 'pending' THEN 1 ELSE 0 END as is_user_pending,
               CASE WHEN f.comment_type = 'admin_response' OR f.admin_id IS NOT NULL THEN 1 ELSE 0 END as is_admin_comment
        FROM feedback f
        LEFT JOIN admins a ON f.admin_id = a.id
        WHERE f.project_id = ?
        AND (f.parent_comment_id IS NULL OR f.parent_comment_id = 0)
        AND f.comment_type IN ('user_comment', 'user_reply')
        AND (
            f.status IN ('approved', 'reviewed', 'responded')
            OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?)
        )
        ORDER BY f.created_at DESC
        LIMIT ?";

$main_comments = pdo_select($pdo, $sql, [$user_ip, $user_agent, $project_id, $user_ip, $user_agent, $comments_limit], 'feedback', true);

// Get total count of top-level comments (exclude admin responses from main count)
$count_sql = "SELECT COUNT(*) 
              FROM feedback f
              WHERE f.project_id = ?
              AND (f.parent_comment_id IS NULL OR f.parent_comment_id = 0)
              AND f.comment_type IN ('user_comment', 'user_reply')
              AND f.status IN ('approved', 'reviewed', 'responded')";
$total_comments_stmt = $pdo->prepare($count_sql);
$total_comments_stmt->execute([$project_id]);
$total_comments_count = $total_comments_stmt->fetchColumn();

// For each comment, get replies (show 4 replies initially)
$project_comments = [];
$replies_limit = 4;

foreach ($main_comments as $comment) {
    // Get all replies for this comment (both user replies and admin responses) - same logic as admin panel
    $replies_sql = "SELECT f.*, 
                           a.name as admin_name, a.email as admin_email,
                           CASE WHEN f.user_ip = ? AND f.user_agent = ? AND f.status = 'pending' THEN 1 ELSE 0 END as is_user_pending,
                           CASE WHEN f.comment_type = 'admin_response' OR f.admin_id IS NOT NULL THEN 1 ELSE 0 END as is_admin_comment
                    FROM feedback f
                    LEFT JOIN admins a ON f.admin_id = a.id
                    WHERE f.parent_comment_id = ?
                    AND (
                        f.status IN ('approved', 'reviewed', 'responded')
                        OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?)
                        OR f.comment_type = 'admin_response'
                    )
                    ORDER BY f.created_at ASC
                    LIMIT ?";

    $replies = pdo_select($pdo, $replies_sql, [$user_ip, $user_agent, $comment['id'], $user_ip, $user_agent, $replies_limit], 'feedback', true);

    // Get total count of replies for this comment (including all admin responses)
    $replies_count_sql = "SELECT COUNT(*) 
                          FROM feedback f
                          WHERE f.parent_comment_id = ?
                          AND (
                              f.status IN ('approved', 'reviewed', 'responded')
                              OR (f.status = 'pending' AND f.user_ip = ? AND f.user_agent = ?)
                              OR f.comment_type = 'admin_response'
                          )";
    $replies_count_stmt = $pdo->prepare($replies_count_sql);
    $replies_count_stmt->execute([$comment['id'], $user_ip, $user_agent]);
    $total_replies_count = $replies_count_stmt->fetchColumn();

    // Add to project comments array
    $comment['replies'] = $replies ?: [];
    $comment['total_replies'] = $total_replies_count;
    $comment['shown_replies'] = count($replies);

    $project_comments[] = $comment;
}

// Count approved comments for display
$approved_comments_count = $total_comments_count;

// Related projects
$related_projects = pdo_select($pdo, "SELECT p.*, d.name as department_name, sc.name as sub_county_name, 
                                      w.name as ward_name
                               FROM projects p 
                               LEFT JOIN departments d ON p.department_id = d.id
                               LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id  
                               LEFT JOIN wards w ON p.ward_id = w.id
                               WHERE p.status = 'ongoing' AND p.id != ?
                               ORDER BY p.created_at DESC 
                               LIMIT 3", [$project_id], 'projects', true);

// Steps progress
$total_steps_count = count($project_steps);
$completed_steps_count = count(array_filter($project_steps, fn($s) => $s['status'] === 'completed'));

// Financial overview
$financial_data = pdo_select_one($pdo, "
    SELECT 
        SUM(CASE WHEN transaction_type = 'budget_increase' AND transaction_status = 'active' THEN amount ELSE 0 END) as budget_increases,
        SUM(CASE WHEN transaction_type = 'disbursement' AND transaction_status = 'active' THEN amount ELSE 0 END) as total_disbursed,
        SUM(CASE WHEN transaction_type = 'expenditure' AND transaction_status = 'active' THEN amount ELSE 0 END) as total_spent,
        COUNT(CASE WHEN transaction_status = 'active' THEN 1 END) as transaction_count
    FROM project_transactions 
    WHERE project_id = ?
", [$project_id], 'project_transactions');

$initial_budget = $project['total_budget'] ?? 0;
$budget_increases = $financial_data['budget_increases'] ?? 0;
$total_allocated = $initial_budget + $budget_increases;
$total_disbursed = $financial_data['total_disbursed'] ?? 0;
$total_spent = $financial_data['total_spent'] ?? 0;
$remaining_balance = $total_disbursed - $total_spent;
$project_total_budget = $total_allocated;

// Transactions list
$recent_transactions = pdo_select($pdo, "
    SELECT pt.*, ptd.id as document_id, ptd.file_path, ptd.original_filename 
    FROM project_transactions pt
    LEFT JOIN project_transaction_documents ptd ON pt.id = ptd.transaction_id
    WHERE pt.project_id = ? AND pt.transaction_status = 'active'
    ORDER BY pt.transaction_date DESC, pt.created_at DESC 
    LIMIT 10
", [$project_id], 'project_transactions', true);

// Documents
$project_documents = pdo_select($pdo, "
    SELECT * FROM project_documents 
    WHERE project_id = ? AND document_type IN ('tender', 'contract', 'budget', 'report')
    ORDER BY created_at DESC
", [$project_id], 'project_documents', true);

// Format "time ago"
function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . ' minutes ago';
    if ($time < 86400) return floor($time / 3600) . ' hours ago';
    if ($time < 2592000) return floor($time / 86400) . ' days ago';
    if ($time < 31536000) return floor($time / 2592000) . ' months ago';
    return floor($time / 31536000) . ' years ago';
}

$page_title = htmlspecialchars($project['project_name']);
$page_description = 'View details, progress and location information for ' . htmlspecialchars($project['project_name']);
$show_nav = true;

include 'includes/header.php';
?>
<style>
.admin-response {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-left: 3px solid #0ea5e9;
}

.admin-reply-text {
    background: rgba(59, 130, 246, 0.05);
    padding: 8px 12px;
    border-radius: 6px;
    border-left: 2px solid #3b82f6;
}

.reply-avatar.admin {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
}

.comment-badge.admin {
    background: #1e40af;
    color: white;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 8px;
}
</style>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

<!-- Animated Background -->
<div class="animated-bg"></div>

<!-- Compact Header Section -->
<div class="bg-gradient-to-r from-slate-600 to-slate-700 text-white py-4">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Breadcrumb Navigation -->
        <nav class="flex mb-3" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="<?php echo BASE_URL; ?>index.php" class="text-white/70 hover:text-white transition-colors flex items-center">
                        <i class="fas fa-home mr-1"></i>
                        Home
                    </a>
                </li>
                <li class="flex items-center">
                    <i class="fas fa-chevron-right text-white/50 mx-2"></i>
                    <span class="text-white/90">Project Details</span>
                </li>
            </ol>
        </nav>

        <!-- Project Title and Status -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white leading-tight">
                    <?php echo htmlspecialchars($project['project_name']); ?>
                </h1>
                <div class="flex flex-wrap items-center gap-3 mt-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold text-white
                        <?php echo $project['status'] === 'ongoing' ? 'bg-blue-600' : 
                                  ($project['status'] === 'completed' ? 'bg-green-600' : 
                                  ($project['status'] === 'planning' ? 'bg-yellow-600' : 
                                  ($project['status'] === 'suspended' ? 'bg-orange-600' : 'bg-red-600'))); ?>">
                        <?php echo ucfirst($project['status']); ?>
                    </span>
                    <span class="text-white/80 text-sm">
                        <i class="fas fa-calendar mr-1"></i>
                        <?php echo $project['project_year']; ?>
                    </span>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex gap-2">
                <?php
                // Get subscription count for this project
                require_once 'includes/projectSubscriptions.php';
                $subscription_manager = new ProjectSubscriptionManager($pdo);
                $subscriber_count = $subscription_manager->getSubscriberCount($project_id);
                ?>
                <button onclick="showSubscriptionModal()" class="bg-green-600 hover:bg-green-700 text-white border border-green-600 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-300">
                    <i class="fas fa-bell mr-2"></i>
                    Subscribe to Updates
                </button>
                <button onclick="scrollToComments()" class="bg-white/20 hover:bg-white/30 text-white border border-white/30 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-300">
                    <i class="fas fa-comments mr-2"></i>
                    Join Discussion
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Container with Jumia-style layout -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 relative z-10">
    <div class="flex flex-col lg:flex-row gap-6">

        <!-- Left Sidebar -->
        <div class="lg:w-80 flex-shrink-0 space-y-6">

            <!-- Project Overview Cards -->
            <div class="glass-card p-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-chart-pie mr-2 text-blue-500"></i>
                    Project Overview
                </h3>

                <!-- Progress Card -->
                <div class="mb-4 text-center cursor-pointer" onclick="showProgressBreakdown()">
                    <?php 
                        $progress = $project['progress_percentage'];
                        $progressColor = $progress >= 75 ? '#10b981' : ($progress >= 50 ? '#3b82f6' : ($progress >= 25 ? '#f59e0b' : '#ef4444'));
                    ?>
                    <div style="position: relative; width: 120px; height: 120px; border-radius: 50%;
                                background: conic-gradient(
                                    from 180deg,
                                    <?php echo $progressColor; ?> 0deg,
                                    <?php echo $progressColor; ?> <?php echo ($progress * 3.6); ?>deg,
                                    rgba(229,231,235,0.3) <?php echo ($progress * 3.6); ?>deg,
                                    rgba(229,231,235,0.3) 360deg
                                );
                                display: flex; justify-content: center; align-items: center; margin: 0 auto;">
                        <div class="inner-circle" 
                            style="position: absolute; width: 85px; height: 85px; 
                                    background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(248,250,252,0.9));
                                    border-radius: 50%; display: flex; align-items: center; justify-content: center;
                                    box-shadow: inset 0 2px 8px rgba(0,0,0,0.1);">
                            <span style="font-weight: bold; font-size: 1.2rem;"><?php echo $progress; ?>%</span>
                        </div>
                    </div>
                </div>


                <!-- Quick Stats Grid -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-lg p-3 text-center cursor-pointer hover:bg-gray-100 transition-colors" onclick="showStepsBreakdown()">
                        <div class="text-lg font-bold text-gray-900"><?php echo $completed_steps_count; ?>/<?php echo $total_steps_count; ?></div>
                        <div class="text-xs text-gray-600">Steps Complete</div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-3 text-center cursor-pointer hover:bg-gray-100 transition-colors" onclick="showDepartmentInfo()">
                        <div class="text-xs text-gray-600 mb-1">
                            <i class="fas fa-building text-blue-500"></i>
                        </div>
                        <div class="text-xs text-gray-900 font-medium truncate">
                            <?php echo htmlspecialchars($project['department_name']); ?>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-3 text-center cursor-pointer hover:bg-gray-100 transition-colors" onclick="showYearInfo()">
                        <div class="text-xs text-gray-600 mb-1">
                            <i class="fas fa-calendar text-gray-500"></i>
                        </div>
                        <div class="text-xs text-gray-900 font-medium">
                            <?php echo $project['project_year']; ?>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-3 text-center cursor-pointer hover:bg-gray-100 transition-colors" onclick="showBudgetBreakdown()">
                        <div class="text-xs text-gray-600 mb-1">
                            <i class="fas fa-money-bill text-green-500"></i>
                        </div>
                        <div class="text-xs text-gray-900 font-medium">
                            KES <?php echo number_format($project_total_budget / 1000000, 1); ?>M
                        </div>
                    </div>
                </div>
            </div>

            <!-- Project Location Card -->
            <?php if (!empty($project['location_coordinates'])): ?>
            <?php
                // Extract coordinates and create Google Maps link
                $coordinates = $project['location_coordinates'];
                $google_maps_link = "https://www.google.com/maps/search/?api=1&query=" . urlencode($coordinates);
            ?>
            <div class="glass-card p-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-map-marker-alt mr-2 text-red-500"></i>
                    Project Location
                </h3>
                <div id="projectMap" class="w-full h-40 rounded-lg border border-gray-200 mb-3"></div>
                <div class="text-center">
                    <p class="text-sm text-gray-600 mb-1">
                        <i class="fas fa-map-marker-alt mr-1 text-red-500"></i>
                        <?php echo htmlspecialchars($project['ward_name'] . ', ' . $project['sub_county_name']); ?>
                    </p>
                    <p class="text-xs text-gray-500">
                        <?php echo htmlspecialchars($project['county_name']); ?>
                    </p>
                    <a href="<?php echo $google_maps_link; ?>" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="inline-block mt-2 text-sm text-blue-600 hover:text-blue-800 font-medium">
                        <i class="fas fa-external-link-alt mr-1"></i> View on Google Maps
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Related Projects Card -->
            <?php if (!empty($related_projects)): ?>
            <div class="glass-card p-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-project-diagram mr-2 text-purple-500"></i>
                    Related Projects
                </h3>
                <div class="space-y-3">
                    <?php foreach ($related_projects as $related_project): ?>
                    <?php 
                    $related_progress = $related_project['progress_percentage'] ?? 0;
                    $related_progress_color = $related_progress >= 75 ? '#10b981' : ($related_progress >= 50 ? '#3b82f6' : ($related_progress >= 25 ? '#f59e0b' : '#ef4444'));
                    ?>
                    <div class="bg-white rounded-lg p-3 border border-gray-200 cursor-pointer hover:shadow-sm transition-shadow"
                         onclick="window.location.href='<?php echo generate_project_url($related_project['id'], $related_project['project_name']); ?>'">
                        <h4 class="font-medium text-gray-900 text-sm line-clamp-2 mb-2">
                            <?php echo htmlspecialchars($related_project['project_name']); ?>
                        </h4>
                        <p class="text-xs text-gray-600 mb-2 flex items-center">
                            <i class="fas fa-map-marker-alt mr-1 text-red-400"></i>
                            <?php echo htmlspecialchars($related_project['ward_name']); ?>
                        </p>
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo get_status_badge_class($related_project['status']); ?>">
                                <?php echo ucfirst($related_project['status']); ?>
                            </span>
                            <div class="flex items-center gap-2">
                                <div class="relative">
                                    <svg width="24" height="24" class="transform rotate(-90)">
                                        <circle cx="12" cy="12" r="10" fill="none" stroke="rgba(229,231,235,0.4)" stroke-width="2"></circle>
                                        <circle cx="12" cy="12" r="10" fill="none" stroke="<?php echo $related_progress_color; ?>" stroke-width="2" 
                                                stroke-linecap="round" 
                                                stroke-dasharray="<?php echo 2 * 3.14159 * 10; ?>" 
                                                stroke-dashoffset="<?php echo (2 * 3.14159 * 10) - (($related_progress / 100) * (2 * 3.14159 * 10)); ?>"
                                                style="transition: stroke-dashoffset 1s ease-in-out;"></circle>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span style="font-size: 8px; font-weight: bold; color: #374151;">
                                            <?php echo $related_progress; ?>%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 text-center">
                    <a href="<?php echo BASE_URL; ?>index.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        View All Projects →
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 space-y-6">

            <!-- Project Details Section -->
            <div class="glass-card p-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-info-circle mr-3 text-blue-500"></i>
                    Project Details
                </h2>

                <!-- Project Description -->
                <div class="mb-6">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-xl border-l-4 border-blue-500">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                            <i class="fas fa-file-text mr-2 text-blue-500"></i>
                            Description
                        </h3>
                        <p class="text-gray-700 leading-relaxed">
                            <?php echo htmlspecialchars($project['description']); ?>
                        </p>
                    </div>
                </div>

                <!-- Project Information Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Location -->
                    <div class="bg-gradient-to-br from-red-50 to-pink-50 p-4 rounded-xl border border-red-100">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center">
                                <i class="fas fa-map-marker-alt text-white text-sm"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900">Location</h4>
                                <p class="text-xs text-gray-600">Project Area</p>
                            </div>
                        </div>
                        <p class="text-gray-800 font-medium">
                            <?php echo htmlspecialchars($project['ward_name']); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <?php echo htmlspecialchars($project['sub_county_name'] . ', ' . $project['county_name']); ?>
                        </p>
                        <?php if (!empty($project['location_coordinates'])): ?>
                        <div class="mt-2">
                            <a href="<?php echo $google_maps_link; ?>" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800">
                                <i class="fas fa-map-marked-alt mr-1"></i> View on Map
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Department -->
                    <div class="bg-gradient-to-br from-purple-50 to-indigo-50 p-4 rounded-xl border border-purple-100">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center">
                                <i class="fas fa-building text-white text-sm"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900">Department</h4>
                                <p class="text-xs text-gray-600">Implementing Authority</p>
                            </div>
                        </div>
                        <p class="text-gray-800 font-medium">
                            <?php echo htmlspecialchars($project['department_name']); ?>
                        </p>
                    </div>

                    <?php if ($project['contractor_name']): ?>
                    <!-- Contractor -->
                    <div class="bg-gradient-to-br from-yellow-50 to-orange-50 p-4 rounded-xl border border-yellow-100">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-yellow-500 to-orange-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-hard-hat text-white text-sm"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900">Contractor</h4>
                                <p class="text-xs text-gray-600">Implementation Partner</p>
                            </div>
                        </div>
                        <p class="text-gray-800 font-medium">
                            <?php echo htmlspecialchars($project['contractor_name']); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if ($project['start_date']): ?>
                    <!-- Start Date -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 p-4 rounded-xl border border-green-100">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-play-circle text-white text-sm"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900">Start Date</h4>
                                <p class="text-xs text-gray-600">Project Commencement</p>
                            </div>
                        </div>
                        <p class="text-gray-800 font-medium">
                            <?php echo format_date($project['start_date']); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Transparency Section -->
            <?php if ($project_total_budget > 0 || $total_allocated > 0 || !empty($recent_transactions)): ?>
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-6 cursor-pointer" onclick="toggleFinancialSection()">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-money-bill-wave mr-3 text-green-500"></i>
                        Financial Transparency
                    </h2>
                    <i id="financialToggle" class="fas fa-chevron-down text-gray-500 transition-transform"></i>
                </div>

                <div id="financialContent" class="space-y-6">
                    <!-- Financial Summary Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Approved Budget Card -->
                        <?php 
                        $budget_percentage = 100; // Always 100% since this represents the full approved budget
                        $budget_circumference = 2 * 3.14159 * 16;
                        $budget_offset = $budget_circumference - ($budget_percentage / 100) * $budget_circumference;
                        ?>
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-100 hover:shadow-lg transition-all duration-300">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="relative">
                                    <svg width="40" height="40" class="transform rotate(-90)">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="rgba(59, 130, 246, 0.2)" stroke-width="3"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="#3b82f6" stroke-width="3" 
                                                stroke-linecap="round" stroke-dasharray="<?php echo $budget_circumference; ?>" 
                                                stroke-dashoffset="<?php echo $budget_offset; ?>"
                                                style="transition: stroke-dashoffset 2s ease-in-out;"
                                                class="financial-progress-circle"></circle>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <i class="fas fa-coins text-blue-600 text-sm"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 text-sm">Approved Budget</h4>
                                    <p class="text-xs text-gray-600">Total Project Budget</p>
                                </div>
                            </div>
                            <p class="text-lg font-bold text-blue-600">KES <?php echo number_format($project_total_budget); ?></p>
                        </div>

                        <!-- Disbursed Amount Card -->
                        <?php 
                        $disbursement_percentage = $project_total_budget > 0 ? ($total_disbursed / $project_total_budget) * 100 : 0;
                        $disbursement_circumference = 2 * 3.14159 * 16;
                        $disbursement_offset = $disbursement_circumference - ($disbursement_percentage / 100) * $disbursement_circumference;
                        ?>
                        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 p-4 rounded-xl border border-purple-100 hover:shadow-lg transition-all duration-300">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="relative">
                                    <svg width="40" height="40" class="transform rotate(-90)">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="rgba(168, 85, 247, 0.2)" stroke-width="3"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="#a855f7" stroke-width="3" 
                                                stroke-linecap="round" stroke-dasharray="<?php echo $disbursement_circumference; ?>" 
                                                stroke-dashoffset="<?php echo $disbursement_offset; ?>"
                                                style="transition: stroke-dashoffset 2s ease-in-out;"
                                                class="financial-progress-circle"></circle>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <i class="fas fa-money-check-alt text-purple-600 text-sm"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 text-sm">Disbursed</h4>
                                    <p class="text-xs text-gray-600"><?php echo round($disbursement_percentage, 1); ?>% of Budget</p>
                                </div>
                            </div>
                            <p class="text-lg font-bold text-purple-600">KES <?php echo number_format($total_disbursed); ?></p>
                        </div>

                        <!-- Expenditure Card -->
                        <?php 
                        $expenditure_percentage = $project_total_budget > 0 ? ($total_spent / $project_total_budget) * 100 : 0;
                        $expenditure_circumference = 2 * 3.14159 * 16;
                        $expenditure_offset = $expenditure_circumference - ($expenditure_percentage / 100) * $expenditure_circumference;
                        ?>
                        <div class="bg-gradient-to-br from-red-50 to-pink-50 p-4 rounded-xl border border-red-100 hover:shadow-lg transition-all duration-300">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="relative">
                                    <svg width="40" height="40" class="transform rotate(-90)">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="rgba(239, 68, 68, 0.2)" stroke-width="3"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="#ef4444" stroke-width="3" 
                                                stroke-linecap="round" stroke-dasharray="<?php echo $expenditure_circumference; ?>" 
                                                stroke-dashoffset="<?php echo $expenditure_offset; ?>"
                                                style="transition: stroke-dashoffset 2s ease-in-out;"
                                                class="financial-progress-circle"></circle>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <i class="fas fa-credit-card text-red-600 text-sm"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 text-sm">Spent Amount</h4>
                                    <p class="text-xs text-gray-600"><?php echo round($expenditure_percentage, 1); ?>% of Budget</p>
                                </div>
                            </div>
                            <p class="text-lg font-bold text-red-600">KES <?php echo number_format($total_spent); ?></p>
                        </div>

                        <!-- Remaining Balance Card -->
                        <?php 
                        $remaining_percentage = $total_disbursed > 0 ? ($remaining_balance / $total_disbursed) * 100 : 0;
                        if ($remaining_percentage < 0) $remaining_percentage = 0;
                        $remaining_circumference = 2 * 3.14159 * 16;
                        $remaining_offset = $remaining_circumference - ($remaining_percentage / 100) * $remaining_circumference;
                        ?>
                        <div class="bg-gradient-to-br from-green-50 to-emerald-50 p-4 rounded-xl border border-green-100 hover:shadow-lg transition-all duration-300">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="relative">
                                    <svg width="40" height="40" class="transform rotate(-90)">
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="rgba(16, 185, 129, 0.2)" stroke-width="3"></circle>
                                        <circle cx="20" cy="20" r="16" fill="none" stroke="<?php echo $remaining_balance >= 0 ? '#10b981' : '#ef4444'; ?>" stroke-width="3" 
                                                stroke-linecap="round" stroke-dasharray="<?php echo $remaining_circumference; ?>" 
                                                stroke-dashoffset="<?php echo $remaining_offset; ?>"
                                                style="transition: stroke-dashoffset 2s ease-in-out;"
                                                class="financial-progress-circle"></circle>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <i class="fas fa-wallet text-<?php echo $remaining_balance >= 0 ? 'green' : 'red'; ?>-600 text-sm"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 text-sm">Remaining Balance</h4>
                                    <p class="text-xs text-gray-600"><?php echo round($remaining_percentage, 1); ?>% of Disbursed</p>
                                </div>
                            </div>
                            <p class="text-lg font-bold <?php echo $remaining_balance >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                KES <?php echo number_format($remaining_balance); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Collapsible Transaction Activities -->
                    <?php if (!empty($recent_transactions)): ?>
                    <div class="border border-gray-200 rounded-lg">
                        <div class="bg-gray-50 px-4 py-3 cursor-pointer flex items-center justify-between" onclick="toggleTransactions()">
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                                <i class="fas fa-receipt mr-2 text-blue-500"></i>
                                Recent Financial Activities
                            </h3>
                            <i id="transactionToggle" class="fas fa-chevron-down text-gray-500 transition-transform"></i>
                        </div>
                        <div id="transactionContent" class="hidden p-4 space-y-3">
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <?php
                                // Determine if transaction adds or subtracts from project account
                                $is_positive = in_array($transaction['transaction_type'], ['budget_increase', 'disbursement']);
                                $is_negative = in_array($transaction['transaction_type'], ['expenditure']);

                                // Set colors and icons based on transaction type
                                if ($is_positive) {
                                    $bg_color = 'bg-green-500';
                                    $text_color = 'text-green-600';
                                    $icon = 'fa-plus';
                                    $sign = '+';
                                } else {
                                    $bg_color = 'bg-red-500';
                                    $text_color = 'text-red-600';
                                    $icon = 'fa-minus';
                                    $sign = '-';
                                }

                                // Transaction type labels for public display
                                $type_labels = [
                                    'budget_increase' => 'Additional Budget',
                                    'disbursement' => 'Funds Disbursed', 
                                    'expenditure' => 'Project Expenditure',
                                    'adjustment' => 'Budget Adjustment'
                                ];
                                ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 <?php echo $bg_color; ?> rounded-full flex items-center justify-center">
                                            <i class="fas <?php echo $icon; ?> text-white text-xs"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-medium text-sm text-gray-900"><?php echo htmlspecialchars($transaction['description']); ?></h4>
                                            <p class="text-xs text-gray-600">
                                                <?php echo $type_labels[$transaction['transaction_type']] ?? ucfirst($transaction['transaction_type']); ?> • 
                                                <?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?>
                                            </p>
                                            <?php if ($transaction['document_id']): ?>
                                            <div class="mt-1">
                                                <button onclick="openDocumentModal('<?php echo htmlspecialchars($transaction['original_filename']); ?>', '<?php echo BASE_URL . 'uploads/' . $transaction['file_path']; ?>')" 
                                                        class="text-xs text-blue-600 hover:text-blue-800 flex items-center">
                                                    <i class="fas fa-file-invoice mr-1"></i>
                                                    View Supporting Document
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-sm <?php echo $text_color; ?>">
                                            <?php echo $sign; ?>KES <?php echo number_format($transaction['amount']); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Project Timeline -->
            <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
                <div class="px-4 py-3 border-b border-gray-200 bg-slate-50 cursor-pointer flex items-center justify-between" onclick="toggleTimelineSection()">
                    <h2 class="text-sm font-semibold text-slate-800 flex items-center">
                        <i class="fas fa-list-ol mr-2 text-slate-600"></i>
                        Project Implementation Status
                    </h2>
                    <i id="timelineToggle" class="fas fa-chevron-down text-slate-500 transition-transform"></i>
                </div>

                <div id="timelineContent" class="p-4">
                    <?php if (!empty($project_steps)): ?>
                    <?php 
                    // Filter steps to only show completed and in_progress
                    $visible_steps = array_filter($project_steps, function($step) {
                        return in_array($step['status'], ['completed', 'in_progress']);
                    });

                    // Re-index the array and add display numbering
                    $display_steps = array_values($visible_steps);
                    ?>

                    <?php if (!empty($display_steps)): ?>
                    <!-- Desktop Layout - Vertical List -->
                    <div class="hidden md:block space-y-3">
                        <?php foreach ($display_steps as $index => $step): ?>
                        <div class="border border-gray-200 rounded-lg">
                            <div class="flex items-center p-3 cursor-pointer hover:bg-gray-50 transition-colors" onclick="toggleStepDetails(<?php echo $index; ?>)">
                                <!-- Step Circle -->
                                <div class="relative mr-4 flex-shrink-0">
                                    <?php if ($step['status'] === 'completed'): ?>
                                        <div class="w-10 h-10 bg-green-600 border-2 border-green-700 rounded-full flex items-center justify-center">
                                            <i class="fas fa-check text-white text-sm"></i>
                                        </div>
                                    <?php else: /* in_progress */ ?>
                                        <div class="w-10 h-10 bg-blue-600 border-2 border-blue-700 rounded-full flex items-center justify-center relative">
                                            <div class="w-4 h-4 bg-white rounded-full"></div>
                                            <div class="absolute inset-0 w-10 h-10 bg-blue-400 rounded-full animate-ping opacity-30"></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Step Number Badge -->
                                    <div class="absolute -top-1 -right-1 w-5 h-5 bg-slate-700 text-white text-xs rounded-full flex items-center justify-center font-bold">
                                        <?php echo ($index + 1); ?>
                                    </div>
                                </div>

                                <!-- Step Header -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="text-sm font-semibold text-slate-800">
                                                <?php echo htmlspecialchars($step['step_name']); ?>
                                            </h4>
                                            <div class="flex items-center gap-3 mt-1">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $step['status'] === 'completed' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>">
                                                    <?php echo $step['status'] === 'completed' ? 'Completed' : 'In Progress'; ?>
                                                </span>
                                                <?php if ($step['actual_end_date']): ?>
                                                    <span class="text-xs text-slate-500">
                                                        <i class="fas fa-calendar-check mr-1"></i>
                                                        <?php echo date('M j, Y', strtotime($step['actual_end_date'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <i id="stepToggle<?php echo $index; ?>" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step Details (Collapsible) -->
                            <div id="stepDetails<?php echo $index; ?>" class="hidden border-t border-gray-200 p-4 bg-gray-50">
                                <?php if ($step['description']): ?>
                                    <div class="mb-4">
                                        <h5 class="text-sm font-medium text-slate-700 mb-2">Description</h5>
                                        <p class="text-sm text-slate-600 leading-relaxed">
                                            <?php echo nl2br(htmlspecialchars($step['description'])); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                    <?php if ($step['start_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Start Date:</span>
                                            <div class="text-slate-600"><?php echo date('M j, Y', strtotime($step['start_date'])); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($step['expected_end_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Expected Completion:</span>
                                            <div class="text-slate-600"><?php echo date('M j, Y', strtotime($step['expected_end_date'])); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($step['actual_end_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Completed On:</span>
                                            <div class="text-green-600 font-medium"><?php echo date('M j, Y', strtotime($step['actual_end_date'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Mobile Layout -->
                    <div class="md:hidden space-y-3">
                        <?php foreach ($display_steps as $index => $step): ?>
                        <div class="border border-gray-200 rounded-lg">
                            <div class="flex items-center p-3 cursor-pointer hover:bg-gray-50 transition-colors" onclick="toggleStepDetailsMobile(<?php echo $index; ?>)">
                                <!-- Step Circle -->
                                <div class="relative mr-3 flex-shrink-0">
                                    <?php if ($step['status'] === 'completed'): ?>
                                        <div class="w-8 h-8 bg-green-600 border border-green-700 rounded-full flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                    <?php else: /* in_progress */ ?>
                                        <div class="w-8 h-8 bg-blue-600 border border-blue-700 rounded-full flex items-center justify-center relative">
                                            <div class="w-3 h-3 bg-white rounded-full"></div>
                                            <div class="absolute inset-0 w-8 h-8 bg-blue-400 rounded-full animate-ping opacity-30"></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Step Number -->
                                    <div class="absolute -top-1 -right-1 w-4 h-4 bg-slate-700 text-white text-xs rounded-full flex items-center justify-center font-bold">
                                        <?php echo ($index + 1); ?>
                                    </div>
                                </div>

                                <!-- Step Details -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <div class="text-sm font-medium text-slate-800">
                                                <?php echo htmlspecialchars($step['step_name']); ?>
                                            </div>
                                            <div class="text-xs text-slate-500 mt-0.5">
                                                Status: <?php echo $step['status'] === 'completed' ? 'Completed' : 'In Progress'; ?>
                                            </div>
                                        </div>
                                        <i id="stepToggleMobile<?php echo $index; ?>" class="fas fa-chevron-down text-slate-400 transition-transform"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Mobile Step Details -->
                            <div id="stepDetailsMobile<?php echo $index; ?>" class="hidden border-t border-gray-200 p-3 bg-gray-50">
                                <?php if ($step['description']): ?>
                                    <div class="mb-3">
                                        <h5 class="text-xs font-medium text-slate-700 mb-1">Description</h5>
                                        <p class="text-xs text-slate-600 leading-relaxed">
                                            <?php echo nl2br(htmlspecialchars($step['description'])); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <div class="space-y-2 text-xs">
                                    <?php if ($step['start_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Start:</span>
                                            <span class="text-slate-600"><?php echo date('M j, Y', strtotime($step['start_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($step['expected_end_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Expected:</span>
                                            <span class="text-slate-600"><?php echo date('M j, Y', strtotime($step['expected_end_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($step['actual_end_date']): ?>
                                        <div>
                                            <span class="font-medium text-slate-700">Completed:</span>
                                            <span class="text-green-600 font-medium"><?php echo date('M j, Y', strtotime($step['actual_end_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php else: ?>
                    <div class="text-center py-6">
                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-clock text-lg text-slate-400"></i>
                        </div>
                        <p class="text-sm text-slate-600 font-medium">No Active Implementation Steps</p>
                        <p class="text-xs text-slate-500 mt-1">Steps will appear here once project implementation begins</p>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="text-center py-6">
                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-list-ol text-lg text-slate-400"></i>
                        </div>
                        <p class="text-sm text-slate-600 font-medium">Timeline Not Available</p>
                        <p class="text-xs text-slate-500 mt-1">Project timeline will be published when available</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Community Comments Section -->
<div id="comments-section" class="glass-card p-6">
    <h3 class="text-xl font-semibold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-comments mr-3 text-blue-500"></i>
        Community Discussion
        <span class="ml-auto text-sm font-normal text-gray-600">
            <?php echo $approved_comments_count; ?> <?php echo $approved_comments_count === 1 ? 'comment' : 'comments'; ?>
        </span>
    </h3>

    <!-- Notification Messages Area -->
    <div id="commentResponseMessage" class="hidden mb-6 p-4 rounded-lg border-l-4">
        <!-- Dynamic content will be added here -->
    </div>

<!-- Display Comments -->
    <div id="comments-container" class="space-y-3 mb-6">
        <?php if (!empty($project_comments)): ?>
            <?php foreach ($project_comments as $comment): ?>
                <?php
                    // Decrypt comment data with proper table context
                    $comment = EncryptionManager::processDataForReading('feedback', $comment);

                    // Determine if this is an admin comment and get proper display name
                    $is_admin_comment = ($comment['comment_type'] === 'admin_response') || !empty($comment['admin_id']);

                    if ($is_admin_comment && !empty($comment['admin_name'])) {
                        // Decrypt admin name using admins table context
                        $admin_data = ['name' => $comment['admin_name']];
                        $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
                        $display_name = $admin_data['name'];
                    } else {
                        // Use citizen name for user comments
                        $display_name = $comment['citizen_name'];
                    }

                    $is_user_pending = isset($comment['is_user_pending']) ? $comment['is_user_pending'] : false;
                    ?>
                <div class="comment-thread" data-comment-id="<?php echo $comment['id']; ?>">
                    <div class="comment-main">
                        <div class="flex items-start">
                            <div class="comment-avatar <?php echo $is_admin_comment ? 'admin' : 'user'; ?>">
                                <?php if ($is_admin_comment): ?>
                                    <i class="fas fa-shield-alt"></i>
                                <?php else: ?>
                                    <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="comment-content">
                                <div class="flex items-center">
                                    <span class="comment-author">
                                        <?php echo htmlspecialchars($display_name); ?>
                                    </span>
                                    <?php if ($is_admin_comment): ?>
                                        <span class="comment-badge admin">
                                            <i class="fas fa-shield-alt" style="font-size: 8px;"></i> Official
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($is_user_pending): ?>
                                        <span class="comment-badge pending">
                                            <i class="fas fa-clock" style="font-size: 8px;"></i> Awaiting Review
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-text">
                                    <?php echo nl2br(htmlspecialchars($comment['message'])); ?>
                                </div>
                                <div class="comment-meta">
                                    <button onclick="replyToComment(<?php echo $comment['id']; ?>, '<?php echo addslashes($display_name); ?>')" 
                                            class="text-blue-600 hover:text-blue-800 font-medium">
                                        <i class="fas fa-reply" style="font-size: 10px;"></i> Reply
                                    </button>
                                    <?php if ($comment['total_replies'] > 0): ?>
                                        <button onclick="toggleReplies(<?php echo $comment['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-800 font-medium" 
                                                id="viewRepliesBtn-<?php echo $comment['id']; ?>">
                                            <i class="fas fa-comment-dots" style="font-size: 10px;"></i>
                                            View <?php echo $comment['total_replies']; ?> <?php echo $comment['total_replies'] === 1 ? 'reply' : 'replies'; ?>
                                        </button>
                                    <?php endif; ?>
                                    <span class="text-gray-500">
                                        <?php echo time_ago($comment['created_at']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Replies Section - Initially Hidden -->
                    <div class="replies-container hidden" id="replies-<?php echo $comment['id']; ?>">
                        <div class="replies-list" id="repliesList-<?php echo $comment['id']; ?>">
                            <?php if (!empty($comment['replies'])): ?>
                                <?php foreach ($comment['replies'] as $reply): ?>
                                    <?php
                                    $reply = EncryptionManager::processDataForReading('feedback', $reply);
                                    $reply_is_admin = ($reply['comment_type'] === 'admin_response') || !empty($reply['admin_id']);

                                    if ($reply_is_admin && !empty($reply['admin_name'])) {
                                        // Decrypt admin name using admins table context
                                        $admin_data = ['name' => $reply['admin_name']];
                                        $admin_data = EncryptionManager::processDataForReading('admins', $admin_data);
                                        $reply_display_name = $admin_data['name'];
                                    } else {
                                        // Use citizen name for user replies
                                        $reply_display_name = $reply['citizen_name'];
                                    }

                                    $reply_is_user_pending = isset($reply['is_user_pending']) ? $reply['is_user_pending'] : false;
                                    ?>
                                    <div class="reply-item <?php echo $reply_is_admin ? 'admin-response' : 'user-reply'; ?>">
                                        <div class="flex items-start">
                                            <div class="reply-avatar <?php echo $reply_is_admin ? 'admin' : 'user'; ?>">
                                                <?php if ($reply_is_admin): ?>
                                                    <i class="fas fa-shield-alt"></i>
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($reply_display_name, 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="reply-content">
                                                <div class="flex items-center">
                                                    <span class="reply-author">
                                                        <?php echo htmlspecialchars($reply_display_name); ?>
                                                    </span>
                                                    <?php if ($reply_is_admin): ?>
                                                        <span class="comment-badge admin">
                                                            <i class="fas fa-shield-alt" style="font-size: 7px;"></i> 
                                                            <?php echo $reply['comment_type'] === 'admin_response' ? 'Official Response' : 'Moderator'; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($reply_is_user_pending): ?>
                                                        <span class="comment-badge pending">
                                                            <i class="fas fa-clock" style="font-size: 7px;"></i> Awaiting Approval
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="reply-text <?php echo $reply_is_admin ? 'admin-reply-text' : ''; ?>">
                                                    <?php echo nl2br(htmlspecialchars($reply['message'])); ?>
                                                </div>
                                                <div class="reply-meta">
                                                    <span class="text-gray-500">
                                                        <?php echo time_ago($reply['created_at']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Load More Replies Button -->
                        <?php if ($comment['total_replies'] > $comment['shown_replies']): ?>
                            <div class="text-center mt-3" id="loadMoreReplies-<?php echo $comment['id']; ?>">
                                <button onclick="loadMoreReplies(<?php echo $comment['id']; ?>, <?php echo $comment['shown_replies']; ?>)" 
                                        class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                    Show <?php echo min(($comment['total_replies'] - $comment['shown_replies']), 10); ?> more replies
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="text-center py-16 text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-comments text-3xl text-gray-400"></i>
                </div>
                <p class="text-lg font-medium mb-2 text-gray-700">No comments yet</p>
                <p class="text-sm text-gray-500">Be the first to share your thoughts about this project!</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Load More Comments Button -->
    <?php if ($total_comments_count > count($project_comments)): ?>
        <div class="text-center mb-6" id="loadMoreCommentsButton">
            <button onclick="loadMoreComments()" 
                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                <i class="fas fa-comments mr-2"></i>
                Load More Comments (<?php echo ($total_comments_count - count($project_comments)); ?> remaining)
            </button>
        </div>
    <?php endif; ?>

    <!-- Add New Comment Form -->
    <div class="border-t border-gray-200 pt-6">
        <!-- Reply Context Banner -->
        <div id="replyingToInfo" class="hidden mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center text-blue-800">
                    <i class="fas fa-reply mr-2"></i>
                    <span class="text-sm">Replying to <strong id="replyingToName"></strong></span>
                </div>
                <button onclick="cancelReply()" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    <i class="fas fa-times mr-1"></i>Cancel
                </button>
            </div>
        </div>

        <h4 id="commentFormTitle" class="text-lg font-semibold text-gray-900 mb-4">Join the Discussion</h4>
        <form id="commentForm" method="POST" class="bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-lg border border-gray-200 shadow-sm">
            <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
            <input type="hidden" id="parentCommentId" name="parent_comment_id" value="0">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Your Name *</label>
                    <input type="text" name="citizen_name" required 
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email (Optional)</label>
                    <input type="email" name="citizen_email" 
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Your Comment *</label>
                <textarea name="message" rows="4" required 
                    placeholder="Share your thoughts about this project..."
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all resize-vertical"></textarea>
            </div>

            <button type="submit" id="submitBtn" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <i class="fas fa-paper-plane mr-2"></i>
                Submit Comment
            </button>
        </form>
    </div>
</div>
<!-- Community Comments Section -->
<!-- Document Modal -->
<div id="documentModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 id="documentModalTitle" class="text-lg leading-6 font-medium text-gray-900 mb-4"></h3>
                        <div class="mt-2">
                            <iframe id="documentViewer" class="w-full h-96 border border-gray-300 rounded-md"></iframe>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="closeDocumentModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Stats Breakdown Modal -->
<div id="statsModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true" onclick="closeStatsModal()">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 id="statsModalTitle" class="text-lg leading-6 font-medium text-gray-900"></h3>
                    <button onclick="closeStatsModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="statsModalContent" class="mt-2">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Subscription Modal -->
<div id="subscriptionModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true" onclick="closeSubscriptionModal()">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
            <div class="bg-gradient-to-r from-green-600 to-blue-600 px-6 py-4 text-white">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-bell text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold">Subscribe to Updates</h3>
                            <p class="text-sm text-green-100">Stay informed about this project</p>
                        </div>
                    </div>
                    <button onclick="closeSubscriptionModal()" class="text-white hover:text-gray-200 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <div class="px-6 py-6">
                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-2">
                        Get notified about important updates for:
                    </p>
                    <p class="font-semibold text-gray-900 bg-gray-50 p-3 rounded-lg">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </p>
                </div>

                <form id="subscriptionForm" class="space-y-4">
                    <div>
                        <label for="subscriptionEmail" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope mr-1 text-blue-500"></i>
                            Email Address *
                        </label>
                        <input type="email" 
                               id="subscriptionEmail" 
                               name="email" 
                               required 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                               placeholder="Enter your email address">
                        <p id="emailError" class="text-red-500 text-xs mt-1 hidden"></p>
                    </div>

                    <div class="text-xs text-gray-500 text-center">
                        By subscribing, you agree to receive email notifications about this project only.
                        We respect your privacy and follow data protection regulations.
                    </div>
                </form>
            </div>

            <div class="bg-gray-50 px-6 py-4 flex flex-col sm:flex-row-reverse gap-3">
                <button type="button" 
                        id="subscribeBtn"
                        onclick="submitSubscription()" 
                        class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg hover:from-green-700 hover:to-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all font-medium">
                    <i class="fas fa-bell mr-2"></i>
                    Subscribe Now
                </button>
                <button type="button" 
                        onclick="closeSubscriptionModal()" 
                        class="flex-1 sm:flex-none px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all font-medium">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scroll to Top Button -->
<button id="scrollToTop" class="fixed bottom-6 right-6 bg-blue-600 text-white p-3 rounded-full shadow-lg hover:bg-blue-700 transition-all z-50 opacity-0 invisible transform translate-y-4">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Scroll Progress Indicator -->
<div class="scroll-indicator"></div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
                                                /* Progress Circle Styles */
                                                .progress-ring {
                                                    transition: all 0.3s ease;
                                                    position: relative;
                                                }

                                                .progress-circle-container:hover .progress-ring {
                                                    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
                                                    transform: scale(1.02);
                                                }

                                                .progress-ring::before {
                                                    content: '';
                                                    position: absolute;
                                                    top: 3px;
                                                    left: 3px;
                                                    right: 3px;
                                                    bottom: 3px;
                                                    border-radius: 50%;
                                                    background: rgba(255,255,255,0.1);
                                                    pointer-events: none;
                                                }

                                                .financial-progress-circle {
                                                    animation: progressLoad 2s ease-in-out;
                                                }

                                                @keyframes progressLoad {
                                                    0% {
                                                        stroke-dashoffset: 100.53;
                                                    }
                                                }

                                                /* Pulse animation for financial cards */
                                                .financial-progress-circle:hover {
                                                    animation: progressPulse 1.5s ease-in-out infinite;
                                                }

                                                @keyframes progressPulse {
                                                    0%, 100% {
                                                        opacity: 1;
                                                    }
                                                    50% {
                                                        opacity: 0.7;
                                                    }
                                                }

                                                /* Notification styles */
                                                .notification {
                                                    animation: slideInRight 0.3s ease, slideOutRight 0.3s ease 2.7s forwards;
                                                }

                                                @keyframes slideInRight {
                                                    from { transform: translateX(100%); opacity: 0; }
                                                    to { transform: translateX(0); opacity: 1; }
                                                }

                                                @keyframes slideOutRight {
                                                    from { transform: translateX(0); opacity: 1; }
                                                    to { transform: translateX(100%); opacity: 0; }
                                                }
</style>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?php echo BASE_URL; ?>assets/js/main.js"></script>

<script>
// Define BASE_URL and project data from PHP
window.BASE_URL = "<?php echo BASE_URL; ?>";
window.projectData = {
    <?php if (!empty($project['location_coordinates'])): ?>
    coordinates: "<?php echo $project['location_coordinates']; ?>",
    name: "<?php echo addslashes($project['project_name']); ?>",
    ward: "<?php echo addslashes($project['ward_name']); ?>",
    sub_county: "<?php echo addslashes($project['sub_county_name']); ?>",
    status: "<?php echo ucfirst($project['status']); ?>",
    department: "<?php echo addslashes($project['department_name']); ?>",
    year: "<?php echo $project['project_year']; ?>",
    progress: <?php echo $progress; ?>
    <?php else: ?>
    coordinates: null
    <?php endif; ?>
};

// Global scroll to comments function
window.scrollToComments = function() {
    const commentsSection = document.getElementById('comments-section');
    if (commentsSection) {
        const headerHeight = 100;
        const elementPosition = commentsSection.offsetTop;
        const offsetPosition = elementPosition - headerHeight;

        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });

        // Add highlight effect
        commentsSection.style.transform = 'scale(1.02)';
        commentsSection.style.transition = 'transform 0.3s ease';
        setTimeout(() => {
            commentsSection.style.transform = 'scale(1)';
        }, 300);
    }
};

// Collapsible sections functionality
window.toggleFinancialSection = function() {
    const content = document.getElementById('financialContent');
    const toggle = document.getElementById('financialToggle');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        toggle.classList.remove('fa-chevron-right');
        toggle.classList.add('fa-chevron-down');
    } else {
        content.style.display = 'none';
        toggle.classList.remove('fa-chevron-down');
        toggle.classList.add('fa-chevron-right');
    }
};

window.toggleTransactions = function() {
    const content = document.getElementById('transactionContent');
    const toggle = document.getElementById('transactionToggle');

    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        toggle.style.transform = 'rotate(180deg)';
    } else {
        content.classList.add('hidden');
        toggle.style.transform = 'rotate(0deg)';
    }
};

// Timeline section toggle
window.toggleTimelineSection = function() {
    const content = document.getElementById('timelineContent');
    const toggle = document.getElementById('timelineToggle');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        toggle.classList.remove('fa-chevron-right');
        toggle.classList.add('fa-chevron-down');
    } else {
        content.style.display = 'none';
        toggle.classList.remove('fa-chevron-down');
        toggle.classList.add('fa-chevron-right');
    }
};

// Individual step toggle for desktop
window.toggleStepDetails = function(stepIndex) {
    const content = document.getElementById(`stepDetails${stepIndex}`);
    const toggle = document.getElementById(`stepToggle${stepIndex}`);

    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        toggle.style.transform = 'rotate(180deg)';
    } else {
        content.classList.add('hidden');
        toggle.style.transform = 'rotate(0deg)';
    }
};

// Individual step toggle for mobile
window.toggleStepDetailsMobile = function(stepIndex) {
    const content = document.getElementById(`stepDetailsMobile${stepIndex}`);
    const toggle = document.getElementById(`stepToggleMobile${stepIndex}`);

    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        toggle.style.transform = 'rotate(180deg)';
    } else {
        content.classList.add('hidden');
        toggle.style.transform = 'rotate(0deg)';
    }
};

// Stats breakdown modal functions
window.showProgressBreakdown = function() {
    const modal = document.getElementById('statsModal');
    const title = document.getElementById('statsModalTitle');
    const content = document.getElementById('statsModalContent');

    title.textContent = 'Progress Breakdown';
    content.innerHTML = `
        <div class="space-y-4">
            <div class="bg-blue-50 p-4 rounded-lg">
                <h4 class="font-semibold text-blue-900 mb-2">Overall Progress: <?php echo $progress; ?>%</h4>
                <p class="text-blue-700 text-sm">This represents the completion status based on project milestones and deliverables.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="text-center p-3 bg-green-50 rounded-lg">
                    <div class="text-2xl font-bold text-green-600"><?php echo $completed_steps_count; ?></div>
                    <div class="text-sm text-green-700">Completed Steps</div>
                </div>
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-gray-600"><?php echo $total_steps_count - $completed_steps_count; ?></div>
                    <div class="text-sm text-gray-700">Remaining Steps</div>
                </div>
            </div>
        </div>
    `;
    modal.classList.remove('hidden');
};

window.showStepsBreakdown = function() {
    const modal = document.getElementById('statsModal');
    const title = document.getElementById('statsModalTitle');
    const content = document.getElementById('statsModalContent');

    title.textContent = 'Project Steps';
    content.innerHTML = `
        <div class="space-y-3">
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-900 mb-2">Step Completion Status</h4>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-green-600">✓ Completed</span>
                        <span class="font-medium"><?php echo $completed_steps_count; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">○ Remaining</span>
                        <span class="font-medium"><?php echo $total_steps_count - $completed_steps_count; ?></span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="font-semibold">Total Steps</span>
                        <span class="font-bold"><?php echo $total_steps_count; ?></span>
                    </div>
                </div>
            </div>


        </div>
    `;
    modal.classList.remove('hidden');
};

window.showDepartmentInfo = function() {
    const modal = document.getElementById('statsModal');
    const title = document.getElementById('statsModalTitle');
    const content = document.getElementById('statsModalContent');

    title.textContent = 'Department Information';
    content.innerHTML = `
        <div class="space-y-4">
            <div class="bg-blue-50 p-4 rounded-lg">
                <h4 class="font-semibold text-blue-900 mb-2"><?php echo htmlspecialchars($project['department_name']); ?></h4>
                <p class="text-blue-700 text-sm">This is the implementing department responsible for overseeing this project.</p>
            </div>
            <div class="text-sm text-gray-600">
                <p><strong>Project Year:</strong> <?php echo $project['project_year']; ?></p>
                <p><strong>Status:</strong> <?php echo ucfirst($project['status']); ?></p>
            </div>
        </div>
    `;
    modal.classList.remove('hidden');
};

window.showYearInfo = function() {
    const modal = document.getElementById('statsModal');
    const title = document.getElementById('statsModalTitle');
    const content = document.getElementById('statsModalContent');

    title.textContent = 'Project Year';
    content.innerHTML = `
        <div class="space-y-4">
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-900 mb-2">Project Year: <?php echo $project['project_year']; ?></h4>
                <p class="text-gray-700 text-sm">This project was initiated in the <?php echo $project['project_year']; ?> financial year.</p>
            </div>
        </div>
    `;
    modal.classList.remove('hidden');
};

window.showBudgetBreakdown = function() {
    const modal = document.getElementById('statsModal');
    const title = document.getElementById('statsModalTitle');
    const content = document.getElementById('statsModalContent');

    title.textContent = 'Budget Information';
    content.innerHTML = `
        <div class="space-y-4">
            <div class="bg-green-50 p-4 rounded-lg">
                <h4 class="font-semibold text-green-900 mb-2">Total Budget: KES <?php echo number_format($project_total_budget); ?></h4>
                <p class="text-green-700 text-sm">This is the total approved budget for the project.</p>
            </div>
            <div class="grid grid-cols-1 gap-3">
                <div class="flex justify-between p-3 bg-blue-50 rounded">
                    <span class="text-blue-700">Disbursed</span>
                    <span class="font-semibold text-blue-900">KES <?php echo number_format($total_disbursed); ?></span>
                </div>
                <div class="flex justify-between p-3 bg-red-50 rounded">
                    <span class="text-red-700">Spent</span>
                    <span class="font-semibold text-red-900">KES <?php echo number_format($total_spent); ?></span>
                </div>
                <div class="flex justify-between p-3 bg-gray-50 rounded">
                    <span class="text-gray-700">Balance</span>
                    <span class="font-semibold <?php echo $remaining_balance >= 0 ? 'text-green-900' : 'text-red-900'; ?>">KES <?php echo number_format($remaining_balance); ?></span>
                </div>
            </div>
        </div>
    `;
    modal.classList.remove('hidden');
};

window.closeStatsModal = function() {
    const modal = document.getElementById('statsModal');
    modal.classList.add('hidden');
};

// Subscription Modal Functions
window.showSubscriptionModal = function() {
    document.getElementById('subscriptionModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    // Clear any previous error messages
    document.getElementById('emailError').classList.add('hidden');
    document.getElementById('subscriptionEmail').value = '';
};

window.closeSubscriptionModal = function() {
    document.getElementById('subscriptionModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    // Reset form
    document.getElementById('subscriptionForm').reset();
    document.getElementById('emailError').classList.add('hidden');
};

window.submitSubscription = async function() {
    const email = document.getElementById('subscriptionEmail').value.trim();
    const emailError = document.getElementById('emailError');
    const subscribeBtn = document.getElementById('subscribeBtn');

    // Reset error state
    emailError.classList.add('hidden');

    // Validate email
    if (!email) {
        emailError.textContent = 'Email address is required';
        emailError.classList.remove('hidden');
        return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        emailError.textContent = 'Please enter a valid email address';
        emailError.classList.remove('hidden');
        return;
    }

    // Disable button and show loading
    subscribeBtn.disabled = true;
    subscribeBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Subscribing...';

    try {
        const response = await fetch(BASE_URL + 'api/subscribe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                project_id: <?php echo $project_id; ?>,
                email: email
            })
        });

        const result = await response.json();

        if (result.success) {
            // Show success message
            alert('✅ ' + result.message);
            closeSubscriptionModal();
        } else {
            // Show error message
            emailError.textContent = result.message;
            emailError.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Subscription error:', error);
        emailError.textContent = 'An error occurred. Please try again later.';
        emailError.classList.remove('hidden');
    } finally {
        // Re-enable button
        subscribeBtn.disabled = false;
        subscribeBtn.innerHTML = '<i class="fas fa-bell mr-2"></i>Subscribe Now';
    }
};

// Document Modal Functions
window.openDocumentModal = function(filename, fileUrl) {
    const modal = document.getElementById('documentModal');
    const title = document.getElementById('documentModalTitle');
    const viewer = document.getElementById('documentViewer');

    if (!modal || !title || !viewer) {
        console.error('Document modal elements not found');
        return;
    }

    title.textContent = filename;
    viewer.src = fileUrl;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};

window.closeDocumentModal = function() {
    const modal = document.getElementById('documentModal');
    const viewer = document.getElementById('documentViewer');

    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    if (viewer) {
        viewer.src = '';
    }
};

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSubscriptionModal();
        closeStatsModal();
        closeDocumentModal();
    }
});

// Initialize application when DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize comment form handler (only once)
    initializeCommentForm();
});

// Comment form handler function to prevent duplicate submissions
function initializeCommentForm() {
    const commentForm = document.getElementById('commentForm');
    const commentResponseMessage = document.getElementById('commentResponseMessage');
    const submitBtn = document.getElementById('submitBtn');
    const parentCommentIdInput = document.getElementById('parentCommentId');
    const commentFormTitle = document.getElementById('commentFormTitle');
    const replyingToInfo = document.getElementById('replyingToInfo');
    const replyingToName = document.getElementById('replyingToName');

    // Check if form exists and handler not already attached
    if (commentForm && !commentForm.hasAttribute('data-handler-attached')) {
        // Mark as having handler attached
        commentForm.setAttribute('data-handler-attached', 'true');

        commentForm.addEventListener('submit', function(event) {
            event.preventDefault();

            // Prevent double submission
            if (submitBtn.disabled) {
                return;
            }

            // Disable the submit button and change the text
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...';

            // Prepare the form data
            const formData = new FormData(commentForm);

            // Send the form data to the server
            fetch('api/feedback.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Re-enable the submit button and change the text back
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i> Submit Comment';

                // Handle the response from the server
                if (data.success) {
                    // Display success message
                    commentResponseMessage.textContent = data.message;
                    commentResponseMessage.classList.remove('hidden', 'border-red-500', 'text-red-700');
                    commentResponseMessage.classList.add('border-green-500', 'text-green-700');

                    // Clear the form
                    commentForm.reset();

                    // Hide the reply context banner
                    if (replyingToInfo) replyingToInfo.classList.add('hidden');
                    if (parentCommentIdInput) parentCommentIdInput.value = 0;
                    if (commentFormTitle) commentFormTitle.textContent = 'Join the Discussion';

                    // Reload comments - simplest approach for now
                    setTimeout(function() {
                        location.reload();
                    }, 1500);

                } else {
                    // Display error message
                    commentResponseMessage.textContent = data.message;
                    commentResponseMessage.classList.remove('hidden', 'border-green-500', 'text-green-700');
                    commentResponseMessage.classList.add('border-red-500', 'text-red-700');
                }

                // Automatically hide the response message after 5 seconds
                setTimeout(function() {
                    commentResponseMessage.classList.add('hidden');
                }, 5000);
            })
            .catch(error => {
                console.error('Error:', error);

                // Re-enable the submit button and change the text back
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i> Submit Comment';

                // Display a generic error message
                commentResponseMessage.textContent = 'An error occurred while submitting your comment. Please try again later.';
                commentResponseMessage.classList.remove('hidden', 'border-green-500', 'text-green-700');
                commentResponseMessage.classList.add('border-red-500', 'text-red-700');

                // Automatically hide the response message after 5 seconds
                setTimeout(function() {
                    commentResponseMessage.classList.add('hidden');
                }, 5000);
            });
        });
    }
}

// Global functions
// Function to load more comments (AJAX)
window.loadMoreComments = async function() {
    const loadButton = document.querySelector('#loadMoreCommentsButton button');
    if (!loadButton) {
        console.error('Load more comments button not found');
        return;
    }

    const originalText = loadButton.innerHTML;
    const currentComments = document.querySelectorAll('.comment-thread').length;

    // Show loading state
    loadButton.disabled = true;
    loadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';

    try {
        const response = await fetch(`${BASE_URL}api/loadMoreComments.php?project_id=<?php echo $project_id; ?>&offset=${currentComments}&limit=10`);

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.success && data.comments.length > 0) {
            const commentsContainer = document.getElementById('comments-container');
            const loadMoreContainer = document.getElementById('loadMoreCommentsButton');

            // Create new comment elements
            data.comments.forEach(comment => {
                const commentElement = createCommentElement(comment);
                commentsContainer.insertBefore(commentElement, loadMoreContainer);
            });

            // Update or remove load more button
            if (data.has_more && data.remaining > 0) {
                loadButton.innerHTML = `Load More Comments (${data.remaining} remaining)`;
                loadButton.disabled = false;
            } else {
                loadMoreContainer.remove();
            }
        } else {
            loadMoreContainer.style.display = 'none';
        }
    } catch (error) {
        console.error('Error loading more comments:', error);
        loadButton.innerHTML = originalText;
        loadButton.disabled = false;

        // Show user-friendly error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'text-red-500 text-xs mt-2 text-center';
        errorDiv.textContent = 'Failed to load comments. Please try again.';
        loadButton.parentElement.appendChild(errorDiv);

        setTimeout(() => {
            if (errorDiv.parentElement) {
                errorDiv.remove();
            }
        }, 3000);
    }
};

// Function to toggle replies visibility
window.toggleReplies = function(commentId) {
    const repliesContainer = document.getElementById(`replies-${commentId}`);
    const viewRepliesBtn = document.getElementById(`viewRepliesBtn-${commentId}`);

    if (!repliesContainer || !viewRepliesBtn) {
        console.error('Replies container or button not found for comment ID:', commentId);
        return;
    }

    if (repliesContainer.classList.contains('hidden')) {
        // Show replies
        repliesContainer.classList.remove('hidden');
        viewRepliesBtn.innerHTML = '<i class="fas fa-chevron-up" style="font-size: 10px;"></i> Hide replies';
    } else {
        // Hide replies
        repliesContainer.classList.add('hidden');
        // Reset the button text to show original reply count
        const totalReplies = repliesContainer.querySelectorAll('.reply-item').length;
        const loadMoreBtn = document.querySelector(`#loadMoreReplies-${commentId} button`);
        const remainingReplies = loadMoreBtn ? parseInt(loadMoreBtn.textContent.match(/\d+/)) || 0 : 0;
        const actualTotal = totalReplies + remainingReplies;

        viewRepliesBtn.innerHTML = `<i class="fas fa-comment-dots" style="font-size: 10px;"></i> View ${actualTotal} ${actualTotal === 1 ? 'reply' : 'replies'}`;
    }
};

// Function to load more replies (AJAX)
window.loadMoreReplies = async function(parentId, currentOffset) {
    const loadButton = document.querySelector(`#loadMoreReplies-${parentId} button`);
    if (!loadButton) {
        console.error('Load more button not found for parent ID:', parentId);
        return;
    }

    const originalText = loadButton.innerHTML;

    // Show loading state
    loadButton.disabled = true;
    loadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';

    try {
        const response = await fetch(`${BASE_URL}api/loadMoreReplies.php?parent_id=${parentId}&offset=${currentOffset}&limit=10`);

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.success && data.replies.length > 0) {
            const repliesList = document.querySelector(`#repliesList-${parentId}`);
            const loadMoreContainer = document.querySelector(`#loadMoreReplies-${parentId}`);

            if (!repliesList) {
                console.error('Replies list not found for parent ID:', parentId);
                return;
            }

            // Create new reply elements and append them to the replies list
            data.replies.forEach(reply => {
                const replyElement = createReplyElement(reply);
                repliesList.appendChild(replyElement);
            });

            // Update or remove load more button with correct offset calculation
            if (data.has_more && data.remaining > 0) {
                const newOffset = currentOffset + data.replies.length;
                loadButton.setAttribute('onclick', `loadMoreReplies(${parentId}, ${newOffset})`);
                loadButton.innerHTML = `Show ${Math.min(data.remaining, 10)} more replies`;
                loadButton.disabled = false;
            } else {
                loadMoreContainer.remove();
            }
        } else {
            loadMoreContainer.remove();
        }
    } catch (error) {
        console.error('Error loading more replies:', error);
        loadButton.innerHTML = originalText;
        loadButton.disabled = false;

        // Show user-friendly error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'text-red-500 text-xs mt-2 text-center';
        errorDiv.textContent = 'Failed to load replies. Please try again.';
        loadButton.parentElement.appendChild(errorDiv);

        setTimeout(() => {
            if (errorDiv.parentElement) {
                errorDiv.remove();
            }
        }, 3000);
    }
};

function createReplyElement(reply) {
    const replyDiv = document.createElement('div');
    replyDiv.className = `reply-item ${reply.is_admin ? 'admin-response' : 'user-reply'}`;

    const pendingBadge = reply.is_user_pending ? 
        '<span class="comment-badge pending"><i class="fas fa-clock" style="font-size: 7px;"></i> Awaiting Approval</span>' : '';

    const adminBadge = reply.is_admin ? 
        '<span class="comment-badge admin"><i class="fas fa-shield-alt" style="font-size: 7px;"></i> Official Response</span>' : '';

    replyDiv.innerHTML = `
        <div class="flex items-start">
            <div class="reply-avatar ${reply.is_admin ? 'admin' : 'user'}">
                ${reply.is_admin ? '<i class="fas fa-shield-alt"></i>' : reply.display_name.charAt(0).toUpperCase()}
            </div>
            <div class="reply-content">
                <div class="flex items-center">
                    <span class="reply-author">
                        ${escapeHtml(reply.display_name)}
                    </span>
                    ${adminBadge}
                    ${pendingBadge}
                </div>
                <div class="reply-text ${reply.is_admin ? 'admin-reply-text' : ''}">
                    ${nl2br(escapeHtml(reply.message))}
                </div>
                <div class="reply-meta">
                    <span class="text-gray-500">
                        ${reply.time_ago}
                    </span>
                </div>
            </div>
        </div>
    `;

    return replyDiv;
}

function nl2br(str) {
    return str.replace(/\n/g, '<br>');
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function createCommentElement(comment) {
    const commentDiv = document.createElement('div');
    commentDiv.className = 'comment-thread';
    commentDiv.setAttribute('data-comment-id', comment.id);

    const pendingBadge = comment.is_user_pending ? 
        '<span class="comment-badge pending"><i class="fas fa-clock" style="font-size: 8px;"></i> Awaiting Review</span>' : '';

    const adminBadge = comment.is_admin ? 
        '<span class="comment-badge admin"><i class="fas fa-shield-alt" style="font-size: 8px;"></i> Official</span>' : '';

    let repliesHtml = '';
    if (comment.replies && comment.replies.length > 0) {
        comment.replies.forEach(reply => {
            const replyPendingBadge = reply.is_user_pending ? 
                '<span class="comment-badge pending"><i class="fas fa-clock" style="font-size: 7px;"></i> Awaiting Approval</span>' : '';

            const replyAdminBadge = reply.is_admin ? 
                '<span class="comment-badge admin"><i class="fas fa-shield-alt" style="font-size: 7px;"></i> Official Response</span>' : '';

            repliesHtml += `
                <div class="reply-item ${reply.is_admin ? 'admin-response' : 'user-reply'}">
                    <div class="flex items-start">
                        <div class="reply-avatar ${reply.is_admin ? 'admin' : 'user'}">
                            ${reply.is_admin ? '<i class="fas fa-shield-alt"></i>' : reply.display_name.charAt(0).toUpperCase()}
                        </div>
                        <div class="reply-content">
                            <div class="flex items-center">
                                <span class="reply-author">
                                    ${escapeHtml(reply.display_name)}
                                </span>
                                ${replyAdminBadge}
                                ${replyPendingBadge}
                            </div>
                            <div class="reply-text ${reply.is_admin ? 'admin-reply-text' : ''}">
                                ${nl2br(escapeHtml(reply.message))}
                            </div>
                            <div class="reply-meta">
                                <span class="text-gray-500">
                                    ${reply.time_ago}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
    }

    const loadMoreRepliesButton = (comment.total_replies > comment.shown_replies) ? 
        `<div class="text-center mt-3" id="loadMoreReplies-${comment.id}">
            <button onclick="loadMoreReplies(${comment.id}, ${comment.shown_replies})" 
                    class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                Show ${Math.min(comment.total_replies - comment.shown_replies, 10)} more replies
            </button>
        </div>` : '';

    const viewRepliesButton = comment.total_replies > 0 ? 
        `<button onclick="toggleReplies(${comment.id})" 
                class="text-blue-600 hover:text-blue-800 font-medium" 
                id="viewRepliesBtn-${comment.id}">
            <i class="fas fa-comment-dots" style="font-size: 10px;"></i>
            View ${comment.total_replies} ${comment.total_replies === 1 ? 'reply' : 'replies'}
        </button>` : '';

    commentDiv.innerHTML = `
        <div class="comment-main">
            <div class="flex items-start">
                <div class="comment-avatar ${comment.is_admin ? 'admin' : 'user'}">
                    ${comment.is_admin ? '<i class="fas fa-shield-alt"></i>' : comment.display_name.charAt(0).toUpperCase()}
                </div>
                <div class="comment-content">
                    <div class="flex items-center">
                        <span class="comment-author">
                            ${escapeHtml(comment.display_name)}
                        </span>
                        ${adminBadge}
                        ${pendingBadge}
                    </div>
                    <div class="comment-text">
                        ${nl2br(escapeHtml(comment.message))}
                    </div>
                    <div class="comment-meta">
                        <button onclick="replyToComment(${comment.id}, '${escapeHtml(comment.display_name).replace(/'/g, "\\'")})" 
                                class="text-blue-600 hover:text-blue-800 font-medium">
                            <i class="fas fa-reply" style="font-size: 10px;"></i> Reply
                        </button>
                        ${viewRepliesButton}
                        <span class="text-gray-500">
                            ${comment.time_ago}
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="replies-container hidden" id="replies-${comment.id}">
            <div class="replies-list" id="repliesList-${comment.id}">
                ${repliesHtml}
            </div>
            ${loadMoreRepliesButton}
        </div>
    `;

    return commentDiv;
}

// Reply to comment function
window.replyToComment = function(commentId, authorName) {
    const parentCommentIdInput = document.getElementById('parentCommentId');
    const commentFormTitle = document.getElementById('commentFormTitle');
    const replyingToInfo = document.getElementById('replyingToInfo');
    const replyingToName = document.getElementById('replyingToName');

    // Set the parent comment ID
    parentCommentIdInput.value = commentId;

    // Update the comment form title
    commentFormTitle.textContent = 'Replying to ' + authorName;

    // Display the "replying to" information
    replyingToName.textContent = authorName;
    replyingToInfo.classList.remove('hidden');

    // Scroll to the comment form
    document.getElementById('commentForm').scrollIntoView({ behavior: 'smooth' });
};

// Cancel reply function
window.cancelReply = function() {
    const parentCommentIdInput = document.getElementById('parentCommentId');
    const commentFormTitle = document.getElementById('commentFormTitle');
    const replyingToInfo = document.getElementById('replyingToInfo');
    // Reset the parent comment ID
    parentCommentIdInput.value = 0;

    // Update the comment form title
    commentFormTitle.textContent = 'Join the Discussion';

    // Hide the "replying to" information
    replyingToInfo.classList.add('hidden');
};
</script>

<!-- Feedback Modal -->
<div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
        <div class="flex justify-between items-center mb-4">
            <h3 id="feedbackModalTitle" class="text-lg font-semibold text-gray-900">Leave Feedback</h3>
            <button type="button" onclick="closeFeedbackModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="feedbackForm" class="space-y-4">
            <input type="hidden" id="projectId" name="project_id" value="<?php echo $project['id']; ?>">
            <input type="hidden" id="parentCommentId" name="parent_comment_id" value="">

            <div>
                <label for="citizenName" class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                <input type="text" id="citizenName" name="citizen_name" required 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label for="citizenEmail" class="block text-sm font-medium text-gray-700 mb-1">Email (optional)</label>
                <input type="email" id="citizenEmail" name="citizen_email"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label for="feedbackMessage" class="block text-sm font-medium text-gray-700 mb-1">Message *</label>
                <textarea id="feedbackMessage" name="message" required rows="4"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                          placeholder="Share your thoughts about this project..."></textarea>
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeFeedbackModal()" 
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                    Cancel
                </button>
                <button type="submit" id="submitFeedbackBtn"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    Submit Feedback
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>