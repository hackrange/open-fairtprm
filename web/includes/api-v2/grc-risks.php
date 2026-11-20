<?php
/**
 * RESTful API v2 - GRC risk register (/api/v2/grc/risks)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 *   GET  /grc/risks            list (limit/offset, updated_since, status, source)
 *   GET  /grc/risks/{id}       one risk
 *   POST /grc/risks            create, idempotent by (source, external_ref)
 *   PUT  /grc/risks/{id}       update a risk this integration created
 *   PUT  /grc/risks/{id}/review  status, review date, treatment plan on ANY risk
 *
 * Write-back rules (open-ctem risk exceptions, KEV-on-internet-facing,
 * validated attack paths):
 *   - write token + GRC module access required
 *   - source is allow-listed and external_ref is required, so a repeated sync
 *     updates instead of duplicating
 *   - an API write may only touch a row whose source matches AND that the
 *     token's own user created; a risk created in FairTPRM (source NULL) or by
 *     another integration user answers 404, exactly like a missing one
 *   - after creation only title, description, treatment_plan, status and
 *     review_date may change; likelihood, impact, treatment, category, owner
 *     and controls are human-owned and refused with 400
 *   - every write is audit-logged
 *
 * Review updates (/review) are the one way an integration touches a risk a
 * person created: only status, review_date and treatment_plan, only with a
 * read_write token whose user may edit risks in FairTPRM (admin or cyber_grc,
 * as on grc-risks.php), and only with acting_user naming the person in the
 * other tool. The audit entry keeps the before and after values so a GRC owner
 * can see and undo it.
 *
 * Included from grc.php in the router's scope.
 */

if (!defined('API_V2_ROUTER')) { http_response_code(404); exit; }

const API_RISK_SOURCES = ['open_ctem'];
const API_RISK_ENUMS = [
    'risk_category'  => ['strategic', 'operational', 'financial', 'compliance', 'reputational', 'technology', 'third_party'],
    'likelihood'     => ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'],
    'impact'         => ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'],
    'risk_treatment' => ['accept', 'mitigate', 'transfer', 'avoid'],
    'status'         => ['identified', 'assessing', 'treating', 'monitoring', 'closed'],
];
const API_RISK_UPDATABLE = ['title', 'description', 'treatment_plan', 'status', 'review_date'];
const API_RISK_REVIEWABLE = ['treatment_plan', 'status', 'review_date'];
const API_RISK_CREATABLE = ['source', 'external_ref', 'title', 'description', 'risk_category', 'likelihood', 'impact',
                            'risk_treatment', 'treatment_plan', 'status', 'review_date', 'vendor_id', 'control_ids'];

/** Validate the writable fields present in $in; 400 on anything wrong. */
function apiRiskValidate(array $in, array $allowedKeys): array {
    $unknown = array_diff(array_keys($in), $allowedKeys);
    if ($unknown) apiError(400, 'Field(s) not writable via the API: ' . implode(', ', array_map(fn($k) => substr(preg_replace('/[^a-z0-9_]/i', '', (string)$k), 0, 40), $unknown)));
    $out = [];
    foreach (API_RISK_ENUMS as $f => $vals) {
        if (!array_key_exists($f, $in)) continue;
        if (!is_string($in[$f]) || !in_array($in[$f], $vals, true)) apiError(400, "Invalid {$f}. Allowed: " . implode(', ', $vals));
        $out[$f] = $in[$f];
    }
    foreach (['title' => 500, 'description' => 10000, 'treatment_plan' => 10000] as $f => $max) {
        if (!array_key_exists($f, $in)) continue;
        if ($in[$f] !== null && !is_string($in[$f])) apiError(400, "{$f} must be a string");
        $v = trim((string)$in[$f]);
        if (mb_strlen($v) > $max) apiError(400, "{$f} is longer than {$max} characters");
        if ($f === 'title' && $v === '') apiError(400, 'title must not be empty');
        $out[$f] = $v === '' ? null : $v;
    }
    if (array_key_exists('review_date', $in)) {
        $d = $in['review_date'];
        if ($d !== null && (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)))) {
            apiError(400, 'review_date must be YYYY-MM-DD or null');
        }
        $out['review_date'] = $d;
    }
    return $out;
}

function apiRiskOut(array $r): array {
    $r['control_id_list'] = apiIdList($r['control_ids'] ?? null);
    return $r;
}

$riskSelect = 'SELECT r.*, u.full_name AS owner_name
                 FROM grc_risk_register r LEFT JOIN users u ON u.id = r.owner_user_id';
$tokenUserId = (int)$token['user_id'];

