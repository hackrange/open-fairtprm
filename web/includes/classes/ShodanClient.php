<?php
/**
 * Shodan API Client - Internet Intelligence for Vendor Security Scoring
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Enhanced scoring engine that evaluates 5 security categories:
 * TLS/Crypto, Network Security, Application Hardening, Vulnerability Exposure,
 * and Email Security. Scans subdomains, analyzes DNS records, inspects banners,
 * and produces a traffic-light rating (Green/Yellow/Red) alongside numeric scores.
 *
 * Scoring philosophy:
 * - Absence of signal != absence of control (don't penalize what we can't detect)
 * - Confidence tiers: banner data = high, ASN/org inference = medium, absence = low
 * - Each category starts at baseline 50, gains for positives, loses for negatives
 * - Final score = weighted average of 5 categories, clamped 0-100
 *
 * API docs: https://developer.shodan.io/api
 */

class ShodanClient
{
    private const API_BASE_URL = 'https://api.shodan.io';
    private const TIMEOUT = 30;
    private const MAX_SUBDOMAINS = 20;
    private const API_DELAY_US = 250000; // 0.25 second between API calls

    // Default category weights for final score calculation (must sum to 100)
    public const DEFAULT_CATEGORY_WEIGHTS = [
        'tls_crypto'       => 25,
        'network_security' => 20,
        'app_hardening'    => 20,
        'vuln_exposure'    => 20,
        'email_security'   => 15,
    ];

    public const CATEGORY_LABELS = [
        'tls_crypto'       => 'TLS / Cryptography',
        'network_security' => 'Network Security',
        'app_hardening'    => 'Application Hardening',
        'vuln_exposure'    => 'Vulnerability Exposure',
        'email_security'   => 'Email Security',
    ];

    private const BANNER_MAX_AGE_DAYS = 7;

    private ?array $categoryWeights = null;

    // Ports that make security people nervous
    private const HIGH_RISK_PORTS = [
        21   => 'FTP',
        23   => 'Telnet',
        135  => 'MSRPC',
        139  => 'NetBIOS',
        445  => 'SMB',
        3389 => 'RDP',
        5900 => 'VNC',
    ];

    // Database ports -- separate from high-risk for granular scoring
    private const DATABASE_PORTS = [
        1433  => 'MSSQL',
        1434  => 'MSSQL Browser',
        3306  => 'MySQL',
        5432  => 'PostgreSQL',
        6379  => 'Redis',
        27017 => 'MongoDB',
        9200  => 'Elasticsearch',
        9300  => 'Elasticsearch Transport',
    ];

    // Standard web ports -- presence of only these is a positive signal
    private const STANDARD_WEB_PORTS = [80, 443, 8080, 8443];

    // Ports to keep on CDN/WAF IPs for scoring. Only standard web ports are
    // relevant to the vendor's security posture. Cloudflare-specific ports
    // (2052, 2082, 2083, 2086, 2087, 2095, 2096, 8880) are infrastructure
    // ports open on EVERY Cloudflare IP and do not represent vendor attack
    // surface -- including them inflates open port counts dramatically.
    private const CDN_PROXIED_PORTS = [80, 443];

    // Ports never subjected to active TCP re-verification. 80/443 are the standard
    // web ports; if Shodan reported one and it is actually filtered, the only effect
    // is a missed positive signal, never a penalty, so trusting them is safe and
    // avoids hammering every scanned host with an extra connect.
    private const ALWAYS_TRUST_PORTS = [80, 443];

    // WAF/CDN detection patterns
    private const WAF_CDN_PATTERNS = [
        'headers' => [
            'cf-ray'          => 'Cloudflare',
            'x-amz-cf-id'    => 'CloudFront',
            'x-amz-cf-pop'   => 'CloudFront',
            'x-cdn'          => 'CDN',
            'x-akamai'       => 'Akamai',
            'x-sucuri-id'    => 'Sucuri',
            'x-stackpath'    => 'StackPath',
        ],
        'server' => [
            'cloudflare'      => 'Cloudflare',
            'cloudfront'      => 'Amazon CloudFront',
            'akamaighost'     => 'Akamai',
            'amazons3'        => 'AWS S3',
            'awselb'          => 'AWS ELB',
            'bigip'           => 'F5 BIG-IP',
            'imperva'         => 'Imperva',
            'incapsula'       => 'Imperva Incapsula',
            'sucuri'          => 'Sucuri',
            'stackpath'       => 'StackPath',
            'fastly'          => 'Fastly',
        ],
        'asn_org' => [
            'cloudflare'      => 'Cloudflare',
            'akamai'          => 'Akamai',
            'fastly'          => 'Fastly',
            'incapsula'       => 'Imperva',
            'imperva'         => 'Imperva',
            'sucuri'          => 'Sucuri',
        ],
    ];

    // Multi-tenant CDN / edge providers. Shodan fingerprints these shared edge
    // fleets, never the vendor's origin, so ANY CVE attributed to one of their
    // IPs is a false positive regardless of port. Deliberately EXCLUDES
    // single-tenant load balancers (awselb, bigip): those front one specific
    // origin, so their findings are kept for triage.
    private const MULTI_TENANT_CDN = [
        'cloudflare', 'cloudfront', 'akamai', 'akamaighost',
        'fastly', 'incapsula', 'imperva', 'sucuri', 'stackpath', 'amazons3',
    ];

    // Infrastructure-mitigated / version-inference CVEs. On enterprise-cloud and
    // managed-edge hosts (AWS/Azure/GCP, CDN, LB) Shodan attaches these from
    // version banners, but the provider patches or mitigates them at the platform
    // layer, or they require preconditions a managed edge does not expose. They are
    // dropped ONLY on such hosts (see keepVuln); on an unmanaged origin they are
    // kept. Genuinely origin-exploitable CVEs are NOT in this list and always
    // survive. Extend at runtime via app_config key 'shodan_infra_mitigated_cves'
    // (JSON array of CVE IDs) -- no code change needed for new HTTP/2 DoS CVEs etc.
    private const INFRA_MITIGATED_CVES = [
        // HTTP/2 protocol DoS family -- availability-only, mitigated at the edge
        'CVE-2023-44487', // HTTP/2 Rapid Reset
        'CVE-2026-49975', // HTTP/2 "Bomb" (HPACK compression bomb + flow-control hold)
        'CVE-2019-9511', 'CVE-2019-9512', 'CVE-2019-9513', 'CVE-2019-9514',
        'CVE-2019-9515', 'CVE-2019-9516', 'CVE-2019-9517', 'CVE-2019-9518',
        // Slowloris-style slow-HTTP DoS
        'CVE-2007-6750',
        // nginx resolver off-by-one -- needs non-default 'resolver' + forged DNS;
        // managed nginx patched since 2021
        'CVE-2021-23017',
        // ALPACA TLS cross-protocol confusion -- needs active MITM + confusable
        // sibling service; ALPN/SNI on managed TLS terminators defeats it
        'CVE-2021-3618',
    ];

    // Reputable certificate authorities
    private const REPUTABLE_CAS = [
        'digicert', 'globalsign', 'sectigo', 'comodo', "let's encrypt",
        'amazon', 'google trust services', 'entrust', 'godaddy',
        'microsoft', 'apple', 'cloudflare',
    ];

    // Admin panel detection patterns (product or HTML title)
    private const ADMIN_PANELS = [
        'tomcat'      => 'Apache Tomcat Manager',
        'jboss'       => 'JBoss Admin',
        'jenkins'     => 'Jenkins',
        'gitlab'      => 'GitLab',
        'phpmyadmin'  => 'phpMyAdmin',
        'webmin'      => 'Webmin',
        'cpanel'      => 'cPanel',
        'plesk'       => 'Plesk',
        'directadmin' => 'DirectAdmin',
        'wp-login'    => 'WordPress Admin',
    ];

    // Database UI detection patterns
    private const DATABASE_UIS = [
        'kibana'           => 'Kibana',
        'grafana'          => 'Grafana',
        'elasticsearch'    => 'Elasticsearch',
        'redis commander'  => 'Redis Commander',
        'redis-commander'  => 'Redis Commander',
        'mongo express'    => 'Mongo Express',
        'adminer'          => 'Adminer',
        'pgadmin'          => 'pgAdmin',
    ];

    // Enterprise email gateway MX patterns
    private const ENTERPRISE_EMAIL_GATEWAYS = [
        'proofpoint'       => 'Proofpoint',
        'pphosted'         => 'Proofpoint',
        'mimecast'         => 'Mimecast',
        'protection.outlook' => 'Microsoft EOP',
        'google'           => 'Google Workspace',
        'googlemail'       => 'Google Workspace',
        'barracuda'        => 'Barracuda',
        'messagelabs'      => 'Broadcom/Symantec',
        'iphmx'            => 'Cisco IronPort',
    ];

    // Managed DNS provider NS patterns
    private const MANAGED_DNS_PROVIDERS = [
        'awsdns'           => 'Amazon Route 53',
        'azure-dns'        => 'Azure DNS',
        'cloudflare'       => 'Cloudflare DNS',
        'googledomains'    => 'Google Cloud DNS',
        'google'           => 'Google Cloud DNS',
        'dynect'           => 'Oracle Dyn',
        'ultradns'         => 'Neustar UltraDNS',
        'nsone'            => 'NS1',
    ];

    // Residential/consumer ISP org names (medium confidence)
    private const RESIDENTIAL_ISPS = [
        'comcast', 'charter', 'spectrum', 'cox communications',
        'at&t', 'verizon fios', 'centurylink', 'frontier',
        'virgin media', 'bt ', 'sky broadband', 'talktalk',
        'deutsche telekom', 'vodafone', 'orange', 'free sas',
    ];

    // Shared hosting indicator patterns
    private const SHARED_HOSTING_PRODUCTS = [
        'cpanel', 'whm', 'plesk', 'directadmin', 'webmin',
    ];
    private const SHARED_HOSTING_PORTS = [2082, 2083, 2086, 2087, 2095, 2096];

    // Common DKIM selectors to probe
    private const DKIM_SELECTORS = [
        'default', 'selector1', 'selector2', 'google', 's1', 'k1', 'dkim', 'mail',
    ];

    // Outdated/EOL software versions (product => minimum acceptable version)
    private const OUTDATED_SOFTWARE = [
        'apache'  => '2.4',
        'nginx'   => '1.16',
        'openssl' => '1.1',
        'php'     => '8.0',
        'iis'     => '10',
    ];

    // Default/welcome page title patterns
    private const DEFAULT_PAGES = [
        'apache2 ubuntu default page',
        'apache2 debian default page',
        'apache http server test page',
        'welcome to nginx',
        'test page for the nginx',
        'iis windows server',
        'it works!',
        'index of /',
        'default web site page',
        'congratulations',
    ];

    // Enterprise cloud hosting ASN/org patterns
    private const ENTERPRISE_CLOUD = [
        'amazon'       => 'AWS',
        'aws'          => 'AWS',
        'microsoft'    => 'Azure',
        'azure'        => 'Azure',
        'google cloud' => 'GCP',
        'google llc'   => 'GCP',
        'oracle'       => 'Oracle Cloud',
        'digitalocean' => 'DigitalOcean',
        'linode'       => 'Linode/Akamai',
        'vultr'        => 'Vultr',
        'hetzner'      => 'Hetzner',
        'ovh'          => 'OVH',
    ];

    // Default signal point values for all 5 categories.
    // Positive values = bonus points, negative values = deductions.
    // These can be overridden via admin settings (stored as JSON in app_config).
    public const DEFAULT_SIGNAL_POINTS = [
        'tls_crypto' => [
            'tls_1_3_supported'   => 10,
            'strong_ciphers_only' => 8,
            'valid_certificate'   => 8,
            'reputable_ca'        => 5,
            'hsts_enabled'        => 7,
            'ocsp_stapling'       => 4,
            'pqc_ready'           => 6,
            'tls_1_0_enabled'     => -12,
            'tls_1_1_enabled'     => -8,
            'sslv2v3_enabled'     => -15,
            'weak_ciphers'        => -10,
            'expired_certificate' => -20,
            'self_signed_cert'    => -15,
            'hostname_mismatch'   => -10,
            'short_rsa_key'       => -10,
            'sha1_signature'      => -8,
        ],
        'network_security' => [
            'waf_cdn_detected'    => 15,
            'enterprise_cloud'    => 8,
            'standard_ports_only' => 7,
            'minimal_ports'       => 5,
            'no_high_risk_ports'  => 5,
            'no_db_ports'         => 5,
            'high_risk_port'      => -8,
            'db_port'             => -7,
            'shared_hosting'      => -10,
            'residential_isp'     => -15,
            'many_open_ports'     => -8,
            'direct_origin'       => -5,
        ],
        'app_hardening' => [
            'no_version_disclosure' => 10,
            'csp_present'           => 8,
            'x_frame_options'       => 5,
            'x_content_type'        => 5,
            'referrer_policy'       => 3,
            'permissions_policy'    => 3,
            'no_default_page'       => 5,
            'no_admin_panels'       => 8,
            'version_disclosure'    => -8,
            'admin_panel'           => -12,
            'db_ui'                 => -10,
            'default_page'          => -8,
            'directory_listing'     => -8,
            'swagger_exposed'       => -5,
        ],
        'vuln_exposure' => [
            'no_cves'           => 30,
            'low_cve_count'     => 10,
            'critical_cve'      => -15,
            'high_cve'          => -10,
            'medium_cve'        => -4,
            'low_cve'           => -1,
            'outdated_software' => -8,
            'no_outdated_software' => 15,
        ],
        'email_security' => [
            'spf_hard_fail'            => 12,
            'spf_soft_fail'            => -6,
            'dmarc_reject'             => 15,
            'dmarc_quarantine'         => 10,
            'dmarc_none'               => 3,
            'dkim_found'               => 10,
            'enterprise_email_gateway' => 10,
            'mta_sts'                  => 5,
            'managed_dns'              => 5,
            'no_spf'                   => -12,
            'no_dmarc'                 => -10,
            'spf_plus_all'             => -15,
            'open_smtp'                => -8,
        ],
    ];

    // Human-readable labels for signal names (used by admin UI)
    public const SIGNAL_LABELS = [
        'tls_crypto' => [
            'tls_1_3_supported'   => 'TLS 1.3 Supported',
            'strong_ciphers_only' => 'Strong Ciphers Only',
            'valid_certificate'   => 'Valid Certificate',
            'reputable_ca'        => 'Reputable Certificate Authority',
            'hsts_enabled'        => 'HSTS Enabled',
            'ocsp_stapling'       => 'OCSP Stapling',
            'pqc_ready'           => 'PQC-Ready Infrastructure (TLS 1.3 + AES-256-GCM)',
            'tls_1_0_enabled'     => 'TLS 1.0 Enabled',
            'tls_1_1_enabled'     => 'TLS 1.1 Enabled',
            'sslv2v3_enabled'     => 'SSLv2/SSLv3 Enabled',
            'weak_ciphers'        => 'Weak Ciphers Detected',
            'expired_certificate' => 'Expired Certificate',
            'self_signed_cert'    => 'Self-Signed Certificate',
            'hostname_mismatch'   => 'Certificate Hostname Mismatch',
            'short_rsa_key'       => 'Short RSA Key (<2048 bits)',
            'sha1_signature'      => 'SHA-1 Signature Algorithm',
        ],
        'network_security' => [
            'waf_cdn_detected'    => 'WAF/CDN Detected',
            'enterprise_cloud'    => 'Enterprise Cloud Hosting',
            'standard_ports_only' => 'Standard Ports Only',
            'minimal_ports'       => 'Minimal Open Ports (≤3)',
            'no_high_risk_ports'  => 'No High-Risk Ports Detected',
            'no_db_ports'         => 'No Database Ports Exposed',
            'high_risk_port'      => 'High-Risk Port (per port, max 4x)',
            'db_port'             => 'Database Port Exposed (per port, max 4x)',
            'shared_hosting'      => 'Shared Hosting Detected',
            'residential_isp'     => 'Residential ISP',
            'many_open_ports'     => 'Many Open Ports (>10)',
            'direct_origin'       => 'Direct Origin Exposure',
        ],
        'app_hardening' => [
            'no_version_disclosure' => 'No Server Version Disclosure',
            'csp_present'           => 'Content Security Policy Header',
            'x_frame_options'       => 'X-Frame-Options Header',
            'x_content_type'        => 'X-Content-Type-Options Header',
            'referrer_policy'       => 'Referrer-Policy Header',
            'permissions_policy'    => 'Permissions-Policy Header',
            'no_default_page'       => 'No Default/Welcome Pages',
            'no_admin_panels'       => 'No Admin Interfaces Exposed',
            'version_disclosure'    => 'Server Version Disclosed',
            'admin_panel'           => 'Admin Panel Exposed (per panel, max 2x)',
            'db_ui'                 => 'Database UI Exposed (per UI, max 2x)',
            'default_page'          => 'Default/Welcome Page Detected',
            'directory_listing'     => 'Directory Listing Enabled',
            'swagger_exposed'       => 'Swagger/OpenAPI Exposed',
        ],
        'vuln_exposure' => [
            'no_cves'           => 'No CVEs Detected',
            'low_cve_count'     => 'Low CVE Count (1-3, non-critical)',
            'critical_cve'      => 'Critical CVE (per CVE, max 3x)',
            'high_cve'          => 'High CVE (per CVE, max 3x)',
            'medium_cve'        => 'Medium CVE (per CVE, max 5x)',
            'low_cve'           => 'Low CVE (per CVE, max 5x)',
            'outdated_software' => 'Outdated/EOL Software (per product)',
            'no_outdated_software' => 'No Outdated Software Detected',
        ],
        'email_security' => [
            'spf_hard_fail'            => 'SPF Hard Fail (-all)',
            'spf_soft_fail'            => 'SPF Soft Fail (~all)',
            'dmarc_reject'             => 'DMARC Reject Policy',
            'dmarc_quarantine'         => 'DMARC Quarantine Policy',
            'dmarc_none'               => 'DMARC Monitor-Only (none)',
            'dkim_found'               => 'DKIM Record Found',
            'enterprise_email_gateway' => 'Enterprise Email Gateway (MX)',
            'mta_sts'                  => 'MTA-STS Present',
            'managed_dns'              => 'Managed DNS Provider',
            'no_spf'                   => 'No SPF Record',
            'no_dmarc'                 => 'No DMARC Record',
            'spf_plus_all'             => 'SPF +all (Open Relay)',
            'open_smtp'                => 'Open SMTP (Port 25)',
        ],
    ];

    private ?string $apiKey = null;
    private bool $enabled = false;
    private ?string $lastError = null;
    private ?array $signalPoints = null;
    private int $maxSubdomains = self::MAX_SUBDOMAINS;
    private bool $randomizeSubdomains = false;
    private array $excludedDomains = [];
    private int $minCveYear = 0; // 0 = use default (5 years from current year)
    private int $bannerMaxAgeDays = self::BANNER_MAX_AGE_DAYS;
    private bool $onDemandScan = false;
    private bool $verifyOpenPorts = true;   // active TCP-verify reported ports before scoring
    private bool $verifyAttribution = true; // cross-check Shodan IPs against live DNS

    public function __construct()
    {
        $this->minCveYear = (int)date('Y') - 5; // Default: exclude CVEs older than 5 years
        $this->loadConfig();
    }

