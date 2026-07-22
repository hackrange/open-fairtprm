<?php
/**
 * Security Utilities - The Paranoia Engine
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This class is the collection of all the "don't get hacked" utilities: CSRF
 * token generation and validation, XSS prevention via output sanitization,
 * input cleaning for database storage, password strength validation, security
 * header management, and various helper methods for IP detection and filename
 * sanitization. If the Encryption class is the vault, this class is the alarm
 * system, the guard dogs, the moat, and the "BEWARE" sign all rolled into one.
 * Pretty much everything you need to not end up on the front page of
 * HackerNews for the wrong reasons.
 */

class Security {
    // Singleton instance -- one security guard is enough if they're good
    private static $instance = null;

    // Config reference for security settings
    private $config;

    // CSP nonce for the current request -- generated once, used everywhere
    private $cspNonce = null;

    /**
     * Private constructor. Just grabs the config instance.
     * Security doesn't need much setup -- it's always ready for trouble.
     */
    private function __construct() {
        $this->config = Config::getInstance();
    }

    /**
     * Singleton accessor. You know the drill by now.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate a fresh CSRF token.
     *
     * Creates a 32-byte random token (64 hex chars) and stores it in the
     * session along with a timestamp. The timestamp is used for expiration
     * checking during validation. Every form should include this token,
     * and every POST handler should validate it. It's the "are you really
     * submitting this from our site?" check.
     *
     * @return string The generated CSRF token
     */
    public function generateCSRFToken() {
        // Make sure sessions are running -- CSRF tokens live in the session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $tokenName = $this->config->get('security.csrf_token_name', 'csrf_token');
        $token = bin2hex(random_bytes(32));
        $timestamp = time();

        // Store both the token and when it was created
        $_SESSION[$tokenName] = $token;
        $_SESSION[$tokenName . '_time'] = $timestamp;

        return $token;
    }

    /**
     * Validate a submitted CSRF token.
     *
     * Checks three things:
     * 1. Does a token exist in the session? (i.e., was one ever generated?)
     * 2. Is the token still fresh? (configurable lifetime, default 2 hours)
     * 3. Does the submitted token match the session token? (constant-time comparison)
     *
     * If any check fails, the token is rejected. Expired tokens get cleaned
     * up from the session too, because we're tidy like that.
     *
     * @param string $token The token submitted with the form
     * @return bool True if the token is valid and fresh
     */
    public function validateCSRFToken($token, $consume = true) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $tokenName = $this->config->get('security.csrf_token_name', 'csrf_token');
        $lifetime = $this->config->get('security.csrf_token_lifetime', 7200);

        // No token in session? Something's off.
        if (!isset($_SESSION[$tokenName]) || !isset($_SESSION[$tokenName . '_time'])) {
            http_response_code(403);
            return false;
        }

        // Token expired? Clean it up and reject.
        if (time() - $_SESSION[$tokenName . '_time'] > $lifetime) {
            unset($_SESSION[$tokenName]);
            unset($_SESSION[$tokenName . '_time']);
            http_response_code(403);
            return false;
        }

        // Constant-time comparison to prevent timing attacks on the token
        $isValid = hash_equals($_SESSION[$tokenName], $token);

        // Consume the token after successful validation to prevent replay.
        // A fresh token is generated immediately so subsequent forms/AJAX
        // can retrieve it via getCSRFToken().
        //
        // High-frequency, UUID-authenticated AJAX endpoints (assessment autosave,
        // batch-save, file upload) pass $consume = false: they validate the token
        // but DO NOT rotate it. Rotating per request breaks legitimate concurrent
        // autosaves -- a second in-flight request arrives with the now-stale token
        // and fails with a spurious 403 ("Error saving"). Keeping a stable
        // per-session token for these endpoints removes that race without weakening
        // CSRF protection (the token is still secret, per-session, and required).
        if ($isValid) {
            if ($consume) {
                $_SESSION[$tokenName] = bin2hex(random_bytes(32));
                $_SESSION[$tokenName . '_time'] = time();
            }
        } else {
            http_response_code(403);
        }

