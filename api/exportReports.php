<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/rbac.php';

// Include Composer autoload for vendor libraries
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
}

// Require authentication
require_admin();

// Load composer autoloader
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Set memory limit and execution time for large exports
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

try {
    $report_type = $_GET['report_type'] ?? 'project_summary';
    $format = $_GET['format'] ?? 'pdf';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $department = $_GET['department'] ?? '';
    $sub_county = $_GET['sub_county'] ?? '';
    $status = $_GET['status'] ?? '';
    $year = $_GET['year'] ?? '';
    $min_budget = $_GET['min_budget'] ?? '';
    $max_budget = $_GET['max_budget'] ?? '';

    $current_admin = get_current_admin();

    // Validate report type
    $valid_reports = ['project_summary', 'financial_summary', 'progress_tracking', 'grievance_feedback', 'pmc_summary', 'project_progress', 'grievance_summary'];
    if (!in_array($report_type, $valid_reports)) {
        throw new Exception('Invalid report type');
    }

    // Validate format
    $valid_formats = ['pdf', 'excel', 'csv'];
    if (!in_array($format, $valid_formats)) {
        throw new Exception('Invalid export format');
    }

    // Build filters array
    $filters = [
        'report_type' => $report_type,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'department' => $department,
        'sub_county' => $sub_county,
        'status' => $status,
        'year' => $year,
        'min_budget' => $min_budget,
        'max_budget' => $max_budget
    ];

    // Get data based on report type
    $data = getReportData($report_type, $filters, $current_admin);

    // Generate export based on format
    switch ($format) {
        case 'pdf':
            exportToPDF($report_type, $data, $filters);
            break;
        case 'excel':
            exportToExcel($report_type, $data, $filters);
            break;
        case 'csv':
            exportToCSV($report_type, $data, $filters);
            break;
    }

} catch (Exception $e) {
    error_log("Export Reports Error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

function getReportData($report_type, $filters, $current_admin) {
    global $pdo;

    $where_conditions = ['1=1'];
    $params = [];

    // Role-based filtering
    if ($current_admin['role'] !== 'super_admin') {
        $where_conditions[] = 'p.created_by = ?';
        $params[] = $current_admin['id'];
    }

    // Apply filters
    if (!empty($filters['start_date'])) {
        $where_conditions[] = 'p.created_at >= ?';
        $params[] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $where_conditions[] = 'p.created_at <= ?';
        $params[] = $filters['end_date'] . ' 23:59:59';
    }

    if (!empty($filters['department'])) {
        $where_conditions[] = 'p.department_id = ?';
        $params[] = $filters['department'];
    }

    if (!empty($filters['sub_county'])) {
        $where_conditions[] = 'p.sub_county_id = ?';
        $params[] = $filters['sub_county'];
    }

    if (!empty($filters['status'])) {
        $where_conditions[] = 'p.status = ?';
        $params[] = $filters['status'];
    }

    if (!empty($filters['year'])) {
        $where_conditions[] = 'p.project_year = ?';
        $params[] = $filters['year'];
    }

    if (!empty($filters['min_budget'])) {
        $where_conditions[] = 'COALESCE(p.total_budget, 0) >= ?';
        $params[] = $filters['min_budget'];
    }

    if (!empty($filters['max_budget'])) {
        $where_conditions[] = 'COALESCE(p.total_budget, 0) <= ?';
        $params[] = $filters['max_budget'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    switch ($report_type) {
        case 'pmc_summary':
        case 'project_summary':
            $sql = "SELECT p.*, d.name as department_name, w.name as ward_name, 
                           sc.name as sub_county_name, c.name as county_name,
                           COALESCE(p.total_budget, 0) as budget_amount,
                           (SELECT COUNT(*) FROM project_steps ps WHERE ps.project_id = p.id) as total_steps,
                           (SELECT COUNT(*) FROM project_steps ps WHERE ps.project_id = p.id AND ps.status = 'completed') as completed_steps,
                           p.progress_percentage,
                           p.start_date,
                           p.expected_completion_date,
                           p.contractor_name,
                           DATE_FORMAT(p.created_at, '%Y-%m-%d') as created_date
                    FROM projects p
                    LEFT JOIN departments d ON p.department_id = d.id
                    LEFT JOIN wards w ON p.ward_id = w.id
                    LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id
                    LEFT JOIN counties c ON p.county_id = c.id
                    WHERE {$where_clause}
                    ORDER BY p.created_at DESC";
            break;

        case 'project_progress':
        case 'progress_tracking':
            $sql = "SELECT p.*, d.name as department_name, w.name as ward_name, 
                           sc.name as sub_county_name,
                           COALESCE(COUNT(ps.id), 0) as total_steps,
                           COALESCE(SUM(CASE WHEN ps.status = 'completed' THEN 1 ELSE 0 END), 0) as completed_steps,
                           COALESCE(SUM(CASE WHEN ps.status = 'in_progress' THEN 1 ELSE 0 END), 0) as in_progress_steps,
                           COALESCE(SUM(CASE WHEN ps.status = 'pending' THEN 1 ELSE 0 END), 0) as pending_steps,
                           p.progress_percentage,
                           p.start_date,
                           p.expected_completion_date,
                           DATE_FORMAT(p.created_at, '%Y-%m-%d') as created_date
                    FROM projects p
                    LEFT JOIN departments d ON p.department_id = d.id
                    LEFT JOIN wards w ON p.ward_id = w.id
                    LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id
                    LEFT JOIN project_steps ps ON p.id = ps.project_id
                    WHERE {$where_clause}
                    GROUP BY p.id, p.project_name, p.status, p.progress_percentage, p.start_date, p.expected_completion_date, p.created_at, d.name, w.name, sc.name
                    ORDER BY p.progress_percentage DESC, p.created_at DESC";
            break;

        case 'financial_summary':
            $sql = "SELECT p.*, d.name as department_name, w.name as ward_name, 
                           sc.name as sub_county_name,
                           COALESCE(p.total_budget, 0) as initial_budget,
                           COALESCE(SUM(CASE WHEN pt.transaction_type = 'allocation' AND pt.transaction_status = 'active' THEN pt.amount ELSE 0 END), 0) as additional_allocation,
                           COALESCE(SUM(CASE WHEN pt.transaction_type = 'disbursement' AND pt.transaction_status = 'active' THEN pt.amount ELSE 0 END), 0) as disbursed,
                           COALESCE(SUM(CASE WHEN pt.transaction_type = 'expenditure' AND pt.transaction_status = 'active' THEN pt.amount ELSE 0 END), 0) as spent,
                           DATE_FORMAT(p.created_at, '%Y-%m-%d') as created_date
                    FROM projects p
                    LEFT JOIN departments d ON p.department_id = d.id
                    LEFT JOIN wards w ON p.ward_id = w.id
                    LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id
                    LEFT JOIN project_transactions pt ON p.id = pt.project_id
                    WHERE {$where_clause}
                    GROUP BY p.id, p.project_name, p.total_budget, p.created_at, d.name, w.name, sc.name
                    ORDER BY initial_budget DESC";
            break;

        case 'grievance_summary':
        case 'grievance_feedback':
            // For grievance reports, we need different filtering logic
            $grievance_where_conditions = ['1=1'];
            $grievance_params = [];

            // Role-based filtering for grievances
            if ($current_admin['role'] !== 'super_admin') {
                $grievance_where_conditions[] = 'p.created_by = ?';
                $grievance_params[] = $current_admin['id'];
            }

            // Apply grievance-specific filters
            if (!empty($filters['start_date'])) {
                $grievance_where_conditions[] = 'f.created_at >= ?';
                $grievance_params[] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $grievance_where_conditions[] = 'f.created_at <= ?';
                $grievance_params[] = $filters['end_date'] . ' 23:59:59';
            }

            if (!empty($filters['department'])) {
                $grievance_where_conditions[] = 'p.department_id = ?';
                $grievance_params[] = $filters['department'];
            }

            if (!empty($filters['sub_county'])) {
                $grievance_where_conditions[] = 'p.sub_county_id = ?';
                $grievance_params[] = $filters['sub_county'];
            }

            if (!empty($filters['status'])) {
                $grievance_where_conditions[] = 'f.status = ?';
                $grievance_params[] = $filters['status'];
            }

            $grievance_where_clause = implode(' AND ', $grievance_where_conditions);

            $sql = "SELECT f.*, p.project_name, d.name as department_name, 
                           w.name as ward_name, sc.name as sub_county_name,
                           a.name as responded_by_name,
                           f.citizen_name,
                           f.subject,
                           f.message,
                           f.status as feedback_status,
                           f.admin_response ,
                           DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i') as date_received,
                           DATE_FORMAT(f.responded_at, '%Y-%m-%d %H:%i') as response_date
                    FROM feedback f
                    JOIN projects p ON f.project_id = p.id
                    LEFT JOIN departments d ON p.department_id = d.id
                    LEFT JOIN wards w ON p.ward_id = w.id
                    LEFT JOIN sub_counties sc ON p.sub_county_id = sc.id
                    LEFT JOIN admins a ON f.responded_by = a.id
                    WHERE {$grievance_where_clause}
                    ORDER BY f.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($grievance_params);
            return $stmt->fetchAll();

        default:
            throw new Exception('Invalid report type');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function exportToPDF($report_type, $data, $filters) {
    // Create simple HTML output instead of TCPDF to avoid errors
    $filename = "{$report_type}_report_" . date('Y-m-d') . ".pdf";

    // Use wkhtmltopdf or similar for better PDF generation
    // For now, create an HTML page that can be printed to PDF
    $html = generatePDFHTML($report_type, $data, $filters);

    header('Content-Type: text/html');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    echo $html;
}

function exportToExcel($report_type, $data, $filters) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('Migori County PMC')
        ->setLastModifiedBy('Migori County PMC')
        ->setTitle(ucfirst(str_replace('_', ' ', $report_type)) . ' Report')
        ->setSubject('Project Management Report')
        ->setDescription('Generated from Migori County Project Management System');

    // Set headers based on report type
    $headers = getExcelHeaders($report_type);
    $sheet->fromArray($headers, null, 'A1');

    // Style headers
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e40af']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ];

    $lastColumn = chr(64 + count($headers));
    $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

    // Add data rows
    $row = 2;
    foreach ($data as $item) {
        $rowData = getExcelRowData($report_type, $item);
        $sheet->fromArray($rowData, null, 'A' . $row);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', $lastColumn) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output Excel file
    $filename = "{$report_type}_report_" . date('Y-m-d') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}

function exportToCSV($report_type, $data, $filters) {
    $filename = "{$report_type}_report_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Add BOM for proper Excel UTF-8 handling
    echo chr(0xEF) . chr(0xBB) . chr(0xBF);

    $output = fopen('php://output', 'w');

    // Write headers
    $headers = getExcelHeaders($report_type);
    fputcsv($output, $headers);

    // Write data rows
    foreach ($data as $item) {
        $rowData = getExcelRowData($report_type, $item);
        fputcsv($output, $rowData);
    }

    fclose($output);
}

function getExcelHeaders($report_type) {
    switch ($report_type) {
        case 'pmc_summary':
        case 'project_summary':
            return ['Project Name', 'Department', 'Ward', 'Sub County', 'Status', 'Year', 'Budget (KES)', 'Progress %', 'Total Steps', 'Completed Steps', 'Contractor', 'Start Date', 'Expected End Date', 'Created Date'];
        case 'financial_summary':
            return ['Project Name', 'Department', 'Ward', 'Sub County', 'Approved Budget (KES)', 'Additional Allocation (KES)', 'Disbursed (KES)', 'Spent (KES)', 'Remaining (KES)', 'Utilization %', 'Created Date'];
        case 'project_progress':
        case 'progress_tracking':
            return ['Project Name', 'Department', 'Ward', 'Sub County', 'Status', 'Progress %', 'Total Steps', 'Completed Steps', 'In Progress Steps', 'Pending Steps', 'Start Date', 'Expected End Date', 'Created Date'];
        case 'grievance_summary':
        case 'grievance_feedback':
            return ['Project Name', 'Citizen Name', 'Subject', 'Message', 'Status', 'Department', 'Ward', 'Sub County', 'Date Received', 'Responded By', 'Response Date', 'admin_response '];
        default:
            return ['Data'];
    }
}

function getExcelRowData($report_type, $item) {
    switch ($report_type) {
        case 'pmc_summary':
        case 'project_summary':
            return [
                $item['project_name'] ?? '',
                $item['department_name'] ?? '',
                $item['ward_name'] ?? '',
                $item['sub_county_name'] ?? '',
                ucfirst($item['status'] ?? ''),
                $item['project_year'] ?? '',
                $item['budget_amount'] ?? 0,
                $item['progress_percentage'] ?? 0,
                $item['total_steps'] ?? 0,
                $item['completed_steps'] ?? 0,
                $item['contractor_name'] ?? 'N/A',
                $item['start_date'] ?? 'N/A',
                $item['expected_completion_date'] ?? 'N/A',
                $item['created_date'] ?? ''
            ];
        case 'financial_summary':
            $initial_budget = $item['initial_budget'] ?? 0;
            $additional_allocation = $item['additional_allocation'] ?? 0;
            $approved_budget = $initial_budget + $additional_allocation; // Total approved budget
            $disbursed = $item['disbursed'] ?? 0;
            $spent = $item['spent'] ?? 0;
            $remaining = $approved_budget - $spent;
            $utilization = $approved_budget > 0 ? ($spent / $approved_budget) * 100 : 0;
            return [
                $item['project_name'] ?? '',
                $item['department_name'] ?? '',
                $item['ward_name'] ?? '',
                $item['sub_county_name'] ?? '',
                $approved_budget,
                $additional_allocation,
                $disbursed,
                $spent,
                $remaining,
                round($utilization, 2),
                $item['created_date'] ?? ''
            ];
        case 'project_progress':
        case 'progress_tracking':
            return [
                $item['project_name'] ?? '',
                $item['department_name'] ?? '',
                $item['ward_name'] ?? '',
                $item['sub_county_name'] ?? '',
                ucfirst($item['status'] ?? ''),
                $item['progress_percentage'] ?? 0,
                $item['total_steps'] ?? 0,
                $item['completed_steps'] ?? 0,
                $item['in_progress_steps'] ?? 0,
                $item['pending_steps'] ?? 0,
                $item['start_date'] ?? 'N/A',
                $item['expected_completion_date'] ?? 'N/A',
                $item['created_date'] ?? ''
            ];
        case 'grievance_summary':
        case 'grievance_feedback':
            return [
                $item['project_name'] ?? '',
                $item['citizen_name'] ?? '',
                $item['subject'] ?? '',
                substr($item['message'] ?? '', 0, 100), // Truncate long messages
                ucfirst($item['feedback_status'] ?? $item['status'] ?? ''),
                $item['department_name'] ?? '',
                $item['ward_name'] ?? '',
                $item['sub_county_name'] ?? '',
                $item['date_received'] ?? '',
                $item['responded_by_name'] ?? 'N/A',
                $item['response_date'] ?? 'N/A',
                substr($item['admin_response '] ?? '', 0, 100) // Truncate long responses
            ];
        default:
            return [$item];
    }
}

function generatePDFHTML($report_type, $data, $filters) {
    $report_titles = [
        'project_summary' => 'Project Summary Report',
        'pmc_summary' => 'PMC Summary Report',
        'project_progress' => 'Project Progress Report',
        'progress_tracking' => 'Project Progress Report',
        'financial_summary' => 'Financial Summary Report',
        'grievance_summary' => 'Grievance & Feedback Report',
        'grievance_feedback' => 'Grievance & Feedback Report'
    ];

    $title = $report_titles[$report_type] ?? ucfirst(str_replace('_', ' ', $report_type)) . ' Report';
    $date_range = '';
    if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
        $date_range = 'Date Range: ' . ($filters['start_date'] ?: 'Beginning') . ' to ' . ($filters['end_date'] ?: 'Present');
    }

    // Calculate summary statistics
    $total_records = count($data);
    $summary_stats = '';

    if ($report_type === 'financial_summary') {
        $total_initial_budget = array_sum(array_column($data, 'initial_budget'));
        $total_additional_allocation = array_sum(array_column($data, 'additional_allocation'));
        $total_approved_budget = $total_initial_budget + $total_additional_allocation;
        $total_spent = array_sum(array_column($data, 'spent'));
        $overall_utilization = $total_approved_budget > 0 ? ($total_spent / $total_approved_budget) * 100 : 0;
        $summary_stats = '<p><strong>Total Approved Budget:</strong> KES ' . number_format($total_approved_budget) . '</p>
                         <p><strong>Total Additional Allocation:</strong> KES ' . number_format($total_additional_allocation) . '</p>
                         <p><strong>Total Spent:</strong> KES ' . number_format($total_spent) . '</p>
                         <p><strong>Overall Utilization:</strong> ' . round($overall_utilization, 2) . '%</p>';
    } elseif ($report_type === 'project_progress' || $report_type === 'progress_tracking') {
        $avg_progress = $total_records > 0 ? array_sum(array_column($data, 'progress_percentage')) / $total_records : 0;
        $total_steps = array_sum(array_column($data, 'total_steps'));
        $completed_steps = array_sum(array_column($data, 'completed_steps'));
        $summary_stats = '<p><strong>Average Progress:</strong> ' . round($avg_progress, 1) . '%</p>
                         <p><strong>Total Steps:</strong> ' . $total_steps . '</p>
                         <p><strong>Completed Steps:</strong> ' . $completed_steps . '</p>';
    } elseif ($report_type === 'grievance_summary' || $report_type === 'grievance_feedback') {
        $pending = count(array_filter($data, function($item) { return ($item['feedback_status'] ?? $item['status'] ?? '') === 'pending'; }));
        $resolved = count(array_filter($data, function($item) { return ($item['feedback_status'] ?? $item['status'] ?? '') === 'resolved'; }));
        $summary_stats = '<p><strong>Pending Grievances:</strong> ' . $pending . '</p>
                         <p><strong>Resolved Grievances:</strong> ' . $resolved . '</p>';
    }

    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . $title . '</title>
        <style>
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
            body { 
                font-family: Arial, sans-serif; 
                font-size: 12px; 
                line-height: 1.4;
                margin: 20px;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                border-bottom: 2px solid #1e40af; 
                padding-bottom: 20px; 
            }
            .logo {
                max-width: 80px;
                height: auto;
                margin-bottom: 15px;
            }
            .header h1 { 
                color: #1e40af; 
                margin: 0; 
                font-size: 24px; 
            }
            .header h2 { 
                color: #1e40af; 
                margin: 10px 0; 
                font-size: 18px; 
            }
            .date-range { 
                color: #666; 
                margin: 10px 0; 
            }
            .summary { 
                background-color: #f8f9fa; 
                padding: 15px; 
                margin: 20px 0; 
                border-radius: 5px; 
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
            }
            th, td { 
                border: 1px solid #ddd; 
                padding: 8px; 
                text-align: left; 
                font-size: 11px;
            }
            th { 
                background-color: #1e40af; 
                color: white; 
                font-weight: bold; 
            }
            tr:nth-child(even) { 
                background-color: #f8f9fa; 
            }
            .footer { 
                margin-top: 30px; 
                text-align: center; 
                color: #666; 
                font-size: 10px; 
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }
            .print-btn {
                background: #1e40af;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
        </div>

        <div class="header">
            <img src="../migoriLogo.png" alt="Migori County Logo" class="logo">
            <h1>Migori County Project Management Committee</h1>
            <h2>' . $title . '</h2>
            <div class="date-range">' . $date_range . '</div>
            <div>Generated on: ' . date('F j, Y \a\t g:i A') . '</div>
        </div>

        <div class="summary">
            <h3>Summary Statistics</h3>
            <p><strong>Total Records:</strong> ' . $total_records . '</p>
            ' . $summary_stats . '
        </div>

        <table>
            <thead>
                <tr>';

    $headers = getExcelHeaders($report_type);
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }

    $html .= '</tr>
            </thead>
            <tbody>';

    foreach ($data as $item) {
        $html .= '<tr>';
        $rowData = getExcelRowData($report_type, $item);
        foreach ($rowData as $cell) {
            $html .= '<td>' . htmlspecialchars($cell) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody>
        </table>

        <div class="footer">
            <p>This report was generated automatically by the Migori County Project Management System</p>
            <p>© ' . date('Y') . ' Migori County Government - All rights reserved</p>
        </div>
    </body>
    </html>';

    return $html;
}

// Log the export activity
log_activity('report_export', "Exported {$report_type} report in {$format} format", $current_admin['id']);
?>