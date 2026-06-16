<?php
/**
 * Assessment PDF Import API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Accepts a completed fillable PDF that was previously generated for this
 * assessment, verifies the embedded Reference matches the assessment's UUID,
 * extracts every AcroForm field value, maps each value back to the correct
 * template question, and stores the responses in vendor_assessment_responses.
 *
 * The PDF is parsed with a pure-PHP regex scanner over object dictionaries;
 * no external binaries are required. We intentionally walk the whole file and
 * let the LAST /V for a given /T win so incremental-update saves from Adobe
 * Reader / Preview are respected.
 *
 * POST multipart/form-data:
 *   csrf_token     required
 *   assessment_id  required -- target assessment
 *   pdf            uploaded file
 *
 * Response: JSON { success, imported, skipped, errors? }
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
$isAuditor = hasGroup('auditor');

// Auditors are read-only so they can't write responses
if (!($isAdmin || $isCyberTPRM || $isProcurement) || $isAuditor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!$security->validateCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$assessmentId = intval($_POST['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing assessment_id.']);
    exit;
}

require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$service = new VendorAssessmentService();

$assessment = $service->getAssessmentById($assessmentId);
if (!$assessment) {
    echo json_encode(['success' => false, 'message' => 'Assessment not found.']);
    exit;
}
if ($assessment['status'] === 'completed') {
    echo json_encode(['success' => false, 'message' => 'This assessment is already completed -- import is disabled.']);
    exit;
}

if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please attach a PDF file.']);
    exit;
}

$tmpPath = $_FILES['pdf']['tmp_name'];
$size = (int)$_FILES['pdf']['size'];
if ($size <= 0 || $size > 25 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'PDF is empty or exceeds 25 MB.']);
    exit;
}

$pdfBytes = @file_get_contents($tmpPath);
if ($pdfBytes === false || substr($pdfBytes, 0, 4) !== '%PDF') {
    echo json_encode(['success' => false, 'message' => 'Not a valid PDF.']);
    exit;
}

try {
    $fields = extractAcroFormFieldValues($pdfBytes);
} catch (Throwable $e) {
    error_log('PDF import parse error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not read form fields from this PDF.']);
    exit;
}

if (empty($fields)) {
    echo json_encode(['success' => false, 'message' => 'No form fields were found in this PDF. It may have been flattened.']);
    exit;
}

// -------------------------------------------------------------------------
// Verify the Reference matches this assessment's UUID. The PDF exporter
// names the vendor-info Reference field "vendor_reference_<N>" so we look
// for any key starting with vendor_reference.
// -------------------------------------------------------------------------
$expectedUuid = strtolower(trim((string)$assessment['uuid']));
$foundRef = null;
foreach ($fields as $name => $value) {
    if (stripos($name, 'vendor_reference') === 0) {
        $foundRef = strtolower(trim((string)$value));
        break;
    }
}

if ($foundRef === null || $foundRef === '') {
    echo json_encode([
        'success' => false,
        'message' => 'The PDF is missing its Reference field. Please upload a PDF generated from this assessment.'
    ]);
    exit;
}
if ($foundRef !== $expectedUuid) {
    echo json_encode([
        'success' => false,
        'message' => 'The Reference in this PDF does not match this assessment. Import aborted.'
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Walk template questions and import matching values.
// -------------------------------------------------------------------------
$sections = $service->getSections($assessment['template_id']);
$imported = 0;
$skipped = 0;
$errors = [];

foreach ($sections as $section) {
    $questions = $service->getQuestions($section['id']);
    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $type = $q['question_type'] ?? 'text';
        $options = is_array($q['options'] ?? null) ? array_values($q['options']) : [];

        try {
            switch ($type) {
                case 'checkbox':
                case 'button_group_multi': {
                    $selected = [];
                    foreach ($options as $idx => $opt) {
                        $cbName = 'q_' . $qid . '_cb_' . $idx;
                        if (!array_key_exists($cbName, $fields)) continue;
                        if (isCheckboxOn($fields[$cbName])) $selected[] = (string)$opt;
                    }
                    if (!empty($selected)) {
                        $service->saveResponse($assessment['id'], $qid, $selected);
                        $imported++;
                    } else {
                        $skipped++;
                    }
                    break;
                }
                case 'select':
                case 'radio':
                case 'button_group': {
                    $name = 'q_' . $qid . '_choice';
                    $val = $fields[$name] ?? '';
                    if ($val !== '') {
                        // Trim and validate against options list when available
                        $val = trim((string)$val);
                        if (!empty($options) && !in_array($val, $options, true)) {
                            // Be lenient: accept case-insensitive match
                            foreach ($options as $opt) {
                                if (strcasecmp(trim((string)$opt), $val) === 0) { $val = (string)$opt; break; }
                            }
                        }
                        $service->saveResponse($assessment['id'], $qid, $val);
                        $imported++;
                    } else {
                        $skipped++;
                    }
                    break;
                }
                default: {
                    // text / textarea / email / number / date / file / anything else
                    $val = findQuestionTextValue($fields, $qid, $type);
                    if ($val !== null && $val !== '') {
                        $service->saveResponse($assessment['id'], $qid, $val);
                        $imported++;
                    } else {
                        $skipped++;
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'q' . $qid . ': ' . $e->getMessage();
        }
    }
}

// Bump status to in_progress if we actually imported something and it was still pending.
if ($imported > 0 && $assessment['status'] === 'pending') {
    try {
        $db->update('vendor_assessments', [
            'status' => 'in_progress',
            'started_at' => $assessment['started_at'] ?: date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $assessment['id']]);
    } catch (Throwable $e) {
        error_log('Import status bump failed: ' . $e->getMessage());
    }
}

$auth->audit($user['id'], 'assessment_import_pdf', 'vendor_assessments', $assessment['id'], [
    'imported' => $imported, 'skipped' => $skipped, 'error_count' => count($errors)
]);

echo json_encode([
    'success'  => true,
    'imported' => $imported,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'message'  => "Imported {$imported} answer(s). " . ($skipped ? "{$skipped} question(s) had no value in the PDF." : ''),
]);
exit;

// ============================================================================
// PDF parsing helpers
// ============================================================================

/**
 * Is a checkbox value considered "checked"? PDF readers use /Yes (most common),
 * /On, /1, or sometimes a custom export value. Only /Off (or empty) means off.
 */
