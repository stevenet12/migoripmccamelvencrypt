<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/rbac.php';

// Require authentication and permission
require_admin();
if (!has_permission('view_reports')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$current_admin = get_current_admin();

try {
    $sub_county_name = $_GET['sub_county'] ?? '';
    
    if (empty($sub_county_name)) {
        throw new Exception('Sub-county name is required');
    }
    
    // Role-based filtering
    $where_clause = "";
    $params = [$sub_county_name];
    
    // Non-super admins can only see their own projects
    if ($current_admin['role'] !== 'super_admin') {
        $where_clause = " AND p.created_by = ?";
        $params[] = $current_admin['id'];
    }
    
    // Get projects for the sub-county
    $sql = "
        SELECT 
            p.id,
            p.project_name,
            p.status,
            p.progress_percentage,
            p.total_budget,
            d.name as department_name,
            w.name as ward_name,
            sc.name as sub_county_name,
            p.created_at
        FROM projects p
        JOIN departments d ON p.department_id = d.id
        JOIN wards w ON p.ward_id = w.id
        JOIN sub_counties sc ON p.sub_county_id = sc.id
        WHERE sc.name = ?" . $where_clause . "
        ORDER BY p.project_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $total_projects = count($projects);
    $total_budget = array_sum(array_column($projects, 'total_budget'));
    $avg_progress = $total_projects > 0 ? 
        round(array_sum(array_column($projects, 'progress_percentage')) / $total_projects, 1) : 0;
    
    // Status breakdown
    $status_counts = array_count_values(array_column($projects, 'status'));
    
    $summary = [
        'total_projects' => $total_projects,
        'total_budget' => $total_budget,
        'avg_progress' => $avg_progress,
        'status_breakdown' => $status_counts
    ];
    
    // Format projects for display
    foreach ($projects as &$project) {
        $project['progress_percentage'] = (float) $project['progress_percentage'];
        $project['total_budget'] = (float) ($project['total_budget'] ?? 0);
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'projects' => $projects,
        'summary' => $summary,
        'sub_county' => $sub_county_name
    ]);
    
} catch (Exception $e) {
    error_log("Get sub-county projects error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load sub-county projects: ' . $e->getMessage()
    ]);
}
