<?php
/**
 * RESTful API v2 - GRC control test attestations (/api/v2/grc/controls/{id}/tests)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 *   GET  /grc/controls/{id}/tests   the control's tests, newest first (limit 1-100, offset)
 *   POST /grc/controls/{id}/tests   record a test, idempotent by (source, external_ref)
 *
 * open-ctem tests a control and records an attestation: what was tested, who
 * tested it, the expected result, the outcome, pass or fail, and why it failed.
 * The integration owns these rows; FairTPRM shows them read-only.
 *
 * Write rules (same shape as the integration-owned risk writes):
 *   - read_write token + GRC module access (checked by the router)
 *   - {id} must be an active control, otherwise 404
 *   - a repeat of (source, external_ref) updates that test and its evidence;
 *     it must come from the same token user and name the same control (409)
 *   - the control's last_tested_at, next_test_due and effectiveness follow
 *     only its newest test (by tested_at); no other control field is touched
 *   - newest event wins between tests and monitor runs: when a monitor that
 *     covers the control ran after the newest test, the monitor's result keeps
 *     effectiveness and last_tested_at (next_test_due always follows the test)
 *   - only the newest test's evidence stays current; older ones are superseded
 *   - every test is mirrored as grc_evidence (control_test / attestation)
 *     linked to the control, so audits and evidence packages carry it
 *   - every write is audit-logged
 *
 * Timestamps: tested_at is stored in the application time zone (app.timezone),
 * like every other PHP-written time (monitor runs, last_tested_at, evidence),
 * so tests and monitor runs compare correctly. The API always returns it as
 * ISO 8601 UTC with a Z suffix. A tested_at sent without an offset is read as UTC.
 *
 * Included from grc.php in the router's scope.
 */

if (!defined('API_V2_ROUTER')) { http_response_code(404); exit; }

const API_CTEST_TEXT_MAX = 5000;