function isCheckboxOn($raw) {
    if ($raw === '' || $raw === null) return false;
    $v = is_string($raw) ? trim($raw) : '';
    if ($v === '' || strcasecmp($v, '/Off') === 0 || strcasecmp($v, 'Off') === 0) return false;
    return true;
}

/**
 * For single-value text-type questions, find the field that starts with
 * "q_<qid>_<bucket>_" where bucket is text/textarea/email/number/date/file.
 * The generator appends a counter suffix, so we match by prefix.
 */
function findQuestionTextValue(array $fields, $qid, $type) {
    $bucketsByType = [
        'text'     => ['text'],
        'textarea' => ['text'],
        'email'    => ['email', 'text'],
        'number'   => ['number', 'text'],
        'date'     => ['date', 'text'],
        'file'     => ['file', 'text'],
    ];
    $buckets = $bucketsByType[$type] ?? ['text'];
    foreach ($buckets as $bucket) {
        $prefix = 'q_' . $qid . '_' . $bucket . '_';
        foreach ($fields as $name => $value) {
            if (strpos($name, $prefix) === 0) {
                if ($value !== '' && $value !== null) return (string)$value;
            }
        }
    }
    // As a last resort, check the "_choice" name in case the question type
    // changed between generation and import.
    if (isset($fields['q_' . $qid . '_choice']) && $fields['q_' . $qid . '_choice'] !== '') {
        return (string)$fields['q_' . $qid . '_choice'];
    }
    return null;
}

/**
 * Scan raw PDF bytes for every object dictionary and return an associative
 * array of /T -> /V mappings. Later occurrences override earlier ones so
 * incremental updates (Adobe Reader save) take precedence.
 *
 * Handles:
 *   - Literal strings (parenthesised) with backslash + octal escapes
 *   - Name objects (/Off, /Yes, /On, ...)
 *   - Hex strings (<...>) with optional UTF-16BE BOM
 *
 * Does NOT yet handle: object streams (compressed /ObjStm content). In
 * practice Adobe Reader / Preview don't rewrite widget annotations into
 * object streams on save, so the field dictionaries remain scannable.
 */
