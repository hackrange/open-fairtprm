<?php
/**
 * Assessment Fillable PDF Generator
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Produces a professional, IRS-W-9-style fillable PDF of a vendor assessment
 * template. Every input is a real AcroForm field so recipients can complete,
 * save, and return the document from any PDF reader -- no web portal required.
 *
 * Interactive behavior:
 *   - Text / textarea / email / number / date fields are real AcroForm text fields.
 *   - Single-choice questions render as clickable combo-box dropdowns.
 *   - Multi-choice questions render as real clickable Btn checkboxes.
 *   - Conditional questions (depends_on_question_id) hide their widgets until
 *     the controlling question has the expected value -- mirrors the web form
 *     behavior via document-level PDF JavaScript.
 *
 * The AcroForm dictionary sets NeedAppearances=true so modern readers (Adobe
 * Reader, Acrobat, Foxit, and macOS Preview) paint field visuals on open and
 * let the user save a completed copy.
 *
 * GET parameters:
 *   template_id         required when assessment_id is absent
 *   assessment_id       load an existing assessment (prefills vendor data)
 *   vendor_name         override vendor name prefill
 *   vendor_contact      override contact name prefill
 *   vendor_contact_email override contact email prefill
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$security = Security::getInstance();
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
    // they are linked to (matches vendor-assessment-view.php / the list scoping).
    if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isAuditor) {
        $vrid = (int)($assessment['vendor_request_id'] ?? 0);
        $link = $vrid ? Database::getInstance()->fetchOne(
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
$vendorContact = $assessment['vendor_contact_name'] ?? ($_GET['vendor_contact'] ?? '');
$vendorContactEmail = $assessment['vendor_contact_email'] ?? ($_GET['vendor_contact_email'] ?? '');
$assessmentRef = $assessment['uuid'] ?? '';

// Pull any in-progress responses so the PDF reflects whatever the vendor has
// already filled in via the web form. Keyed by question_id.
$existingResponses = [];
if (!empty($assessment['id'])) {
    $existingResponses = $service->getResponses((int)$assessment['id']);
}

$companyName = getAppConfig('company_name', '') ?: '';
$theme = getUserTheme();

$logoPath = null;
$logoCandidate = $theme['logo_url'] ?? '';
if ($logoCandidate && !preg_match('#^https?://#i', $logoCandidate)) {
    $docRoot = realpath(dirname(__DIR__));
    $candidate = $docRoot . DIRECTORY_SEPARATOR . ltrim($logoCandidate, '/');
    if (is_file($candidate)) {
        $logoPath = $candidate;
    }
}

$hexColor = $theme['header_color'] ?? '#35a0a3';
$primaryColor = hexToRgb($hexColor);

require_once __DIR__ . '/../vendor/autoload.php';

$options = new \Dompdf\Options();
$options->set('isFontSubsettingEnabled', false);
$options->set('defaultFont', 'helvetica');
$options->set('isRemoteEnabled', false);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->setPaper('letter', 'portrait');
$dompdf->loadHtml('<!DOCTYPE html><html><head></head><body></body></html>');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$cpdf = $canvas->get_cpdf();

$pdfBuilder = new AssessmentPdfBuilder($canvas, $cpdf, $primaryColor, $logoPath, $companyName, $template['name']);
$pdfBuilder->setExistingResponses($existingResponses);

// Collect dependency info across all questions so conditional show/hide works
// in the generated PDF's JavaScript.
$pdfBuilder->registerDependencies($sections);

$pdfBuilder->startDocument();
$pdfBuilder->drawVendorInfoBlock([
    'Vendor Name'           => $vendorName,
    'Vendor Contact Name'   => $vendorContact,
    'Vendor Contact Email'  => $vendorContactEmail,
    'Reference'             => $assessmentRef,
]);

if (!empty($template['description'])) {
    $pdfBuilder->drawIntroBlock($template['description']);
}

foreach ($sections as $index => $section) {
    $pdfBuilder->drawSectionHeader($index + 1, $section['name'], $section['description'] ?? '');
    foreach ($section['questions'] as $q) {
        $pdfBuilder->drawQuestion($q);
    }
}

$pdfBuilder->drawSignatureBlock();
$pdfBuilder->finalize();

$filename = sanitizeFilename($template['name']) . '-Assessment.pdf';
if ($vendorName) {
    $filename = sanitizeFilename($vendorName) . '-' . $filename;
}

$pdfContent = $dompdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: private, no-cache, must-revalidate');
header('Pragma: public');
echo $pdfContent;
exit;

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return [0.208, 0.628, 0.639];
    }
    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

function sanitizeFilename($name) {
    $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $name);
    $name = preg_replace('/\s+/', '_', trim($name));
    if ($name === '') $name = 'Vendor';
    return substr($name, 0, 80);
}

/**
 * Layout engine for the fillable PDF. Uses dompdf's low-level canvas + the
 * underlying Cpdf so each input, textarea, dropdown, and checkbox is a real
 * AcroForm field. Also wires up PDF JavaScript to hide conditional questions
 * until their controlling question is answered appropriately.
 */
class AssessmentPdfBuilder {
    private $canvas;
    private $cpdf;
    private $primaryColor;
    private $logoPath;
    private $companyName;
    private $templateName;

    private $pageW;
    private $pageH;
    private $marginLeft = 50;
    private $marginRight = 50;
    private $marginTop = 50;
    private $marginBottom = 70;
    private $contentW;
    private $currentY;
    private $pageNum = 1;

    private $font = 'helvetica';
    private $fontBold = 'helvetica-bold';
    private $fontItalic = 'helvetica-oblique';

    private $textColor = [0.13, 0.13, 0.13];
    private $mutedColor = [0.40, 0.40, 0.40];
    private $borderColor = [0.70, 0.70, 0.70];
    private $accentBg = [0.96, 0.97, 0.98];
    private $headerInk = [1.0, 1.0, 1.0];

    // Dependency tracking ----------------------------------------------------
    // $dependencies[$parentQid] = [ ['qid' => depQid, 'value' => expected], ... ]
    // An empty expected value means "any non-empty answer on the parent shows
    // the dependent question" (matches the web form semantics).
    private $dependencies = [];
    // Map of controlling question IDs => controller combo-box field name
    private $controllerFields = [];
    // Map of controlling multi-select (checkbox) question IDs => list of
    // ['value' => optionText, 'name' => checkboxFieldName, 'fieldId' => id].
    // Lets a question depend on a specific option of a multi-select parent
    // (e.g. show "Other AI Providers" only when "Others" is checked).
    private $checkboxControllers = [];
    // Map of question id => ['type' => 'text|combo|checkbox', 'fields' => [fieldName,...]]
    private $questionWidgets = [];
    // Questions with at least one dependent (so we know which controllers need JS)
    private $hasDependents = [];
    // Map of question id => short question text (used in conditional hints)
    private $questionSummaries = [];
    // Saved responses keyed by question id, exactly as returned by
    // VendorAssessmentService::getResponses(). Used to prefill fields.
    private $existingResponses = [];
    // Section widgets that should hide when every question inside them is
    // hidden by its own dependency. Populated in drawSectionHeader.
    // $sectionWidgets[$sectionIdx] = [fieldName, ...]
    private $sectionWidgets = [];
    // $sectionQuestionIds[$sectionIdx] = [qid1, qid2, ...]
    private $sectionQuestionIds = [];
    private $currentSectionIdx = -1;

