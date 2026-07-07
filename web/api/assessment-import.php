<?php
/**
 * Unified Assessment Import API (PDF or Excel)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Accepts a completed assessment that was previously generated for THIS
 * assessment and stores the answers. One upload control, three formats:
 *
 *   - Fillable PDF  (from assessment-download-pdf.php)   -> %PDF magic
 *   - Excel .xlsx   (from assessment-download-xlsx.php)  -> PK zip magic
 *   - CSV           (a saved-as-CSV copy of the .xlsx)   -> everything else
 *
 * Every format carries the assessment Reference (its UUID); we verify that
 * match before writing a single row, then map each value back to the correct
 * template question and persist via VendorAssessmentService::saveResponse().
 *
 * The PDF path reuses the exact pure-PHP AcroForm scanner from
 * assessment-import-pdf.php (which remains for backward compatibility). The
 * Excel/CSV paths key answers directly off the hidden question_id column the
 * exporter writes.
 *
 * POST multipart/form-data:
 *   csrf_token     required
 *   assessment_id  required -- target assessment
 *   file           uploaded PDF / XLSX / CSV  (also accepts legacy field "pdf")
 *
 * Response: JSON { success, imported, skipped, format, errors?, message }
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

// Auditors are read-only so they can't write responses.
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

if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
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

// The exporter's BOLA rule: a scoped stakeholder path would not reach this
// endpoint (they lack write groups), so admin/cyber/procurement suffices here.

// Uploaded file -- accept the new "file" field or the legacy "pdf" field.
$upload = $_FILES['file'] ?? ($_FILES['pdf'] ?? null);
if (empty($upload) || $upload['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please attach a completed PDF or Excel file.']);
    exit;
}

$size = (int)$upload['size'];
if ($size <= 0 || $size > 25 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File is empty or exceeds 25 MB.']);
    exit;
}

$bytes = @file_get_contents($upload['tmp_name']);
if ($bytes === false || $bytes === '') {
    echo json_encode(['success' => false, 'message' => 'Could not read the uploaded file.']);
    exit;
}

$expectedUuid = strtolower(trim((string)$assessment['uuid']));

// -------------------------------------------------------------------------
// Detect format by magic bytes and extract a normalized answer set.
// PDF  -> handled by the AcroForm walker (field-name keyed).
// XLSX -> ZIP (PK\x03\x04) parsed to a question_id => answer map.
// CSV  -> fallback, same question_id => answer map.
// -------------------------------------------------------------------------
$magic4 = substr($bytes, 0, 4);

try {
    if ($magic4 === '%PDF') {
        $result = importFromPdf($service, $assessment, $bytes, $expectedUuid);
    } elseif (substr($bytes, 0, 2) === 'PK') {
        $result = importFromSpreadsheet($service, $assessment, parseXlsx($bytes), $expectedUuid, 'xlsx');
    } else {
        $result = importFromSpreadsheet($service, $assessment, parseCsv($bytes), $expectedUuid, 'csv');
    }
} catch (ImportError $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    error_log('Assessment import error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not read answers from this file.']);
    exit;
}

// Bump status to in_progress if we imported something and it was still pending.
if ($result['imported'] > 0 && $assessment['status'] === 'pending') {
    try {
        $db->update('vendor_assessments', [
            'status' => 'in_progress',
            'started_at' => $assessment['started_at'] ?: date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $assessment['id']]);
    } catch (Throwable $e) {
        error_log('Import status bump failed: ' . $e->getMessage());
    }
}

$auth->audit($user['id'], 'assessment_import', 'vendor_assessments', $assessment['id'], [
    'format' => $result['format'],
    'imported' => $result['imported'],
    'skipped' => $result['skipped'],
    'error_count' => count($result['errors']),
]);

echo json_encode([
    'success'  => true,
    'format'   => $result['format'],
    'imported' => $result['imported'],
    'skipped'  => $result['skipped'],
    'errors'   => $result['errors'],
    'message'  => "Imported {$result['imported']} answer(s) from the {$result['format']} file. "
                . ($result['skipped'] ? "{$result['skipped']} question(s) had no value." : ''),
]);
exit;

// ============================================================================
// Shared mapping
// ============================================================================

class ImportError extends Exception {}

/**
 * Apply a question_id => answer map (from XLSX/CSV) to the assessment. Answers
 * are validated per question type before saving, mirroring the PDF importer's
 * leniency (case-insensitive option matching, multi-select comma-split).
 */