function extractAcroFormFieldValues($pdfBytes) {
    $fields = [];
    // Grab every "N G obj << ... >> endobj" block. The dict body is captured
    // non-greedily so nested << >> stay intact.
    $re = '/\b\d+\s+\d+\s+obj\s*(<<.*?>>)\s*(?:stream\b|endobj\b)/s';
    if (!preg_match_all($re, $pdfBytes, $matches, PREG_SET_ORDER)) {
        return $fields;
    }
    foreach ($matches as $m) {
        $body = $m[1];
        // Only bother if it looks like a form-field dictionary
        if (strpos($body, '/T') === false) continue;

        $name = pdfExtractString($body, 'T');
        if ($name === null || $name === '') continue;

        $val = pdfExtractValue($body, 'V');
        if ($val === null) {
            // Some readers update the appearance state /AS but leave /V on the
            // original widget. Treat /AS as a fallback for checkbox state.
            $val = pdfExtractValue($body, 'AS');
        }
        if ($val === null) continue;

        $fields[$name] = $val;
    }
    return $fields;
}

/**
 * Extract the string value of a named key from a PDF dictionary body. Looks
 * for the key followed by either a literal string, a name object, or a hex
 * string. Returns null when the key is absent or the value is a reference
 * (indirect object) we can't resolve without the full xref.
 */
function pdfExtractValue($body, $key) {
    $k = preg_quote($key, '/');

    // Literal string: /K (value)
    if (preg_match('/\/' . $k . '\s*\(((?:\\\\.|[^()\\\\]|\((?:\\\\.|[^()\\\\])*\))*)\)/s', $body, $m)) {
        return pdfUnescapeLiteral($m[1]);
    }
    // Hex string: /K <hex>
    if (preg_match('/\/' . $k . '\s*<([0-9a-fA-F\s]+)>/', $body, $m)) {
        $hex = preg_replace('/\s+/', '', $m[1]);
        if (strlen($hex) % 2 === 1) $hex .= '0';
        $raw = @hex2bin($hex) ?: '';
        return pdfDecodeWideString($raw);
    }
    // Name object: /K /Value
    if (preg_match('/\/' . $k . '\s*\/([^\s\/<>\[\]()]+)/', $body, $m)) {
        return '/' . $m[1];
    }
    return null;
}

function pdfExtractString($body, $key) {
    $val = pdfExtractValue($body, $key);
    if (is_string($val) && strlen($val) > 0 && $val[0] === '/') {
        // A /T should never be a name object, so reject
        return null;
    }
    return $val;
}

/**
 * Unescape a PDF literal string body (characters inside the parentheses).
 */
function pdfUnescapeLiteral($s) {
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c !== '\\') { $out .= $c; continue; }
        if ($i + 1 >= $len) break;
        $n = $s[$i + 1];
        switch ($n) {
            case 'n': $out .= "\n"; $i++; break;
            case 'r': $out .= "\r"; $i++; break;
            case 't': $out .= "\t"; $i++; break;
            case 'b': $out .= "\x08"; $i++; break;
            case 'f': $out .= "\x0C"; $i++; break;
            case '(': $out .= '('; $i++; break;
            case ')': $out .= ')'; $i++; break;
            case '\\': $out .= '\\'; $i++; break;
            case "\r":
                // Line continuation: '\' followed by EOL -> skip the EOL
                $i++;
                if ($i + 1 < $len && $s[$i + 1] === "\n") $i++;
                break;
            case "\n":
                $i++;
                break;
            default:
                if ($n >= '0' && $n <= '7') {
                    $oct = '';
                    for ($j = 0; $j < 3 && $i + 1 + $j < $len; $j++) {
                        $c2 = $s[$i + 1 + $j];
                        if ($c2 < '0' || $c2 > '7') break;
                        $oct .= $c2;
                    }
                    if ($oct !== '') {
                        $out .= chr(octdec($oct) & 0xFF);
                        $i += strlen($oct);
                    }
                } else {
                    $out .= $n;
                    $i++;
                }
        }
    }
    return pdfDecodeWideString($out);
}

/**
 * If a string starts with the UTF-16BE BOM, decode to UTF-8. PDF literal or
 * hex strings can carry Unicode this way.
 */
function pdfDecodeWideString($raw) {
    if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFE\xFF") {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    }
    if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFF\xFE") {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }
    return $raw;
}
