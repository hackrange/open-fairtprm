<?php
/**
 * Authentication Manager - The Bouncer of This Application
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is where all the "who are you and prove it" logic lives. Login, logout,
 * password checks, TOTP two-factor auth, account lockouts for the hammering bots,
 * session bootstrapping, and even admin impersonation (for when you need to see
 * things through someone else's eyes without stealing their lunch). If your user
 * can't get in, this class is probably the reason -- and that's a good thing.
 * Think of it as the velvet rope at the nightclub, except it actually works.
 */

class Auth {
    // Singleton instance -- there can be only one bouncer
    private static $instance = null;

    // All the tools we need to do our job: database, session, crypto, security, config
    private $db;
    private $session;
    private $encryption;
    private $security;
    private $config;

    /**
     * Private constructor because we're a singleton and don't want anyone
     * running around creating multiple Auth instances like it's a free buffet.
     * Grabs references to basically every other core class because authentication
     * touches EVERYTHING.
     */
    private function __construct() {
        $this->db = Database::getInstance();
        $this->session = Session::getInstance();
        $this->encryption = new Encryption();
        $this->security = Security::getInstance();
        $this->config = Config::getInstance();
    }

    /**
     * The classic singleton getInstance pattern.
     * First call builds it, every other call just returns the same instance.
     * Like a loyal dog -- always the same one waiting for you at the door.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Authenticate user with username and password.
     *
     * This is the big one -- the main login flow. Here's the play-by-play:
     * 1. Look up the user (bail if they don't exist, but don't tell the attacker which part was wrong)
     * 2. Check if the account is locked (too many failed attempts = timeout corner)
     * 3. Verify the password hash (Argon2id or bcrypt, we're not savages)
     * 4. Opportunistically rehash if the password hash algorithm got upgraded
     * 5. Reset failed login counters on success (forgiveness is a virtue)
     * 6. If TOTP is enabled, pump the brakes and ask for the second factor
     * 7. Otherwise, roll out the red carpet and establish the session
     *
     * @param string $username Username
     * @param string $password Password
     * @return array ['success' => bool, 'message' => string, 'requires_totp' => bool, 'user_id' => int]
     */
    public function login($username, $password) {
        try {
            // Step 1: Find the user in the database. Active users only -- no zombies.
            $user = $this->db->fetchOne(
                'SELECT * FROM users WHERE username = :username AND is_active = 1',
                [':username' => $username]
            );

            // No user found? Log it and give a vague error so attackers can't enumerate usernames
            if (!$user) {
                $this->logAudit(null, 'login_failed', 'users', null, [
                    'username' => $username,
                    'reason' => 'user_not_found'
                ]);
                return ['success' => false, 'message' => 'Invalid username or password'];
            }

            // Step 2: Is this account in the penalty box? Check lockout timer.
            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                $lockoutRemaining = strtotime($user['account_locked_until']) - time();
                $minutes = ceil($lockoutRemaining / 60);
                return [
                    'success' => false,
                    'message' => "Account is locked. Please try again in {$minutes} minute(s)."
                ];
            }

            // Step 3: Does the password actually match? This uses constant-time comparison
            // under the hood so timing attacks can go pound sand.
            if (!$this->encryption->verifyPassword($password, $user['password_hash'])) {
                $this->handleFailedLogin($user['id']);
                $this->logAudit($user['id'], 'login_failed', 'users', $user['id'], [
                    'username' => $username,
                    'reason' => 'invalid_password'
                ]);
                return ['success' => false, 'message' => 'Invalid username or password'];
            }

            // Step 4: While we have the plaintext password handy, check if the hash
            // needs upgrading. Maybe we bumped Argon2 params or switched from bcrypt.
            // Opportunistic rehashing is the polite thing to do.
            if ($this->encryption->needsRehash($user['password_hash'])) {
                $newHash = $this->encryption->hashPassword($password);
                $this->db->update('users', ['password_hash' => $newHash], 'id = :id', [':id' => $user['id']]);
            }

            // Step 5: They got the password right, so wipe the failed attempt slate clean
            $this->db->update('users', [
                'failed_login_attempts' => 0,
                'account_locked_until' => null
            ], 'id = :id', [':id' => $user['id']]);

            // Step 6: TOTP check -- if 2FA is enabled, we need that second factor before
            // we let them fully in. Stash the user ID so the TOTP verification step knows
            // who we're dealing with.
            if ($user['totp_enabled']) {
                $this->session->set('pending_totp_user_id', $user['id']);
                return [
                    'success' => false,
                    'message' => 'TOTP verification required',
                    'requires_totp' => true,
                    'user_id' => $user['id']
                ];
            }

            // Step 7: No 2FA? Great, let them in. Build the session and party on.
            $this->establishSession($user);

            $this->logAudit($user['id'], 'login_success', 'users', $user['id'], [
                'username' => $username
            ]);

            return [
                'success' => true,
                'message' => 'Login successful',
                'user_id' => $user['id']
            ];

        } catch (Exception $e) {
            // Something went sideways. Log it, but don't leak details to the user.
            error_log('Login error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during login'];
        }
    }

    /**
     * Verify TOTP code -- the second half of the 2FA handshake.
     *
     * User already passed the password check and now needs to prove they have
     * their authenticator app handy. We decrypt their stored TOTP secret,
     * check the code, and if it's legit, finish the login process.
     *
     * @param int $userId User ID
     * @param string $code TOTP code (those 6 magical digits)
     * @return array ['success' => bool, 'message' => string]
     */
    public function verifyTOTP($userId, $code) {
        try {
            // Make sure the user exists and is active -- no ghost logins
            $user = $this->db->fetchOne(
                'SELECT * FROM users WHERE id = :id AND is_active = 1',
                [':id' => $userId]
            );

            if (!$user || !$user['totp_enabled']) {
                return ['success' => false, 'message' => 'TOTP not enabled for this user'];
            }

            // Step 1: Is this account in the penalty box? The TOTP step gets the
            // same lockout treatment as the password step. Without this, someone
            // who already has the password gets unlimited guesses at six digits,
            // which is a few hundred thousand requests -- not a real second factor.
            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                $lockoutRemaining = strtotime($user['account_locked_until']) - time();
                $minutes = ceil($lockoutRemaining / 60);
                return [
                    'success' => false,
                    'message' => "Account is locked. Please try again in {$minutes} minute(s)."
                ];
            }

            // Decrypt the stored TOTP secret and verify the code.
            // The secret is encrypted at rest because we're not animals.
            $secret = $this->encryption->decrypt($user['totp_secret']);
            $totp = new TOTP();

            if (!$totp->verifyCode($secret, $code)) {
                // Count this against the same failed-attempt budget as a bad
                // password, so max_login_attempts / lockout_duration apply here too.
                $this->handleFailedLogin($userId);
                $this->logAudit($userId, 'totp_verification_failed', 'users', $userId);
                return ['success' => false, 'message' => 'Invalid TOTP code'];
            }

            // Code checks out! Wipe the failed attempt slate, clean up the
            // pending state, and let them in.
            $this->db->update('users', [
                'failed_login_attempts' => 0,
                'account_locked_until' => null
            ], 'id = :id', [':id' => $user['id']]);

            $this->session->remove('pending_totp_user_id');
            $this->establishSession($user);

            $this->logAudit($userId, 'totp_verification_success', 'users', $userId);

            return ['success' => true, 'message' => 'TOTP verification successful'];

        } catch (Exception $e) {
            error_log('TOTP verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during TOTP verification'];
        }
    }

    /**
     * Enable TOTP for a user -- the "I want to be extra secure" button.
     *
     * Generates a fresh secret, encrypts it, stores it in the DB, and
     * hands back a QR code URL so the user can scan it with their
     * authenticator app. Note: TOTP isn't actually "on" yet -- they still
     * need to confirm with a valid code (see confirmTOTP).
     *
     * @param int $userId User ID
     * @return array ['success' => bool, 'secret' => string, 'qr_url' => string]
     */
    public function enableTOTP($userId) {
        try {
            $user = $this->db->fetchOne(
                'SELECT * FROM users WHERE id = :id',
                [':id' => $userId]
            );

            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            // Generate a shiny new secret and encrypt it before storage
            $totp = new TOTP();
            $secret = $totp->generateSecret();
            $encryptedSecret = $this->encryption->encrypt($secret);

            // Stash the encrypted secret -- TOTP isn't enabled yet, just staged
            $this->db->update('users', [
                'totp_secret' => $encryptedSecret
            ], 'id = :id', [':id' => $userId]);

            // Build the QR code URL and provisioning URI for authenticator apps
            $qrUrl = $totp->getQRCodeUrl($secret, $user['username']);
            $provisioningUri = $totp->getProvisioningUri($secret, $user['username']);

            return [
                'success' => true,
                'secret' => $secret,
                'qr_url' => $qrUrl,
                'provisioning_uri' => $provisioningUri
            ];

        } catch (Exception $e) {
            error_log('Enable TOTP error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to enable TOTP'];
        }
    }

    /**
     * Confirm TOTP setup -- prove you actually scanned the QR code.
     *
     * The user enters a code from their authenticator app to prove they've
     * set it up correctly. If valid, we flip the totp_enabled flag to 1.
     * This two-step dance prevents users from locking themselves out by
     * enabling 2FA without actually having the app configured.
     *
     * @param int $userId User ID
     * @param string $code TOTP code from authenticator app
     * @return array ['success' => bool, 'message' => string]
     */
    public function confirmTOTP($userId, $code) {
        try {
            $user = $this->db->fetchOne(
                'SELECT * FROM users WHERE id = :id',
                [':id' => $userId]
            );

            // Can't confirm something that was never started
            if (!$user || !$user['totp_secret']) {
                return ['success' => false, 'message' => 'TOTP setup not initiated'];
            }

            // Decrypt the secret and see if the code matches
            $secret = $this->encryption->decrypt($user['totp_secret']);
            $totp = new TOTP();

            if (!$totp->verifyCode($secret, $code)) {
                return ['success' => false, 'message' => 'Invalid TOTP code'];
            }

            // Boom! TOTP is now officially enabled. Welcome to the 2FA club.
            $this->db->update('users', [
                'totp_enabled' => 1
            ], 'id = :id', [':id' => $userId]);

            $this->logAudit($userId, 'totp_enabled', 'users', $userId);

            return ['success' => true, 'message' => 'TOTP enabled successfully'];

        } catch (Exception $e) {
            error_log('Confirm TOTP error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to confirm TOTP'];
        }
    }

    /**
     * Disable TOTP for a user -- the "I lost my phone" escape hatch.
     *
     * Nukes the stored secret and flips the flag off. Simple and irreversible.
     * They'll need to go through enableTOTP + confirmTOTP again to re-enable.
     *
     * @param int $userId User ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function disableTOTP($userId) {
        try {
            $this->db->update('users', [
                'totp_enabled' => 0,
                'totp_secret' => null
            ], 'id = :id', [':id' => $userId]);

            $this->logAudit($userId, 'totp_disabled', 'users', $userId);

            return ['success' => true, 'message' => 'TOTP disabled successfully'];

        } catch (Exception $e) {
            error_log('Disable TOTP error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to disable TOTP'];
        }
    }

    /**
     * Establish user session -- the "welcome aboard" routine.
     *
     * Once authentication is fully complete (password + optional TOTP), this method
     * builds out the entire session with user data. Regenerates the session ID first
     * to prevent session fixation attacks (security 101, folks). Then loads up all the
     * user's info, ACL groups, timestamps, and persists the session to the database
     * for good measure. It's like checking into a hotel -- you get your key card,
     * your room preferences are loaded, and the front desk knows you're here.
     *
     * @param array $user User data from the database
     */
    private function establishSession($user) {
        // Regenerate session ID to prevent session fixation. This is non-negotiable.
        $this->session->regenerateId();

        // Load up the session with all the user goodies
        $this->session->set('user_id', $user['id']);
        $this->session->set('username', $user['username']);
        $this->session->set('email', $user['email']);
        $this->session->set('full_name', $user['full_name']);
        $this->session->set('is_admin', $user['is_admin']);
        $this->session->set('is_super_admin', $user['is_super_admin'] ?? 0);
        $this->session->set('authenticated', true);
        $this->session->set('login_time', time());

        // Pull in ACL group memberships so we know what this user can do.
        // If ACL loading fails, we give them an empty set (better safe than sorry).
        try {
            $acl = ACL::getInstance();
            $userGroups = $acl->getUserGroups($user['id']);
            $this->session->set('acl_groups', $userGroups);
        } catch (Exception $e) {
            error_log('Failed to load ACL groups for user ' . $user['id'] . ': ' . $e->getMessage());
            $this->session->set('acl_groups', []);
        }

        // Update the last login timestamp -- nice for audit trails and "last seen" features
        $this->db->update('users', [
            'last_login' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $user['id']]);

        // Persist the session to the database so we can track active sessions
        $this->session->saveToDatabase($user['id']);
    }

    /**
     * Handle a failed login attempt -- the "three strikes and you're out" logic.
     *
     * Increments the failed attempt counter and checks if we've hit the threshold.
     * If so, locks the account for a configurable duration (default 30 minutes).
     * This is our brute-force protection. Not the fanciest, but it works.
     * Bots hate this one weird trick.
     *
     * @param int $userId User ID of the account being hammered
     */
    private function handleFailedLogin($userId) {
        // Grab current fail count
        $user = $this->db->fetchOne(
            'SELECT failed_login_attempts FROM users WHERE id = :id',
            [':id' => $userId]
        );

        $attempts = ($user['failed_login_attempts'] ?? 0) + 1;
        $maxAttempts = $this->config->get('auth.max_login_attempts', 5);

        $updateData = [
            'failed_login_attempts' => $attempts,
            'last_failed_login' => date('Y-m-d H:i:s')
        ];

        // If they've hit the limit, slam the door shut for a while
        if ($attempts >= $maxAttempts) {
            $lockoutDuration = $this->config->get('auth.lockout_duration', 1800);
            $updateData['account_locked_until'] = date('Y-m-d H:i:s', time() + $lockoutDuration);
            $this->logAudit($userId, 'account_locked', 'users', $userId, [
                'new' => ['attempts' => $attempts, 'locked_until' => $updateData['account_locked_until']]
            ]);
        }

        $this->db->update('users', $updateData, 'id = :id', [':id' => $userId]);
    }

    /**
     * Quick check: is the current user authenticated?
     * Just peeks at the session flag. Nothing fancy.
     *
     * @return bool True if the user is logged in
     */
    public function isAuthenticated() {
        return $this->session->get('authenticated', false) === true;
    }

    /**
     * Quick check: is the current user an admin?
     * Uses loose comparison (==) because the DB might store it as string "1".
     *
     * @return bool True if admin
     */
    public function isAdmin() {
        return $this->session->get('is_admin', false) == 1;
    }

    /**
     * Get the current user's ID from the session.
     * Returns null if nobody's logged in. Pretty self-explanatory.
     *
     * @return int|null User ID or null
     */
    public function getUserId() {
        return $this->session->get('user_id');
    }

    /**
     * Get a summary of the current user's data from the session.
     * Handy for "who am I" type displays. Returns null if not authenticated
     * because we don't serve strangers.
     *
     * @return array|null User data or null
     */
    public function getUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return [
            'id' => $this->session->get('user_id'),
            'username' => $this->session->get('username'),
            'email' => $this->session->get('email'),
            'full_name' => $this->session->get('full_name'),
            'is_admin' => $this->session->get('is_admin')
        ];
    }

    /**
     * Logout -- the "I'm done here" button.
     * Logs the event for audit purposes, then nukes the session from orbit.
     * It's the only way to be sure.
     */
    public function logout() {
        $userId = $this->getUserId();
        if ($userId) {
            $this->logAudit($userId, 'logout', 'users', $userId);
        }

        $this->session->destroy();
    }

    /**
     * Impersonate another user -- admin superpower for debugging and support.
     *
     * This lets an admin "become" another user to see exactly what they see.
     * We stash the original admin session so we can restore it later (see
     * stopImpersonation). There are guardrails here:
     * - Only admins/super admins can do this
     * - Can't impersonate yourself (that would be weird)
     * - Can't nest impersonations (Inception rules don't apply here)
     * - Regular admins can't impersonate super admins (hierarchy matters)
     *
     * @param int $targetUserId User ID to impersonate
     * @return array ['success' => bool, 'message' => string]
     */
    public function impersonate($targetUserId) {
        try {
            // Check if current user has the juice to do this
            $currentUserId = $this->getUserId();
            $isSuperAdmin = $this->session->get('is_super_admin');
            $isAdmin = $this->session->get('is_admin');

            if (!$isSuperAdmin && !$isAdmin) {
                return ['success' => false, 'message' => 'Only administrators can impersonate users'];
            }

            // No impersonating yourself, weirdo
            if ($currentUserId == $targetUserId) {
                return ['success' => false, 'message' => 'Cannot impersonate yourself'];
            }

            // No impersonation-ception -- one level deep max
            if ($this->isImpersonating()) {
                return ['success' => false, 'message' => 'Already impersonating another user. Stop current impersonation first.'];
            }

            // Look up the target user -- they need to exist and be active
            $targetUser = $this->db->fetchOne(
                'SELECT * FROM users WHERE id = :id AND is_active = 1',
                [':id' => $targetUserId]
            );

            if (!$targetUser) {
                return ['success' => false, 'message' => 'User not found or inactive'];
            }

            // Hierarchy check: regular admins can't impersonate super admins
            if (!$isSuperAdmin && $targetUser['is_super_admin']) {
                return ['success' => false, 'message' => 'Only super administrators can impersonate other super administrators'];
            }

            // Stash the current admin's session data so we can put Humpty Dumpty
            // back together again when they're done playing dress-up
            $originalSession = [
                'user_id' => $this->session->get('user_id'),
                'username' => $this->session->get('username'),
                'email' => $this->session->get('email'),
                'full_name' => $this->session->get('full_name'),
                'is_admin' => $this->session->get('is_admin'),
                'is_super_admin' => $this->session->get('is_super_admin'),
                'acl_groups' => $this->session->get('acl_groups'),
                'login_time' => $this->session->get('login_time')
            ];
            $this->session->set('impersonation_original', $originalSession);
            $this->session->set('impersonation_started', time());

            // Leave a trail in the audit log -- this is important for accountability
            $this->logAudit($currentUserId, 'impersonation_start', 'users', $targetUserId, [
                'admin_user_id' => $currentUserId,
                'target_user_id' => $targetUserId,
                'target_username' => $targetUser['username']
            ]);

            // Now swap the session over to the target user
            $this->session->set('user_id', $targetUser['id']);
            $this->session->set('username', $targetUser['username']);
            $this->session->set('email', $targetUser['email']);
            $this->session->set('full_name', $targetUser['full_name']);
            $this->session->set('is_admin', $targetUser['is_admin']);
            $this->session->set('is_super_admin', $targetUser['is_super_admin'] ?? 0);

            // Load ACL groups for the impersonated user so permissions are accurate
            try {
                $acl = ACL::getInstance();
                $userGroups = $acl->getUserGroups($targetUser['id']);
                $this->session->set('acl_groups', $userGroups);
            } catch (Exception $e) {
                error_log('Failed to load ACL groups for impersonated user ' . $targetUser['id'] . ': ' . $e->getMessage());
                $this->session->set('acl_groups', []);
            }

            return [
                'success' => true,
                'message' => 'Now impersonating ' . $targetUser['full_name']
            ];

        } catch (Exception $e) {
            error_log('Impersonation error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during impersonation'];
        }
    }

    /**
     * Stop impersonating and restore the original admin session.
     *
     * Pulls the stashed admin session data back out and replaces the
     * impersonated user's session with it. Like waking up from a dream.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function stopImpersonation() {
        try {
            if (!$this->isImpersonating()) {
                return ['success' => false, 'message' => 'Not currently impersonating anyone'];
            }

            $originalSession = $this->session->get('impersonation_original');
            $impersonatedUserId = $this->session->get('user_id');

            // Log the end of impersonation for audit trail
            $this->logAudit($originalSession['user_id'], 'impersonation_end', 'users', $impersonatedUserId, [
                'admin_user_id' => $originalSession['user_id'],
                'impersonated_user_id' => $impersonatedUserId
            ]);

            // Put all the original admin session values back where they belong
            $this->session->set('user_id', $originalSession['user_id']);
            $this->session->set('username', $originalSession['username']);
            $this->session->set('email', $originalSession['email']);
            $this->session->set('full_name', $originalSession['full_name']);
            $this->session->set('is_admin', $originalSession['is_admin']);
            $this->session->set('is_super_admin', $originalSession['is_super_admin']);
            $this->session->set('acl_groups', $originalSession['acl_groups']);
            $this->session->set('login_time', $originalSession['login_time']);

            // Clean up the impersonation breadcrumbs
            $this->session->remove('impersonation_original');
            $this->session->remove('impersonation_started');

            return [
                'success' => true,
                'message' => 'Impersonation ended. Restored to ' . $originalSession['full_name']
            ];

        } catch (Exception $e) {
            error_log('Stop impersonation error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while stopping impersonation'];
        }
    }

    /**
     * Are we currently wearing someone else's hat?
     * Checks if there's stashed original session data (the telltale sign of impersonation).
     * Also enforces a 30-minute maximum impersonation window -- if the timer's up,
     * auto-reverts to the original admin session.
     *
     * @return bool True if currently impersonating another user
     */
    public function isImpersonating() {
        if ($this->session->get('impersonation_original') === null) {
            return false;
        }

        // Enforce 30-minute max impersonation duration
        $started = $this->session->get('impersonation_started');
        if ($started && (time() - $started) > 1800) {
            $this->stopImpersonation();
            return false;
        }

        return true;
    }

    /**
     * Get the real admin's user ID while impersonating.
     * Returns null if we're not in impersonation mode.
     * Useful for "who's really driving this bus" checks.
     *
     * @return int|null Original user ID or null if not impersonating
     */
    public function getOriginalUserId() {
        $original = $this->session->get('impersonation_original');
        return $original ? $original['user_id'] : null;
    }

    /**
     * Get the real admin's username while impersonating.
     * Same deal as getOriginalUserId but for the username.
     *
     * @return string|null Original username or null if not impersonating
     */
    public function getOriginalUsername() {
        $original = $this->session->get('impersonation_original');
        return $original ? $original['username'] : null;
    }

    /**
     * Get full impersonation details -- who's pretending to be whom.
     * Returns null if not impersonating. Handy for displaying that
     * "you are impersonating X" banner in the UI so admins don't
     * accidentally wreck someone else's data.
     *
     * @return array|null Impersonation details or null if not impersonating
     */
    public function getImpersonationInfo() {
        if (!$this->isImpersonating()) {
            return null;
        }

        $original = $this->session->get('impersonation_original');
        return [
            'original_user_id' => $original['user_id'],
            'original_username' => $original['username'],
            'original_full_name' => $original['full_name'],
            'impersonating_user_id' => $this->session->get('user_id'),
            'impersonating_username' => $this->session->get('username'),
            'impersonating_full_name' => $this->session->get('full_name'),
            'started_at' => $this->session->get('impersonation_started')
        ];
    }

    /**
     * Log an audit event -- the private version.
     *
     * Writes a record to the audit_log table with who did what, when, and from
     * where (IP + user agent). This is the kind of stuff that saves your bacon
     * during a security incident investigation. If logging fails, we just
     * error_log it because we don't want audit failures to break the app.
     *
     * @param int|null $userId User ID (null for anonymous actions like failed logins)
     * @param string $action Action performed
     * @param string $tableName Table name being affected
     * @param int|null $recordId Record ID being affected
     * @param array $data Additional context data
     */
    private function logAudit($userId, $action, $tableName, $recordId = null, $data = []) {
        try {
            $this->db->insert('audit_log', [
                'user_id' => $userId,
                'action' => $action,
                'table_name' => $tableName,
                'record_id' => $recordId,
                'old_values' => !empty($data['old']) ? json_encode($data['old']) : null,
                'new_values' => !empty($data['new']) ? json_encode($data['new']) : (!empty($data) ? json_encode($data) : null),
                'ip_address' => $this->security->getClientIP(),
                'user_agent' => $this->security->getUserAgent()
            ]);

            // Probabilistic log retention purge (~1% of writes)
            if (random_int(1, 100) === 1) {
                $retentionRow = $this->db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'log_retention_days'");
                $retentionDays = intval($retentionRow['config_value'] ?? 90);
                if ($retentionDays > 0) {
                    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
                    $this->db->query("DELETE FROM audit_log WHERE created_at < ?", [$cutoffDate]);
                }
            }
        } catch (Exception $e) {
            // Don't let audit logging failures break the actual operation
            error_log('Failed to log audit: ' . $e->getMessage());
        }
    }

    /**
     * Login via SAML -- establish session for a SAML-authenticated user.
     *
     * Called by the SAML ACS endpoint after the IdP has authenticated the user
     * and the SAML response has been validated. Skips password verification
     * (the IdP already handled that) and goes straight to session establishment.
     *
     * @param array $user User data from the database
     * @return array ['success' => bool, 'message' => string]
     */
    public function loginViaSaml($user) {
        try {
            if (!$user || empty($user['id'])) {
                return ['success' => false, 'message' => 'Invalid user data'];
            }

            if (!$user['is_active']) {
                return ['success' => false, 'message' => 'Account is disabled'];
            }

            $this->establishSession($user);

            return [
                'success' => true,
                'message' => 'SAML login successful',
                'user_id' => $user['id']
            ];

        } catch (Exception $e) {
            error_log('SAML login error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during SAML login'];
        }
    }

    // Prevent cloning -- singletons don't do mitosis
    private function __clone() {}

    // Prevent unserialization -- no Frankenstein instances allowed
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Public audit logging method -- the externally-facing version.
     *
     * Same as logAudit but callable from outside the class. Used when other
     * parts of the app need to log auth-related events (like impersonation
     * logging from controllers). Just a thin wrapper around the private method.
     *
     * @param int|null $userId User ID
     * @param string $action Action performed
     * @param string $tableName Table name
     * @param int|null $recordId Record ID
     * @param array $data Additional data
     */
    public function audit($userId, $action, $tableName, $recordId = null, $data = []) {
        $this->logAudit($userId, $action, $tableName, $recordId, $data);
    }
}