function importFromSpreadsheet($service, $assessment, $parsed, $expectedUuid, $format) {
    $foundRef = strtolower(trim((string)($parsed['reference'] ?? '')));
    if ($foundRef === '') {
        throw new ImportError('The file is missing its Reference cell. Please upload a file generated from this assessment.');
    }
    if ($foundRef !== $expectedUuid) {
        throw new ImportError('The Reference in this file does not match this assessment. Import aborted.');
    }

    $answers = $parsed['answers'] ?? [];   // qid => raw answer string
    if (empty($answers)) {
        throw new ImportError('No answers were found in this file.');
    }

    $imported = 0; $skipped = 0; $errors = [];
    $multiTypes  = ['checkbox', 'button_group_multi'];
    $singleTypes = ['select', 'radio', 'button_group'];

    foreach ($service->getSections($assessment['template_id']) as $section) {
        foreach ($service->getQuestions($section['id']) as $q) {
            $qid = (int)$q['id'];
            if (!array_key_exists($qid, $answers)) { continue; }
            $raw = trim((string)$answers[$qid]);
            $type = $q['question_type'] ?? 'text';
            $options = is_array($q['options'] ?? null) ? array_values($q['options']) : [];

            try {
                if (in_array($type, $multiTypes, true)) {
                    $selected = [];
                    foreach (explode(',', $raw) as $piece) {
                        $piece = trim($piece);
                        if ($piece === '') continue;
                        $selected[] = canonicalizeOption($piece, $options);
                    }
                    $selected = array_values(array_unique($selected));
                    if (!empty($selected)) { $service->saveResponse($assessment['id'], $qid, $selected); $imported++; }
                    else { $skipped++; }
                } elseif (in_array($type, $singleTypes, true)) {
                    if ($raw !== '') { $service->saveResponse($assessment['id'], $qid, canonicalizeOption($raw, $options)); $imported++; }
                    else { $skipped++; }
                } else {
                    if ($raw !== '') { $service->saveResponse($assessment['id'], $qid, $raw); $imported++; }
                    else { $skipped++; }
                }
            } catch (Throwable $e) {
                $errors[] = 'q' . $qid . ': ' . $e->getMessage();
            }
        }
    }

    return ['format' => $format, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Return the canonical option string matching $value case-insensitively, or the
 * trimmed value unchanged when there is no options list / no match (lenient,
 * same spirit as the PDF importer).
 */
function canonicalizeOption($value, array $options) {
    $value = trim((string)$value);
    if (empty($options)) return $value;
    foreach ($options as $opt) {
        if (strcasecmp(trim((string)$opt), $value) === 0) return (string)$opt;
    }
    return $value;
}

// ============================================================================
// XLSX parsing (pure PHP, ZipArchive + SimpleXML)
// ============================================================================

/**
 * Parse a .xlsx into ['reference' => str, 'answers' => [qid => answer]].
 *
 * Reads the first worksheet plus sharedStrings, flattens it to a cell grid,
 * then locates (a) the Reference marker and (b) the question_id / Answer columns
 * by scanning header text -- robust to the sheet gaining or losing leading rows.
 */
function parseXlsx($bytes) {
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    if ($tmp === false) throw new ImportError('Server temp error.');
    file_put_contents($tmp, $bytes);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new ImportError('This .xlsx file could not be opened.'); }

    // Shared strings (Excel writes string cells via this table on save).
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false && $ssXml !== '') {
        $sx = @simplexml_load_string($ssXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                $shared[] = siText($si);
            }
        }
    }

    // Scan EVERY worksheet: the exporter keeps the Reference on a hidden
    // "Internal Use" sheet and the answers on the "Assessment" sheet, so we read
    // all of them and take the Reference from wherever it appears and the
    // answers from whichever sheet carries the question_id/Answer header.
    $reference = ''; $answers = [];
    foreach (allSheetPaths($zip) as $path) {
        $sheetXml = $zip->getFromName($path);
        if ($sheetXml === false || $sheetXml === '') continue;
        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false) continue;

        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $rNum = (int)$row['r'];
            if ($rNum <= 0) continue;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = preg_replace('/\d+/', '', $ref);
                $grid[$rNum][$col] = cellValue($c, $shared);
            }
        }

        $res = gridToAnswers($grid);
        if ($reference === '' && $res['reference'] !== '') $reference = $res['reference'];
        if (empty($answers) && !empty($res['answers'])) $answers = $res['answers'];
    }

    $zip->close();
    @unlink($tmp);

    return ['reference' => $reference, 'answers' => $answers];
}

