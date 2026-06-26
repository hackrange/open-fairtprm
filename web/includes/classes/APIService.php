<?php
/**
 * RESTful API Service - Token Auth, IP Whitelisting, Rate Limiting
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Provides secure API authentication using bearer tokens with SHA-512 hashing,
 * per-token IP whitelisting (single IPs only, no CIDR), per-minute rate
 * limiting, and comprehensive request logging. The token is shown exactly
 * once at creation and stored as a hash -- if you lose it, generate a new one.
 *
 * Supports both TPRM and GRC module access with read or read_write scopes.
 * Every request is logged for audit trail purposes.
 */

class APIService {
    private static $instance = null;
    private $db;
    private $encryption;

    private function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate a new API token. Returns the raw token (shown once) and the token record.
     */
    public function createToken(array $data, int $createdBy): array {
        $rawToken = 'tprm_' . bin2hex(random_bytes(32));
        $tokenHash = hash('sha512', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 8);

        $this->db->insert('api_tokens', [
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'] ?? 'read',
            'module_access' => json_encode($data['module_access'] ?? ['both']),
            'user_id' => $data['user_id'] ?? $createdBy,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 60,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $createdBy,
        ]);

        $tokenId = (int)$this->db->lastInsertId();

        // Add IP whitelist entries (single IPs only)
        if (!empty($data['allowed_ips'])) {
            foreach ($data['allowed_ips'] as $ip) {
                $ip = trim($ip);
                // Reject CIDR notation - single IPs only
                if (strpos($ip, '/') !== false) {
                    continue;
                }
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $this->db->insert('api_token_ips', [
                    'token_id' => $tokenId,
                    'ip_address' => $ip,
                    'label' => $data['ip_labels'][$ip] ?? null,
                ]);
            }
        }

        return [
            'token_id' => $tokenId,
            'raw_token' => $rawToken,
            'token_prefix' => $tokenPrefix,
        ];
    }

    /**
     * Authenticate a bearer token and verify IP whitelist.
     * Returns the token record if valid, null otherwise.
     */
    public function authenticateToken(string $bearerToken, string $clientIp): ?array {
        $tokenHash = hash('sha512', $bearerToken);

        $token = $this->db->fetchOne(
            'SELECT * FROM api_tokens WHERE token_hash = :hash AND is_active = 1',
            [':hash' => $tokenHash]
        );

        if (!$token) return null;

        // Check expiry
        if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
            return null;
        }

        // Check IP whitelist -- MANDATORY, no IPs = token is useless
        $allowedIps = $this->db->fetchAll(
            'SELECT ip_address FROM api_token_ips WHERE token_id = :tid',
            [':tid' => $token['id']]
        );

        if (empty($allowedIps)) {
            // No IPs configured = token is locked out (security by default)
            return null;
        }

        $ipList = array_column($allowedIps, 'ip_address');
        if (!in_array($clientIp, $ipList, true)) {
            $this->logRequest($token['id'], $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? '/', $clientIp, 403, null,
                'IP not in whitelist');
            return null;
        }

        // Rate limiting
        if (!$this->checkRateLimit($token)) {
            $this->logRequest($token['id'], $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? '/', $clientIp, 429, null,
                'Rate limit exceeded');
            return null;
        }

        // Update last used
        $this->db->update('api_tokens', [
            'last_used_at' => date('Y-m-d H:i:s'),
            'last_used_ip' => $clientIp,
            'request_count' => (int)$token['request_count'] + 1,
        ], 'id = :id', [':id' => $token['id']]);

