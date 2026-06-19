<?php
/**
 * SRS Data Lookup API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This little endpoint is the bridge between our app and the SRS (Security Rating
 * Service). Give it a vendor domain and it'll go fetch the security score, risk
 * data, and all the other spicy details from SRS. The FAIR analysis form uses this
 * to auto-populate security fields so analysts don't have to manually copy-paste
 * scores from another tab like animals. If SRS isn't configured or doesn't have
 * data for the domain, it politely says "nope" instead of exploding. Good manners
 * for an API endpoint, honestly.
 */

// We're a JSON API. Always have been, always will be.
header('Content-Type: application/json');

// Boot up the application and the SRS service classes
require_once '../includes/init.php';
require_once '../includes/classes/SRSService.php';
require_once '../includes/classes/ShodanService.php';

// ---------------------------------------------------------------
// AUTHENTICATION GATE
// Must be logged in to look up vendor security data.
// No anonymous snooping allowed.
// ---------------------------------------------------------------
if (!Auth::getInstance()->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// SECURITY (IDOR): authentication alone is not enough -- a scoped stakeholder (who
// has no SRS visibility) could otherwise pull SRS/Shodan posture for any domain.
// Require a function-level SRS capability: an org-wide reviewer role, an explicit
// srs.view permission, or analysis.read (the FAIR-analysis autofill caller).
$acl = ACL::getInstance();
$canViewSrs = $acl->hasGroup(['administrator', 'cyber_tprm', 'auditor', 'procurement'])
           || $acl->hasPermission('srs.view')
           || $acl->hasPermission('analysis.read')
           || Session::getInstance()->get('is_super_admin');
if (!$canViewSrs) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Grab the domain from the query string. Trim it because users
// love adding spaces to things that shouldn't have spaces.
$domain = trim($_GET['domain'] ?? '');

if (empty($domain)) {
    echo json_encode(['success' => false, 'error' => 'Domain parameter required']);
    exit;
}

// Validate domain format to prevent SSRF or injection via the SRS lookup.
// Only allow valid domain characters (alphanumeric, dots, hyphens).
if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
    echo json_encode(['success' => false, 'error' => 'Invalid domain format']);
    exit;
}

try {
    // Fire up the SRS service -- this handles all the communication
    // with the external security rating provider
    $srsService = new SRSService();

    // ---------------------------------------------------------------
    // AVAILABILITY CHECK
    // First, make sure SRS is actually configured and enabled.
    // If the API keys aren't set up or the service is disabled,
    // we bail early with a "not configured" message instead of
    // letting the request hang awkwardly like a bad phone call.
    // ---------------------------------------------------------------
    if (!$srsService->isAvailable()) {
        echo json_encode(['success' => false, 'error' => 'SRS not configured', 'available' => false]);
        exit;
    }

    // ---------------------------------------------------------------
    // THE ACTUAL LOOKUP
    // Ask SRS for everything it knows about this domain.
    // If data exists, we send it back wrapped in a success response.
    // If not, we say "no data found" -- which is different from an
    // error. The domain might just be too new or too obscure for SRS.
    // ---------------------------------------------------------------
    $data = $srsService->getVendorDataByDomain($domain);

    // Also check Shodan data if available
    $shodanData = null;
    $shodanService = new ShodanService();
    if ($shodanService->isAvailable()) {
        // Look up Shodan score for this domain's vendor
        $db = Database::getInstance();
        $vendor = $db->fetchOne(
            'SELECT id FROM vendor_onboarding_requests WHERE LOWER(vendor_domain) = :domain ORDER BY last_srs_score_at DESC LIMIT 1',
            [':domain' => strtolower($domain)]
        );
        if ($vendor) {
            $shodanLatest = $shodanService->getLatestScore($vendor['id']);
            if ($shodanLatest) {
                $shodanData = [
                    'score' => (int)$shodanLatest['score'],
                    'grade' => $shodanService->calculateGrade((int)$shodanLatest['score']),
                    'scored_at' => $shodanLatest['scored_at'],
                    'open_ports_count' => (int)($shodanLatest['open_ports_count'] ?? 0),
                    'vuln_count' => (int)($shodanLatest['vuln_count'] ?? 0),
                    'critical_vulns' => (int)($shodanLatest['critical_vulns'] ?? 0),
                    'high_vulns' => (int)($shodanLatest['high_vulns'] ?? 0)
                ];
            }
        }
    }

    if ($data || $shodanData) {
        $response = [
            'success' => true,
            'available' => true,
            'data' => $data
        ];
        if ($shodanData) {
            $response['shodan'] = $shodanData;
        }
        echo json_encode($response);
    } else {
        echo json_encode([
            'success' => false,
            'available' => true,
            'error' => 'No SRS data found for this domain'
        ]);
    }
} catch (Exception $e) {
    // SRS blew up. Could be network, could be auth, could be a bad day.
    // Log it and give back a generic error so we don't leak internals.
    error_log('SRS data lookup error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve SRS data'
    ]);
}
