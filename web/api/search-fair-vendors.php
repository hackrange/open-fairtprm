<?php
/**
 * FAIR Vendor Search API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Type-ahead search for the FAIR analysis form. This endpoint is the matchmaker
 * between the analyst and the vendor data they need. It searches TWO data sources:
 * the tprm_results table (past FAIR analyses) and vendor_onboarding_requests
 * (onboarded vendors). Results are tagged with their source so the frontend knows
 * where the data came from. Think of it as a vendor dating app -- swipe right on
 * a result and it auto-fills your FAIR analysis form with existing PII counts,
 * security scores, and all that juicy risk data. De-duplication is handled by a
 * "seen vendors" tracker because nobody wants to see the same vendor twice on a
 * first date.
 */

// JSON is the only language this endpoint speaks
header('Content-Type: application/json');

// Initialize the app -- configs, autoloading, session, the whole enchilada
require_once '../includes/init.php';

// ---------------------------------------------------------------
// AUTHENTICATION WALL
// No session? No data. Simple as that.
// ---------------------------------------------------------------
if (!Auth::getInstance()->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();

// ---------------------------------------------------------------
// PERMISSION CHECK
// You need FAIR analysis permissions to be here. That means either
// you can create/read analyses, or you're an admin/cyber_tprm member.
// If you're just some random user, sorry -- no peeking at the risk data.
// ---------------------------------------------------------------
$canAccess = $acl->hasPermission('analysis.create') ||
             $acl->hasPermission('analysis.read') ||
             $acl->hasGroup('administrator') ||
             $acl->hasGroup('cyber_tprm');

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// GET requests only -- we're searching, not submitting a thesis
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Minimum 2 characters to search. We're not psychics.
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'vendors' => []]);
    exit;
}

require_once __DIR__ . '/../includes/classes/SRSService.php';
$srsService = new SRSService();

