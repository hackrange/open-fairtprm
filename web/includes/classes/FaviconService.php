<?php
/**
 * Favicon Service - Fetches and Validates Vendor Favicons
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This service grabs favicon images for vendor domains so we can display
 * little logos next to vendor names in the SRS table. Uses Google's favicon
 * API as the primary source (reliable, handles edge cases like redirects and
 * non-standard favicon locations) with a direct /favicon.ico fallback for
 * environments where Google isn't reachable. The fetched image data is stored
 * as a BLOB in the database -- no external requests needed after the initial fetch.
 */

class FaviconService
{
    private const GOOGLE_FAVICON_URL = 'https://www.google.com/s2/favicons?domain=%s&sz=32';
    private const DDG_FAVICON_URL = 'https://icons.duckduckgo.com/ip3/%s.ico';
    private const TIMEOUT = 5;
    private const MAX_FAVICON_SIZE = 102400; // 100KB -- favicons shouldn't be bigger than this

    /** @var array Allowed MIME types for favicons */
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/gif',
        'image/jpeg',
        'image/svg+xml',
        'image/webp',
    ];

    /**
     * Fetch a favicon for the given domain.
     *
     * Strategy (in order):
     * 1. Google's favicon API -- fast and handles most sites
     * 2. DuckDuckGo's icon API -- good fallback, different index than Google
     * 3. Parse the site's HTML for <link rel="icon"> -- catches SVG favicons,
     *    non-standard paths, and sites that don't use /favicon.ico
     * 4. Direct /favicon.ico fetch -- the classic fallback
     *
     * Returns null if nothing works. We'll just skip the icon in the UI.
     *
     * @param string $domain The vendor domain (e.g., "example.com")
     * @return array|null ['data' => binary image data, 'mime' => MIME type string] or null
     */
    public function fetchFavicon(string $domain): ?array
    {
        $domain = $this->sanitizeDomain($domain);
        if (empty($domain)) {
            return null;
        }

        // Try Google's favicon API first -- it's fast, reliable, and handles
        // weird favicon setups (link tags, apple-touch-icon, etc.)
        $result = $this->fetchFromGoogle($domain);
        if ($result !== null) {
            return $result;
        }

        // Try DuckDuckGo's icon API -- different index than Google,
        // often has favicons for Cloudflare-protected sites
        $result = $this->fetchFromDDG($domain);
        if ($result !== null) {
            return $result;
        }

        // Parse the site's HTML for <link rel="icon"> tags. This catches
        // SVG favicons, non-standard paths, and other setups that Google
        // might miss or that /favicon.ico won't find.
        $result = $this->fetchFromHtml($domain);
        if ($result !== null) {
            return $result;
        }

        // Last resort: try the classic /favicon.ico path
        return $this->fetchDirect($domain);
    }

    /**
     * Strip a domain down to just the hostname. No protocols, no paths,
     * no query strings, no funny business.
     */
    private function sanitizeDomain(string $domain): string
    {
        $domain = trim($domain);

        // If someone pasted a full URL, extract just the host
        if (preg_match('#^https?://#i', $domain)) {
            $parsed = parse_url($domain);
            $domain = $parsed['host'] ?? '';
        }

        // Strip any remaining path/query/fragment
        $domain = explode('/', $domain)[0];
        $domain = explode('?', $domain)[0];
        $domain = explode('#', $domain)[0];

        // Basic validation -- must look like a domain
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
            return '';
        }

        return strtolower($domain);
    }

    /**
     * SECURITY (SSRF guard): reject hosts that resolve to private / loopback /
     * link-local / reserved IPs (cloud metadata 169.254.169.254, 127.0.0.1,
     * 10/8, 172.16/12, 192.168/16, ::1, fc00::/7, ...). Fails closed when the
     * host cannot be resolved.
     */
    private function isBlockedHost(string $host): bool
    {
        $host = trim($host, '[]'); // strip IPv6 brackets
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ip']))   { $ips[] = $r['ip']; }
                    if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
                }
            }
            if (!$ips) {
                $resolved = @gethostbynamel($host);
                if (is_array($resolved)) { $ips = $resolved; }
            }
        }
        if (!$ips) {
            return true; // unresolvable -> fail closed
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true; // private or reserved
            }
        }
        return false;
    }

    /** URL is fetchable only if it is http(s) and its host is public. */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return !$this->isBlockedHost($parts['host']);
    }

    /**
     * cURL GET with the SSRF guard applied: HTTP(S) only (no file://, gopher://,
     * etc.), redirects followed manually so EACH hop's host is re-validated
     * against private/reserved ranges before it is fetched.
     * Returns ['body','httpCode','contentType'] or null.
     */
    private function curlGet(string $url, int $maxRedirects = 3): ?array
    {
        $redirects = 0;
        while (true) {
            if (!$this->isSafeUrl($url)) {
                return null;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false, // followed manually with per-hop validation
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TPRM-FaviconFetcher/1.0)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($httpCode >= 300 && $httpCode < 400 && $location && $redirects < $maxRedirects) {
                $url = $location;
                $redirects++;
                continue;
            }
            return ['body' => $body, 'httpCode' => $httpCode, 'contentType' => $contentType];
        }
    }

    /**
     * Fetch favicon via Google's favicon service.
     * Returns null if the request fails or the response isn't a valid image.
     */
    private function fetchFromGoogle(string $domain): ?array
    {
        $url = sprintf(self::GOOGLE_FAVICON_URL, urlencode($domain));
        return $this->fetchUrl($url);
    }

    /**
     * Fetch favicon via DuckDuckGo's icon service.
     * Good fallback when Google doesn't have a favicon indexed.
     */
    private function fetchFromDDG(string $domain): ?array
    {
        $url = sprintf(self::DDG_FAVICON_URL, urlencode($domain));
        return $this->fetchUrl($url);
    }

    /**
     * Fetch the site's HTML and look for <link rel="icon"> tags.
     * Handles rel="icon", rel="shortcut icon", and rel="apple-touch-icon".
     * Resolves relative URLs against the domain.
     */
    private function fetchFromHtml(string $domain): ?array
    {
        $baseUrl = 'https://' . $domain;
        $result = $this->curlGet($baseUrl);
        if ($result === null || $result['httpCode'] !== 200 || empty($result['body'])) {
            return null;
        }
        $html = $result['body'];

        // Only look at the <head> section to avoid matching random content
        $headEnd = stripos($html, '</head>');
        if ($headEnd !== false) {
            $html = substr($html, 0, $headEnd);
        }

        // Match <link> tags with rel containing "icon" and extract the href
        if (!preg_match_all('/<link\s[^>]*rel\s*=\s*["\'](?:shortcut\s+)?icon["\'][^>]*>/i', $html, $matches)) {
            return null;
        }

        foreach ($matches[0] as $linkTag) {
            if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $linkTag, $hrefMatch)) {
                continue;
            }

            $href = $hrefMatch[1];

            // Resolve relative URLs
            if (strpos($href, '//') === 0) {
                $href = 'https:' . $href;
            } elseif (strpos($href, 'http') !== 0) {
                $href = rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
            }

            $result = $this->fetchUrl($href);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Fetch favicon directly from the domain's /favicon.ico.
     * Returns null if the request fails or the response isn't a valid image.
     */
    private function fetchDirect(string $domain): ?array
    {
        $url = 'https://' . $domain . '/favicon.ico';
        return $this->fetchUrl($url);
    }

    /**
     * The actual cURL fetch. Grabs the URL, checks the content type,
     * validates the response looks like an image, and returns the goods.
     */
    private function fetchUrl(string $url): ?array
    {
        $result = $this->curlGet($url);
        if ($result === null) {
            return null;
        }
        $response = $result['body'];
        $httpCode = $result['httpCode'];
        $contentType = $result['contentType'];

        // Must be a successful response with actual content
        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        // Don't store absurdly large favicons
        if (strlen($response) > self::MAX_FAVICON_SIZE) {
            return null;
        }

        // Extract just the MIME type (strip charset and other parameters)
        $mime = strtolower(trim(explode(';', $contentType ?? '')[0]));

        // Validate it's actually an image type we're willing to store
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            // Some servers return generic types -- check if the binary looks like an image
            $mime = $this->detectMimeFromContent($response);
            if ($mime === null) {
                return null;
            }
        }

        return [
            'data' => $response,
            'mime' => $mime,
        ];
    }

    /**
     * Try to detect image type from the binary content when the server
     * gives us a useless Content-Type like "application/octet-stream".
     * Checks magic bytes for common image formats.
     */
    private function detectMimeFromContent(string $data): ?string
    {
        if (strlen($data) < 4) {
            return null;
        }

        // PNG: 89 50 4E 47
        if (substr($data, 0, 4) === "\x89PNG") {
            return 'image/png';
        }

        // GIF: 47 49 46 38
        if (substr($data, 0, 4) === "GIF8") {
            return 'image/gif';
        }

        // JPEG: FF D8 FF
        if (substr($data, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }

        // ICO: 00 00 01 00
        if (substr($data, 0, 4) === "\x00\x00\x01\x00") {
            return 'image/x-icon';
        }

        // WebP: RIFF....WEBP
        if (substr($data, 0, 4) === "RIFF" && substr($data, 8, 4) === "WEBP") {
            return 'image/webp';
        }

        // SVG: text-based, look for <svg tag
        if (stripos($data, '<svg') !== false) {
            return 'image/svg+xml';
        }

        return null;
    }
}
