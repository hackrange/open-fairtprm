<?php
/**
 * SAML SP-Initiated SSO Entry Point
 *
 * Builds an AuthnRequest and redirects the user to the IdP for authentication.
 * After IdP auth, the user will be redirected back to /saml/acs.
 */

require_once __DIR__ . '/../includes/init.php';

$auth = Auth::getInstance();

// If already authenticated, go to dashboard
if ($auth->isAuthenticated()) {
    redirect('/index.php');
}

try {
    $saml = new SAMLHandler();

    if (!$saml->isEnabled()) {
        redirect('/login.php');
    }

    // Capture return_to for post-login redirect
    $returnTo = $_GET['return_to'] ?? '';
    if (!empty($returnTo) && (!preg_match('#^/[a-zA-Z0-9]#', $returnTo) || preg_match('/[\r\n]/', $returnTo))) {
        $returnTo = '';
    }

    // Build login URL and redirect to IdP
    $loginUrl = $saml->getLoginUrl($returnTo);
    header('Location: ' . $loginUrl);
    exit;

} catch (Exception $e) {
    error_log('SAML Login Error: ' . $e->getMessage());
    $session = Session::getInstance();
    $session->set('saml_error', 'SSO is not available: ' . $e->getMessage());
    redirect('/login.php');
}