        return $isValid;
    }

    /**
     * Get the current CSRF token from the session.
     * Returns null if no token has been generated yet.
     * Handy for embedding in forms without generating a new one.
     *
     * @return string|null The current token, or null if none exists
     */
    public function getCSRFToken() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $tokenName = $this->config->get('security.csrf_token_name', 'csrf_token');
        return $_SESSION[$tokenName] ?? null;
    }

    /**
     * Validate the Origin or Referer header matches this server.
     *
     * Defense-in-depth check alongside CSRF tokens. Verifies that the
     * request originated from the same host by comparing the Origin header
     * (preferred) or Referer header (fallback) against the server's own
     * host. Also checks the Sec-Fetch-Site header (sent by modern browsers)
     * to catch cross-site requests even when Origin/Referer are stripped.
     *
     * @return bool True if the origin is verified as same-site
     */
    public function validateOrigin(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;

        // Sec-Fetch-Site is set by browsers and cannot be spoofed by JS.
        // If it says cross-site, reject immediately.
        if ($fetchSite !== null && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
            http_response_code(403);
            return false;
        }

        // Use Origin header first (most reliable, not stripped by proxies)
        if ($origin !== null) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $valid = $originHost !== null && strcasecmp($originHost, $_SERVER['HTTP_HOST']) === 0;
            if (!$valid) {
                http_response_code(403);
            }
            return $valid;
        }

        // Fallback to Referer header
        if ($referer !== null) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $valid = $refererHost !== null && strcasecmp($refererHost, $_SERVER['HTTP_HOST']) === 0;
            if (!$valid) {
                http_response_code(403);
            }
            return $valid;
        }

        // Neither Origin nor Referer present. If Sec-Fetch-Site confirmed
        // same-origin/none above, allow it. Otherwise reject -- an attacker
        // can strip Referer with referrerpolicy="no-referrer" but cannot
        // forge Sec-Fetch-Site, and legitimate browsers always send at least
        // one of these headers.
        if ($fetchSite === null) {
            http_response_code(403);
        }
        return $fetchSite !== null;
    }

    /**
     * Sanitize data for safe HTML output (XSS prevention).
     *
     * Converts special characters to HTML entities using htmlspecialchars.
     * This is the "use when DISPLAYING data" method, not when storing it.
     * Store raw, display safe -- that's the mantra. Handles arrays
     * recursively, so you can pass in a whole form submission and
     * get it all sanitized at once.
     *
     * @param mixed $data String or array to sanitize
     * @return mixed Sanitized data with HTML entities escaped
     */
    public function sanitizeInput($data) {
        // Recursively handle arrays -- sanitize all the things
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }

        if (is_string($data)) {
            // ENT_QUOTES handles both single and double quotes
            // ENT_HTML5 for modern HTML entity handling
            // UTF-8 because it's not the 90s anymore
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Non-string, non-array? Just pass it through (ints, bools, etc.)
        return $data;
    }

    /**
     * Clean input for database storage.
     *
     * This is the "use when STORING data" counterpart to sanitizeInput.
     * Strips null bytes (which can cause havoc in C-based string functions)
     * and trims whitespace. Doesn't do HTML encoding because we store
     * raw and encode on output. Handles arrays recursively.
     *
     * @param mixed $data String or array to clean
     * @return mixed Cleaned data
     */
    public function cleanInput($data) {
        if (is_array($data)) {
            return array_map([$this, 'cleanInput'], $data);
        }

        if (is_string($data)) {
            // Remove null bytes -- they're never legitimate in user input
            // and can be used for null byte injection attacks
            $data = str_replace("\0", '', $data);
            return trim($data);
        }

        return $data;
    }

    /**
     * Validate an email address using PHP's filter_var.
     * Not perfect (RFC 5322 is a nightmare) but catches the obvious
     * garbage like "notanemail" or "bob@" while accepting the stuff
     * that actually works in the real world.
     *
     * @param string $email Email address to validate
     * @return bool True if the email looks legit
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate password strength against configured requirements.
     *
     * Checks minimum length, uppercase, lowercase, numbers, and special
     * characters based on what's configured in the auth.password_requirements
     * config section. Returns a structured result with a valid flag and
     * an array of human-readable error messages. So you can show the user
     * exactly WHICH requirements they missed instead of just "password too weak."
     *
     * @param string $password The password to validate
     * @return array ['valid' => bool, 'errors' => array of error messages]
     */
    public function validatePassword($password) {
        $requirements = $this->config->get('auth.password_requirements');
        $errors = [];

        // Check minimum length -- the most basic requirement
        if (strlen($password) < $requirements['min_length']) {
            $errors[] = "Password must be at least {$requirements['min_length']} characters long";
        }

        // Check for uppercase letter -- gotta have at least one big boy
        if ($requirements['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        // Check for lowercase letter -- can't be ALL caps (this isn't the internet in 1998)
        if ($requirements['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        // Check for numbers -- throw a digit in there
        if ($requirements['require_numbers'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        // Check for special characters -- the @#$%^& gang
        if ($requirements['require_special_chars'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Generate (or return the existing) CSP nonce for this request.
     * The nonce is a base64-encoded 16-byte random value, unique per request.
     *
     * @return string The CSP nonce value
     */
    public function getNonce() {
        if ($this->cspNonce === null) {
            $this->cspNonce = base64_encode(random_bytes(16));
        }
        return $this->cspNonce;
    }

    /**
     * Set security headers on the HTTP response.
     *
     * Sets all OWASP-recommended security headers directly in PHP for
     * defense-in-depth. If a reverse proxy (nginx) also sets these,
     * the proxy headers will typically take precedence, which is fine.
     * CSP must be emitted here because it contains a per-request nonce.
     * Call early, before any output.
     */
    public function setSecurityHeaders() {
        // Strip headers that leak server/technology info
        header_remove('X-Powered-By');
        header_remove('Server');

        // Defense-in-depth: set security headers in PHP as fallback
        // even if they are also configured at the reverse proxy layer.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 0');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Prevent browsers and proxies from caching authenticated pages.
        // Sensitive data (user lists, vendor assessments, etc.) must not
        // persist in browser/proxy caches.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // HSTS: only set when served over HTTPS to avoid issues in dev
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Content-Security-Policy must be emitted from PHP because it
        // contains a per-request nonce for inline script blocks.
        $nonce = $this->getNonce();
        // Google origins are path-scoped on purpose. Allowlisting the bare
        // https://www.google.com and https://www.gstatic.com origins is a well
        // known CSP bypass: those hosts serve JSONP callback endpoints (e.g.
        // /complete/search?callback=) and old AngularJS builds, either of which
        // turns any HTML injection in this app into full script execution.
        // Restricting to the reCAPTCHA and Maps API paths we actually load
        // (see app/js/script.js) keeps both features working without handing
        // out that bypass.
        $csp = "default-src 'self'; "
             . "script-src 'self' 'nonce-{$nonce}' https://maps.google.com/maps/api/ https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "font-src 'self' https://fonts.gstatic.com; "
             . "img-src 'self' data: https:; "
             . "connect-src 'self'; "
             . "frame-src https://www.google.com/recaptcha/ blob:; "
             . "frame-ancestors 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self'; "
             . "object-src 'none';";
        header("Content-Security-Policy: {$csp}");
    }

    /**
     * Get the client's IP address.
     *
     * Uses REMOTE_ADDR exclusively. Apache mod_remoteip is configured to
     * trust X-Real-IP only from internal proxy subnets (10/8, 172.16/12,
     * 192.168/16, 127/8) and overwrites REMOTE_ADDR with the real client
     * IP before PHP ever runs. Checking user-controllable headers like
     * HTTP_CLIENT_IP or HTTP_X_FORWARDED_FOR here would let attackers
     * spoof their IP to bypass WAF blocks and session validation.
     *
     * @return string Client IP address
     */
    public function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return filter_var(trim($ip), FILTER_VALIDATE_IP) ? trim($ip) : '0.0.0.0';
    }

    /**
     * Get the user agent string from the request.
     * Falls back to 'Unknown' because some clients/bots don't send one.
     *
     * @return string User agent string
     */
    public function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    /**
     * Check if the current request is over HTTPS.
     * Simple check -- is the HTTPS server variable set and not 'off'?
     *
     * @return bool True if using HTTPS
     */
    public function isHTTPS() {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * Clickjacking prevention is handled at the nginx layer via
     * X-Frame-Options and CSP frame-ancestors headers.
     * See /etc/nginx/conf.d/demo.fairtprm.com.conf
     */
    public function preventClickjacking() {
        // No-op: headers managed by nginx
    }

    /**
     * Sanitize a filename to prevent path traversal and other shenanigans.
     *
     * Strips everything that isn't alphanumeric, dots, hyphens, or underscores.
     * Also collapses multiple consecutive dots into a single dot to prevent
     * directory traversal via "../../etc/passwd" type tricks. After this
     * method is done with a filename, it's as safe as a filename can be.
     *
     * @param string $filename The raw filename to sanitize
     * @return string A clean, safe filename
     */
    public function sanitizeFilename($filename) {
        // Strip everything except safe characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        // Collapse multiple dots to prevent directory traversal
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        return $filename;
    }

    // No cloning the security guard
    private function __clone() {}

    // No defrosting frozen security instances
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
