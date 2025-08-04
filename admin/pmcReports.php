<?php
require_once 'includes/pageSecurity.php';
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';

// Require authentication and permission to view reports
require_admin();
if (!hasPagePermission('view_reports')) {
    header('Location: index.php?error=access_denied');
    exit;
}

$current_admin = get_current_admin();

// Log access to reports
log_activity('pmc_reports_access', 'Accessed PMC reports page', $current_admin['id']);

$page_title = "PMC Reports";

include 'includes/adminHeader.php';

// Get report statistics with role-based filtering
try {
    $where_clause = "";
    $params = [];

    // Non-super admins can only see their own projects
    if ($current_admin['role'] !== 'super_admin') {
        $where_clause = " WHERE created_by = ?";
        $params[] = $current_admin['id'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $where_clause);
    $stmt->execute($params);
    $total_projects = $stmt->fetchColumn();

    $completed_params = $params;
    if ($where_clause) {
        $completed_params[] = 'completed';
    } else {
        $completed_params = ['completed'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $where_clause . ($where_clause ? " AND" : " WHERE") . " status = ?");
    $stmt->execute($completed_params);
    $completed_projects = $stmt->fetchColumn();

    $ongoing_params = $params;
    if ($where_clause) {
        $ongoing_params[] = 'ongoing';
    } else {
        $ongoing_params = ['ongoing'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects" . $where_clause . ($where_clause ? " AND" : " WHERE") . " status = ?");
    $stmt->execute($ongoing_params);
    $ongoing_projects = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT SUM(total_budget) FROM projects" . $where_clause . ($where_clause ? " AND" : " WHERE") . " total_budget IS NOT NULL");
    $stmt->execute($params);
    $total_budget = $stmt->fetchColumn() ?: 0;

    // Projects by sub-county with role-based filtering
    $location_sql = "
        SELECT sc.name as sub_county, COUNT(*) as project_count, 
               SUM(p.total_budget) as total_budget,
               AVG(p.progress_percentage) as avg_progress
        FROM projects p 
        JOIN sub_counties sc ON p.sub_county_id = sc.id" . $where_clause . "
        GROUP BY sc.id, sc.name 
        ORDER BY project_count DESC
    ";
    $stmt = $pdo->prepare($location_sql);
    $stmt->execute($params);
    $projects_by_location = $stmt->fetchAll();

    // Recent milestones with role-based filtering
    function getRecentMilestones($where_conditions, $params) {
        global $pdo;

        $where_clause = implode(' AND ', $where_conditions);

        $milestones_sql = "
            SELECT p.project_name, ps.step_name, ps.actual_end_date as completion_date, ps.status
            FROM project_steps ps 
            JOIN projects p ON ps.project_id = p.id
            WHERE {$where_clause} AND ps.actual_end_date IS NOT NULL 
            ORDER BY ps.actual_end_date DESC 
            LIMIT 10
        ";
        $stmt = $pdo->prepare($milestones_sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    $milestone_conditions = ['1=1'];
    $milestone_params = [];

    if ($current_admin['role'] !== 'super_admin') {
        $milestone_conditions[] = 'p.created_by = ?';
        $milestone_params[] = $current_admin['id'];
    }

    $recent_milestones = getRecentMilestones($milestone_conditions, $milestone_params);

    // Pending grievances with role-based filtering
    $grievances_params = $params;
    if ($where_clause) {
        $grievances_params[] = 'pending';
    } else {
        $grievances_params = ['pending'];
    }
    $grievances_sql = "
        SELECT COUNT(*) FROM feedback f
        JOIN projects p ON f.project_id = p.id" . $where_clause . 
        ($where_clause ? " AND" : " WHERE") . " f.status = ?
    ";
    $stmt = $pdo->prepare($grievances_sql);
    $stmt->execute($grievances_params);
    $pending_grievances = $stmt->fetchColumn();

} catch (Exception $e) {
    error_log("PMC Reports Error: " . $e->getMessage());
}

$page_title = "PMC Reports";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => 'index.php'],
    ['title' => 'PMC Reports']
];

ob_start();
?>

<style>
/* Mobile-first responsive design for PMC Reports */
.reports-container {
    background: #f8f9fa;
    padding: 1rem;
}

.main-card {
    background: #ffffff !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 1rem;
}

.card-content {
    background: #ffffff !important;
    padding: 1.5rem;
}

.filter-form {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.filter-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
}

.filter-input {
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 0.875rem;
}

.download-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.btn-download {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.btn-pdf {
    background: #dc2626;
    color: white;
}

.btn-pdf:hover {
    background: #b91c1c;
}

.btn-excel {
    background: #16a34a;
    color: white;
}

.btn-excel:hover {
    background: #15803d;
}

.btn-csv {
    background: #2563eb;
    color: white;
}

.btn-csv:hover {
    background: #1d4ed8;
}

.location-table-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 1rem 0;
}

.location-table {
    width: 100%;
    min-width: 500px;
    border-collapse: collapse;
    background: white;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.location-table th {
    background: #f8f9fa;
    padding: 0.75rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.875rem;
}

.location-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.875rem;
}

.location-table tr:hover {
    background: #f9fafb;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #10b981;
    border-radius: 4px;
}

.milestone-item {
    padding: 0.75rem;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
}

.milestone-header {
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.milestone-project {
    color: #6b7280;
    font-size: 0.8125rem;
    margin-bottom: 0.25rem;
}

.milestone-date {
    color: #9ca3af;
    font-size: 0.75rem;
}

@media (min-width: 768px) {
    .filter-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .download-buttons {
        flex-wrap: nowrap;
    }
}

@media (min-width: 1024px) {
    .filter-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>

<!-- Breadcrumb -->
<div class="mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li class="text-gray-600 font-medium">
                <i class="fas fa-home mr-1"></i> Dashboard
            </li>
            <li class="text-gray-400">/</li>
            <li class="text-gray-600 font-medium">PMC Reports</li>
        </ol>
    </nav>
</div>

<div class="reports-container bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">
<!-- Filter Form with Download Options -->
    <div class="main-card">
        <div class="card-content">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Filter Data for Download</h3>

            <form id="reportFilterForm" class="filter-form">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label for="status_filter">Project Status</label>
                        <select id="status_filter" name="status" class="filter-input">
                            <option value="">All Status</option>
                            <option value="planning">Planning</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="completed">Completed</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="department_filter">Department</label>
                        <select id="department_filter" name="department" class="filter-input">
                            <option value="">All Departments</option>
                            <?php
                            $dept_sql = "SELECT id, name FROM departments ORDER BY name";
                            $dept_stmt = $pdo->prepare($dept_sql);
                            $dept_stmt->execute();
                            $departments = $dept_stmt->fetchAll();
                            foreach ($departments as $dept) {
                                echo '<option value="' . $dept['id'] . '">' . htmlspecialchars($dept['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sub_county_filter">Sub-County</label>
                        <select id="sub_county_filter" name="sub_county" class="filter-input">
                            <option value="">All Sub-Counties</option>
                            <?php
                            $sc_sql = "SELECT id, name FROM sub_counties ORDER BY name";
                            $sc_stmt = $pdo->prepare($sc_sql);
                            $sc_stmt->execute();
                            $sub_counties = $sc_stmt->fetchAll();
                            foreach ($sub_counties as $sc) {
                                echo '<option value="' . $sc['id'] . '">' . htmlspecialchars($sc['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" class="filter-input">
                            <option value="project_summary">Project Summary Report</option>
                            <option value="project_progress">Project Progress Report</option>
                            <option value="financial_summary">Financial Summary Report</option>
                            <option value="grievance_summary">Grievance & Feedback Report</option>
                        </select>
                    </div>
                </div>

                <div class="download-buttons">
                    <button type="button" onclick="exportReport('pdf')" class="btn-download btn-pdf">
                        <i class="fas fa-file-pdf"></i>
                        Download PDF
                    </button>
                    <button type="button" onclick="exportReport('excel')" class="btn-download btn-excel">
                        <i class="fas fa-file-excel"></i>
                        Download Excel
                    </button>
                    <button type="button" onclick="exportReport('csv')" class="btn-download btn-csv">
                        <i class="fas fa-file-csv"></i>
                        Download CSV
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Projects by Sub-County -->
    <div class="main-card">
        <div class="card-content">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Projects by Sub-County</h3>
            </div>

            <div class="location-table-container">
                <table class="location-table">
                    <thead>
                        <tr>
                            <th>Sub-County</th>
                            <th>Projects</th>
                            <th>Budget (KES)</th>
                            <th>Avg. Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($projects_by_location)): ?>
                            <?php foreach ($projects_by_location as $location): ?>
                                <tr class="cursor-pointer hover:bg-blue-50" onclick="showSubCountyDetails('<?php echo htmlspecialchars($location['sub_county']); ?>')">
                                    <td class="font-medium text-gray-900">
                                        <div class="flex items-center">
                                            <?php echo htmlspecialchars($location['sub_county']); ?>
                                            <i class="fas fa-eye ml-2 text-blue-500 text-sm"></i>
                                        </div>
                                    </td>
                                    <td class="text-gray-900">
                                        <?php echo number_format($location['project_count']); ?>
                                    </td>
                                    <td class="text-gray-900">
                                        <?php echo number_format($location['total_budget'] ?? 0); ?>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <div class="progress-bar flex-1">
                                                <div class="progress-fill" style="width: <?php echo $location['avg_progress']; ?>%"></div>
                                            </div>
                                            <span class="text-sm text-gray-900 min-w-0"><?php echo round($location['avg_progress'] ?? 0, 1); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-gray-500 py-4">No data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Milestones and Quick Statistics Side by Side -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Milestones -->
        <div class="main-card">
            <div class="card-content">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Recent Project Milestones</h3>
                </div>

                <div>
                    <?php if (!empty($recent_milestones)): ?>
                        <div class="space-y-2">
                            <?php foreach ($recent_milestones as $milestone): ?>
                                <div class="milestone-item">
                                    <div class="flex items-start gap-3">
                                        <div class="w-6 h-6 bg-green-500 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="milestone-header"><?php echo htmlspecialchars($milestone['project_name']); ?></div>
                                            <div class="milestone-project"><?php echo htmlspecialchars($milestone['step_name']); ?></div>
                                            <div class="milestone-date">
                                                <?php echo date('M d, Y', strtotime($milestone['completion_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-calendar-check text-3xl text-gray-400 mb-2"></i>
                            <p class="text-gray-500">No recent milestones</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Statistics -->
        <div class="main-card">
            <div class="card-content">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Quick Statistics</h3>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600 text-sm">Project Completion Rate</span>
                        <span class="font-semibold text-green-600 text-sm">
                            <?php echo $total_projects > 0 ? round(($completed_projects / $total_projects) * 100, 1) : 0; ?>%
                        </span>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600 text-sm">Pending Grievances</span>
                        <span class="font-semibold text-red-600 text-sm"><?php echo $pending_grievances ?? 0; ?></span>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600 text-sm">Sub-Counties Covered</span>
                        <span class="font-semibold text-blue-600 text-sm"><?php echo count($projects_by_location ?? []); ?></span>
                    </div>

                    <div class="flex items-center justify-between py-2">
                        <span class="text-gray-600 text-sm">Average Progress</span>
                        <span class="font-semibold text-blue-600 text-sm">
                            <?php 
                            $avg_progress = 0;
                            if (!empty($projects_by_location)) {
                                $total_progress = array_sum(array_column($projects_by_location, 'avg_progress'));
                                $avg_progress = round($total_progress / count($projects_by_location), 1);
                            }
                            echo $avg_progress; 
                            ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sub-County Details Modal -->
<div id="subCountyModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900" id="modalTitle">Sub-County Projects</h3>
                    <button onclick="closeSubCountyModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div id="modalContent" class="space-y-4">
                    <!-- Content will be loaded here -->
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-2xl text-gray-400 mb-2"></i>
                        <p class="text-gray-500">Loading projects...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Export Report Functions
function exportReport(format) {
    const form = document.getElementById('reportFilterForm');
    const formData = new FormData(form);

    // Add format to form data
    formData.append('format', format);

    // Build URL with parameters
    const params = new URLSearchParams();
    for (let [key, value] of formData.entries()) {
        if (value) {
            params.append(key, value);
        }
    }

    // Show loading state
    const buttons = document.querySelectorAll('.btn-download');
    buttons.forEach(btn => {
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

        // Reset after 3 seconds
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }, 3000);
    });

    // Create download link
    $url = `../api/exportReports.php?${params.toString()}`;
    const link = document.createElement('a');
    link.href = $url;
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Sub-County Quick View Functions
async function showSubCountyDetails(subCountyName) {
    const modal = document.getElementById('subCountyModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');

    modalTitle.textContent = `Projects in ${subCountyName}`;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Show loading state
    modalContent.innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-2xl text-gray-400 mb-2"></i>
            <p class="text-gray-500">Loading projects...</p>
        </div>
    `;

    try {
        const response = await fetch(`../api/getSubCountyProjects.php?sub_county=${encodeURIComponent(subCountyName)}`);
        const data = await response.json();

        if (data.success) {
            renderSubCountyProjects(data.projects, data.summary);
        } else {
            throw new Error(data.message || 'Failed to load projects');
        }
    } catch (error) {
        console.error('Error loading sub-county projects:', error);
        modalContent.innerHTML = `
            <div class="text-center py-8 text-red-600">
                <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                <p>Failed to load projects</p>
            </div>
        `;
    }
}

function renderSubCountyProjects(projects, summary) {
    const modalContent = document.getElementById('modalContent');

    let html = `
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="text-center p-4 bg-blue-50 rounded-lg">
                <div class="text-2xl font-bold text-blue-600">${summary.total_projects}</div>
                <div class="text-sm text-blue-600">Total Projects</div>
            </div>
            <div class="text-center p-4 bg-green-50 rounded-lg">
                <div class="text-2xl font-bold text-green-600">KES ${formatNumber(summary.total_budget)}</div>
                <div class="text-sm text-green-600">Total Budget</div>
            </div>
            <div class="text-center p-4 bg-yellow-50 rounded-lg">
                <div class="text-2xl font-bold text-yellow-600">${summary.avg_progress}%</div>
                <div class="text-sm text-yellow-600">Avg. Progress</div>
            </div>
        </div>
    `;

    if (projects.length > 0) {
        html += `
            <div class="space-y-3">
                <h4 class="font-medium text-gray-900 mb-3">Project Details</h4>
        `;

        projects.forEach(project => {
            const statusBadge = getStatusBadgeClass(project.status);
            const progressColor = getProgressColor(project.progress_percentage);

            html += `
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex-1">
                            <h5 class="font-medium text-gray-900 mb-1">${escapeHtml(project.project_name)}</h5>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${statusBadge}">
                                    ${project.status.charAt(0).toUpperCase() + project.status.slice(1)}
                                </span>
                                <span class="text-sm text-gray-600">${escapeHtml(project.department_name)}</span>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-gray-600">
                                <span><i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(project.ward_name)}</span>
                                <span><i class="fas fa-money-bill-wave mr-1"></i>KES ${formatNumber(project.total_budget || 0)}</span>
                            </div>
                        </div>
                        <div class="md:w-32">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs text-gray-500">Progress</span>
                                <span class="text-xs font-medium">${project.progress_percentage}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full ${progressColor}" style="width: ${project.progress_percentage}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
    } else {
        html += `
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p>No projects found for this sub-county</p>
            </div>
        `;
    }

    modalContent.innerHTML = html;
}

function closeSubCountyModal() {
    const modal = document.getElementById('subCountyModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function getStatusBadgeClass(status) {
    const classes = {
        planning: 'bg-yellow-100 text-yellow-800',
        ongoing: 'bg-blue-100 text-blue-800',
        completed: 'bg-green-100 text-green-800',
        suspended: 'bg-orange-100 text-orange-800',
        cancelled: 'bg-red-100 text-red-800'
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
}

function getProgressColor(percentage) {
    if (percentage >= 80) return 'bg-green-500';
    if (percentage >= 60) return 'bg-blue-500';
    if (percentage >= 40) return 'bg-yellow-500';
    if (percentage >= 20) return 'bg-orange-500';
    return 'bg-red-500';
}

function formatNumber(num) {
    return new Intl.NumberFormat('en-KE').format(num || 0);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSubCountyModal();
    }
});
</script>

<?php
include 'includes/adminFooter.php';
?>