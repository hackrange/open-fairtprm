<?php
/**
 * Vendor Search API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Type-ahead search endpoint that lets admins and TPRM folks find vendors by name,
 * domain, or by stakeholder name. Returns up to 10 matching vendors with SRS
 * grade and tier information.
 */

require_once __DIR__ . '/../includes/init.php';

// If you're not logged in, the requireAuth() function will boot you out
// faster than a bouncer at an exclusive nightclub
requireAuth();

// We speak JSON here. English is our second language.
header('Content-Type: application/json');

// Gather our tools -- auth for identity, db for data, acl for "can you even do this?"
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();

// ---------------------------------------------------------------
// PERMISSION CHECK
// Only admins and the cyber_tprm crew get to use this search.
// Everyone else can go use the regular UI like peasants.
// ---------------------------------------------------------------
$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isProcurement = $acl->hasGroup('procurement');
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isAuditor) {
    echo json_encode([
        'success' => false,
        'error' => 'Access denied'
    ]);
    exit;
}

// Grab the search query. If they typed less than 2 characters,
// we're not even going to bother -- that's just noise.
$query = trim($_GET['q'] ?? '');

if (empty($query) || strlen($query) < 2) {
    echo json_encode([
        'success' => true,
        'vendors' => []
    ]);
    exit;
}

// Pull in SRS service for grade calculations -- because vendors
// get report cards too, apparently
require_once __DIR__ . '/../includes/classes/SRSService.php';
$srsService = new SRSService();

