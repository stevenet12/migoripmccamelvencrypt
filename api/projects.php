<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../includes/functions.php';

try {
    // Handle coordinates-only request for map previews
    if (isset($_GET['coordinates_only']) && isset($_GET['project_id'])) {
        $project_id = intval($_GET['project_id']);

        $stmt = $pdo->prepare("SELECT location_coordinates FROM projects WHERE id = ? AND visibility = 'published'");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();

        if ($project) {
            echo json_encode([
                'success' => true,
                'coordinates' => $project['location_coordinates']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }
        exit;
    }

    $filters = [];

    if (isset($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }

    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];
    }

    if (isset($_GET['ward']) && !empty($_GET['ward'])) {
        $filters['ward'] = $_GET['ward'];
    }

    if (isset($_GET['department']) && !empty($_GET['department'])) {
        $filters['department'] = $_GET['department'];
    }

    if (isset($_GET['year']) && !empty($_GET['year'])) {
        $filters['year'] = $_GET['year'];
    }

    $projects = get_projects($filters);

    echo json_encode([
        'success' => true,
        'projects' => $projects,
        'count' => count($projects)
    ]);

} catch (Exception $e) {
    error_log("Projects API Error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load projects. Please try again.'], 500);
}
?>