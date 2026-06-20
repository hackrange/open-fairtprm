<?php
/**
 * Application Bootstrap - The "Turn Everything On" File
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the file that gets the party started. Every page in the app includes
 * this at the top, and it sets up the autoloader, error handlers, security headers,
 * session management, timezone, and a bunch of helper functions. If you're looking
 * at this file, you're either debugging something fundamental or you're the new dev
 * trying to understand how the app boots. Either way, welcome. Read carefully --
 * the order of operations here matters more than you'd think.
 */

// Log everything but show nothing to the user. Because stack traces in production
// are basically a treasure map for hackers.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define path constants so the rest of the app doesn't have to guess where things are.
// Using these instead of relative paths keeps everything sane when files include each other.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', APP_ROOT . '/includes');
}
if (!defined('CLASSES_PATH')) {
    define('CLASSES_PATH', APP_ROOT . '/includes/classes');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', APP_ROOT . '/config');
}

/**
 * Autoloader -- the lazy programmer's best friend.
 * When PHP encounters an unknown class name, this function tries to find it
 * in the classes directory. No more massive require_once blocks at the top of files.
 * If the class file exists, load it. If not, return false and let PHP
 * throw its "Class not found" tantrum.
 */
spl_autoload_register(function ($className) {
    $classFile = CLASSES_PATH . '/' . $className . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
        return true;
    }

    return false;
});

/**
 * Procedural helpers that aren't classes (so the autoloader won't find them).
 * Loaded eagerly here so every page and API has them available.
 */
require_once INCLUDES_PATH . '/contact-fields.php';

/**
 * Global exception handler -- catches anything that nobody else bothered to catch.
 * In debug mode: shows the full error and stack trace (developer-friendly).
 * In production: shows a polite "something broke" message (user-friendly, hacker-unfriendly).
 * Either way, the full details go to error_log because that's where they belong.
 */
set_exception_handler(function ($exception) {
    error_log('Uncaught exception: ' . $exception->getMessage());
    error_log('Stack trace: ' . $exception->getTraceAsString());

    if (Config::getInstance()->get('app.debug', false)) {
        // Debug mode: show the developer everything. Yes, everything.
        echo '<h1>Application Error</h1>';
        echo '<p>' . htmlspecialchars($exception->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
    } else {
        // Production: "We're sorry, Dave. I'm afraid I can't do that."
        http_response_code(500);
        echo '<h1>An error occurred</h1>';
        echo '<p>Please contact the system administrator.</p>';
    }
    exit;
});

/**
 * Global error handler -- converts PHP errors into exceptions (in debug mode)
 * or just logs them (in production). The error_reporting() check respects the
 * @ error suppression operator, which we probably shouldn't be using but
 * definitely are somewhere in this codebase.
 */
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // If the error was suppressed with @, don't handle it
    if (!(error_reporting() & $errno)) {
        return false;
    }

    error_log("Error [$errno]: $errstr in $errfile on line $errline");

    // In debug mode, promote errors to exceptions so they're impossible to ignore
    if (Config::getInstance()->get('app.debug', false)) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    return true;
});

// ============================================================================
// Core initialization -- Config, Security Headers, and Session
// If any of this fails, the app can't run. Period.
// ============================================================================
try {
    // Load app config and set the timezone (defaults to Eastern if not configured)
    $config = Config::getInstance();
    date_default_timezone_set($config->get('app.timezone', 'America/New_York'));

    // Set security headers (CSP, X-Frame-Options, etc.) before any output
    $security = Security::getInstance();
    $security->setSecurityHeaders();

    // Boot up the session manager and clean up any expired sessions
    // (because leaving stale sessions around is just sloppy)
    $session = Session::getInstance();
    $session->cleanupOldSessions();

} catch (Throwable $e) {
    // If we can't even initialize, something is fundamentally broken.
    // Log it and die with a helpful-ish message.
    error_log('Initialization error: ' . $e->getMessage());
    die('Application initialization failed. Please check the configuration.');
}

// ============================================================================
// Helper Functions -- Global utilities available everywhere in the app.
// These are the "use 'em a million times" functions that save keystrokes.
// ============================================================================

