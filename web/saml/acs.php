<?php
/**
 * SAML Assertion Consumer Service (ACS) Endpoint
 *
 * Receives SAML Response POST from the IdP after authentication.
 * Validates the response, extracts user attributes, creates/updates the user,
 * syncs group memberships, and establishes a session.
 */

require_once __DIR__ . '/../includes/init.php';

$auth = Auth::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$security = Security::getInstance();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// SAMLResponse must be present
if (empty($_POST['SAMLResponse'])) {
    http_response_code(400);
    die('Missing SAMLResponse');
}

try {
    $saml = new SAMLHandler();

    if (!$saml->isEnabled()) {
        throw new Exception('SAML SSO is not enabled');
    }

    // Process and validate the SAML response
    $result = $saml->processResponse($_POST['SAMLResponse']);

    $nameId = $result['nameId'];
    $attributes = $result['attributes'];
    $sessionIndex = $result['sessionIndex'];

    // Log raw SAML attributes for debugging
    error_log('SAML attributes received: ' . json_encode(array_keys($attributes)));

    // Map SAML attributes to user fields
    $mappedUser = $saml->mapAttributesToUser($nameId, $attributes);
    $email = strtolower(trim($mappedUser['email']));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address from SAML assertion: ' . $email);
    }

    // Look up existing user by email
    $user = $db->fetchOne(
        'SELECT * FROM users WHERE LOWER(email) = :email',
        [':email' => $email]
    );

    if ($user && !$user['is_active']) {
        throw new Exception('Account is disabled. Contact your administrator.');
    }

    if (!$user) {
        // Auto-provision new user from SAML attributes
        $username = $mappedUser['username'] ?? $email;
        $fullName = $mappedUser['full_name'] ?? $username;

        // Check auto-activate setting
        $samlConfig = $db->fetchOne('SELECT auto_activate FROM saml_config LIMIT 1');
        $autoActivate = ($samlConfig['auto_activate'] ?? 1) ? 1 : 0;

        // Ensure unique username (allow @ for email-based usernames)
        $baseUsername = preg_replace('/[^a-zA-Z0-9._@-]/', '', $username);
        if (empty($baseUsername)) {
            $baseUsername = $email;
        }
        $finalUsername = $baseUsername;
        $counter = 1;
        while ($db->fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $finalUsername])) {
            $finalUsername = $baseUsername . $counter;
            $counter++;
        }

        // Create user with random password (they'll login via SAML)
        $encryption = new Encryption();
        $randomPassword = $encryption->hashPassword(bin2hex(random_bytes(32)));

        $db->insert('users', [
            'username'      => $finalUsername,
            'email'         => $email,
            'full_name'     => $fullName,
            'password_hash' => $randomPassword,
            'is_active'     => $autoActivate,
            'is_admin'      => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // Fetch the newly created user
        $user = $db->fetchOne(
            'SELECT * FROM users WHERE LOWER(email) = :email',
            [':email' => $email]
        );

        if (!$user) {
            throw new Exception('Failed to create user account');
        }

        // Auto-assign to stakeholder group
        $acl = ACL::getInstance();
        $stakeholderGroup = $db->fetchOne(
            "SELECT id FROM acl_groups WHERE group_name = 'stakeholder' AND is_active = 1"
        );
        if ($stakeholderGroup) {
            $acl->assignUserToGroup($user['id'], $stakeholderGroup['id'], null);
        }

        $auth->audit(null, 'saml_user_created', 'users', $user['id'], [
            'new' => ['username' => $finalUsername, 'email' => $email, 'full_name' => $fullName, 'is_active' => $autoActivate, 'default_group' => 'stakeholder']
        ]);

        if (!$autoActivate) {
            throw new Exception('Your account has been created and is pending approval. Please contact your administrator to activate your account.');
        }
    }

    // Establish session via Auth
    $loginResult = $auth->loginViaSaml($user);
    if (!$loginResult['success']) {
        throw new Exception($loginResult['message'] ?? 'Failed to establish session');
    }

    // Store SAML session data for SLO
    $session->set('saml_name_id', $nameId);
    $session->set('saml_session_index', $sessionIndex);
    $session->set('saml_authenticated', true);

    // Sync SAML group memberships
    $samlGroups = $saml->getGroupsFromAttributes($attributes);
    if (!empty($samlGroups)) {
        $acl = ACL::getInstance();
        $syncResult = $acl->syncSamlGroups($user['id'], $samlGroups);

        // Reload ACL groups into session after sync
        $userGroups = $acl->getUserGroups($user['id']);
        $session->set('acl_groups', $userGroups);

        if (!empty($syncResult['added']) || !empty($syncResult['removed'])) {
            $auth->audit($user['id'], 'saml_groups_synced', 'user_acl_groups', $user['id'], [
                'new' => ['added' => $syncResult['added'], 'removed' => $syncResult['removed'], 'saml_groups' => $samlGroups]
            ]);
        }
    }

    // Log the SAML login
    $auth->audit($user['id'], 'saml_login', 'users', $user['id'], [
        'new' => ['name_id' => $nameId, 'idp_session' => $sessionIndex]
    ]);

    // Determine where to send the user
    $relayState = $_POST['RelayState'] ?? '';
    if (!empty($relayState) && preg_match('#^/[a-zA-Z0-9]#', $relayState) && !preg_match('/[\r\n]/', $relayState)) {
        $destination = $relayState;
    } else {
        $destination = '/index.php';
    }

    // Use a client-side redirect instead of Location header.
    // The session cookie is SameSite=Strict, so browsers won't send it on
    // a server-side redirect that's part of a cross-site POST chain (IdP → ACS → app).
    // A client-side redirect starts a new same-site navigation context.
    $safeUrl = htmlspecialchars($destination, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head>';
    echo '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">';
    echo '</head><body><p>Signing in...</p>';
    echo '<script>window.location.replace(' . json_encode($destination) . ');</script>';
    echo '</body></html>';
    exit;

} catch (Exception $e) {
    error_log('SAML ACS Error: ' . $e->getMessage());

    // Use client-side redirect for error case too
    $session->set('saml_error', 'SSO login failed: ' . $e->getMessage());
    echo '<!DOCTYPE html><html><head>';
    echo '<meta http-equiv="refresh" content="0;url=/login.php">';
    echo '</head><body><p>Redirecting...</p>';
    echo '<script>window.location.replace("/login.php");</script>';
    echo '</body></html>';
    exit;
}
