<?php
/**
 * Search Subprocessors API (GET autocomplete)
 *
 * Returns matching subprocessors by name/domain with vendor_count.
 * Limit 10 results.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireAuth();
$auth = Auth::getInstance();
$session = Session::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 1) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$db = Database::getInstance();

try {
    $like = '%' . $q . '%';
    $results = $db->fetchAll(
        "SELECT s.id, s.subprocessor_name, s.subprocessor_domain, s.country, s.linked_vendor_id,
                COUNT(m.id) AS vendor_count
         FROM vendor_subprocessors s
         LEFT JOIN vendor_subprocessor_mappings m ON m.subprocessor_id = s.id
         WHERE s.subprocessor_name LIKE ? OR s.subprocessor_domain LIKE ?
         GROUP BY s.id
         ORDER BY vendor_count DESC, s.subprocessor_name ASC
         LIMIT 10",
        [$like, $like]
    );

    // Also search monitored vendors by name or domain
    $vendors = $db->fetchAll(
        "SELECT id, vendor_name, vendor_domain
         FROM vendor_onboarding_requests
         WHERE vendor_name LIKE ? OR vendor_domain LIKE ?
         ORDER BY vendor_name ASC
         LIMIT 10",
        [$like, $like]
    );

    echo json_encode([
        'success' => true,
        'results' => $results,
        'vendors' => $vendors
    ]);
} catch (Exception $e) {
    error_log('Search subprocessors error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Search failed']);
}