/** Concatenate all <t> descendants of a sharedStrings <si> (handles rich runs). */
function siText($si) {
    $text = '';
    if (isset($si->t)) $text .= (string)$si->t;
    if (isset($si->r)) {
        foreach ($si->r as $run) { $text .= (string)$run->t; }
    }
    return $text;
}

/** Extract a worksheet cell's string value across s / inlineStr / str / numeric. */
function cellValue($c, array $shared) {
    $t = (string)$c['t'];
    if ($t === 's') {
        $idx = (int)$c->v;
        return $shared[$idx] ?? '';
    }
    if ($t === 'inlineStr') {
        return isset($c->is) ? siText($c->is) : '';
    }
    // 'str' (formula string) or numeric/boolean -> the <v> text.
    return isset($c->v) ? (string)$c->v : '';
}

/** Paths of every worksheet part, resolved from the workbook rels (with an
 *  archive-scan fallback). */
function allSheetPaths(ZipArchive $zip) {
    $paths = [];
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels !== false) {
        $rx = @simplexml_load_string($rels);
        if ($rx !== false) {
            foreach ($rx->Relationship as $rel) {
                if (substr((string)$rel['Type'], -9) !== 'worksheet') continue;
                $t = ltrim((string)$rel['Target'], '/');
                if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
                $paths[] = $t;
            }
        }
    }
    if (empty($paths)) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) $paths[] = $name;
        }
    }
    return $paths;
}

/**
 * Turn a cell grid into ['reference', 'answers']. Scans for a "Reference" label
 * (value = the cell to its right) and a header row containing "question_id" and
 * "Answer" (mapping every subsequent row's id -> answer).
 */
function gridToAnswers(array $grid) {
    $reference = '';
    $qidCol = null; $answerCol = null; $headerRow = null;

    foreach ($grid as $rNum => $cols) {
        // Reference marker: value in the cell to the right of a "Reference" label.
        foreach ($cols as $colLetter => $val) {
            $norm = strtolower(trim((string)$val));
            if ($reference === '' && $norm === 'reference') {
                $reference = trim((string)($cols[nextCol($colLetter)] ?? ''));
            }
        }
        // Header row: the row that carries BOTH a question_id and an Answer
        // marker. Scanned together so column order doesn't matter (Answer can
        // come before question_id).
        if ($headerRow === null) {
            $qc = null; $ac = null;
            foreach ($cols as $colLetter => $val) {
                $norm = strtolower(trim((string)$val));
                if ($norm === 'question_id' || $norm === 'question id') $qc = $colLetter;
                if ($norm === 'answer') $ac = $colLetter;
            }
            if ($qc !== null && $ac !== null) { $headerRow = $rNum; $qidCol = $qc; $answerCol = $ac; }
        }
    }

    $answers = [];
    if ($headerRow !== null && $qidCol !== null && $answerCol !== null) {
        foreach ($grid as $rNum => $cols) {
            if ($rNum <= $headerRow) continue;
            $qid = (int)trim((string)($cols[$qidCol] ?? ''));
            if ($qid <= 0) continue;
            $answers[$qid] = (string)($cols[$answerCol] ?? '');
        }
    }

    return ['reference' => $reference, 'answers' => $answers];
}

