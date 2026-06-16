<?php
/**
 * ZscalerService — Zscaler Internet Access (ZIA) URL-category API client.
 *
 * Purpose: given a vendor domain, append it to a custom URL Category in the
 * customer's ZIA tenant so traffic to that domain is blocked. Triggered by
 * the "Deny" button in shadow-saas.php; configuration lives in app_config
 * under zscaler_* keys, managed in admin.php?section=shadow-saas.
 *
 * Auth strategy: tenants vary. We try modes in this order and stop at the
 * first that returns a usable token / session:
 *   1. OAuth2 client_credentials  — treats stored user/pass as
 *      client_id/client_secret against the ZIdentity vanity domain derived
 *      from the username (e.g. user@fairtprm.zslogin.net → fairtprm.zslogin.net).
 *   2. OAuth2 ROPC (grant_type=password) — same vanity endpoint, true
 *      username/password. Most tenants don't enable ROPC, but harmless to try.
 *   3. Legacy /api/v1/authenticatedSession — only attempted when an API key
 *      is also configured (zscaler_api_key, currently not surfaced in UI).
 *
 * The class exposes:
 *   testConnection()  — authenticate, report which mode worked, log out.
 *   denyDomain($d)    — full flow: auth → find category by name → append
 *                       (action=ADD_TO_LIST) → activate → log out.
 *   getLastError()    — last error surface for the admin UI.
 */

class ZscalerService
{
    private const REQUEST_TIMEOUT = 30;
    private const OAUTH_AUDIENCE = 'https://api.zscaler.com';

    private Database $db;
    private string $apiUrl = '';
    private string $vanityDomain = '';
    private string $username = '';
    private string $password = '';
    private string $apiKey = '';
    private string $urlCategory = '';
    private bool   $enabled = false;

