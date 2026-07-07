<?php
/**
 * Assessment Fillable Excel (.xlsx) Generator
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Produces a human-friendly Excel workbook of a vendor assessment so recipients
 * can complete it in Excel, Google Sheets, or LibreOffice and return it. It is a
 * companion to the fillable PDF (assessment-download-pdf.php) and round-trips
 * through the same importer (assessment-import.php).
 *
 * Two sheets:
 *   "Assessment"   -- visible, UNPROTECTED, the form the recipient fills in.
 *                     Column A = question text (+ colored section header bars,
 *                     merged A:B). Column B = the answer (single-choice questions
 *                     get a dropdown). Column C = question_id, hidden. There is a
 *                     hidden machine-header row so the importer can locate the
 *                     Answer + question_id columns.
 *   "Internal Use" -- hidden. Holds the Reference (assessment UUID), Vendor, and
 *                     Template. Kept off the form so the recipient works in a
 *                     clean, fully editable sheet; the importer reads the
 *                     Reference from here to match the file back to the
 *                     assessment.
 *
 * Nothing is sheet-protected: real Excel would not reliably honor per-cell
 * unlock exceptions, which blocked recipients from typing answers and hid the
 * dropdowns. Keeping the identity fields on a separate hidden sheet gives the
 * same "don't fat-finger the reference" safety without protecting the form.
 *
 * No external spreadsheet library is used -- the workbook is assembled from
 * hand-written OOXML parts zipped with ZipArchive.
 *
 * GET parameters:
 *   template_id     required when assessment_id is absent (blank form)
 *   assessment_id   load an existing assessment (prefills + stamps Reference)
 *   vendor_name     optional prefill
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
$isStakeholder = hasGroup('stakeholder');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isStakeholder && !$isAuditor) {
    http_response_code(403);
    die('Access denied.');
}

require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$service = new VendorAssessmentService();

$templateId = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;
$assessmentId = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;

$assessment = null;
if ($assessmentId) {
    $assessment = $service->getAssessmentById($assessmentId);
    if (!$assessment) {
        http_response_code(404);
        die('Assessment not found.');
    }
    // BOLA/IDOR fix: a scoped stakeholder may only export assessments for vendors
    // they are linked to (mirrors assessment-download-pdf.php).
    if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isAuditor) {
        $vrid = (int)($assessment['vendor_request_id'] ?? 0);
        $link = $vrid ? $db->fetchOne(
            'SELECT 1 AS x FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
            [':rid' => $vrid, ':uid' => (int)$user['id']]) : null;
        if (empty($link)) {
            http_response_code(403);
            die('Access denied.');
        }
    }
    $templateId = (int)$assessment['template_id'];
}

if (!$templateId) {
    http_response_code(400);
    die('Missing template_id or assessment_id.');
}

$template = $service->getTemplate($templateId);
if (!$template) {
    http_response_code(404);
    die('Template not found.');
}

$sections = $service->getSections($templateId);
foreach ($sections as &$section) {
    $section['questions'] = $service->getQuestions($section['id']);
}
unset($section);

$vendorName = $assessment['vendor_name'] ?? ($_GET['vendor_name'] ?? '');
$assessmentRef = $assessment['uuid'] ?? '';

$existingResponses = [];
if (!empty($assessment['id'])) {
    $existingResponses = $service->getResponses((int)$assessment['id']);
}

/**
 * Prefill value for a question: multi-select responses (stored as a JSON array)
 * are joined with ", "; everything else is a plain string.
 */
$prefillFor = function ($qid) use ($existingResponses) {
    $r = $existingResponses[$qid] ?? null;
    if (!$r) return '';
    $raw = $r['response_value'] ?? '';
    if ($raw === '' || $raw === null) return '';
    if (is_string($raw) && strlen($raw) > 0 && ($raw[0] === '[' || $raw[0] === '{')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return implode(', ', array_map('strval', $decoded));
    }
    return (string)$raw;
};

