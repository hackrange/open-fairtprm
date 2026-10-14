<?php
/**
 * Breach Evidence Validator
 *
 * Guards the Breach / Cyber Alert pipeline against mis-attributed ("false
 * positive") alerts produced by AI breach research.
 *
 * Observed failure mode that motivated this class: the research model is handed
 * a list of monitored vendor names and a set of web-search hits. Evergreen
 * "2026 Data Breaches" style roundup pages rank for virtually every
 * "<vendor> data breach" query and are re-dated continuously, so they survive
 * the recency filter and look like credible security-industry sources. The
 * model then pairs a vendor name from our own prompt with a breach narrative
 * from the roundup and cites the roundup as proof. The second "corroborating"
 * source is frequently a bare homepage (https://www.securityweek.com/) or a
 * standing index page (CISA's advisories listing), which satisfies the
 * "2 credible source URLs" rule while carrying no evidence at all.
 *
 * The validator applies three layers:
 *
 *   1. Structural  - classify every cited URL as article / roundup / index and
 *                    require at least two independent, incident-specific
 *                    articles on distinct hosts. No network access.
 *   2. Corroborative - fetch the cited articles (SSRF-guarded) and require the
 *                    affected entity to actually be named on the page alongside
 *                    incident language. Network access; best effort.
 *   3. Memory      - refuse to re-raise a narrative a human already dismissed
 *                    as a false positive for the same entity.
 *
 * Verdicts:
 *   verified   - evidence corroborates the alert; eligible for email.
 *   unverified - evidence could not be checked (fetch blocked/failed). The
 *                alert is still recorded but is NOT emailed.
 *   rejected   - evidence contradicts or does not support the alert. The alert
 *                is not created; a row is written to cyber_breach_alert_rejections
 *                so nothing disappears silently.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

class BreachEvidenceValidator {

    public const VERDICT_VERIFIED   = 'verified';
    public const VERDICT_UNVERIFIED = 'unverified';
    public const VERDICT_REJECTED   = 'rejected';

    /** Source classes. */
    public const SRC_ARTICLE = 'article';
    public const SRC_ROUNDUP = 'roundup';
    public const SRC_INDEX   = 'index';
    public const SRC_INVALID = 'invalid';

    private Database $db;

    /** Cached app_config values for this request/process. */
    private array $settings = [];

    /** Per-process memo of fetched page text, keyed by URL. */
    private array $fetchCache = [];

    /** User-Agent for fetches; callers reading vendor sites may set their own. */
    public string $userAgent = 'FairTPRM-BreachEvidenceValidator/1.0';

    /**
     * Force HTTP/1.1. Some CDNs reset HTTP/2 streams from non-browser clients
     * ("stream was not closed cleanly"), so readers of vendor sites turn this on.
     */
    public bool $forceHttp11 = false;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: Database::getInstance();
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Read a breach-verification setting from app_config with a default.
     * Values are cached for the life of the process (one cron run).
     */
    private function setting(string $key, string $default = ''): string {
        if (!array_key_exists($key, $this->settings)) {
            try {
                $row = $this->db->fetchOne(
                    "SELECT config_value FROM app_config WHERE config_key = :k",
                    [':k' => $key]
                );
            } catch (Throwable $e) {
                $row = null;
            }
            $val = $row['config_value'] ?? null;
            $this->settings[$key] = ($val === null || $val === '') ? $default : (string)$val;
        }
        return $this->settings[$key];
    }

    private function boolSetting(string $key, bool $default): bool {
        $raw = $this->setting($key, $default ? '1' : '0');
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    public function isEnabled(): bool {
        return $this->boolSetting('breach_verify_enabled', true);
    }

    private function fetchEnabled(): bool {
        return $this->boolSetting('breach_verify_fetch', true);
    }

    /**
     * Minimum number of incident-specific, independently-hosted articles an
     * AI-generated alert must cite. Floor of 2 - a single source is not
     * corroboration, and the whole failure mode is one narrative repeated.
     */
    private function minArticles(): int {
        return max(1, min(4, (int)$this->setting('breach_verify_min_articles', '1')));
    }

    // =========================================================================
    // LAYER 1 - SOURCE CLASSIFICATION (no network)
    // =========================================================================

    /**
     * URL path fragments that mark a page as a rolling roundup / listicle
     * rather than coverage of one incident. These pages are re-dated as they
     * are appended to, so they defeat recency filtering, and they name dozens
     * of unrelated companies, so a model can "find" any vendor on them.
     *
     * Patterns are deliberately plural-anchored: "klue-data-breach-2026" is a
     * specific article, "2026-data-breaches" is a list.
     */
    private const ROUNDUP_PATTERNS = [
        '~(^|[/-])(20\d{2})-data-?breaches([/-]|$)~i',
        '~(^|[/-])data-?breaches-(20\d{2}|updated|list|latest)([/-]|$)~i',
        '~(^|[/-])(recent|latest|biggest|largest|major|notable|all|ongoing)-(data-?)?breach(es)?([/-]|$)~i',
        '~(^|[/-])(data-?)?breach(es)?-(roundup|round-up|tracker|list|digest|recap|summary)([/-]|$)~i',
        '~(^|[/-])week-?in-?breach([/-]|$)~i',
        '~(^|[/-])(weekly|monthly)-(breach|roundup|round-up|recap|review|digest)~i',
        '~(^|[/-])top-\d+-(data-?)?breach(es)?([/-]|$)~i',
        '~(^|[/-])breaches-of-the-(week|month|year)([/-]|$)~i',
        '~(^|[/-])threat-intelligence-report([/-]|$)~i',
        '~(^|[/-])intel-?feeds?([/-]|$)~i',
        '~(^|[/-])updated-list([/-]|$)~i',
        '~(^|[/-])(breach|incident)-(database|archive)([/-]|$)~i',
    ];

    /**
     * Whole-path patterns for standing index / landing pages. A homepage or a
     * section index proves nothing about a specific incident, yet it reads as a
     * "credible source" to both the model and the old count-the-URLs check.
     */
    private const INDEX_PATTERNS = [
        '~^/?$~',
        '~^/(news|newsroom|blog|blogs|articles?|advisories|alerts?|resources|research|security|insights|press|feed|rss|topics?|category|categories|tag|tags)/?$~i',
        '~^/news-events/[a-z0-9-]+/?$~i',   // cisa.gov/news-events/cybersecurity-advisories
        '~^/(en|en-us|us)/?$~i',
    ];

    /**
     * Classify a single cited URL.
     *
     * @return array{class:string, host:string, reason:string}
     */
    public function classifySource(string $url): array {
        $url = trim($url);
        if ($url === '') {
            return ['class' => self::SRC_INVALID, 'host' => '', 'reason' => 'empty URL'];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return ['class' => self::SRC_INVALID, 'host' => '', 'reason' => 'unparseable URL'];
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['class' => self::SRC_INVALID, 'host' => '', 'reason' => 'non-HTTP scheme'];
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        foreach (self::ROUNDUP_PATTERNS as $re) {
            if (preg_match($re, $path)) {
                return ['class' => self::SRC_ROUNDUP, 'host' => $host, 'reason' => 'rolling breach roundup / listicle'];
            }
        }

        foreach (self::INDEX_PATTERNS as $re) {
            if (preg_match($re, $path)) {
                return ['class' => self::SRC_INDEX, 'host' => $host, 'reason' => 'site homepage or section index'];
            }
        }

        // A real article URL carries a slug. Require either two path segments
        // or one segment that reads like a headline slug, otherwise the link is
        // too coarse to be about one incident. "/mercor-cyberattack" qualifies;
        // "/cve" and "/news" do not.
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $slugish  = false;
        foreach ($segments as $seg) {
            if ((substr_count($seg, '-') >= 1 && strlen($seg) >= 10) || strlen($seg) >= 20) { $slugish = true; break; }
        }
        if (count($segments) < 2 && !$slugish) {
            return ['class' => self::SRC_INDEX, 'host' => $host, 'reason' => 'no incident-specific path'];
        }

        return ['class' => self::SRC_ARTICLE, 'host' => $host, 'reason' => ''];
    }

    /**
     * Reduce a host to its registrable domain so news.example.com and
     * www.example.com count as one source, not two.
     */
    public function registrableDomain(string $host): string {
        $host = strtolower(trim($host, '.'));
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) return $host;

        // Handle the common two-label public suffixes we actually see in
        // security reporting (co.uk, com.au, co.in, ...). A full PSL is
        // overkill here - misgrouping only ever makes the check stricter.
        $twoLabelSuffixes = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au',
                             'co.nz', 'co.in', 'co.za', 'com.br', 'com.mx', 'co.jp', 'or.jp'];
        $lastTwo = $parts[$n - 2] . '.' . $parts[$n - 1];
        if (in_array($lastTwo, $twoLabelSuffixes, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }

    /**
     * Structural pass over an alert's cited sources.
     *
     * @return array{ok:bool, articles:array<int,string>, roundups:int, indexes:int, reason:string}
     */
    public function checkSourceStructure(array $sourceUrls): array {
        $articles = [];
        $domains  = [];
        $roundups = 0;
        $indexes  = 0;
        $invalid  = 0;

        foreach ($sourceUrls as $url) {
            if (!is_string($url)) { $invalid++; continue; }
            $c = $this->classifySource($url);
            switch ($c['class']) {
                case self::SRC_ARTICLE:
                    $dom = $this->registrableDomain($c['host']);
                    // One article per domain - two links on the same outlet are
                    // one source, and the model likes to pad with them.
                    if (!isset($domains[$dom])) {
                        $domains[$dom] = true;
                        $articles[] = $url;
                    }
                    break;
                case self::SRC_ROUNDUP: $roundups++; break;
                case self::SRC_INDEX:   $indexes++;  break;
                default:                $invalid++;  break;
            }
        }

        $need = $this->minArticles();
        if (count($articles) >= $need) {
            return ['ok' => true, 'articles' => $articles, 'roundups' => $roundups, 'indexes' => $indexes, 'reason' => ''];
        }

        $bits = [];
        if ($roundups) $bits[] = "{$roundups} rolling breach roundup/listicle page(s)";
        if ($indexes)  $bits[] = "{$indexes} homepage/section-index page(s)";
        if ($invalid)  $bits[] = "{$invalid} unusable URL(s)";
        $detail = $bits ? ' Cited instead: ' . implode(', ', $bits) . '.' : '';

        return [
            'ok'       => false,
            'articles' => $articles,
            'roundups' => $roundups,
            'indexes'  => $indexes,
            'reason'   => 'Cited ' . count($articles) . ' incident-specific article(s) on distinct outlets; '
                        . $need . ' required.' . $detail,
        ];
    }

    // =========================================================================
    // ENTITY NAME MATCHING
    // =========================================================================

    /** Corporate suffixes and filler that carry no identifying signal. */
    private const ENTITY_STOPWORDS = [
        'inc', 'inc.', 'llc', 'l.l.c', 'ltd', 'limited', 'corp', 'corporation', 'co', 'company',
        'plc', 'gmbh', 'ag', 'sa', 'nv', 'bv', 'ab', 'oy', 'as', 'pty', 'pte', 'srl', 'spa',
        'holdings', 'holding', 'group', 'the', 'and', 'of', 'software', 'technologies',
        'technology', 'tech', 'solutions', 'services', 'systems', 'systems.', 'international',
        'global', 'worldwide', 'partners', 'labs', 'digital', 'data', 'cloud', 'security',
    ];

    /**
     * Distinctive lowercase tokens for an entity name, with corporate filler
     * removed. "Onestream Software Llc" -> ['onestream'].
     * Falls back to the full normalized name when every token is filler.
     *
     * @return array<int,string>
     */
    public function entityTokens(string $entity): array {
        $norm = strtolower($entity);
        $norm = preg_replace('/[^a-z0-9]+/', ' ', $norm);
        $raw  = array_values(array_filter(explode(' ', $norm), 'strlen'));

        $tokens = [];
        foreach ($raw as $tok) {
            if (strlen($tok) < 3) continue;
            if (in_array($tok, self::ENTITY_STOPWORDS, true)) continue;
            $tokens[$tok] = true;   // deduplicated: "Empower (Empower Retirement)" is two tokens, not three
        }
        if (empty($tokens)) {
            foreach ($raw as $tok) {
                if (strlen($tok) >= 3) $tokens[$tok] = true;
            }
        }
        return array_keys($tokens);
    }

    /**
     * Which of an entity's distinctive tokens appear in $text as whole words.
     *
     * Whole-word anchoring is what separates "Sonic Boom Wellness" from a page
     * about SonicWall: "sonic" does not match inside "SonicWall". Long tokens
     * (>= 8 chars) additionally match against a whitespace-stripped copy of the
     * text so a vendor recorded as "Edenredpay" is still found on a page that
     * writes it "Edenred Pay".
     *
     * `head` reports whether the FIRST distinctive token matched. That is the
     * brand token the press actually prints - a vendor record reading
     * "SAP America, Inc." or "First Advantage Background (Formerly Sterling)"
     * appears in coverage as "SAP" and "First Advantage", so requiring a
     * majority of its tokens would reject genuine reports.
     *
     * @return array{hits:int, total:int, head:bool}
     */
    private function entityTokenHits(string $text, string $entity): array {
        $tokens = $this->entityTokens($entity);
        if (empty($tokens)) return ['hits' => 0, 'total' => 0, 'head' => false];

        $hay     = strtolower($text);
        $compact = preg_replace('/[^a-z0-9]+/', '', $hay);

        $hits = 0;
        $head = false;
        foreach ($tokens as $i => $tok) {
            $found = (bool)preg_match('/\b' . preg_quote($tok, '/') . '(s|es)?\b/i', $hay);
            if (!$found && strlen($tok) >= 8 && $compact !== '') {
                $found = strpos($compact, $tok) !== false;
            }
            if ($found) {
                $hits++;
                if ($i === 0) $head = true;
            }
        }
        return ['hits' => $hits, 'total' => count($tokens), 'head' => $head];
    }

    /**
     * Does $text name $entity at all? True when any distinctive token matches.
     * Zero hits across a page that was successfully retrieved is the
     * unambiguous mis-attribution signal.
     */
    public function entityMentioned(string $text, string $entity, bool $strict = true): bool {
        $r = $this->entityTokenHits($text, $entity);
        if ($r['total'] === 0) return false;
        return $strict ? $r['head'] : ($r['hits'] >= 1);
    }

    /**
     * Does the alert's own headline name the entity it is attributed to?
     *
     * The sharpest mis-attribution signal in the observed data: the model
     * retrieves genuine coverage of one company and files it against a
     * different, similarly-named company from our monitored list - a SonicWall
     * advisory attributed to "Sonic Boom Wellness", a "The Miller Group"
     * ransomware post attributed to "Miller Safety Consultants". Whenever that
     * happens the entity is absent from the headline the model itself wrote.
     * A genuine alert practically always leads with the affected company.
     */
    public function entityMatchesTitle(string $title, string $entity): bool {
        return $this->entityMentioned($title, $entity, false);
    }

    /** Incident language that should appear on a page genuinely covering a breach. */
    private const INCIDENT_TERMS = [
        'breach', 'breached', 'ransomware', 'cyberattack', 'cyber attack', 'hacked', 'hack',
        'data leak', 'leaked', 'compromise', 'compromised', 'unauthorized access', 'intrusion',
        'exfiltrat', 'extortion', 'incident', 'vulnerability', 'exploited', 'cve-',
    ];

    private function incidentLanguagePresent(string $text): bool {
        $hay = strtolower($text);
        foreach (self::INCIDENT_TERMS as $term) {
            if (strpos($hay, $term) !== false) return true;
        }
        return false;
    }

    // =========================================================================
    // LAYER 2 - CORROBORATION FETCH (SSRF-guarded)
    // =========================================================================

    /**
     * Resolve a hostname and confirm every address is publicly routable.
     * Private, loopback, link-local and reserved ranges are refused so a cited
     * URL can never be used to reach internal infrastructure. Returns the
     * validated address to pin, or null when the host must not be contacted.
     */
    private function resolvePublicIp(string $host): ?string {
        // A literal IP in the URL is checked directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                ? $host : null;
        }

        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $addresses = $v4;

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) $addresses[] = $rec['ipv6'];
            }
        }

        if (empty($addresses)) return null;

        // Every resolved address must be public - a host that resolves to both
        // a public and a private address is a rebinding attempt, not a source.
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        return $addresses[0];
    }

    /**
     * Fetch a cited page and return its visible text, or null if it could not
     * be retrieved. Redirects are followed manually so every hop is
     * re-validated, and the resolved address is pinned via CURLOPT_RESOLVE to
     * close the DNS-rebinding window between check and connect.
     */
    public function fetchPageText(string $url, int $maxRedirects = 3): ?string {
        if (array_key_exists($url, $this->fetchCache)) {
            return $this->fetchCache[$url];
        }

        $body = $this->fetchPageBody($url, $maxRedirects);
        if ($body === null || trim($body) === '') {
            $this->fetchCache[$url] = null;
            return null;
        }
        $text = self::htmlToText($body);

        // A near-empty body means a JS-rendered page or a bot wall: treat it as
        // "could not check", never as "entity absent".
        $this->fetchCache[$url] = (strlen($text) < 400) ? null : $text;
        return $this->fetchCache[$url];
    }

    /** Visible text of an HTML body: script/style dropped, tags stripped, entities decoded, whitespace collapsed. */
    public static function htmlToText(string $body): string {
        // Drop script/style bodies before stripping tags so JS string literals
        // can't supply a bogus entity match.
        $text = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $body);
        $text = strip_tags((string)$text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string)$text);
    }

    /**
     * Raw body of a page (HTML as served), or null. Same SSRF guards as
     * fetchPageText(): public addresses only, pinned IP, every redirect hop
     * re-validated, http/https only, 1 MB cap. $finalUrl receives the URL the
     * body was actually served from (after redirects) so relative links can be
     * resolved against it.
     */
    public function fetchPageBody(string $url, int $maxRedirects = 3, ?string &$finalUrl = null): ?string {
        $current = $url;
        $body    = null;
        $finalUrl = null;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $parts = parse_url($current);
            if (!$parts || empty($parts['host'])) break;

            $scheme = strtolower($parts['scheme'] ?? '');
            if ($scheme !== 'http' && $scheme !== 'https') break;

            $host = $parts['host'];
            $ip   = $this->resolvePublicIp($host);
            if ($ip === null) {
                error_log("BreachEvidenceValidator: refusing to fetch {$current} - host does not resolve to a public address");
                break;
            }
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $ip],
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTP_VERSION   => $this->forceHttp11 ? CURL_HTTP_VERSION_1_1 : CURL_HTTP_VERSION_NONE,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.8'],
                // Cap the download so a huge or hostile response can't exhaust memory.
                CURLOPT_BUFFERSIZE     => 16384,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dlNow) {
                    return ($dlNow > 1048576) ? 1 : 0; // abort past 1 MB
                },
            ]);

            $raw       = curl_exec($ch);
            $code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $err       = curl_error($ch);
            // No curl_close(): deprecated as a no-op since PHP 8.0, and it
            // raises a deprecation notice on the 8.5 runtime this image ships.
            unset($ch);

            if ($raw === false || $err !== '') {
                error_log("BreachEvidenceValidator: fetch failed for {$current}: {$err}");
                break;
            }

            $headers = substr((string)$raw, 0, $headerLen);
            $payload = substr((string)$raw, $headerLen);

            if ($code >= 300 && $code < 400) {
                if (preg_match('/^location:\s*(.+)$/mi', $headers, $m)) {
                    $next = trim($m[1]);
                    // Resolve a relative redirect against the current URL.
                    if (!preg_match('~^https?://~i', $next)) {
                        $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
                        $next = (strpos($next, '/') === 0) ? $base . $next : $base . '/' . ltrim($next, '/');
                    }
                    $current = $next;
                    continue;
                }
                break;
            }

            if ($code !== 200) {
                error_log("BreachEvidenceValidator: fetch for {$current} returned HTTP {$code}");
                break;
            }

            $body = $payload;
            $finalUrl = $current;
            break;
        }

        return $body;
    }

    // =========================================================================
    // LAYER 3 - FALSE-POSITIVE MEMORY
    // =========================================================================

    /** Tokens too common in breach headlines to carry narrative signal. */
    private const TITLE_STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'to', 'for', 'via', 'by', 'with',
        'at', 'from', 'is', 'was', 'as', 'its', 'after', 'amid', 'over', 'into',
    ];

    /**
     * Synonym clusters collapsed before two headlines are compared.
     *
     * The recycled narrative arrives worded differently every week - "Data
     * Breach via Exposed Developer Credentials" one run, "Unauthorized Access
     * via Exposed GitHub Personal Access Token" the next - so raw token
     * overlap between two tellings of the same story can be close to zero.
     * Folding each cluster to one symbol lets the comparison see the claim
     * instead of the wording.
     */
    private const NARRATIVE_SYNONYMS = [
        'credential' => 'CRED', 'token' => 'CRED', 'pat' => 'CRED', 'secret' => 'CRED',
        'password' => 'CRED', 'key' => 'CRED', 'apikey' => 'CRED',
        'github' => 'REPO', 'gitlab' => 'REPO', 'bitbucket' => 'REPO',
        'repo' => 'REPO', 'repository' => 'REPO', 'repositorie' => 'REPO',
        'exposed' => 'EXPOSED', 'expose' => 'EXPOSED', 'exposure' => 'EXPOSED',
        'leaked' => 'EXPOSED', 'leak' => 'EXPOSED',
        'unauthorized' => 'ACCESS', 'access' => 'ACCESS', 'intrusion' => 'ACCESS',
        'compromise' => 'ACCESS', 'compromised' => 'ACCESS',
        'breach' => 'ACCESS', 'breached' => 'ACCESS',
        'ransomware' => 'RANSOM', 'ransom' => 'RANSOM', 'extortion' => 'RANSOM',
    ];

    /**
     * Normalized significant tokens of a headline: stopwords dropped, crudely
     * singularized so "Credential" and "Credentials" collapse together, then
     * mapped through NARRATIVE_SYNONYMS.
     *
     * @return array<int,string>
     */
    private function titleTokens(string $title): array {
        $norm = strtolower($title);
        $norm = preg_replace('/[^a-z0-9]+/', ' ', $norm);
        $out  = [];
        foreach (explode(' ', (string)$norm) as $tok) {
            if (strlen($tok) < 3) continue;
            if (in_array($tok, self::TITLE_STOPWORDS, true)) continue;
            if (strlen($tok) > 4 && substr($tok, -1) === 's' && substr($tok, -2) !== 'ss') {
                $tok = substr($tok, 0, -1);
            }
            $tok = self::NARRATIVE_SYNONYMS[$tok] ?? $tok;
            $out[$tok] = true;
        }
        return array_keys($out);
    }

    /**
     * How much of the same story two headlines tell, 0-1.
     *
     * The entity name is removed from both sides first: it matches by
     * construction (we only compare headlines for the same entity) and would
     * otherwise inflate every score. What is left is the narrative -
     * "unauthorized access via exposed GitHub credential" - which is exactly
     * what the model re-proposes week after week under a different alert_type.
     *
     * Overlap coefficient rather than Jaccard, because the two headlines are
     * usually the same claim at different lengths ("...Developer Credential"
     * vs "...Credentials"), and Jaccard punishes that difference hard enough
     * to miss an obvious repeat.
     */
    public function narrativeSimilarity(string $a, string $b, string $entity = ''): float {
        $ta = $this->titleTokens($a);
        $tb = $this->titleTokens($b);
        if (empty($ta) || empty($tb)) return 0.0;

        if ($entity !== '') {
            $drop = $this->entityTokens($entity);
            $strippedA = array_values(array_diff($ta, $drop));
            $strippedB = array_values(array_diff($tb, $drop));
            // Only use the stripped forms while both still carry signal.
            if (!empty($strippedA) && !empty($strippedB)) {
                $ta = $strippedA;
                $tb = $strippedB;
            }
        }

        $inter = count(array_intersect($ta, $tb));
        $min   = min(count($ta), count($tb));
        return $min > 0 ? $inter / $min : 0.0;
    }

    /**
     * Has a human already dismissed this narrative for this entity? Marking an
     * alert as a false positive previously had no effect on future scans: the
     * dedup hash keys on entity + alert_type, so the same story came back a
     * week later under a different alert_type. This closes that loop.
     *
     * @return array{suppressed:bool, alert_id:int, title:string, similarity:float}
     */
    public function checkFalsePositiveMemory(string $entity, string $title, int $windowDays = 365): array {
        $none = ['suppressed' => false, 'alert_id' => 0, 'title' => '', 'similarity' => 0.0];
        if (!$this->boolSetting('breach_verify_suppress_fp', true)) return $none;

        $entity = trim($entity);
        if ($entity === '') return $none;

        try {
            $rows = $this->db->fetchAll(
                "SELECT id, title FROM cyber_breach_alerts
                  WHERE status = 'false_positive'
                    AND LOWER(affected_entity) = LOWER(:entity)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  ORDER BY id DESC
                  LIMIT 50",
                [':entity' => $entity, ':days' => $windowDays]
            );
        } catch (Throwable $e) {
            return $none;
        }

        $threshold = (float)$this->setting('breach_verify_fp_similarity', '0.6');
        if ($threshold <= 0 || $threshold > 1) $threshold = 0.6;

        foreach ($rows as $r) {
            $sim = $this->narrativeSimilarity($title, (string)$r['title'], $entity);
            if ($sim >= $threshold) {
                return [
                    'suppressed' => true,
                    'alert_id'   => (int)$r['id'],
                    'title'      => (string)$r['title'],
                    'similarity' => round($sim, 2),
                ];
            }
        }
        return $none;
    }

    /**
     * Entity + headline pairs a human has dismissed, for injection into the
     * research prompt so the model stops re-proposing them.
     *
     * @return array<int,array{entity:string,title:string}>
     */
    public function getDismissedNarratives(int $limit = 40): array {
        try {
            $rows = $this->db->fetchAll(
                "SELECT affected_entity, title FROM cyber_breach_alerts
                  WHERE status = 'false_positive'
                  ORDER BY id DESC
                  LIMIT :lim",
                [':lim' => (int)$limit]
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['entity' => (string)$r['affected_entity'], 'title' => (string)$r['title']];
        }
        return $out;
    }

    // =========================================================================
    // TOP-LEVEL VALIDATION
    // =========================================================================

    /**
     * Validate one candidate alert before it is written.
     *
     * @param array $alert  Parsed alert (title, affected_entity, source_urls, ...)
     * @return array{verdict:string, confidence:int, notes:string, checked_urls:array}
     */
    public function validate(array $alert): array {
        $title  = (string)($alert['title'] ?? '');
        $entity = (string)($alert['affected_entity'] ?? '');

        $sources = $alert['source_urls'] ?? [];
        if (is_string($sources)) {
            $decoded = json_decode($sources, true);
            $sources = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($sources)) $sources = [];

        // --- Layer 3: has a human already said no to this story? -------------
        $fp = $this->checkFalsePositiveMemory($entity, $title);
        if ($fp['suppressed']) {
            return [
                'verdict'      => self::VERDICT_REJECTED,
                'confidence'   => 0,
                'notes'        => sprintf(
                    'Suppressed: alert #%d for "%s" was marked a false positive by a reviewer and this headline is %d%% the same story.',
                    $fp['alert_id'], $entity, (int)round($fp['similarity'] * 100)
                ),
                'checked_urls' => [],
            ];
        }

        // --- Layer 1: structural source quality ------------------------------
        $structure = $this->checkSourceStructure($sources);
        if (!$structure['ok']) {
            return [
                'verdict'      => self::VERDICT_REJECTED,
                'confidence'   => 0,
                'notes'        => 'Insufficient evidence. ' . $structure['reason'],
                'checked_urls' => [],
            ];
        }

        // --- Coherence: does the alert's own headline name the entity? -------
        // A mismatch never rejects on its own - a vendor record can carry a
        // legal name the press never prints - but it caps the alert at
        // "unverified", so it is recorded and visible without being emailed.
        $coherent = $this->entityMatchesTitle($title, $entity);
        $coherenceNote = $coherent ? '' : sprintf(
            ' Possible mis-attribution: "%s" is not named in the alert headline ("%s"), which is the signature of a report about a similarly-named company.',
            $entity, $title
        );

        // --- Layer 2: does the cited article actually name the entity? -------
        if (!$this->fetchEnabled()) {
            return [
                'verdict'      => self::VERDICT_UNVERIFIED,
                'confidence'   => $coherent ? 50 : 25,
                'notes'        => 'Source structure passed; corroboration fetch is disabled (breach_verify_fetch=0).' . $coherenceNote,
                'checked_urls' => [],
            ];
        }

        $corroborating = [];   // page names the entity's brand token + incident language
        $unrelated     = [];   // page retrieved, entity not named on it at all
        $weak          = [];   // page names the entity only partially
        $unreachable   = [];

        foreach ($structure['articles'] as $url) {
            $text = $this->fetchPageText($url);
            if ($text === null) { $unreachable[] = $url; continue; }

            $hit = $this->entityTokenHits($text, $entity);
            if ($hit['hits'] === 0) {
                $unrelated[] = $url;
            } elseif ($hit['head'] && $this->incidentLanguagePresent($text)) {
                $corroborating[] = $url;
            } else {
                $weak[] = $url;
            }
        }

        $checked = [
            'corroborating' => $corroborating,
            'partial_match' => $weak,
            'not_matching'  => $unrelated,
            'unreachable'   => $unreachable,
        ];

        // Every article was retrieved and not one of them names the entity.
        // This is coverage of some other company's incident filed against our
        // vendor - the "SonicWall advisory attributed to Sonic Boom Wellness"
        // failure. Unambiguous, so it is rejected outright.
        if (empty($corroborating) && empty($weak) && empty($unreachable) && !empty($unrelated)) {
            return [
                'verdict'      => self::VERDICT_REJECTED,
                'confidence'   => 0,
                'notes'        => sprintf(
                    'Mis-attribution: every cited article was retrieved and none of them mentions "%s". Sources checked: %s.',
                    $entity, implode(', ', $unrelated)
                ) . $coherenceNote,
                'checked_urls' => $checked,
            ];
        }

        // Corroborated and self-consistent: safe to notify on.
        if (!empty($corroborating) && $coherent) {
            return [
                'verdict'      => self::VERDICT_VERIFIED,
                'confidence'   => min(100, 60 + 20 * count($corroborating)),
                'notes'        => sprintf(
                    '%d of %d cited article(s) name "%s" alongside incident language.',
                    count($corroborating), count($structure['articles']), $entity
                ),
                'checked_urls' => $checked,
            ];
        }

        // Anything else is recorded and shown, but never emailed: the evidence
        // is too thin to wake somebody at 3am over.
        $why = [];
        if (!$coherent)              $why[] = 'the headline does not name the entity';
        if (!empty($weak))           $why[] = count($weak) . ' article(s) mention the entity only in passing';
        if (!empty($unrelated))      $why[] = count($unrelated) . ' article(s) do not mention the entity';
        if (!empty($unreachable))    $why[] = count($unreachable) . ' article(s) could not be retrieved (paywall, bot wall, or network error)';

        return [
            'verdict'      => self::VERDICT_UNVERIFIED,
            'confidence'   => $coherent ? 40 : 20,
            'notes'        => 'Held for review, not emailed: ' . implode('; ', $why) . '.' . $coherenceNote,
            'checked_urls' => $checked,
        ];
    }
}
