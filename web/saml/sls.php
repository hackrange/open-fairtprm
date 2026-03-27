<?php
/**
 * SAML Single Logout Service (SLS) Endpoint
 *
 * Handles both:
 * - IdP-initiated logout (SAMLRequest in query string)
 * - SP-initiated logout response (SAMLResponse in query string)
 */

require_once __DIR__ . '/../includes/init.php';

$auth = Auth::getInstance();
$session = Session::getInstance();

try {
    $saml = new SAMLHandler();

    if (!$saml->isEnabled()) {
        redirect('/login.php');
    }

    // IdP-initiated logout: IdP sends a LogoutRequest
    if (!empty($_GET['SAMLRequest'])) {
        $result = $saml->processLogoutRequest($_GET['SAMLRequest']);

        // Log and destroy session
        $userId = $auth->getUserId();
        if ($userId) {
            $auth->audit($userId, 'saml_logout', 'users', $userId, [
                'new' => ['type' => 'idp_initiated', 'name_id' => $result['nameId']]
            ]);
        }

        $auth->logout();

        // Send LogoutResponse back to IdP
        $responseUrl = $saml->getLogoutResponseUrl();
        if ($responseUrl) {
            header('Location: ' . $responseUrl);
            exit;
        }

        redirect('/login.php');
    }

    // SP-initiated logout response: IdP confirms logout
    if (!empty($_GET['SAMLResponse'])) {
        $userId = $auth->getUserId();
        if ($userId) {
            $auth->audit($userId, 'saml_logout', 'users', $userId, [
                'new' => ['type' => 'sp_initiated_response']
            ]);
        }

        $auth->logout();
        redirect('/login.php');
    }

    // No SAMLRequest or SAMLResponse - just do a local logout
    $auth->logout();
    redirect('/login.php');

} catch (Exception $e) {
    error_log('SAML SLS Error: ' . $e->getMessage());
    $auth->logout();
    redirect('/login.php');
}