// ---------------------------------------------------------------------------
// Assemble the workbook.
// ---------------------------------------------------------------------------
// Match the section-header bars to the admin "Header Color" setting. That value
// is the application-wide app_config key (what admin.php saves and the app UI
// reads); read it straight from the DB -- init.php does not populate a $theme
// global in this endpoint context. Convert #RRGGBB -> FFRRGGBB (8-hex ARGB).
$headerColorRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'header_color'");
$headerHex = ltrim((string)($headerColorRow['config_value'] ?? '#35a0a3'), '#');
$headerArgb = preg_match('/^[0-9A-Fa-f]{6}$/', $headerHex) ? 'FF' . strtoupper($headerHex) : 'FF35A0A3';
$xlsx = new AssessmentXlsxWriter($headerArgb);

// Sheet 1: the fillable form (visible, unprotected).
$xlsx->addSheet('Assessment');
$titleRow = $xlsx->addRow([['OpenTPRM Vendor Security Assessment', 'title'], ['', 'title']]);
$xlsx->addMerge('A' . $titleRow . ':B' . $titleRow);
$xlsx->addRow([['Complete the Answer column (B). Single-choice questions have a dropdown.', 'sectiondesc'], ['', 'sectiondesc']]);
$xlsx->addMerge('A2:B2');
$xlsx->addRow([]); // spacer

// Hidden machine-header row: lets the importer find the Answer (B) and
// question_id (C) columns generically, without the user ever seeing it.
$xlsx->addRow([
    ['Question', 'marker'], ['Answer', 'marker'], ['question_id', 'marker'],
], ['hidden' => true]);

$multiTypes  = ['checkbox', 'button_group_multi'];
$singleTypes = ['select', 'radio', 'button_group'];

$qidRow = [];    // question id => its answer row on the Assessment sheet
$condMeta = [];  // conditional questions, resolved after all rows are placed

foreach ($sections as $section) {
    $hr = $xlsx->addRow([[strtoupper($section['name'] ?? ''), 'section'], ['', 'section']]);
    $xlsx->addMerge('A' . $hr . ':B' . $hr);

    $desc = trim(preg_replace('/\s+/', ' ', strip_tags($section['description'] ?? '')));
    if ($desc !== '') {
        $dr = $xlsx->addRow([[$desc, 'sectiondesc'], ['', 'sectiondesc']]);
        $xlsx->addMerge('A' . $dr . ':B' . $dr);
    }

    foreach (($section['questions'] ?? []) as $q) {
        $qid   = (int)$q['id'];
        $type  = $q['question_type'] ?? 'text';
        $req   = !empty($q['is_required']);
        $qtext = trim(preg_replace('/\s+/', ' ', strip_tags($q['question_text'] ?? '')));
        if ($req) $qtext .= ' *';
        $help  = trim(preg_replace('/\s+/', ' ', strip_tags($q['help_text'] ?? '')));
        if ($help !== '') $qtext .= "\n" . $help;
        $opts  = is_array($q['options'] ?? null) ? array_values(array_map('strval', $q['options'])) : [];

        if (in_array($type, $multiTypes, true) && !empty($opts)) {
            $qtext .= "\n(Select all that apply, comma-separated: " . implode(', ', $opts) . ")";
        }

        $row = $xlsx->addRow([
            [$qtext, 'question'],
            [$prefillFor($qid), 'answer'],
            [$qid, 'number'],
        ]);
        $qidRow[$qid] = $row;

        if (in_array($type, $singleTypes, true) && !empty($opts)) {
            $xlsx->addDropdown('B' . $row, $opts);
        }

        if (!empty($q['depends_on_question_id'])) {
            $condMeta[] = [
                'qid'    => $qid,
                'row'    => $row,
                'parent' => (int)$q['depends_on_question_id'],
                'value'  => (string)($q['depends_on_value'] ?? ''),
            ];
        }
    }
}

