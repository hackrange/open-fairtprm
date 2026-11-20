<?php
/**
 * RESTful API v2 - GRC endpoints (/api/v2/grc/...)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Included by api/v2/router.php after the token is authenticated and GRC
 * module access is checked; runs in the router's scope ($db, $grc, $token,
 * $apiService, $method, $segments). Lives under includes/, which the web
 * server never serves, so it cannot be requested directly.
 *
 * List endpoints accept updated_since=<ISO 8601> for incremental sync.
 * Risk write endpoints are in grc-risks.php.
 */

if (!defined('API_V2_ROUTER')) { http_response_code(404); exit; }

/** Parse updated_since into a DB-local 'Y-m-d H:i:s', null when absent; 400 when malformed. */
function apiUpdatedSince(): ?string {
    $raw = $_GET['updated_since'] ?? null;
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || strlen($raw) > 40
        || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+\-]\d{2}:?\d{2})?)?$/', $raw)) {
        apiError(400, 'updated_since must be an ISO 8601 date or date-time');
    }
    try {
        $dt = new DateTimeImmutable($raw);
    } catch (Exception $e) {
        apiError(400, 'updated_since must be an ISO 8601 date or date-time');
    }
    return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/** Keep rows whose updated_at is at or after $since (for service-built lists). */
function apiFilterSince(array $rows, ?string $since): array {
    if ($since === null) return $rows;
    return array_values(array_filter($rows, fn($r) => !isset($r['updated_at']) || (string)$r['updated_at'] >= $since));
}

function apiLimitOffset(int $default, int $max): array {
    return [min($max, max(1, (int)($_GET['limit'] ?? $default))), max(0, (int)($_GET['offset'] ?? 0))];
}

/** control_ids is stored as a JSON array or (from the risk form) a comma list. */
function apiIdList($raw): array {
    if ($raw === null || $raw === '') return [];
    $j = json_decode((string)$raw, true);
    $list = is_array($j) ? $j : explode(',', (string)$raw);
    return array_values(array_filter(array_map('intval', $list), fn($v) => $v > 0));
}

$grcResource = $segments[1] ?? '';
$grcId = isset($segments[2]) && ctype_digit((string)$segments[2]) ? (int)$segments[2] : null;
$grcSub = $segments[3] ?? null;
$since = $method === 'GET' ? apiUpdatedSince() : null;

