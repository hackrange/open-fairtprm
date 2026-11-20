<?php
/**
 * RESTful API v2 - Shadow SaaS / Shadow AI app inventory (/api/v2/shadow-saas/apps)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 *   GET /shadow-saas/apps   list (limit/offset, updated_since, ai=1 for AI apps only)
 *
 * Read-only app inventory for tools such as open-ctem: name, domain, category, user
 * count, risk score, source, status, Grip sanction tag and AI depth. Never the people:
 * no business owner, primary contact, user roster or raw Grip payload.
 * Same audience as the Shadow SaaS pages: super admin, administrator, cyber_tprm,
 * auditor. TPRM module access is checked by the router.
 *
 * Included by api/v2/router.php in the router's scope ($db, $token, $method, $segments).
 */

if (!defined('API_V2_ROUTER')) { http_response_code(404); exit; }

if (!apiTokenUserAllowed($db, $token, ['administrator', 'cyber_tprm', 'auditor'])) {
    apiError(403, 'The token user cannot view Shadow SaaS');
}
if (($segments[1] ?? '') !== 'apps' || isset($segments[2])) apiError(404, 'Shadow SaaS resource not found');
if ($method !== 'GET') apiError(405, 'Method not allowed');

foreach (['limit', 'offset'] as $k) {
    if (isset($_GET[$k]) && (!is_string($_GET[$k]) || !ctype_digit($_GET[$k]))) apiError(400, "{$k} must be a non-negative integer");
}
$limit = min(1000, max(1, (int)($_GET['limit'] ?? 500)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$ai = $_GET['ai'] ?? '';
if (!is_string($ai) || !in_array($ai, ['', '0', '1'], true)) apiError(400, 'ai must be 0 or 1');

$where = ['1=1'];
$params = [];
$rawSince = $_GET['updated_since'] ?? null;
if ($rawSince !== null && $rawSince !== '') {
    if (!is_string($rawSince) || strlen($rawSince) > 40
        || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+\-]\d{2}:?\d{2})?)?$/', $rawSince)) {
        apiError(400, 'updated_since must be an ISO 8601 date or date-time');
    }
    try {
        $sinceDt = new DateTimeImmutable($rawSince);
    } catch (Exception $e) {
        apiError(400, 'updated_since must be an ISO 8601 date or date-time');
    }
    $where[] = 's.updated_at >= :since';
    $params[':since'] = $sinceDt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

// AI depth as the Shadow SaaS pages read it: the app's own Grip AI depth, then the
// Grip mirror's, else the "AI: ..." part of risk_type (CSV and other sources).
// "No AI" (any case or spacing) means no AI.
$aiExpr = "COALESCE(NULLIF(TRIM(s.grip_ai_depth_level), ''), NULLIF(TRIM(a.ai_depth_level), ''))";
if ($ai === '1') {
    $where[] = "(({$aiExpr} IS NOT NULL AND REPLACE(LOWER({$aiExpr}), ' ', '') <> 'noai')
                 OR ({$aiExpr} IS NULL AND s.risk_type LIKE '%AI:%' AND s.risk_type NOT LIKE '%AI: No AI%'))";
}
$w = implode(' AND ', $where);
$from = 'FROM shadow_saas s LEFT JOIN shadow_saas_grip_apps a ON a.grip_id = s.grip_id';
$rows = $db->fetchAll(
    "SELECT s.id, s.vendor_name AS name, s.vendor_domain AS domain, s.application_category AS category,
            COALESCE(a.number_of_users, s.number_of_users) AS users, s.risk_score, s.source, s.status,
            LOWER(COALESCE(NULLIF(s.grip_sanction_tag, ''), NULLIF(a.sanction_tag, ''))) AS sanction_tag,
            {$aiExpr} AS ai_depth_level, s.risk_type AS risk_type_internal, a.first_event_time AS first_seen,
            a.latest_event_time AS last_seen, s.updated_at
       {$from} WHERE {$w} ORDER BY s.id LIMIT {$limit} OFFSET {$offset}", $params);
foreach ($rows as &$r) {
    if ($r['ai_depth_level'] === null
        && preg_match('/(?:^|;)\s*AI:\s*([^;]+)/i', (string)$r['risk_type_internal'], $m) && trim($m[1]) !== '') {
        $r['ai_depth_level'] = mb_substr(trim($m[1]), 0, 255);
    }
    unset($r['risk_type_internal']);
    $r['id'] = (int)$r['id'];
    $r['users'] = $r['users'] === null ? null : (int)$r['users'];
    $r['risk_score'] = $r['risk_score'] === null ? null : (int)$r['risk_score'];
}
unset($r);
$total = (int)($db->fetchOne("SELECT COUNT(*) AS c {$from} WHERE {$w}", $params)['c'] ?? 0);
apiSuccess(['data' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
