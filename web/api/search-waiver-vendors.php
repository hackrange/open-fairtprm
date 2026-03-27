<?php
/**
 * API: Search vendors for risk waiver management
 *
 * Type-ahead search endpoint for the admin Shodan waivers section.
 * Searches vendors by name or domain and returns matching vendors
 * along with their waiver counts.
 *
 * GET ?q=<search>&action=search  — type-ahead vendor search (min 2 chars)
 * GET ?vendor_id=<id>&action=waivers — get waivers for a specific vendor
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$db = Database::getInstance();
$acl = ACL::getInstance();

// Only admins and cyber_tprm can manage waivers
if (!$acl->hasGroup('administrator') && !$acl->hasGroup('cyber_tprm')) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? 'search';

if ($action === 'search') {
    $query = trim($_GET['q'] ?? '');

    if (empty($query) || strlen($query) < 2) {
        echo json_encode(['success' => true, 'vendors' => []]);
        exit;
    }

    try {
        $query = str_replace(['%', '_'], ['\\%', '\\_'], $query);
        $vendors = $db->fetchAll(
            "SELECT r.id, r.vendor_name, r.vendor_domain,
                    COUNT(w.id) as waiver_count
             FROM vendor_onboarding_requests r
             LEFT JOIN vendor_shodan_waivers w ON w.vendor_onboarding_id = r.id
             WHERE r.status != 'inactive'
               AND (r.vendor_name LIKE :search OR r.vendor_domain LIKE :search2)
             GROUP BY r.id, r.vendor_name, r.vendor_domain
             ORDER BY waiver_count DESC, r.vendor_name ASC
             LIMIT 10",
            [':search' => '%' . $query . '%', ':search2' => '%' . $query . '%']
        );

        echo json_encode(['success' => true, 'vendors' => $vendors]);
    } catch (Exception $e) {
        error_log('Waiver vendor search failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Search failed']);
    }

} elseif ($action === 'waivers') {
    $vendorId = intval($_GET['vendor_id'] ?? 0);

    if (!$vendorId) {
        echo json_encode(['success' => false, 'error' => 'Missing vendor_id']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/classes/ShodanService.php';
        $shodanService = new ShodanService();
        $waivers = $shodanService->getWaiversForVendorWithInfo($vendorId);

        $vendor = $db->fetchOne(
            'SELECT id, vendor_name, vendor_domain FROM vendor_onboarding_requests WHERE id = :id',
            [':id' => $vendorId]
        );

        echo json_encode([
            'success' => true,
            'vendor' => $vendor ?: null,
            'waivers' => $waivers,
        ]);
    } catch (Exception $e) {
        error_log('Waiver fetch failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to load waivers']);
    }

} elseif ($action === 'cve_waivers') {
    $vendorId = intval($_GET['vendor_id'] ?? 0);

    if (!$vendorId) {
        echo json_encode(['success' => false, 'error' => 'Missing vendor_id']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/classes/ShodanService.php';
        $shodanService = new ShodanService();
        $waivers = $shodanService->getCveWaiversForVendor($vendorId);

        $vendor = $db->fetchOne(
            'SELECT id, vendor_name, vendor_domain FROM vendor_onboarding_requests WHERE id = :id',
            [':id' => $vendorId]
        );

        echo json_encode([
            'success' => true,
            'vendor' => $vendor ?: null,
            'waivers' => $waivers,
        ]);
    } catch (Exception $e) {
        error_log('CVE waiver fetch failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to load CVE waivers']);
    }

} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