    /**
     * Pulls Shodan config from the app_config table.
     */
    private function loadConfig(): void
    {
        try {
            $db = Database::getInstance();
            $encryption = new Encryption();

            $config = $db->fetchAll(
                'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
                ['shodan_%']
            );

            foreach ($config as $row) {
                $value = $row['is_encrypted'] ? $encryption->decrypt($row['config_value']) : $row['config_value'];

                switch ($row['config_key']) {
                    case 'shodan_enabled':
                        $this->enabled = ($value === '1');
                        break;
                    case 'shodan_api_key':
                        $this->apiKey = $value ?: null;
                        break;
                    case 'shodan_max_subdomains':
                        $this->maxSubdomains = max(1, min(1000, intval($value ?: self::MAX_SUBDOMAINS)));
                        break;
                    case 'shodan_randomize_subdomains':
                        $this->randomizeSubdomains = ($value === '1');
                        break;
                    case 'shodan_excluded_domains':
                        if (!empty($value)) {
                            $this->excludedDomains = array_filter(array_map(
                                fn($d) => strtolower(trim($d)),
                                preg_split('/[\r\n,]+/', $value)
                            ));
                        }
                        break;
                    case 'shodan_min_cve_year':
                        $parsed = intval($value);
                        if ($parsed > 0) {
                            $this->minCveYear = $parsed;
                        }
                        // 0 or empty keeps the default (current year - 5)
                        break;
                    case 'shodan_banner_max_age':
                        $parsed = intval($value);
                        if ($parsed > 0) {
                            $this->bannerMaxAgeDays = $parsed;
                        } elseif ($value === '0') {
                            $this->bannerMaxAgeDays = 0; // 0 = no filtering
                        }
                        break;
                    case 'shodan_on_demand_scan':
                        $this->onDemandScan = ($value === '1');
                        break;
                    case 'shodan_verify_open_ports':
                        // Default ON; only an explicit '0' disables active TCP port
                        // verification (for deployments without outbound TCP egress).
                        $this->verifyOpenPorts = ($value !== '0');
                        break;
                    case 'shodan_verify_attribution':
                        // Default ON; only an explicit '0' disables the live-DNS
                        // stale-attribution cross-check.
                        $this->verifyAttribution = ($value !== '0');
                        break;
                }
            }
        } catch (Exception $e) {
            error_log('Shodan: Failed to load config: ' . $e->getMessage());
        }
    }