/**
 * Resolve the effective unauthenticated-login URL.
 *
 * When auth.force_saml is true AND SAML is actually configured, send the user
 * straight to /saml/login (because /login.php returns 404 in that mode).
 * Otherwise fall back to /login.php as before.
 */
function getEffectiveLoginUrl($returnTo = '') {
    $base = '/login.php';
    try {
        $forceSaml = (bool)Config::getInstance()->get('auth.force_saml', false);
        if ($forceSaml) {
            $saml = new SAMLHandler();
            if ($saml->isEnabled()) {
                $base = '/saml/login';
            }
        }
    } catch (Exception $e) {
        // SAML not available; stay on /login.php
    }
    if (!empty($returnTo) && $returnTo !== '/' && $returnTo !== '/login.php') {
        $base .= '?return_to=' . urlencode($returnTo);
    }
    return $base;
}

/**
 * Checks if the user is logged in. If not, bounces them to the login page.
 * Call this at the top of any page that requires authentication.
 * Simple, effective, no-nonsense.
 */
function requireAuth() {
    $auth = Auth::getInstance();
    if (!$auth->isAuthenticated()) {
        // Preserve the original URL so the user lands back here after login
        $returnTo = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . getEffectiveLoginUrl($returnTo));
        exit;
    }
}

/**
 * Checks if the current user has admin privileges. Dies with 403 if not.
 *
 * Admin access is granted through three paths (because backwards compatibility
 * is a harsh mistress):
 * 1. Super Admin flag (is_super_admin = 1) -- the nuclear option
 * 2. Legacy admin flag (is_admin = 1) -- from the Before Times
 * 3. "Administrator" ACL group membership -- the modern way
 *
 * If none of those are true, you get a nice 403 page and an invitation to
 * reconsider your life choices.
 */
function requireAdmin() {
    $auth = Auth::getInstance();
    if (!$auth->isAuthenticated()) {
        // Redirect to login instead of dying with 403 -- when a session expires
        // mid-use the user should land on the login page, not a dead-end error.
        $returnTo = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . getEffectiveLoginUrl($returnTo));
        exit;
    }

    $session = Session::getInstance();

    // Super admins always pass -- they're the root users of the app
    if ($session->get('is_super_admin')) {
        return;
    }

    // Legacy admin flag -- kept for backward compatibility with older user records
    if ($auth->isAdmin()) {
        return;
    }

    // Modern ACL-based admin check
    $acl = ACL::getInstance();
    if ($acl->hasGroup('administrator')) {
        return;
    }

    // None of the above? Access denied.
    http_response_code(403);
    die('Access denied. Admin privileges required.');
}

/**
 * Requires the user to be in one or more ACL groups. Dies with 403 if they're not.
 * Pass a single group name or an array of group names -- if the user is in ANY
 * of them, they pass. It's an OR check, not an AND.
 */
function requireGroup($groupNames) {
    requireAuth(); // Gotta be logged in first, obviously

    $acl = ACL::getInstance();
    if (!$acl->hasGroup($groupNames)) {
        $groups = is_array($groupNames) ? implode(', ', $groupNames) : $groupNames;
        http_response_code(403);
        die("Access denied. Required group membership: {$groups}");
    }
}

/**
 * Requires the user to have a specific permission code (e.g., 'vendor.create').
 * Dies with 403 if they don't. Used for fine-grained access control beyond
 * just group membership.
 */
function requirePermission($permissionCode) {
    requireAuth();

    $acl = ACL::getInstance();
    if (!$acl->hasPermission($permissionCode)) {
        http_response_code(403);
        die("Access denied. Required permission: {$permissionCode}");
    }
}

/**
 * The strictest gate -- only super admins get through.
 * Use this for things like "delete all users" or "drop database"
 * (kidding about the second one. mostly.)
 */
function requireSuperAdmin() {
    requireAuth();

    $session = Session::getInstance();
    if (!$session->get('is_super_admin')) {
        http_response_code(403);
        die('Access denied. Super admin privileges required.');
    }
}

/**
 * Non-fatal group check -- returns true/false instead of dying.
 * Useful for conditionally showing/hiding UI elements based on
 * group membership. "Show the delete button only if they're an admin."
 */
function hasGroup($groupNames) {
    $auth = Auth::getInstance();
    if (!$auth->isAuthenticated()) {
        return false;
    }

    $acl = ACL::getInstance();
    return $acl->hasGroup($groupNames);
}