try {
    // Check if custom_score column exists before referencing it
    $customColExists = false;
    try {
        $db->fetchOne("SELECT custom_score FROM vendor_onboarding_requests LIMIT 1");
        $customColExists = true;
    } catch (Exception $e) {}

    $query = str_replace(['%', '_'], ['\\%', '\\_'], $query);
    $searchTerm = '%' . $query . '%';
    $results = [];
    $seenVendors = []; // De-dupe tracker keyed by lowercase "name|domain"

    // ---------------------------------------------------------------
    // SOURCE 1: TPRM_RESULTS (Past FAIR Analyses)
    // These are vendors that already have FAIR analysis data.
    // They get searched first because they have the richest data
    // (security scores, ISO certification status, etc.)
    // Results are tagged with [FAIR] so users know where they came from.
    // ---------------------------------------------------------------
    $fairVendors = $db->fetchAll(
        "SELECT
            vendor_domain,
            vendor_name,
            pii_record_count,
            spii_record_count,
            sox_record_count,
            security_score,
            iso_27001_certified,
            status
         FROM tprm_results
         WHERE (vendor_domain LIKE :search1 OR vendor_name LIKE :search2)
         ORDER BY
           CASE
             WHEN vendor_name LIKE :exact1 THEN 1
             WHEN vendor_domain LIKE :exact2 THEN 2
             ELSE 3
           END,
           updated_at DESC
         LIMIT 15",
        [
            ':search1' => $searchTerm,
            ':search2' => $searchTerm,
            ':exact1' => $query . '%',
            ':exact2' => $query . '%'
        ]
    );

    // Process FAIR results and de-dupe as we go
    foreach ($fairVendors as $v) {
        $key = strtolower(($v['vendor_name'] ?? '') . '|' . ($v['vendor_domain'] ?? ''));
        if (isset($seenVendors[$key])) continue;
        $seenVendors[$key] = true;

        // Build display string: "Acme Corp - acme.com [FAIR]"
        $displayParts = [];
        if (!empty($v['vendor_name'])) $displayParts[] = $v['vendor_name'];
        if (!empty($v['vendor_domain'])) $displayParts[] = $v['vendor_domain'];

        $results[] = [
            'source' => 'fair',
            'domain' => $v['vendor_domain'] ?? '',
            'name' => $v['vendor_name'] ?? '',
            'pii_record_count' => (int)($v['pii_record_count'] ?? 0),
            'spii_record_count' => (int)($v['spii_record_count'] ?? 0),
            'sox_record_count' => (int)($v['sox_record_count'] ?? 0),
            'security_score' => $v['security_score'] ?? '',
            'iso_27001_certified' => (int)($v['iso_27001_certified'] ?? 0),
            'display' => implode(' - ', $displayParts) . ' [FAIR]'
        ];
    }

    // ---------------------------------------------------------------
    // SOURCE 2: ONBOARDING REQUESTS
    // If we still have room for more results (under 10), we dip into
    // the onboarding requests table. These vendors might not have FAIR
    // data yet but they exist in the system. Results prioritize approved
    // vendors first, then submitted, then in_review, then drafts --
    // because a fully approved vendor is more useful than a half-baked
    // draft that someone abandoned three months ago.
    // Tagged with [Onboarding] so you know what you're getting.
    // ---------------------------------------------------------------
    if (count($results) < 10) {
        $limit = 15 - count($results);
        $customScoreCol = $customColExists ? ', custom_score' : '';
        $onboardingVendors = $db->fetchAll(
            "SELECT
                id,
                vendor_domain,
                vendor_name,
                pii_record_count,
                spii_record_count,
                sox_record_count,
                business_impact,
                product_service_description,
                current_srs_score,
                current_shodan_score
                {$customScoreCol},
                status,
                updated_at
             FROM vendor_onboarding_requests
             WHERE (vendor_domain LIKE :search1 OR vendor_name LIKE :search2)
               AND (vendor_domain IS NOT NULL AND vendor_domain != '' OR vendor_name IS NOT NULL AND vendor_name != '')
               AND status NOT IN ('inactive', 'rejected')
             ORDER BY
               CASE status
                 WHEN 'approved' THEN 1
                 WHEN 'submitted' THEN 2
                 WHEN 'in_review' THEN 3
                 WHEN 'draft' THEN 4
                 ELSE 5
               END,
               CASE
                 WHEN vendor_name LIKE :exact1 THEN 1
                 WHEN vendor_domain LIKE :exact2 THEN 2
                 ELSE 3
               END,
               updated_at DESC
             LIMIT " . intval($limit),
            [
                ':search1' => $searchTerm,
                ':search2' => $searchTerm,
                ':exact1' => $query . '%',
                ':exact2' => $query . '%'
            ]
        );

        // Same de-dupe and formatting dance as above
        foreach ($onboardingVendors as $v) {
            $key = strtolower(($v['vendor_name'] ?? '') . '|' . ($v['vendor_domain'] ?? ''));
            if (isset($seenVendors[$key])) continue;
            $seenVendors[$key] = true;

            $displayParts = [];
            if (!empty($v['vendor_name'])) $displayParts[] = $v['vendor_name'];
            if (!empty($v['vendor_domain'])) $displayParts[] = $v['vendor_domain'];

            if (!$customColExists) {
                $v['custom_score'] = null;
            }
            $combined = $srsService->getCombinedScore($v);
            $results[] = [
                'source' => 'onboarding',
                'onboarding_id' => (int)($v['id'] ?? 0),
                'domain' => $v['vendor_domain'] ?? '',
                'name' => $v['vendor_name'] ?? '',
                'pii_record_count' => (int)($v['pii_record_count'] ?? 0),
                'spii_record_count' => (int)($v['spii_record_count'] ?? 0),
                'sox_record_count' => (int)($v['sox_record_count'] ?? 0),
                'business_impact' => $v['business_impact'] ?? '',
                'scope_of_work' => $v['product_service_description'] ?? '',
                'security_score' => $combined['grade'],
                'display' => implode(' - ', $displayParts) . ' [Onboarding]'
            ];
        }
    }

    // Return the combined, de-duped results from both sources
    echo json_encode(['success' => true, 'vendors' => $results]);

} catch (Exception $e) {
    // Database decided to take a personal day. Log and bail.
    error_log('FAIR vendor search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