// Conditional questions: gray the whole row (A:B) when the controlling answer
// does not match, so it visibly reads as "not applicable" without hiding it.
// A macro-free, cross-platform stand-in for the PDF's true show/hide -- it
// reacts live as the parent answer changes.
foreach ($condMeta as $cm) {
    $parentRow = $qidRow[$cm['parent']] ?? null;
    if ($parentRow === null) continue; // parent not on the sheet -- leave normal
    $childRow = $cm['row'];
    $expected = trim($cm['value']);

    // SEARCH is a case-insensitive substring test, so it also works against a
    // multi-select parent's comma-separated answer (e.g. "OpenAI, Others").
    $pcell = '$B$' . $parentRow;
    $formula = ($expected === '')
        ? 'LEN(' . $pcell . ')=0'
        : 'NOT(ISNUMBER(SEARCH("' . str_replace('"', '""', $expected) . '",' . $pcell . ')))';
    $xlsx->addConditionalFormat('A' . $childRow . ':B' . $childRow, $formula);
}

$xlsx->setColumnWidths([64.0, 40.0, 10.0]); // A question, B answer, C question_id
$xlsx->hideColumn(3);                        // question_id

// Sheet 2: identity fields, hidden. The importer reads Reference from here.
$xlsx->addSheet('Internal Use', true);
$xlsx->addRow([['Do not edit -- used to match this file back to the assessment on import.', 'sectiondesc'], ['', 'sectiondesc']]);
$xlsx->addMerge('A1:B1');
$xlsx->addRow([['Reference', 'label'], [$assessmentRef, 'value']]);
$xlsx->addRow([['Vendor', 'label'], [$vendorName, 'value']]);
$xlsx->addRow([['Template', 'label'], [$template['name'] ?? '', 'value']]);
$xlsx->setColumnWidths([16.0, 44.0]);

$bytes = $xlsx->build();