/**
 * Non-fatal permission check -- returns true/false.
 * Same idea as hasGroup() but for specific permission codes.
 */
function hasPermission($permissionCode) {
    $auth = Auth::getInstance();
    if (!$auth->isAuthenticated()) {
        return false;
    }

    $acl = ACL::getInstance();
    return $acl->hasPermission($permissionCode);
}

/**
 * Quick check: are we currently impersonating another user?
 * Returns true if a super admin is "viewing as" someone else.
 */
function isImpersonating() {
    $auth = Auth::getInstance();
    return $auth->isImpersonating();
}

/**
 * Get details about the current impersonation session.
 * Returns who's impersonating whom, or null if nobody's pretending to be anyone.
 */
function getImpersonationInfo() {
    $auth = Auth::getInstance();
    return $auth->getImpersonationInfo();
}

/**
 * Fetches a value from the app_config table, with optional decryption.
 * Caches results in a static variable so repeated calls for the same key
 * don't hammer the database. Falls back to $default if the key doesn't exist.
 *
 * Think of this as the "just give me that setting" function.
 */
function getAppConfig($key, $default = null, bool $_clearCache = false) {
    static $configCache = [];

    if ($_clearCache) {
        $configCache = [];
        return null;
    }

    // Already fetched this key? Return from cache.
    if (isset($configCache[$key])) {
        return $configCache[$key];
    }

    try {
        $db = Database::getInstance();
        $result = $db->fetchOne(
            'SELECT config_value, is_encrypted FROM app_config WHERE config_key = :key',
            [':key' => $key]
        );

        if ($result) {
            $value = $result['config_value'];

            // If it's encrypted, decrypt it before returning
            if ($result['is_encrypted']) {
                $encryption = new Encryption();
                $value = $encryption->decrypt($value);
            }

            $configCache[$key] = $value;
            return $value;
        }

        return $default;
    } catch (Exception $e) {
        error_log('Failed to get config value: ' . $e->getMessage());
        return $default;
    }
}

/**
 * Returns the CSP nonce for the current request.
 * Use in templates: <script nonce="<?php echo cspNonce(); ?>">
 */
function cspNonce() {
    return Security::getInstance()->getNonce();
}

/**
 * Shorthand for htmlspecialchars() with all the right flags.
 * Because typing htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8')
 * every time is a crime against developer productivity.
 * Usage: <?= e($userInput) ?> -- done.
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * SECURITY: neutralize CSV/formula injection for a single CSV cell.
 * Spreadsheet apps (Excel/Sheets/LibreOffice) execute a cell whose value begins
 * with = + - @ (or a leading tab/CR), e.g. =HYPERLINK(...)/=cmd|'/c calc'!A1.
 * Since vendor-supplied fields (names, justifications, domains) end up in CSV
 * exports opened by admins, prefix any such cell with a single quote so it is
 * treated as literal text. Apply via: array_map('csvSafeCell', $row) before fputcsv.
 */
function csvSafeCell($value) {
    $s = (string)$value;
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
}

/**
 * SECURITY (SSRF): validate an admin-configured outbound URL (AI / Grip / Zscaler
 * integration endpoints, whose requests carry bearer tokens). Rejects only cloud-metadata
 * (169.254.0.0/16) and loopback (127.0.0.0/8, ::1) hosts — the high-value SSRF / credential-
 * exfil destinations that are never legitimate integration targets. Private LAN ranges are
 * intentionally allowed, since on-prem AI/integration hosts are valid. Empty = allowed
 * (clearing the setting). Returns true if safe to save.
 */
function isConfigUrlHostSafe($url) {
    $url = trim((string)$url);
    if ($url === '') return true;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if ($ip === '::1') return false;
        if (strpos($ip, '127.') === 0) return false;     // loopback
        if (strpos($ip, '169.254.') === 0) return false;  // link-local / cloud metadata
    }
    return true;
}

/**
 * Normalize a vendor domain entry to a bare host.
 *
 * People paste full URLs ("https://domain.com", "http://domain.com/path") when
 * all we want is the domain. Strip the scheme and anything from the first
 * path/query/fragment separator onward, then trim stray dots/whitespace.
 *
 * Deliberately conservative: it does NOT strip "www." and does NOT blank out
 * values that don't look like a domain, so unusual-but-valid hosts survive.
 * Returns the cleaned string ('' stays '').
 */
