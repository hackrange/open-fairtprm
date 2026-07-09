<?php
/**
 * Session Management - The Memory Keeper
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Handles PHP sessions with all the security hardening your paranoid sysadmin
 * would approve of: IP binding, periodic session ID regeneration, timeout
 * enforcement, secure cookie flags, and database persistence. Think of it as
 * PHP's built-in session handling but wearing body armor. Without this class,
 * session hijacking would be as easy as stealing candy from a baby. With it,
 * it's more like stealing candy from a baby who has a bodyguard.
 */

class Session {
    // Singleton -- because multiple session managers would be chaos
    private static $instance = null;

    // Dependencies we need to do our job
    private $config;
    private $db;
    private $security;

    /**
     * Private constructor. Sets up dependencies and immediately configures
     * the session with all our security settings. By the time this constructor
     * finishes, the session is started, validated, and ready to rock.
     */
    private function __construct() {
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
        $this->configureSession();
    }

    /**
     * Singleton accessor. First call boots everything up, after that you just
     * get the same instance back. Consistency is key, especially with sessions.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Is the request that reached PHP being served over HTTPS?
     *
     * Trusts X-Forwarded-Proto because we are normally behind a TLS-terminating
     * reverse proxy (the setup script's nginx config sets it). A client that
     * forges the header only talks itself into a Secure cookie its own browser
     * will then refuse to send back -- it cannot use this to weaken anything.
     *
     * @return bool True when the browser sees an https:// origin
     */
    private function requestIsHttps() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            // May be a comma-separated chain; the left-most hop is the client's.
            $proto = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
            if (strtolower(trim($proto)) === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * Configure PHP session settings and start the session.
     *
     * This is where we lock things down. We set:
     * - strict mode (reject uninitialized session IDs)
     * - cookies only (no session IDs in URLs, because it's not 1999)
     * - httponly flag (JavaScript can't touch our session cookie)
     * - secure flag (HTTPS only, and only when the request actually is HTTPS)
     * - samesite attribute (CSRF protection at the cookie level)
     * - gc_maxlifetime (how long before PHP garbage-collects the session)
     *
     * After starting the session, we run validation checks to make sure
     * nobody's trying anything funny.
     */
    private function configureSession() {
        // Pull all our settings from config -- sensible defaults if missing
        $sessionName = $this->config->get('auth.session_name', '__Host-tprm_session');
        $lifetime = $this->config->get('auth.session_lifetime', 3600);
        $httponly = $this->config->get('security.httponly_cookies', true);
        $samesite = $this->config->get('security.samesite_cookies', 'Strict');

        // A Secure cookie is only ever stored and returned by a browser over an
        // HTTPS origin, and a __Host- prefixed cookie is rejected outright unless
        // it is Secure. Asking for either on a plain-HTTP request means the
        // browser keeps no session at all, so every CSRF check fails and the
        // login form rejects even a correct password. Ask only for what the
        // current request can actually carry.
        $secure = $this->config->get('security.secure_cookies', true) && $this->requestIsHttps();
        if (!$secure) {
            $sessionName = preg_replace('/^__(Host|Secure)-/', '', $sessionName);
        }

        // Harden the PHP session settings via ini_set
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', $httponly ? '1' : '0');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $samesite);
        ini_set('session.cookie_domain', '');
        ini_set('session.cookie_path', '/');
        ini_set('session.gc_maxlifetime', $lifetime);

        // Custom session name so it doesn't scream "I'm a PHP app" with PHPSESSID
        session_name($sessionName);

        // Only start the session if one isn't already running.
        // Double-starting a session is a great way to get confusing warnings.
        if (!isset($_SESSION)) {
            session_start([
                'cookie_lifetime' => $lifetime,
                'cookie_secure' => $secure,
                'cookie_httponly' => $httponly,
                'cookie_samesite' => $samesite,
                'cookie_domain' => '',
                'cookie_path' => '/',
                'use_strict_mode' => true
            ]);
        }

        // Run our gauntlet of session validation checks
        $this->validateSession();
    }

    /**
     * Validate the current session for signs of tampering or expiration.
     *
     * Here's what we check:
     * 1. New session? Regenerate the ID immediately and record the client's IP/UA
     * 2. IP changed since session started? Kill it. (Possible hijack attempt.)
     * 3. Session been idle too long? Kill it. (Stale sessions = risk.)
     * 4. Session ID hasn't been regenerated in 5+ minutes? Regenerate it.
     *    (Moving target = harder to hijack.)
     *
     * This runs on every single request. Yes, every one. Security isn't free.
     */
    private function validateSession() {
        // Brand new session -- fingerprint the client.
        // No need to regenerate the ID here: use_strict_mode is enabled,
        // so PHP already rejected any uninitialized session ID and
        // generated a fresh one in session_start(). Regenerating again
        // would just emit a redundant Set-Cookie header.
        if (!isset($_SESSION['initiated'])) {
            $_SESSION['initiated'] = true;
            $_SESSION['ip_address'] = $this->security->getClientIP();
            $_SESSION['user_agent'] = $this->security->getUserAgent();
            $_SESSION['regenerated'] = time();
        }

        // IP address mismatch? Someone might be replaying a stolen session.
        // Nuke it from orbit.
        if (isset($_SESSION['ip_address']) &&
            $_SESSION['ip_address'] !== $this->security->getClientIP()) {
            $this->destroy();
            return;
        }

        // Check for session timeout -- if the user's been idle too long, boot them
        if (isset($_SESSION['last_activity'])) {
            $lifetime = $this->config->get('auth.session_lifetime', 3600);
            if (time() - $_SESSION['last_activity'] > $lifetime) {
                $this->destroy();
                return;
            }
        }

        // Update the last activity timestamp -- the session is still alive
        $_SESSION['last_activity'] = time();

        // Regenerate session ID every 5 minutes to reduce hijacking window.
        // This is like changing the locks regularly -- annoying but effective.
        if (!isset($_SESSION['regenerated']) || time() - $_SESSION['regenerated'] > 300) {
            session_regenerate_id(true);
            $_SESSION['regenerated'] = time();
        }
    }

    /**
     * Set a value in the session. Bread and butter stuff.
     * It's just a fancy wrapper around $_SESSION[$key] = $value, but
     * using this method keeps things consistent and testable.
     */
    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }

    /**
     * Get a value from the session, with an optional default.
     * Returns the default if the key doesn't exist. No more
     * "undefined index" notices cluttering up your error log.
     */
    public function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if a key exists in the session.
     * Useful when you need to know "is it set?" vs "is it null?".
     */
    public function has($key) {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a key from the session.
     * Checks if it exists first because unsetting something that
     * doesn't exist won't error, but being explicit never hurts.
     */
    public function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Destroy the session completely. Total scorched earth.
     *
     * Empties the session array, kills the session cookie by setting it
     * to expire way in the past (42000 seconds ago -- because why not),
     * and then calls session_destroy() to nuke the server-side data.
     * After this, the user is effectively a stranger again.
     */
    public function destroy() {
        // Wipe the session data array
        $_SESSION = [];

        // Murder the session cookie by backdating its expiry.
        // We use the existing cookie params to make sure we're killing
        // the right cookie on the right path/domain.
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );

        // Destroy the server-side session file/storage
        session_destroy();
    }

    /**
     * Regenerate the session ID manually.
     * Used during login to prevent session fixation attacks. The old
     * session ID gets invalidated and a shiny new one takes its place.
     * Also updates the regeneration timestamp so our periodic regen
     * timer resets.
     */
    public function regenerateId() {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = time();
    }

    /**
     * Persist the current session to the database.
     *
     * Stores session ID, user ID, IP, user agent, the full session payload
     * as JSON, and a timestamp. If the session already exists in the DB,
     * we update it; otherwise we insert a new record. This gives us a
     * centralized view of all active sessions, which is great for things
     * like "show me who's logged in" or "force-logout this user."
     *
     * Wrapped in a try/catch because if DB persistence fails, we'd rather
     * the session still work than crash the entire request.
     *
     * @param int $userId The user ID this session belongs to
     */
    public function saveToDatabase($userId) {
        try {
            $sessionId = session_id();
            $ipAddress = $this->security->getClientIP();
            $userAgent = $this->security->getUserAgent();
            $payload = json_encode($_SESSION);
            $lastActivity = time();

            // Check if this session ID already has a row in the DB
            $existing = $this->db->fetchOne(
                'SELECT id FROM sessions WHERE id = :id',
                [':id' => $sessionId]
            );

            if ($existing) {
                // Session exists -- just update the payload and activity timestamp
                $this->db->update(
                    'sessions',
                    [
                        'payload' => $payload,
                        'last_activity' => $lastActivity
                    ],
                    'id = :id',
                    [':id' => $sessionId]
                );
            } else {
                // New session -- insert the whole shebang
                $this->db->insert('sessions', [
                    'id' => $sessionId,
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'payload' => $payload,
                    'last_activity' => $lastActivity
                ]);
            }
        } catch (Exception $e) {
            // If DB session save fails, log it but don't break the user's experience
            error_log('Failed to save session to database: ' . $e->getMessage());
        }
    }

    /**
     * Clean up expired sessions from the database.
     *
     * Deletes any session rows where last_activity is older than the
     * configured lifetime. Run this periodically (cron, middleware, whatever)
     * to keep the sessions table from growing into a monster that eats
     * your disk space. Think of it as taking out the trash.
     */
    public function cleanupOldSessions() {
        try {
            $lifetime = $this->config->get('auth.session_lifetime', 3600);
            $expiredTime = time() - $lifetime;

            $this->db->delete(
                'sessions',
                'last_activity < :expired',
                [':expired' => $expiredTime]
            );
        } catch (Exception $e) {
            error_log('Failed to cleanup old sessions: ' . $e->getMessage());
        }
    }

    // No cloning -- there can be only one session manager (Highlander rules)
    private function __clone() {}

    // No deserializing -- reconstructing a session from a serialized blob is asking for trouble
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