        return $token;
    }

    /**
     * Check if the token has exceeded its rate limit.
     */
    private function checkRateLimit(array $token): bool {
        $limit = (int)($token['rate_limit_per_minute'] ?? 60);
        $count = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM api_request_log
             WHERE token_id = :tid AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            [':tid' => $token['id']]
        )['c'] ?? 0);

        return $count < $limit;
    }

    /**
     * Check if token has access to the requested module.
     */
    public function hasModuleAccess(array $token, string $module): bool {
        $access = json_decode($token['module_access'] ?? '["both"]', true);
        return in_array('both', $access) || in_array($module, $access);
    }

    /**
     * Check if token has write access.
     */
    public function hasWriteAccess(array $token): bool {
        return $token['scope'] === 'read_write';
    }

    /**
     * Log an API request for audit trail.
     */
    public function logRequest(?int $tokenId, string $method, string $endpoint, string $ip,
                               int $responseCode, ?int $responseTimeMs = null, ?string $errorMessage = null): void {
        $this->db->insert('api_request_log', [
            'token_id' => $tokenId,
            'method' => $method,
            'endpoint' => substr($endpoint, 0, 500),
            'request_ip' => $ip,
            'response_code' => $responseCode,
            'response_time_ms' => $responseTimeMs,
            'error_message' => $errorMessage,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }

    /**
     * Get a single token by ID (for editing).
     */
    public function getToken(int $tokenId): ?array {
        $token = $this->db->fetchOne(
            'SELECT t.*, u.full_name as user_name, u.email as user_email
             FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.id = :id',
            [':id' => $tokenId]
        );
        return $token ?: null;
    }

    /**
     * Update token metadata (name, description, scope, module access, rate limit, expiry, user).
     */
    public function updateToken(int $tokenId, array $data): void {
        $allowed = ['name', 'description', 'scope', 'module_access', 'user_id', 'rate_limit_per_minute', 'expires_at'];
        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (!empty($update)) {
            $this->db->update('api_tokens', $update, 'id = :id', [':id' => $tokenId]);
        }
    }

    /**
     * Remove an IP from a token's whitelist.
     */
    public function removeTokenIp(int $ipId): void {
        $this->db->delete('api_token_ips', 'id = :id', [':id' => $ipId]);
    }

    /**
     * Get all tokens (for admin management).
     */
    public function getTokens(): array {
        return $this->db->fetchAll(
            'SELECT t.*, u.full_name as user_name, u.email as user_email,
                    (SELECT COUNT(*) FROM api_token_ips WHERE token_id = t.id) as ip_count,
                    (SELECT COUNT(*) FROM api_request_log WHERE token_id = t.id AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as requests_24h
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             ORDER BY t.created_at DESC'
        );
    }

    /**
     * Get IPs for a specific token.
     */
    public function getTokenIps(int $tokenId): array {
        return $this->db->fetchAll(
            'SELECT * FROM api_token_ips WHERE token_id = :tid ORDER BY ip_address',
            [':tid' => $tokenId]
        );
    }

    /**
     * Add an IP to a token's whitelist (single IP only).
     */
    public function addTokenIp(int $tokenId, string $ip, ?string $label = null): bool {
        $ip = trim($ip);
        if (strpos($ip, '/') !== false) return false; // No CIDR
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

        $exists = $this->db->fetchOne(
            'SELECT id FROM api_token_ips WHERE token_id = :tid AND ip_address = :ip',
            [':tid' => $tokenId, ':ip' => $ip]
        );
        if ($exists) return false;

        $this->db->insert('api_token_ips', [
            'token_id' => $tokenId,
            'ip_address' => $ip,
            'label' => $label,
        ]);
        return true;
    }

    /**
     * Revoke (deactivate) a token.
     */
    public function revokeToken(int $tokenId): void {
        $this->db->update('api_tokens', ['is_active' => 0], 'id = :id', [':id' => $tokenId]);
    }

    /**
     * Reactivate a revoked token.
     */
    public function reactivateToken(int $tokenId): void {
        $this->db->update('api_tokens', ['is_active' => 1], 'id = :id', [':id' => $tokenId]);
    }

    /**
     * Permanently delete a token and its IP whitelist and request logs.
     */
    public function deleteToken(int $tokenId): void {
        $this->db->delete('api_request_log', 'token_id = :id', [':id' => $tokenId]);
        $this->db->delete('api_token_ips', 'token_id = :id', [':id' => $tokenId]);
        $this->db->delete('api_tokens', 'id = :id', [':id' => $tokenId]);
    }

    /**
     * Get API request logs (for audit).
     */
    public function getRequestLogs(array $filters = [], int $limit = 100, int $offset = 0): array {
        $sql = 'SELECT arl.*, t.name as token_name, t.token_prefix
                FROM api_request_log arl
                LEFT JOIN api_tokens t ON t.id = arl.token_id';

        $where = [];
        $params = [];

        if (!empty($filters['token_id'])) {
            $where[] = 'arl.token_id = :tid';
            $params[':tid'] = $filters['token_id'];
        }
        if (!empty($filters['response_code'])) {
            $where[] = 'arl.response_code = :code';
            $params[':code'] = $filters['response_code'];
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY arl.created_at DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Generate OpenAPI 3.1 / Swagger specification.
     */
    public function getSwaggerSpec(string $baseUrl): array {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'TPRM & GRC Unified API',
                'description' => 'RESTful API for Third-Party Risk Management and Governance, Risk & Compliance.',
                'version' => '2.6.2',
                'contact' => ['name' => 'API Support'],
            ],
            'servers' => [['url' => rtrim($baseUrl, '/') . '/api/v2']],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'API Token',
                        'description' => 'API token prefixed with tprm_. Must be accompanied by whitelisted source IP.',
                    ],
                ],
            ],
            'paths' => $this->getSwaggerPaths(),
        ];
    }

    private function getSwaggerPaths(): array {
        return [
            '/vendors' => [
                'get' => [
                    'summary' => 'List vendors',
                    'description' => 'Retrieve a paginated list of vendor onboarding requests. Supports filtering by status and keyword search.',
                    'tags' => ['TPRM'],
                    'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'description' => 'Filter by vendor status', 'schema' => ['type' => 'string']],
                        ['name' => 'search', 'in' => 'query', 'description' => 'Search vendor name or domain', 'schema' => ['type' => 'string']],
                        ['name' => 'limit', 'in' => 'query', 'description' => 'Max results per page (1-200)', 'schema' => ['type' => 'integer', 'default' => 50]],
                        ['name' => 'offset', 'in' => 'query', 'description' => 'Pagination offset', 'schema' => ['type' => 'integer', 'default' => 0]],
                    ],
                    'responses' => ['200' => ['description' => 'List of vendors with pagination metadata']],
                ],
            ],
            '/vendors/{id}' => [
                'get' => [
                    'summary' => 'Get vendor details',
                    'description' => 'Retrieve full details for a single vendor by ID. The response also includes a "custom_onboarding_data" array: custom fields defined on the vendor\'s onboarding template that have no standard vendor column (each entry has field_name, label, value, type, section, template_name).',
                    'tags' => ['TPRM'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Vendor ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Vendor details, including custom_onboarding_data[]'], '404' => ['description' => 'Vendor not found']],
                ],
                'put' => [
                    'summary' => 'Update vendor',
                    'description' => 'Update vendor fields. Requires read_write scope with TPRM access.',
                    'tags' => ['TPRM'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Vendor ID', 'schema' => ['type' => 'integer']]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'vendor_name' => ['type' => 'string', 'description' => 'Vendor name'],
                            'vendor_domain' => ['type' => 'string', 'description' => 'Primary domain'],
                            'status' => ['type' => 'string', 'description' => 'Vendor status', 'enum' => ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive', 'evaluation']],
                            'vendor_tier' => ['type' => 'string', 'description' => 'Risk tier', 'enum' => ['1', '2', '3']],
                            'vendor_type' => ['type' => 'string', 'description' => 'Vendor type/category'],
                            'relationship_manager' => ['type' => 'string', 'description' => 'Relationship manager name'],
                            'product_service_description' => ['type' => 'string', 'description' => 'Product/service description'],
                            'primary_contact_email' => ['type' => 'string', 'description' => 'Primary contact email'],
                            'status_notes' => ['type' => 'string', 'description' => 'Status change notes'],
                        ]]]],
                    ],
                    'responses' => ['200' => ['description' => 'Vendor updated'], '403' => ['description' => 'Write access required'], '404' => ['description' => 'Vendor not found']],
                ],
            ],
            '/vendors/{id}/srs' => [
                'get' => [
                    'summary' => 'Get vendor SRS scores',
                    'description' => 'Retrieve the last 10 Security Rating Service scores for a vendor.',
                    'tags' => ['TPRM'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Vendor ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'SRS score history']],
                ],
            ],
            '/vendors/{id}/assessments' => [
                'get' => [
                    'summary' => 'Get vendor assessments',
                    'description' => 'List all assessments submitted for a specific vendor.',
                    'tags' => ['TPRM'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Vendor ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Assessment list']],
                ],
            ],
            '/fair/analyses' => [
                'get' => [
                    'summary' => 'List FAIR analyses',
                    'description' => 'Retrieve a paginated list of FAIR risk analyses with risk level and ALE.',
                    'tags' => ['TPRM'],
                    'parameters' => [
                        ['name' => 'limit', 'in' => 'query', 'description' => 'Max results (1-200)', 'schema' => ['type' => 'integer', 'default' => 50]],
                        ['name' => 'offset', 'in' => 'query', 'description' => 'Pagination offset', 'schema' => ['type' => 'integer', 'default' => 0]],
                    ],
                    'responses' => ['200' => ['description' => 'FAIR analysis list']],
                ],
            ],
            '/grc/dashboard' => [
                'get' => [
                    'summary' => 'Get GRC dashboard statistics',
                    'description' => 'Retrieve aggregated compliance statistics across all frameworks, controls, and risks.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Dashboard analytics data']],
                ],
            ],
            '/grc/frameworks' => [
                'get' => [
                    'summary' => 'List compliance frameworks',
                    'description' => 'Retrieve all compliance frameworks with their compliance status percentages.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Framework list with compliance status']],
                ],
            ],
            '/grc/frameworks/{id}' => [
                'get' => [
                    'summary' => 'Get framework details',
                    'description' => 'Retrieve a single compliance framework by ID.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Framework ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Framework details'], '404' => ['description' => 'Framework not found']],
                ],
                'put' => [
                    'summary' => 'Update framework',
                    'description' => 'Update framework metadata. Requires read_write scope.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Framework ID', 'schema' => ['type' => 'integer']]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Framework name'],
                            'description' => ['type' => 'string'],
                            'is_active' => ['type' => 'boolean'],
                            'compliance_year' => ['type' => 'integer'],
                            'scope_id' => ['type' => 'integer', 'description' => 'Scope ID or null'],
                        ]]]],
                    ],
                    'responses' => ['200' => ['description' => 'Framework updated'], '403' => ['description' => 'Write access required']],
                ],
            ],
            '/grc/frameworks/{id}/requirements' => [
                'get' => [
                    'summary' => 'Get framework requirements',
                    'description' => 'List all requirements for a framework with their control mappings and compliance status.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Framework ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Requirement list with control mappings']],
                ],
            ],
            '/grc/requirements/{id}' => [
                'put' => [
                    'summary' => 'Update requirement status',
                    'description' => 'Update a requirement compliance status or notes. Requires read_write scope.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Requirement ID', 'schema' => ['type' => 'integer']]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Requirement title'],
                            'description' => ['type' => 'string', 'description' => 'Requirement description'],
                            'guidance' => ['type' => 'string', 'description' => 'Implementation guidance'],
                            'is_required' => ['type' => 'boolean', 'description' => 'Whether requirement is mandatory'],
                            'sort_order' => ['type' => 'integer', 'description' => 'Display sort order'],
                        ]]]],
                    ],
                    'responses' => ['200' => ['description' => 'Requirement updated'], '403' => ['description' => 'Write access required']],
                ],
            ],
            '/grc/frameworks/{id}/status' => [
                'get' => [
                    'summary' => 'Get framework compliance status',
                    'description' => 'Retrieve compliance statistics (total, compliant, non-compliant, N/A) for a framework.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Framework ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Compliance status breakdown']],
                ],
            ],
            '/grc/controls' => [
                'get' => [
                    'summary' => 'List internal controls',
                    'description' => 'Retrieve all internal controls. Supports filtering by status, framework, type, and keyword.',
                    'tags' => ['GRC'],
                    'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'description' => 'Filter by control status', 'schema' => ['type' => 'string']],
                        ['name' => 'framework_id', 'in' => 'query', 'description' => 'Filter by framework ID', 'schema' => ['type' => 'integer']],
                        ['name' => 'type', 'in' => 'query', 'description' => 'Filter by control type', 'schema' => ['type' => 'string']],
                        ['name' => 'search', 'in' => 'query', 'description' => 'Search control title', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Control list']],
                ],
                'post' => [
                    'summary' => 'Create internal control',
                    'description' => 'Create a new internal control. Requires read_write scope.',
                    'tags' => ['GRC'],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['title'], 'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Control title'],
                            'description' => ['type' => 'string'],
                            'control_type' => ['type' => 'string', 'enum' => ['preventive', 'detective', 'corrective']],
                            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'inactive']],
                        ]]]],
                    ],
                    'responses' => ['201' => ['description' => 'Control created'], '403' => ['description' => 'Write access required']],
                ],
            ],
            '/grc/controls/{id}' => [
                'get' => [
                    'summary' => 'Get control details',
                    'description' => 'Retrieve a single control with its framework and requirement mappings.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Control ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Control details with mappings'], '404' => ['description' => 'Control not found']],
                ],
                'put' => [
                    'summary' => 'Update control',
                    'description' => 'Update an existing control. Requires read_write scope.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Control ID', 'schema' => ['type' => 'integer']]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                        ]]]],
                    ],
                    'responses' => ['200' => ['description' => 'Control updated'], '403' => ['description' => 'Write access required']],
                ],
            ],
            '/grc/evidence' => [
                'get' => [
                    'summary' => 'List evidence artifacts',
                    'description' => 'Retrieve evidence records with expiration status.',
                    'tags' => ['GRC'],
                    'parameters' => [
                        ['name' => 'limit', 'in' => 'query', 'description' => 'Max results (1-200)', 'schema' => ['type' => 'integer', 'default' => 50]],
                    ],
                    'responses' => ['200' => ['description' => 'Evidence list']],
                ],
            ],
            '/grc/policies' => [
                'get' => [
                    'summary' => 'List policies',
                    'description' => 'Retrieve all governance policies with version and approval status.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Policy list']],
                ],
            ],
            '/grc/policies/{id}' => [
                'get' => [
                    'summary' => 'Get policy details',
                    'description' => 'Retrieve a single policy by ID.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Policy ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Policy details'], '404' => ['description' => 'Policy not found']],
                ],
            ],
            '/grc/audits' => [
                'get' => [
                    'summary' => 'List audits',
                    'description' => 'Retrieve all audits with framework association and finding counts.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Audit list']],
                ],
            ],
            '/grc/audits/{id}' => [
                'get' => [
                    'summary' => 'Get audit details with findings',
                    'description' => 'Retrieve a single audit by ID including all findings sorted by severity.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Audit ID', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Audit details with findings'], '404' => ['description' => 'Audit not found']],
                ],
                'put' => [
                    'summary' => 'Update audit',
                    'description' => 'Update audit status and details. Requires read_write scope.',
                    'tags' => ['GRC'],
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Audit ID', 'schema' => ['type' => 'integer']]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Audit title'],
                            'status' => ['type' => 'string', 'description' => 'Audit status', 'enum' => ['planning', 'fieldwork', 'reporting', 'remediation', 'closed']],
                            'description' => ['type' => 'string', 'description' => 'Audit description'],
                            'audit_type' => ['type' => 'string', 'description' => 'Audit type', 'enum' => ['internal', 'external', 'certification', 'surveillance', 'readiness']],
                            'planned_start' => ['type' => 'string', 'format' => 'date', 'description' => 'Planned start date (YYYY-MM-DD)'],
                            'planned_end' => ['type' => 'string', 'format' => 'date', 'description' => 'Planned end date (YYYY-MM-DD)'],
                        ]]]],
                    ],
                    'responses' => ['200' => ['description' => 'Audit updated'], '403' => ['description' => 'Write access required']],
                ],
            ],
            '/grc/risks' => [
                'get' => [
                    'summary' => 'List risk register entries',
                    'description' => 'Retrieve all entries from the GRC risk register.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Risk register list']],
                ],
            ],
            '/grc/crosswalk' => [
                'get' => [
                    'summary' => 'Get framework crosswalk',
                    'description' => 'Retrieve the requirement-level mapping between two compliance frameworks.',
                    'tags' => ['GRC'],
                    'parameters' => [
                        ['name' => 'source_framework_id', 'in' => 'query', 'required' => true, 'description' => 'Source framework ID', 'schema' => ['type' => 'integer']],
                        ['name' => 'target_framework_id', 'in' => 'query', 'required' => true, 'description' => 'Target framework ID', 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => ['200' => ['description' => 'Crosswalk mapping data']],
                ],
            ],
            '/grc/monitors' => [
                'get' => [
                    'summary' => 'List continuous monitors',
                    'description' => 'Retrieve all continuous monitoring checks with their current status.',
                    'tags' => ['GRC'],
                    'responses' => ['200' => ['description' => 'Monitor list with status']],
                ],
            ],
        ];
    }
}
