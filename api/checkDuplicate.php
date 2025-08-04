<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/encryptionManager.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['project_name']) || empty(trim($input['project_name']))) {
    echo json_encode(['exists' => false]);
    exit;
}

$project_name = trim($input['project_name']);
$department_id = $input['department_id'] ?? null;
$project_year = $input['project_year'] ?? null;
$total_budget = $input['total_budget'] ?? null;
$ward_id = $input['ward_id'] ?? null;
$sub_county_id = $input['sub_county_id'] ?? null;

try {
    $duplicate_checks = [];
    $em = new EncryptionManager($pdo);

    // Get all projects that are potential matches
    $query = "SELECT id, project_name, project_year, department_id, total_budget, ward_id, sub_county_id 
              FROM projects";
    $projects = $em->processDataForReading($query, [], 'projects');

    foreach ($projects as $p) {
        $is_exact = false;
        $is_similar = false;
        $messages = [];

        // Normalize comparison
        $input_name = strtolower($project_name);
        $db_name = strtolower($p['project_name']);

        // Check 1: Exact same project name in same department and year
        if (
            $p['department_id'] == $department_id &&
            $p['project_year'] == $project_year &&
            $input_name === $db_name
        ) {
            $duplicate_checks[] = [
                'type' => 'exact_match',
                'message' => "A project with this exact name already exists in the same department and year",
                'severity' => 'high',
                'project_id' => $p['id']
            ];
            continue;
        }

        // Check 2: Similar name (90%) within department
        if (
            $p['department_id'] == $department_id &&
            $input_name !== $db_name
        ) {
            $similarity = similarity($input_name, $db_name);
            if ($similarity >= 0.9) {
                $duplicate_checks[] = [
                    'type' => 'similar_name',
                    'message' => "Very similar project name found: '{$p['project_name']}'",
                    'severity' => 'medium',
                    'similarity' => round($similarity * 100, 1) . '%',
                    'project_id' => $p['id']
                ];
            }
        }

        // Check 3: Similar budget in same location
        if (
            $total_budget && $total_budget > 0 &&
            $p['project_name'] !== $project_name
        ) {
            $budget_tolerance = $total_budget * 0.05;
            if (
                abs($p['total_budget'] - $total_budget) <= $budget_tolerance &&
                (
                    ($ward_id && $p['ward_id'] == $ward_id) ||
                    (!$ward_id && $sub_county_id && $p['sub_county_id'] == $sub_county_id)
                )
            ) {
                $duplicate_checks[] = [
                    'type' => 'budget_location_match',
                    'message' => "Similar budget (KES " . number_format($p['total_budget']) . ") in same location: '{$p['project_name']}'",
                    'severity' => 'medium',
                    'project_id' => $p['id']
                ];
            }
        }

        // Check 4: Multiple matching criteria (70%+ similarity in name + department + year)
        if (
            $p['department_id'] == $department_id &&
            $p['project_year'] == $project_year &&
            $input_name !== $db_name
        ) {
            $similarity = similarity($input_name, $db_name);
            if ($similarity >= 0.7) {
                $duplicate_checks[] = [
                    'type' => 'multiple_criteria',
                    'message' => "Potential duplicate in same department and year: '{$p['project_name']}'",
                    'severity' => 'medium',
                    'similarity' => round($similarity * 100, 1) . '%',
                    'project_id' => $p['id']
                ];
            }
        }
    }

    // Determine overall duplicate status
    $has_high_severity = false;
    $has_medium_severity = false;

    foreach ($duplicate_checks as $check) {
        if ($check['severity'] === 'high') {
            $has_high_severity = true;
            break;
        } elseif ($check['severity'] === 'medium') {
            $has_medium_severity = true;
        }
    }

    $response = [
        'exists' => $has_high_severity,
        'warnings' => $has_medium_severity && !$has_high_severity,
        'checks' => $duplicate_checks,
        'total_issues' => count($duplicate_checks),
        'recommendation' => getDuplicateRecommendation($duplicate_checks)
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Enhanced duplicate check error: " . $e->getMessage());
    echo json_encode([
        'exists' => false,
        'error' => 'Unable to verify project details. Please try again.',
        'checks' => [],
        'total_issues' => 0
    ]);
}

// String similarity function
function similarity($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));

    if ($str1 === $str2) return 1.0;

    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 1.0;

    $distance = levenshtein($str1, $str2);
    return 1 - ($distance / $maxLen);
}

// Recommendation generator
function getDuplicateRecommendation($checks) {
    if (empty($checks)) {
        return 'No duplicate concerns found. You can proceed with creating this project.';
    }

    $high_severity = array_filter($checks, fn($c) => $c['severity'] === 'high');
    if ($high_severity) {
        return 'This project appears to be a duplicate. Please verify or modify the name.';
    }

    $medium_severity = array_filter($checks, fn($c) => $c['severity'] === 'medium');
    if ($medium_severity) {
        return 'Similar projects detected. Please review and confirm uniqueness.';
    }

    return 'Minor similarities found. Please verify details.';
}
?>