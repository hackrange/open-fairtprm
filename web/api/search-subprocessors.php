<?php
/**
 * Search Subprocessors API (GET autocomplete)
 *
 * Returns matching subprocessors by name/domain with vendor_count, matching
 * monitored vendors, and (for users who can see Shadow SaaS) matching Shadow
 * SaaS apps not yet onboarded, so a vendor's subprocessor can be picked from an
 * app the organization only sees through Shadow SaaS (e.g. one it tolerates).
 * Limit 10 results per group.
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

    // Shadow SaaS apps not yet onboarded (onboarded ones are listed as vendors
    // above). Same audience as the Shadow SaaS page, minus read-only auditors.
    $shadow = [];
    if ($isAdmin || $isCyberTPRM) {
        require_once __DIR__ . '/../includes/partials/shadow-saas-helpers.php';
        $gripEnabled = (function_exists('getAppConfig') && getAppConfig('grip_enabled', '0') === '1');
        $known = [];
        foreach ($results as $r) {
            if (($d = shadowSaasNormDomain($r['subprocessor_domain'] ?? '')) !== '') $known['d:' . $d] = true;
            $known['n:' . strtolower(trim((string)$r['subprocessor_name']))] = true;
        }
        try {
            $rows = $db->fetchAll(
                "SELECT id, vendor_name, vendor_domain, status
                 FROM shadow_saas
                 WHERE status <> 'onboarded' AND (vendor_name LIKE ? OR vendor_domain LIKE ?)
                 ORDER BY vendor_name ASC
                 LIMIT 10",
                [$like, $like]
            );
        } catch (Exception $e) {
            // A Shadow SaaS problem must not break subprocessor search.
            error_log('Search subprocessors: Shadow SaaS lookup failed: ' . $e->getMessage());
            $rows = [];
        }
        foreach ($rows as $r) {
            $d = shadowSaasNormDomain($r['vendor_domain'] ?? '');
            // Already a subprocessor: picking that entry links the same thing.
            if (($d !== '' && isset($known['d:' . $d])) || isset($known['n:' . strtolower(trim((string)$r['vendor_name']))])) continue;
            $shadow[] = [
                'id'           => (int)$r['id'],
                'vendor_name'  => (string)$r['vendor_name'],
                'vendor_domain'=> $d,
                'status'       => (string)$r['status'],
                'status_label' => shadowSaasStatusLabel((string)$r['status'], $gripEnabled),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'vendors' => $vendors,
        'shadow_saas' => $shadow
    ]);
} catch (Exception $e) {
    error_log('Search subprocessors error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Search failed']);
}
