<?php
/**
 * open-ctem client - outbound HTTPS to an admin-configured CTEM instance
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Every request FairTPRM makes to open-ctem goes through here, because the
 * CTEM base URL is admin-supplied and the request carries a bearer token:
 *
 *   - https only, no userinfo, no query or fragment in the base URL
 *   - the host is resolved once and every address is vetted: loopback,
 *     link-local (cloud metadata), multicast, unspecified and reserved ranges
 *     are always refused; private LAN ranges only when the integration is
 *     explicitly marked as an on-prem CTEM
 *   - the vetted address is pinned for the connection (CURLOPT_RESOLVE), so a
 *     second DNS answer cannot redirect the request (DNS rebinding)
 *   - no redirects, no proxy, TLS verified, hard timeout, hard size cap
 *
 * Error messages are safe to show and store: they never contain the token or
 * the response body.
 *
 * Contract FairTPRM expects from open-ctem (all under {base}/api/v1/grc):
 *   GET checks                      {"checks":[{check_type,name,description,suggested_controls}]}
 *   GET checks/{type}               {check_type,check_version,result,summary,measured_at,
 *                                    thresholds,data_freshness,details,evidence_data}
 *   GET checks/{type}/report?format=csv|json   signed body (25 MB cap)
 *   GET checks/{type}/history?from=&to=        signed body (25 MB cap)
 *   GET evidence-key                {"keys":[{"key_id","algorithm":"Ed25519","public_key":<base64 32 bytes>}]}
 * Signed bodies carry response headers
 *   X-CTEM-Signature: base64 Ed25519 detached signature of the exact raw body
 *   X-CTEM-Key-Id:    the key id that signed it
 */

class OpenCtemException extends RuntimeException {}

class OpenCtemClient {
    public const MAX_JSON_BYTES   = 1048576;    // 1 MB: check results, catalog, keys
    public const MAX_REPORT_BYTES = 26214400;   // 25 MB: reports and history
    public const CHECK_TIMEOUT    = 10;
    public const REPORT_TIMEOUT   = 60;

    private string $scheme = 'https';
    private string $host;
    private int $port;
    private string $basePath;
    private string $token;
    private bool $allowPrivate;
    private ?string $pinnedIp = null;
    private ?string $caPem = null;

    public function __construct(string $baseUrl, string $token, bool $allowPrivate = false, ?string $caPem = null) {
        [$this->host, $this->port, $this->basePath] = self::parseBaseUrl($baseUrl);
        if ($token === '' || preg_match('/[\r\n\0]/', $token)) {
            throw new OpenCtemException('CTEM API token is missing or malformed');
        }
        $this->token = $token;
        $this->allowPrivate = $allowPrivate;
        if ($caPem !== null && trim($caPem) !== '') {
            self::validateCaPem($caPem);
            $this->caPem = $caPem;
        }
    }

    /**
     * Optional private CA for an on-prem CTEM (PEM, one or more certificates).
     * TLS verification stays on; this only adds the trust anchor.
     */
    public static function validateCaPem(string $pem): void {
        if (strlen($pem) > 65536 || !preg_match('/-----BEGIN CERTIFICATE-----/', $pem)
            || !preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) {
            throw new OpenCtemException('Custom CA must be one or more PEM certificates');
        }
        foreach ($m[0] as $cert) {
            if (@openssl_x509_read($cert) === false) throw new OpenCtemException('Custom CA contains a certificate that cannot be parsed');
        }
    }

    /** Build from a grc_integrations row's decrypted credentials and endpoint_url. */
    public static function fromIntegration(array $credentials, string $endpointUrl): self {
        return new self(
            $endpointUrl,
            (string)($credentials['api_token'] ?? ''),
            !empty($credentials['allow_private']),
            isset($credentials['ca_pem']) ? (string)$credentials['ca_pem'] : null
        );
    }

