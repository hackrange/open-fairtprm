<?php
/**
 * API: AI Extract Pricing from Contract Document
 *
 * Called via AJAX from vendor-onboarding.php upload/edit modals.
 * Extracts text from an uploaded or stored contract document,
 * sends to AI to identify pricing & renewal terms,
 * and returns structured JSON for form population.
 *
 * Author: Tim Rice
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF validation — FormData sends as POST field, not JSON body
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || !$security->validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();

// Permission check: admin, procurement, or cyber_tprm
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');
if (!$isAdmin && !$isProcurement && !$isCyberTPRM) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied', 'csrf_token' => $newCsrfToken]);
    exit;
}

// AI Platform Service
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Encryption instance needed for decrypting vendor documents
$encryption = new Encryption();

// ============================================================================
// Determine mode: file upload or existing document_id
// ============================================================================
require_once __DIR__ . '/../includes/classes/DocumentTextExtractor.php';

$rawText = null;
$maxExtract = 60000; // Extract generously, then trim smartly
$maxPromptChars = 24000; // Max chars to send to AI

if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    // Upload mode: extract text from the uploaded file
    $tmpPath = $_FILES['file']['tmp_name'];
    $mimeType = $_FILES['file']['type'];
    $fileData = file_get_contents($tmpPath);

    if (empty($fileData)) {
        echo json_encode(['error' => 'Uploaded file is empty', 'csrf_token' => $newCsrfToken]);
        exit;
    }

    $rawText = DocumentTextExtractor::extract($fileData, $mimeType, $maxExtract);
    unset($fileData);

} elseif (!empty($_POST['document_id'])) {
    // Edit mode: load encrypted document from DB, decrypt, extract text
    $documentId = intval($_POST['document_id']);
    $doc = $db->fetchOne(
        "SELECT encrypted_data, mime_type FROM vendor_documents WHERE id = :id AND is_active = 1",
        [':id' => $documentId]
    );

    if (!$doc || empty($doc['encrypted_data'])) {
        echo json_encode(['error' => 'Document not found', 'csrf_token' => $newCsrfToken]);
        exit;
    }

    $decrypted = $encryption->decryptRaw($doc['encrypted_data']);
    if (empty($decrypted)) {
        echo json_encode(['error' => 'Could not decrypt document', 'csrf_token' => $newCsrfToken]);
        exit;
    }

    $rawText = DocumentTextExtractor::extract($decrypted, $doc['mime_type'], $maxExtract);
    unset($decrypted);

} else {
    echo json_encode(['error' => 'No file or document_id provided', 'csrf_token' => $newCsrfToken]);
    exit;
}

if (empty($rawText)) {
    echo json_encode(['error' => 'Could not extract text from this file type. Supported formats: PDF, CSV, XLS, XLSX.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Smart text selection: prioritize sections with pricing content
// ============================================================================
$documentText = $rawText;
if (mb_strlen($rawText) > $maxPromptChars) {
    // Split into ~500-char chunks and score each by pricing keyword density
    $chunkSize = 500;
    $chunks = [];
    $len = mb_strlen($rawText);
    for ($i = 0; $i < $len; $i += $chunkSize) {
        $chunks[] = ['offset' => $i, 'text' => mb_substr($rawText, $i, $chunkSize)];
    }

    $pricingKeywords = [
        'fee', 'price', 'cost', 'rate', 'charge', 'payment', 'invoice', 'billing',
        'annual', 'monthly', 'quarterly', 'subscription', 'license', 'seat', 'user',
        'unit', 'overage', 'renewal', 'termination', 'notice', 'uplift', 'escalat',
        'increase', 'cap', 'order form', 'schedule', 'pricing', 'total', 'amount',
        '$', 'per month', 'per year', 'per user', 'per seat', 'one-time', 'setup',
        'implementation', 'professional services', 'included', 'transaction',
    ];

    // Score each chunk
    foreach ($chunks as &$chunk) {
        $lower = strtolower($chunk['text']);
        $score = 0;
        foreach ($pricingKeywords as $kw) {
            $score += substr_count($lower, $kw);
        }
        // Also boost chunks containing dollar amounts (e.g. $1,234 or 1234.00)
        $score += preg_match_all('/\$[\d,]+\.?\d*|\d{1,3}(?:,\d{3})+(?:\.\d{2})?/', $chunk['text']);
        $chunk['score'] = $score;
    }
    unset($chunk);

    // Always include first 2000 chars (document header/parties) and last 4000 chars (appendices/order forms)
    $headerText = mb_substr($rawText, 0, 2000);
    $tailText = mb_substr($rawText, max(0, $len - 4000));

    // Collect high-scoring chunks from the middle, sorted by score desc
    $middleChunks = array_filter($chunks, function($c) use ($len) {
        return $c['offset'] >= 2000 && $c['offset'] < ($len - 4000);
    });
    usort($middleChunks, function($a, $b) { return $b['score'] - $a['score']; });

    // Build text from high-value chunks until we hit the limit
    $remaining = $maxPromptChars - mb_strlen($headerText) - mb_strlen($tailText);
    $selectedMiddle = [];
    foreach ($middleChunks as $c) {
        if ($c['score'] < 1) continue; // Skip chunks with zero pricing relevance
        if ($remaining <= 0) break;
        $selectedMiddle[] = $c;
        $remaining -= mb_strlen($c['text']);
    }

    // Re-sort selected middle chunks by offset to maintain document order
    usort($selectedMiddle, function($a, $b) { return $a['offset'] - $b['offset']; });

    $middleText = implode("\n[...]\n", array_map(function($c) { return $c['text']; }, $selectedMiddle));

    $documentText = $headerText . "\n[...]\n" . $middleText . "\n[...]\n" . $tailText;
}
$documentText = mb_substr($documentText, 0, $maxPromptChars);

// ============================================================================
// Build AI prompt
// ============================================================================
$prompt = "You are a contract analyst. Read this vendor contract/order form carefully and extract every commercial and pricing term you can find.

DOCUMENT TEXT:
{$documentText}

FIELDS TO EXTRACT (with descriptions):
- contractId: The contract number, order number, PO number, quote number, or any business reference ID (e.g. \"ORD-2026-001\", \"Q-12345\", \"PO-789\"). Do NOT use DocuSign envelope IDs, Adobe Sign IDs, e-signature tracking numbers, or other system-generated UUIDs.
- systemOfRecordLink: Any URL or link to a portal, system of record, or account management page
- renewalType: How the contract renews. Must be exactly one of: \"Auto-Renew\", \"Manual\", \"Evergreen\". Look for terms like \"auto-renewal\", \"automatically renews\", \"evergreen\", \"manual renewal required\"
- billingFrequency: How often billing occurs. Must be exactly one of: \"Monthly\", \"Quarterly\", \"Annually\". Look for terms like \"billed monthly\", \"annual billing\", \"per month\", \"per year\", \"/mo\", \"/yr\"
- renewalNoticeWindow: How much notice is required before renewal (e.g. \"90 days\", \"60 days prior to renewal\")
- terminationRights: Termination or cancellation terms (e.g. \"30-day written notice\", \"terminate for cause with 60 days notice\")
- annualFee: Annual platform fee, subscription fee, or base fee. Plain number only, no currency symbols (e.g. 12000 not \$12,000). If pricing is monthly, multiply by 12 for annual.
- unitPrice: Per-unit, per-seat, or per-user price. Plain number only.
- includedUnits: Number of included seats, users, licenses, API calls, or units. Plain number only.
- overagePrice: Price per additional unit beyond included amount. Plain number only.
- oneTimeFees: Any one-time, setup, implementation, onboarding, or professional services fees. Plain number only.
- priceIncreaseCap: Maximum annual price increase as a percentage number (e.g. 5 for 5%, 3 for 3%)
- renewalUplift: Is there a renewal uplift/escalation clause? Must be exactly \"Y\" or \"N\"

RULES:
- Extract ONLY from the document text above. Never fabricate values.
- Include a field ONLY if the document contains clear evidence for it. Skip fields with no evidence.
- For numeric fields: return plain numbers without currency symbols, commas, or units (e.g. 15000 not \$15,000.00)
- If a price is given as monthly but the field asks for annual, multiply by 12
- Look thoroughly — pricing info may appear in tables, line items, schedules, or appendices
- Be aggressive about extracting: if a value is present in any form, extract it

Return ONLY a valid JSON object with the fields you found (no markdown fences, no explanation, no extra text):
{\"contractId\":\"value\",\"annualFee\":\"value\"}";

// ============================================================================
// Send to AI
// ============================================================================
$messages = [
    ['role' => 'user', 'content' => $prompt]
];

$result = $ai->chatCompletion($messages, [
    'purpose' => 'fair',
    'temperature' => 0.3,
    'max_tokens' => max($ai->getMaxTokens(), 2000),
]);

if (!$result['success']) {
    error_log("Contract pricing AI extract error: " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$content = $result['content'];

// ============================================================================
// Parse AI response
// ============================================================================

// Strip markdown fences if present
$content = preg_replace('/```json\s*/i', '', $content);
$content = preg_replace('/```\s*$/s', '', $content);
$content = preg_replace('/```/', '', $content);
$content = trim($content);

