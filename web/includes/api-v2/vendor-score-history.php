<?php
/**
 * RESTful API v2 - Vendor score history (/api/v2/vendors/score-history)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 *   GET /vendors/score-history?since=YYYY-MM-DD&tier=1|2|3&limit=1-200&offset=N
 *
 * Read-only. For each vendor in the token's scope (same rule as GET /vendors),
 * every UpGuard, Shodan and custom score from `since` on, plus the last one
 * before `since`, oldest first, so a client can work out the combined score
 * percentage (SRSService::getCombinedScore) as of any date after `since`.
 * UpGuard and Shodan rows only where score > 0, as getCombinedScore ignores the rest.
 * Each list holds at most 1000 rows (newest kept); "truncated": true says so.
 *
 * All times are in the server time zone (top-level "timezone"). UpGuard and
 * Shodan times are stored that way; custom score changes are stored in UTC
 * (vendor_custom_score_history) and converted here.
 *
 * Included by api/v2/router.php in the router's scope ($db, $token, $method,
 * $segments, $tokenUserId, $tokenCanReadAllVendors). TPRM module access is
 * checked by the router.
 */

if (!defined('API_V2_ROUTER')) { http_response_code(404); exit; }

if (isset($segments[2])) apiError(404, 'Vendor resource not found');
if ($method !== 'GET') apiError(405, 'Method not allowed');

// ---- query parameters ------------------------------------------------------
$q = function (string $k): ?string {
    if (!array_key_exists($k, $_GET)) return null;
    if (!is_string($_GET[$k])) apiError(400, "{$k} must be a single value");
    return $_GET[$k] === '' ? null : $_GET[$k];
};

$sinceRaw = $q('since');
if ($sinceRaw === null) {
    $since = ((int)date('Y') - 1) . '-01-01';
} else {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $sinceRaw, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 1970) {
        apiError(400, 'since must be a date in YYYY-MM-DD format (1970 or later)');
    }
    $since = $sinceRaw;
}

$tier = $q('tier');
if ($tier !== null && !in_array($tier, ['1', '2', '3'], true)) {
    apiError(400, 'tier must be 1, 2 or 3');
}

$limitRaw = $q('limit');
if ($limitRaw !== null && (!ctype_digit($limitRaw) || (int)$limitRaw < 1 || (int)$limitRaw > 200)) {
    apiError(400, 'limit must be an integer from 1 to 200');
}
$limit = $limitRaw !== null ? (int)$limitRaw : 100;

$offsetRaw = $q('offset');
if ($offsetRaw !== null && (!ctype_digit($offsetRaw) || strlen($offsetRaw) > 9)) {
    apiError(400, 'offset must be a non-negative integer');
}
$offset = $offsetRaw !== null ? (int)$offsetRaw : 0;

// ---- time boundaries -------------------------------------------------------
$tzName = date_default_timezone_get();
$serverTz = new DateTimeZone($tzName);
$utc = new DateTimeZone('UTC');
$sinceLocal = $since . ' 00:00:00';   // UpGuard / Shodan: stored in server time
$sinceUtc = (new DateTime($sinceLocal, $serverTz))->setTimezone($utc)->format('Y-m-d H:i:s');   // custom: stored in UTC
$toServerTime = function (string $utcTime) use ($utc, $serverTz): string {
    return (new DateTime($utcTime, $utc))->setTimezone($serverTz)->format('Y-m-d H:i:s');
};