/** Shape a grc_control_tests row for output. */
function apiCtestOut(array $r): array {
    return [
        'id'              => (int)$r['id'],
        'control_id'      => (int)$r['control_id'],
        'source'          => $r['source'],
        'external_ref'    => $r['external_ref'],
        'tested_at'       => apiCtestIso($r['tested_at']),
        'tested_by'       => $r['tested_by'],
        'what_tested'     => $r['what_tested'],
        'expected_result' => $r['expected_result'],
        'outcome'         => $r['outcome'],
        'result'          => $r['result'],
        'failure_reason'  => $r['failure_reason'],
        'next_test_due'   => $r['next_test_due'],
        'frequency_days'  => $r['frequency_days'] === null ? null : (int)$r['frequency_days'],
        'evidence_id'     => $r['evidence_id'] === null ? null : (int)$r['evidence_id'],
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

/** A required 1-5000 character text field; 400 names the field. */
function apiCtestText(array $in, string $f): string {
    $v = $in[$f] ?? null;
    if (!is_string($v)) apiError(400, "{$f} is required (1-" . API_CTEST_TEXT_MAX . ' characters)');
    $v = trim($v);
    if ($v === '') apiError(400, "{$f} must not be empty");
    if (mb_strlen($v) > API_CTEST_TEXT_MAX) apiError(400, "{$f} is longer than " . API_CTEST_TEXT_MAX . ' characters');
    if (str_contains($v, "\0")) apiError(400, "{$f} contains a NUL character");
    return $v;
}

/** Validate the POST body. Returns the row values; 400 on the first problem. Unknown fields are ignored. */
function apiCtestValidate(array $in): array {
    $out = [];

    $s = $in['source'] ?? null;
    if (!is_string($s) || !preg_match('/^[a-z0-9_-]{1,32}$/', $s)) apiError(400, 'source is required: 1-32 characters of a-z, 0-9, _ and -');
    $out['source'] = $s;

    $x = $in['external_ref'] ?? null;
    if (!is_string($x) || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $x)) {
        apiError(400, 'external_ref is required and must be a UUID');
    }
    $out['external_ref'] = $x;   // the unique key's collation is case-insensitive

    // ISO 8601 date-time. DateTimeImmutable alone rolls 2026-02-30 over to March,
    // so the calendar date and clock are checked first.
    $t = $in['tested_at'] ?? null;
    $m = [];
    if (!is_string($t) || strlen($t) > 40
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+\-]\d{2}:?\d{2})?$/', $t, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59) {
        apiError(400, 'tested_at must be an ISO 8601 date-time, e.g. 2026-11-22T14:05:00Z');
    }
    try {
        $dt = new DateTimeImmutable($t, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        apiError(400, 'tested_at must be an ISO 8601 date-time, e.g. 2026-11-22T14:05:00Z');
    }
    if ($dt->getTimestamp() > time() + 86400) apiError(400, 'tested_at must not be more than 1 day in the future');
    if ($dt->getTimestamp() < 0) apiError(400, 'tested_at is out of range');
    $out['tested_at'] = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');

    $b = $in['tested_by'] ?? null;
    $b = is_string($b) ? trim($b) : null;
    if ($b === null || !preg_match('/^[\p{L}\p{N} .@,()\'-]{1,128}$/u', $b)) {
        apiError(400, "tested_by is required: 1-128 letters, digits, spaces and . @ , ( ) ' -");
    }
    $out['tested_by'] = $b;

    foreach (['what_tested', 'expected_result', 'outcome'] as $f) $out[$f] = apiCtestText($in, $f);

    $r = $in['result'] ?? null;
    if ($r !== 'pass' && $r !== 'fail') apiError(400, 'result must be "pass" or "fail"');
    $out['result'] = $r;

    // Required on a fail; ignored (stored as null) on a pass.
    $out['failure_reason'] = $r === 'fail' ? apiCtestText($in, 'failure_reason') : null;

    $d = $in['next_test_due'] ?? null;
    if ($d !== null && (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)))) {
        apiError(400, 'next_test_due must be YYYY-MM-DD or null');
    }
    $out['next_test_due'] = $d;

    $fd = $in['frequency_days'] ?? null;
    if ($fd !== null && (!is_int($fd) || $fd < 1 || $fd > 3650)) apiError(400, 'frequency_days must be an integer from 1 to 3650, or null');
    $out['frequency_days'] = $fd;

    return $out;
}

/** Title and body of the evidence record that mirrors a test. */
function apiCtestEvidenceFields(array $t, array $control): array {
    $lines = [
        'Control test attestation recorded by ' . $t['source'] . ' (' . $t['external_ref'] . ').',
        'Tested at: ' . apiCtestIso($t['tested_at']),
        'Tested by: ' . $t['tested_by'],
        'Result: ' . strtoupper($t['result']),
        '',
        'What was tested:',
        $t['what_tested'],
        '',
        'Expected result:',
        $t['expected_result'],
        '',
        'Outcome:',
        $t['outcome'],
    ];
    if ($t['result'] === 'fail') array_push($lines, '', 'Failure reason:', (string)$t['failure_reason']);
    if ($t['next_test_due'] !== null) array_push($lines, '', 'Next test due: ' . $t['next_test_due']);
    return [
        'title'             => mb_substr('Control test: ' . $control['control_ref'] . ' ' . $t['result'], 0, 500),
        'description'       => implode("\n", $lines),
        'evidence_type'     => 'control_test',
        'collection_method' => 'attestation',
        'collected_at'      => $t['tested_at'],
        'valid_from'        => $t['tested_at'],
        'valid_until'       => $t['next_test_due'] !== null ? $t['next_test_due'] . ' 23:59:59' : null,
        'status'            => 'current',
    ];
}