// Find the JSON object
$firstBrace = strpos($content, '{');
$lastBrace = strrpos($content, '}');
if ($firstBrace === false || $lastBrace === false) {
    error_log("Contract pricing AI extract error: No JSON object found in AI response");
    echo json_encode(['error' => 'AI returned an invalid response. Please try again.', 'csrf_token' => $newCsrfToken]);
    exit;
}

$jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
$parsed = json_decode($jsonStr, true);

if (!$parsed || !is_array($parsed)) {
    error_log("Contract pricing AI extract error: Invalid JSON structure");
    echo json_encode(['error' => 'AI returned an invalid response. Please try again.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Validate and sanitize fields
$validFields = [
    'contractId', 'systemOfRecordLink', 'renewalType', 'billingFrequency',
    'renewalNoticeWindow', 'terminationRights', 'annualFee', 'unitPrice',
    'includedUnits', 'overagePrice', 'oneTimeFees', 'priceIncreaseCap', 'renewalUplift'
];

$validRenewalTypes = ['Auto-Renew', 'Manual', 'Evergreen'];
$validBillingFrequencies = ['Monthly', 'Quarterly', 'Annually'];
$validRenewalUplift = ['Y', 'N'];

$numericFields = ['annualFee', 'unitPrice', 'includedUnits', 'overagePrice', 'oneTimeFees', 'priceIncreaseCap'];

$pricing = [];
foreach ($validFields as $field) {
    if (!isset($parsed[$field]) || $parsed[$field] === '' || $parsed[$field] === null) {
        continue;
    }

    $val = $parsed[$field];

    // Validate enum fields
    if ($field === 'renewalType' && !in_array($val, $validRenewalTypes, true)) continue;
    if ($field === 'billingFrequency' && !in_array($val, $validBillingFrequencies, true)) continue;
    if ($field === 'renewalUplift' && !in_array($val, $validRenewalUplift, true)) continue;

    // Clean numeric fields
    if (in_array($field, $numericFields)) {
        // Strip currency symbols, commas, whitespace
        $val = preg_replace('/[^0-9.\-]/', '', (string)$val);
        if ($val === '' || !is_numeric($val)) continue;
    }

    // Sanitize string values
    if (is_string($val)) {
        $val = trim($val);
        if ($val === '' || strtolower($val) === 'n/a' || strtolower($val) === 'not specified') continue;
    }

    $pricing[$field] = (string)$val;
}

echo json_encode([
    'success' => true,
    'pricing' => $pricing,
    'filled' => count($pricing),
    'csrf_token' => $newCsrfToken,
]);