function normalizeVendorDomain($input) {
    $s = trim((string)$input);
    if ($s === '') return '';
    $s = preg_replace('#^\s*https?://#i', '', $s);   // drop http:// or https://
    $s = preg_replace('#[/?\#].*$#', '', $s);        // drop path/query/fragment
    return trim($s, ". \t\n\r\0\x0B");
}

/**
 * The canonical set of languages the application ships translations for.
 * Maps language code => human-readable label (autonym + English name).
 * English is the hard fallback and is always available; it cannot be disabled.
 * This is the single source of truth -- the admin and profile pickers, the
 * language validators, and t()'s fallback all read from here.
 */
function i18nLanguages() {
    return [
        'en'      => 'English',
        'es'      => 'Español (Spanish)',
        'it'      => 'Italiano (Italian)',
        'uk'      => 'Українська (Ukrainian)',
        'zh-Hans' => '中文 (简体) — Chinese (Simplified)',
        'hi'      => 'हिन्दी (Hindi)',
        'fr'      => 'Français (French)',
        'pt'      => 'Português (Portuguese)',
    ];
}

/**
 * The set of languages an admin has switched on (from app_config.enabled_languages),
 * as a list of codes. English is always forced in, and unknown codes are dropped,
 * so the result is always a non-empty subset of i18nLanguages().
 */
function i18nEnabledLanguages() {
    $known = i18nLanguages();
    $enabled = json_decode((string)getAppConfig('enabled_languages', '["en"]'), true);
    if (!is_array($enabled)) {
        $enabled = ['en'];
    }
    $enabled = array_values(array_filter($enabled, function ($code) use ($known) {
        return isset($known[$code]);
    }));
    if (!in_array('en', $enabled, true)) {
        array_unshift($enabled, 'en');
    }
    return $enabled;
}

/**
 * Resolves the active UI language for this request, exactly once.
 * Order: the signed-in user's preferred_language -> the admin default
 * (app_config.default_language) -> 'en'. The result is always a code we ship a
 * catalog for; anything unknown collapses to 'en'. The DB lookup is wrapped so
 * pre-login pages (no auth) and pre-migration databases (no column) fall back
 * cleanly to the default rather than erroring.
 */
function currentLanguage() {
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $known = i18nLanguages();
    $resolved = getAppConfig('default_language', 'en');

    try {
        $userId = Auth::getInstance()->getUserId();
        if ($userId) {
            $row = Database::getInstance()->fetchOne(
                'SELECT preferred_language FROM users WHERE id = :id',
                [':id' => $userId]
            );
            if ($row && !empty($row['preferred_language'])) {
                $resolved = $row['preferred_language'];
            }
        }
    } catch (Exception $e) {
        // No auth yet, or the column doesn't exist on this DB -- keep the default.
    }

    $lang = isset($known[$resolved]) ? $resolved : 'en';
    return $lang;
}

/**
 * Translation lookup. Returns the RAW (unescaped) string for $key in the active
 * language, falling back to English and finally to the key itself, so a missing
 * translation never blanks the page. Any extra arguments are applied with
 * vsprintf(), matching printf-style placeholders in the catalog value.
 *
 * Output is NOT escaped -- wrap with e() at the call site, matching the
 * codebase convention: <?php echo e(t('nav.dashboard')); ?>
 */
function t($key, ...$args) {
    static $catalogs = [];

    foreach ([currentLanguage(), 'en'] as $candidate) {
        if (!array_key_exists($candidate, $catalogs)) {
            $file = __DIR__ . '/i18n/' . $candidate . '.php';
            $loaded = is_file($file) ? require $file : [];
            $catalogs[$candidate] = is_array($loaded) ? $loaded : [];
        }
        if (isset($catalogs[$candidate][$key])) {
            return $args ? vsprintf($catalogs[$candidate][$key], $args) : $catalogs[$candidate][$key];
        }
    }

    // Nothing anywhere -- return the key so the gap is visible but harmless.
    return $key;
}

