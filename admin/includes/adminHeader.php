<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_admin();
$current_admin = get_current_admin();

// Get allowed pages and feedback counts
$allowed_pages = getAllowedPages();
$pending_count = 0;
$grievance_count = 0;
$has_feedback_permission = hasPagePermission('manage_feedback');

if ($has_feedback_permission) {
    try {
        $pending_count = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")->fetchColumn();

        if ($current_admin['role'] === 'super_admin') {
            $grievance_count = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'grievance'")->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback f
                                  JOIN projects p ON f.project_id = p.id
                                  WHERE f.status = 'grievance' 
                                  AND p.created_by = ?");
            $stmt->execute([$current_admin['id']]);
            $grievance_count = $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log("Error getting feedback counts: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Migori County PMC Portal - Admin</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../migoriLogo.png">

    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'pmc-navy': '#003366',
                        'pmc-gold': '#FFD966',
                        'pmc-gray': '#F4F4F4',
                        'pmc-text': '#333333',
                        'pmc-green': '#4CAF50'
                    },
                    zIndex: {
                        '999': '999',
                        '1000': '1000',
                        '1001': '1001',
                        '1002': '1002',
                        '1050': '1050',
                        '9999': '9999'
                    }
                }
            }
        }
    </script>

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>
        /* Critical mobile fixes with proper z-index management */
        body {
            overflow-x: hidden;
            position: relative;
        }

        /* Fixed header with highest z-index */
        .admin-header {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1002 !important;
            height: 64px !important;
            background: linear-gradient(135deg, #003366 0%, #004080 100%) !important;
            border-bottom: 3px solid #FFD966 !important;
            color: white !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }

        /* Main content pushed below fixed header */
        .content-wrapper {
            margin-top: 64px !important;
            min-height: calc(100vh - 64px) !important;
        }

        /* Desktop sidebar positioning */
        .desktop-sidebar {
            position: fixed !important;
            top: 64px !important;
            left: 0 !important;
            bottom: 0 !important;
            width: 250px !important;
            z-index: 1000 !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            overflow-y: auto !important;
            transform: translateX(0) !important;
            transition: transform 0.3s ease !important;
        }

        /* Mobile sidebar overlay and positioning */
        .mobile-sidebar-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            background: rgba(0, 0, 0, 0.5) !important;
            z-index: 999 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            transition: opacity 0.3s ease, visibility 0.3s ease !important;
        }

        .mobile-sidebar-overlay.active {
            opacity: 1 !important;
            visibility: visible !important;
        }

        /* Mobile sidebar */
        .mobile-sidebar {
            position: fixed !important;
            top: 64px !important;
            left: 0 !important;
            bottom: 0 !important;
            width: 280px !important;
            max-width: 85vw !important;
            z-index: 1001 !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            transform: translateX(-100%) !important;
            transition: transform 0.3s ease !important;
            overflow-y: auto !important;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1) !important;
        }

        .mobile-sidebar.active {
            transform: translateX(0) !important;
        }

        /* Navigation items with proper click targets */
        .sidebar-nav-item {
            display: flex !important;
            align-items: center !important;
            padding: 12px 16px !important;
            margin: 4px 8px !important;
            border-radius: 8px !important;
            color: #003366 !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            cursor: pointer !important;
            position: relative !important;
            z-index: 1 !important;
            min-height: 44px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
        }

        .sidebar-nav-item:hover {
            background: #f3f4f6 !important;
            color: #003366 !important;
            text-decoration: none !important;
        }

        .sidebar-nav-item.active {
            background: #eff6ff !important;
            color: #003366 !important;
            font-weight: 600 !important;
            border-left: 3px solid #003366 !important;
        }

        .sidebar-nav-item i {
            width: 20px !important;
            margin-right: 12px !important;
            text-align: center !important;
            color: inherit !important;
            flex-shrink: 0 !important;
        }

        /* Main content area */
        .main-content {
            margin-left: 250px !important;
            padding: 1.5rem !important;
            min-height: calc(100vh - 64px) !important;
            background: #F4F4F4 !important;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            width: 44px !important;
            height: 44px !important;
            background: none !important;
            border: none !important;
            color: white !important;
            font-size: 18px !important;
            cursor: pointer !important;
            border-radius: 6px !important;
            transition: background 0.2s ease !important;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1) !important;
        }

        /* Notification badges */
        .notification-badge {
            position: absolute !important;
            top: -6px !important;
            right: -6px !important;
            background: #ef4444 !important;
            color: white !important;
            font-size: 11px !important;
            font-weight: bold !important;
            padding: 2px 6px !important;
            border-radius: 10px !important;
            min-width: 18px !important;
            text-align: center !important;
            line-height: 1.2 !important;
            z-index: 10 !important;
        }

        /* Prevent body scroll when sidebar is open */
        body.sidebar-open {
            overflow: hidden !important;
        }

        /* Desktop responsive */
        @media (min-width: 1024px) {
            .mobile-menu-btn {
                display: none !important;
            }
            
            .mobile-sidebar {
                display: none !important;
            }
            
            .mobile-sidebar-overlay {
                display: none !important;
            }
            
            .desktop-sidebar {
                display: block !important;
            }
        }

        /* Mobile responsive */
        @media (max-width: 1023px) {
            .mobile-menu-btn {
                display: flex !important;
            }
            
            .desktop-sidebar {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 1rem !important;
            }
        }

        /* Extra small screens */
        @media (max-width: 640px) {
            .mobile-sidebar {
                width: 90vw !important;
                max-width: 300px !important;
            }
            
            .main-content {
                padding: 0.75rem !important;
            }
            
            .admin-header h1 {
                font-size: 1.25rem !important;
            }
            
            .admin-header p {
                font-size: 0.75rem !important;
            }
        }

        /* Section headers in sidebar */
        .sidebar-section-header {
            padding: 8px 16px !important;
            margin: 16px 8px 8px 8px !important;
            font-size: 11px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            color: #6b7280 !important;
            border-top: 1px solid #e5e7eb !important;
        }

        .sidebar-section-header:first-child {
            border-top: none !important;
            margin-top: 8px !important;
        }

        /* Logo and text overflow fixes */
        .header-logo-section {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            min-width: 0 !important;
            flex: 1 !important;
        }

        .header-logo-section img {
            width: 40px !important;
            height: 40px !important;
            flex-shrink: 0 !important;
        }

        .header-text {
            min-width: 0 !important;
            overflow: hidden !important;
        }

        .header-text h1 {
            font-size: 1.5rem !important;
            font-weight: 700 !important;
            line-height: 1.2 !important;
            margin: 0 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .header-text p {
            font-size: 0.875rem !important;
            line-height: 1.2 !important;
            margin: 0 !important;
            color: rgba(255, 255, 255, 0.8) !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        /* Header actions section */
        .header-actions {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            flex-shrink: 0 !important;
        }

        /* User avatar */
        .user-avatar {
            width: 36px !important;
            height: 36px !important;
            background: #FFD966 !important;
            color: #003366 !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            flex-shrink: 0 !important;
        }

        @media (max-width: 640px) {
            .header-text h1 {
                font-size: 1.25rem !important;
            }
            
            .header-text p {
                font-size: 0.75rem !important;
            }
            
            .user-avatar {
                width: 32px !important;
                height: 32px !important;
                font-size: 12px !important;
            }
            
            .header-actions {
                gap: 8px !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Fixed Admin Header -->
    <header class="admin-header">
        <div class="flex items-center justify-between h-full px-4">
            <!-- Left section with mobile menu and logo -->
            <div class="header-logo-section">
                <button id="mobile-menu-toggle" class="mobile-menu-btn" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="index.php" class="flex items-center gap-2 min-w-0">
                    <img src="../migoriLogo.png" alt="Migori County" class="w-10 h-10 flex-shrink-0">
                    <div class="header-text">
                        <h1 class="text-white font-bold">PMC Portal</h1>
                        <p class="text-blue-200">Admin Dashboard</p>
                    </div>
                </a>
            </div>

            <!-- Right section with notifications and user -->
            <div class="header-actions">
                <?php if ($has_feedback_permission): ?>
                <div class="relative">
                    <a href="feedback.php" class="text-white hover:text-pmc-gold p-2 rounded-lg hover:bg-white/10 flex items-center justify-center w-10 h-10">
                        <i class="fas fa-bell text-lg"></i>
                        <?php if ($pending_count > 0): ?>
                            <span class="notification-badge"><?php echo $pending_count; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- User Menu -->
                <div class="flex items-center gap-2">
                    <div class="user-avatar">
                        <span><?php echo strtoupper(substr($current_admin['name'], 0, 1)); ?></span>
                    </div>
                    <span class="text-white text-sm font-medium hidden sm:block"><?php echo htmlspecialchars($current_admin['name']); ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Sidebar Overlay -->
    <div id="mobile-sidebar-overlay" class="mobile-sidebar-overlay"></div>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Desktop Sidebar -->
        <aside class="desktop-sidebar">
            <nav class="py-4">
                <!-- Dashboard -->
                <a href="index.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Projects Section -->
                <?php $has_project_perms = hasPagePermission('create_projects') || hasPagePermission('view_projects') || 
                                          hasPagePermission('manage_budgets') || hasPagePermission('view_reports'); ?>
                <?php if ($has_project_perms): ?>
                    <div class="sidebar-section-header">Project Management</div>

                    <?php if (hasPagePermission('create_projects')): ?>
                        <a href="importCsv.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'importCsv.php' ? 'active' : '' ?>">
                            <i class="fas fa-upload"></i>
                            <span>Upload Projects</span>
                        </a>
                        <a href="createProject.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'createProject.php' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add New Project</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('view_projects')): ?>
                        <a href="projects.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'projects.php' ? 'active' : '' ?>">
                            <i class="fas fa-folder"></i>
                            <span>Manage Projects</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('manage_budgets')): ?>
                        <a href="budgetManagement.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'budgetManagement.php' ? 'active' : '' ?>">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Budget Management</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('view_reports')): ?>
                        <a href="pmcReports.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'pmcReports.php' ? 'active' : '' ?>">
                            <i class="fas fa-chart-bar"></i>
                            <span>PMC Reports</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Community & Documents -->
                <?php $has_community_perms = hasPagePermission('manage_documents') || hasPagePermission('manage_feedback'); ?>
                <?php if ($has_community_perms): ?>
                    <div class="sidebar-section-header">Community & Documents</div>

                    <?php if (hasPagePermission('manage_documents')): ?>
                        <a href="documentManager.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'documentManager.php' ? 'active' : '' ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>PMC Documents</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('manage_feedback')): ?>
                        <a href="feedback.php" class="sidebar-nav-item relative <?= basename($_SERVER['PHP_SELF']) == 'feedback.php' ? 'active' : '' ?>">
                            <i class="fas fa-comments"></i>
                            <span>Community Feedback</span>
                            <?php if ($pending_count > 0): ?>
                                <span class="notification-badge"><?php echo $pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($grievance_count > 0): ?>
                        <a href="grievances.php" class="sidebar-nav-item relative <?= basename($_SERVER['PHP_SELF']) == 'grievances.php' ? 'active' : '' ?>">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                            <span class="text-red-700">Grievance Management</span>
                            <span class="notification-badge bg-red-500"><?php echo $grievance_count; ?></span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- System Administration -->
                <?php $has_admin_perms = hasPagePermission('manage_roles') || hasPagePermission('system_settings'); ?>
                <?php if ($has_admin_perms): ?>
                    <div class="sidebar-section-header">System Administration</div>

                    <?php if (hasPagePermission('manage_roles')): ?>
                        <a href="rolesPermissions.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'rolesPermissions.php' ? 'active' : '' ?>">
                            <i class="fas fa-user-shield"></i>
                            <span>Roles & Permissions</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('system_settings')): ?>
                        <a href="systemSettings.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'systemSettings.php' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i>
                            <span>System Settings</span>
                        </a>
                        <a href="settings.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                            <i class="fas fa-sliders-h"></i>
                            <span>General Settings</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Profile & Logout -->
                <div class="sidebar-section-header">Account</div>
                <a href="profile.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
                    <i class="fas fa-user"></i>
                    <span>Profile Settings</span>
                </a>
                <a href="../logout.php" class="sidebar-nav-item text-red-600 hover:!bg-red-50">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Mobile Sidebar -->
        <aside id="mobile-sidebar" class="mobile-sidebar">
            <nav class="py-4">
                <!-- Dashboard -->
                <a href="index.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Projects Section -->
                <?php if ($has_project_perms): ?>
                    <div class="sidebar-section-header">Project Management</div>

                    <?php if (hasPagePermission('create_projects')): ?>
                        <a href="importCsv.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'importCsv.php' ? 'active' : '' ?>">
                            <i class="fas fa-upload"></i>
                            <span>Upload Projects</span>
                        </a>
                        <a href="createProject.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'createProject.php' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add New Project</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('view_projects')): ?>
                        <a href="projects.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'projects.php' ? 'active' : '' ?>">
                            <i class="fas fa-folder"></i>
                            <span>Manage Projects</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('manage_budgets')): ?>
                        <a href="budgetManagement.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'budgetManagement.php' ? 'active' : '' ?>">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Budget Management</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('view_reports')): ?>
                        <a href="pmcReports.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'pmcReports.php' ? 'active' : '' ?>">
                            <i class="fas fa-chart-bar"></i>
                            <span>PMC Reports</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Community & Documents -->
                <?php if ($has_community_perms): ?>
                    <div class="sidebar-section-header">Community & Documents</div>

                    <?php if (hasPagePermission('manage_documents')): ?>
                        <a href="documentManager.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'documentManager.php' ? 'active' : '' ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>PMC Documents</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('manage_feedback')): ?>
                        <a href="feedback.php" class="sidebar-nav-item relative <?= basename($_SERVER['PHP_SELF']) == 'feedback.php' ? 'active' : '' ?>">
                            <i class="fas fa-comments"></i>
                            <span>Community Feedback</span>
                            <?php if ($pending_count > 0): ?>
                                <span class="notification-badge"><?php echo $pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($grievance_count > 0): ?>
                        <a href="grievances.php" class="sidebar-nav-item relative <?= basename($_SERVER['PHP_SELF']) == 'grievances.php' ? 'active' : '' ?>">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                            <span class="text-red-700">Grievance Management</span>
                            <span class="notification-badge bg-red-500"><?php echo $grievance_count; ?></span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- System Administration -->
                <?php if ($has_admin_perms): ?>
                    <div class="sidebar-section-header">System Administration</div>

                    <?php if (hasPagePermission('manage_roles')): ?>
                        <a href="rolesPermissions.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'rolesPermissions.php' ? 'active' : '' ?>">
                            <i class="fas fa-user-shield"></i>
                            <span>Roles & Permissions</span>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPagePermission('system_settings')): ?>
                        <a href="systemSettings.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'systemSettings.php' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i>
                            <span>System Settings</span>
                        </a>
                        <a href="settings.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                            <i class="fas fa-sliders-h"></i>
                            <span>General Settings</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Profile & Logout -->
                <div class="sidebar-section-header">Account</div>
                <a href="profile.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
                    <i class="fas fa-user"></i>
                    <span>Profile Settings</span>
                </a>
                <a href="../logout.php" class="sidebar-nav-item text-red-600 hover:!bg-red-50">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">