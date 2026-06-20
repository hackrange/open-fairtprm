<?php
/**
 * VAT validation API — live EU VIES lookup.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Validates an EU VAT number against the official European Commission VIES
 * service (https://ec.europa.eu/taxation_customs/vies/), which queries each
 * member state's live registry, is free, and needs no API key.
 *
 * Like assessment-autosave.php, this endpoint is reachable by external vendors
 * filling out an assessment via their UUID token, so it does NOT require a login
 * session. It is purely ADVISORY — it never writes anything and never blocks a
 * save; the caller stores the VAT number regardless and merely surfaces the
 * outcome. To avoid being abused as an open lookup oracle, it:
 *   - only ever contacts the single, hard-coded VIES host (no SSRF surface),
 *   - rejects anything that is not a plausibly-formatted EU VAT number, and
 *   - applies a light per-session rate limit.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../includes/init.php';

// Read-only lookup -> GET. No CSRF needed for a side-effect-free request.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['reachable' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// LIGHT RATE LIMIT (per session, sliding 60s window). Cheap guard
// against someone hammering the EU service through us.
// ---------------------------------------------------------------
$now = time();
$bucket = $_SESSION['vat_vies_calls'] ?? [];
$bucket = array_values(array_filter($bucket, function ($ts) use ($now) { return $ts > $now - 60; }));
if (count($bucket) >= 30) {
    http_response_code(429);
    echo json_encode(['reachable' => false, 'message' => 'Too many requests. Please wait a moment.']);
    exit;
}
$bucket[] = $now;
$_SESSION['vat_vies_calls'] = $bucket;

// ---------------------------------------------------------------
// VALIDATE INPUT FORMAT before touching the network.
// ---------------------------------------------------------------
$raw = $_GET['vat'] ?? '';
if (!is_string($raw) || strlen($raw) > 32) {
    echo json_encode(['reachable' => true, 'valid' => false, 'message' => 'Invalid VAT number']);
    exit;
}

$fmt = vat_validate_format($raw);
if (!$fmt['ok']) {
    // Format is wrong -> we can answer definitively without calling VIES.
    echo json_encode([
        'reachable' => true,
        'valid'     => false,
        'name'      => '',
        'message'   => $fmt['message'],
    ]);
    exit;
}

// ---------------------------------------------------------------
// LIVE VIES CHECK (advisory; fail-open on any outage).
// ---------------------------------------------------------------
$result = vat_vies_check($raw);

if (empty($result['reachable'])) {
    echo json_encode(['reachable' => false]);
    exit;
}

// VIES answered but the member state's registry was temporarily unavailable: we
// cannot confirm validity right now. Report it distinctly so the UI can say
// "temporarily unavailable" rather than "not valid".
if (!empty($result['unavailable']) || (array_key_exists('valid', $result) && $result['valid'] === null)) {
    echo json_encode([
        'reachable'   => true,
        'valid'       => null,
        'unavailable' => true,
        'detail'      => $result['detail'] ?? '',
    ]);
    exit;
}

echo json_encode([
    'reachable'   => true,
    'valid'       => !empty($result['valid']),
    'name'        => $result['name'] ?? '',
    'address'     => $result['address'] ?? '',
    'requestDate' => $result['requestDate'] ?? '',
]);
