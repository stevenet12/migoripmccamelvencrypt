<?php
require_once '../config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'sub_counties':
            $county_id = intval($_GET['county_id'] ?? 0);
            if ($county_id > 0) {
                try {
                    $sub_counties = get_sub_counties($county_id);
                    if ($sub_counties !== false && is_array($sub_counties)) {
                        json_response(['success' => true, 'data' => $sub_counties]);
                    } else {
                        error_log("Failed to load sub-counties for county_id: " . $county_id);
                        json_response(['success' => false, 'message' => 'No sub-counties found for this county']);
                    }
                } catch (Exception $e) {
                    error_log("Error loading sub-counties: " . $e->getMessage());
                    json_response(['success' => false, 'message' => 'Database error occurred']);
                }
            } else {
                json_response(['success' => false, 'message' => 'Invalid county ID']);
            }
            break;
            
        case 'wards':
            $sub_county_id = intval($_GET['sub_county_id'] ?? 0);
            if ($sub_county_id > 0) {
                try {
                    $wards = get_wards($sub_county_id);
                    if ($wards !== false && is_array($wards)) {
                        json_response(['success' => true, 'data' => $wards]);
                    } else {
                        error_log("Failed to load wards for sub_county_id: " . $sub_county_id);
                        json_response(['success' => false, 'message' => 'No wards found for this sub-county']);
                    }
                } catch (Exception $e) {
                    error_log("Error loading wards: " . $e->getMessage());
                    json_response(['success' => false, 'message' => 'Database error occurred']);
                }
            } else {
                json_response(['success' => false, 'message' => 'Invalid sub-county ID']);
            }
            break;
            
        default:
            json_response(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Locations API Error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load location data. Please try again.'], 500);
}
?>
