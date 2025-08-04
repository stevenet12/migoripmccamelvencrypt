<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';

require_role('admin');
$current_admin = get_current_admin();

$page_title = "Project Management";

// Log access
log_activity('projects_page_access', 'Accessed projects management page', $current_admin['id']);

// Get project statistics
try {
    $role_filter = "";
    $role_params = [];

    if ($current_admin['role'] !== 'super_admin') {
        $role_filter = " WHERE created_by = ?";
        $role_params = [$current_admin['id']];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $role_filter);
    $stmt->execute($role_params);
    $total_projects = $stmt->fetchColumn();

    // Get status counts
    $status_counts = [];
    $statuses = ['planning', 'ongoing', 'completed', 'suspended', 'cancelled'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE status = ?" . ($role_filter ? " AND created_by = ?" : ""));
        $params = [$status];
        if ($role_filter) $params[] = $current_admin['id'];
        $stmt->execute($params);
        $status_counts[$status] = $stmt->fetchColumn();
    }

} catch (Exception $e) {
    error_log("Projects page error: " . $e->getMessage());
    $total_projects = 0;
    $status_counts = [];
}

// Filter and pagination logic
$status = $_GET['status'] ?? '';
$department = $_GET['department'] ?? '';
$search = $_GET['search'] ?? '';
$sub_county = $_GET['sub_county'] ?? '';
$visibility = $_GET['visibility'] ?? '';
$year = $_GET['year'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$filters = array_filter([
    'status' => $status,
    'department' => $department,
    'search' => $search,
    'sub_county' => $sub_county,
    'visibility' => $visibility,
    'year' => $year,
    'page' => $page,
    'per_page' => $per_page
]);

// Role-based filtering
if ($current_admin['role'] !== 'super_admin') {
    $filters['created_by'] = $current_admin['id'];
}

// Get projects data
$projects_data = get_all_projects($filters, true, $per_page);
$projects = $projects_data['projects'] ?? [];
$total_projects = $projects_data['total'] ?? 0;
$total_pages = $projects_data['total_pages'] ?? 1;

// Get filter options
$departments = get_departments();
$years = get_project_years();

// Get sub-counties
try {
    $stmt = $pdo->query("SELECT id, name FROM sub_counties ORDER BY name");
    $sub_counties = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $sub_counties = [];
}

include 'includes/adminHeader.php';
?>

<style>
/* Responsive status counters */
.status-counters {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}

@media (min-width: 640px) {
    .status-counters {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 768px) {
    .status-counters {
        grid-template-columns: repeat(5, 1fr);
    }
}

.status-counter {
    padding: 0.75rem;
    border-radius: 0.5rem;
    text-align: center;
    min-width: 0;
}

.status-counter .count {
    font-size: 1.125rem;
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.status-counter .label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Compact table cells for mobile */
@media (max-width: 767px) {
    .project-table td, .project-table th {
        padding: 0.5rem;
    }
    
    .project-table th {
        font-size: 0.75rem;
    }
    
    .project-table td {
        font-size: 0.875rem;
    }
    
    .progress-container {
        min-width: 60px;
    }
    
    .action-button {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
}
</style>

<!-- Page Header (Outside Card) -->
<div class="mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li class="text-gray-600 font-medium">
                <i class="fas fa-home mr-1"></i> Dashboard
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">Projects</li>
        </ol>
    </nav>
</div>

<!-- Stats Header (Outside Card) -->
<div class="mb-8">
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-4 sm:p-6">
        <div class="flex flex-col md:flex-row items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1 sm:mb-2">Project Management</h1>
                <p class="text-sm sm:text-base text-gray-600">Manage and oversee all PMC projects</p>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">Monitor project progress and manage details</p>
            </div>
            <div class="text-center md:text-right">
                <div class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo number_format($total_projects); ?></div>
                <div class="text-xs sm:text-sm text-gray-600">Total Projects</div>
                <div class="text-xs text-gray-500">Under management</div>
            </div>
        </div>

        <!-- Status Overview - Improved responsive layout -->
        <div class="status-counters mt-4 sm:mt-6 pt-4 sm:pt-6 border-t border-gray-200">
            <div class="status-counter bg-yellow-50">
                <div class="count text-yellow-600"><?php echo number_format($status_counts['planning'] ?? 0); ?></div>
                <div class="label text-yellow-700">Planning</div>
            </div>
            <div class="status-counter bg-blue-50">
                <div class="count text-blue-600"><?php echo number_format($status_counts['ongoing'] ?? 0); ?></div>
                <div class="label text-blue-700">Ongoing</div>
            </div>
            <div class="status-counter bg-green-50">
                <div class="count text-green-600"><?php echo number_format($status_counts['completed'] ?? 0); ?></div>
                <div class="label text-green-700">Completed</div>
            </div>
            <div class="status-counter bg-orange-50">
                <div class="count text-orange-600"><?php echo number_format($status_counts['suspended'] ?? 0); ?></div>
                <div class="label text-orange-700">Suspended</div>
            </div>
            <div class="status-counter bg-red-50">
                <div class="count text-red-600"><?php echo number_format($status_counts['cancelled'] ?? 0); ?></div>
                <div class="label text-red-700">Cancelled</div>
            </div>
        </div>
    </div>
</div>

<!-- Main Card -->
<div class="bg-white shadow-sm rounded-lg border border-gray-200">
    <!-- Card Header -->
    <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 bg-gray-50">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-gray-900">Projects</h2>
                <p class="mt-1 text-sm text-gray-600 truncate">
                    <?php if ($current_admin['role'] === 'super_admin'): ?>
                        Manage all system projects
                    <?php else: ?>
                        Manage your projects
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex-shrink-0">
                <?php if (hasPagePermission('create_projects')): ?>
                    <a href="createProject.php" class="inline-flex items-center px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                        <i class="fas fa-plus mr-1 sm:mr-2"></i>
                        <span class="hidden sm:inline">New Project</span>
                        <span class="sm:hidden">Add</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card Content -->
    <div class="p-4 sm:p-6">
        <!-- Filters -->
        <div class="bg-gray-50 rounded-lg p-3 sm:p-4 mb-4 sm:mb-6">
            <form method="GET" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" name="search" placeholder="Search..." 
                               class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Statuses</option>
                        <option value="planning" <?php echo $status === 'planning' ? 'selected' : ''; ?>>Planning</option>
                        <option value="ongoing" <?php echo $status === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>

                    <select name="sub_county" id="subCountyFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Sub-Counties</option>
                        <?php foreach ($sub_counties as $id => $name): ?>
                            <option value="<?php echo htmlspecialchars($id); ?>" <?php echo $sub_county === (string)$id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="visibility" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Visibility</option>
                        <option value="private" <?php echo $visibility === 'private' ? 'selected' : ''; ?>>Private</option>
                        <option value="published" <?php echo $visibility === 'published' ? 'selected' : ''; ?>>Published</option>
                    </select>

                    <div class="flex space-x-2">
                        <button type="submit" class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                            Filter
                        </button>
                        <a href="projects.php" class="px-3 py-2 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 hover:bg-gray-50">
                            Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Projects List -->
        <div class="mb-3 sm:mb-4">
            <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                <?php echo number_format($total_projects); ?> Project<?php echo $total_projects !== 1 ? 's' : ''; ?>
            </h3>
        </div>

        <?php if (empty($projects)): ?>
            <div class="text-center py-8 sm:py-12">
                <i class="fas fa-project-diagram text-3xl sm:text-4xl text-gray-400 mb-3 sm:mb-4"></i>
                <h3 class="text-base sm:text-lg font-medium text-gray-900 mb-1 sm:mb-2">No Projects Found</h3>
                <p class="text-sm sm:text-base text-gray-600 mb-3 sm:mb-4">
                    <?php if ($current_admin['role'] === 'super_admin'): ?>
                        No projects match your current filters.
                    <?php else: ?>
                        You haven't created any projects yet.
                    <?php endif; ?>
                </p>
                <?php if (hasPagePermission('create_projects')): ?>
                    <a href="createProject.php" class="inline-flex items-center px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                        <i class="fas fa-plus mr-1 sm:mr-2"></i>Create Project
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 project-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visibility</th>
                            <th class="px-3 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($projects as $project): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 sm:px-4 py-3 sm:py-4">
                                    <div class="flex items-center">
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-900 truncate">
                                                <?php echo htmlspecialchars($project['project_name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 truncate">
                                                Year: <?php echo $project['project_year']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-4">
                                    <div class="text-sm text-gray-900 truncate">
                                        <?php echo htmlspecialchars($project['sub_county_name'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 truncate">
                                        <?php echo htmlspecialchars($project['ward_name'] ?? ''); ?>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo get_status_badge_class($project['status']); ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-12 sm:w-16 bg-gray-200 rounded-full h-2 mr-2 sm:mr-3 progress-container">
                                            <div class="h-2 rounded-full <?php echo get_progress_color_class($project['progress_percentage']); ?>" 
                                                 style="width: <?php echo $project['progress_percentage']; ?>%"></div>
                                        </div>
                                        <span class="text-xs sm:text-sm text-gray-900"><?php echo $project['progress_percentage']; ?>%</span>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-4 whitespace-nowrap">
                                    <?php 
                                    $row_status_class = $project['visibility'] === 'published' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                                    ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $row_status_class; ?>">
                                        <?php if ($project['visibility'] === 'published'): ?>
                                            <i class="fas fa-eye mr-1"></i>Public
                                        <?php else: ?>
                                            <i class="fas fa-eye-slash mr-1"></i>Private
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 sm:py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="manageProject.php?id=<?php echo $project['id']; ?>" 
                                       class="inline-flex items-center px-2 sm:px-3 py-1 border border-transparent text-xs sm:text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 action-button">
                                        Manage
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex flex-col sm:flex-row items-center justify-between mt-4 sm:mt-6 pt-3 sm:pt-4 border-t border-gray-200 gap-3">
                    <div class="text-xs sm:text-sm text-gray-700">
                        Showing <?php echo (($page - 1) * $per_page) + 1; ?> to 
                        <?php echo min($page * $per_page, $total_projects); ?> of 
                        <?php echo number_format($total_projects); ?> results
                    </div>
                    <nav class="flex items-center space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-2 sm:px-3 py-1 border border-gray-300 rounded text-xs sm:text-sm text-gray-700 hover:bg-gray-50">
                                ‹
                            </a>
                        <?php endif; ?>

                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-2 sm:px-3 py-1 border <?php echo $i === $page ? 'border-blue-500 bg-blue-50 text-blue-600' : 'border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded text-xs sm:text-sm">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-2 sm:px-3 py-1 border border-gray-300 rounded text-xs sm:text-sm text-gray-700 hover:bg-gray-50">
                                ›
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/adminFooter.php'; ?>