switch ($grcResource) {
    case 'dashboard':
        if ($method === 'GET') {
            apiSuccess(['data' => $grc->getDashboardStats()]);
        }
        break;

    case 'frameworks':
        if ($method === 'GET' && $grcId === null) {
            apiSuccess(['data' => apiFilterSince($grc->getFrameworks(), $since)]);
        } elseif ($method === 'GET' && $grcId !== null && $grcSub === 'requirements') {
            apiSuccess(['data' => $grc->getAllRequirements($grcId)]);
        } elseif ($method === 'GET' && $grcId !== null && $grcSub === 'status') {
            apiSuccess(['data' => $grc->getFrameworkComplianceStatus($grcId)]);
        } elseif ($method === 'GET' && $grcId !== null) {
            $fw = $grc->getFramework($grcId);
            if (!$fw) apiError(404, 'Framework not found');
            apiSuccess(['data' => $fw]);
        } elseif ($method === 'PUT' && $grcId !== null) {
            if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
            $fw = $grc->getFramework($grcId);
            if (!$fw) apiError(404, 'Framework not found');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) apiError(400, 'Request body required');
            $allowed = ['name', 'description', 'is_active', 'compliance_year', 'scope_id'];
            $update = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) $update[$f] = $input[$f];
            }
            if (!empty($update)) {
                $db->update('grc_frameworks', $update, 'id = :id', [':id' => $grcId]);
            }
            apiSuccess(['data' => $grc->getFramework($grcId)]);
        }
        break;

    case 'controls':
        if ($method === 'GET' && $grcId === null) {
            $filters = array_intersect_key($_GET, array_flip(['status', 'framework_id', 'search', 'type']));
            $controls = apiFilterSince($grc->getControls($filters), $since);
            // Mapped requirement refs per framework (not just framework names).
            $reqs = [];
            foreach ($db->fetchAll(
                'SELECT crm.control_id, crm.coverage, fr.id AS requirement_id, fr.requirement_ref,
                        f.id AS framework_id, f.code AS framework_code
                   FROM grc_control_requirement_map crm
                   JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
                   JOIN grc_frameworks f ON f.id = fr.framework_id
                  ORDER BY f.code, fr.requirement_ref') as $r) {
                $cid = (int)$r['control_id'];
                unset($r['control_id']);
                $reqs[$cid][] = $r;
            }
            foreach ($controls as &$c) $c['requirements'] = $reqs[(int)$c['id']] ?? [];
            unset($c);
            apiSuccess(['data' => $controls]);
        } elseif ($method === 'GET' && $grcId !== null) {
            $control = $grc->getControl($grcId);
            if (!$control) apiError(404, 'Control not found');
            apiSuccess(['data' => $control]);
        } elseif ($method === 'POST' && $grcId === null) {
            if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || empty($input['title'])) apiError(400, 'Title is required');
            $id = $grc->createControl($input, (int)$token['user_id']);
            apiSuccess(['data' => $grc->getControl($id)], 201);
        } elseif ($method === 'PUT' && $grcId !== null) {
            if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) apiError(400, 'Request body required');
            $grc->updateControl($grcId, $input);
            apiSuccess(['data' => $grc->getControl($grcId)]);
        }
        break;

    case 'evidence':
        if ($method === 'GET') {
            [$limit, $offset] = apiLimitOffset(50, 200);
            // Metadata only: encrypted file, screenshot and API payload columns never leave.
            $where = ['1=1'];
            $params = [];
            if ($since !== null) { $where[] = 'e.updated_at >= :since'; $params[':since'] = $since; }
            $w = implode(' AND ', $where);
            $evidence = $db->fetchAll(
                "SELECT e.id, e.evidence_ref, e.title, e.evidence_type, e.collection_method, e.status,
                        e.monitor_id, e.collected_at, e.valid_from, e.valid_until, e.updated_at,
                        e.content_sha256, e.signature_key_id,
                        (e.valid_until IS NOT NULL AND e.valid_until < NOW()) AS is_expired,
                        (SELECT GROUP_CONCAT(m.control_id ORDER BY m.control_id) FROM grc_evidence_control_map m
                          WHERE m.evidence_id = e.id) AS control_ids
                   FROM grc_evidence e WHERE {$w}
                  ORDER BY e.collected_at DESC, e.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
            foreach ($evidence as &$ev) $ev['control_ids'] = apiIdList($ev['control_ids']);
            unset($ev);
            $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM grc_evidence e WHERE {$w}", $params)['c'] ?? 0);
            apiSuccess(['data' => $evidence, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        }
        break;

    case 'policies':
        $policyService = PolicyService::getInstance();
        if ($method === 'GET' && $grcId === null) {
            apiSuccess(['data' => apiFilterSince($policyService->getPolicies(), $since)]);
        } elseif ($method === 'GET' && $grcId !== null) {
            $policy = $policyService->getPolicy($grcId);
            if (!$policy) apiError(404, 'Policy not found');
            apiSuccess(['data' => $policy]);
        }
        break;

    case 'requirements':
        if ($method === 'PUT' && $grcId !== null) {
            if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
            $req = $db->fetchOne('SELECT id FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
            if (!$req) apiError(404, 'Requirement not found');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) apiError(400, 'Request body required');
            $allowed = ['title', 'description', 'guidance', 'is_required', 'sort_order'];
            $update = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) $update[$f] = $input[$f];
            }
            if (!empty($update)) {
                $db->update('grc_framework_requirements', $update, 'id = :id', [':id' => $grcId]);
            }
            $updated = $db->fetchOne('SELECT * FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
            apiSuccess(['data' => $updated]);
        } elseif ($method === 'GET' && $grcId !== null) {
            $req = $db->fetchOne('SELECT * FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
            if (!$req) apiError(404, 'Requirement not found');
            apiSuccess(['data' => $req]);
        } else {
            apiError(405, 'Method not allowed');
        }
        break;

    case 'assessments':
        // Read-only results of the unified (CSF maturity) assessments: scores and counts
        // only. Never the encrypted report PDF, assessor notes, remediation text or who
        // answered what. Same audience as grc-assessment.php.
        if ($method !== 'GET') apiError(405, 'Method not allowed');
        if ($grcSub !== null || (isset($segments[2]) && $grcId === null)) apiError(404, 'GRC resource not found');
        if (!apiTokenUserAllowed($db, $token, ['administrator', 'cyber_grc', 'auditor', 'grc_contributors'], true)) {
            apiError(403, 'The token user cannot view GRC assessments');
        }
        require_once dirname(__DIR__) . '/classes/UnifiedAssessmentService.php';
        $uas = UnifiedAssessmentService::getInstance();
        $cols = 'id, assessment_ref, title, assessment_type, status, planned_start, planned_end,
                 actual_start, actual_end, overall_fairscore, overall_compliance_pct, created_at, updated_at';
        // Answer progress plus the mean maturity rating over the rated questions.
        $progress = function (int $aid) use ($uas, $db): array {
            $p = $uas->getAssessmentProgress($aid);
            $avg = $db->fetchOne('SELECT AVG(maturity_rating) AS m FROM grc_assessment_responses
                                   WHERE assessment_id = :aid AND maturity_rating IS NOT NULL', [':aid' => $aid])['m'] ?? null;
            $p['avg_maturity'] = $avg === null ? null : round((float)$avg, 2);
            return $p;
        };
        if ($grcId === null) {
            $params = [];
            $sinceSql = '';
            if ($since !== null) { $sinceSql = ' WHERE updated_at >= :since'; $params[':since'] = $since; }
            $rows = $db->fetchAll("SELECT {$cols} FROM grc_assessments{$sinceSql} ORDER BY created_at DESC, id DESC LIMIT 500", $params);
            foreach ($rows as &$row) $row['progress'] = $progress((int)$row['id']);
            unset($row);
            apiSuccess(['data' => $rows]);
        }
        $a = $db->fetchOne("SELECT {$cols} FROM grc_assessments WHERE id = :id", [':id' => $grcId]);
        if (!$a) apiError(404, 'Assessment not found');
        $a['progress'] = $progress($grcId);
        $a['domain_scores'] = $db->fetchAll(
            'SELECT d.domain_code, d.name AS domain_name, ds.average_score, ds.questions_total,
                    ds.questions_answered, ds.conforming_count, ds.partial_count,
                    ds.non_conforming_count, ds.not_applicable_count,
                    (SELECT mt.target_score FROM grc_maturity_targets mt
                      WHERE mt.domain_id = ds.domain_id ORDER BY mt.id DESC LIMIT 1) AS target_score
               FROM grc_assessment_domain_scores ds
               JOIN grc_security_domains d ON d.id = ds.domain_id
              WHERE ds.assessment_id = :aid ORDER BY d.sort_order, d.id',
            [':aid' => $grcId]
        );
        $a['framework_compliance'] = array_map(fn($f) => array_intersect_key($f, array_flip([
            'framework_code', 'framework_name', 'total_mapped', 'conforming', 'partial', 'non_conforming',
            'not_applicable', 'compliance_pct', 'avg_maturity', 'maturity_count',
        ])), $uas->calculateFrameworkCompliance($grcId));
        $a['gaps'] = $db->fetchAll(
            "SELECT q.question_ref, q.question_text, d.domain_code, d.name AS domain_name,
                    r.conformity_status, r.maturity_rating, r.validation_status, r.assessed_at
               FROM grc_assessment_responses r
               JOIN grc_unified_questions q ON q.id = r.question_id
               JOIN grc_security_domains d ON d.id = q.domain_id
              WHERE r.assessment_id = :aid AND r.conformity_status IN ('non_conforming', 'partial')
              ORDER BY FIELD(r.conformity_status, 'non_conforming', 'partial'), d.sort_order, q.sort_order, q.id
              LIMIT 500",
            [':aid' => $grcId]
        );
        apiSuccess(['data' => $a]);
        break;

    case 'audits':
        if ($method === 'GET' && $grcId === null) {
            $params = [];
            $sinceSql = '';
            if ($since !== null) { $sinceSql = ' WHERE a.updated_at >= :since'; $params[':since'] = $since; }
            $audits = $db->fetchAll(
                'SELECT a.*, f.code as framework_code
                 FROM grc_audits a LEFT JOIN grc_frameworks f ON f.id = a.framework_id' . $sinceSql . '
                 ORDER BY a.created_at DESC', $params
            );
            foreach ($audits as &$a) unset($a['report_encrypted_data']);
            unset($a);
            apiSuccess(['data' => $audits]);
        } elseif ($method === 'GET' && $grcId !== null) {
            $audit = $db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $grcId]);
            if (!$audit) apiError(404, 'Audit not found');
            unset($audit['report_encrypted_data']);
            $audit['findings'] = $db->fetchAll(
                'SELECT * FROM grc_audit_findings WHERE audit_id = :aid ORDER BY severity DESC',
                [':aid' => $grcId]
            );
            apiSuccess(['data' => $audit]);
        } elseif ($method === 'PUT' && $grcId !== null) {
            if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
            $audit = $db->fetchOne('SELECT id FROM grc_audits WHERE id = :id', [':id' => $grcId]);
            if (!$audit) apiError(404, 'Audit not found');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) apiError(400, 'Request body required');

            $validStatuses = ['planning', 'fieldwork', 'reporting', 'remediation', 'closed'];
            if (isset($input['status']) && !in_array($input['status'], $validStatuses, true)) {
                apiError(400, 'Invalid status. Allowed: ' . implode(', ', $validStatuses));
            }
            $validTypes = ['internal', 'external', 'certification', 'surveillance', 'readiness'];
            if (isset($input['audit_type']) && !in_array($input['audit_type'], $validTypes, true)) {
                apiError(400, 'Invalid audit_type. Allowed: ' . implode(', ', $validTypes));
            }

            $allowed = ['title', 'description', 'status', 'audit_type', 'planned_start', 'planned_end'];
            $update = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) $update[$f] = $input[$f];
            }
            if (!empty($update)) {
                $db->update('grc_audits', $update, 'id = :id', [':id' => $grcId]);
            }
            $updated = $db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $grcId]);
            unset($updated['report_encrypted_data']);
            apiSuccess(['data' => $updated]);
        }
        break;

    case 'crosswalk':
        if ($method === 'GET') {
            $srcId = (int)($_GET['source_framework_id'] ?? 0);
            $tgtId = (int)($_GET['target_framework_id'] ?? 0);
            if (!$srcId || !$tgtId) apiError(400, 'source_framework_id and target_framework_id required');
            apiSuccess(['data' => $grc->getCrosswalk($srcId, $tgtId)]);
        }
        break;

    case 'monitors':
        if ($method === 'GET') {
            // Explicit columns: never collector_config, credentials or integration secrets.
            $params = [];
            $sinceSql = '';
            if ($since !== null) { $sinceSql = ' WHERE m.updated_at >= :since'; $params[':since'] = $since; }
            $monitors = $db->fetchAll(
                'SELECT m.id, m.monitor_ref, m.name, m.description, m.integration_id,
                        i.name AS integration_name, i.integration_type,
                        m.collector_class, m.check_type, m.frequency, m.is_enabled, m.attach_report,
                        m.control_ids, m.last_result, m.last_run_at, m.next_run_at, m.failure_count,
                        m.created_at, m.updated_at
                   FROM grc_continuous_monitors m
                   LEFT JOIN grc_integrations i ON i.id = m.integration_id' . $sinceSql . '
                  ORDER BY m.name', $params);
            foreach ($monitors as &$m) $m['control_ids'] = apiIdList($m['control_ids']);
            unset($m);
            apiSuccess(['data' => $monitors]);
        }
        break;

    case 'risks':
        require __DIR__ . '/grc-risks.php';
        break;

    default:
        apiError(404, 'GRC resource not found: ' . $grcResource);
}
apiError(405, 'Method not allowed');