if ($method === 'GET' && $grcId === null) {
    [$limit, $offset] = apiLimitOffset(500, 1000);
    $where = ['1=1'];
    $params = [];
    if ($since !== null) { $where[] = 'r.updated_at >= :since'; $params[':since'] = $since; }
    if (!empty($_GET['status'])) {
        if (!in_array($_GET['status'], API_RISK_ENUMS['status'], true)) apiError(400, 'Invalid status');
        $where[] = 'r.status = :st'; $params[':st'] = $_GET['status'];
    }
    if (!empty($_GET['source'])) {
        if (!in_array($_GET['source'], API_RISK_SOURCES, true)) apiError(400, 'Invalid source');
        $where[] = 'r.source = :src'; $params[':src'] = $_GET['source'];
    }
    $w = implode(' AND ', $where);
    $risks = array_map('apiRiskOut', $db->fetchAll(
        "{$riskSelect} WHERE {$w} ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}", $params));
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM grc_risk_register r WHERE {$w}", $params)['c'] ?? 0);
    apiSuccess(['data' => $risks, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

if ($grcSub !== null && ($grcSub !== 'review' || $grcId === null || isset($segments[4]))) apiError(404, 'GRC resource not found');
if ($grcSub === 'review') {
    if ($method !== 'PUT') apiError(405, 'Method not allowed');
    if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
    if (!apiTokenUserAllowed($db, $token, ['administrator', 'cyber_grc'], true)) apiError(403, 'The token user cannot edit risks');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !$input || array_is_list($input)) apiError(400, 'JSON object request body required');
    $actor = $input['acting_user'] ?? null;
    $actor = is_string($actor) ? trim($actor) : '';
    if (!preg_match('/^[\p{L}\p{N} ._@,()\'-]{1,128}$/u', $actor)) {
        apiError(400, 'acting_user is required: who made this change in the other tool (1-128 letters, digits, spaces and . _ @ , ( ) \' -)');
    }
    unset($input['acting_user']);
    $update = apiRiskValidate($input, API_RISK_REVIEWABLE);
    if (!$update) apiError(400, 'No reviewable fields. Allowed: ' . implode(', ', API_RISK_REVIEWABLE));
    $before = $db->fetchOne('SELECT id, risk_ref, status, review_date, treatment_plan FROM grc_risk_register WHERE id = :id', [':id' => $grcId]);
    if (!$before) apiError(404, 'Risk not found');
    // Only what actually changes is written and audited.
    $changed = array_filter($update, fn($v, $k) => (string)($before[$k] ?? '') !== (string)($v ?? ''), ARRAY_FILTER_USE_BOTH);
    if ($changed) {
        $db->update('grc_risk_register', $changed, 'id = :id', [':id' => $grcId]);
        Auth::recordEvent($tokenUserId, 'grc_risk_api_review', 'grc_risk_register', $grcId, [
            'acting_user' => $actor, 'token_id' => (int)$token['id'], 'risk_ref' => $before['risk_ref'],
            'before' => array_intersect_key($before, $changed), 'after' => $changed,
        ]);
    }
    apiSuccess(['data' => apiRiskOut($db->fetchOne("{$riskSelect} WHERE r.id = :id", [':id' => $grcId]))]);
}

if ($method === 'GET' && $grcId !== null) {
    $risk = $db->fetchOne("{$riskSelect} WHERE r.id = :id", [':id' => $grcId]);
    if (!$risk) apiError(404, 'Risk not found');
    apiSuccess(['data' => apiRiskOut($risk)]);
}

if (($method === 'POST' && $grcId === null) || ($method === 'PUT' && $grcId !== null)) {
    if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !$input) apiError(400, 'JSON request body required');
}

if ($method === 'PUT' && $grcId !== null) {
    $existing = $db->fetchOne('SELECT id, source, external_ref, created_by FROM grc_risk_register WHERE id = :id', [':id' => $grcId]);
    // Only a risk this integration user created; anything else looks missing.
    if (!$existing || $existing['source'] === null || (int)$existing['created_by'] !== $tokenUserId
        || (isset($input['source']) && $input['source'] !== $existing['source'])) {
        apiError(404, 'Risk not found');
    }
    unset($input['source'], $input['external_ref']);
    $update = apiRiskValidate($input, API_RISK_UPDATABLE);
    if (!$update) apiError(400, 'No updatable fields. Allowed: ' . implode(', ', API_RISK_UPDATABLE));
    $db->update('grc_risk_register', $update, 'id = :id', [':id' => $grcId]);
    Auth::recordEvent($tokenUserId, 'grc_risk_api_update', 'grc_risk_register', $grcId, [
        'source' => $existing['source'], 'external_ref' => $existing['external_ref'],
        'fields' => array_keys($update), 'token_id' => (int)$token['id'],
    ]);
    apiSuccess(['data' => apiRiskOut($db->fetchOne("{$riskSelect} WHERE r.id = :id", [':id' => $grcId])), 'created' => false]);
}

if ($method === 'POST' && $grcId === null) {
    $source = $input['source'] ?? null;
    $extRef = $input['external_ref'] ?? null;
    if (!is_string($source) || !in_array($source, API_RISK_SOURCES, true)) apiError(400, 'source is required. Allowed: ' . implode(', ', API_RISK_SOURCES));
    if (!is_string($extRef) || !preg_match('/^[A-Za-z0-9._:\-]{1,191}$/', $extRef)) apiError(400, 'external_ref is required (1-191 chars: letters, digits, . _ : -)');

    $existing = $db->fetchOne('SELECT id, created_by FROM grc_risk_register WHERE source = :s AND external_ref = :x',
        [':s' => $source, ':x' => $extRef]);
    if ($existing) {
        // Repeated sync: update in place, but only what PUT may change.
        if ((int)$existing['created_by'] !== $tokenUserId) apiError(409, 'external_ref already belongs to another integration user');
        $update = apiRiskValidate(array_intersect_key($input, array_flip(API_RISK_UPDATABLE)), API_RISK_UPDATABLE);
        apiRiskValidate($input, API_RISK_CREATABLE);   // still reject unknown or malformed fields
        if ($update) {
            $db->update('grc_risk_register', $update, 'id = :id', [':id' => (int)$existing['id']]);
            Auth::recordEvent($tokenUserId, 'grc_risk_api_update', 'grc_risk_register', (int)$existing['id'], [
                'source' => $source, 'external_ref' => $extRef, 'fields' => array_keys($update), 'token_id' => (int)$token['id'],
            ]);
        }
        apiSuccess(['data' => apiRiskOut($db->fetchOne("{$riskSelect} WHERE r.id = :id", [':id' => (int)$existing['id']])), 'created' => false]);
    }

    $fields = apiRiskValidate($input, API_RISK_CREATABLE);
    if (empty($fields['title'])) apiError(400, 'title is required');
    $vendorId = null;
    if (isset($input['vendor_id']) && $input['vendor_id'] !== null) {
        $vendorId = filter_var($input['vendor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$vendorId || !$db->fetchOne('SELECT id FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId])) apiError(400, 'vendor_id does not exist');
    }
    $controlIds = [];
    if (isset($input['control_ids'])) {
        if (!is_array($input['control_ids']) || count($input['control_ids']) > 100) apiError(400, 'control_ids must be an array of control ids');
        foreach ($input['control_ids'] as $cid) {
            $cid = filter_var($cid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$cid || !$db->fetchOne('SELECT id FROM grc_internal_controls WHERE id = :id', [':id' => $cid])) apiError(400, 'control_ids contains an unknown control');
            $controlIds[] = $cid;
        }
    }
    $lNum = array_search($fields['likelihood'] ?? 'possible', API_RISK_ENUMS['likelihood'], true) + 1;
    $iNum = array_search($fields['impact'] ?? 'moderate', API_RISK_ENUMS['impact'], true) + 1;
    $max = (int)($db->fetchOne("SELECT MAX(CAST(SUBSTRING(risk_ref, 5) AS UNSIGNED)) AS n FROM grc_risk_register WHERE risk_ref REGEXP '^RSK-[0-9]+$'")['n'] ?? 0);

    try {
        $db->insert('grc_risk_register', [
            'risk_ref'            => 'RSK-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT),
            'title'               => $fields['title'],
            'description'         => $fields['description'] ?? null,
            'risk_category'       => $fields['risk_category'] ?? 'technology',
            'likelihood'          => $fields['likelihood'] ?? 'possible',
            'impact'              => $fields['impact'] ?? 'moderate',
            'inherent_risk_score' => $lNum * $iNum,
            'residual_risk_score' => $lNum * $iNum,
            'risk_treatment'      => $fields['risk_treatment'] ?? 'mitigate',
            'treatment_plan'      => $fields['treatment_plan'] ?? null,
            'owner_user_id'       => null,
            'control_ids'         => $controlIds ? implode(',', $controlIds) : null,
            'status'              => $fields['status'] ?? 'identified',
            'review_date'         => $fields['review_date'] ?? null,
            'vendor_id'           => $vendorId,
            'source'              => $source,
            'external_ref'        => $extRef,
            'created_by'          => $tokenUserId,
        ]);
    } catch (Exception $e) {
        // A concurrent sync won the (source, external_ref) or risk_ref race.
        if (stripos($e->getMessage(), 'Duplicate entry') !== false) apiError(409, 'Conflicting concurrent write; retry');
        throw $e;
    }
    $newId = (int)$db->lastInsertId();
    Auth::recordEvent($tokenUserId, 'grc_risk_api_create', 'grc_risk_register', $newId, [
        'source' => $source, 'external_ref' => $extRef, 'token_id' => (int)$token['id'],
    ]);
    apiSuccess(['data' => apiRiskOut($db->fetchOne("{$riskSelect} WHERE r.id = :id", [':id' => $newId])), 'created' => true], 201);
}

apiError(405, 'Method not allowed');