/** Next spreadsheet column letter (A -> B, Z -> AA). */
function nextCol($col) {
    $col = strtoupper($col);
    $i = strlen($col) - 1;
    $carry = 1;
    $out = '';
    while ($i >= 0) {
        $d = ord($col[$i]) - 65 + $carry;
        $carry = intdiv($d, 26);
        $out = chr(65 + ($d % 26)) . $out;
        $i--;
    }
    if ($carry > 0) $out = chr(64 + $carry) . $out;
    return $out;
}

// ============================================================================
// CSV parsing (fallback -- a saved-as-CSV copy of the workbook)
// ============================================================================

/**
 * Parse CSV into ['reference', 'answers']. Same scan strategy as the xlsx path
 * but over columns indexed numerically.
 */
function parseCsv($bytes) {
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes); // strip UTF-8 BOM
    // Parse with fgetcsv over a stream so quoted fields containing embedded
    // newlines/commas (e.g. wrapped question text) are handled correctly -- a
    // naive line-split would shred those rows.
    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $bytes);
    rewind($fh);
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($fh);

    $reference = '';
    $qidCol = null; $answerCol = null; $headerIdx = null;

    foreach ($rows as $ri => $cells) {
        foreach ($cells as $ci => $val) {
            $norm = strtolower(trim((string)$val));
            if ($reference === '' && $norm === 'reference') {
                $reference = trim((string)($cells[$ci + 1] ?? ''));
            }
        }
        // Header row carries BOTH markers; scan together so column order is
        // irrelevant (Answer may precede question_id).
        if ($headerIdx === null) {
            $qc = null; $ac = null;
            foreach ($cells as $ci => $val) {
                $norm = strtolower(trim((string)$val));
                if ($norm === 'question_id' || $norm === 'question id') $qc = $ci;
                if ($norm === 'answer') $ac = $ci;
            }
            if ($qc !== null && $ac !== null) { $headerIdx = $ri; $qidCol = $qc; $answerCol = $ac; }
        }
    }

    $answers = [];
    if ($headerIdx !== null && $qidCol !== null && $answerCol !== null) {
        foreach ($rows as $ri => $cells) {
            if ($ri <= $headerIdx) continue;
            $qid = (int)trim((string)($cells[$qidCol] ?? ''));
            if ($qid <= 0) continue;
            $answers[$qid] = (string)($cells[$answerCol] ?? '');
        }
    }

    return ['reference' => $reference, 'answers' => $answers];
}

// ============================================================================
// PDF parsing -- mirrors assessment-import-pdf.php (kept identical on purpose)
// ============================================================================

