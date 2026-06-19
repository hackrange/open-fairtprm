<?php
/**
 * SRS Scores CSV Export - Dump Everything Into a Spreadsheet
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the "I need to put this in a PowerPoint for the board meeting" page.
 * Hit this endpoint and it streams a CSV file straight to your browser with every
 * vendor's security score, grade, tier, domain, status, and whether they need
 * rescoring. Respects whatever filters you've got active on the list page (tier,
 * grade, search) so you can export exactly the subset you care about. No UI here --
 * it's all business. Sets Content-Disposition headers and starts dumping rows.
 */

// Init and auth -- even exports need to verify you're allowed to see this data
require_once 'includes/init.php';
requireAuth();

// Grab our singletons
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();

// Only the cool kids (admins and cyber_tprm) get to export vendor data.
// We take data exfiltration seriously, even when it's intentional.
$canExport = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if (!$canExport) {
    http_response_code(403);
    die(e(t('vendor-srs-export.access_denied')));
}

require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();

// Build the query using the same filters as the list page -- tier, search, etc.
// That way the CSV matches what you're seeing on screen, not the entire universe.
$params = [];
$whereConditions = ["r.vendor_domain IS NOT NULL", "r.vendor_domain != ''"];

// Tier filter
$tierFilter = isset($_GET['tier']) ? $_GET['tier'] : '';
if (!empty($tierFilter) && in_array($tierFilter, ['1', '2', '3'])) {
    $whereConditions[] = "r.vendor_tier = :tier";
    $params[':tier'] = $tierFilter;
}

// Search filter
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($searchQuery)) {
    $whereConditions[] = "(r.vendor_name LIKE :search OR r.vendor_domain LIKE :search2)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

$query = "
    SELECT r.id, r.vendor_name, r.vendor_domain, r.vendor_id, r.vendor_tier, r.vendor_type,
           r.current_srs_score, r.last_srs_score_at, r.status, r.created_at,
           u.full_name as created_by_name
    FROM vendor_onboarding_requests r
    LEFT JOIN users u ON r.created_by = u.id
    {$whereClause}
    ORDER BY r.vendor_name ASC
";

$vendors = $db->fetchAll($query, $params);

// Grade filter in PHP because grades are derived from scores, not stored directly.
// Yes, this means we fetch all vendors and then filter. Deal with it.
$gradeFilter = isset($_GET['grade']) ? strtoupper($_GET['grade']) : '';
if (!empty($gradeFilter)) {
    $vendors = array_filter($vendors, function($v) use ($gradeFilter, $srsService) {
        if (empty($v['current_srs_score'])) return false;
        $grade = $srsService->calculateGrade((int)$v['current_srs_score']);
        return $grade === $gradeFilter;
    });
}

// Set HTTP headers to force a file download. The timestamp in the filename
// prevents overwriting last week's export when someone doesn't rename files.
$filename = 'vendor_srs_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Column headers -- the first row of the CSV that Excel will turn bold for you
$headers = [
    'ID',
    'Vendor Name',
    'Vendor Domain',
    'Vendor ID (VID)',
    'Vendor Type',
    'Vendor Tier',
    'Current SRS Score',
    'SRS Grade',
    'Last Scored At',
    'Needs Rescore',
    'Onboarding Status',
    'Created By',
    'Created At'
];

fputcsv($output, $headers);

// Loop through each vendor and write a CSV row. We calculate the grade and
// needs-rescore status on the fly since those aren't stored in the DB.
foreach ($vendors as $vendor) {
    $score = !empty($vendor['current_srs_score']) ? intval($vendor['current_srs_score']) : null;
    $grade = $score !== null ? $srsService->calculateGrade($score) : '';
    $needsRescore = $srsService->needsRescore($vendor) ? 'Yes' : 'No';

    $row = [
        $vendor['id'],
        $vendor['vendor_name'] ?? '',
        $vendor['vendor_domain'] ?? '',
        $vendor['vendor_id'] ?? '',
        $vendor['vendor_type'] ?? '',
        $vendor['vendor_tier'] ?? '',
        $score ?? '',
        $grade,
        $vendor['last_srs_score_at'] ?? '',
        $needsRescore,
        $vendor['status'] ?? '',
        $vendor['created_by_name'] ?? '',
        $vendor['created_at'] ?? ''
    ];

    fputcsv($output, array_map('csvSafeCell', $row)); // SECURITY: neutralize CSV formula injection
}

fclose($output);
exit;