// ---- vendors in scope (same rule as GET /vendors) ------------------------------
$where = ['1=1'];
$params = [];
if ($tier !== null) { $where[] = 'v.vendor_tier = :tier'; $params[':tier'] = $tier; }
if (!$tokenCanReadAllVendors) {
    $where[] = '(v.created_by = :scu OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s WHERE s.request_id = v.id AND s.user_id = :scu2))';
    $params[':scu'] = $tokenUserId; $params[':scu2'] = $tokenUserId;
}
$whereSql = implode(' AND ', $where);
$vendors = $db->fetchAll(
    "SELECT v.id, v.vendor_name, v.vendor_tier, v.status, v.current_srs_score, v.current_shodan_score, v.custom_score
       FROM vendor_onboarding_requests v WHERE {$whereSql} ORDER BY v.id LIMIT {$limit} OFFSET {$offset}", $params);
$total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM vendor_onboarding_requests v WHERE {$whereSql}", $params)['c'] ?? 0);

require_once dirname(__DIR__) . '/classes/SRSService.php';
$srs = new SRSService();
$maxRows = 1000;

/**
 * Rows from $since on (newest first, at most $maxRows + 1) plus the newest row
 * before it, returned oldest first and capped at $maxRows (newest kept).
 */
$history = function (string $table, string $timeCol, string $scoreFilter, int $vid, string $bound) use ($db, $maxRows): array {
    $fetch = $maxRows + 1;
    $inRange = $db->fetchAll(
        "SELECT id, score, {$timeCol} AS t FROM {$table}
          WHERE vendor_onboarding_id = :v AND {$timeCol} >= :b{$scoreFilter}
          ORDER BY {$timeCol} DESC, id DESC LIMIT {$fetch}", [':v' => $vid, ':b' => $bound]);
    $rows = $inRange;
    if (count($rows) <= $maxRows) {
        $before = $db->fetchOne(
            "SELECT id, score, {$timeCol} AS t FROM {$table}
              WHERE vendor_onboarding_id = :v AND {$timeCol} < :b{$scoreFilter}
              ORDER BY {$timeCol} DESC, id DESC LIMIT 1", [':v' => $vid, ':b' => $bound]);
        if ($before) $rows[] = $before;
    }
    $truncated = count($rows) > $maxRows;
    if ($truncated) $rows = array_slice($rows, 0, $maxRows);
    return [array_reverse($rows), $truncated];
};

$intOrNull = fn($v) => ($v === null || $v === '') ? null : (int)$v;

$data = [];
foreach ($vendors as $v) {
    $vid = (int)$v['id'];
    [$ug, $ugT] = $history('vendor_srs_scores', 'scored_at', ' AND score > 0', $vid, $sinceLocal);
    [$sh, $shT] = $history('vendor_shodan_scores', 'scored_at', ' AND score > 0', $vid, $sinceLocal);
    [$cu, $cuT] = $history('vendor_custom_score_history', 'changed_at', '', $vid, $sinceUtc);
    $combined = $srs->getCombinedScore($v);
    $row = [
        'id'          => $vid,
        'vendor_name' => $v['vendor_name'],
        'vendor_tier' => $v['vendor_tier'] !== null ? (string)$v['vendor_tier'] : null,
        'status'      => $v['status'],
        'current'     => [
            'upguard'      => $intOrNull($v['current_srs_score']),
            'shodan'       => $intOrNull($v['current_shodan_score']),
            'custom'       => $intOrNull($v['custom_score']),
            'combined_pct' => $combined['score'],
            'grade'        => $combined['grade'],
        ],
        'upguard' => array_map(fn($r) => ['score' => (int)$r['score'], 'scored_at' => $r['t']], $ug),
        'shodan'  => array_map(fn($r) => ['score' => (int)$r['score'], 'scored_at' => $r['t']], $sh),
        'custom'  => array_map(fn($r) => ['score' => $intOrNull($r['score']), 'changed_at' => $toServerTime($r['t'])], $cu),
    ];
    if ($ugT || $shT || $cuT) $row['truncated'] = true;
    $data[] = $row;
}

apiSuccess([
    'data'              => $data,
    'upguard_max_score' => $srs->getUpguardMaxScore(),
    'timezone'          => $tzName,
    'since'             => $since,
    'total'             => $total,
    'limit'             => $limit,
    'offset'            => $offset,
]);
