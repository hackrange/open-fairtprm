<?php
/**
 * Vendor Favicon API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Serves stored vendor favicons from the database. These are small images
 * (typically 32x32 PNG or ICO files) fetched when a vendor is first onboarded.
 * We store them as BLOBs so we don't need to hit external services every time
 * someone loads the SRS vendor list. If no favicon is stored, we return a 1x1
 * transparent PNG so the browser doesn't show a broken image icon.
 *
 * Caching is set to 24 hours because favicons almost never change, and there's
 * no point hammering the database for the same 32x32 icon on every page load.
 */

require_once '../includes/init.php';

requireAuth();

$vendorId = (int)($_GET['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    http_response_code(400);
    die('Missing vendor_id');
}

$db = Database::getInstance();

// SECURITY (IDOR): only users who may view this vendor get its stored favicon.
// Unauthorized callers fall through to the generic placeholder (instead of a 403),
// so the response cannot be used to enumerate which vendor IDs exist / have a logo.
$acl = ACL::getInstance();
$canView = $acl->hasGroup(['administrator', 'cyber_tprm', 'auditor', 'procurement'])
        || Session::getInstance()->get('is_super_admin');
if (!$canView && $acl->hasGroup('stakeholder')) {
    $st = $db->fetchOne(
        'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = ? AND user_id = ?',
        [$vendorId, (int)Auth::getInstance()->getUserId()]
    );
    $canView = !empty($st);
}

// Wrap in try/catch because the favicon columns may not exist yet if the
// migration hasn't been run. In that case, fall through to the placeholder.
try {
    $row = $canView ? $db->fetchOne(
        'SELECT vendor_favicon, vendor_favicon_mime FROM vendor_onboarding_requests WHERE id = ?',
        [$vendorId]
    ) : null;

    if ($row && !empty($row['vendor_favicon'])) {
        $mime = $row['vendor_favicon_mime'] ?: 'image/png';

        // Only serve known image types
        $allowedMimes = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/gif', 'image/jpeg', 'image/svg+xml', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            $mime = 'image/png';
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($row['vendor_favicon']));
        header('Cache-Control: public, max-age=86400');
        echo $row['vendor_favicon'];
        exit;
    }
} catch (Exception $e) {
    // Columns likely don't exist yet -- fall through to placeholder
}

// No favicon stored -- return a 1x1 transparent PNG placeholder
// This prevents broken image icons in the browser
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
// 1x1 transparent PNG (67 bytes)
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg==');