$filename = xlsxSanitizeFilename($template['name']) . '-Assessment.xlsx';
if ($vendorName) {
    $filename = xlsxSanitizeFilename($vendorName) . '-' . $filename;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-cache, must-revalidate');
header('Pragma: public');
echo $bytes;
exit;

// ===========================================================================
// Helpers
// ===========================================================================

function xlsxSanitizeFilename($name) {
    $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', (string)$name);
    $name = preg_replace('/\s+/', '_', trim($name));
    if ($name === '') $name = 'Vendor';
    return substr($name, 0, 80);
}

/**
 * Minimal, self-contained multi-sheet .xlsx writer.
 *
 * Emits one or more worksheets from hand-written OOXML and zips them with
 * ZipArchive. Supports inline-string and numeric cells, named cell styles,
 * hidden columns, hidden rows, hidden sheets, merged cells, and list-type
 * data-validation dropdowns. No sheet protection -- deliberately, so real Excel
 * lets recipients type into every answer cell and shows the dropdowns.
 *
 * addRow/addMerge/addDropdown/setColumnWidths/hideColumn operate on the sheet
 * most recently created with addSheet().
 *
 * Style indexes (see stylesXml):
 *   0 default   1 title      2 label(bold)  3 value(gray box)
 *   4 section   5 question   6 answer(box)  7 number  8 marker  9 sectiondesc
 */
class AssessmentXlsxWriter {
    private $sheets = [];   // list of sheet arrays
    private $cur = -1;

    // Section-header bar color as 8-hex ARGB. Defaults to the app's default
    // header color (#35a0a3); the endpoint passes the tenant's configured
    // "Header Color" so the workbook matches the admin theme.
    private $headerFill = 'FF35A0A3';

    public function __construct($headerFillArgb = 'FF35A0A3') {
        if (preg_match('/^[0-9A-Fa-f]{8}$/', (string)$headerFillArgb)) {
            $this->headerFill = strtoupper($headerFillArgb);
        }
    }

    private static $styleMap = [
        'default'     => 0,
        'title'       => 1,
        'label'       => 2,
        'value'       => 3,
        'section'     => 4,
        'question'    => 5,
        'answer'      => 6,
        'number'      => 7,
        'marker'      => 8,
        'sectiondesc' => 9,
    ];

    /** Start a new sheet and make it current. Returns its 0-based index. */
    public function addSheet($name, $hidden = false) {
        $this->sheets[] = [
            'name'        => $name,
            'hidden'      => (bool)$hidden,
            'rows'        => [],
            'dropdowns'   => [],
            'merges'      => [],
            'colWidths'   => [],
            'hiddenCols'  => [],
            'condFormats' => [],
        ];
        $this->cur = count($this->sheets) - 1;
        return $this->cur;
    }

    public function addRow(array $cells, array $opts = []) {
        $row = [];
        foreach ($cells as $cell) {
            $value = $cell[0] ?? '';
            $style = $cell[1] ?? 'default';
            $styleIdx = self::$styleMap[$style] ?? 0;
            $isNumber = ($style === 'number');
            $row[] = ['v' => $value, 't' => $isNumber ? 'n' : 's', 's' => $styleIdx];
        }
        $this->sheets[$this->cur]['rows'][] = ['cells' => $row, 'hidden' => !empty($opts['hidden'])];
        return count($this->sheets[$this->cur]['rows']);
    }

    public function addDropdown($sqref, array $options) {
        $this->sheets[$this->cur]['dropdowns'][] = ['sqref' => $sqref, 'options' => array_values($options)];
    }

    public function addMerge($ref) {
        $this->sheets[$this->cur]['merges'][] = $ref;
    }

    public function setColumnWidths(array $widths) {
        foreach ($widths as $i => $w) {
            $this->sheets[$this->cur]['colWidths'][$i + 1] = (float)$w;
        }
    }

    public function hideColumn($colNumber1Based) {
        $this->sheets[$this->cur]['hiddenCols'][(int)$colNumber1Based] = true;
    }

    /**
     * Add a formula-driven conditional-format rule. When $formula (an Excel
     * expression WITHOUT the leading '=') evaluates true for the anchor cell, the
     * gray "inactive" dxf is applied over $sqref. Used to dim conditional
     * questions whose controlling answer does not match.
     */
    public function addConditionalFormat($sqref, $formula) {
        $this->sheets[$this->cur]['condFormats'][] = ['sqref' => $sqref, 'formula' => $formula];
    }

    /** Build the .xlsx and return the raw bytes. */
    public function build() {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate temp file for xlsx.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Unable to open zip for xlsx.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet));
        }
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read generated xlsx.');
        }
        return $bytes;
    }

    // -- XML parts ----------------------------------------------------------

    private function contentTypesXml() {
        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml() {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $state = $sheet['hidden'] ? ' state="hidden"' : '';
            $sheets .= '<sheet name="' . self::attr($sheet['name']) . '" sheetId="' . ($i + 1)
                . '"' . $state . ' r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml() {
        $rels = '';
        foreach ($this->sheets as $i => $sheet) {
            $rels .= '<Relationship Id="rId' . ($i + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $stylesId = count($this->sheets) + 1;
        $rels .= '<Relationship Id="rId' . $stylesId
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    /** Styles. No protection anywhere -- sheets are never protected. */
    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            .   '<font><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="' . $this->headerFill . '"/><bgColor indexed="64"/></patternFill></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
            .   '<border>'
            .     '<left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
            .     '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/>'
            .   '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                              // 0 default
            .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                // 1 title
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                // 2 label
            .   '<xf numFmtId="49" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                               // 3 value
            .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 4 section
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'               // 5 question
            .   '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 6 answer
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                              // 7 number
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                              // 8 marker
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 9 sectiondesc
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            // dxf 0: the "inactive" look for a conditional question whose
            // controlling answer does not match -- gray italic text on a light
            // gray fill (dxf fills use bgColor for the solid color).
            . '<dxfs count="1"><dxf><font><i/><color rgb="FF9CA3AF"/></font>'
            .   '<fill><patternFill><bgColor rgb="FFF3F4F6"/></patternFill></fill></dxf></dxfs>'
            . '</styleSheet>';
    }

    private function sheetXml(array $sheet) {
        $rows = $sheet['rows'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $lastCol = $this->maxColumns($sheet);
        $lastRow = max(1, count($rows));
        $xml .= '<dimension ref="A1:' . self::colLetter($lastCol) . $lastRow . '"/>';
        $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if (!empty($sheet['colWidths']) || !empty($sheet['hiddenCols'])) {
            $xml .= '<cols>';
            for ($c = 1; $c <= $lastCol; $c++) {
                $w = $sheet['colWidths'][$c] ?? null;
                $hidden = !empty($sheet['hiddenCols'][$c]);
                if ($w === null && !$hidden) continue;
                $xml .= '<col min="' . $c . '" max="' . $c . '"';
                $xml .= ' width="' . ($w !== null ? self::num($w) : '10') . '" customWidth="1"';
                if ($hidden) $xml .= ' hidden="1"';
                $xml .= '/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $r => $rowInfo) {
            $rowNum = $r + 1;
            $xml .= '<row r="' . $rowNum . '"';
            if (!empty($rowInfo['hidden'])) $xml .= ' hidden="1"';
            $xml .= '>';
            foreach ($rowInfo['cells'] as $c => $cell) {
                $ref = self::colLetter($c + 1) . $rowNum;
                $s = (int)$cell['s'];
                if ($cell['t'] === 'n') {
                    $v = $cell['v'];
                    if ($v === '' || $v === null || !is_numeric($v)) {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                    } else {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . self::num($v) . '</v></c>';
                    }
                } else {
                    $v = (string)$cell['v'];
                    if ($v === '') {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                    } else {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">'
                              . self::text($v) . '</t></is></c>';
                    }
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        // Element order per schema: sheetData, mergeCells, conditionalFormatting,
        // dataValidations.
        if (!empty($sheet['merges'])) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $m) {
                $xml .= '<mergeCell ref="' . self::attr($m) . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        foreach ($sheet['condFormats'] as $i => $cf) {
            $xml .= '<conditionalFormatting sqref="' . self::attr($cf['sqref']) . '">'
                  . '<cfRule type="expression" dxfId="0" priority="' . ($i + 1) . '">'
                  . '<formula>' . self::text($cf['formula']) . '</formula>'
                  . '</cfRule></conditionalFormatting>';
        }

        $valid = [];
        foreach ($sheet['dropdowns'] as $dv) {
            $formula = self::inlineListFormula($dv['options']);
            if ($formula === null) continue;
            $valid[] = '<dataValidation type="list" allowBlank="1" showInputMessage="1" '
                . 'showErrorMessage="1" sqref="' . self::attr($dv['sqref']) . '">'
                . '<formula1>' . $formula . '</formula1></dataValidation>';
        }
        if (!empty($valid)) {
            $xml .= '<dataValidations count="' . count($valid) . '">' . implode('', $valid) . '</dataValidations>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    // -- utilities ----------------------------------------------------------

    private function maxColumns(array $sheet) {
        $max = 1;
        foreach ($sheet['rows'] as $rowInfo) {
            $max = max($max, count($rowInfo['cells']));
        }
        foreach (array_keys($sheet['colWidths']) as $c) $max = max($max, $c);
        foreach (array_keys($sheet['hiddenCols']) as $c) $max = max($max, $c);
        return $max;
    }

    private static function inlineListFormula(array $options) {
        $clean = [];
        foreach ($options as $opt) {
            $opt = (string)$opt;
            if (strpos($opt, ',') !== false || strpos($opt, '"') !== false) return null;
            $clean[] = $opt;
        }
        if (empty($clean)) return null;
        $joined = implode(',', $clean);
        if (strlen($joined) + 2 > 255) return null;
        return '"' . self::text($joined) . '"';
    }

    private static function colLetter($n) {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    private static function num($v) {
        if (is_int($v) || (is_string($v) && ctype_digit(ltrim($v, '-')))) return (string)(int)$v;
        return rtrim(rtrim(sprintf('%.4F', (float)$v), '0'), '.');
    }

    private static function text($s) {
        $s = (string)$s;
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function attr($s) {
        return self::text($s);
    }
}