/**
 * Redirect and die. Sets the Location header and exits immediately.
 * No output buffering shenanigans, no "headers already sent" surprises.
 * Just go there. Now.
 */
function redirect($url) {
    // Prevent open redirect attacks by only allowing relative URLs
    // and URLs to our own domain. Block protocol-relative URLs (//)
    // and absolute URLs to external domains.
    if (preg_match('#^https?://#i', $url) || preg_match('#^//#', $url)) {
        // Absolute URL -- verify it points to our own host
        $config = Config::getInstance();
        $baseUrl = $config->get('app.url', '');
        if (!empty($baseUrl) && strpos($url, rtrim($baseUrl, '/')) !== 0) {
            $url = '/'; // Redirect to home if URL doesn't match our base
        }
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Builds a full URL from the app's base URL and a relative path.
 * If app.url isn't configured, it auto-detects the protocol and host
 * from the current request. Handles trailing/leading slashes so you
 * don't end up with "http://example.com//page.php" ugliness.
 */
function baseUrl($path = '') {
    // Priority: app_config DB setting > config.php file > auto-detect from request
    static $cachedBaseUrl = null;

    if ($cachedBaseUrl === null) {
        $cachedBaseUrl = '';

        // 1. Check app_config table (admin-configurable)
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'app_url'");
            if ($row && !empty(trim($row['config_value']))) {
                $cachedBaseUrl = rtrim(trim($row['config_value']), '/');
            }
        } catch (Exception $e) {
            // DB not available yet (e.g. during setup)
        }

        // 2. Fall back to config.php
        if (empty($cachedBaseUrl)) {
            $config = Config::getInstance();
            $cachedBaseUrl = rtrim($config->get('app.url', ''), '/');
        }

        // 3. Fall back to auto-detect from current request
        if (empty($cachedBaseUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
            $cachedBaseUrl = $protocol . '://' . $host;
        }
    }

    return $cachedBaseUrl . '/' . ltrim($path, '/');
}

/**
 * Validate a CSS color value. Returns the value if it matches #hex format,
 * otherwise returns the default. Prevents CSS injection from stored values
 * that are output inside <style> blocks.
 */
function safeColor($color, $default) {
    return preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) ? $color : $default;
}

/**
 * Loads the user's theme settings with a layered fallback system.
 *
 * Priority order:
 * 1. User-specific theme settings (from the users table)
 * 2. App-wide theme settings (from app_config)
 * 3. Hardcoded defaults (teal header and buttons)
 *
 * Cached in a static variable because theme doesn't change mid-request.
 * Returns an array with logo_url, colors, nav settings, etc.
 */
function getUserTheme() {
    static $theme = null;

    // Already loaded? Ship it.
    if ($theme !== null) {
        return $theme;
    }

    // Layer 1: Hardcoded defaults -- the fallback of last resort
    $theme = [
        'logo_url' => 'app/images/logo-default-418x78.png',
        'footer_logo_url' => 'app/images/logo-inverse-416x78.png',
        'header_color' => '#35a0a3',
        'footer_color' => '#1a365d',
        'button_color' => '#35a0a3',
        'nav_fill_color' => '#e9ecef',
        'nav_font_color' => '#1f1e1e',
        'nav_width' => '220'
    ];

    try {
        $db = Database::getInstance();

        // Layer 2: App-wide theme defaults from app_config
        $appTheme = $db->fetchAll(
            'SELECT config_key, config_value FROM app_config WHERE config_key IN (?, ?, ?, ?, ?, ?, ?, ?)',
            ['logo_url', 'footer_logo_url', 'header_color', 'footer_color', 'button_color', 'nav_fill_color', 'nav_font_color', 'nav_width']
        );

        // Override defaults with anything the admin has set
        foreach ($appTheme as $setting) {
            if (!empty($setting['config_value'])) {
                $theme[$setting['config_key']] = $setting['config_value'];
            }
        }

        // Layer 3: Per-user theme overrides (if they're logged in and have custom settings)
        $auth = Auth::getInstance();
        if ($auth->isAuthenticated()) {
            $user = $auth->getUser();

            // Try to fetch all theme columns, including the newer nav ones
            try {
                $userTheme = $db->fetchOne(
                    'SELECT theme_logo_url, theme_footer_logo_url, theme_header_color, theme_footer_color, theme_button_color, theme_nav_fill_color, theme_nav_font_color, theme_nav_width FROM users WHERE id = :id',
                    [':id' => $user['id']]
                );
            } catch (Exception $e) {
                // If the newer nav columns don't exist yet (schema migration pending),
                // fall back to just the original columns
                $userTheme = $db->fetchOne(
                    'SELECT theme_logo_url, theme_footer_logo_url, theme_header_color, theme_footer_color, theme_button_color FROM users WHERE id = :id',
                    [':id' => $user['id']]
                );
            }

            // User theme overrides app theme, but only if they've actually set values
            if ($userTheme) {
                if (!empty($userTheme['theme_logo_url'])) {
                    $logoScheme = parse_url($userTheme['theme_logo_url'], PHP_URL_SCHEME);
                    $logoValid = ($logoScheme !== null && in_array(strtolower($logoScheme), ['http', 'https'], true))
                              || ($logoScheme === null && preg_match('#[/.]#', $userTheme['theme_logo_url']));
                    if ($logoValid) {
                        $theme['logo_url'] = $userTheme['theme_logo_url'];
                    }
                }
                if (!empty($userTheme['theme_footer_logo_url'])) {
                    $footerLogoScheme = parse_url($userTheme['theme_footer_logo_url'], PHP_URL_SCHEME);
                    $footerLogoValid = ($footerLogoScheme !== null && in_array(strtolower($footerLogoScheme), ['http', 'https'], true))
                                    || ($footerLogoScheme === null && preg_match('#[/.]#', $userTheme['theme_footer_logo_url']));
                    if ($footerLogoValid) {
                        $theme['footer_logo_url'] = $userTheme['theme_footer_logo_url'];
                    }
                }
                // Defense-in-depth: only accept valid hex colors from DB
                $hexColorRx = '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/';
                if (!empty($userTheme['theme_header_color']) && preg_match($hexColorRx, $userTheme['theme_header_color'])) {
                    $theme['header_color'] = $userTheme['theme_header_color'];
                }
                if (!empty($userTheme['theme_footer_color']) && preg_match($hexColorRx, $userTheme['theme_footer_color'])) {
                    $theme['footer_color'] = $userTheme['theme_footer_color'];
                }
                if (!empty($userTheme['theme_button_color']) && preg_match($hexColorRx, $userTheme['theme_button_color'])) {
                    $theme['button_color'] = $userTheme['theme_button_color'];
                }
                // Nav columns might not exist on older schemas, hence the isset() checks
                if (isset($userTheme['theme_nav_fill_color']) && !empty($userTheme['theme_nav_fill_color']) && preg_match($hexColorRx, $userTheme['theme_nav_fill_color'])) {
                    $theme['nav_fill_color'] = $userTheme['theme_nav_fill_color'];
                }
                if (isset($userTheme['theme_nav_font_color']) && !empty($userTheme['theme_nav_font_color']) && preg_match($hexColorRx, $userTheme['theme_nav_font_color'])) {
                    $theme['nav_font_color'] = $userTheme['theme_nav_font_color'];
                }
                if (isset($userTheme['theme_nav_width']) && !empty($userTheme['theme_nav_width'])) {
                    $theme['nav_width'] = $userTheme['theme_nav_width'];
                }
                $theme['nav_width'] = (is_numeric($theme['nav_width']) && $theme['nav_width'] >= 150 && $theme['nav_width'] <= 400)
                    ? intval($theme['nav_width'])
                    : 220;
            }
        }
    } catch (Exception $e) {
        // Theme loading failure = not the end of the world. Use defaults.
        error_log('Failed to load theme settings: ' . $e->getMessage());
    }

    // Validate all color values to prevent CSS injection when output in <style> blocks.
    // htmlspecialchars (e()) does not protect against injection in CSS context.
    $colorDefaults = [
        'header_color' => '#35a0a3',
        'footer_color' => '#1a365d',
        'button_color' => '#35a0a3',
        'nav_fill_color' => '#e9ecef',
        'nav_font_color' => '#1f1e1e'
    ];
    foreach ($colorDefaults as $key => $default) {
        $theme[$key] = safeColor($theme[$key] ?? '', $default);
    }

    return $theme;
}

/**
 * Checks if the current user is any flavor of admin.
 * Returns true for super admins, legacy admins, or ACL administrator group members.
 * Useful for "show admin-only UI elements" checks without caring about HOW they're an admin.
 */
function isAnyAdmin() {
    $auth = Auth::getInstance();
    if (!$auth->isAuthenticated()) {
        return false;
    }

    $session = Session::getInstance();

    // Super admin? Yes.
    if ($session->get('is_super_admin')) {
        return true;
    }

    // Legacy admin flag? Also yes.
    if ($auth->isAdmin()) {
        return true;
    }

    // ACL group "administrator"? Yep, that counts too.
    $acl = ACL::getInstance();
    return $acl->hasGroup('administrator');
}

/**
 * Validates a CSRF token on API endpoints.
 * Checks for the token in POST data and verifies it against the session.
 * If it fails, returns a JSON error and kills the request immediately.
 * No token, no API call. That's the deal.
 */
function requireCsrfToken($tokenKey = 'csrf_token') {
    $security = Security::getInstance();
    if (!isset($_POST[$tokenKey]) || !$security->validateCSRFToken($_POST[$tokenKey])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
}

/**
 * Format a UTC datetime string to the app's configured timezone.
 * Because showing everything in UTC is a great way to confuse literally
 * everyone who doesn't live in Greenwich, England.
 *
 * Falls back to the app_config timezone setting, then America/New_York,
 * because at least that covers most of the East Coast where the compliance
 * folks tend to live.
 */
function formatLocalTime(string $utcDateTime, string $format = 'M j, Y g:i A'): string {
    if (empty($utcDateTime)) {
        return '-';
    }
    // Grab the timezone from app_config, fall back to a sensible default
    static $tz = null;
    if ($tz === null) {
        $tz = getAppConfig('app_timezone') ?: 'America/New_York';
    }
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($format);
    } catch (Exception $e) {
        // If the timezone is bogus, just wing it with strtotime
        return date($format, strtotime($utcDateTime));
    }
}

/**
 * Renders the impersonation warning banner at the top of the page.
 * Shows who you're viewing as and who you actually are, with a big
 * orange/amber gradient so it's impossible to forget you're impersonating.
 * Call this right after the <body> tag on authenticated pages.
 * Does nothing if nobody's impersonating anybody.
 */
/**
 * Get the current application version from the VERSION file.
 * Falls back to .git/HEAD for backward compatibility.
 * Returns e.g. "v2.5.3" or "unknown" if unreadable.
 * Result is cached in a static variable so the file is only read once per request.
 */
function getAppVersion() {
    static $version = null;
    if ($version === null) {
        // Primary: read from VERSION file
        $versionFile = APP_ROOT . '/VERSION';
        if (file_exists($versionFile)) {
            $v = trim(@file_get_contents($versionFile));
            if ($v !== '' && $v !== false) {
                $version = $v;
                return $version;
            }
        }
        // Fallback: read from .git/HEAD
        $headFile = APP_ROOT . '/.git/HEAD';
        if (file_exists($headFile)) {
            $head = trim(@file_get_contents($headFile));
            if (strpos($head, 'ref: refs/heads/') === 0) {
                $version = substr($head, strlen('ref: refs/heads/'));
            } else {
                $version = substr($head, 0, 12) . '...';
            }
        } else {
            $version = 'unknown';
        }
    }
    return $version;
}

function renderImpersonationBanner() {
    $auth = Auth::getInstance();
    if (!$auth->isImpersonating()) {
        return;
    }

    $info = $auth->getImpersonationInfo();
    ?>
    <div class="impersonation-banner" style="background: linear-gradient(90deg, #f59e0b, #d97706); color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; font-size: 14px; flex-wrap: wrap; gap: 10px;">
        <div>
            <strong>Impersonation Active:</strong> You are viewing as <strong><?php echo e($info['impersonating_full_name']); ?></strong> (<?php echo e($info['impersonating_username']); ?>)
            <span style="opacity: 0.8; margin-left: 10px;">| Logged in as: <?php echo e($info['original_full_name']); ?></span>
        </div>
        <a href="admin.php?section=users&stop_impersonation=1" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.5); padding: 6px 15px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: 500;">
            Stop Impersonation
        </a>
    </div>
    <?php
}
