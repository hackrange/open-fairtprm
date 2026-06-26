<?php
/**
 * Vendor Onboarding CSV Export - The Data Dump Truck
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Dumps all vendor onboarding requests to a CSV download. Great for
 * reporting, backups, or giving management a spreadsheet to stare at
 * during the quarterly review. Supports the same status and search filters
 * as the list page, so you can export just approved vendors or just
 * the ones matching a search. About 42 columns of vendor goodness,
 * covering everything from basic info to data security questions to
 * FAIR fields. Locked down to admins and cyber TPRM only, because
 * exporting all your vendor data is kind of a big deal.
 */

require_once 'includes/init.php';
requireAuth(); // No anonymous exports, thank you

// Singletons -- just the essentials for this one
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();

// ============================================================================
// PERMISSION CHECK
// Only admins and cyber TPRM can export. Stakeholders would probably just
// forward the CSV to the wrong person anyway.
// ============================================================================
$canExport = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if (!$canExport) {
    http_response_code(403);
    die('Access denied. Only administrators and Cyber TPRM users can export data.');
}

// ============================================================================
// BUILD QUERY WITH FILTERS
// Same filter logic as the list page -- supports status and search params
// so you can export a filtered subset of vendors.
// ============================================================================
$params = [];
$whereConditions = [];

