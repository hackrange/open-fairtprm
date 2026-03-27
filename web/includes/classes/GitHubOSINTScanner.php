<?php
/**
 * GitHub OSINT Scanner — Direct GitHub Code Search API Integration
 *
 * Searches GitHub's Code Search API for accidentally exposed credentials,
 * API keys, database passwords, and sensitive configuration data related
 * to monitored vendors and domains. No AI model required.
 *
 * Requires a GitHub Personal Access Token (PAT) for authenticated searches
 * (30 requests/min). Falls back to unauthenticated (10 requests/min).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

class GitHubOSINTScanner {

    private const API_BASE = 'https://api.github.com/search/code';
    private const API_DELAY_MS = 2200000; // 2.2 seconds between requests (safe for 30/min)
    private const UNAUTH_DELAY_MS = 6500000; // 6.5 seconds for unauthenticated (10/min)
    private const MAX_RESULTS_PER_QUERY = 10;

    private ?string $pat;
    private int $requestCount = 0;
    private float $lastRequestTime = 0;

    /** Sensitive file names to search for each domain */
    private const SENSITIVE_FILES = [
        'application.properties',
        'application.yml',
        '.env',
        '.env.production',
        '.env.local',
        'config.yml',
        'config.json',
        'appsettings.json',
        'wp-config.php',
        'settings.py',
        'database.yml',
        'docker-compose.yml',
        'web.config',
        'terraform.tfvars',
        '.npmrc',
        '.pypirc',
        '.htpasswd',
        'credentials.yml',
        'secrets.yml',
    ];

    /** Credential keywords that indicate real secrets (not docs/examples) */
    private const CREDENTIAL_PATTERNS = [
        'password'              => 'Database/Application Password',
        'passwd'                => 'Password',
        'secret'                => 'Secret Key',
        'api_key'               => 'API Key',
        'apikey'                => 'API Key',
        'api-key'               => 'API Key',
        'access_key'            => 'Access Key',
        'aws_access_key_id'     => 'AWS Access Key',
        'aws_secret_access_key' => 'AWS Secret Key',
        'private_key'           => 'Private Key',
        'client_secret'         => 'OAuth Client Secret',
        'jwt_secret'            => 'JWT Secret',
        'encryption_key'        => 'Encryption Key',
        'smtp_password'         => 'SMTP Password',
        'mail_password'         => 'Mail Password',
        'db_password'           => 'Database Password',
        'database_password'     => 'Database Password',
        'mysql_root_password'   => 'MySQL Root Password',
        'postgres_password'     => 'PostgreSQL Password',
        'redis_password'        => 'Redis Password',
        'auth_key'              => 'Authentication Key',
        'secure_auth_key'       => 'Secure Auth Key',
        'session_secret'        => 'Session Secret',
        'app_key'               => 'Application Key',
        'datasource.password'   => 'Spring Datasource Password',
        'datasource.url'        => 'Database Connection String',
        'connection_string'     => 'Connection String',
        'connectionstring'      => 'Connection String',
    ];

    /** Well-known domains to skip — searching these returns only noise */
    private const SKIP_DOMAINS = [
        'google.com', 'microsoft.com', 'amazon.com', 'apple.com', 'facebook.com',
        'github.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com',
        'aws.amazon.com', 'azure.microsoft.com', 'cloud.google.com',
        'salesforce.com', 'oracle.com', 'ibm.com', 'adobe.com',
        'slack.com', 'zoom.us', 'dropbox.com', 'box.com',
        'twitter.com', 'linkedin.com', 'youtube.com',
    ];

    /** Patterns that indicate the file is an example/template, not real credentials */
    private const FALSE_POSITIVE_INDICATORS = [
        'example', 'sample', 'template', 'placeholder', 'your_', 'YOUR_',
        'xxx', 'XXX', 'changeme', 'CHANGEME', 'todo', 'TODO',
        '<your', '${', '{{', 'INSERT_', 'REPLACE_',
    ];

    public function __construct(?string $pat = null) {
        $this->pat = $pat;
    }

    /**
     * Scan for exposed credentials across all provided domains.
     *
     * @param array $domains       Array of domain strings to search
     * @param string $companyName  Company name for additional searches
     * @param callable|null $onProgress Progress callback
     * @param array $domainNameMap Optional map of domain => display name (e.g., ['example.com' => 'Example Corp'])
     * @return array Array of structured alert data
     */
    public function scan(array $domains, string $companyName = '', ?callable $onProgress = null, array $domainNameMap = []): array {
        $allFindings = [];

        // Search by domain
        $skipped = 0;
        foreach ($domains as $i => $domain) {
            // Skip well-known domains that would return only noise
            if (in_array(strtolower($domain), self::SKIP_DOMAINS)) {
                $skipped++;
                continue;
            }

            $domainBase = preg_replace('/\.(com|org|net|io|co|gov|edu|us|uk|ca|au|de|fr|in|jp)$/i', '', $domain);
            $displayName = $domainNameMap[$domain] ?? $domain;

            if ($onProgress) {
                $onProgress("OSINT GitHub scan: searching domain " . ($i + 1 - $skipped) . "/" . (count($domains) - $skipped) . " ({$displayName})...");
            }

            error_log("GitHubOSINTScanner: Searching domain {$domain} ({$displayName})");

            // Search using domain base as keyword, display name as entity label
            $findings = $this->searchForCredentials($domainBase, $displayName);
            $allFindings = array_merge($allFindings, $findings);
        }

        // Search by company name
        if (!empty($companyName)) {
            if ($onProgress) {
                $onProgress("OSINT GitHub scan: searching company name \"{$companyName}\"...");
            }
            error_log("GitHubOSINTScanner: Searching company name \"{$companyName}\"");

            $cnBase = strtolower(str_replace(' ', '', $companyName));
            $findings = $this->searchForCredentials($cnBase, $companyName);
            $allFindings = array_merge($allFindings, $findings);
        }

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($allFindings as $f) {
            $key = $f['source_urls'][0] ?? '';
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $f;
            }
        }

        error_log("GitHubOSINTScanner: Found " . count($unique) . " unique finding(s) across " . count($domains) . " domain(s)");
        return $unique;
    }

    /**
     * Search for credentials related to a specific domain/keyword.
     */
    private function searchForCredentials(string $keyword, string $displayName): array {
        $findings = [];

        // Focused searches — consolidated for speed. GitHub code search supports
        // multiple filename: qualifiers and OR operators in a single query.
        $searches = [
            // Config files with passwords (covers .env, application.properties, config.yml, docker-compose.yml, etc.)
            "\"{$keyword}\" password OR secret filename:.env OR filename:application.properties OR filename:config.yml OR filename:docker-compose.yml",
            // More config files (appsettings, wp-config, settings.py, database.yml, web.config)
            "\"{$keyword}\" password OR secret OR api_key filename:appsettings.json OR filename:wp-config.php OR filename:settings.py OR filename:database.yml",
            // AWS / cloud credentials and database connection strings
            "\"{$keyword}\" aws_access_key OR rds.amazonaws.com OR datasource.password OR jdbc OR connectionString",
            // API keys, tokens, and SMTP credentials
            "\"{$keyword}\" api_key OR client_secret OR smtp_password OR private_key OR jwt_secret",
        ];

        foreach ($searches as $query) {
            $results = $this->githubCodeSearch($query);

            if ($results === null) {
                continue; // Rate limited or error
            }

            foreach ($results as $item) {
                $finding = $this->analyzeResult($item, $displayName);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Execute a GitHub Code Search API request.
     *
     * @return array|null Array of result items, or null on error
     */
    private function githubCodeSearch(string $query): ?array {
        // Rate limiting
        $delay = $this->pat ? self::API_DELAY_MS : self::UNAUTH_DELAY_MS;
        $elapsed = (microtime(true) - $this->lastRequestTime) * 1000000;
        if ($elapsed < $delay && $this->lastRequestTime > 0) {
            usleep((int)($delay - $elapsed));
        }

        $url = self::API_BASE . '?' . http_build_query([
            'q'        => $query,
            'per_page' => self::MAX_RESULTS_PER_QUERY,
        ]);

        $headers = [
            'Accept: application/vnd.github.v3.text-match+json',
            'User-Agent: TPRM-OSINT-Scanner/1.0',
        ];

        if ($this->pat) {
            $headers[] = 'Authorization: token ' . $this->pat;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastRequestTime = microtime(true);
        $this->requestCount++;

        if ($httpCode === 403 || $httpCode === 429) {
            error_log("GitHubOSINTScanner: Rate limited (HTTP {$httpCode}). Waiting 60s...");
            sleep(60);
            return null;
        }

        if ($httpCode !== 200) {
            error_log("GitHubOSINTScanner: HTTP {$httpCode} for query: " . substr($query, 0, 100));
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['items'])) {
            return null;
        }

        return $data['items'];
    }

    /**
     * Analyze a GitHub code search result item for credential exposure.
     *
     * @return array|null Structured alert data, or null if not a real finding
     */
    private function analyzeResult(array $item, string $entityName): ?array {
        $htmlUrl = $item['html_url'] ?? '';
        $repoName = $item['repository']['full_name'] ?? '';
        $filePath = $item['path'] ?? '';
        $fileName = basename($filePath);

        // Extract text match fragments
        $fragments = [];
        foreach ($item['text_matches'] ?? [] as $match) {
            $fragments[] = $match['fragment'] ?? '';
        }
        $fragmentText = implode("\n", $fragments);

        if (empty($fragmentText)) {
            return null;
        }

        // Check for credential patterns in fragments
        $foundCredentials = [];
        foreach (self::CREDENTIAL_PATTERNS as $pattern => $label) {
            if (stripos($fragmentText, $pattern) !== false) {
                $foundCredentials[$pattern] = $label;
            }
        }

        if (empty($foundCredentials)) {
            return null;
        }

        // Check for false positives (example/template files)
        $lowerFragment = strtolower($fragmentText);
        $falsePositiveScore = 0;
        foreach (self::FALSE_POSITIVE_INDICATORS as $fp) {
            if (stripos($lowerFragment, strtolower($fp)) !== false) {
                $falsePositiveScore++;
            }
        }

        // If more than 2 false positive indicators, skip
        if ($falsePositiveScore > 2) {
            return null;
        }

        // Determine severity based on what was found
        $severity = 'medium';
        $exposedTypes = [];

        foreach ($foundCredentials as $pattern => $label) {
            if (in_array($pattern, ['aws_access_key_id', 'aws_secret_access_key', 'private_key'])) {
                $severity = 'critical';
                $exposedTypes[] = 'aws_access_key';
            } elseif (in_array($pattern, ['datasource.password', 'datasource.url', 'db_password', 'database_password', 'mysql_root_password', 'postgres_password', 'connection_string', 'connectionstring'])) {
                $severity = 'critical';
                $exposedTypes[] = 'mysql_password';
            } elseif (in_array($pattern, ['password', 'passwd'])) {
                if ($severity !== 'critical') $severity = 'high';
                $exposedTypes[] = 'connection_string';
            } elseif (in_array($pattern, ['api_key', 'apikey', 'api-key', 'secret', 'client_secret', 'jwt_secret'])) {
                if ($severity !== 'critical') $severity = 'high';
                $exposedTypes[] = 'api_token';
            } elseif (in_array($pattern, ['smtp_password', 'mail_password'])) {
                $exposedTypes[] = 'smtp_credentials';
            }
        }

        // Check for RDS/cloud endpoints
        if (preg_match('/rds\.amazonaws\.com|\.database\.azure\.com|\.cloudsql\./', $fragmentText)) {
            $severity = 'critical';
            $exposedTypes[] = 'rds_endpoint';
        }

        // Check for S3 buckets
        if (preg_match('/s3\.amazonaws\.com|s3:\/\//', $fragmentText)) {
            $exposedTypes[] = 's3_bucket';
        }

        $exposedTypes = array_unique($exposedTypes);
        if (empty($exposedTypes)) {
            $exposedTypes[] = 'other';
        }

        $credLabels = array_unique(array_values($foundCredentials));
        $credSummary = implode(', ', $credLabels);

        $title = "Exposed credentials found in GitHub: {$repoName}/{$fileName}";

        $summary = "A GitHub repository at {$repoName} contains a file ({$filePath}) "
            . "with exposed {$credSummary} related to \"{$entityName}\". "
            . "The file is publicly accessible and contains what appear to be real credentials "
            . "that should be rotated immediately.";

        if ($falsePositiveScore > 0) {
            $summary .= " Note: Some template/example indicators were detected — verify this is not a sample file.";
            if ($severity === 'critical') $severity = 'high';
        }

        return [
            'title'                => $title,
            'alert_type'           => 'osint_exposure',
            'severity'             => $severity,
            'affected_entity'      => $entityName,
            'affected_entity_type' => 'vendor',
            'affected_vendor_ids'  => null,
            'affected_technology'  => null,
            'vendor_count'         => 0,
            'source_urls'          => [$htmlUrl],
            'summary'              => $summary,
            'ai_analysis'          => $summary . "\n\nThis finding was identified through automated GitHub Code Search scanning of public repositories. "
                . "Exposed data types: " . implode(', ', $credLabels) . ". "
                . "Immediate action recommended: notify the vendor to rotate compromised credentials and remove or secure the source file.",
            'exposed_data_types'   => $exposedTypes,
        ];
    }
}