    private ?string $accessToken = null;
    private ?string $jsessionId = null;
    private string  $authMode = '';
    private ?string $lastError = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $encryption = new Encryption();
        $rows = $this->db->fetchAll(
            'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
            ['zscaler_%']
        );
        foreach ($rows as $row) {
            $val = ($row['is_encrypted'] && !empty($row['config_value']))
                ? $encryption->decrypt($row['config_value'])
                : $row['config_value'];
            switch ($row['config_key']) {
                case 'zscaler_enabled':        $this->enabled      = ($val === '1'); break;
                case 'zscaler_api_url':        $this->apiUrl       = is_string($val) ? trim($val) : ''; break;
                case 'zscaler_vanity_domain':  $this->vanityDomain = is_string($val) ? strtolower(trim($val)) : ''; break;
                case 'zscaler_username':       $this->username     = is_string($val) ? trim($val) : ''; break;
                case 'zscaler_password':       $this->password     = is_string($val) ? $val : ''; break;
                case 'zscaler_api_key':        $this->apiKey       = is_string($val) ? trim($val) : ''; break;
                case 'zscaler_url_category':   $this->urlCategory  = is_string($val) ? trim($val) : ''; break;
            }
        }
        $this->apiUrl = rtrim($this->apiUrl, '/');
    }

    // -------------------------- Public API --------------------------

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->username !== '' && $this->password !== '';
    }

    public function isEnabled(): bool { return $this->enabled && $this->isConfigured(); }
    public function getUrlCategory(): string { return $this->urlCategory; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getAuthMode(): string { return $this->authMode; }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Not configured. API URL, Username, and Password are required.'];
        }
        if (!$this->authenticate()) {
            return ['success' => false, 'message' => 'Authentication failed. ' . ($this->lastError ?? '')];
        }
        $mode = $this->authMode;
        // Optional secondary probe: list URL categories to confirm the token has scope.
        $probe = $this->ziaCall('GET', '/urlCategories', null, ['customOnly' => 'true']);
        $this->logout();
        if ($probe['code'] === 200) {
            $count = is_array($probe['body']) ? count($probe['body']) : 0;
            return ['success' => true, 'message' => "Authenticated via {$mode}. Custom categories visible: {$count}."];
        }
        return [
            'success' => false,
            'message' => "Authenticated via {$mode}, but /urlCategories returned HTTP {$probe['code']}. " .
                         "The credential may lack URL-Categories scope. " . substr((string)$probe['raw'], 0, 200),
        ];
    }

    /**
     * Add $domain (vendor domain or URL) to the configured URL Category and
     * activate the change. Returns success/message and (on failure) lastError.
     */
    public function denyDomain(string $domain): array
    {
        return $this->mutateCategory($domain, 'ADD_TO_LIST');
    }

    /**
     * Remove $domain from the configured URL Category and activate. Inverse
     * of denyDomain — used by the Allow button to revert a prior block.
     */
    public function allowDomain(string $domain): array
    {
        return $this->mutateCategory($domain, 'REMOVE_FROM_LIST');
    }

    /**
     * Shared add/remove workflow. $action is 'ADD_TO_LIST' or 'REMOVE_FROM_LIST'.
     *
     * We push both the bare hostname AND a leading-dot wildcard form into the
     * URL Category: Zscaler treats `example.com` as an exact match and
     * `.example.com` as a wildcard for subdomains, and the two are evaluated
     * as distinct entries (see help.zscaler.com/zia/about-url-categories and
     * the Zenith thread on overlapping categories). Sending both ensures the
     * apex and every subdomain of a vendor are covered by a single deny.
     * ADD_TO_LIST is additive on duplicates and REMOVE_FROM_LIST silently
     * ignores entries that aren't present, so this is safe whether or not
     * either form was already on the list.
     */
    private function mutateCategory(string $domain, string $action): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Zscaler is not configured.'];
        }
        if ($this->urlCategory === '') {
            return ['success' => false, 'message' => 'URL Category is not configured.'];
        }
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return ['success' => false, 'message' => 'Could not derive a domain from the input.'];
        }
        $entries = [$normalized, '.' . $normalized];
        $verb = $action === 'REMOVE_FROM_LIST' ? 'remove' : 'add';
        try {
            if (!$this->authenticate()) {
                return ['success' => false, 'message' => 'Authentication failed. ' . ($this->lastError ?? '')];
            }
            $cat = $this->findCategoryByName($this->urlCategory);
            if (!$cat || empty($cat['id'])) {
                $this->logout();
                return ['success' => false, 'message' => 'URL Category "' . $this->urlCategory . '" not found in Zscaler.'];
            }
            $ok = $this->mutateCategoryUrls((string)$cat['id'], $this->urlCategory, $entries, $action);
            if (!$ok) {
                $this->logout();
                return ['success' => false, 'message' => "Failed to {$verb} domain. " . ($this->lastError ?? '')];
            }
            $activated = $this->activate();
            $this->logout();
            $past = $action === 'REMOVE_FROM_LIST' ? 'Removed' : 'Added';
            $prep = $action === 'REMOVE_FROM_LIST' ? 'from' : 'to';
            $entriesStr = "'" . implode("', '", $entries) . "'";
            return [
                'success' => true,
                'message' => "{$past} {$entriesStr} {$prep} '{$this->urlCategory}'" . ($activated ? ' and activated.' : ' (activation pending — concurrent edit in progress).'),
            ];
        } catch (Throwable $e) {
            try { $this->logout(); } catch (Throwable $e2) {}
            error_log("Zscaler mutateCategory ({$action}) error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Normalize a vendor input to Zscaler's expected hostname form. Strips
     * scheme, path, query, port, and leading "www.". Zscaler stores bare
     * hostnames; ".example.com" wildcards subdomains. We return the bare host;
     * callers can prepend "." if they want subdomain coverage.
     */
    public function normalizeDomain(string $input): string
    {
        $s = trim($input);
        if ($s === '') return '';
        $s = preg_replace('#^https?://#i', '', $s);
        $s = preg_replace('#/.*$#', '', $s);
        $s = preg_replace('#\?.*$#', '', $s);
        $s = preg_replace('#:\d+$#', '', $s);
        $s = preg_replace('#^www\.#i', '', $s);
        $s = strtolower(trim($s, ". \t\n\r\0\x0B"));
        if (!preg_match('#^[a-z0-9.-]+\.[a-z]{2,}$#', $s)) {
            return '';
        }
        return $s;
    }

    // -------------------------- Auth --------------------------

    private function authenticate(): bool
    {
        $this->accessToken = null;
        $this->jsessionId  = null;
        $this->authMode    = '';
        $this->lastError   = null;

        $vanity = $this->deriveVanity();
        $errors = [];

        if ($vanity !== null) {
            $tokenUrl = "https://{$vanity}/oauth2/v1/token";
            if ($this->tryOAuthClientCredentials($tokenUrl)) { $this->authMode = 'oauth_client_credentials'; return true; }
            $errors[] = 'client_credentials: ' . ($this->lastError ?? '');
            if ($this->tryOAuthPassword($tokenUrl))           { $this->authMode = 'oauth_password';           return true; }
            $errors[] = 'password_grant: '       . ($this->lastError ?? '');
        }

        if ($this->apiKey !== '') {
            if ($this->tryLegacySession()) { $this->authMode = 'legacy'; return true; }
            $errors[] = 'legacy: ' . ($this->lastError ?? '');
        }

        $this->lastError = $errors ? implode(' | ', $errors) : 'No usable auth mode (username has no .zslogin.net suffix and no API key is configured).';
        return false;
    }

    /**
     * Resolve the ZIdentity vanity host used to build the OAuth token URL.
     * Prefers the explicit Vanity Domain field; falls back to the legacy
     * "user@<vanity>.zslogin.net" suffix on the Client ID / Username field
     * for migrations from the pre-relabel form.
     */
    private function deriveVanity(): ?string
    {
        if ($this->vanityDomain !== '') {
            $v = strtolower($this->vanityDomain);
            $v = preg_replace('#^https?://#i', '', $v);
            $v = preg_replace('#/.*$#', '', $v);
            return $v !== '' ? $v : null;
        }
        if (preg_match('#@([a-z0-9.-]+\.zslogin\.net)$#i', $this->username, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function tryOAuthClientCredentials(string $tokenUrl): bool
    {
        return $this->fetchOAuthToken($tokenUrl, [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->username,
            'client_secret' => $this->password,
            'audience'      => self::OAUTH_AUDIENCE,
        ]);
    }

    private function tryOAuthPassword(string $tokenUrl): bool
    {
        return $this->fetchOAuthToken($tokenUrl, [
            'grant_type' => 'password',
            'username'   => $this->username,
            'password'   => $this->password,
            'audience'   => self::OAUTH_AUDIENCE,
        ]);
    }

    private function fetchOAuthToken(string $url, array $form): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->lastError = "cURL error contacting {$url}: {$err}";
            return false;
        }
        if ($code !== 200) {
            $this->lastError = "Token endpoint returned HTTP {$code}: " . substr((string)$resp, 0, 200);
            return false;
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $this->lastError = 'Token response missing access_token.';
            return false;
        }
        $this->accessToken = (string)$data['access_token'];
        return true;
    }

    /** Legacy /api/v1/authenticatedSession with obfuscated API key. */
    private function tryLegacySession(): bool
    {
        $now      = (int)floor(microtime(true) * 1000);
        $tsString = (string)$now;
        $n        = substr($tsString, -6);
        $r        = str_pad((string)((int)$n >> 1), 6, '0', STR_PAD_LEFT);

        $key = '';
        $seed = $this->apiKey;
        for ($i = 0; $i < strlen($n); $i++) {
            $idx = (int)$n[$i];
            if (isset($seed[$idx])) { $key .= $seed[$idx]; }
        }
        for ($j = 0; $j < strlen($r); $j++) {
            $idx = (int)$r[$j] + 2;
            if (isset($seed[$idx])) { $key .= $seed[$idx]; }
        }

        $url = rtrim($this->apiUrl, '/') . '/api/v1/authenticatedSession';
        $body = json_encode([
            'username'  => $this->username,
            'password'  => $this->password,
            'timestamp' => $tsString,
            'apiKey'    => $key,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->lastError = "cURL error contacting {$url}: {$err}";
            return false;
        }
        if ($code !== 200) {
            $body = substr((string)$resp, $hdrSize);
            $this->lastError = "Legacy auth returned HTTP {$code}: " . substr($body, 0, 200);
            return false;
        }
        $headers = substr((string)$resp, 0, $hdrSize);
        if (preg_match('#Set-Cookie:\s*JSESSIONID=([^;\s]+)#i', $headers, $m)) {
            $this->jsessionId = $m[1];
            return true;
        }
        $this->lastError = 'Legacy auth succeeded but no JSESSIONID cookie was returned.';
        return false;
    }

    private function logout(): void
    {
        try {
            if ($this->jsessionId !== null) {
                $this->ziaCall('DELETE', '/authenticatedSession');
            }
        } catch (Throwable $e) {
            // best-effort; ignore
        }
        $this->accessToken = null;
        $this->jsessionId  = null;
    }

    // -------------------------- ZIA API helpers --------------------------

    /** Build ZIA API URL. OAuth tokens go through OneAPI (/zia/api/v1/...). */
    private function buildZiaUrl(string $path): string
    {
        $base = rtrim($this->apiUrl, '/');
        if ($this->accessToken !== null) {
            if (!preg_match('#/zia(/api(/v1)?)?$#', $base)) {
                return $base . '/zia/api/v1' . $path;
            }
        }
        return $base . '/api/v1' . $path;
    }

    private function ziaCall(string $method, string $path, $body = null, array $query = []): array
    {
        $url = $this->buildZiaUrl($path);
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->accessToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($this->jsessionId !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, 'JSESSIONID=' . $this->jsessionId);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'code' => $code,
            'raw'  => $resp === false ? '' : (string)$resp,
            'body' => $resp === false ? null : json_decode((string)$resp, true),
            'err'  => $err,
        ];
    }

    private function findCategoryByName(string $name): ?array
    {
        $r = $this->ziaCall('GET', '/urlCategories', null, ['customOnly' => 'true']);
        if ($r['code'] !== 200 || !is_array($r['body'])) {
            $this->lastError = "List categories returned HTTP {$r['code']}: " . substr($r['raw'], 0, 200);
            return null;
        }
        foreach ($r['body'] as $cat) {
            if (!is_array($cat)) continue;
            $cn = (string)($cat['configuredName'] ?? '');
            if ($cn !== '' && strcasecmp($cn, $name) === 0) {
                return $cat;
            }
        }
        return null;
    }

    private function mutateCategoryUrls(string $categoryId, string $categoryName, array $domains, string $action): bool
    {
        $r = $this->ziaCall(
            'PUT',
            '/urlCategories/' . rawurlencode($categoryId),
            ['configuredName' => $categoryName, 'urls' => array_values($domains)],
            ['action' => $action]
        );
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        $this->lastError = "{$action} returned HTTP {$r['code']}: " . substr($r['raw'], 0, 200);
        return false;
    }

    private function activate(): bool
    {
        $r = $this->ziaCall('POST', '/status/activate', new stdClass());
        return ($r['code'] >= 200 && $r['code'] < 300);
    }
}