// Status filter -- validated against allowed values
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
if (!empty($statusFilter) && in_array($statusFilter, ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive'])) {
    $whereConditions[] = "r.status = :status";
    $params[':status'] = $statusFilter;
}

// Search filter -- vendor name, type, or relationship manager
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($searchQuery)) {
    $whereConditions[] = "(r.vendor_name LIKE :search OR r.vendor_type LIKE :search2 OR r.relationship_manager LIKE :search3)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
    $params[':search3'] = '%' . $searchQuery . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Pull all matching records with creator info
$query = "
    SELECT r.*,
           u.full_name as created_by_name,
           u.email as created_by_email
    FROM vendor_onboarding_requests r
    LEFT JOIN users u ON r.created_by = u.id
    {$whereClause}
    ORDER BY r.updated_at DESC
";

$requests = $db->fetchAll($query, $params);

// ============================================================================
// CUSTOM ONBOARDING FIELDS
// Build the union of custom field_names across the exported vendors so each
// becomes its own "custom:<field_name>" column. Values come from the shared
// VendorAssessmentService helper (same source as the Custom Data tab + API).
// ============================================================================
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$vasExport = new VendorAssessmentService();
// Viewer context for role gating: the exporting user only gets custom/standard
// onboarding fields they are permitted to see in the GUI (no IDOR via export).
$expViewerGroups = $acl->getUserGroups();
$expViewerSuper  = (bool)Session::getInstance()->get('is_super_admin');
$expViewer = ['groups' => $expViewerGroups, 'super' => $expViewerSuper];
$hiddenByVendor = [];    // vendorId => [lowercased standard field_name => true]
$customByVendor = [];    // vendorId => [field_name => value]
$customFieldNames = [];  // ordered union of custom field_names
foreach ($requests as $req) {
    $hiddenByVendor[$req['id']] = $vasExport->getHiddenOnboardingFieldNames($req['id'], $expViewerGroups, $expViewerSuper);
    $cd = $vasExport->getCustomOnboardingData($req['id'], $expViewer);
    if (empty($cd)) continue;
    $vals = [];
    foreach ($cd as $fn => $cf) {
        $vals[$fn] = $cf['value'];
        if (!in_array($fn, $customFieldNames, true)) $customFieldNames[] = $fn;
    }
    $customByVendor[$req['id']] = $vals;
}

// ============================================================================
// CSV OUTPUT
// Set headers to force download, then pump out the data. The filename
// includes a timestamp so you can tell exports apart.
// ============================================================================
$filename = 'vendor_onboarding_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Column headers -- human-readable names for each field
$headers = [
    'ID',
    'Vendor Name',
    'Vendor Domain',
    'Vendor ID (VID)',
    'Vendor Type',
    'Relationship Manager',
    'Expected Procurement Date',
    'Product/Service Description',
    'Target User Count',
    'Primary Contact Email',
    'Primary Contact Full Name',
    'Primary Contact Title',
    'Primary Contact Phone',
    'NDA In Place',
    'Vendor Competitors',
    // Data & Security Questions -- the fun part
    'PII/PHI Exchange',
    'PII/PHI Justification',
    'Confidential Info Shared',
    'Confidential Info Justification',
    'Cross Border Transfer',
    'Cross Border Justification',
    'Offsite Data Hosting',
    'Offsite Data Justification',
    'Remote Network Access',
    'Remote Access Justification',
    'Source Code Access',
    'Source Code Justification',
    'Critical Business Function',
    'Critical Function Justification',
    'Unauthorized Disclosure Impact',
    'Unauthorized Disclosure Justification',
    'Unauthorized Modification Impact',
    'Disruption Impact',
    'SAML/SSO Support',
    'Is SaaS',
    // FAIR Fields
    'PII Record Count',
    'SPII Record Count',
    'SOX Record Count',
    'Business Impact',
    // VSU Fields
    'Procurement Onboarding',
    // Tier
    'Vendor Tier',
    // Additional Info
    'Additional Information',
    // Status & Workflow metadata
    'Status',
    'Status Notes',
    'Created By',
    'Created By Email',
    'Created At',
    'Submitted At',
    'Updated At'
];

// Append one column per discovered custom field, keyed by its stable field_name.
foreach ($customFieldNames as $fn) {
    $headers[] = 'custom:' . $fn;
}

fputcsv($output, $headers);

// Export each record as a CSV row -- null coalescing everywhere because
// not every field is guaranteed to be populated
foreach ($requests as $req) {
    // Blank any standard onboarding columns this exporter is not permitted to see.
    foreach ($hiddenByVendor[$req['id']] ?? [] as $hiddenFnl => $_unused) {
        if (array_key_exists($hiddenFnl, $req)) $req[$hiddenFnl] = '';
    }
    $row = [
        $req['id'],
        $req['vendor_name'] ?? '',
        $req['vendor_domain'] ?? '',
        $req['vendor_id'] ?? '',
        $req['vendor_type'] ?? '',
        $req['relationship_manager'] ?? '',
        $req['expected_procurement_date'] ?? '',
        $req['product_service_description'] ?? '',
        $req['target_user_count'] ?? '',
        $req['primary_contact_email'] ?? '',
        $req['primary_contact_details'] ?? '',
        $req['primary_contact_title'] ?? '',
        $req['primary_contact_phone'] ?? '',
        $req['nda_in_place'] ?? '',
        $req['vendor_competitors'] ?? '',
        // Data & Security Questions
        $req['pii_phi_exchange'] ?? '',
        $req['pii_phi_justification'] ?? '',
        $req['confidential_info_shared'] ?? '',
        $req['confidential_info_justification'] ?? '',
        $req['cross_border_transfer'] ?? '',
        $req['cross_border_justification'] ?? '',
        $req['offsite_data_hosting'] ?? '',
        $req['offsite_data_justification'] ?? '',
        $req['remote_network_access'] ?? '',
        $req['remote_access_justification'] ?? '',
        $req['source_code_access'] ?? '',
        $req['source_code_justification'] ?? '',
        $req['critical_business_function'] ?? '',
        $req['critical_function_justification'] ?? '',
        $req['unauthorized_disclosure_impact'] ?? '',
        $req['unauthorized_disclosure_justification'] ?? '',
        $req['unauthorized_modification_impact'] ?? '',
        $req['disruption_impact'] ?? '',
        $req['saml_sso_support'] ?? '',
        $req['is_saas'] ?? '',
        // FAIR Fields
        $req['pii_record_count'] ?? 0,
        $req['spii_record_count'] ?? 0,
        $req['sox_record_count'] ?? 0,
        $req['business_impact'] ?? '',
        // VSU Fields
        $req['vsu_onboarded'] ?? '',
        // Tier
        $req['vendor_tier'] ?? '',
        // Additional Info
        $req['additional_information'] ?? '',
        // Status & Workflow
        $req['status'] ?? '',
        $req['status_notes'] ?? '',
        $req['created_by_name'] ?? '',
        $req['created_by_email'] ?? '',
        $req['created_at'] ?? '',
        $req['submitted_at'] ?? '',
        $req['updated_at'] ?? ''
    ];

    // Append this vendor's custom field values, aligned to the header union.
    foreach ($customFieldNames as $fn) {
        $row[] = $customByVendor[$req['id']][$fn] ?? '';
    }

    fputcsv($output, array_map('csvSafeCell', $row)); // SECURITY: neutralize CSV formula injection
}

fclose($output);
exit;
