<?php
//Step-based progress calculation functions
// Update project progress based on step progress calculation
function complete_step($step_id) {
    require_once 'projectProgressCalculator.php';
    return update_step_status_with_progress_recalc($step_id, 'completed');
}

// Mark step as incomplete
function incomplete_step($step_id) {
    require_once 'projectProgressCalculator.php';
    return update_step_status_with_progress_recalc($step_id, 'pending');
}

/**
 * Mark step as in progress
 */
function mark_step_in_progress($step_id) {
    require_once 'projectProgressCalculator.php';
    return update_step_status_with_progress_recalc($step_id, 'in_progress');
}
?>