function importFromPdf($service, $assessment, $pdfBytes, $expectedUuid) {
    $fields = extractAcroFormFieldValues($pdfBytes);
    if (empty($fields)) {
        throw new ImportError('No form fields were found in this PDF. It may have been flattened.');
    }

    // Verify the Reference matches this assessment's UUID.
    $foundRef = null;
    foreach ($fields as $name => $value) {
        if (stripos($name, 'vendor_reference') === 0) { $foundRef = strtolower(trim((string)$value)); break; }
    }
    if ($foundRef === null || $foundRef === '') {
        throw new ImportError('The PDF is missing its Reference field. Please upload a PDF generated from this assessment.');
    }
    if ($foundRef !== $expectedUuid) {
        throw new ImportError('The Reference in this PDF does not match this assessment. Import aborted.');
    }

    $imported = 0; $skipped = 0; $errors = [];
    foreach ($service->getSections($assessment['template_id']) as $section) {
        foreach ($service->getQuestions($section['id']) as $q) {
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
                        if (!empty($selected)) { $service->saveResponse($assessment['id'], $qid, $selected); $imported++; }
                        else { $skipped++; }
                        break;
                    }
                    case 'select':
                    case 'radio':
                    case 'button_group': {
                        $val = $fields['q_' . $qid . '_choice'] ?? '';
                        if ($val !== '') { $service->saveResponse($assessment['id'], $qid, canonicalizeOption($val, $options)); $imported++; }
                        else { $skipped++; }
                        break;
                    }
                    default: {
                        $val = findQuestionTextValue($fields, $qid, $type);
                        if ($val !== null && $val !== '') { $service->saveResponse($assessment['id'], $qid, $val); $imported++; }
                        else { $skipped++; }
                        break;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'q' . $qid . ': ' . $e->getMessage();
            }
        }
    }

    return ['format' => 'pdf', 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

function isCheckboxOn($raw) {
    if ($raw === '' || $raw === null) return false;
    $v = is_string($raw) ? trim($raw) : '';
    if ($v === '' || strcasecmp($v, '/Off') === 0 || strcasecmp($v, 'Off') === 0) return false;
    return true;
}

function findQuestionTextValue(array $fields, $qid, $type) {
    $bucketsByType = [
        'text' => ['text'], 'textarea' => ['text'], 'email' => ['email', 'text'],
        'number' => ['number', 'text'], 'date' => ['date', 'text'], 'file' => ['file', 'text'],
    ];
    $buckets = $bucketsByType[$type] ?? ['text'];
    foreach ($buckets as $bucket) {
        $prefix = 'q_' . $qid . '_' . $bucket . '_';
        foreach ($fields as $name => $value) {
            if (strpos($name, $prefix) === 0 && $value !== '' && $value !== null) return (string)$value;
        }
    }
    if (isset($fields['q_' . $qid . '_choice']) && $fields['q_' . $qid . '_choice'] !== '') {
        return (string)$fields['q_' . $qid . '_choice'];
    }
    return null;
}

function extractAcroFormFieldValues($pdfBytes) {
    $fields = [];
    $re = '/\b\d+\s+\d+\s+obj\s*(<<.*?>>)\s*(?:stream\b|endobj\b)/s';
    if (!preg_match_all($re, $pdfBytes, $matches, PREG_SET_ORDER)) return $fields;
    foreach ($matches as $m) {
        $body = $m[1];
        if (strpos($body, '/T') === false) continue;
        $name = pdfExtractString($body, 'T');
        if ($name === null || $name === '') continue;
        $val = pdfExtractValue($body, 'V');
        if ($val === null) $val = pdfExtractValue($body, 'AS');
        if ($val === null) continue;
        $fields[$name] = $val;
    }
    return $fields;
}

function pdfExtractValue($body, $key) {
    $k = preg_quote($key, '/');
    if (preg_match('/\/' . $k . '\s*\(((?:\\\\.|[^()\\\\]|\((?:\\\\.|[^()\\\\])*\))*)\)/s', $body, $m)) {
        return pdfUnescapeLiteral($m[1]);
    }
    if (preg_match('/\/' . $k . '\s*<([0-9a-fA-F\s]+)>/', $body, $m)) {
        $hex = preg_replace('/\s+/', '', $m[1]);
        if (strlen($hex) % 2 === 1) $hex .= '0';
        $raw = @hex2bin($hex) ?: '';
        return pdfDecodeWideString($raw);
    }
    if (preg_match('/\/' . $k . '\s*\/([^\s\/<>\[\]()]+)/', $body, $m)) {
        return '/' . $m[1];
    }
    return null;
}

function pdfExtractString($body, $key) {
    $val = pdfExtractValue($body, $key);
    if (is_string($val) && strlen($val) > 0 && $val[0] === '/') return null;
    return $val;
}

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
            case "\r": $i++; if ($i + 1 < $len && $s[$i + 1] === "\n") $i++; break;
            case "\n": $i++; break;
            default:
                if ($n >= '0' && $n <= '7') {
                    $oct = '';
                    for ($j = 0; $j < 3 && $i + 1 + $j < $len; $j++) {
                        $c2 = $s[$i + 1 + $j];
                        if ($c2 < '0' || $c2 > '7') break;
                        $oct .= $c2;
                    }
                    if ($oct !== '') { $out .= chr(octdec($oct) & 0xFF); $i += strlen($oct); }
                } else { $out .= $n; $i++; }
        }
    }
    return pdfDecodeWideString($out);
}

function pdfDecodeWideString($raw) {
    if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFE\xFF") {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    }
    if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFF\xFE") {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }
    return $raw;
}