try {
    // ---------------------------------------------------------------
    // COLUMN EXISTENCE CHECK
    // The custom_score column may not exist if the migration hasn't run
    // yet. If we blindly reference it, the query blows up and nobody
    // gets search results. So we probe for it first.
    // ---------------------------------------------------------------
    $customColExists = false;
    try {
        $db->fetchOne("SELECT custom_score FROM vendor_onboarding_requests LIMIT 1");
        $customColExists = true;
    } catch (Exception $e) {}

    $customScoreSelect = $customColExists ? ', r.custom_score' : '';
    $customScoreGroup = $customColExists ? ', r.custom_score' : '';

    // The vat_number column was added in v2.6.1; older instances that haven't
    // applied the migration won't have it, so probe before referencing it.
    $vatColExists = false;
    try {
        $db->fetchOne("SELECT vat_number FROM vendor_onboarding_requests LIMIT 1");
        $vatColExists = true;
    } catch (Exception $e) {}
    $vatWhere = $vatColExists ? "\n            OR r.vat_number LIKE :search_vat" : '';
    $vatRank  = $vatColExists ? ' OR r.vat_number LIKE :rank_vat' : '';

    // ---------------------------------------------------------------
    // THE BIG SEARCH QUERY
    // This beast searches vendors by name, domain, AND by stakeholder
    // info (name, username, email). It's a LEFT JOIN party in here.
    // GROUP_CONCAT smashes all stakeholder names into a comma-separated
    // string because who needs normalized data in an API response, right?
    // ---------------------------------------------------------------
    $sql = "
        SELECT DISTINCT
            r.id,
            r.vendor_name,
            r.vendor_domain,
            r.vendor_tier,
            r.status,
            r.current_srs_score,
            r.current_shodan_score
            {$customScoreSelect},
            GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') as stakeholder_name,
            GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') as stakeholder_username
        FROM vendor_onboarding_requests r
        LEFT JOIN vendor_onboarding_stakeholders s ON s.request_id = r.id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE (
            r.vendor_name LIKE :search
            OR r.vendor_domain LIKE :search2{$vatWhere}
            OR r.id IN (
                SELECT s2.request_id
                FROM vendor_onboarding_stakeholders s2
                JOIN users u2 ON u2.id = s2.user_id
                WHERE (
                    u2.full_name LIKE :search3
                    OR u2.username LIKE :search4
                    OR u2.email LIKE :search5
                )
            )
        )
        GROUP BY r.id, r.vendor_name, r.vendor_domain, r.vendor_tier, r.status, r.current_srs_score, r.current_shodan_score{$customScoreGroup}
        ORDER BY
            (CASE WHEN r.vendor_name LIKE :rank1 OR r.vendor_domain LIKE :rank2{$vatRank} THEN 0 ELSE 1 END),
            r.vendor_name ASC
        LIMIT 10
    ";

    // PDO won't let us reuse the same named param, so we get to define
    // the exact same search term five glorious times. Isn't SQL fun?
    $query = str_replace(['%', '_'], ['\\%', '\\_'], $query);
    $params = [
        ':search' => '%' . $query . '%',
        ':search2' => '%' . $query . '%',
        ':search3' => '%' . $query . '%',
        ':search4' => '%' . $query . '%',
        ':search5' => '%' . $query . '%',
        // Ranking params: surface direct vendor name/domain matches ahead of
        // rows matched only via a shared stakeholder email/name. Without this,
        // a term that appears in many stakeholders' emails (e.g. the org's own
        // domain) floods results and buries the actual vendor past LIMIT 10.
        ':rank1' => '%' . $query . '%',
        ':rank2' => '%' . $query . '%'
    ];
    // VAT number search (only when the column exists). A VAT match counts as a
    // direct match for ranking, so searching a VAT surfaces that vendor first.
    if ($vatColExists) {
        $params[':search_vat'] = '%' . $query . '%';
        $params[':rank_vat'] = '%' . $query . '%';
    }

    $vendors = $db->fetchAll($sql, $params);

    // ---------------------------------------------------------------
    // POST-PROCESSING: GRADE + TIER LABELS
    // Slap a letter grade and a human-readable tier name on each vendor
    // because raw numbers are for databases, not for humans.
    // ---------------------------------------------------------------
    foreach ($vendors as &$vendor) {
        if (!$customColExists) {
            $vendor['custom_score'] = null;
        }
        $combined = $srsService->getCombinedScore($vendor);
        $vendor['combined_score'] = $combined['score'];
        $vendor['grade'] = $combined['grade'];
        $vendor['is_shadow_saas'] = false;

        if (!empty($vendor['vendor_tier'])) {
            $vendor['tier_name'] = $srsService->getTierDisplayName($vendor['vendor_tier']);
        } else {
            $vendor['tier_name'] = 'No Tier';
        }
    }

    // ---------------------------------------------------------------
    // SHADOW SAAS SEARCH
    // Also search the shadow_saas table for unmanaged SaaS entries.
    // These show up with a different icon so users know the difference.
    // ---------------------------------------------------------------
    if ($isAdmin || $isCyberTPRM || $isAuditor) {
        try {
            // Only surface 'pending' rows — dismissed entries are decision
            // records, not actionable vendors, and onboarded rows are deleted
            // from shadow_saas. Also exclude any pending row whose domain
            // already exists as a non-inactive TPRM vendor, so we don't show
            // the same vendor twice when both rows happen to coexist.
            $ssSql = "
                SELECT ss.id, ss.vendor_name, ss.vendor_domain,
                       ss.relationship_manager, ss.status, ss.created_at,
                       ss.current_srs_score, ss.current_shodan_score
                FROM shadow_saas ss
                WHERE ss.status = 'pending'
                  AND (ss.vendor_name LIKE :search OR ss.vendor_domain LIKE :search2 OR ss.relationship_manager LIKE :search3)
                  AND NOT EXISTS (
                      SELECT 1 FROM vendor_onboarding_requests v
                      WHERE v.vendor_domain IS NOT NULL
                        AND v.vendor_domain <> ''
                        AND v.vendor_domain = ss.vendor_domain
                        AND v.status <> 'inactive'
                  )
                ORDER BY ss.vendor_name ASC
                LIMIT 5
            ";
            $ssParams = [
                ':search' => $params[':search'],
                ':search2' => $params[':search2'],
                ':search3' => $params[':search3'],
            ];
            $shadowResults = $db->fetchAll($ssSql, $ssParams);

            foreach ($shadowResults as $ss) {
                $combined = $srsService->getCombinedScore($ss);
                $vendors[] = [
                    'id' => (int)$ss['id'],
                    'vendor_name' => $ss['vendor_name'],
                    'vendor_domain' => $ss['vendor_domain'],
                    'vendor_tier' => null,
                    'status' => $ss['status'],
                    'stakeholder_name' => $ss['relationship_manager'],
                    'combined_score' => $combined['score'],
                    'grade' => $combined['grade'],
                    'tier_name' => 'Shadow SaaS',
                    'is_shadow_saas' => true,
                ];
            }
        } catch (Exception $e) {
            // shadow_saas table may not exist yet -- silently skip
        }
    }

    echo json_encode([
        'success' => true,
        'vendors' => $vendors
    ]);

} catch (Exception $e) {
    // Well that didn't work. Let the caller know it's not their fault
    // (it's probably our fault, but we'll never admit that in production)
    error_log('Vendor search failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Search failed'
    ]);
}