    /**
     * Validate an admin-entered base URL. Returns [host, port, path].
     * Throws OpenCtemException with a message fit to show the admin.
     */
    public static function parseBaseUrl(string $url): array {
        $url = trim($url);
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || strtolower($p['scheme'] ?? '') !== 'https') {
            throw new OpenCtemException('CTEM URL must be an https:// URL');
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new OpenCtemException('CTEM URL must not contain credentials, a query or a fragment');
        }
        $host = strtolower(trim($p['host'], '[]'));
        if (!preg_match('/^[a-z0-9.\-:]+$/', $host)) {
            throw new OpenCtemException('CTEM URL host is not valid');
        }
        $port = (int)($p['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new OpenCtemException('CTEM URL port is not valid');
        }
        $path = rtrim($p['path'] ?? '', '/');
        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $path)) {
            throw new OpenCtemException('CTEM URL path is not valid');
        }
        return [$host, $port, $path];
    }

    /**
     * Is this IP an acceptable CTEM destination?
     * Always refused: loopback, link-local (169.254/16 incl. cloud metadata,
     * fe80::/10), unspecified, multicast, broadcast, 0/8, 100.64/10 (CGNAT,
     * where some clouds expose metadata), IPv4-mapped/translated IPv6.
     * Private (10/8, 172.16/12, 192.168/16, fc00::/7) only when $allowPrivate.
     */
    public static function isAllowedIp(string $ip, bool $allowPrivate): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $bin = inet_pton($ip);
        if ($bin === false) return false;

        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96): judge the embedded v4
            if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
                || substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
                return false;
            }
            $b0 = ord($bin[0]); $b1 = ord($bin[1]);
            if ($bin === str_repeat("\0", 15) . "\x01") return false;          // ::1
            if ($bin === str_repeat("\0", 16)) return false;                    // ::
            if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return false;            // fe80::/10
            if ($b0 === 0xff) return false;                                     // multicast
            if (($b0 & 0xfe) === 0xfc) return $allowPrivate;                    // fc00::/7
            if ($b0 === 0x20 && $b1 === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8) return false; // doc
            return true;
        }

        $o = array_map('ord', str_split($bin));
        if ($o[0] === 0 || $o[0] === 127) return false;                         // 0/8, loopback
        if ($o[0] === 169 && $o[1] === 254) return false;                       // link-local / metadata
        if ($o[0] === 100 && ($o[1] & 0xc0) === 64) return false;               // 100.64/10
        if ($o[0] >= 224) return false;                                         // multicast, reserved, broadcast
        if ($o[0] === 192 && $o[1] === 0 && $o[2] === 0) return false;          // 192.0.0/24
        if ($o[0] === 198 && ($o[1] & 0xfe) === 18) return false;               // 198.18/15 benchmarking
        $private = $o[0] === 10
            || ($o[0] === 172 && ($o[1] & 0xf0) === 16)
            || ($o[0] === 192 && $o[1] === 168);
        return $private ? $allowPrivate : true;
    }

    /** Resolve the host once and pick the first vetted address. */
    private function vettedIp(): string {
        if ($this->pinnedIp !== null) return $this->pinnedIp;
        if (filter_var($this->host, FILTER_VALIDATE_IP)) {
            $ips = [$this->host];
        } else {
            $ips = [];
            foreach ((@dns_get_record($this->host, DNS_A | DNS_AAAA) ?: []) as $r) {
                if (!empty($r['ip'])) $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
            if (!$ips) $ips = @gethostbynamel($this->host) ?: [];
        }
        if (!$ips) throw new OpenCtemException('CTEM host does not resolve');
        // Every answer must be acceptable: one bad record means the name is not trustworthy.
        foreach ($ips as $ip) {
            if (!self::isAllowedIp($ip, $this->allowPrivate)) {
                throw new OpenCtemException('CTEM host resolves to a disallowed address'
                    . ($this->allowPrivate ? '' : ' (enable "on-prem CTEM" for a private LAN address)'));
            }
        }
        return $this->pinnedIp = $ips[0];
    }

    /**
     * GET {base}{path}. Returns ['status' => int, 'body' => string, 'headers' => array].
     * Throws OpenCtemException on transport errors, oversize bodies and non-2xx.
     */
    public function get(string $path, array $query = [], int $maxBytes = self::MAX_JSON_BYTES,
                        int $timeout = self::CHECK_TIMEOUT, string $accept = 'application/json'): array {
        if (!preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $path)) {
            throw new OpenCtemException('Invalid CTEM path');
        }
        $ip = $this->vettedIp();
        $url = 'https://' . (strpos($this->host, ':') !== false ? '[' . $this->host . ']' : $this->host)
             . ':' . $this->port . $this->basePath . $path
             . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        $body = '';
        $headers = [];
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE         => [$this->host . ':' . $this->port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)],
            CURLOPT_PROTOCOLS_STR   => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROXY           => '',
            CURLOPT_NOPROXY         => '*',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_CONNECTTIMEOUT  => min(10, $timeout),
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_HTTPHEADER      => [
                'Authorization: Bearer ' . $this->token,
                'Accept: ' . $accept,
                'User-Agent: FairTPRM-OpenCtem/1',
            ],
            CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) { $tooBig = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($this->caPem !== null) curl_setopt($ch, CURLOPT_CAINFO_BLOB, $this->caPem);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        unset($ch);

        if ($tooBig) throw new OpenCtemException('CTEM response exceeded ' . (int)($maxBytes / 1048576) . ' MB');
        if ($ok === false || $errno) {
            // libcurl codes by number: not every CURLE_* constant exists on every PHP build.
            $why = match (true) {
                $errno === 28 => 'timed out',
                $errno === 6 => 'host could not be resolved',
                $errno === 7 => 'connection refused',
                in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => 'TLS handshake or certificate not trusted',
                default => 'transport error ' . $errno,
            };
            throw new OpenCtemException('CTEM request failed: ' . $why);
        }
        if ($status >= 300 && $status < 400) throw new OpenCtemException('CTEM answered with a redirect (HTTP ' . $status . '); redirects are not followed');
        if ($status === 401 || $status === 403) throw new OpenCtemException('CTEM rejected the API token (HTTP ' . $status . ')');
        if ($status === 404) throw new OpenCtemException('CTEM endpoint or check not found (HTTP 404)');
        if ($status < 200 || $status >= 300) throw new OpenCtemException('CTEM returned HTTP ' . $status);
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /** GET and decode a JSON object. */
    public function getJson(string $path, array $query = []): array {
        $r = $this->get($path, $query);
        $data = json_decode($r['body'], true);
        if (!is_array($data)) throw new OpenCtemException('CTEM returned a malformed JSON body');
        return $data;
    }

    public static function isValidCheckType(string $t): bool {
        return (bool)preg_match('/^ctem_[a-z0-9_]{1,60}$/', $t);
    }

    public function getCatalog(): array {
        $data = $this->getJson('/api/v1/grc/checks');
        $list = $data['checks'] ?? $data['data'] ?? $data;
        return is_array($list) ? $list : [];
    }

    public function getCheck(string $checkType): array {
        if (!self::isValidCheckType($checkType)) throw new OpenCtemException('Invalid CTEM check type');
        return $this->getJson('/api/v1/grc/checks/' . $checkType);
    }

    /** Signed report: returns ['body','signature','key_id','content_type']. */
    public function getReport(string $checkType, string $format = 'csv'): array {
        if (!self::isValidCheckType($checkType)) throw new OpenCtemException('Invalid CTEM check type');
        $format = $format === 'json' ? 'json' : 'csv';
        $r = $this->get('/api/v1/grc/checks/' . $checkType . '/report', ['format' => $format],
            self::MAX_REPORT_BYTES, self::REPORT_TIMEOUT, $format === 'json' ? 'application/json' : 'text/csv');
        return self::signedParts($r);
    }

    /** Signed daily history for a window (CTEM caps at 400 days). */
    public function getHistory(string $checkType, string $from, string $to): array {
        if (!self::isValidCheckType($checkType)) throw new OpenCtemException('Invalid CTEM check type');
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new OpenCtemException('Invalid history date');
        }
        $r = $this->get('/api/v1/grc/checks/' . $checkType . '/history', ['from' => $from, 'to' => $to],
            self::MAX_REPORT_BYTES, self::REPORT_TIMEOUT);
        return self::signedParts($r);
    }

    /** Published evidence-signing keys as key_id => raw 32-byte public key. */
    public function getEvidenceKeys(): array {
        $data = $this->getJson('/api/v1/grc/evidence-key');
        $list = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [$data];
        $keys = [];
        foreach ($list as $k) {
            if (!is_array($k)) continue;
            $kid = (string)($k['key_id'] ?? '');
            $alg = strtolower((string)($k['algorithm'] ?? 'ed25519'));
            $raw = base64_decode((string)($k['public_key'] ?? ''), true);
            if ($kid !== '' && preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $kid) && $alg === 'ed25519'
                && $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[$kid] = $raw;
            }
        }
        return $keys;
    }

    private static function signedParts(array $r): array {
        return [
            'body'         => $r['body'],
            'signature'    => (string)($r['headers']['x-ctem-signature'] ?? ''),
            'key_id'       => (string)($r['headers']['x-ctem-key-id'] ?? ''),
            'content_type' => (string)($r['headers']['content-type'] ?? ''),
        ];
    }

    /**
     * Verify a signed body against the PINNED keys (key_id => base64 public key).
     * Returns null when valid, else the reason it was refused. A key id that is
     * not pinned is refused even if CTEM currently publishes it: rotation needs
     * an admin to re-pin, otherwise pinning would be meaningless.
     */
    public static function verifySignature(string $body, string $sigB64, string $keyId, array $pinnedKeys): ?string {
        if ($sigB64 === '' || $keyId === '') return 'report is not signed';
        if (!isset($pinnedKeys[$keyId])) return 'report signed by key id "' . substr(preg_replace('/[^A-Za-z0-9._:\-]/', '', $keyId), 0, 100) . '", which is not pinned';
        $pub = base64_decode((string)$pinnedKeys[$keyId], true);
        $sig = base64_decode($sigB64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return 'pinned key is malformed';
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return 'signature is malformed';
        try {
            return sodium_crypto_sign_verify_detached($sig, $body, $pub) ? null : 'signature does not match the report';
        } catch (SodiumException $e) {
            return 'signature could not be verified';
        }
    }
}