/** Newest test of a control (tested_at, then id), or null. */
function apiCtestNewest(Database $db, int $controlId): ?array {
    return $db->fetchOne('SELECT id, tested_at, result, next_test_due, evidence_id FROM grc_control_tests
                           WHERE control_id = :c ORDER BY tested_at DESC, id DESC LIMIT 1', [':c' => $controlId]) ?: null;
}

/** Newest run of any monitor covering the control: ['run_at', 'result'] or null. */
function apiCtestNewestMonitorRun(Database $db, int $controlId): ?array {
    $ids = [];
    foreach ($db->fetchAll('SELECT id, control_ids FROM grc_continuous_monitors') as $m) {
        if (in_array($controlId, apiIdList($m['control_ids']), true)) $ids[] = (int)$m['id'];
    }
    if (!$ids) return null;
    $in = []; $params = [];
    foreach ($ids as $i => $id) { $in[] = ":m{$i}"; $params[":m{$i}"] = $id; }
    return $db->fetchOne('SELECT run_at, result FROM grc_monitor_results WHERE monitor_id IN (' . implode(', ', $in) . ')
                           ORDER BY run_at DESC, id DESC LIMIT 1', $params) ?: null;
}

if ($grcId === null || isset($segments[4])) apiError(404, 'GRC resource not found');

$ctestControl = $db->fetchOne('SELECT id, control_ref, is_active FROM grc_internal_controls WHERE id = :id', [':id' => $grcId]);
$ctestTokenUser = (int)$token['user_id'];

if ($method === 'GET') {
    if (!$ctestControl || (int)$ctestControl['is_active'] !== 1) apiError(404, 'Control not found');
    [$limit, $offset] = apiLimitOffset(100, 100);
    $rows = $db->fetchAll("SELECT * FROM grc_control_tests WHERE control_id = :c
                            ORDER BY tested_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}", [':c' => $grcId]);
    $total = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM grc_control_tests WHERE control_id = :c', [':c' => $grcId])['c'] ?? 0);
    apiSuccess(['data' => array_map('apiCtestOut', $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

if ($method !== 'POST') apiError(405, 'Method not allowed');
if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
if (!$ctestControl || (int)$ctestControl['is_active'] !== 1) apiError(404, 'Control not found');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !$input || array_is_list($input)) apiError(400, 'JSON object request body required');
$test = apiCtestValidate($input);

$existing = $db->fetchOne('SELECT * FROM grc_control_tests WHERE source = :s AND external_ref = :x',
    [':s' => $test['source'], ':x' => $test['external_ref']]);
if ($existing && (int)$existing['created_by'] !== $ctestTokenUser) apiError(409, 'external_ref already belongs to another integration user');
if ($existing && (int)$existing['control_id'] !== $grcId) apiError(409, 'external_ref is already recorded on another control');

$created = !$existing;
$prevNewest = apiCtestNewest($db, $grcId);
$controlUpdated = false;
$db->beginTransaction();
try {
    if ($created) {
        $db->insert('grc_control_tests', ['control_id' => $grcId, 'created_by' => $ctestTokenUser] + $test);
        $testId = (int)$db->lastInsertId();
        $evidenceId = null;
    } else {
        $testId = (int)$existing['id'];
        // The idempotency key stays as first recorded.
        $test['source'] = $existing['source'];
        $test['external_ref'] = $existing['external_ref'];
        $db->update('grc_control_tests', array_diff_key($test, ['source' => 1, 'external_ref' => 1]), 'id = :id', [':id' => $testId]);
        $evidenceId = $existing['evidence_id'] === null ? null : (int)$existing['evidence_id'];
    }

    // Evidence that mirrors the test. Re-created when someone deleted it in FairTPRM.
    $ev = apiCtestEvidenceFields($test, $ctestControl);
    if ($evidenceId !== null && $db->fetchOne('SELECT id FROM grc_evidence WHERE id = :id', [':id' => $evidenceId])) {
        $db->update('grc_evidence', $ev, 'id = :id', [':id' => $evidenceId]);
    } else {
        $db->insert('grc_evidence', $ev + [
            'evidence_ref' => 'EV-CTEST-' . str_pad((string)$testId, 6, '0', STR_PAD_LEFT),
            'collected_by' => $ctestTokenUser,
        ]);
        $evidenceId = (int)$db->lastInsertId();
        $db->update('grc_control_tests', ['evidence_id' => $evidenceId], 'id = :id', [':id' => $testId]);
    }
    if (!$db->fetchOne('SELECT id FROM grc_evidence_control_map WHERE evidence_id = :e AND control_id = :c', [':e' => $evidenceId, ':c' => $grcId])) {
        $db->insert('grc_evidence_control_map', [
            'evidence_id' => $evidenceId, 'control_id' => $grcId, 'linked_by' => $ctestTokenUser,
            'notes' => 'Control test attestation from ' . $test['source'],
        ]);
    }

    // The control follows its newest test only. An update that moves the
    // previously newest test back in time hands the control to the new newest.
    // Newest event wins: a monitor run later than that test keeps its result.
    $newest = apiCtestNewest($db, $grcId);
    if ($newest && ((int)$newest['id'] === $testId || ($prevNewest && (int)$prevNewest['id'] === $testId))) {
        $run = apiCtestNewestMonitorRun($db, $grcId);
        if ($run && (string)$run['run_at'] > (string)$newest['tested_at']) {
            // Same mapping as ContinuousMonitor::updateControlComplianceFromMonitor().
            $state = ['last_tested_at' => $run['run_at'], 'effectiveness' => match ($run['result']) {
                'pass' => 'effective', 'warning' => 'partially_effective', default => 'ineffective',
            }];
        } else {
            $state = ['last_tested_at' => $newest['tested_at'], 'effectiveness' => $newest['result'] === 'pass' ? 'effective' : 'ineffective'];
        }
        $db->update('grc_internal_controls', $state + ['next_test_due' => $newest['next_test_due']], 'id = :id', [':id' => $grcId]);
        $controlUpdated = true;
    }

    // Only the newest test's evidence is current; older test evidence stays
    // linked (audit history) but is superseded.
    if ($newest && $newest['evidence_id'] !== null) {
        $db->query("UPDATE grc_evidence SET status = CASE WHEN id = :cur THEN 'current' ELSE 'superseded' END
                     WHERE id IN (SELECT evidence_id FROM grc_control_tests WHERE control_id = :c AND evidence_id IS NOT NULL)",
            [':cur' => (int)$newest['evidence_id'], ':c' => $grcId]);
    }
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollback();
    // A concurrent sync won the (source, external_ref) or evidence_ref race.
    if (stripos($e->getMessage(), 'Duplicate entry') !== false) apiError(409, 'Conflicting concurrent write; retry');
    throw $e;   // the router logs it and answers a generic 500
}

Auth::recordEvent($ctestTokenUser, $created ? 'grc_control_test_api_create' : 'grc_control_test_api_update', 'grc_control_tests', $testId, [
    'control_id'      => $grcId,
    'control_ref'     => $ctestControl['control_ref'],
    'source'          => $test['source'],
    'external_ref'    => $test['external_ref'],
    'tested_by'       => $test['tested_by'],
    'tested_at'       => apiCtestIso($test['tested_at']),
    'result'          => $test['result'],
    'evidence_id'     => $evidenceId,
    'control_updated' => $controlUpdated,
    'token_id'        => (int)$token['id'],
]);

$row = apiCtestOut($db->fetchOne('SELECT * FROM grc_control_tests WHERE id = :id', [':id' => $testId]));
apiSuccess(['data' => array_intersect_key($row, array_flip([
    'id', 'control_id', 'source', 'external_ref', 'tested_at', 'tested_by', 'result', 'failure_reason', 'next_test_due',
])) + ['evidence_id' => $evidenceId, 'created' => $created]], $created ? 201 : 200);
