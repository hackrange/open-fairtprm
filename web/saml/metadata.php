<?php
/**
 * SAML SP Metadata Endpoint
 *
 * Generates XML metadata that IdPs can consume to configure the SP.
 * This endpoint is publicly accessible (no auth required) because
 * IdPs need to fetch it during configuration.
 */

require_once __DIR__ . '/../includes/init.php';

try {
    $saml = new SAMLHandler();

    if (!$saml->isEnabled()) {
        http_response_code(404);
        die('SAML SSO is not configured');
    }

    $xml = $saml->generateMetadataXml();

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $xml;

} catch (Exception $e) {
    error_log('SAML Metadata Error: ' . $e->getMessage());
    http_response_code(500);
    die('Error generating SAML metadata');
}