    // Cached checkbox appearance-stream XObject ids. Every checkbox is the same
    // size, so a single /Yes + /Off pair is shared across all of them. Populated
    // lazily by checkboxAppearanceIds().
    private $cbAppYes = null;
    private $cbAppOff = null;

    // Field names that ship Hidden and are revealed by document-level JS (which
    // only Adobe runs) -- the digital signature + date-signed widgets. Browser
    // PDF viewers can't sign or show a date picker, so they never see these.
    private $adobeOnlyFields = [];
    // Set by field-drawing helpers so callers can grab the just-created object id.
    private $lastFieldId = null;

    public function __construct($canvas, $cpdf, $primaryColor, $logoPath, $companyName, $templateName) {
        $this->canvas = $canvas;
        $this->cpdf = $cpdf;
        $this->primaryColor = $primaryColor;
        $this->logoPath = $logoPath;
        $this->companyName = $companyName ?: 'Third-Party Risk Management';
        $this->templateName = $templateName;
        $this->pageW = $canvas->get_width();
        $this->pageH = $canvas->get_height();
        $this->contentW = $this->pageW - $this->marginLeft - $this->marginRight;
    }

    public function setExistingResponses(array $responses) {
        $this->existingResponses = $responses;
    }

    /**
     * Extract a saved response value for a given question id. Multi-select
     * answers are stored as a JSON array -- for those, returns an array of
     * string values. Everything else comes back as a trimmed string.
     */
    private function getResponseFor($qid) {
        $r = $this->existingResponses[$qid] ?? null;
        if (!$r) return null;
        $raw = $r['response_value'] ?? '';
        if ($raw === '' || $raw === null) return null;
        // Multi-select responses are JSON arrays of selected option strings.
        if (is_string($raw) && strlen($raw) > 0 && ($raw[0] === '[' || $raw[0] === '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return (string)$raw;
    }

    public function registerDependencies(array $sections) {
        foreach ($sections as $section) {
            foreach (($section['questions'] ?? []) as $q) {
                $qid = (int)$q['id'];
                $summary = trim(strip_tags($q['question_text'] ?? ''));
                // Keep the full question text; drawQuestion will wrap long
                // conditional hints onto as many lines as needed.
                $summary = preg_replace('/\s+/', ' ', $summary);
                $this->questionSummaries[$qid] = $summary;

                $parent = !empty($q['depends_on_question_id']) ? (int)$q['depends_on_question_id'] : 0;
                if ($parent > 0) {
                    $this->dependencies[$parent][] = [
                        'qid'   => $qid,
                        'value' => (string)($q['depends_on_value'] ?? ''),
                    ];
                    $this->hasDependents[$parent] = true;
                }
            }
        }
    }

    public function startDocument() {
        // Request NeedAppearances so modern PDF readers paint the form visuals
        // (important so users can save completed forms with state preserved).
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $this->drawHeader();
        $this->drawFooter();
        $this->currentY = $this->marginTop + 110;
    }

    private function drawHeader() {
        $headerHeight = 85;
        $this->canvas->filled_rectangle(0, 0, $this->pageW, $headerHeight, $this->primaryColor);

        $title = 'Vendor Security Assessment';
        $subtitle = $this->templateName;
        $this->canvas->text(
            $this->marginLeft + 180, 30, $title,
            $this->fontBold, 18, $this->headerInk
        );
        $this->canvas->text(
            $this->marginLeft + 180, 55, $subtitle,
            $this->font, 11, [0.92, 0.96, 0.97]
        );

        if ($this->logoPath && is_file($this->logoPath)) {
            $size = @getimagesize($this->logoPath);
            if ($size) {
                list($iw, $ih) = $size;
                $maxH = 50; $maxW = 160;
                $ratio = $iw / $ih;
                $h = $maxH;
                $w = $h * $ratio;
                if ($w > $maxW) { $w = $maxW; $h = $w / $ratio; }
                $x = $this->marginLeft;
                $y = ($headerHeight - $h) / 2;
                try {
                    $this->canvas->image($this->logoPath, $x, $y, $w, $h, 'normal');
                } catch (\Exception $e) { /* ignore logo errors */ }
            }
        }

        $this->canvas->filled_rectangle(0, $headerHeight, $this->pageW, 3, [0.20, 0.25, 0.32]);
    }

    private function drawFooter() {
        $y = $this->pageH - 38;
        $this->canvas->line(
            $this->marginLeft, $y, $this->pageW - $this->marginRight, $y,
            $this->borderColor, 0.5
        );
        $footerText = $this->companyName . '  -  Confidential';
        $this->canvas->text($this->marginLeft, $y + 10, $footerText, $this->font, 8, $this->mutedColor);

        $right = 'Page ' . $this->pageNum;
        $w = $this->canvas->get_text_width($right, $this->font, 8);
        $this->canvas->text($this->pageW - $this->marginRight - $w, $y + 10, $right, $this->font, 8, $this->mutedColor);

        $generated = 'Generated ' . date('M j, Y');
        $gw = $this->canvas->get_text_width($generated, $this->font, 8);
        $this->canvas->text(($this->pageW - $gw) / 2, $y + 10, $generated, $this->font, 8, $this->mutedColor);
    }

    public function drawVendorInfoBlock(array $fields) {
        $x = $this->marginLeft;
        $labelY = $this->currentY;

        $this->canvas->filled_rectangle($x, $labelY, $this->contentW, 22, [0.20, 0.25, 0.32]);
        $this->canvas->text($x + 10, $labelY + 6, 'VENDOR INFORMATION', $this->fontBold, 11, $this->headerInk);
        $this->currentY += 32;

        $colW = ($this->contentW - 14) / 2;
        $rowH = 38;
        $col = 0;
        foreach ($fields as $label => $value) {
            $cx = $x + ($col === 0 ? 0 : $colW + 14);
            $cy = $this->currentY;
            // Reference is the assessment UUID that the importer matches
            // against -- lock it so a signer can't accidentally overwrite it.
            $readonly = (strcasecmp($label, 'Reference') === 0);
            $this->drawLabeledInput($cx, $cy, $colW, $label, $value, $readonly);
            $col++;
            if ($col >= 2) { $col = 0; $this->currentY += $rowH; }
        }
        if ($col !== 0) $this->currentY += $rowH;
        $this->currentY += 8;
    }

    public function drawIntroBlock($description) {
        $this->ensureSpace(60);
        $desc = trim(strip_tags($description));
        if ($desc === '') return;
        $lines = $this->wrapText($desc, $this->contentW - 16, $this->font, 10);
        $padding = 10;
        $height = ($padding * 2) + (count($lines) * 14);
        $this->canvas->filled_rectangle($this->marginLeft, $this->currentY, $this->contentW, $height, $this->accentBg);
        $this->canvas->rectangle($this->marginLeft, $this->currentY, $this->contentW, $height, $this->borderColor, 0.5);
        $ty = $this->currentY + $padding + 4;
        foreach ($lines as $line) {
            $this->canvas->text($this->marginLeft + $padding, $ty, $line, $this->font, 10, $this->textColor);
            $ty += 14;
        }
        $this->currentY += $height + 12;
    }

public function drawSectionHeader($num, $name, $description = '') {
        $this->ensureSpace(60);
        $this->currentSectionIdx++;
        $idx = $this->currentSectionIdx;
        $this->sectionWidgets[$idx] = [];
        $this->sectionQuestionIds[$idx] = [];

        // Header bar: read-only text field with the primary color as BG so we
        // can hide it alongside its questions when the controller disagrees.
        $label = 'PART ' . $num . ' -- ' . strtoupper($name);
        $barName = $this->drawReadOnlyText(
            $this->marginLeft, $this->currentY, $this->contentW, 22,
            $label, $this->fontBold, 11, $this->headerInk,
            'left', $this->primaryColor
        );
        if ($barName) $this->sectionWidgets[$idx][] = $barName;
        $this->currentY += 28;

        $desc = trim(strip_tags($description ?? ''));
        if ($desc !== '') {
            $lines = $this->wrapText($desc, $this->contentW, $this->fontItalic, 9);
            $h = count($lines) * 12;
            $text = implode("\n", $lines);
            $descName = $this->drawReadOnlyText(
                $this->marginLeft, $this->currentY, $this->contentW, $h,
                $text, $this->fontItalic, 9, $this->mutedColor
            );
            if ($descName) $this->sectionWidgets[$idx][] = $descName;
            $this->currentY += $h + 4;
        }
    }

    public function drawQuestion($q) {
        $questionText = trim(strip_tags($q['question_text'] ?? ''));
        $helpText = trim(strip_tags($q['help_text'] ?? ''));
        $type = $q['question_type'] ?? 'text';
        $required = !empty($q['is_required']);
        $qid = (int)$q['id'];
        $isConditional = !empty($q['depends_on_question_id']);

        // Remember this question belongs to the current section so aggregate
        // section hiding can check it later.
        if ($this->currentSectionIdx >= 0) {
            $this->sectionQuestionIds[$this->currentSectionIdx][] = $qid;
        }

        $labelLines = $this->wrapText($questionText . ($required ? ' *' : ''), $this->contentW, $this->fontBold, 10);
        $helpLines = $helpText ? $this->wrapText($helpText, $this->contentW, $this->fontItalic, 9) : [];

        $condHint = '';
        if ($isConditional) {
            $parentQid = (int)$q['depends_on_question_id'];
            $parentSummary = $this->questionSummaries[$parentQid] ?? 'the previous question';
            $condVal = (string)($q['depends_on_value'] ?? '');
            $condHint = $condVal !== ''
                ? sprintf('Only applies when "%s" is answered "%s".', $parentSummary, $condVal)
                : sprintf('Only applies when "%s" is answered.', $parentSummary);
        }
        $condLines = $condHint ? $this->wrapText($condHint, $this->contentW, $this->fontItalic, 8) : [];

        $fieldHeight = $this->fieldHeightForType($type, $q);
        $labelBlockH = count($labelLines) * 13;
        $condBlockH = count($condLines) * 11;
        $helpBlockH = count($helpLines) * 12;
        $blockHeight = $labelBlockH + $condBlockH + $helpBlockH + $fieldHeight + 14;
        $this->ensureSpace($blockHeight);

        // Widgets belonging to this question that should hide/show together.
        $questionFieldNames = [];

        if ($isConditional) {
            if (!empty($labelLines)) {
                $name = $this->drawReadOnlyText(
                    $this->marginLeft, $this->currentY, $this->contentW, $labelBlockH,
                    implode("\n", $labelLines), $this->fontBold, 10, $this->textColor
                );
                if ($name) $questionFieldNames[] = $name;
                $this->currentY += $labelBlockH;
            }
            if (!empty($condLines)) {
                $name = $this->drawReadOnlyText(
                    $this->marginLeft, $this->currentY, $this->contentW, $condBlockH,
                    implode("\n", $condLines), $this->fontItalic, 8, [0.55, 0.35, 0.08]
                );
                if ($name) $questionFieldNames[] = $name;
                $this->currentY += $condBlockH;
            }
            if (!empty($helpLines)) {
                $name = $this->drawReadOnlyText(
                    $this->marginLeft, $this->currentY, $this->contentW, $helpBlockH,
                    implode("\n", $helpLines), $this->fontItalic, 9, $this->mutedColor
                );
                if ($name) $questionFieldNames[] = $name;
                $this->currentY += $helpBlockH;
            }
        } else {
            foreach ($labelLines as $line) {
                $this->canvas->text($this->marginLeft, $this->currentY, $line, $this->fontBold, 10, $this->textColor);
                $this->currentY += 13;
            }
            foreach ($helpLines as $line) {
                $this->canvas->text($this->marginLeft, $this->currentY, $line, $this->fontItalic, 9, $this->mutedColor);
                $this->currentY += 12;
            }
        }

        $this->currentY += 2;
        $this->drawInputForType($type, $q, $isConditional, $questionFieldNames);
        $this->currentY += 10;
    }

    private function fieldHeightForType($type, $q) {
        switch ($type) {
            case 'textarea':     return 72;
            case 'checkbox':
            case 'button_group_multi':
            case 'radio':
            case 'button_group':
            case 'select':
                $count = is_array($q['options'] ?? null) ? count($q['options']) : 0;
                if ($type === 'select' || $type === 'radio' || $type === 'button_group') {
                    // Rendered as a single combo box
                    return 22;
                }
                if ($count <= 0) return 22;
                return ceil($count / 2) * 22 + 6;
            default: return 22;
        }
    }

    private function drawInputForType($type, $q, $isConditional = false, array $existingNames = []) {
        $x = $this->marginLeft;
        $w = $this->contentW;
        $qid = (int)$q['id'];
        $names = $existingNames;
        $saved = $this->getResponseFor($qid);
        // Coerce simple saved values to a string for single-value fields.
        $savedStr = is_string($saved) ? $saved : '';

        switch ($type) {
            case 'textarea':
                $name = $this->widgetName('q_' . $qid . '_text');
                $fieldId = $this->drawTextArea($x, $this->currentY, $w, 72, $name, $isConditional);
                $this->setFieldValueSafe($fieldId, $savedStr);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 72;
                break;

            case 'select':
            case 'radio':
            case 'button_group':
                $opts = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
                if (empty($opts)) {
                    $name = $this->widgetName('q_' . $qid . '_text');
                    $fieldId = $this->drawSingleLineInput($x, $this->currentY, $w, 22, $name, '', $savedStr, $isConditional);
                    $names[] = $name;
                    $this->registerWidgets($qid, 'text', $names);
                    $this->currentY += 22;
                } else {
                    $name = 'q_' . $qid . '_choice';
                    $fieldId = $this->drawComboBox($x, $this->currentY, min($w, 360), 22, $name, $opts, $isConditional);
                    $this->setFieldValueSafe($fieldId, $savedStr);
                    $names[] = $name;
                    $this->registerWidgets($qid, 'combo', $names);
                    $this->controllerFields[$qid] = ['name' => $name, 'fieldId' => $fieldId];
                    $this->currentY += 22;
                }
                break;

            case 'checkbox':
            case 'button_group_multi':
                $opts = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
                if (empty($opts)) {
                    $name = $this->widgetName('q_' . $qid . '_text');
                    $fieldId = $this->drawSingleLineInput($x, $this->currentY, $w, 22, $name, '', $savedStr, $isConditional);
                    $names[] = $name;
                    $this->registerWidgets($qid, 'text', $names);
                    $this->currentY += 22;
                } else {
                    $selected = is_array($saved) ? $saved : [];
                    $fieldNames = $this->drawCheckboxGroup($x, $this->currentY, $w, $qid, $opts, $isConditional, $selected);
                    foreach ($fieldNames as $fn) $names[] = $fn;
                    $this->registerWidgets($qid, 'checkbox', $names);
                    $this->currentY += $this->fieldHeightForType($type, $q);
                }
                break;

            case 'file':
                $name = $this->widgetName('q_' . $qid . '_file');
                $fieldId = $this->drawSingleLineInput($x, $this->currentY, $w, 22, $name, 'Reference / filename (attach supporting file separately)', $savedStr, $isConditional);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 22;
                break;

            case 'date':
                $name = $this->widgetName('q_' . $qid . '_date');
                $fieldId = $this->drawDateInput($x, $this->currentY, 200, 22, $name, $isConditional);
                $this->setFieldValueSafe($fieldId, $savedStr);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 22;
                break;

            case 'email':
                $name = $this->widgetName('q_' . $qid . '_email');
                $fieldId = $this->drawSingleLineInput($x, $this->currentY, $w, 22, $name, 'name@example.com', $savedStr, $isConditional);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 22;
                break;

            case 'number':
                $name = $this->widgetName('q_' . $qid . '_number');
                $fieldId = $this->drawSingleLineInput($x, $this->currentY, 220, 22, $name, '', $savedStr, $isConditional);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 22;
                break;

            case 'text':
            default:
                $name = $this->widgetName('q_' . $qid . '_text');
                $fieldId = $this->drawSingleLineInput($x, $this->currentY, $w, 22, $name, '', $savedStr, $isConditional);
                $names[] = $name;
                $this->registerWidgets($qid, 'text', $names);
                $this->currentY += 22;
                break;
        }
    }

    /**
     * Escape + set a text-field /V. Shared helper so combo boxes, date fields,
     * and textareas don't each re-implement the paren/backslash escape.
     */
    private function setFieldValueSafe($fieldId, $value) {
        if (!$fieldId || $value === null || $value === '') return;
        $safe = strtr((string)$value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        try { $this->cpdf->setFormFieldValue($fieldId, $safe); } catch (\Exception $e) { /* no-op */ }
    }

    private function drawLabeledInput($x, $y, $w, $label, $value, $readonly = false) {
        $this->canvas->text($x, $y, strtoupper($label), $this->fontBold, 8, [0.30, 0.36, 0.44]);
        $fy = $y + 12;
        $fieldName = $this->widgetName('vendor_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($label)));
        $fieldId = $this->drawSingleLineInput($x, $fy, $w, 22, $fieldName, '', $value);
        if ($readonly && $fieldId && isset($this->cpdf->objects[$fieldId])) {
            // Set the AcroForm ReadOnly flag (bit 1 of /Ff) so the value stays
            // visible but the reader refuses edits. Preserves other flags that
            // Cpdf may have already set.
            $info = &$this->cpdf->objects[$fieldId]['info'];
            $existing = isset($info['Ff']) ? (int)$info['Ff'] : 0;
            $info['Ff'] = $existing | \Dompdf\Cpdf::ACROFORM_FIELD_READONLY;
            unset($info);
        }
        return $fieldId;
    }

    private function drawSingleLineInput($x, $y, $w, $h, $name, $placeholder = '', $value = '', $isConditional = false) {
        if (!$isConditional) {
            // Canvas background is page content and cannot be hidden via JS,
            // so skip it for conditional fields and paint the box on the
            // widget itself (see below).
            $this->canvas->filled_rectangle($x, $y, $w, $h, [0.98, 0.98, 0.99]);
            $this->canvas->rectangle($x, $y, $w, $h, $this->borderColor, 0.6);
        }
        $fieldId = $this->addTextField($name, $x, $y, $w, $h, false);
        if ($isConditional) $this->applyInputChrome($fieldId);
        if ($value !== '' && $fieldId) {
            $safe = strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $this->cpdf->setFormFieldValue($fieldId, $safe);
        }
        if (!$isConditional && $placeholder !== '' && $value === '') {
            $this->canvas->text($x + 5, $y + 7, $placeholder, $this->fontItalic, 9, [0.65, 0.65, 0.65]);
        }
        return $fieldId;
    }

    private function drawTextArea($x, $y, $w, $h, $name, $isConditional = false) {
        if (!$isConditional) {
            $this->canvas->filled_rectangle($x, $y, $w, $h, [0.98, 0.98, 0.99]);
            $this->canvas->rectangle($x, $y, $w, $h, $this->borderColor, 0.6);
        }
        $fieldId = $this->addTextField($name, $x, $y, $w, $h, true);
        if ($isConditional) $this->applyInputChrome($fieldId);
        return $fieldId;
    }

    private function drawComboBox($x, $y, $w, $h, $name, array $options, $isConditional = false) {
        if (!$isConditional) {
            $this->canvas->filled_rectangle($x, $y, $w, $h, [0.98, 0.98, 0.99]);
            $this->canvas->rectangle($x, $y, $w, $h, $this->borderColor, 0.6);
            // Dropdown arrow glyph drawn on canvas (readers draw one too,
            // but this guarantees visibility when no appearance stream is set).
            $arrowX = $x + $w - 14;
            $arrowY = $y + $h/2 - 2;
            $this->canvas->polygon(
                [$arrowX, $arrowY, $arrowX + 8, $arrowY, $arrowX + 4, $arrowY + 5],
                [0.35, 0.35, 0.4], null, [], true
            );
        }

        $this->cpdf->selectFont($this->font);
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $yTop = $this->pageH - $y;
        $yBot = $this->pageH - ($y + $h);
        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_CHOICE;
        $ff = \Dompdf\Cpdf::ACROFORM_FIELD_CHOICE_COMBO | \Dompdf\Cpdf::ACROFORM_FIELD_CHOICE_EDIT;
        try {
            $id = $this->cpdf->addFormField($ft, $name, $x, $yBot, $x + $w, $yTop, $ff, 10, [0, 0, 0]);
            $choices = array_merge([''], array_map('strval', $options));
            $this->cpdf->setFormFieldOpt($id, $choices);
            if ($isConditional) $this->applyInputChrome($id);
            return $id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Paints a 0.6pt grey border + light-grey fill on the widget itself. Used
     * for conditional inputs so the chrome hides/shows with the widget.
     */
    private function applyInputChrome($fieldId) {
        if (!$fieldId || !isset($this->cpdf->objects[$fieldId])) return;
        $info = &$this->cpdf->objects[$fieldId]['info'];
        $info['Border'] = '[0 0 0.6]';
        $info['MK'] = '<< /BC [0.7 0.7 0.7] /BG [0.98 0.98 0.99] >>';
        unset($info);
    }

    /**
     * Renders a group of real Btn checkboxes. Each checkbox is a separate
     * AcroForm field with a unique name so the reader toggles it independently.
     * When $isConditional is true, the option labels render as read-only form
     * fields so they hide alongside their checkboxes.
     * Returns an array of generated field names (checkboxes + optionally labels).
     */
    private function drawCheckboxGroup($x, $y, $w, $qid, array $options, $isConditional = false, array $selectedValues = []) {
        $cols = 2;
        $colW = ($w - 10) / $cols;
        $rowH = 22;
        $col = 0; $row = 0;
        $names = [];
        $controllerOpts = [];
        // Normalise selected values for a case-insensitive comparison
        $selectedNorm = array_map(function($v) { return strtolower(trim((string)$v)); }, $selectedValues);
        foreach ($options as $idx => $opt) {
            $cx = $x + $col * ($colW + 10);
            $cy = $y + $row * $rowH;

            $boxSize = 12;
            $boxX = $cx;
            $boxY = $cy + 3;

            // The widget itself paints a 1pt border via /Border + /MK /BC so
            // the empty checkbox visual appears/disappears with the field.
            $fieldName = 'q_' . $qid . '_cb_' . $idx;
            $isChecked = in_array(strtolower(trim((string)$opt)), $selectedNorm, true);
            $cbId = $this->addCheckboxField($fieldName, $boxX, $boxY, $boxSize, $boxSize, $isChecked);
            $names[] = $fieldName;
            // Record each option so a dependent question can key off a specific
            // checked value (conditional show/hide for multi-select parents).
            $controllerOpts[] = ['value' => (string)$opt, 'name' => $fieldName, 'fieldId' => $cbId];

            $label = (string)$opt;
            $labelMaxW = $colW - $boxSize - 10;
            if ($isConditional) {
                $labelName = $this->drawReadOnlyText(
                    $boxX + $boxSize + 6, $cy + 3, $labelMaxW, 14,
                    $label, $this->font, 9, $this->textColor
                );
                if ($labelName) $names[] = $labelName;
            } else {
                $labelLines = $this->wrapText($label, $labelMaxW, $this->font, 9);
                if (!empty($labelLines)) {
                    $this->canvas->text($boxX + $boxSize + 6, $cy + 6, $labelLines[0], $this->font, 9, $this->textColor);
                }
            }

            $col++;
            if ($col >= $cols) { $col = 0; $row++; }
        }
        $this->checkboxControllers[$qid] = $controllerOpts;
        return $names;
    }

    /**
     * Borderless read-only text field used as a "label widget" so conditional
     * questions can hide their labels along with their inputs. When $bgColor is
     * provided, it paints a filled background via /MK /BG (used for section
     * header bars).
     */
    private function drawReadOnlyText($x, $y, $w, $h, $text, $font, $size, $color = null, $align = 'left', $bgColor = null) {
        if ($color === null) $color = $this->textColor;
        $this->cpdf->selectFont($font);
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $yTop = $this->pageH - $y;
        $yBot = $this->pageH - ($y + $h);
        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
        $ff = \Dompdf\Cpdf::ACROFORM_FIELD_READONLY | \Dompdf\Cpdf::ACROFORM_FIELD_TEXT_MULTILINE;
        $name = $this->widgetName('lbl');
        try {
            $fieldId = $this->cpdf->addFormField($ft, $name, $x, $yBot, $x + $w, $yTop, $ff, $size, $color);
            $this->lastFieldId = $fieldId;
            if ($fieldId && isset($this->cpdf->objects[$fieldId])) {
                // Cpdf::o_field writes /V ($v) without escaping, so any
                // unbalanced parens in $text would break the PDF literal. Belt
                // and suspenders: escape both parens + backslashes here.
                $safe = strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                $this->cpdf->setFormFieldValue($fieldId, $safe);
                $info = &$this->cpdf->objects[$fieldId]['info'];
                $info['Border'] = '[0 0 0]';
                if ($bgColor !== null) {
                    $info['MK'] = '<< /BG [' . sprintf('%.3F %.3F %.3F', $bgColor[0], $bgColor[1], $bgColor[2]) . '] >>';
                }
                unset($info);
            }
            return $name;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function drawSignatureBlock() {
        $this->ensureSpace(210);
        $this->currentY += 6;
        $this->canvas->filled_rectangle($this->marginLeft, $this->currentY, $this->contentW, 22, [0.20, 0.25, 0.32]);
        $this->canvas->text($this->marginLeft + 10, $this->currentY + 6, 'CERTIFICATION AND SIGNATURE', $this->fontBold, 11, $this->headerInk);
        $this->currentY += 28;

        $cert = 'I certify that the information provided in this assessment is true, complete, and accurate to the best of my knowledge.';
        $certLines = $this->wrapText($cert, $this->contentW, $this->font, 9);
        foreach ($certLines as $line) {
            $this->canvas->text($this->marginLeft, $this->currentY, $line, $this->font, 9, $this->textColor);
            $this->currentY += 12;
        }
        $this->currentY += 10;

        $halfW = ($this->contentW - 14) / 2;
        $this->drawLabeledInput($this->marginLeft, $this->currentY, $halfW, 'Authorized Signer Name', '');
        $this->drawLabeledInput($this->marginLeft + $halfW + 14, $this->currentY, $halfW, 'Title', '');
        $this->currentY += 38;

        // Primary signature: a typed-name TEXT field that every PDF viewer can
        // complete. Browser viewers (Chrome/Edge/Firefox) cannot apply a digital
        // or ink signature, so this typed field -- paired with the certification
        // statement above -- is what makes the form signable anywhere. Always
        // visible.
        $sigLabelY = $this->currentY;
        $this->canvas->text($this->marginLeft, $sigLabelY, 'SIGNATURE (TYPE FULL NAME)', $this->fontBold, 8, [0.30, 0.36, 0.44]);
        $this->drawSingleLineInput($this->marginLeft, $sigLabelY + 12, $halfW, 26, $this->widgetName('typed_signature'), '');

        // DATE SIGNED -- Adobe-only. Rendered as hideable widgets (label + field)
        // that ship Hidden. Acrobat/Reader run the reveal JS in finalize() and
        // expose the field with its calendar date picker; browser viewers, which
        // run no PDF JS and can't offer a picker, simply never show it.
        $dateLabelName = $this->drawReadOnlyText(
            $this->marginLeft + $halfW + 14, $sigLabelY, $halfW, 12,
            'DATE SIGNED (ADOBE ACROBAT / READER)', $this->fontBold, 8, [0.30, 0.36, 0.44]
        );
        $this->markAdobeOnly($dateLabelName, $this->lastFieldId);
        $dateName = $this->widgetName('date_signed');
        $dateId = $this->drawDateInput($this->marginLeft + $halfW + 14, $sigLabelY + 12, $halfW, 26, $dateName, true);
        $this->markAdobeOnly($dateName, $dateId);

        $this->currentY = $sigLabelY + 12 + 26 + 16;

        // DIGITAL SIGNATURE -- Adobe-only, same hide-in-browser treatment: a
        // hideable label + a real /Sig field, both shipped Hidden and revealed
        // only by Acrobat's JS.
        $this->ensureSpace(70);
        $digLabelY = $this->currentY;
        $digLabelName = $this->drawReadOnlyText(
            $this->marginLeft, $digLabelY, $halfW, 12,
            'DIGITAL SIGNATURE (ADOBE ACROBAT / READER ONLY)', $this->fontBold, 8, [0.30, 0.36, 0.44]
        );
        $this->markAdobeOnly($digLabelName, $this->lastFieldId);
        $sigFieldY = $digLabelY + 12;
        $sigH = 44;
        $sigId = $this->drawSignatureField($this->marginLeft, $sigFieldY, $halfW, $sigH, 'authorized_signature');
        $this->markAdobeOnly('authorized_signature', $sigId);

        $this->currentY = $sigFieldY + $sigH + 10;
    }

    /**
     * Real PDF signature field. Readers expose this as a digital-signing target.
     * Chrome is painted on the widget itself (border/fill via /MK) rather than
     * on the page canvas, so the field can be hidden cleanly in browser viewers
     * (see markAdobeOnly) without leaving an orphaned box behind.
     */
    private function drawSignatureField($x, $y, $w, $h, $name) {
        $this->cpdf->selectFont($this->font);
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $yTop = $this->pageH - $y;
        $yBot = $this->pageH - ($y + $h);
        try {
            $id = $this->cpdf->addFormField(
                \Dompdf\Cpdf::ACROFORM_FIELD_SIG,
                $name,
                $x, $yBot, $x + $w, $yTop,
                0, 10, [0, 0, 0]
            );
            if ($id && isset($this->cpdf->objects[$id])) {
                $this->cpdf->objects[$id]['info']['Border'] = '[0 0 0.6]';
                $this->cpdf->objects[$id]['info']['MK'] = '<< /BC [0.7 0.7 0.7] /BG [0.98 0.98 0.99] >>';
            }
            return $id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Text field with Acrobat's AFDate_KeystrokeEx / AFDate_FormatEx attached so
     * the field renders with Acrobat's date picker UI and validates input.
     * Other readers treat it as a free-text field with the MM/DD/YYYY hint.
     */
    private function drawDateInput($x, $y, $w, $h, $name, $isConditional = false) {
        if (!$isConditional) {
            $this->canvas->filled_rectangle($x, $y, $w, $h, [0.98, 0.98, 0.99]);
            $this->canvas->rectangle($x, $y, $w, $h, $this->borderColor, 0.6);
            $this->canvas->text($x + 5, $y + 7, 'MM/DD/YYYY', $this->fontItalic, 9, [0.65, 0.65, 0.65]);
            // Calendar glyph on the right edge (visual hint only)
            $iconX = $x + $w - 18;
            $iconY = $y + 4;
            $this->canvas->rectangle($iconX, $iconY, 14, 14, [0.30, 0.36, 0.44], 0.7);
            $this->canvas->filled_rectangle($iconX, $iconY, 14, 4, [0.30, 0.36, 0.44]);
        }

        $fieldId = $this->addTextField($name, $x, $y, $w, $h, false);
        if ($fieldId && isset($this->cpdf->objects[$fieldId])) {
            $aa = '<< /K << /Type /Action /S /JavaScript /JS (AFDate_KeystrokeEx("mm/dd/yyyy");) >> '
                . '/F << /Type /Action /S /JavaScript /JS (AFDate_FormatEx("mm/dd/yyyy");) >> >>';
            $this->cpdf->objects[$fieldId]['info']['AA'] = $aa;
            if ($isConditional) $this->applyInputChrome($fieldId);
        }
        return $fieldId;
    }

    /**
     * After all widgets have been laid down, emit document-level JavaScript that
     * (a) defines refreshDependencies(), (b) wires each controller combo box to
     * re-run it on value change, and (c) executes it once at open so the
     * initial state matches the data prefilled on the form.
     */
    public function finalize() {
        // Reveal the Adobe-only widgets (digital signature + date signed). They
        // ship Hidden so browser PDF viewers -- which execute no PDF JavaScript
        // -- never show them; Acrobat/Reader run this and make them visible.
        if (!empty($this->adobeOnlyFields)) {
            $reveal  = "var _AO = " . json_encode(array_values($this->adobeOnlyFields)) . ";\n";
            $reveal .= "for (var i=0;i<_AO.length;i++){ try { this.getField(_AO[i]).display = display.visible; } catch(e) {} }\n";
            $this->cpdf->addJavascript($reveal);
        }

        if (empty($this->dependencies) ||
            (empty($this->controllerFields) && empty($this->checkboxControllers))) {
            return;
        }

        // Per-question dependency rules. A dependent whose parent is a single-
        // choice dropdown gets a 'combo' rule (match the controller's value). A
        // dependent whose parent is a MULTI-select gets a 'cb' rule keyed to the
        // specific option's checkbox (e.g. show "Other AI Providers" only when
        // the "Others" box is checked), or 'cbany' when it depends on any answer.
        // An empty expected-value means "show whenever the parent has an answer".
        $questionRules = [];
        foreach ($this->dependencies as $parentQid => $dependents) {
            $isCombo = isset($this->controllerFields[$parentQid]);
            $isCb    = isset($this->checkboxControllers[$parentQid]);
            if (!$isCombo && !$isCb) continue;
            foreach ($dependents as $dep) {
                $depQid = (int)$dep['qid'];
                if (!isset($this->questionWidgets[$depQid])) continue;
                $expected = (string)$dep['value'];
                if ($isCombo) {
                    $questionRules[$depQid] = [
                        'kind'       => 'combo',
                        'controller' => $this->controllerFields[$parentQid]['name'],
                        'value'      => $expected,
                    ];
                    continue;
                }
                $optsMap  = $this->checkboxControllers[$parentQid];
                $allNames = array_values(array_map(function ($o) { return $o['name']; }, $optsMap));
                if ($expected === '') {
                    $questionRules[$depQid] = ['kind' => 'cbany', 'fields' => $allNames];
                } else {
                    $field = null;
                    foreach ($optsMap as $o) {
                        if (strcasecmp(trim((string)$o['value']), $expected) === 0) { $field = $o['name']; break; }
                    }
                    $questionRules[$depQid] = ($field !== null)
                        ? ['kind' => 'cb', 'field' => $field]
                        : ['kind' => 'cbany', 'fields' => $allNames];
                }
            }
        }

        // qid => widget field names
        $questionWidgets = [];
        foreach ($this->questionWidgets as $qid => $info) {
            if (!empty($info['fields'])) $questionWidgets[(int)$qid] = array_values($info['fields']);
        }

        // sectionIdx => {header_widgets, question_ids}
        $sections = [];
        foreach ($this->sectionWidgets as $idx => $widgets) {
            if (empty($widgets)) continue;
            $qids = $this->sectionQuestionIds[$idx] ?? [];
            $sections[] = [
                'headers' => array_values($widgets),
                'qids'    => array_values($qids),
            ];
        }

        if (empty($questionRules) && empty($sections)) return;

        $payload = [
            'rules'    => $questionRules,
            'widgets'  => $questionWidgets,
            'sections' => $sections,
        ];

        $js = "var _DEP = " . json_encode($payload) . ";\n";
        $js .= "function _dset(names, show) { for (var i=0;i<names.length;i++){ try { this.getField(names[i]).display = show ? display.visible : display.hidden; } catch(e) {} } }\n";
        $js .= "function refreshDependencies() {\n";
        // Compute visibility per question
        $js .= "  var vis = {};\n";
        $js .= "  for (var qid in _DEP.widgets) vis[qid] = true;\n";
        $js .= "  for (var qid in _DEP.rules) {\n";
        $js .= "    var r = _DEP.rules[qid]; var show = false; var v;\n";
        $js .= "    if (r.kind === 'combo') { v=''; try { v = this.getField(r.controller).value || ''; } catch(e) {}\n";
        $js .= "      show = (r.value === '') ? (v !== '' && v !== 'Off') : (String(v).toLowerCase() === String(r.value).toLowerCase()); }\n";
        $js .= "    else if (r.kind === 'cb') { v='Off'; try { v = this.getField(r.field).value; } catch(e) {} show = (v !== 'Off' && v !== '' && v != null); }\n";
        $js .= "    else if (r.kind === 'cbany') { for (var k=0;k<r.fields.length;k++){ var vv='Off'; try { vv = this.getField(r.fields[k]).value; } catch(e) {} if (vv !== 'Off' && vv !== '' && vv != null) { show = true; break; } } }\n";
        $js .= "    vis[qid] = show;\n";
        $js .= "  }\n";
        // Apply widget visibility
        $js .= "  for (var qid in _DEP.widgets) _dset(_DEP.widgets[qid], vis[qid]);\n";
        // Section visibility: visible if any contained question is visible OR
        // the section contains a question that has no dependency rule.
        $js .= "  for (var i=0;i<_DEP.sections.length;i++) {\n";
        $js .= "    var s = _DEP.sections[i]; var any = false;\n";
        $js .= "    for (var j=0;j<s.qids.length;j++) { if (vis[s.qids[j]]) { any = true; break; } }\n";
        $js .= "    _dset(s.headers, any || s.qids.length === 0);\n";
        $js .= "  }\n";
        $js .= "}\n";
        $js .= "refreshDependencies();\n";

        $this->cpdf->addJavascript($js);

        // Attach per-controller 'on value change' action so the graph refreshes
        // whenever a dropdown answer is picked.
        foreach ($this->controllerFields as $parentQid => $ctrl) {
            if (empty($this->hasDependents[$parentQid])) continue;
            $fieldId = $ctrl['fieldId'] ?? null;
            if (!$fieldId) continue;
            if (!isset($this->cpdf->objects[$fieldId])) continue;
            // Defer the refresh via app.setTimeOut so Validate / Blur / Keystroke
            // all fire AFTER the new value has been committed -- otherwise
            // getField().value still returns the previous answer on combo boxes.
            $js = 'app.setTimeOut("refreshDependencies();", 10);';
            $action = '<< /K << /Type /Action /S /JavaScript /JS (' . $js . ') >> '
                    . '/V << /Type /Action /S /JavaScript /JS (' . $js . ') >> '
                    . '/F << /Type /Action /S /JavaScript /JS (' . $js . ') >> '
                    . '/Bl << /Type /Action /S /JavaScript /JS (' . $js . ') >> >>';
            $this->cpdf->objects[$fieldId]['info']['AA'] = $action;
        }

        // Same 'on change' refresh for MULTI-select controllers: toggling any of
        // their checkboxes re-evaluates dependents. MouseUp (/U) fires after the
        // toggle is committed.
        foreach ($this->checkboxControllers as $parentQid => $optsMap) {
            if (empty($this->hasDependents[$parentQid])) continue;
            $cbJs = 'app.setTimeOut("refreshDependencies();", 10);';
            $action = '<< /U << /Type /Action /S /JavaScript /JS (' . $cbJs . ') >> '
                    . '/Fo << /Type /Action /S /JavaScript /JS (' . $cbJs . ') >> '
                    . '/Bl << /Type /Action /S /JavaScript /JS (' . $cbJs . ') >> >>';
            foreach ($optsMap as $o) {
                $fid = $o['fieldId'] ?? null;
                if (!$fid || !isset($this->cpdf->objects[$fid])) continue;
                $this->cpdf->objects[$fid]['info']['AA'] = $action;
            }
        }
    }

    // ------------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------------

    private function registerWidgets($qid, $type, array $fields) {
        $this->questionWidgets[$qid] = ['type' => $type, 'fields' => $fields];
    }

    /**
     * Mark a widget as Adobe-only: set the annotation Hidden flag (/F bit 2) so
     * it does not render in browser PDF viewers, and register its field name so
     * finalize()'s reveal JavaScript (which only Adobe executes) can show it.
     */
    private function markAdobeOnly($name, $fieldId) {
        if ($fieldId && isset($this->cpdf->objects[$fieldId])) {
            $this->cpdf->objects[$fieldId]['info']['F'] = 2; // Hidden
        }
        if ($name) $this->adobeOnlyFields[] = $name;
    }

    /**
     * Return [yesXObjId, offXObjId] for the shared checkbox appearance streams,
     * building them on first use. Returns [null, null] if the Cpdf build can't
     * create the XObjects (defensive -- the caller then falls back to the
     * NeedAppearances behavior).
     */
    private function checkboxAppearanceIds($w, $h) {
        if ($this->cbAppYes === null || $this->cbAppOff === null) {
            $this->cbAppYes = $this->makeCheckAppearance($w, $h, true);
            $this->cbAppOff = $this->makeCheckAppearance($w, $h, false);
        }
        return [$this->cbAppYes, $this->cbAppOff];
    }

    /**
     * Create a Form XObject appearance stream for a checkbox state. Both states
     * paint a white box with a dark border; the checked state adds a green tick.
     * Coordinates are in the appearance's own bottom-left origin bbox.
     */
    private function makeCheckAppearance($w, $h, $checked) {
        try {
            $id = $this->cpdf->addXObject('Form', 0, 0, $w, $h);
        } catch (\Exception $e) {
            return null;
        }
        if (!$id || !isset($this->cpdf->objects[$id])) {
            return null;
        }
        $c  = "q\n";
        $c .= sprintf("1 1 1 rg 0 0 %.2F %.2F re f\n", $w, $h);
        $c .= sprintf("0.30 0.36 0.44 RG 1 w 0.5 0.5 %.2F %.2F re S\n", $w - 1, $h - 1);
        if ($checked) {
            // A tick from lower-left to a peak on the right.
            $c .= sprintf(
                "0 0.45 0 RG 1.6 w %.2F %.2F m %.2F %.2F l %.2F %.2F l S\n",
                $w * 0.22, $h * 0.52,
                $w * 0.42, $h * 0.28,
                $w * 0.80, $h * 0.78
            );
        }
        $c .= "Q";
        $this->cpdf->objects[$id]['c'] = $c;
        return $id;
    }

    private $nameCounter = 0;
    private function widgetName($base) {
        $this->nameCounter++;
        return $base . '_' . $this->nameCounter;
    }

    private function ensureSpace($needed) {
        $available = ($this->pageH - $this->marginBottom) - $this->currentY;
        if ($needed > $available) {
            $this->newPage();
        }
    }

    private function newPage() {
        $this->canvas->new_page();
        $this->pageNum++;
        $this->drawHeader();
        $this->drawFooter();
        $this->currentY = $this->marginTop + 50;
    }

    /**
     * Add a text AcroForm field at top-left canvas coordinates. Converts to
     * PDF bottom-left coordinate space and ensures a font is active.
     */
    private function addTextField($name, $x, $y, $w, $h, $multiline) {
        $this->cpdf->selectFont($this->font);
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $yTop = $this->pageH - $y;
        $yBot = $this->pageH - ($y + $h);
        $ft = \Dompdf\Cpdf::ACROFORM_FIELD_TEXT;
        $ff = $multiline ? \Dompdf\Cpdf::ACROFORM_FIELD_TEXT_MULTILINE : 0;
        try {
            return $this->cpdf->addFormField($ft, $name, $x, $yBot, $x + $w, $yTop, $ff, 10, [0, 0, 0]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Emits a real Btn checkbox field. Cpdf's `addFormField` does not know
     * about checkbox state, so we drop down to the underlying objects array
     * to set /V /Off, /DV /Off, and /AS /Off as name objects (not strings).
     * Readers with /NeedAppearances = true generate the check mark appearance
     * on toggle.
     */
    private function addCheckboxField($name, $x, $y, $w, $h, $checked = false) {
        $this->cpdf->selectFont($this->font);
        if (!isset($this->cpdf->acroFormId)) {
            $this->cpdf->addForm(0, true);
        }
        $yTop = $this->pageH - $y;
        $yBot = $this->pageH - ($y + $h);
        try {
            $fieldId = $this->cpdf->addFormField(
                \Dompdf\Cpdf::ACROFORM_FIELD_BUTTON,
                $name,
                $x, $yBot, $x + $w, $yTop,
                0,            // Ff: not pushbutton, not radio => checkbox
                10,
                [0, 0, 0]
            );
            if ($fieldId && isset($this->cpdf->objects[$fieldId])) {
                $info = &$this->cpdf->objects[$fieldId]['info'];
                $state = $checked ? '/Yes' : '/Off';
                $info['V']  = $state;
                $info['DV'] = '/Off';
                $info['AS'] = $state;
                // Explicit 1pt border so the empty square is visible before a
                // reader generates the /Yes appearance. When the widget hides
                // (display.hidden) the border hides with it.
                $info['Border'] = '[0 0 1]';
                $info['MK'] = '<< /BC [0.3 0.36 0.44] /BG [1 1 1] >>';
                // Real /AP appearance streams for BOTH states. Adobe honors
                // /NeedAppearances and synthesizes the checkmark, but PDFium
                // (Chrome, Edge, Firefox built-in viewers) does not -- without a
                // baked appearance the box can't be seen or toggled there. o_field
                // emits unknown info keys verbatim, so this writes the nested
                // state dictionary its 'appearance' handler cannot.
                list($yesId, $offId) = $this->checkboxAppearanceIds($w, $h);
                if ($yesId && $offId) {
                    $info['AP'] = '<< /N << /Yes ' . $yesId . ' 0 R /Off ' . $offId . ' 0 R >> >>';
                }
                unset($info);
            }
            return $fieldId;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Wrap text to a maximum pixel width given a font/size.
     */
    private function wrapText($text, $maxWidth, $font, $size) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$text));
        if ($text === '') return [];
        $lines = [];
        $words = explode(' ', $text);
        $current = '';
        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            $width = $this->canvas->get_text_width($test, $font, $size);
            if ($width > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }
        if ($current !== '') $lines[] = $current;
        return $lines;
    }
}