    /**
     * Loads signal point configuration from app_config.
     * Falls back to DEFAULT_SIGNAL_POINTS for any missing values.
     * Cached per-request.
     */
    private function loadSignalPoints(): array
    {
        if ($this->signalPoints !== null) {
            return $this->signalPoints;
        }

        $this->signalPoints = self::DEFAULT_SIGNAL_POINTS;

        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                'SELECT config_value FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_signal_points']
            );

            if ($row && !empty($row['config_value'])) {
                $custom = json_decode($row['config_value'], true);
                if (is_array($custom)) {
                    // Merge custom values over defaults (per-category, per-signal)
                    foreach ($custom as $category => $signals) {
                        if (isset($this->signalPoints[$category]) && is_array($signals)) {
                            foreach ($signals as $signal => $points) {
                                if (array_key_exists($signal, $this->signalPoints[$category])) {
                                    $this->signalPoints[$category][$signal] = (int)$points;
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ShodanClient: Failed to load signal points config: ' . $e->getMessage());
        }

        return $this->signalPoints;
    }

    /**
     * Gets the point value for a specific signal, loading config if needed.
     */
    private function pts(string $category, string $signal): int
    {
        $config = $this->loadSignalPoints();
        return $config[$category][$signal] ?? 0;
    }

    /**
     * Public accessor for signal point values (used by ShodanService for waiver recomputation).
     */
    public function getSignalPoints(string $category, string $signal): int
    {
        return $this->pts($category, $signal);
    }

    /**
     * Loads category weights from app_config, falling back to defaults.
     * Returns weights as decimals (e.g. 0.25) for computation.
     */
    private function loadCategoryWeights(): array
    {
        if ($this->categoryWeights !== null) {
            return $this->categoryWeights;
        }

        $this->categoryWeights = self::DEFAULT_CATEGORY_WEIGHTS;

        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                'SELECT config_value FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_category_weights']
            );

            if ($row && !empty($row['config_value'])) {
                $custom = json_decode($row['config_value'], true);
                if (is_array($custom)) {
                    foreach ($custom as $category => $weight) {
                        if (array_key_exists($category, $this->categoryWeights)) {
                            $this->categoryWeights[$category] = (int)$weight;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ShodanClient: Failed to load category weights config: ' . $e->getMessage());
        }

        return $this->categoryWeights;
    }

    /**
     * Public getter for category weights. Used by admin/display pages.
     * Returns integer percentages (e.g. ['tls_crypto' => 25, ...]).
     */
    public function getCategoryWeights(): array
    {
        return $this->loadCategoryWeights();
    }

    /**
     * Are we ready to make API calls?
     */
    public function isConfigured(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    /**
     * What went wrong last time?
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Checks if a hostname matches any excluded domain (exact or suffix match).
     */
    private function isExcludedDomain(string $hostname): bool
    {
        if (empty($this->excludedDomains)) {
            return false;
        }
        $hostname = strtolower($hostname);
        foreach ($this->excludedDomains as $excluded) {
            if ($hostname === $excluded || str_ends_with($hostname, '.' . $excluded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if a CVE ID is older than the configured minimum year.
     * Returns true if the CVE should be filtered out.
     */
    private function isCveTooOld(string $cveId): bool
    {
        if ($this->minCveYear <= 0) {
            return false;
        }
        if (preg_match('/^CVE-(\d{4})-/i', $cveId, $m)) {
            return (int)$m[1] < $this->minCveYear;
        }
        return false;
    }

    /**
     * Returns a lowercase-keyed HTTP headers array for a Shodan banner.
     * Shodan does not populate banner['http']['headers'] as a dict — it only
     * exposes a headers_hash. The raw HTTP response (status line + headers +
     * body) lives in the top-level banner['data'] field as plain text, so we
     * parse it out of there. Falls back to banner['http']['headers'] if ever
     * populated (defensive).
     */
    private function extractHttpHeaders(array $banner): array
    {
        $existing = $banner['http']['headers'] ?? null;
        if (is_array($existing) && !empty($existing)) {
            return array_change_key_case($existing, CASE_LOWER);
        }
        $raw = $banner['data'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $split = preg_split("/\r?\n\r?\n/", $raw, 2);
        $headerBlock = $split[0] ?? '';
        if ($headerBlock === '') {
            return [];
        }
        $lines = preg_split("/\r?\n/", $headerBlock);
        $headers = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && preg_match('#^HTTP/\d#', $line)) {
                continue; // skip status line
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                if (is_array($headers[$name])) {
                    $headers[$name][] = $value;
                } else {
                    $headers[$name] = [$headers[$name], $value];
                }
            } else {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Actively probes HTTPS subdomains in parallel with real SNI/Host headers to
     * capture security-header posture that Shodan's IP-indexed passive scan misses.
     *
     * Shodan probes by IP without SNI, so CDN/WAF edges (Cloudflare, Fastly, ELB)
     * return 400/403/301 defaults that don't carry HSTS/CSP/XFO/etc. This method
     * fires parallel HEAD requests (curl_multi) to each hostname and returns
     * synthetic banner records that downstream analyzers (analyzeTlsCrypto,
     * analyzeAppHardening) will treat alongside real Shodan banners.
     *
     * @param string[] $hostnames          Hostnames to probe.
     * @param array    $hostnameToIp       Map of hostname => IP (for proof/context).
     * @return array[] Synthetic banner records (port=443, _active_probe=true, data=raw HTTP response).
     */
    private function probeActiveHeaders(array $hostnames, array $hostnameToIp): array
    {
        if (empty($hostnames) || !function_exists('curl_multi_init')) {
            return [];
        }

        // Filter exclusions + dedupe + cap to maxSubdomains to bound work
        $hostnames = array_values(array_unique(array_filter(
            $hostnames,
            fn($h) => !empty($h) && !$this->isExcludedDomain($h)
        )));
        if ($this->maxSubdomains > 0) {
            $hostnames = array_slice($hostnames, 0, $this->maxSubdomains);
        }
        if (empty($hostnames)) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($hostnames as $hostname) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => "https://{$hostname}/",
                CURLOPT_NOBODY         => true,   // HEAD
                CURLOPT_HEADER         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FairTPRM-Probe/1.0)',
            ]);
            $handles[$hostname] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        $running = null;
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0);

        $results = [];
        foreach ($handles as $hostname => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);

            if ($httpCode > 0 && is_string($response) && $response !== '') {
                $ip = $hostnameToIp[$hostname] ?? '';
                $results[] = [
                    'port'           => 443,
                    'transport'      => 'tcp',
                    '_ip'            => $ip,
                    '_hostnames'     => [$hostname],
                    '_active_probe'  => true,
                    'data'           => $response,
                    'http'           => [],
                    'timestamp'      => date('c'),
                ];
            }
        }
        curl_multi_close($multi);

        if (!empty($results)) {
            error_log("Shodan: Active HTTPS probe captured " . count($results) . " of " . count($hostnames) . " hostnames");
        }
        return $results;
    }

    /**
     * Low-level HTTP GET request to the Shodan API.
     */
    private function request(string $endpoint, array $params = []): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'Shodan integration is not configured';
            return null;
        }

        $params['key'] = $this->apiKey;
        $url = self::API_BASE_URL . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError) {
            $this->lastError = 'cURL error: ' . $curlError;
            error_log('Shodan API error: ' . $curlError);
            return null;
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->lastError = $data['error'] ?? "HTTP error $httpCode";
            error_log("Shodan API error ($httpCode): " . json_encode($data));
            return null;
        }

        return $data;
    }

    /**
     * Low-level HTTP POST request to the Shodan API.
     */
    private function postRequest(string $endpoint, array $params = []): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'Shodan integration is not configured';
            return null;
        }

        $url = self::API_BASE_URL . $endpoint . '?key=' . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError) {
            $this->lastError = 'cURL error: ' . $curlError;
            error_log('Shodan API error: ' . $curlError);
            return null;
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->lastError = $data['error'] ?? "HTTP error $httpCode";
            error_log("Shodan API error ($httpCode): " . json_encode($data));
            return null;
        }

        return $data;
    }

    /**
     * Request an on-demand rescan of a specific IP address.
     *
     * Uses Shodan's /shodan/scan endpoint which requires scan credits.
     * Returns the scan ID if successful, or null on failure.
     *
     * @param string $ip IP address to rescan
     * @return array ['success' => bool, 'message' => string, 'scan_id' => string|null]
     */
    public function requestRescan(string $ip): array
    {
        return $this->requestRescanBatch([$ip]);
    }

    /**
     * Request Shodan to rescan one or more IPs in a single API call.
     * Shodan's /shodan/scan endpoint accepts comma-separated IPs.
     *
     * @param array $ips Array of IP address strings
     * @return array {success: bool, message: string, scan_id: string|null}
     */
    public function requestRescanBatch(array $ips): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Shodan integration is not configured', 'scan_id' => null];
        }

        // Validate and filter IPs
        $validIps = array_filter($ips, function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP);
        });

        if (empty($validIps)) {
            return ['success' => false, 'message' => 'No valid IP addresses provided', 'scan_id' => null];
        }

        // Shodan /shodan/scan accepts comma-separated IPs in a single request
        $ipList = implode(',', $validIps);
        $result = $this->postRequest('/shodan/scan', ['ips' => $ipList]);

        if ($result === null) {
            return ['success' => false, 'message' => $this->lastError ?? 'Rescan request failed', 'scan_id' => null];
        }

        // Shodan returns: {"id": "scan_id", "count": N, "credits_left": N}
        if (!empty($result['id'])) {
            $count = $result['count'] ?? count($validIps);
            $creditsLeft = $result['credits_left'] ?? 'unknown';
            return [
                'success' => true,
                'message' => "Rescan initiated for {$count} IP(s) (scan ID: {$result['id']}). Credits remaining: {$creditsLeft}. Results typically available within 1-5 minutes.",
                'scan_id' => $result['id']
            ];
        }

        return ['success' => false, 'message' => 'Unexpected response from Shodan API', 'scan_id' => null];
    }

    /**
     * Request on-demand scans for multiple IPs in a single API call.
     * Shodan's /shodan/scan endpoint accepts comma-separated IPs.
     *
     * @param array $ips Array of IP address strings
     * @return array ['success' => bool, 'message' => string, 'scan_id' => string|null, 'credits_left' => int|null]
     */
    public function requestScanMultiple(array $ips): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Shodan integration is not configured', 'scan_id' => null, 'credits_left' => null];
        }

        $validIps = array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP));
        if (empty($validIps)) {
            return ['success' => false, 'message' => 'No valid IP addresses provided', 'scan_id' => null, 'credits_left' => null];
        }

        $ipList = implode(',', $validIps);
        $result = $this->postRequest('/shodan/scan', ['ips' => $ipList]);

        if ($result === null) {
            return ['success' => false, 'message' => $this->lastError ?? 'On-demand scan request failed', 'scan_id' => null, 'credits_left' => null];
        }

        if (!empty($result['id'])) {
            $creditsLeft = $result['credits_left'] ?? null;
            $count = count($validIps);
            error_log("Shodan: On-demand scan initiated for {$count} IP(s) (scan ID: {$result['id']}, credits left: " . ($creditsLeft ?? 'unknown') . ")");
            return [
                'success' => true,
                'message' => "On-demand scan initiated for {$count} IP(s) (scan ID: {$result['id']}). Credits remaining: " . ($creditsLeft ?? 'unknown') . ".",
                'scan_id' => $result['id'],
                'credits_left' => $creditsLeft,
            ];
        }

        return ['success' => false, 'message' => 'Unexpected response from Shodan API', 'scan_id' => null, 'credits_left' => null];
    }

    /**
     * Whether on-demand scanning is enabled.
     */
    public function isOnDemandScanEnabled(): bool
    {
        return $this->onDemandScan;
    }

    /**
     * Override on-demand scan setting for this request (ad-hoc usage).
     */
    public function setOnDemandScan(bool $enabled): void
    {
        $this->onDemandScan = $enabled;
    }

    /** Enable/disable active TCP verification of reported open ports. */
    public function setVerifyOpenPorts(bool $enabled): void
    {
        $this->verifyOpenPorts = $enabled;
    }

    /** Enable/disable cross-checking Shodan-attributed IPs against live authoritative DNS. */
    public function setVerifyAttribution(bool $enabled): void
    {
        $this->verifyAttribution = $enabled;
    }

    /**
     * Current authoritative A/AAAA addresses for a hostname, following CNAME chains
     * (what `dig` returns). Used to catch stale Shodan attributions: Shodan's cached
     * /dns/resolve can point a host at an IP it no longer uses. Returns [] on lookup
     * failure so callers can fail OPEN and never drop data on a transient DNS error.
     */
    private function liveResolve(string $hostname): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $recs = @dns_get_record($hostname, $type);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (!empty($r['ip']))   { $ips[] = $r['ip']; }
                    if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
                }
            }
        }
        if (empty($ips)) {
            $a = @gethostbynamel($hostname);   // all A records, follows CNAME
            if (is_array($a)) {
                $ips = array_merge($ips, $a);
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Active TCP verification of reported ports (nmap -sT equivalent). Shodan's port
     * data is historical and vantage-dependent, so it can report a port as open when
     * it is currently FILTERED (no handshake) or closed. Confirm each port with a real,
     * concurrent, non-blocking TCP connect and return only those that complete the
     * handshake. Anything still pending at the deadline is treated as filtered/closed.
     */
    private function filterOpenPorts(array $ports, float $timeout = 2.0): array
    {
        $pending = []; // "ip:port" => ['sock'=>resource, 'data'=>portData]
        foreach ($ports as $pd) {
            $ip   = (string) ($pd['ip'] ?? '');
            $port = (int) ($pd['port'] ?? 0);
            if ($ip === '' || $port < 1 || $port > 65535) {
                continue;
            }
            $host = (strpos($ip, ':') !== false) ? '[' . $ip . ']' : $ip; // bracket IPv6
            $errno = 0; $errstr = '';
            $sock = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
            );
            if ($sock !== false) {
                $pending[$ip . ':' . $port] = ['sock' => $sock, 'data' => $pd];
            }
        }

        $open     = [];
        $deadline = microtime(true) + $timeout;
        while (!empty($pending) && microtime(true) < $deadline) {
            $write = [];
            foreach ($pending as $k => $p) {
                $write[$k] = $p['sock'];
            }
            $read = null; $except = null; $wsel = $write;
            $n = @stream_select($read, $wsel, $except, 0, 200000); // 0.2s slices
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                continue; // nothing settled yet; loop until the deadline
            }
            foreach ($pending as $k => $p) {
                $ready = false;
                foreach ($wsel as $ws) {
                    if ($ws === $p['sock']) { $ready = true; break; }
                }
                if (!$ready) {
                    continue;
                }
                // A settled async connect is writable; a real peer name means it connected.
                if (@stream_socket_get_name($p['sock'], true) !== false) {
                    $open[$k] = $p['data'];
                }
                @fclose($p['sock']);
                unset($pending[$k]);
            }
        }
        // Anything still pending timed out => filtered => not open.
        foreach ($pending as $p) {
            @fclose($p['sock']);
        }
        return array_values($open);
    }

    /**
     * Verifies the API key is valid by calling /api-info.
     */
    public function testConnection(): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'Shodan integration is disabled'];
        }

        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'API key is not configured'];
        }

        $result = $this->request('/api-info');

        if ($result === null) {
            return ['success' => false, 'message' => $this->lastError ?? 'API request failed'];
        }

        $plan = $result['plan'] ?? 'unknown';
        return ['success' => true, 'message' => "Connection successful (Plan: $plan)"];
    }

    /**
     * Resolves hostnames to IP addresses using Shodan's DNS endpoint.
     * Accepts comma-separated hostnames, returns [hostname => ip] map.
     */
    public function resolveDomain(string $domain): array
    {
        $result = $this->request('/dns/resolve', ['hostnames' => $domain]);

        if ($result === null || empty($result[$domain])) {
            // Fallback to native PHP DNS when Shodan can't resolve
            $ip = @gethostbyname($domain);
            if ($ip !== $domain) {
                return [$ip];
            }
            return [];
        }

        $ip = $result[$domain];
        return is_array($ip) ? $ip : [$ip];
    }

    /**
     * Resolves multiple hostnames to IPs in a single API call.
     * Returns [hostname => ip] map.
     */
    private function resolveMultiple(array $hostnames): array
    {
        if (empty($hostnames)) {
            return [];
        }

        // Shodan DNS resolve accepts comma-separated hostnames
        $hostnameStr = implode(',', $hostnames);
        $result = $this->request('/dns/resolve', ['hostnames' => $hostnameStr]);

        if ($result === null) {
            $result = [];
        }

        // Fallback to native PHP DNS for any hostnames Shodan couldn't resolve
        foreach ($hostnames as $hostname) {
            if (empty($result[$hostname])) {
                $ip = @gethostbyname($hostname);
                if ($ip !== $hostname) {
                    $result[$hostname] = $ip;
                }
            }
        }

        return $result;
    }

    /**
     * Gets host information from Shodan for a single IP address.
     */
    public function getHostInfo(string $ip): ?array
    {
        return $this->request("/shodan/host/{$ip}");
    }

    /**
     * Discovers subdomains for a domain using Shodan's DNS domain endpoint.
     * Returns array of subdomain strings, capped at MAX_SUBDOMAINS.
     */
    public function getSubdomains(string $domain): array
    {
        $result = $this->request("/dns/domain/{$domain}");

        if ($result === null || empty($result['subdomains'])) {
            return [];
        }

        $subdomains = [];
        foreach ($result['subdomains'] as $sub) {
            // Skip wildcards
            if ($sub === '*' || strpos($sub, '*') !== false) {
                continue;
            }
            $subdomains[] = $sub . '.' . $domain;
        }

        $subdomains = array_values(array_unique($subdomains));

        // Filter out excluded domains
        if (!empty($this->excludedDomains)) {
            $subdomains = array_values(array_filter($subdomains, fn($sub) => !$this->isExcludedDomain($sub)));
        }

        // Randomize selection if configured (gives broader coverage over repeated scans)
        if ($this->randomizeSubdomains) {
            shuffle($subdomains);
        }

        // Cap to manage API budget
        return array_slice($subdomains, 0, $this->maxSubdomains);
    }

    /**
     * Analyzes DNS records for email security and infrastructure signals.
     * Uses PHP dns_get_record() -- no Shodan API credits consumed.
     */
    public function analyzeDNS(string $domain): array
    {
        $result = [
            'spf' => null,
            'dmarc' => null,
            'dkim_found' => false,
            'dkim_selector' => null,
            'mx_records' => [],
            'ns_records' => [],
            'has_mta_sts' => false,
        ];

        // Query TXT records for SPF
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                $txt = $record['txt'] ?? '';
                if (stripos($txt, 'v=spf1') === 0) {
                    $result['spf'] = $txt;
                }
            }
        }

        // Query DMARC
        $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
        if ($dmarcRecords) {
            foreach ($dmarcRecords as $record) {
                $txt = $record['txt'] ?? '';
                if (stripos($txt, 'v=DMARC1') === 0) {
                    $result['dmarc'] = $txt;
                }
            }
        }

        // Check DKIM with common selectors
        foreach (self::DKIM_SELECTORS as $selector) {
            $dkimRecords = @dns_get_record($selector . '._domainkey.' . $domain, DNS_TXT);
            if ($dkimRecords && !empty($dkimRecords)) {
                foreach ($dkimRecords as $record) {
                    $txt = $record['txt'] ?? '';
                    if (stripos($txt, 'v=DKIM1') !== false || stripos($txt, 'p=') !== false) {
                        $result['dkim_found'] = true;
                        $result['dkim_selector'] = $selector;
                        break 2;
                    }
                }
            }
        }

        // Query MX records
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if ($mxRecords) {
            foreach ($mxRecords as $record) {
                $result['mx_records'][] = $record['target'] ?? '';
            }
        }

        // Query NS records
        $nsRecords = @dns_get_record($domain, DNS_NS);
        if ($nsRecords) {
            foreach ($nsRecords as $record) {
                $result['ns_records'][] = $record['target'] ?? '';
            }
        }

        // Check MTA-STS
        $mtaStsRecords = @dns_get_record('_mta-sts.' . $domain, DNS_TXT);
        if ($mtaStsRecords) {
            foreach ($mtaStsRecords as $record) {
                $txt = $record['txt'] ?? '';
                if (stripos($txt, 'v=STSv1') !== false) {
                    $result['has_mta_sts'] = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * The main scoring engine -- scores a vendor's domain by:
     * 1. Discovering subdomains
     * 2. Resolving all hostnames to unique IPs
     * 3. Querying Shodan for each IP
     * 4. Running DNS analysis
     * 5. Running 5 category analyzers
     * 6. Computing weighted final score + traffic light
     */
    public function scoreVendor(string $domain): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'Shodan integration is not configured';
            return null;
        }

        $domain = strtolower(trim($domain));

        // Check if the primary domain itself is excluded
        if ($this->isExcludedDomain($domain)) {
            $this->lastError = "Domain is in the excluded domains list: $domain";
            return null;
        }

        // Step 1: Discover subdomains (already filters excluded domains)
        $subdomains = $this->getSubdomains($domain);

        // Combine main domain + subdomains for resolution, then filter exclusions
        $allHostnames = array_unique(array_merge([$domain], $subdomains));
        if (!empty($this->excludedDomains)) {
            $allHostnames = array_values(array_filter($allHostnames, fn($h) => !$this->isExcludedDomain($h)));
            if (empty($allHostnames)) {
                $this->lastError = "All hostnames for $domain are excluded";
                return null;
            }
        }

        // Step 2: Resolve all hostnames to IPs
        $hostnameToIp = [];
        $ipToHostnames = [];

        // Resolve in batches (Shodan allows comma-separated)
        foreach (array_chunk($allHostnames, 10) as $batchIndex => $batch) {
            if ($batchIndex > 0) {
                usleep(self::API_DELAY_US);
            }
            $resolved = $this->resolveMultiple($batch);
            foreach ($resolved as $hostname => $ip) {
                if (!empty($ip)) {
                    $hostnameToIp[$hostname] = $ip;
                    $ipToHostnames[$ip][] = $hostname;
                }
            }
        }

        // Drop RFC1918 / reserved / loopback / link-local IPs. They are not
        // routable from the Internet, so no remotely-exploitable finding can
        // attach to them. Shodan occasionally returns these from stale or
        // split-horizon DNS records.
        foreach ($hostnameToIp as $hn => $ip) {
            if (!$this->isPublicIp($ip)) {
                unset($hostnameToIp[$hn], $ipToHostnames[$ip]);
                error_log("Shodan: Dropped non-public IP {$ip} ({$hn}) for {$domain}");
            }
        }

        // Attribution helper: Shodan's /dns/resolve is cached and can attribute an IP a
        // host no longer points to (e.g. after a move to Oracle Cloud WAAS / Cloudflare).
        // Cross-check each attribution against LIVE authoritative DNS and drop stale ones
        // -- but only on a positive contradiction (host resolves, and the IP isn't in the
        // live set); a failed/empty lookup keeps the data, so a DNS hiccup never nukes a scan.
        if ($this->verifyAttribution && !empty($hostnameToIp)) {
            // Bound total DNS time, but SCALE the budget to the host count. A flat 8s cap
            // silently skipped every host past ~#46 on a 100-host domain (measured ~0.18s
            // per live lookup), so late-listed stale attributions -- e.g. a Shodan-cached
            // Oracle WAAS edge on a subdomain near the end of the list -- survived unchecked.
            $numHosts = count($hostnameToIp);
            $deadline = microtime(true) + max(10.0, min(35.0, $numHosts * 0.30));
            // Fail fast on dead subdomains (NXDOMAIN) so they don't starve the budget.
            $prevRes = getenv('RES_OPTIONS');
            @putenv('RES_OPTIONS=timeout:2 attempts:1');
            $liveCache = [];
            $checked   = 0;
            foreach ($hostnameToIp as $hn => $ip) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $checked++;
                if (!array_key_exists($hn, $liveCache)) {
                    $liveCache[$hn] = $this->liveResolve($hn);
                }
                $live = $liveCache[$hn];
                if (!empty($live) && !in_array($ip, $live, true)) {
                    unset($hostnameToIp[$hn]);
                    if (isset($ipToHostnames[$ip])) {
                        $ipToHostnames[$ip] = array_values(array_diff($ipToHostnames[$ip], [$hn]));
                        if (empty($ipToHostnames[$ip])) {
                            unset($ipToHostnames[$ip]);
                        }
                    }
                    error_log("Shodan attribution: dropped stale IP {$ip} for {$hn} (live: " . implode(',', $live) . ")");
                }
            }
            // Restore the resolver options for the rest of the process.
            @putenv($prevRes === false ? 'RES_OPTIONS' : "RES_OPTIONS={$prevRes}");
            // Never skip silently: if the budget ran out, say how many hosts went unchecked.
            if ($checked < $numHosts) {
                error_log("Shodan attribution: verified {$checked}/{$numHosts} hosts for {$domain}; "
                    . ($numHosts - $checked) . " unverified (DNS budget exhausted)");
            }
        }

        $uniqueIps = array_unique(array_values($hostnameToIp));

        if (empty($uniqueIps)) {
            $this->lastError = "Could not resolve domain: $domain";
            return null;
        }

        // Cap IPs to match the configured max subdomains setting
        $uniqueIps = array_slice($uniqueIps, 0, $this->maxSubdomains);

        // Step 2b: On-demand scan -- request Shodan to scan IPs that have no host data.
        // Shodan may not have scanned these IPs before, so we trigger a scan first,
        // wait briefly for results, then proceed with host queries as normal.
        if ($this->onDemandScan) {
            // Check which IPs Shodan already has data for
            $ipsNeedingScan = [];
            foreach ($uniqueIps as $ip) {
                $hostInfo = $this->getHostInfo($ip);
                if ($hostInfo === null) {
                    $ipsNeedingScan[] = $ip;
                }
                usleep(self::API_DELAY_US);
            }

            if (!empty($ipsNeedingScan)) {
                $scanResult = $this->requestScanMultiple($ipsNeedingScan);
                if ($scanResult['success']) {
                    // Wait for Shodan to process the scan. The /shodan/scan endpoint
                    // queues scans that typically complete within 1-5 minutes. We poll
                    // for up to 3 minutes with increasing intervals.
                    $scanId = $scanResult['scan_id'];
                    $maxWaitSeconds = 180;
                    $elapsed = 0;
                    $pollIntervals = [10, 10, 15, 15, 20, 20, 30, 30, 30];
                    $pollIndex = 0;

                    error_log("Shodan: Waiting for on-demand scan {$scanId} to complete (up to {$maxWaitSeconds}s)...");

                    while ($elapsed < $maxWaitSeconds) {
                        $sleepTime = $pollIntervals[$pollIndex] ?? 30;
                        sleep($sleepTime);
                        $elapsed += $sleepTime;
                        $pollIndex++;

                        // Check scan status via /shodan/scan/{id}
                        $status = $this->request("/shodan/scan/{$scanId}");
                        $scanStatus = $status['status'] ?? 'UNKNOWN';

                        if ($scanStatus === 'DONE') {
                            error_log("Shodan: On-demand scan {$scanId} completed after {$elapsed}s");
                            break;
                        }

                        error_log("Shodan: On-demand scan {$scanId} status: {$scanStatus} ({$elapsed}s elapsed)");
                    }

                    if ($elapsed >= $maxWaitSeconds) {
                        error_log("Shodan: On-demand scan {$scanId} did not complete within {$maxWaitSeconds}s, proceeding with available data");
                    }
                } else {
                    error_log("Shodan: On-demand scan request failed: " . $scanResult['message']);
                }
            }
        }

        // Step 3: Query each IP for host info
        $allPorts = [];
        $allVulns = [];
        $allServices = [];
        $allBanners = [];
        $hostResults = [];

        foreach ($uniqueIps as $ip) {
            if (!empty($hostResults)) {
                usleep(self::API_DELAY_US);
            }

            $hostInfo = $this->getHostInfo($ip);

            if ($hostInfo === null) {
                // Shodan might not have data for this IP -- skip
                continue;
            }

            $hostResults[$ip] = $hostInfo;

            // Determine subdomain(s) for this IP
            $hostSubdomains = $ipToHostnames[$ip] ?? [$domain];
            $primarySubdomain = $hostSubdomains[0] ?? $domain;

            // Extract open ports
            if (isset($hostInfo['ports']) && is_array($hostInfo['ports'])) {
                foreach ($hostInfo['ports'] as $port) {
                    $portKey = $ip . ':' . $port;
                    $allPorts[$portKey] = [
                        'ip' => $ip,
                        'port' => $port,
                        'is_high_risk' => isset(self::HIGH_RISK_PORTS[$port]),
                        'is_database' => isset(self::DATABASE_PORTS[$port]),
                        'subdomain' => $primarySubdomain,
                    ];
                }
            }

            // Extract vulnerabilities from host-level
            if (isset($hostInfo['vulns']) && is_array($hostInfo['vulns'])) {
                foreach ($hostInfo['vulns'] as $cve) {
                    if (!isset($allVulns[$cve])) {
                        $allVulns[$cve] = [
                            'cve_id' => $cve,
                            'ip' => $ip,
                            'cvss' => null,
                            'severity' => 'unknown',
                            'subdomain' => $primarySubdomain,
                            // Host-level `vulns` is a bare CVE-ID list with no
                            // confirmation detail, so treat as version-inferred
                            // until a banner-level entry (which carries Shodan's
                            // `verified` flag) overwrites it below.
                            'verified' => false,
                        ];
                    }
                }
            }

            // Extract services and banners from data array
            if (isset($hostInfo['data']) && is_array($hostInfo['data'])) {
                foreach ($hostInfo['data'] as $banner) {
                    // Skip banners older than configured max age -- Shodan keeps historical
                    // data for ports that may now be filtered/closed.
                    if ($this->bannerMaxAgeDays > 0) {
                        $bannerTs = $banner['timestamp'] ?? '';
                        if (!empty($bannerTs)) {
                            $bannerAge = time() - strtotime($bannerTs);
                            if ($bannerAge > $this->bannerMaxAgeDays * 86400) {
                                continue;
                            }
                        }
                    }

                    $port = $banner['port'] ?? 0;
                    $serviceName = $banner['product'] ?? $banner['_shodan']['module'] ?? 'unknown';
                    $transport = $banner['transport'] ?? 'tcp';

                    $allServices[] = [
                        'ip' => $ip,
                        'port' => $port,
                        'protocol' => $transport,
                        'service_name' => $serviceName,
                        'version' => $banner['version'] ?? null,
                        'subdomain' => $primarySubdomain,
                    ];

                    // Store full banner for category analysis
                    $banner['_ip'] = $ip;
                    $banner['_hostnames'] = $ipToHostnames[$ip] ?? [$domain];
                    $allBanners[] = $banner;

                    // Extract vulns from individual banners
                    if (isset($banner['vulns']) && is_array($banner['vulns'])) {
                        foreach ($banner['vulns'] as $cve => $vulnData) {
                            $cvss = null;
                            if (is_array($vulnData) && isset($vulnData['cvss'])) {
                                $cvss = (float)$vulnData['cvss'];
                            }

                            $allVulns[$cve] = [
                                'cve_id' => $cve,
                                'ip' => $ip,
                                'port' => $port,
                                'cvss' => $cvss,
                                'severity' => $this->cvssToSeverity($cvss),
                                'subdomain' => $primarySubdomain,
                                // Shodan sets `verified` true only when a check
                                // module actively confirmed the vuln; the common
                                // case (false/absent) is pure version-banner
                                // inference and a frequent false-positive source.
                                'verified' => (is_array($vulnData) && !empty($vulnData['verified'])),
                            ];
                        }
                    }
                }
            }
        }

        // Remove ports from $allPorts that have no fresh banners
        if ($this->bannerMaxAgeDays > 0) {
            $freshPortKeys = [];
            foreach ($allServices as $svc) {
                $freshPortKeys[$svc['ip'] . ':' . $svc['port']] = true;
            }
            $allPorts = array_filter($allPorts, function ($portData) use ($freshPortKeys) {
                return isset($freshPortKeys[$portData['ip'] . ':' . $portData['port']]);
            });
        }

        // Step 3b: Filter out non-proxied ports on CDN/WAF IPs.
        // CDN IPs (e.g. Cloudflare) only proxy web traffic; other ports Shodan reports
        // are actually filtered/unreachable (confirmed by nmap). Remove them so they
        // don't inflate open port counts or trigger false-positive findings.
        $cdnIps = [];
        $multiTenantCdnIps = [];
        $managedInfraIps = [];  // enterprise-cloud OR CDN/WAF/LB -- where curated CVEs are mitigated
        foreach ($hostResults as $ip => $hostInfo) {
            $isCdn = $this->isCdnIp($hostInfo);
            if ($isCdn) {
                $cdnIps[$ip] = true;
            }
            if ($this->isMultiTenantCdnIp($hostInfo)) {
                $multiTenantCdnIps[$ip] = true;
            }
            if ($isCdn || $this->isEnterpriseCloudIp($hostInfo)) {
                $managedInfraIps[$ip] = true;
            }
        }

        // Check if ALL IPs are behind CDN/WAF (e.g., fully proxied through Cloudflare).
        // Used to skip email security findings since CDN providers don't handle email.
        $allIpsBehindCdn = !empty($hostResults) && !empty($cdnIps) && count($cdnIps) === count($hostResults);

        if (!empty($cdnIps)) {
            // Filter ports: keep only CDN-proxied ports for CDN IPs
            $allPorts = array_filter($allPorts, function ($portData) use ($cdnIps) {
                if (isset($cdnIps[$portData['ip']])) {
                    return in_array($portData['port'], self::CDN_PROXIED_PORTS);
                }
                return true;
            });

            // Filter services on CDN IPs to only CDN-proxied ports
            $allServices = array_filter($allServices, function ($svc) use ($cdnIps) {
                if (isset($cdnIps[$svc['ip']])) {
                    return in_array($svc['port'], self::CDN_PROXIED_PORTS);
                }
                return true;
            });
            $allServices = array_values($allServices);

            // Filter banners on CDN IPs to only CDN-proxied ports
            $allBanners = array_filter($allBanners, function ($banner) use ($cdnIps) {
                $bannerIp = $banner['_ip'] ?? '';
                if (isset($cdnIps[$bannerIp])) {
                    return in_array($banner['port'] ?? 0, self::CDN_PROXIED_PORTS);
                }
                return true;
            });
            $allBanners = array_values($allBanners);

        }

        // Step 3c: Active TCP verification (nmap -sT equivalent). Shodan's port data
        // is historical and vantage-dependent, so it can report a port as open when
        // it is currently FILTERED (no handshake) or closed. Confirm every reported
        // port (except standard proxied web ports) with a real, concurrent TCP connect
        // from here, and drop any that don't complete the handshake -- then prune the
        // services/banners on those ports so they can't produce false-positive findings.
        if ($this->verifyOpenPorts && !empty($allPorts)) {
            $toVerify = [];
            foreach ($allPorts as $key => $pd) {
                if (!in_array((int) $pd['port'], self::ALWAYS_TRUST_PORTS, true)) {
                    $toVerify[$key] = $pd;
                }
            }
            if (!empty($toVerify)) {
                $confirmed = [];
                foreach ($this->filterOpenPorts(array_values($toVerify)) as $pd) {
                    $confirmed[$pd['ip'] . ':' . $pd['port']] = true;
                }
                $removed = []; // "ip:port" => true for ports that failed verification
                foreach ($toVerify as $key => $pd) {
                    $k = $pd['ip'] . ':' . $pd['port'];
                    if (empty($confirmed[$k])) {
                        unset($allPorts[$key]);
                        $removed[$k] = true;
                    }
                }
                if (!empty($removed)) {
                    error_log('Shodan: dropped ' . count($removed) . ' filtered/unreachable port(s) for ' . $domain . ' after active TCP check');
                    $allServices = array_values(array_filter($allServices, function ($s) use ($removed) {
                        return empty($removed[($s['ip'] ?? '') . ':' . ($s['port'] ?? 0)]);
                    }));
                    $allBanners = array_values(array_filter($allBanners, function ($b) use ($removed) {
                        return empty($removed[($b['_ip'] ?? '') . ':' . ($b['port'] ?? 0)]);
                    }));
                    // Drop CVEs tied to a port we could not confirm open. Host-level
                    // vulns (no port) are left to the existing keepVuln() logic.
                    $allVulns = array_filter($allVulns, function ($v) use ($removed) {
                        if (!isset($v['port']) || $v['port'] === null) return true;
                        return empty($removed[($v['ip'] ?? '') . ':' . ($v['port'] ?? 0)]);
                    });
                }
            }
        }

        // Drop CVE false positives in one pass. Runs unconditionally (not only
        // when isCdnIp ports were trimmed above) so it also covers CloudFront
        // edges detected solely by Server header / x-amz-cf-* response headers,
        // and non-routable IPs. See keepVuln() for the exact rules.
        $allVulns = array_filter(
            $allVulns,
            fn($vuln) => $this->keepVuln($vuln, $cdnIps, $multiTenantCdnIps, $managedInfraIps)
        );

        // Filter CVEs older than the configured minimum year
        if ($this->minCveYear > 0) {
            $preFilterCount = count($allVulns);
            $allVulns = array_filter($allVulns, fn($vuln) => !$this->isCveTooOld($vuln['cve_id'] ?? ''));
            $filtered = $preFilterCount - count($allVulns);
            if ($filtered > 0) {
                error_log("Shodan: Filtered {$filtered} CVEs older than {$this->minCveYear} for {$domain}");
            }
        }

        // Step 4: DNS analysis (free -- no API credits)
        $dnsData = $this->analyzeDNS($domain);

        // Step 5: Handle no-data scenario
        if (empty($hostResults)) {
            return $this->buildNoDataResult($domain, $uniqueIps, $subdomains, $dnsData);
        }

        // Step 5b: Active HTTPS probe. Shodan indexes by IP without SNI, so CDN/WAF
        // edges return default error responses that don't carry security headers
        // (HSTS, CSP, XFO, etc.). Actively probe each hostname with real SNI to
        // capture the real header posture and inject synthetic banners into the
        // pipeline so the existing header-aware analyzers light up for every
        // reachable subdomain, not just the few Shodan happens to see directly.
        $probeBanners = $this->probeActiveHeaders(array_keys($hostnameToIp), $hostnameToIp);
        if (!empty($probeBanners)) {
            $allBanners = array_merge($allBanners, $probeBanners);
        }

        // Step 6: Run 5 category analyzers
        $httpBanners = array_filter($allBanners, function ($b) {
            $module = $b['_shodan']['module'] ?? '';
            return in_array($b['port'] ?? 0, [80, 443, 8080, 8443])
                || stripos($module, 'http') !== false;
        });

        $portNumbers = array_unique(array_column(array_values($allPorts), 'port'));

        $tlsResult      = $this->analyzeTlsCrypto($allBanners, $domain);
        $networkResult   = $this->analyzeNetworkSecurity($hostResults, $portNumbers, $allBanners);
        $appResult       = $this->analyzeAppHardening($httpBanners, $portNumbers, $allServices);
        $vulnResult      = $this->analyzeVulnExposure(array_values($allVulns), $allServices);
        $emailResult     = $this->analyzeEmailSecurity($dnsData, $portNumbers, $allBanners, $allIpsBehindCdn);

        $categoryScores = [
            'tls_crypto'       => $tlsResult['score'],
            'network_security' => $networkResult['score'],
            'app_hardening'    => $appResult['score'],
            'vuln_exposure'    => $vulnResult['score'],
            'email_security'   => $emailResult['score'],
        ];

        // Gather all findings
        $allFindings = array_merge(
            $tlsResult['findings'],
            $networkResult['findings'],
            $appResult['findings'],
            $vulnResult['findings'],
            $emailResult['findings']
        );

        // Enrich findings missing IP/subdomain with context from the scan
        $firstIp = $uniqueIps[0] ?? null;
        $firstSub = $firstIp ? (($ipToHostnames[$firstIp] ?? [$domain])[0] ?? $domain) : $domain;
        foreach ($allFindings as &$f) {
            if (empty($f['ip']) && $firstIp) {
                $f['ip'] = $firstIp;
            }
            if (empty($f['subdomain'])) {
                // Look up subdomain from IP
                $fIp = $f['ip'] ?? $firstIp;
                $f['subdomain'] = $fIp ? (($ipToHostnames[$fIp] ?? [$domain])[0] ?? $domain) : $domain;
            }
        }
        unset($f);

        // Step 7: Compute weighted final score (with clean posture bonus)
        $finalScore = $this->computeWeightedScore($categoryScores, $allFindings);

        // Step 8: Determine traffic light
        $trafficLight = $this->determineTrafficLight($categoryScores, $allFindings);

        // Count positives/negatives
        $positiveCount = count(array_filter($allFindings, fn($f) => $f['type'] === 'positive'));
        $negativeCount = count(array_filter($allFindings, fn($f) => $f['type'] === 'negative'));

        // Classify vulns
        $vulnCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($allVulns as $vuln) {
            $sev = $vuln['severity'];
            if (isset($vulnCounts[$sev])) {
                $vulnCounts[$sev]++;
            }
        }

        // Step 9: Extract technology fingerprints for 4th Party Risk
        $technologies = $this->extractTechnologies($allBanners, $hostResults, $dnsData, $domain, array_values($allVulns));

        return [
            'score'              => $finalScore,
            'traffic_light'      => $trafficLight,
            'category_scores'    => $categoryScores,
            'positive_count'     => $positiveCount,
            'negative_count'     => $negativeCount,
            'subdomains_scanned' => $subdomains,
            'findings'           => $allFindings,
            'open_ports'         => array_values($allPorts),
            'vulns'              => array_values($allVulns),
            'services'           => $allServices,
            'ip_addresses'       => $uniqueIps,
            'open_ports_count'   => count($allPorts),
            'vuln_count'         => count($allVulns),
            'critical_vulns'     => $vulnCounts['critical'],
            'high_vulns'         => $vulnCounts['high'],
            'medium_vulns'       => $vulnCounts['medium'],
            'low_vulns'          => $vulnCounts['low'],
            'dns_data'           => $dnsData,
            'technologies'       => $technologies,
        ];
    }

    /**
     * Builds a result when Shodan has no host data.
     * DNS checks still run, other categories get neutral baseline.
     */
    private function buildNoDataResult(string $domain, array $ips, array $subdomains, array $dnsData): array
    {
        // Run email security analysis on DNS data alone
        // Pass false for allIpsBehindCdn since we have no IP data
        $emailResult = $this->analyzeEmailSecurity($dnsData, [], [], false);

        // Enrich email findings with domain context
        foreach ($emailResult['findings'] as &$f) {
            if (empty($f['subdomain'])) {
                $f['subdomain'] = $domain;
            }
        }
        unset($f);

        $categoryScores = [
            'tls_crypto'       => 50,
            'network_security' => 50,
            'app_hardening'    => 50,
            'vuln_exposure'    => 50,
            'email_security'   => $emailResult['score'],
        ];

        $finalScore = $this->computeWeightedScore($categoryScores, $emailResult['findings']);

        $positiveCount = count(array_filter($emailResult['findings'], fn($f) => $f['type'] === 'positive'));
        $negativeCount = count(array_filter($emailResult['findings'], fn($f) => $f['type'] === 'negative'));

        // Yellow by default for insufficient data, unless email alone yields strong signals
        $trafficLight = 'yellow';
        if ($positiveCount >= 5 && $negativeCount === 0) {
            $trafficLight = 'green';
        }

        // Extract technologies from DNS data even with no host data
        $technologies = $this->extractTechnologies([], [], $dnsData, $domain);

        return [
            'score'              => $finalScore,
            'traffic_light'      => $trafficLight,
            'category_scores'    => $categoryScores,
            'positive_count'     => $positiveCount,
            'negative_count'     => $negativeCount,
            'subdomains_scanned' => $subdomains,
            'findings'           => $emailResult['findings'],
            'open_ports'         => [],
            'vulns'              => [],
            'services'           => [],
            'ip_addresses'       => $ips,
            'open_ports_count'   => 0,
            'vuln_count'         => 0,
            'critical_vulns'     => 0,
            'high_vulns'         => 0,
            'medium_vulns'       => 0,
            'low_vulns'          => 0,
            'dns_data'           => $dnsData,
            'technologies'       => $technologies,
        ];
    }

    // =========================================================================
    // CATEGORY ANALYZERS
    // =========================================================================

    /**
     * Analyzes TLS/Crypto configuration across all banners.
     * Weight: 25%
     */
    private function analyzeTlsCrypto(array $banners, string $domain): array
    {
        $score = 50;
        $findings = [];

        // Track flags + source info + proof data for each signal
        $flags = [];
        $src = []; // $src['key'] = ['ip' => ..., 'sub' => ..., 'port' => ..., 'proof' => [...]]
        $hasStrongCiphersOnly = true;
        $observedCiphers = [];

        $strongCipherPatterns = ['ECDHE', 'AES', 'GCM', 'CHACHA20', 'POLY1305'];
        $weakCipherPatterns = ['RC4', '3DES', 'DES-CBC', 'NULL', 'EXPORT', 'anon'];

        foreach ($banners as $banner) {
            $ssl = $banner['ssl'] ?? null;
            if (!$ssl) {
                continue;
            }

            $ip = $banner['_ip'] ?? '';
            $port = $banner['port'] ?? 443;
            $hostnames = $banner['_hostnames'] ?? [$domain];
            $subdomain = $hostnames[0] ?? $domain;
            $bi = ['ip' => $ip, 'sub' => $subdomain, 'port' => $port];

            // Helper to record first source + proof for a flag, accumulating subdomains
            $setFlag = function(string $key, array $proof = []) use (&$flags, &$src, $bi) {
                if (empty($flags[$key])) {
                    $flags[$key] = true;
                    $src[$key] = $bi;
                    $src[$key]['proof'] = $proof;
                    $src[$key]['allSubs'] = !empty($bi['sub']) ? [$bi['sub']] : [];
                } else {
                    $sub = $bi['sub'] ?? '';
                    if (!empty($sub) && !in_array($sub, $src[$key]['allSubs'])) {
                        $src[$key]['allSubs'][] = $sub;
                    }
                }
            };

            // Check TLS versions
            // Shodan prefixes disabled protocols with '-' (e.g. "-SSLv2", "-TLSv1")
            // so we must skip entries starting with '-' to avoid false positives.
            $versions = $ssl['versions'] ?? [];
            $versionsStr = implode(', ', $versions);
            foreach ($versions as $ver) {
                // Skip disabled protocols (prefixed with '-')
                if (strpos($ver, '-') === 0) {
                    continue;
                }
                $verLower = strtolower($ver);
                if (strpos($verLower, 'tlsv1.3') !== false || $ver === 'TLSv1.3') {
                    $setFlag('tls13', ['TLS Versions' => $versionsStr]);
                }
                if ($ver === 'TLSv1' || $verLower === 'tlsv1.0' || $verLower === 'tlsv1') {
                    $setFlag('tls10', ['TLS Versions' => $versionsStr]);
                }
                if ($verLower === 'tlsv1.1') {
                    $setFlag('tls11', ['TLS Versions' => $versionsStr]);
                }
                if (stripos($verLower, 'sslv2') !== false || stripos($verLower, 'sslv3') !== false) {
                    $setFlag('sslv2v3', ['TLS Versions' => $versionsStr]);
                }
            }

            // Fallback: detect TLS 1.3 from cipher.version when versions array is empty
            // (happens when server only supports TLS 1.3 and rejects older protocol probes)
            $cipherVersion = strtolower($ssl['cipher']['version'] ?? '');
            if (empty($flags['tls13']) && strpos($cipherVersion, 'tlsv1.3') !== false) {
                $setFlag('tls13', ['TLS Versions' => 'TLS 1.3 (detected from cipher negotiation)']);
            }

            // Check cipher strength
            $cipherName = strtoupper($ssl['cipher']['name'] ?? '');
            if (!empty($cipherName)) {
                $observedCiphers[] = $cipherName;
                foreach ($weakCipherPatterns as $weak) {
                    if (stripos($cipherName, $weak) !== false) {
                        $setFlag('weak_cipher', ['Cipher Suite' => $cipherName]);
                        $hasStrongCiphersOnly = false;
                    }
                }
                $isStrong = false;
                foreach ($strongCipherPatterns as $strong) {
                    if (stripos($cipherName, $strong) !== false) {
                        $isStrong = true;
                        break;
                    }
                }
                if (!$isStrong) {
                    $hasStrongCiphersOnly = false;
                }

                // Check for PQC-ready infrastructure: TLS 1.3 + TLS_AES_256_GCM_SHA384
                if (!empty($flags['tls13']) && $cipherName === 'TLS_AES_256_GCM_SHA384') {
                    $setFlag('pqc_ready', [
                        'TLS Version' => 'TLS 1.3',
                        'Cipher Suite' => $cipherName,
                        'Note' => 'Server supports TLS 1.3 with AES-256-GCM-SHA384, the required cipher suite for PQC hybrid key exchange (X25519 + ML-KEM-768). Actual PQC key exchange cannot be confirmed via Shodan.',
                    ]);
                }
            }

            // Check certificate validity.
            // Shodan scans by IP without SNI, so shared-hosting IPs return the server's
            // default certificate. When the Shodan cert doesn't match our hostname, do a
            // live SNI check ONCE and use the real cert for ALL checks (expiry, self-signed,
            // CA, key length, signature algorithm, hostname match).
            $cert = $ssl['cert'] ?? [];
            if (!empty($cert)) {
                $expires = $cert['expires'] ?? null;
                $issuerO = $cert['issuer']['O'] ?? '';
                $subjectO = $cert['subject']['O'] ?? '';
                $issuerCN = $cert['issuer']['CN'] ?? '';
                $subjectCN = $cert['subject']['CN'] ?? '';
                $keyBits = $cert['pubkey']['bits'] ?? null;
                $keyType = strtolower($cert['pubkey']['type'] ?? 'rsa');
                $sigAlg = strtolower($cert['sig_alg'] ?? '');

                // Quick check: does the Shodan cert CN match any hostname on this IP?
                $shodanCertMatchesHostname = false;
                foreach ($hostnames as $hostname) {
                    if (stripos($subjectCN, $hostname) !== false) {
                        $shodanCertMatchesHostname = true;
                        break;
                    }
                }

                // If Shodan's cert doesn't match, fetch the real cert via SNI.
                // This replaces Shodan's cert data for ALL subsequent checks.
                $sniCertUsed = false;
                $sniHostnameMatch = false;
                if (!$shodanCertMatchesHostname && !empty($subjectCN)) {
                    $sniHost = $subdomain;
                    $ctx = @stream_context_create(['ssl' => [
                        'capture_peer_cert' => true,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'SNI_enabled' => true,
                        'peer_name' => $sniHost,
                    ]]);
                    $stream = @stream_socket_client(
                        "ssl://{$sniHost}:443",
                        $errno, $errstr, 5,
                        STREAM_CLIENT_CONNECT, $ctx
                    );
                    if ($stream) {
                        $params = stream_context_get_params($stream);
                        $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;
                        if ($peerCert) {
                            $certInfo = openssl_x509_parse($peerCert);
                            $sniCN = $certInfo['subject']['CN'] ?? '';
                            $sniSans = $certInfo['extensions']['subjectAltName'] ?? '';

                            // Verify the SNI cert matches this hostname
                            $sniMatch = stripos($sniCN, $sniHost) !== false || stripos($sniSans, $sniHost) !== false;
                            if (!$sniMatch) {
                                $hostParts = explode('.', $sniHost);
                                if (count($hostParts) >= 3) {
                                    $wildcard = '*.' . implode('.', array_slice($hostParts, 1));
                                    $sniMatch = stripos($sniCN, $wildcard) !== false || stripos($sniSans, $wildcard) !== false;
                                }
                            }

                            if ($sniMatch) {
                                // Replace Shodan cert data with the real SNI cert data
                                $sniCertUsed = true;
                                $sniHostnameMatch = true;
                                $subjectCN = $sniCN;
                                $issuerO = $certInfo['issuer']['O'] ?? '';
                                $subjectO = $certInfo['subject']['O'] ?? '';
                                $issuerCN = $certInfo['issuer']['CN'] ?? '';

                                // Update expiry from live cert
                                $validTo = $certInfo['validTo_time_t'] ?? null;
                                if ($validTo) {
                                    $expires = date('Y-m-d\TH:i:s', $validTo);
                                }

                                // Update key info from live cert
                                $pubKey = openssl_pkey_get_public($peerCert);
                                if ($pubKey) {
                                    $keyDetails = openssl_pkey_get_details($pubKey);
                                    $keyBits = $keyDetails['bits'] ?? $keyBits;
                                    $keyType = match ($keyDetails['type'] ?? -1) {
                                        OPENSSL_KEYTYPE_RSA => 'rsa',
                                        OPENSSL_KEYTYPE_EC  => 'ec',
                                        OPENSSL_KEYTYPE_DSA => 'dsa',
                                        default => $keyType,
                                    };
                                }

                                // Update sig algo from live cert
                                $sigAlg = strtolower($certInfo['signatureTypeSN'] ?? $sigAlg);
                            }
                        }
                        @fclose($stream);
                    }
                }

                // Now run all cert checks using the best available data (SNI cert if available)
                if ($expires) {
                    $expiryTime = strtotime($expires);
                    if ($expiryTime && $expiryTime > time()) {
                        $setFlag('valid_cert', ['Certificate Issuer' => $issuerO, 'Subject' => $subjectCN, 'Expires' => $expires]);
                    } else {
                        $setFlag('expired_cert', ['Certificate Issuer' => $issuerO, 'Subject' => $subjectCN, 'Expired' => $expires]);
                    }
                }

                // Check self-signed
                if (!empty($issuerO) && !empty($subjectO) && $issuerO === $subjectO && $issuerCN === $subjectCN) {
                    $setFlag('self_signed', ['Issuer (O)' => $issuerO, 'Subject (O)' => $subjectO, 'Issuer (CN)' => $issuerCN, 'Subject (CN)' => $subjectCN]);
                }

                // Check reputable CA
                $issuerOrg = strtolower($issuerO);
                foreach (self::REPUTABLE_CAS as $ca) {
                    if (stripos($issuerOrg, $ca) !== false) {
                        $setFlag('reputable_ca', ['Certificate Authority' => $issuerO]);
                        break;
                    }
                }

                // Check hostname match using Shodan's SAN data (or skip if SNI already confirmed).
                $domainMatch = $sniHostnameMatch;
                if (!$domainMatch) {
                    // Parse SANs from Shodan's cert data (DER-encoded)
                    $sansRaw = '';
                    $sansDomains = [];
                    foreach ($cert['extensions'] ?? [] as $ext) {
                        if (is_array($ext) && ($ext['name'] ?? '') === 'subjectAltName') {
                            $sansRaw = $ext['data'] ?? '';
                            $pos = 0;
                            $dataLen = strlen($sansRaw);
                            if ($dataLen > 2 && ord($sansRaw[0]) === 0x30) {
                                $pos = 2;
                            }
                            while ($pos < $dataLen - 1) {
                                $tag = ord($sansRaw[$pos]);
                                $entryLen = ord($sansRaw[$pos + 1]);
                                if ($entryLen === 0 || $pos + 2 + $entryLen > $dataLen) break;
                                if ($tag === 0x82) {
                                    $name = substr($sansRaw, $pos + 2, $entryLen);
                                    if (preg_match('/^[\x21-\x7E]+$/', $name)) {
                                        $sansDomains[] = $name;
                                    }
                                }
                                $pos += 2 + $entryLen;
                            }
                            break;
                        }
                    }
                    $sans = implode(', ', $sansDomains);
                    $certCN = $subjectCN;

                    foreach ($hostnames as $hostname) {
                        if (stripos($certCN, $hostname) !== false) {
                            $domainMatch = true;
                            break;
                        }
                        foreach ($sansDomains as $sanDomain) {
                            if (stripos($sanDomain, $hostname) !== false) {
                                $domainMatch = true;
                                break 2;
                            }
                        }
                        if (!empty($sansRaw) && stripos($sansRaw, $hostname) !== false) {
                            $domainMatch = true;
                            break;
                        }
                        $parts = explode('.', $hostname);
                        if (count($parts) >= 3) {
                            $wildcardDomain = '*.' . implode('.', array_slice($parts, 1));
                            if (stripos($certCN, $wildcardDomain) !== false) {
                                $domainMatch = true;
                                break;
                            }
                            foreach ($sansDomains as $sanDomain) {
                                if (stripos($sanDomain, $wildcardDomain) !== false) {
                                    $domainMatch = true;
                                    break 2;
                                }
                            }
                        }
                    }

                    // Skip hostname mismatch for WAF/CDN proxy certificates
                    $isWafProxyCert = stripos($certCN, 'cloudflare') !== false
                        || stripos($certCN, 'imperva') !== false
                        || stripos($certCN, 'incapsula') !== false
                        || stripos($certCN, 'fastly') !== false
                        || stripos($sans, 'cloudflare') !== false
                        || stripos($sans, 'imperva') !== false
                        || stripos($sans, 'incapsula') !== false
                        || stripos($sans, 'fastly') !== false;
                    if (!$domainMatch && !$isWafProxyCert) {
                        $sanDisplay = strlen($sans) > 200 ? substr($sans, 0, 200) . '...' : $sans;
                        $setFlag('hostname_mismatch', ['Certificate CN' => $certCN, 'Certificate SANs' => $sanDisplay ?: '(none)', 'Expected Domain' => $subdomain]);
                    }
                }

                // Check key length
                if ($keyType === 'rsa' && $keyBits !== null && $keyBits < 2048) {
                    $setFlag('short_key', ['Key Type' => strtoupper($keyType), 'Key Size' => $keyBits . ' bits', 'Minimum Required' => '2048 bits']);
                }

                // Check signature algorithm
                if (strpos($sigAlg, 'sha1') !== false) {
                    $setFlag('sha1_sig', ['Signature Algorithm' => $cert['sig_alg'] ?? $sigAlg]);
                }
            }

            // Check OCSP stapling
            if (!empty($ssl['ocsp'])) {
                $setFlag('ocsp', ['OCSP Stapling' => 'Enabled in SSL handshake']);
            }

            // Check HSTS in HTTP headers
            $lowerHeaders = $this->extractHttpHeaders($banner);
            if (isset($lowerHeaders['strict-transport-security'])) {
                $hstsVal = $lowerHeaders['strict-transport-security'];
                if (is_array($hstsVal)) $hstsVal = implode('; ', $hstsVal);
                $setFlag('hsts', ['Strict-Transport-Security' => $hstsVal]);
            }
        }

        // Post-loop: HSTS detection for active-probe synthetic banners.
        // The main loop above skips non-SSL banners, so synthetic probes aren't
        // touched by cert/cipher analysis. We still want to catch HSTS on them.
        foreach ($banners as $banner) {
            if (empty($banner['_active_probe'])) continue;
            $lowerHeaders = $this->extractHttpHeaders($banner);
            if (!isset($lowerHeaders['strict-transport-security'])) continue;

            $hstsVal = $lowerHeaders['strict-transport-security'];
            if (is_array($hstsVal)) $hstsVal = implode('; ', $hstsVal);

            $sub  = $banner['_hostnames'][0] ?? $domain;
            $ip   = $banner['_ip'] ?? '';
            $port = $banner['port'] ?? 443;

            if (empty($flags['hsts'])) {
                $flags['hsts'] = true;
                $src['hsts'] = [
                    'ip' => $ip, 'sub' => $sub, 'port' => $port,
                    'proof' => ['Strict-Transport-Security' => $hstsVal],
                    'allSubs' => !empty($sub) ? [$sub] : [],
                ];
            } elseif (!empty($sub) && !in_array($sub, $src['hsts']['allSubs'] ?? [])) {
                $src['hsts']['allSubs'][] = $sub;
            }
        }

        // Count unique SSL subdomains for proportional scoring.
        // Active-probe synthetic banners count too — they represent real HTTPS
        // hosts we reached directly, which is the denominator we want for the
        // "X of Y hosts" adoption ratio.
        $sslSubdomains = [];
        foreach ($banners as $b) {
            if (!empty($b['ssl']) || !empty($b['_active_probe'])) {
                $sub = ($b['_hostnames'][0] ?? $domain);
                if (!empty($sub)) $sslSubdomains[$sub] = true;
            }
        }
        $totalSslSubs = count($sslSubdomains);

        // Scale signal points by adoption rate across SSL subdomains
        $adoptionPts = function(string $flagKey, int $basePts) use (&$src, $totalSslSubs) {
            if ($totalSslSubs <= 1) return $basePts;
            $detected = count($src[$flagKey]['allSubs'] ?? []);
            if ($detected <= 0) return $basePts;
            return (int)round($basePts * $detected / $totalSslSubs);
        };

        // Helpers to get source info and proof for a flag
        $s = fn(string $key) => $src[$key] ?? [];
        $prf = function(string $key) use (&$src, $totalSslSubs) {
            $proof = $src[$key]['proof'] ?? null;
            $allSubs = $src[$key]['allSubs'] ?? [];
            $detected = count($allSubs);
            if ($totalSslSubs > 1 && is_array($proof)) {
                if ($detected > 1) {
                    $proof['Observed Subdomains'] = implode(', ', $allSubs);
                }
                $pct = round(100 * $detected / $totalSslSubs);
                $proof['Adoption'] = "{$detected} of {$totalSslSubs} SSL hosts ({$pct}%)";
            } elseif ($detected > 1 && is_array($proof)) {
                $proof['Observed Subdomains'] = implode(', ', $allSubs);
            }
            return $proof;
        };

        // Apply scoring using configurable point values
        $c = 'tls_crypto';

        if (!empty($flags['tls13'])) {
            $p = $adoptionPts('tls13', $this->pts($c, 'tls_1_3_supported'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'tls_1_3_supported', 'positive', $p, 'high', 'TLS 1.3 Supported', 'Server supports TLS 1.3', $s('tls13')['ip'] ?? null, $s('tls13')['port'] ?? null, $s('tls13')['sub'] ?? null, $prf('tls13'));
        }

        if ($hasStrongCiphersOnly && count($banners) > 0) {
            $sslBanners = array_filter($banners, fn($b) => !empty($b['ssl']));
            if (!empty($sslBanners)) {
                $fb = reset($sslBanners);
                $p = $this->pts($c, 'strong_ciphers_only');
                $score += $p;
                $cipherProof = !empty($observedCiphers) ? ['Observed Cipher Suites' => implode(', ', array_unique($observedCiphers))] : null;
                $findings[] = $this->makeFinding($c, 'strong_ciphers_only', 'positive', $p, 'high', 'Strong Ciphers Only', 'Only strong cipher suites detected (ECDHE+AES-GCM/ChaCha20)', $fb['_ip'] ?? null, $fb['port'] ?? null, ($fb['_hostnames'][0] ?? null), $cipherProof);
            }
        }

        if (!empty($flags['valid_cert'])) {
            $p = $adoptionPts('valid_cert', $this->pts($c, 'valid_certificate'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'valid_certificate', 'positive', $p, 'high', 'Valid Certificate', 'SSL/TLS certificate is valid and not expired', $s('valid_cert')['ip'] ?? null, $s('valid_cert')['port'] ?? null, $s('valid_cert')['sub'] ?? null, $prf('valid_cert'));
        }

        if (!empty($flags['reputable_ca'])) {
            $p = $adoptionPts('reputable_ca', $this->pts($c, 'reputable_ca'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'reputable_ca', 'positive', $p, 'high', 'Reputable Certificate Authority', 'Certificate issued by a reputable CA', $s('reputable_ca')['ip'] ?? null, $s('reputable_ca')['port'] ?? null, $s('reputable_ca')['sub'] ?? null, $prf('reputable_ca'));
        }

        if (!empty($flags['hsts'])) {
            $p = $adoptionPts('hsts', $this->pts($c, 'hsts_enabled'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'hsts_enabled', 'positive', $p, 'high', 'HSTS Enabled', 'HTTP Strict Transport Security header present', $s('hsts')['ip'] ?? null, $s('hsts')['port'] ?? null, $s('hsts')['sub'] ?? null, $prf('hsts'));
        }

        if (!empty($flags['ocsp'])) {
            $p = $adoptionPts('ocsp', $this->pts($c, 'ocsp_stapling'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'ocsp_stapling', 'positive', $p, 'high', 'OCSP Stapling', 'OCSP stapling is enabled', $s('ocsp')['ip'] ?? null, $s('ocsp')['port'] ?? null, $s('ocsp')['sub'] ?? null, $prf('ocsp'));
        }

        if (!empty($flags['pqc_ready'])) {
            $p = $adoptionPts('pqc_ready', $this->pts($c, 'pqc_ready'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'pqc_ready', 'positive', $p, 'high', 'PQC-Ready Infrastructure', 'Server supports TLS 1.3 with TLS_AES_256_GCM_SHA384 cipher suite, indicating readiness for post-quantum hybrid key exchange (X25519 + ML-KEM-768)', $s('pqc_ready')['ip'] ?? null, $s('pqc_ready')['port'] ?? null, $s('pqc_ready')['sub'] ?? null, $prf('pqc_ready'));
        }

        // Negative signals (proportional to affected hosts)
        if (!empty($flags['tls10'])) {
            $p = $adoptionPts('tls10', $this->pts($c, 'tls_1_0_enabled'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'tls_1_0_enabled', 'negative', $p, 'high', 'TLS 1.0 Enabled', 'Deprecated TLS 1.0 is still enabled', $s('tls10')['ip'] ?? null, $s('tls10')['port'] ?? null, $s('tls10')['sub'] ?? null, $prf('tls10'));
        }

        if (!empty($flags['tls11'])) {
            $p = $adoptionPts('tls11', $this->pts($c, 'tls_1_1_enabled'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'tls_1_1_enabled', 'negative', $p, 'high', 'TLS 1.1 Enabled', 'Deprecated TLS 1.1 is still enabled', $s('tls11')['ip'] ?? null, $s('tls11')['port'] ?? null, $s('tls11')['sub'] ?? null, $prf('tls11'));
        }

        if (!empty($flags['sslv2v3'])) {
            $p = $adoptionPts('sslv2v3', $this->pts($c, 'sslv2v3_enabled'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'sslv2v3_enabled', 'negative', $p, 'high', 'SSLv2/SSLv3 Enabled', 'Critically insecure SSL protocols are enabled', $s('sslv2v3')['ip'] ?? null, $s('sslv2v3')['port'] ?? null, $s('sslv2v3')['sub'] ?? null, $prf('sslv2v3'));
        }

        if (!empty($flags['weak_cipher'])) {
            $p = $adoptionPts('weak_cipher', $this->pts($c, 'weak_ciphers'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'weak_ciphers', 'negative', $p, 'high', 'Weak Ciphers Detected', 'Weak cipher suites detected (RC4/3DES/NULL/EXPORT)', $s('weak_cipher')['ip'] ?? null, $s('weak_cipher')['port'] ?? null, $s('weak_cipher')['sub'] ?? null, $prf('weak_cipher'));
        }

        if (!empty($flags['expired_cert'])) {
            $p = $adoptionPts('expired_cert', $this->pts($c, 'expired_certificate'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'expired_certificate', 'negative', $p, 'high', 'Expired Certificate', 'SSL/TLS certificate has expired', $s('expired_cert')['ip'] ?? null, $s('expired_cert')['port'] ?? null, $s('expired_cert')['sub'] ?? null, $prf('expired_cert'));
        }

        if (!empty($flags['self_signed'])) {
            $p = $adoptionPts('self_signed', $this->pts($c, 'self_signed_cert'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'self_signed_cert', 'negative', $p, 'high', 'Self-Signed Certificate', 'Certificate is self-signed (not issued by a trusted CA)', $s('self_signed')['ip'] ?? null, $s('self_signed')['port'] ?? null, $s('self_signed')['sub'] ?? null, $prf('self_signed'));
        }

        if (!empty($flags['hostname_mismatch'])) {
            $p = $adoptionPts('hostname_mismatch', $this->pts($c, 'hostname_mismatch'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'hostname_mismatch', 'negative', $p, 'high', 'Certificate Hostname Mismatch', 'Domain not found in certificate Subject Alternative Names', $s('hostname_mismatch')['ip'] ?? null, $s('hostname_mismatch')['port'] ?? null, $s('hostname_mismatch')['sub'] ?? null, $prf('hostname_mismatch'));
        }

        if (!empty($flags['short_key'])) {
            $p = $adoptionPts('short_key', $this->pts($c, 'short_rsa_key'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'short_rsa_key', 'negative', $p, 'high', 'Short RSA Key', 'RSA key length is less than 2048 bits', $s('short_key')['ip'] ?? null, $s('short_key')['port'] ?? null, $s('short_key')['sub'] ?? null, $prf('short_key'));
        }

        if (!empty($flags['sha1_sig'])) {
            $p = $adoptionPts('sha1_sig', $this->pts($c, 'sha1_signature'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'sha1_signature', 'negative', $p, 'high', 'SHA-1 Signature', 'Certificate uses deprecated SHA-1 signature algorithm', $s('sha1_sig')['ip'] ?? null, $s('sha1_sig')['port'] ?? null, $s('sha1_sig')['sub'] ?? null, $prf('sha1_sig'));
        }

        return ['score' => max(0, min(100, $score)), 'findings' => $findings];
    }

    /**
     * Checks whether an IP belongs to a known CDN/WAF provider based on its host info.
     * CDN IPs proxy only web traffic; other ports Shodan reports are filtered/unreachable.
     */
    private function isCdnIp(array $hostInfo): bool
    {
        $org = strtolower($hostInfo['org'] ?? '');
        $isp = strtolower($hostInfo['isp'] ?? '');

        foreach (self::WAF_CDN_PATTERNS['asn_org'] as $pattern => $name) {
            if (stripos($org, $pattern) !== false || stripos($isp, $pattern) !== false) {
                return true;
            }
        }

        // Also check banners for CDN headers
        if (isset($hostInfo['data']) && is_array($hostInfo['data'])) {
            foreach ($hostInfo['data'] as $banner) {
                $lowerHeaders = $this->extractHttpHeaders($banner);
                if (!empty($lowerHeaders)) {
                    foreach (self::WAF_CDN_PATTERNS['headers'] as $headerKey => $name) {
                        if (isset($lowerHeaders[$headerKey])) {
                            return true;
                        }
                    }
                }
                $server = strtolower($banner['http']['server'] ?? $banner['product'] ?? '');
                if (!empty($server)) {
                    foreach (self::WAF_CDN_PATTERNS['server'] as $pattern => $name) {
                        if (stripos($server, $pattern) !== false) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * True if the IP is a multi-tenant CDN/edge IP whose CVEs are always false
     * positives (Shodan fingerprints the shared edge, not the vendor origin).
     * Narrower than isCdnIp(): excludes single-tenant LBs (awselb/bigip).
     */
    private function isMultiTenantCdnIp(array $hostInfo): bool
    {
        $org = strtolower($hostInfo['org'] ?? '');
        $isp = strtolower($hostInfo['isp'] ?? '');
        foreach (self::MULTI_TENANT_CDN as $pat) {
            if (stripos($org, $pat) !== false || stripos($isp, $pat) !== false) {
                return true;
            }
        }
        if (isset($hostInfo['data']) && is_array($hostInfo['data'])) {
            foreach ($hostInfo['data'] as $banner) {
                $server = strtolower($banner['http']['server'] ?? $banner['product'] ?? '');
                foreach (self::MULTI_TENANT_CDN as $pat) {
                    if (stripos($server, $pat) !== false) {
                        return true;
                    }
                }
                // CloudFront/Cloudflare edge headers (org string is often just "Amazon")
                $hdrs = $this->extractHttpHeaders($banner);
                if (isset($hdrs['x-amz-cf-id']) || isset($hdrs['x-amz-cf-pop']) || isset($hdrs['cf-ray'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * True if a Server header belongs to managed edge infrastructure (CDN/WAF/LB)
     * whose version string the vendor cannot control or remove -- e.g. AWS ELB
     * always emits "awselb/2.0". Such headers must NOT trigger a version-disclosure
     * deduction; the disclosed version is the provider's, not the vendor's app.
     */
    private function isManagedEdgeServer(string $server): bool
    {
        $server = strtolower($server);
        foreach (self::WAF_CDN_PATTERNS['server'] as $pattern => $name) {
            if (stripos($server, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * True only for globally-routable IPs. RFC1918 private ranges, loopback,
     * link-local, and other reserved ranges are unreachable from the Internet,
     * so no remotely-exploitable finding can legitimately attach to them.
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Decides whether a single CVE finding survives false-positive filtering.
     *  - non-public IP (RFC1918/reserved): drop (not Internet-reachable)
     *  - multi-tenant CDN edge: drop (Shodan sees the edge fleet, not the origin)
     *  - other CDN/WAF/LB IP: keep only proxied-port vulns, drop host-level ones
     *  - anything else (real origin): keep
     */
    private function keepVuln(array $vuln, array $cdnIps, array $multiTenantCdnIps, array $managedInfraIps): bool
    {
        $ip = $vuln['ip'] ?? '';
        if ($ip !== '' && !$this->isPublicIp($ip)) {
            return false;
        }
        if (isset($multiTenantCdnIps[$ip])) {
            return false;
        }
        // 4th class: infrastructure-mitigated / version-inference CVEs on managed
        // cloud or edge hosts (AWS/Azure/GCP, CDN, LB). Drop only the curated CVE
        // IDs; any other (genuinely origin-exploitable) CVE on the same host stays.
        if (isset($managedInfraIps[$ip]) && $this->isInfraMitigatedCve($vuln['cve_id'] ?? '')) {
            return false;
        }
        if (isset($cdnIps[$ip])) {
            if (isset($vuln['port'])) {
                return in_array($vuln['port'], self::CDN_PROXIED_PORTS);
            }
            return false;
        }
        return true;
    }

    /**
     * True if the IP is on enterprise-cloud infrastructure (AWS/Azure/GCP/Oracle/
     * DigitalOcean/etc.) by ASN org or ISP. Used -- together with CDN/WAF/LB
     * detection -- to decide whether infrastructure-mitigated CVEs should be
     * dropped for a host. A raw EC2 origin is enterprise-cloud but NOT a CDN.
     */
    private function isEnterpriseCloudIp(array $hostInfo): bool
    {
        $org = strtolower($hostInfo['org'] ?? '');
        $isp = strtolower($hostInfo['isp'] ?? '');
        foreach (self::ENTERPRISE_CLOUD as $pattern => $name) {
            if (stripos($org, $pattern) !== false || stripos($isp, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private ?array $infraMitigatedCveCache = null;

    /**
     * The set of infrastructure-mitigated CVE IDs (uppercased, keyed for O(1)
     * lookup): the INFRA_MITIGATED_CVES constant unioned with any extras configured
     * in app_config under 'shodan_infra_mitigated_cves' (JSON array). DB failures
     * are non-fatal -- the constant alone is a safe default.
     */
    private function infraMitigatedCves(): array
    {
        if ($this->infraMitigatedCveCache !== null) {
            return $this->infraMitigatedCveCache;
        }
        $set = [];
        foreach (self::INFRA_MITIGATED_CVES as $c) {
            $set[strtoupper($c)] = true;
        }
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                'SELECT config_value FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_infra_mitigated_cves']
            );
            if ($row && !empty($row['config_value'])) {
                $custom = json_decode($row['config_value'], true);
                if (is_array($custom)) {
                    foreach ($custom as $c) {
                        if (is_string($c) && $c !== '') {
                            $set[strtoupper(trim($c))] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Defaults suffice; managed-infra CVE suppression still works offline.
        }
        return $this->infraMitigatedCveCache = $set;
    }

    /**
     * True if a CVE ID is on the infrastructure-mitigated / version-inference list.
     */
    private function isInfraMitigatedCve(string $cveId): bool
    {
        $cveId = strtoupper(trim($cveId));
        if ($cveId === '') {
            return false;
        }
        return isset($this->infraMitigatedCves()[$cveId]);
    }

    /**
     * Analyzes network security posture.
     * Weight: 20%
     */
    private function analyzeNetworkSecurity(array $hostResults, array $ports, array $banners): array
    {
        $score = 50;
        $findings = [];

        $wafDetected = false;
        $wafName = '';
        $wafIp = null;
        $wafSub = null;
        $wafProof = [];
        $wafSubs = [];

        // Check for WAF/CDN via headers, server name, and ASN
        foreach ($banners as $banner) {
            $ip = $banner['_ip'] ?? '';
            $hostnames = $banner['_hostnames'] ?? [];
            $subdomain = $hostnames[0] ?? null;

            // Check HTTP headers for WAF signatures
            $lowerHeaders = $this->extractHttpHeaders($banner);
            if (!empty($lowerHeaders)) {
                foreach (self::WAF_CDN_PATTERNS['headers'] as $headerKey => $name) {
                    if (isset($lowerHeaders[$headerKey])) {
                        if (!$wafDetected) {
                            $wafIp = $ip;
                            $wafSub = $subdomain;
                            $hdrVal = $lowerHeaders[$headerKey];
                            if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                            $wafProof = ['Detection Method' => "HTTP Header: $headerKey", 'Header Value' => substr($hdrVal, 0, 200)];
                        }
                        if (!empty($subdomain) && !in_array($subdomain, $wafSubs)) {
                            $wafSubs[] = $subdomain;
                        }
                        $wafDetected = true;
                        $wafName = $name;
                        break;
                    }
                }
            }

            // Check Server header
            $server = strtolower($banner['http']['server'] ?? $banner['product'] ?? '');
            if (!empty($server)) {
                foreach (self::WAF_CDN_PATTERNS['server'] as $pattern => $name) {
                    if (stripos($server, $pattern) !== false) {
                        if (!$wafDetected) {
                            $wafIp = $ip;
                            $wafSub = $subdomain;
                            $wafProof = ['Detection Method' => 'Server Header', 'Server' => $server];
                        }
                        if (!empty($subdomain) && !in_array($subdomain, $wafSubs)) {
                            $wafSubs[] = $subdomain;
                        }
                        $wafDetected = true;
                        $wafName = $name;
                        break;
                    }
                }
            }
        }

        // Count total unique HTTP subdomains for proportional WAF scoring
        $httpSubdomains = [];
        foreach ($banners as $b) {
            if (!empty($b['http'])) {
                $sub = ($b['_hostnames'][0] ?? null);
                if (!empty($sub)) $httpSubdomains[$sub] = true;
            }
        }
        $totalHttpSubs = count($httpSubdomains);

        // Check ASN/org for WAF/CDN and cloud hosting
        $enterpriseCloud = false;
        $cloudProvider = '';
        $cloudIp = null;
        $cloudOrg = '';
        $cloudIsp = '';
        $residentialISP = false;
        $residentialIp = null;
        $residentialOrg = '';

        foreach ($hostResults as $ip => $hostInfo) {
            $org = strtolower($hostInfo['org'] ?? '');
            $isp = strtolower($hostInfo['isp'] ?? '');

            // WAF/CDN via ASN
            foreach (self::WAF_CDN_PATTERNS['asn_org'] as $pattern => $name) {
                if (stripos($org, $pattern) !== false || stripos($isp, $pattern) !== false) {
                    if (!$wafDetected) {
                        $wafIp = $ip;
                        $wafProof = ['Detection Method' => 'ASN/Organization', 'Organization' => $org];
                    }
                    $wafDetected = true;
                    $wafName = $name;
                }
            }

            // Enterprise cloud
            foreach (self::ENTERPRISE_CLOUD as $pattern => $name) {
                if (stripos($org, $pattern) !== false || stripos($isp, $pattern) !== false) {
                    $enterpriseCloud = true;
                    $cloudProvider = $name;
                    $cloudIp = $ip;
                    $cloudOrg = $org;
                    $cloudIsp = $isp;
                    break;
                }
            }

            // Residential ISP
            foreach (self::RESIDENTIAL_ISPS as $pattern) {
                if (stripos($org, $pattern) !== false || stripos($isp, $pattern) !== false) {
                    $residentialISP = true;
                    $residentialIp = $ip;
                    $residentialOrg = $org ?: $isp;
                    break;
                }
            }
        }

        // Positive signals using configurable points
        $c = 'network_security';

        if ($wafDetected) {
            $basePts = $this->pts($c, 'waf_cdn_detected');
            $wafCount = count($wafSubs);
            $p = ($totalHttpSubs <= 1 || $wafCount <= 0) ? $basePts : (int)round($basePts * $wafCount / $totalHttpSubs);
            $score += $p;
            $wafProofFinal = $wafProof ?: null;
            if (count($wafSubs) > 1 && is_array($wafProofFinal)) {
                $wafProofFinal['Observed Subdomains'] = implode(', ', $wafSubs);
            }
            if ($totalHttpSubs > 1 && is_array($wafProofFinal)) {
                $pct = round(100 * $wafCount / $totalHttpSubs);
                $wafProofFinal['Adoption'] = "{$wafCount} of {$totalHttpSubs} HTTP hosts ({$pct}%)";
            }
            $findings[] = $this->makeFinding($c, 'waf_cdn_detected', 'positive', $p, 'high', 'WAF/CDN Detected', "Protected by {$wafName}", $wafIp, null, $wafSub, $wafProofFinal);
        }

        if ($enterpriseCloud) {
            $p = $this->pts($c, 'enterprise_cloud');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'enterprise_cloud', 'positive', $p, 'medium', 'Enterprise Cloud Hosting', "Hosted on {$cloudProvider}", $cloudIp, null, null, ['ASN Organization' => $cloudOrg, 'ISP' => $cloudIsp]);
        }

        // Check if only standard web ports
        $nonStandardPorts = array_diff($ports, self::STANDARD_WEB_PORTS);
        if (empty($nonStandardPorts) && !empty($ports)) {
            $p = $this->pts($c, 'standard_ports_only');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'standard_ports_only', 'positive', $p, 'high', 'Standard Ports Only', 'Only standard web ports (80, 443, 8080, 8443) are open', null, null, null, ['Open Ports' => implode(', ', $ports)]);
        }

        // Minimal open ports
        if (count($ports) > 0 && count($ports) <= 3) {
            $p = $this->pts($c, 'minimal_ports');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'minimal_ports', 'positive', $p, 'high', 'Minimal Open Ports', 'Only ' . count($ports) . ' port(s) open', null, null, null, ['Open Ports' => implode(', ', $ports)]);
        }

        // Negative signals
        $perPortPts = abs($this->pts($c, 'high_risk_port'));
        $highRiskDeduct = 0;
        $highRiskCap = $perPortPts * 4;
        foreach ($ports as $port) {
            if (isset(self::HIGH_RISK_PORTS[$port])) {
                $deduct = min($perPortPts, $highRiskCap - $highRiskDeduct);
                if ($deduct > 0) {
                    $highRiskDeduct += $deduct;
                    $score -= $deduct;
                    $findings[] = $this->makeFinding($c, 'high_risk_port_' . $port, 'negative', -$deduct, 'high', 'High-Risk Port Open: ' . self::HIGH_RISK_PORTS[$port], 'Port ' . $port . ' (' . self::HIGH_RISK_PORTS[$port] . ') is exposed to the internet', null, $port, null, ['Port' => $port, 'Service' => self::HIGH_RISK_PORTS[$port]]);
                }
            }
        }

        $perDbPts = abs($this->pts($c, 'db_port'));
        $dbDeduct = 0;
        $dbCap = $perDbPts * 4;
        foreach ($ports as $port) {
            if (isset(self::DATABASE_PORTS[$port])) {
                $deduct = min($perDbPts, $dbCap - $dbDeduct);
                if ($deduct > 0) {
                    $dbDeduct += $deduct;
                    $score -= $deduct;
                    $findings[] = $this->makeFinding($c, 'db_port_' . $port, 'negative', -$deduct, 'high', 'Database Port Exposed: ' . self::DATABASE_PORTS[$port], 'Port ' . $port . ' (' . self::DATABASE_PORTS[$port] . ') is exposed to the internet', null, $port, null, ['Port' => $port, 'Service' => self::DATABASE_PORTS[$port]]);
                }
            }
        }

        // Shared hosting detection
        // Skip if WAF/CDN detected -- Cloudflare uses ports 2082-2096 for its own services,
        // and detecting cPanel/WHM on CDN IPs is a false positive.
        $sharedHosting = false;
        $sharedIp = null;
        $sharedProof = [];
        if (!$wafDetected) {
            foreach ($ports as $port) {
                if (in_array($port, self::SHARED_HOSTING_PORTS)) {
                    $sharedHosting = true;
                    $sharedProof = ['Detection' => "Control panel port $port detected"];
                    break;
                }
            }
            if (!$sharedHosting) {
                foreach ($banners as $banner) {
                    $product = strtolower($banner['product'] ?? '');
                    foreach (self::SHARED_HOSTING_PRODUCTS as $pattern) {
                        if (stripos($product, $pattern) !== false) {
                            $sharedHosting = true;
                            $sharedIp = $banner['_ip'] ?? null;
                            $sharedProof = ['Detection' => "Product '$product' detected"];
                            break 2;
                        }
                    }
                }
            }
            if ($sharedHosting) {
                $p = $this->pts($c, 'shared_hosting');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'shared_hosting', 'negative', $p, 'high', 'Shared Hosting Detected', 'cPanel/WHM/Plesk control panel ports or products detected', $sharedIp, null, null, $sharedProof ?: null);
            }
        }

        // Residential ISP
        if ($residentialISP) {
            $p = $this->pts($c, 'residential_isp');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'residential_isp', 'negative', $p, 'medium', 'Residential ISP', 'Server appears to be hosted on a residential internet connection', $residentialIp, null, null, ['ISP/Organization' => $residentialOrg]);
        }

        // Many open ports
        // Skip if WAF/CDN detected -- CDN providers like Cloudflare have many ports open by design
        if (count($ports) > 10 && !$wafDetected) {
            $p = $this->pts($c, 'many_open_ports');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'many_open_ports', 'negative', $p, 'high', 'Many Open Ports', count($ports) . ' ports are open (>10 suggests poor network hardening)', null, null, null, ['Port Count' => count($ports), 'Open Ports' => implode(', ', $ports)]);
        }

        // Direct origin exposure (single IP, no WAF)
        if (count($hostResults) === 1 && !$wafDetected) {
            $singleIp = array_key_first($hostResults);
            $p = $this->pts($c, 'direct_origin');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'direct_origin', 'negative', $p, 'medium', 'Direct Origin Exposure', 'Single IP with no CDN/WAF detected -- origin server directly exposed', $singleIp, null, null, ['IP Address' => $singleIp, 'Note' => 'No CDN/WAF headers or ASN detected']);
        }

        // Absence-of-bad positives (same pattern as no_admin_panels / no_cves)
        if ($highRiskDeduct === 0 && !empty($ports)) {
            $p = $this->pts($c, 'no_high_risk_ports');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_high_risk_ports', 'positive', $p, 'high', 'No High-Risk Ports Detected', 'No high-risk service ports (RDP, Telnet, FTP, etc.) are exposed');
        }
        if ($dbDeduct === 0 && !empty($ports)) {
            $p = $this->pts($c, 'no_db_ports');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_db_ports', 'positive', $p, 'high', 'No Database Ports Exposed', 'No database ports (MySQL, PostgreSQL, MongoDB, etc.) are exposed');
        }

        return ['score' => max(0, min(100, $score)), 'findings' => $findings];
    }

    /**
     * Analyzes application hardening via HTTP banners.
     * Weight: 20%
     */
    private function analyzeAppHardening(array $httpBanners, array $ports, array $services): array
    {
        $score = 50;
        $findings = [];

        // Track flags + source info (ip/port/subdomain) for each signal
        $flags = [];
        $src = []; // $src['signal_name'] = ['ip' => ..., 'sub' => ..., 'port' => ...]

        $adminPanelCount = 0;
        $dbUiCount = 0;
        $detectedAdminPanels = [];  // name => ['ip' => ..., 'port' => ..., 'sub' => ...]
        $detectedDbUis = [];        // name => ['ip' => ..., 'port' => ..., 'sub' => ...]

        foreach ($httpBanners as $banner) {
            $ip = $banner['_ip'] ?? '';
            $port = $banner['port'] ?? 0;
            $hostnames = $banner['_hostnames'] ?? [];
            $subdomain = $hostnames[0] ?? null;
            $bi = ['ip' => $ip, 'sub' => $subdomain, 'port' => $port];

            $product = strtolower($banner['product'] ?? '');
            $title = strtolower($banner['http']['title'] ?? '');
            $serverHeader = strtolower($banner['http']['server'] ?? '');

            // Helper to record first source + proof for a flag, accumulating subdomains
            $setFlag = function(string $key, array $proof = []) use (&$flags, &$src, $bi) {
                if (empty($flags[$key])) {
                    $flags[$key] = true;
                    $src[$key] = $bi;
                    $src[$key]['proof'] = $proof;
                    $src[$key]['allSubs'] = !empty($bi['sub']) ? [$bi['sub']] : [];
                } else {
                    $sub = $bi['sub'] ?? '';
                    if (!empty($sub) && !in_array($sub, $src[$key]['allSubs'])) {
                        $src[$key]['allSubs'][] = $sub;
                    }
                }
            };

            // Check headers (parsed from banner.data since Shodan doesn't populate http.headers)
            $lowerHeaders = $this->extractHttpHeaders($banner);

            // Server version disclosure -- skip managed edges (CDN/WAF/LB) whose
            // Server header version is fixed by the provider and not removable by
            // the vendor (e.g. AWS ELB always emits "awselb/2.0"). Penalizing it
            // would flag infrastructure the vendor cannot change.
            if (!empty($serverHeader)
                && preg_match('/\d+\.\d+/', $serverHeader)
                && !$this->isManagedEdgeServer($serverHeader)) {
                $setFlag('version_disclosure', ['Server Header' => $serverHeader]);
            }

            // Security headers (safely convert header values to strings)
            if (isset($lowerHeaders['content-security-policy'])) {
                $hdrVal = $lowerHeaders['content-security-policy'];
                if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                $setFlag('csp', ['Content-Security-Policy' => substr($hdrVal, 0, 300)]);
            }
            if (isset($lowerHeaders['x-frame-options'])) {
                $hdrVal = $lowerHeaders['x-frame-options'];
                if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                $setFlag('xfo', ['X-Frame-Options' => $hdrVal]);
            }
            if (isset($lowerHeaders['x-content-type-options'])) {
                $hdrVal = $lowerHeaders['x-content-type-options'];
                if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                $setFlag('xcto', ['X-Content-Type-Options' => $hdrVal]);
            }
            if (isset($lowerHeaders['referrer-policy'])) {
                $hdrVal = $lowerHeaders['referrer-policy'];
                if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                $setFlag('referrer', ['Referrer-Policy' => $hdrVal]);
            }
            if (isset($lowerHeaders['permissions-policy'])) {
                $hdrVal = $lowerHeaders['permissions-policy'];
                if (is_array($hdrVal)) $hdrVal = implode(', ', $hdrVal);
                $setFlag('permissions', ['Permissions-Policy' => substr($hdrVal, 0, 300)]);
            }

            // Default page detection
            foreach (self::DEFAULT_PAGES as $pattern) {
                if (stripos($title, $pattern) !== false) {
                    $setFlag('default_page', ['Page Title' => $title]);
                    break;
                }
            }

            // Directory listing
            if (stripos($title, 'index of /') !== false) {
                $setFlag('directory_listing', ['Page Title' => $title]);
            }

            // Swagger/OpenAPI
            if (stripos($title, 'swagger') !== false || stripos($title, 'openapi') !== false) {
                $setFlag('swagger', ['Page Title' => $title]);
            }

            // Admin panel detection
            foreach (self::ADMIN_PANELS as $pattern => $name) {
                if (stripos($product, $pattern) !== false || stripos($title, $pattern) !== false) {
                    if (!isset($detectedAdminPanels[$name])) {
                        $detectedAdminPanels[$name] = array_merge($bi, ['proof' => ['Detected' => $name, 'Product' => $product ?: '-', 'Page Title' => $title ?: '-']]);
                        $adminPanelCount++;
                    }
                    break;
                }
            }

            // Database UI detection
            foreach (self::DATABASE_UIS as $pattern => $name) {
                if (stripos($product, $pattern) !== false || stripos($title, $pattern) !== false) {
                    if (!isset($detectedDbUis[$name])) {
                        $detectedDbUis[$name] = array_merge($bi, ['proof' => ['Detected' => $name, 'Product' => $product ?: '-', 'Page Title' => $title ?: '-']]);
                        $dbUiCount++;
                    }
                    break;
                }
            }
        }

        // Count total unique HTTP subdomains for proportional scoring
        $httpSubdomains = [];
        foreach ($httpBanners as $b) {
            $sub = ($b['_hostnames'][0] ?? null);
            if (!empty($sub)) $httpSubdomains[$sub] = true;
        }
        $totalHttpSubs = count($httpSubdomains);

        // Scale signal points by adoption rate across HTTP subdomains
        $adoptionPts = function(string $flagKey, int $basePts) use (&$src, $totalHttpSubs) {
            if ($totalHttpSubs <= 1) return $basePts;
            $detected = count($src[$flagKey]['allSubs'] ?? []);
            if ($detected <= 0) return $basePts;
            return (int)round($basePts * $detected / $totalHttpSubs);
        };

        // Helpers to get source info and proof for a flag
        $s = function(string $key) use (&$src): array { return $src[$key] ?? []; };
        $prf = function(string $key) use (&$src, $totalHttpSubs) {
            $proof = $src[$key]['proof'] ?? null;
            $allSubs = $src[$key]['allSubs'] ?? [];
            $detected = count($allSubs);
            if ($totalHttpSubs > 1 && is_array($proof)) {
                if ($detected > 1) {
                    $proof['Observed Subdomains'] = implode(', ', $allSubs);
                }
                $pct = round(100 * $detected / $totalHttpSubs);
                $proof['Adoption'] = "{$detected} of {$totalHttpSubs} HTTP hosts ({$pct}%)";
            } elseif ($detected > 1 && is_array($proof)) {
                $proof['Observed Subdomains'] = implode(', ', $allSubs);
            }
            return $proof;
        };

        // Positive signals using configurable points
        $c = 'app_hardening';

        // Proportional: award points based on how many subdomains do NOT disclose version
        if (!empty($httpBanners)) {
            $disclosingSubs = count($src['version_disclosure']['allSubs'] ?? []);
            $nonDisclosing = max(0, $totalHttpSubs - $disclosingSubs);
            if ($nonDisclosing > 0 && $totalHttpSubs > 0) {
                $basePts = $this->pts($c, 'no_version_disclosure');
                $p = ($totalHttpSubs <= 1) ? $basePts : (int)round($basePts * $nonDisclosing / $totalHttpSubs);
                $proof = null;
                if ($totalHttpSubs > 1) {
                    $pct = round(100 * $nonDisclosing / $totalHttpSubs);
                    $proof = ['Adoption' => "{$nonDisclosing} of {$totalHttpSubs} HTTP hosts ({$pct}%)"];
                }
                $findings[] = $this->makeFinding($c, 'no_version_disclosure', 'positive', $p, 'high', 'No Server Version Disclosure', 'Server header does not reveal software version', null, null, null, $proof);
                $score += $p;
            }
        }

        if (!empty($flags['csp'])) {
            $p = $adoptionPts('csp', $this->pts($c, 'csp_present'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'csp_present', 'positive', $p, 'high', 'Content Security Policy', 'CSP header is present', $s('csp')['ip'] ?? null, $s('csp')['port'] ?? null, $s('csp')['sub'] ?? null, $prf('csp'));
        }

        if (!empty($flags['xfo'])) {
            $p = $adoptionPts('xfo', $this->pts($c, 'x_frame_options'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'x_frame_options', 'positive', $p, 'high', 'X-Frame-Options', 'Clickjacking protection header present', $s('xfo')['ip'] ?? null, $s('xfo')['port'] ?? null, $s('xfo')['sub'] ?? null, $prf('xfo'));
        }

        if (!empty($flags['xcto'])) {
            $p = $adoptionPts('xcto', $this->pts($c, 'x_content_type'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'x_content_type', 'positive', $p, 'high', 'X-Content-Type-Options', 'MIME type sniffing protection present', $s('xcto')['ip'] ?? null, $s('xcto')['port'] ?? null, $s('xcto')['sub'] ?? null, $prf('xcto'));
        }

        if (!empty($flags['referrer'])) {
            $p = $adoptionPts('referrer', $this->pts($c, 'referrer_policy'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'referrer_policy', 'positive', $p, 'high', 'Referrer-Policy', 'Referrer policy header present', $s('referrer')['ip'] ?? null, $s('referrer')['port'] ?? null, $s('referrer')['sub'] ?? null, $prf('referrer'));
        }

        if (!empty($flags['permissions'])) {
            $p = $adoptionPts('permissions', $this->pts($c, 'permissions_policy'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'permissions_policy', 'positive', $p, 'high', 'Permissions-Policy', 'Permissions policy header present', $s('permissions')['ip'] ?? null, $s('permissions')['port'] ?? null, $s('permissions')['sub'] ?? null, $prf('permissions'));
        }

        // Proportional: award points based on how many subdomains do NOT show default pages
        if (!empty($httpBanners)) {
            $defaultSubs = count($src['default_page']['allSubs'] ?? []);
            $nonDefault = max(0, $totalHttpSubs - $defaultSubs);
            if ($nonDefault > 0 && $totalHttpSubs > 0) {
                $basePts = $this->pts($c, 'no_default_page');
                $p = ($totalHttpSubs <= 1) ? $basePts : (int)round($basePts * $nonDefault / $totalHttpSubs);
                $proof = null;
                if ($totalHttpSubs > 1) {
                    $pct = round(100 * $nonDefault / $totalHttpSubs);
                    $proof = ['Adoption' => "{$nonDefault} of {$totalHttpSubs} HTTP hosts ({$pct}%)"];
                }
                $findings[] = $this->makeFinding($c, 'no_default_page', 'positive', $p, 'high', 'No Default Pages', 'No default/welcome pages detected', null, null, null, $proof);
                $score += $p;
            }
        }

        if ($adminPanelCount === 0 && !empty($httpBanners)) {
            $p = $this->pts($c, 'no_admin_panels');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_admin_panels', 'positive', $p, 'high', 'No Admin Interfaces Exposed', 'No exposed admin panels detected');
        }

        // Negative signals (proportional to affected hosts)
        if (!empty($flags['version_disclosure'])) {
            $p = $adoptionPts('version_disclosure', $this->pts($c, 'version_disclosure'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'version_disclosure', 'negative', $p, 'high', 'Server Version Disclosed', 'Server header reveals software version information', $s('version_disclosure')['ip'] ?? null, $s('version_disclosure')['port'] ?? null, $s('version_disclosure')['sub'] ?? null, $prf('version_disclosure'));
        }

        $perPanelPts = abs($this->pts($c, 'admin_panel'));
        $adminDeduct = 0;
        $adminCap = $perPanelPts * 2;
        foreach ($detectedAdminPanels as $panel => $panelSrc) {
            $deduct = min($perPanelPts, $adminCap - $adminDeduct);
            if ($deduct > 0) {
                $adminDeduct += $deduct;
                $score -= $deduct;
                $findings[] = $this->makeFinding($c, 'admin_panel_' . strtolower(str_replace(' ', '_', $panel)), 'negative', -$deduct, 'high', 'Admin Panel Exposed: ' . $panel, $panel . ' admin interface is exposed to the internet', $panelSrc['ip'] ?? null, $panelSrc['port'] ?? null, $panelSrc['sub'] ?? null, $panelSrc['proof'] ?? null);
            }
        }

        $perDbUiPts = abs($this->pts($c, 'db_ui'));
        $dbUiDeduct = 0;
        $dbUiCap = $perDbUiPts * 2;
        foreach ($detectedDbUis as $ui => $uiSrc) {
            $deduct = min($perDbUiPts, $dbUiCap - $dbUiDeduct);
            if ($deduct > 0) {
                $dbUiDeduct += $deduct;
                $score -= $deduct;
                $findings[] = $this->makeFinding($c, 'db_ui_' . strtolower(str_replace(' ', '_', $ui)), 'negative', -$deduct, 'high', 'Database UI Exposed: ' . $ui, $ui . ' is exposed to the internet', $uiSrc['ip'] ?? null, $uiSrc['port'] ?? null, $uiSrc['sub'] ?? null, $uiSrc['proof'] ?? null);
            }
        }

        if (!empty($flags['default_page'])) {
            $p = $adoptionPts('default_page', $this->pts($c, 'default_page'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'default_page', 'negative', $p, 'high', 'Default Page Detected', 'Server is showing a default/welcome page', $s('default_page')['ip'] ?? null, $s('default_page')['port'] ?? null, $s('default_page')['sub'] ?? null, $prf('default_page'));
        }

        if (!empty($flags['directory_listing'])) {
            $p = $adoptionPts('directory_listing', $this->pts($c, 'directory_listing'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'directory_listing', 'negative', $p, 'high', 'Directory Listing Enabled', 'Web server directory listing is enabled', $s('directory_listing')['ip'] ?? null, $s('directory_listing')['port'] ?? null, $s('directory_listing')['sub'] ?? null, $prf('directory_listing'));
        }

        if (!empty($flags['swagger'])) {
            $p = $adoptionPts('swagger', $this->pts($c, 'swagger_exposed'));
            $score += $p;
            $findings[] = $this->makeFinding($c, 'swagger_exposed', 'negative', $p, 'high', 'Swagger/OpenAPI Exposed', 'API documentation (Swagger/OpenAPI) is publicly accessible', $s('swagger')['ip'] ?? null, $s('swagger')['port'] ?? null, $s('swagger')['sub'] ?? null, $prf('swagger'));
        }

        return ['score' => max(0, min(100, $score)), 'findings' => $findings];
    }

    /**
     * Analyzes vulnerability exposure from CVE data and software versions.
     * Weight: 20%
     */
    private function analyzeVulnExposure(array $vulns, array $services): array
    {
        $score = 50;
        $findings = [];

        // Only CONFIRMED (verified) CVEs move the score. Version-inferred CVEs are
        // the dominant external-scan false positive (e.g. backported distro patches
        // that keep the original version string), so they are surfaced in the report
        // but never deducted. Split the input up front.
        $verifiedVulns = array_values(array_filter($vulns, fn($v) => !empty($v['verified'])));
        $inferredCount = count($vulns) - count($verifiedVulns);

        // Count CONFIRMED CVEs by severity (these drive the deductions below) and
        // collect their IDs for proof data.
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;
        $critCveIds = [];
        $highCveIds = [];
        $medCveIds = [];
        $lowCveIds = [];

        foreach ($verifiedVulns as $vuln) {
            $cvss = $vuln['cvss'] ?? null;
            $severity = $vuln['severity'] ?? $this->cvssToSeverity($cvss);
            $cveId = $vuln['cve_id'] ?? '';
            switch ($severity) {
                case 'critical': $criticalCount++; $critCveIds[] = $cveId; break;
                case 'high':     $highCount++;     $highCveIds[] = $cveId; break;
                case 'medium':   $mediumCount++;   $medCveIds[]  = $cveId; break;
                case 'low':      $lowCount++;      $lowCveIds[]  = $cveId; break;
            }
        }

        $verifiedTotal = count($verifiedVulns);
        $c = 'vuln_exposure';

        // When CVEs were excluded for inadequate validation, note it on whichever
        // finding we emit so the score stays explainable next to the report's
        // low-fidelity (not-counted) table.
        $inferredNote = $inferredCount > 0
            ? ['CVEs not validated' => $inferredCount . ' (version-inferred; not scored)']
            : [];

        // Positive: no VALIDATED CVEs. Version-inferred hosts still count as clean
        // here, since those findings lack the validation needed to confirm the
        // vulnerability and are deliberately excluded from scoring.
        if ($verifiedTotal === 0) {
            $p = $this->pts($c, 'no_cves');
            $score += $p;
            if ($inferredCount > 0) {
                $findings[] = $this->makeFinding($c, 'no_cves', 'positive', $p, 'high', 'No Validated CVEs', 'No validated vulnerabilities detected. ' . $inferredCount . ' version-inferred CVE' . ($inferredCount === 1 ? '' : 's') . ' observed but not validated, so excluded from the score.', null, null, null, array_merge(['Hosts Scanned' => count($services) . ' services across all IPs'], $inferredNote));
            } else {
                $findings[] = $this->makeFinding($c, 'no_cves', 'positive', $p, 'high', 'No CVEs Detected', 'No known vulnerabilities detected across all hosts', null, null, null, ['Hosts Scanned' => count($services) . ' services across all IPs']);
            }
        } elseif ($verifiedTotal <= 3 && $criticalCount === 0 && $highCount === 0) {
            $p = $this->pts($c, 'low_cve_count');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'low_cve_count', 'positive', $p, 'high', 'Low Validated CVE Count', "Only {$verifiedTotal} non-critical validated vulnerabilit" . ($verifiedTotal === 1 ? 'y' : 'ies') . " detected", null, null, null, array_merge(['Validated CVE Count' => $verifiedTotal, 'Severities' => 'No critical or high severity'], $inferredNote));
        }

        // Negative: CVEs by severity (with caps at 3x per-CVE points)
        $perCritPts = abs($this->pts($c, 'critical_cve'));
        $critDeduct = min($criticalCount * $perCritPts, $perCritPts * 3);
        if ($critDeduct > 0) {
            $score -= $critDeduct;
            $findings[] = $this->makeFinding($c, 'critical_cves', 'negative', -$critDeduct, 'high', "Critical CVEs ({$criticalCount})", "{$criticalCount} validated critical vulnerabilit" . ($criticalCount === 1 ? 'y' : 'ies') . " detected (CVSS >= 9.0)", null, null, null, ['CVE IDs' => implode(', ', $critCveIds)]);
        }

        $perHighPts = abs($this->pts($c, 'high_cve'));
        $highDeduct = min($highCount * $perHighPts, $perHighPts * 3);
        if ($highDeduct > 0) {
            $score -= $highDeduct;
            $findings[] = $this->makeFinding($c, 'high_cves', 'negative', -$highDeduct, 'high', "High CVEs ({$highCount})", "{$highCount} validated high-severity vulnerabilit" . ($highCount === 1 ? 'y' : 'ies') . " detected (CVSS 7.0-8.9)", null, null, null, ['CVE IDs' => implode(', ', $highCveIds)]);
        }

        $perMedPts = abs($this->pts($c, 'medium_cve'));
        $medDeduct = min($mediumCount * $perMedPts, $perMedPts * 5);
        if ($medDeduct > 0) {
            $score -= $medDeduct;
            $findings[] = $this->makeFinding($c, 'medium_cves', 'negative', -$medDeduct, 'high', "Medium CVEs ({$mediumCount})", "{$mediumCount} validated medium-severity vulnerabilit" . ($mediumCount === 1 ? 'y' : 'ies') . " detected (CVSS 4.0-6.9)", null, null, null, ['CVE IDs' => implode(', ', $medCveIds)]);
        }

        $perLowPts = abs($this->pts($c, 'low_cve'));
        $lowDeduct = min($lowCount * $perLowPts, $perLowPts * 5);
        if ($lowDeduct > 0) {
            $score -= $lowDeduct;
            $findings[] = $this->makeFinding($c, 'low_cves', 'negative', -$lowDeduct, 'high', "Low CVEs ({$lowCount})", "{$lowCount} validated low-severity vulnerabilit" . ($lowCount === 1 ? 'y' : 'ies') . " detected (CVSS < 4.0)", null, null, null, ['CVE IDs' => implode(', ', $lowCveIds)]);
        }

        // Check for outdated software
        $outdatedPts = abs($this->pts($c, 'outdated_software'));
        $foundOutdated = false;
        foreach ($services as $service) {
            $product = strtolower($service['service_name'] ?? '');
            $version = $service['version'] ?? null;

            if (empty($version)) {
                continue;
            }

            foreach (self::OUTDATED_SOFTWARE as $softwareName => $minVersion) {
                if (stripos($product, $softwareName) !== false) {
                    if (version_compare($version, $minVersion, '<')) {
                        $score -= $outdatedPts;
                        $foundOutdated = true;
                        $svcIp = $service['ip'] ?? null;
                        $svcPort = isset($service['port']) ? (int)$service['port'] : null;
                        $svcSub = $service['subdomain'] ?? null;
                        $findings[] = $this->makeFinding($c, 'outdated_' . $softwareName, 'negative', -$outdatedPts, 'medium', 'Outdated Software: ' . ucfirst($softwareName), ucfirst($softwareName) . " {$version} is below minimum acceptable version {$minVersion}", $svcIp, $svcPort, $svcSub, ['Software' => ucfirst($softwareName), 'Detected Version' => $version, 'Minimum Required' => $minVersion]);
                        break; // Only count each software type once
                    }
                }
            }
        }

        // Positive: no outdated software detected (only if services were actually scanned)
        if (!$foundOutdated && !empty($services)) {
            $p = $this->pts($c, 'no_outdated_software');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_outdated_software', 'positive', $p, 'medium', 'No Outdated Software Detected', 'All detected software versions are within acceptable ranges', null, null, null, ['Services Scanned' => count($services)]);
        }

        return ['score' => max(0, min(100, $score)), 'findings' => $findings];
    }

    /**
     * Analyzes email security posture via DNS records and port/banner data.
     * Weight: 15%
     *
     * @param bool $allIpsBehindCdn If true, all IPs are behind CDN/WAF (e.g. Cloudflare).
     *                              Skip email findings since CDN providers don't handle email.
     */
    private function analyzeEmailSecurity(array $dnsData, array $ports, array $banners, bool $allIpsBehindCdn = false): array
    {
        $score = 50;
        $findings = [];
        $c = 'email_security';

        if ($allIpsBehindCdn) {
            // CDN providers proxy web traffic only -- they don't handle email.
            // Skip port-based checks (SMTP) but still run DNS-based checks
            // (SPF, DMARC, DKIM, MX, MTA-STS, NS) since those are always relevant.
            $ports = [];
            $banners = [];
        }

        // Determine if Shodan has positive evidence that a mail server is running.
        // Only flag missing SPF/DMARC if we can confirm mail services are present.
        // MX records alone are NOT sufficient -- they're inherited from the apex domain
        // and don't indicate that a specific subdomain actually sends/receives email.
        // We need SMTP ports (25, 587, 465) to be open as positive evidence.
        $hasSmtpPort = in_array(25, $ports) || in_array(587, $ports) || in_array(465, $ports);

        // Only consider it a mail server if SMTP ports are actually open
        $hasMailServerEvidence = $hasSmtpPort;

        // SPF analysis using configurable points
        $spf = $dnsData['spf'] ?? null;
        if ($spf !== null) {
            if (stripos($spf, '+all') !== false) {
                $p = $this->pts($c, 'spf_plus_all');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'spf_plus_all', 'negative', $p, 'high', 'SPF +all (Open Relay)', 'SPF record allows any server to send email for this domain', null, null, null, ['SPF Record' => $spf]);
            } elseif (stripos($spf, '-all') !== false) {
                $p = $this->pts($c, 'spf_hard_fail');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'spf_hard_fail', 'positive', $p, 'high', 'SPF Hard Fail (-all)', 'SPF record with strict enforcement', null, null, null, ['SPF Record' => $spf]);
            } elseif (stripos($spf, '~all') !== false) {
                $p = $this->pts($c, 'spf_soft_fail');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'spf_soft_fail', 'negative', $p, 'medium', 'SPF Soft Fail (~all)', 'SPF record uses soft fail (~all) instead of hard fail (-all) -- mail servers may still accept spoofed email', null, null, null, ['SPF Record' => $spf]);
            }
        } elseif ($hasMailServerEvidence) {
            // Only flag missing SPF if SMTP ports are open (positive evidence subdomain handles mail)
            $p = $this->pts($c, 'no_spf');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_spf', 'negative', $p, 'high', 'No SPF Record', 'No SPF record found -- domain vulnerable to email spoofing', null, null, null, ['DNS Query' => 'No TXT record containing v=spf1 found']);
        }

        // DMARC analysis
        $dmarc = $dnsData['dmarc'] ?? null;
        if ($dmarc !== null) {
            if (preg_match('/p\s*=\s*reject/i', $dmarc)) {
                $p = $this->pts($c, 'dmarc_reject');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'dmarc_reject', 'positive', $p, 'high', 'DMARC Reject Policy', 'DMARC policy set to reject unauthenticated email', null, null, null, ['DMARC Record' => $dmarc]);
            } elseif (preg_match('/p\s*=\s*quarantine/i', $dmarc)) {
                $p = $this->pts($c, 'dmarc_quarantine');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'dmarc_quarantine', 'positive', $p, 'high', 'DMARC Quarantine Policy', 'DMARC policy set to quarantine unauthenticated email', null, null, null, ['DMARC Record' => $dmarc]);
            } elseif (preg_match('/p\s*=\s*none/i', $dmarc)) {
                $p = $this->pts($c, 'dmarc_none');
                $score += $p;
                $findings[] = $this->makeFinding($c, 'dmarc_none', 'positive', $p, 'high', 'DMARC Monitor-Only', 'DMARC policy set to none (monitoring only)', null, null, null, ['DMARC Record' => $dmarc]);
            }
        } elseif ($hasMailServerEvidence) {
            // Only flag missing DMARC if SMTP ports are open (positive evidence subdomain handles mail)
            $p = $this->pts($c, 'no_dmarc');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'no_dmarc', 'negative', $p, 'high', 'No DMARC Record', 'No DMARC record found -- domain lacks email authentication policy', null, null, null, ['DNS Query' => 'No TXT record found at _dmarc subdomain']);
        }

        // DKIM
        if ($dnsData['dkim_found'] ?? false) {
            $p = $this->pts($c, 'dkim_found');
            $score += $p;
            $selector = $dnsData['dkim_selector'] ?? 'unknown';
            $findings[] = $this->makeFinding($c, 'dkim_found', 'positive', $p, 'high', 'DKIM Record Found', "DKIM record found (selector: {$selector})", null, null, null, ['DKIM Selector' => $selector, 'Record Found' => 'Yes']);
        }

        // Enterprise email gateway via MX records
        $mxRecords = $dnsData['mx_records'] ?? [];
        $gatewayDetected = false;
        $gatewayName = '';
        foreach ($mxRecords as $mx) {
            $mxLower = strtolower($mx);
            foreach (self::ENTERPRISE_EMAIL_GATEWAYS as $pattern => $name) {
                if (stripos($mxLower, $pattern) !== false) {
                    $gatewayDetected = true;
                    $gatewayName = $name;
                    break 2;
                }
            }
        }
        if ($gatewayDetected) {
            $p = $this->pts($c, 'enterprise_email_gateway');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'enterprise_email_gateway', 'positive', $p, 'high', 'Enterprise Email Gateway', "Email protected by {$gatewayName}", null, null, null, ['MX Records' => implode(', ', $mxRecords)]);
        }

        // MTA-STS
        if ($dnsData['has_mta_sts'] ?? false) {
            $p = $this->pts($c, 'mta_sts');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'mta_sts', 'positive', $p, 'high', 'MTA-STS Present', 'MTA Strict Transport Security is configured', null, null, null, ['MTA-STS Record' => 'Present']);
        }

        // Managed DNS
        $nsRecords = $dnsData['ns_records'] ?? [];
        $managedDns = false;
        $dnsProvider = '';
        foreach ($nsRecords as $ns) {
            $nsLower = strtolower($ns);
            foreach (self::MANAGED_DNS_PROVIDERS as $pattern => $name) {
                if (stripos($nsLower, $pattern) !== false) {
                    $managedDns = true;
                    $dnsProvider = $name;
                    break 2;
                }
            }
        }
        if ($managedDns) {
            $p = $this->pts($c, 'managed_dns');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'managed_dns', 'positive', $p, 'medium', 'Managed DNS Provider', "Using {$dnsProvider}", null, null, null, ['NS Records' => implode(', ', $nsRecords)]);
        }

        // Open SMTP relay (port 25)
        if (in_array(25, $ports)) {
            // Try to find SMTP banner for proof
            $smtpBanner = '';
            foreach ($banners as $banner) {
                if (($banner['port'] ?? 0) === 25) {
                    $smtpBanner = substr($banner['data'] ?? '', 0, 200);
                    break;
                }
            }
            $smtpProof = ['Port' => 25];
            if (!empty($smtpBanner)) {
                $smtpProof['SMTP Banner'] = $smtpBanner;
            }
            $p = $this->pts($c, 'open_smtp');
            $score += $p;
            $findings[] = $this->makeFinding($c, 'open_smtp', 'negative', $p, 'high', 'Open SMTP (Port 25)', 'SMTP port 25 is open -- potential for email relay abuse', null, null, null, $smtpProof);
        }

        return ['score' => max(0, min(100, $score)), 'findings' => $findings];
    }

    // =========================================================================
    // SCORING HELPERS
    // =========================================================================

    /**
     * Computes weighted final score from category scores.
     * Uses configurable weights (integer percentages that sum to 100).
     */
    public function computeWeightedScore(array $categoryScores, array $findings = []): int
    {
        $weights = $this->loadCategoryWeights();
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            $totalWeight = 100;
        }

        $weighted = 0;
        foreach ($weights as $category => $weight) {
            $weighted += ($categoryScores[$category] ?? 50) * ($weight / $totalWeight);
        }
        $score = (int)round($weighted);

        // Clean posture bonus: if every category >= 70 and no negative findings, add +8
        if (!empty($findings)) {
            $allAbove70 = true;
            foreach ($weights as $category => $weight) {
                if (($categoryScores[$category] ?? 50) < 70) {
                    $allAbove70 = false;
                    break;
                }
            }
            $hasNegative = false;
            foreach ($findings as $f) {
                if (($f['type'] ?? '') === 'negative' && empty($f['waived'])) {
                    $hasNegative = true;
                    break;
                }
            }
            if ($allAbove70 && !$hasNegative) {
                $score = min(100, $score + 8);
            }
        }

        return $score;
    }

    /**
     * Public wrapper for traffic light computation (used by ShodanService for waiver recomputation).
     */
    public function computeTrafficLight(array $categoryScores, array $allFindings): string
    {
        // Filter out waived findings before computing traffic light
        $activeFindings = array_filter($allFindings, fn($f) => empty($f['waived']));
        return $this->determineTrafficLight($categoryScores, array_values($activeFindings));
    }

    /**
     * Determines risk rating based on the ratio of positive to total signals.
     *
     * Formula: positive / (positive + negative) * 100
     *   >= 80  → green  (Acceptable)
     *   < 50   → red    (Unacceptable)
     *   else   → yellow (Needs Improvement)
     */
    private function determineTrafficLight(array $categoryScores, array $allFindings): string
    {
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($allFindings as $finding) {
            if (($finding['type'] ?? '') === 'positive') {
                $positiveCount++;
            } elseif (($finding['type'] ?? '') === 'negative') {
                $negativeCount++;
            }
        }

        $total = $positiveCount + $negativeCount;

        // No signals at all → default to yellow
        if ($total === 0) {
            return 'yellow';
        }

        $ratio = ($positiveCount / $total) * 100;

        if ($ratio >= 80) {
            return 'green';
        } elseif ($ratio < 50) {
            return 'red';
        }

        return 'yellow';
    }

    /**
     * Creates a standardized finding array.
     */
    private function makeFinding(
        string $category,
        string $signal,
        string $type,
        int $points,
        string $confidence,
        string $label,
        string $description,
        ?string $ip = null,
        ?int $port = null,
        ?string $subdomain = null,
        ?array $proof = null
    ): array {
        return [
            'category'    => $category,
            'signal'      => $signal,
            'type'        => $type,
            'points'      => $points,
            'confidence'  => $confidence,
            'label'       => $label,
            'description' => $description,
            'ip'          => $ip,
            'port'        => $port,
            'subdomain'   => $subdomain,
            'proof'       => $proof,
        ];
    }

    /**
     * Maps a CVSS score to a severity label.
     */
    private function cvssToSeverity(?float $cvss): string
    {
        if ($cvss === null) return 'unknown';
        if ($cvss >= 9.0) return 'critical';
        if ($cvss >= 7.0) return 'high';
        if ($cvss >= 4.0) return 'medium';
        if ($cvss >= 0.1) return 'low';
        return 'info';
    }

    // =========================================================================
    // 4TH PARTY RISK — TECHNOLOGY EXTRACTION
    // =========================================================================

    /** Maps Shodan http.components categories to our technology_category values */
    private const COMPONENT_CATEGORY_MAP = [
        'JavaScript libraries'  => 'js_library',
        'JavaScript frameworks' => 'framework',
        'UI frameworks'         => 'framework',
        'Web frameworks'        => 'framework',
        'CMS'                   => 'cms',
        'Programming languages' => 'programming_language',
        'Web servers'           => 'web_server',
        'Databases'             => 'database',
        'Caching'               => 'other',
        'Security'              => 'cdn_waf',
        'CDN'                   => 'cdn_waf',
        'Analytics'             => 'other',
        'Font scripts'          => 'other',
        'Advertising'           => 'other',
        'Tag managers'          => 'other',
        'Maps'                  => 'other',
        'Photo galleries'       => 'other',
        'Widgets'               => 'other',
        'Miscellaneous'         => 'other',
    ];

    /**
     * Extracts technology fingerprints from already-collected Shodan scan data.
     * No additional API calls — everything comes from banners, DNS, and host info
     * already gathered during scoreVendor().
     *
     * @param array  $banners     All banners collected during the scan
     * @param array  $hostResults Map of IP => hostInfo from Shodan
     * @param array  $dnsData     DNS analysis results (mx_records, ns_records, etc.)
     * @param string $domain      The primary domain being scanned
     * @param array  $vulns       All CVEs collected during scoring (optional)
     * @return array Array of technology records ready for storage
     */
    public function extractTechnologies(array $banners, array $hostResults, array $dnsData, string $domain, array $vulns = []): array
    {
        $technologies = [];
        $seen = []; // Dedup key: "name|category|detected_on"

        // Build IP:port => CVE list map from banner-level vulns
        $bannerCves = []; // "ip:port" => ["CVE-2024-1234" => ["cvss" => 9.8, "severity" => "critical"], ...]
        foreach ($banners as $banner) {
            if (!isset($banner['vulns']) || !is_array($banner['vulns'])) continue;
            $bIp = $banner['_ip'] ?? '';
            $bPort = $banner['port'] ?? 0;
            $key = $bIp . ':' . $bPort;
            foreach ($banner['vulns'] as $cveId => $vulnData) {
                if ($this->isCveTooOld($cveId)) continue;
                $cvss = null;
                $severity = 'unknown';
                if (is_array($vulnData) && isset($vulnData['cvss'])) {
                    $cvss = (float)$vulnData['cvss'];
                    $severity = $this->cvssToSeverity($cvss);
                }
                $bannerCves[$key][$cveId] = ['cvss' => $cvss, 'severity' => $severity];
            }
        }

        // Also build IP-level CVE map from host-level vulns
        $hostCves = []; // "ip" => ["CVE-..." => ...]
        foreach ($vulns as $v) {
            if ($this->isCveTooOld($v['cve_id'] ?? '')) continue;
            $hostCves[$v['ip']][$v['cve_id']] = [
                'cvss' => $v['cvss'],
                'severity' => $v['severity'],
            ];
        }

        $addTech = function(
            string $name,
            string $category,
            ?string $version = null,
            ?string $detectedOn = null,
            ?int $port = null,
            string $method = 'banner_product',
            string $confidence = 'medium',
            ?array $evidence = null,
            array $cves = []
        ) use (&$technologies, &$seen, $domain) {
            $detectedOn = $detectedOn ?: $domain;
            $key = strtolower($name) . '|' . $category . '|' . $detectedOn;
            if (isset($seen[$key])) {
                // Merge CVEs from additional detections and update version
                $idx = $seen[$key];
                if ($version && empty($technologies[$idx]['technology_version'])) {
                    $technologies[$idx]['technology_version'] = $version;
                }
                if (!empty($cves)) {
                    $existing = $technologies[$idx]['cves'] ?? [];
                    foreach ($cves as $cveId => $cveData) {
                        if (!isset($existing[$cveId])) {
                            $existing[$cveId] = $cveData;
                        }
                    }
                    $technologies[$idx]['cves'] = $existing;
                }
                return;
            }
            $seen[$key] = count($technologies);
            $technologies[] = [
                'technology_name'      => $name,
                'technology_category'  => $category,
                'technology_version'   => $version,
                'detected_on'          => $detectedOn,
                'detected_port'        => $port,
                'detection_method'     => $method,
                'detection_confidence' => $confidence,
                'raw_evidence'         => $evidence ? json_encode($evidence) : null,
                'cves'                 => $cves,
            ];
        };

        foreach ($banners as $banner) {
            $ip = $banner['_ip'] ?? '';
            $hostnames = $banner['_hostnames'] ?? [$domain];
            $subdomain = $hostnames[0] ?? $domain;
            $port = $banner['port'] ?? null;

            // Collect CVEs for this banner (IP:port level + IP-level fallback)
            $bCves = $bannerCves[$ip . ':' . $port] ?? [];
            if (empty($bCves) && isset($hostCves[$ip])) {
                $bCves = $hostCves[$ip];
            }

            // --- Banner product + version ---
            $product = $banner['product'] ?? null;
            if ($product) {
                $version = $banner['version'] ?? null;
                $cat = $this->inferCategoryFromProduct($product, $port);
                $addTech($product, $cat, $version, $subdomain, $port, 'banner_product', 'high',
                    ['product' => $product, 'version' => $version, 'port' => $port], $bCves);
            }

            // --- HTTP Server header ---
            $serverHeader = $banner['http']['server'] ?? null;
            if ($serverHeader) {
                $parsed = $this->parseServerHeader($serverHeader);
                if ($parsed) {
                    $addTech($parsed['name'], 'web_server', $parsed['version'], $subdomain, $port, 'http_server', 'high',
                        ['server_header' => $serverHeader], $bCves);
                }
            }

            // --- HTTP Components (Shodan's fingerprinted technologies) ---
            $components = $banner['http']['components'] ?? [];
            if (is_array($components)) {
                foreach ($components as $compName => $compData) {
                    $shodanCategories = $compData['categories'] ?? [];
                    $compVersion = null;
                    if (!empty($compData['versions'])) {
                        $compVersion = is_array($compData['versions']) ? end($compData['versions']) : (string)$compData['versions'];
                    }
                    $cat = 'other';
                    foreach ($shodanCategories as $sc) {
                        if (isset(self::COMPONENT_CATEGORY_MAP[$sc])) {
                            $cat = self::COMPONENT_CATEGORY_MAP[$sc];
                            break;
                        }
                    }
                    $addTech($compName, $cat, $compVersion, $subdomain, $port, 'http_component', 'high',
                        ['shodan_categories' => $shodanCategories], $bCves);
                }
            }

            // --- WAF/CDN from headers ---
            $httpHeaders = $this->extractHttpHeaders($banner);
            foreach (self::WAF_CDN_PATTERNS['headers'] as $headerKey => $wafName) {
                if (isset($httpHeaders[$headerKey])) {
                    $addTech($wafName, 'cdn_waf', null, $subdomain, $port, 'http_header', 'high',
                        ['header' => $headerKey], $bCves);
                }
            }

            // --- WAF/CDN from Server header ---
            if ($serverHeader) {
                $serverLower = strtolower($serverHeader);
                foreach (self::WAF_CDN_PATTERNS['server'] as $pattern => $wafName) {
                    if (stripos($serverLower, $pattern) !== false) {
                        $addTech($wafName, 'cdn_waf', null, $subdomain, $port, 'http_server', 'high',
                            ['server_header' => $serverHeader], $bCves);
                        break;
                    }
                }
            }

            // --- SSL Certificate Issuer ---
            $issuerCN = $banner['ssl']['cert']['issuer']['CN'] ?? null;
            $issuerO = $banner['ssl']['cert']['issuer']['O'] ?? null;
            $issuerName = $issuerO ?: $issuerCN;
            if ($issuerName) {
                $addTech($issuerName, 'ssl_ca', null, $subdomain, $port, 'ssl_cert', 'high',
                    ['issuer_cn' => $issuerCN, 'issuer_o' => $issuerO], $bCves);
            }

            // --- CPE identifiers ---
            $cpes = $banner['cpe'] ?? $banner['cpe23'] ?? [];
            if (is_array($cpes)) {
                foreach ($cpes as $cpe) {
                    $parsed = $this->parseCPE($cpe);
                    if ($parsed) {
                        $addTech($parsed['product'], $parsed['category'], $parsed['version'], $subdomain, $port, 'cpe', 'high',
                            ['cpe' => $cpe], $bCves);
                    }
                }
            }

            // --- Admin panels ---
            $title = strtolower($banner['http']['title'] ?? '');
            $prodLower = strtolower($product ?? '');
            foreach (self::ADMIN_PANELS as $pattern => $panelName) {
                if (stripos($title, $pattern) !== false || stripos($prodLower, $pattern) !== false) {
                    $addTech($panelName, 'admin_panel', null, $subdomain, $port, 'banner_product', 'high',
                        ['pattern' => $pattern, 'title' => $banner['http']['title'] ?? ''], $bCves);
                    break;
                }
            }

            // --- Database UIs ---
            foreach (self::DATABASE_UIS as $pattern => $dbName) {
                if (stripos($title, $pattern) !== false || stripos($prodLower, $pattern) !== false) {
                    $addTech($dbName, 'database', null, $subdomain, $port, 'banner_product', 'high',
                        ['pattern' => $pattern], $bCves);
                    break;
                }
            }
        }

        // --- Cloud platform from host org/ISP ---
        foreach ($hostResults as $ip => $hostInfo) {
            $org = strtolower($hostInfo['org'] ?? $hostInfo['isp'] ?? '');
            foreach (self::ENTERPRISE_CLOUD as $pattern => $cloudName) {
                if (stripos($org, $pattern) !== false) {
                    $addTech($cloudName, 'cloud_platform', null, $domain, null, 'asn_org', 'medium',
                        ['org' => $hostInfo['org'] ?? '', 'isp' => $hostInfo['isp'] ?? '']);
                    break;
                }
            }

            // --- WAF/CDN from ASN org ---
            foreach (self::WAF_CDN_PATTERNS['asn_org'] as $pattern => $wafName) {
                if (stripos($org, $pattern) !== false) {
                    $addTech($wafName, 'cdn_waf', null, $domain, null, 'asn_org', 'medium',
                        ['org' => $hostInfo['org'] ?? '']);
                    break;
                }
            }
        }

        // --- Database ports ---
        foreach ($banners as $banner) {
            $port = $banner['port'] ?? 0;
            if (isset(self::DATABASE_PORTS[$port])) {
                $bIp = $banner['_ip'] ?? '';
                $subdomain = ($banner['_hostnames'] ?? [$domain])[0] ?? $domain;
                $bCves = $bannerCves[$bIp . ':' . $port] ?? ($hostCves[$bIp] ?? []);
                $addTech(self::DATABASE_PORTS[$port], 'database', null, $subdomain, $port, 'open_port', 'medium',
                    ['port' => $port], $bCves);
            }
        }

        // --- Email gateways from MX records ---
        $mxRecords = $dnsData['mx_records'] ?? [];
        foreach ($mxRecords as $mx) {
            $mxLower = strtolower($mx);
            foreach (self::ENTERPRISE_EMAIL_GATEWAYS as $pattern => $gwName) {
                if (stripos($mxLower, $pattern) !== false) {
                    $addTech($gwName, 'email_gateway', null, $domain, null, 'dns_mx', 'high',
                        ['mx_record' => $mx]);
                    break;
                }
            }
        }

        // --- DNS providers from NS records ---
        $nsRecords = $dnsData['ns_records'] ?? [];
        foreach ($nsRecords as $ns) {
            $nsLower = strtolower($ns);
            foreach (self::MANAGED_DNS_PROVIDERS as $pattern => $dnsName) {
                if (stripos($nsLower, $pattern) !== false) {
                    $addTech($dnsName, 'dns_provider', null, $domain, null, 'dns_ns', 'high',
                        ['ns_record' => $ns]);
                    break;
                }
            }
        }

        // Convert CVE associative arrays to serializable format
        foreach ($technologies as &$tech) {
            if (!empty($tech['cves'])) {
                $cveList = [];
                foreach ($tech['cves'] as $cveId => $cveData) {
                    $cveList[] = [
                        'id' => $cveId,
                        'cvss' => $cveData['cvss'],
                        'severity' => $cveData['severity'],
                    ];
                }
                // Sort by CVSS descending (most severe first)
                usort($cveList, function($a, $b) {
                    return ($b['cvss'] ?? 0) <=> ($a['cvss'] ?? 0);
                });
                $tech['cves'] = $cveList;
            } else {
                $tech['cves'] = [];
            }
        }
        unset($tech);

        return $technologies;
    }

    /**
     * Infers a technology category from a banner product name and port.
     */
    private function inferCategoryFromProduct(string $product, ?int $port): string
    {
        $lower = strtolower($product);
        $webServers = ['apache', 'nginx', 'iis', 'lighttpd', 'caddy', 'litespeed', 'openresty'];
        foreach ($webServers as $ws) {
            if (stripos($lower, $ws) !== false) return 'web_server';
        }
        if (stripos($lower, 'openssl') !== false || stripos($lower, 'php') !== false) {
            return 'programming_language';
        }
        $cdnWafs = ['cloudflare', 'akamai', 'bigip', 'big-ip', 'f5 big', 'imperva', 'incapsula',
                    'sucuri', 'stackpath', 'fastly', 'cloudfront', 'awselb', 'amazon elb', 'aws elb'];
        foreach ($cdnWafs as $cw) {
            if (stripos($lower, $cw) !== false) return 'cdn_waf';
        }

        if ($port && isset(self::DATABASE_PORTS[$port])) return 'database';

        $dbProducts = ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis', 'elasticsearch', 'memcached'];
        foreach ($dbProducts as $db) {
            if (stripos($lower, $db) !== false) return 'database';
        }

        return 'other';
    }

    /**
     * Parses a Server header like "Apache/2.4.54 (Ubuntu)" into name + version.
     */
    private function parseServerHeader(string $header): ?array
    {
        // Match "Product/Version" or just "Product"
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9._-]+)(?:\/(\S+))?/', trim($header), $m)) {
            return ['name' => $m[1], 'version' => $m[2] ?? null];
        }
        return null;
    }

    /**
     * Parses a CPE string (cpe:2.3:a:vendor:product:version:...) into useful fields.
     */
    private function parseCPE(string $cpe): ?array
    {
        // CPE 2.3 format: cpe:2.3:part:vendor:product:version:...
        $parts = explode(':', $cpe);
        if (count($parts) < 5) return null;

        $product = str_replace('_', ' ', $parts[4] ?? '');
        $version = ($parts[5] ?? '*') !== '*' ? $parts[5] : null;

        if (empty($product) || $product === '*') return null;

        // Infer category from CPE part field
        $part = $parts[2] ?? 'a';
        $category = 'other';
        if ($part === 'o') $category = 'other'; // OS
        elseif ($part === 'h') $category = 'other'; // Hardware
        else $category = $this->inferCategoryFromProduct($product, null);

        return ['product' => ucwords($product), 'category' => $category, 'version' => $version];
    }
}
