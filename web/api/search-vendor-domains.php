<?php
/**
 * Vendor Domain Search API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Type-ahead search specifically for vendor domain fields. When someone starts
 * typing a domain name (like "acme.com") in the onboarding form, this endpoint
 * wakes up and checks the existing vendor_onboarding_requests table for matches.
 * It also returns a bunch of pre-filled data (vendor name, type, record counts,
 * contact info) so the form can auto-populate fields and save the user from
 * re-typing stuff that's already in the system. It's basically autocomplete on
 * steroids -- you type a domain, we give you the whole vendor profile for free.
 */

// JSON response, because XML is so 2003
header('Content-Type: application/json');

// Boot the application stack
require_once '../includes/init.php';

// ---------------------------------------------------------------
// AUTHENTICATION CHECK
// No session = no search. Go log in first.
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
// You need at least some level of onboarding permission to search.
// Create, read, read_own, read_assigned -- any of those will do.
// This is the "can you at least see the onboarding module?" check.
// ---------------------------------------------------------------
$canAccess = $acl->hasPermission('onboarding.create') ||
             $acl->hasPermission('onboarding.read') ||
             $acl->hasPermission('onboarding.read_own') ||
             $acl->hasPermission('onboarding.read_assigned');

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Read-only operation, GET requests only please
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Two character minimum for searching. "a" would match basically everything
// and we'd rather not accidentally DDoS ourselves.
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'vendors' => []]);
    exit;
}

try {
    $query = str_replace(['%', '_'], ['\\%', '\\_'], $query);
    $searchTerm = '%' . $query . '%';

    // SECURITY (BOLA): callers without org-wide onboarding read (e.g. a stakeholder
    // who only holds read_own/read_assigned/create) must not enumerate every vendor's
    // domain/PII/business-impact metadata. Scope their results to vendors they created
    // or are assigned to. Org-wide readers (onboarding.read / admin / cyber_tprm /
    // procurement / super admin) keep the full typeahead.
    $ownerScope = '';
    $scopeParams = [];
    if (!$acl->hasPermission('onboarding.read')
        && !$acl->hasGroup(['administrator', 'cyber_tprm', 'procurement'])
        && !Session::getInstance()->get('is_super_admin')) {
        $ownerScope = " AND (created_by = :scope_uid
            OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s
                       WHERE s.request_id = vendor_onboarding_requests.id
                         AND s.user_id = :scope_uid2))";
        $uid = (int)Auth::getInstance()->getUserId();
        $scopeParams = [':scope_uid' => $uid, ':scope_uid2' => $uid];
    }

    // ---------------------------------------------------------------
    // DOMAIN + NAME SEARCH
    // Searches vendor_domain and vendor_name in existing onboarding
    // requests. Only returns results where the domain actually exists
    // (not null, not empty) because a vendor without a domain is like
    // a website without a URL -- technically possible but not useful here.
    //
    // Results are prioritized: exact prefix matches on domain first,
    // then exact prefix matches on name, then everything else.
    // Limited to 10 so the dropdown doesn't become a phone book.
    // ---------------------------------------------------------------
    $vendors = $db->fetchAll(
        "SELECT DISTINCT
            vendor_domain,
            vendor_name,
            vendor_type,
            pii_record_count,
            spii_record_count,
            sox_record_count,
            business_impact,
            relationship_manager,
            primary_contact_email
         FROM vendor_onboarding_requests
         WHERE vendor_domain IS NOT NULL
           AND vendor_domain != ''
           AND (vendor_domain LIKE :search1 OR vendor_name LIKE :search2)" . $ownerScope . "
         ORDER BY
           CASE
             WHEN vendor_domain LIKE :exact1 THEN 1
             WHEN vendor_name LIKE :exact2 THEN 2
             ELSE 3
           END,
           vendor_name ASC
         LIMIT 10",
        array_merge([
            ':search1' => $searchTerm,
            ':search2' => $searchTerm,
            ':exact1' => $query . '%',
            ':exact2' => $query . '%'
        ], $scopeParams)
    );

    // ---------------------------------------------------------------
    // FORMAT RESULTS
    // Package each vendor with all its metadata. The frontend uses
    // this to auto-fill the onboarding form when someone picks a
    // vendor from the dropdown. Display format: "acme.com (Acme Corp)"
    // ---------------------------------------------------------------
    $results = [];
    foreach ($vendors as $v) {
        $results[] = [
            'domain' => $v['vendor_domain'],
            'name' => $v['vendor_name'] ?? '',
            'type' => $v['vendor_type'] ?? '',
            'pii_record_count' => $v['pii_record_count'] ?? 0,
            'spii_record_count' => $v['spii_record_count'] ?? 0,
            'sox_record_count' => $v['sox_record_count'] ?? 0,
            'business_impact' => $v['business_impact'] ?? '',
            'relationship_manager' => $v['relationship_manager'] ?? '',
            'primary_contact_email' => $v['primary_contact_email'] ?? '',
            'display' => $v['vendor_domain'] . ($v['vendor_name'] ? ' (' . $v['vendor_name'] . ')' : '')
        ];
    }

    echo json_encode(['success' => true, 'vendors' => $results]);

} catch (Exception $e) {
    // Something broke. Log the gory details, tell the user nothing useful.
    error_log('Vendor domain search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
