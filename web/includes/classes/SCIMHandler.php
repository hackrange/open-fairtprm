<?php
/**
 * SCIM 2.0 Service Provider Handler
 *
 * Implements SCIM 2.0 (RFC 7643/7644) endpoints for user provisioning.
 * IdPs like Okta, Entra ID, and OneLogin push user lifecycle events
 * (create, update, deactivate) to these endpoints via REST API.
 *
 * Supported resources: Users
 * Authentication: Bearer token stored in app_config
 */

class SCIMHandler {
    private $db;
    private $baseUrl;

    private const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const SCHEMA_LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const SCHEMA_ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const SCHEMA_PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const SCHEMA_SP_CONFIG = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';
    private const SCHEMA_RESOURCE_TYPE = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';
    private const SCHEMA_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Schema';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->baseUrl = rtrim(baseUrl(), '/') . '/scim/v2';
    }

    /**
     * Authenticate the incoming request via Bearer token.
     */
    public function authenticate() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Fallback: read from getallheaders() if Apache stripped the env var
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($authHeader)) {
            $this->sendError(401, 'Authorization header required', 'invalidValue');
            return;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $this->sendError(401, 'Bearer token required', 'invalidValue');
            return;
        }

        $token = $matches[1];
        $storedHash = $this->getStoredTokenHash();

        if (empty($storedHash)) {
            $this->sendError(401, 'SCIM provisioning is not configured', 'invalidValue');
            return;
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            $this->sendError(401, 'Invalid bearer token', 'invalidValue');
            return;
        }
    }

    // ========================================================================
    // Resource Routing
    // ========================================================================

    public function handleUsers($method, $resourceId = null) {
        switch ($method) {
            case 'GET':
                if ($resourceId) {
                    $this->getUser($resourceId);
                } else {
                    $this->listUsers();
                }
                break;
            case 'POST':
                $this->createUser();
                break;
            case 'PUT':
                if (!$resourceId) {
                    $this->sendError(400, 'Resource ID required for PUT', 'invalidValue');
                    return;
                }
                $this->replaceUser($resourceId);
                break;
            case 'PATCH':
                if (!$resourceId) {
                    $this->sendError(400, 'Resource ID required for PATCH', 'invalidValue');
                    return;
                }
                $this->patchUser($resourceId);
                break;
            case 'DELETE':
                if (!$resourceId) {
                    $this->sendError(400, 'Resource ID required for DELETE', 'invalidValue');
                    return;
                }
                $this->deleteUser($resourceId);
                break;
            default:
                $this->sendError(405, 'Method not allowed', 'invalidValue');
        }
    }

    // ========================================================================
    // User CRUD
    // ========================================================================

    private function getUser($id) {
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id',
            [':id' => intval($id)]
        );

        if (!$user) {
            $this->sendError(404, 'User not found', 'noTarget');
            return;
        }

        $this->sendJson(200, $this->formatUser($user));
    }

    private function listUsers() {
        $startIndex = max(1, intval($_GET['startIndex'] ?? 1));
        $count = min(100, max(1, intval($_GET['count'] ?? 100)));
        $filter = $_GET['filter'] ?? '';

        $where = '1=1';
        $params = [];

        // Parse basic SCIM filter (supports userName eq "value" and email eq "value")
        if (!empty($filter)) {
            $parsed = $this->parseFilter($filter);
            if ($parsed) {
                $where = $parsed['where'];
                $params = $parsed['params'];
            }
        }

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM users WHERE {$where}",
            $params
        );
        $totalResults = intval($total['cnt']);

        $offset = $startIndex - 1;
        $users = $this->db->fetchAll(
            "SELECT * FROM users WHERE {$where} ORDER BY id ASC LIMIT {$count} OFFSET {$offset}",
            $params
        );

        $resources = [];
        foreach ($users as $user) {
            $resources[] = $this->formatUser($user);
        }

        $this->sendJson(200, [
            'schemas'      => [self::SCHEMA_LIST],
            'totalResults' => $totalResults,
            'startIndex'   => $startIndex,
            'itemsPerPage' => $count,
            'Resources'    => $resources,
        ]);
    }

    private function createUser() {
        $input = $this->getJsonInput();

        $userName = $input['userName'] ?? '';
        if (empty($userName)) {
            $this->sendError(400, 'userName is required', 'invalidValue');
            return;
        }

        // Extract email from emails array or fall back to userName
        $email = $this->extractEmail($input) ?: $userName;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError(400, 'Valid email address is required', 'invalidValue');
            return;
        }

        // Check for existing user by userName or email
        $existing = $this->db->fetchOne(
            'SELECT id FROM users WHERE username = :u OR LOWER(email) = :e',
            [':u' => $userName, ':e' => strtolower($email)]
        );

        if ($existing) {
            $this->sendError(409, 'User already exists', 'uniqueness');
            return;
        }

        // Extract name
        $fullName = $this->extractFullName($input) ?: $userName;

        // Generate a random password (SCIM users authenticate via SSO)
        $encryption = new Encryption();
        $randomPassword = $encryption->hashPassword(bin2hex(random_bytes(32)));

        // Check auto-activate setting from SAML config
        $samlConfig = $this->db->fetchOne('SELECT auto_activate FROM saml_config LIMIT 1');
        $defaultActive = ($samlConfig['auto_activate'] ?? 1) ? 1 : 0;
        $isActive = isset($input['active']) ? ($input['active'] ? 1 : 0) : $defaultActive;

        // Ensure unique username (allow @ for email-based usernames)
        $baseUsername = preg_replace('/[^a-zA-Z0-9._@-]/', '', $userName);
        if (empty($baseUsername)) {
            $baseUsername = $email;
        }
        $finalUsername = $baseUsername;
        $counter = 1;
        while ($this->db->fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $finalUsername])) {
            $finalUsername = $baseUsername . $counter;
            $counter++;
        }

        $this->db->insert('users', [
            'username'      => $finalUsername,
            'email'         => strtolower($email),
            'full_name'     => $fullName,
            'password_hash' => $randomPassword,
            'is_active'     => $isActive,
            'is_admin'      => 0,
            'department'    => $input['department'] ?? null,
            'job_title'     => $input['title'] ?? null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE username = :u',
            [':u' => $finalUsername]
        );

        if (!$user) {
            $this->sendError(500, 'Failed to create user', 'noTarget');
            return;
        }

        // Auto-assign to stakeholder group
        $stakeholderGroup = $this->db->fetchOne(
            "SELECT id FROM acl_groups WHERE group_name = 'stakeholder' AND is_active = 1"
        );
        if ($stakeholderGroup) {
            $acl = ACL::getInstance();
            $acl->assignUserToGroup($user['id'], $stakeholderGroup['id'], null);
        }

        $this->logAudit('scim_user_created', $user['id'], [
            'username' => $finalUsername, 'email' => $email, 'default_group' => 'stakeholder'
        ]);

        $this->sendJson(201, $this->formatUser($user));
    }

    private function replaceUser($id) {
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id',
            [':id' => intval($id)]
        );

        if (!$user) {
            $this->sendError(404, 'User not found', 'noTarget');
            return;
        }

        $input = $this->getJsonInput();

        $email = $this->extractEmail($input) ?: $user['email'];
        $fullName = $this->extractFullName($input) ?: $user['full_name'];
        $isActive = isset($input['active']) ? ($input['active'] ? 1 : 0) : $user['is_active'];

        $updateData = [
            'email'     => strtolower($email),
            'full_name' => $fullName,
            'is_active' => $isActive,
        ];

        if (isset($input['department'])) {
            $updateData['department'] = $input['department'];
        }
        if (isset($input['title'])) {
            $updateData['job_title'] = $input['title'];
        }

        $this->db->update('users', $updateData, 'id = :id', [':id' => $user['id']]);

        $this->logAudit('scim_user_updated', $user['id'], $updateData);

        $updated = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $user['id']]);
        $this->sendJson(200, $this->formatUser($updated));
    }

    private function patchUser($id) {
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id',
            [':id' => intval($id)]
        );

        if (!$user) {
            $this->sendError(404, 'User not found', 'noTarget');
            return;
        }

        $input = $this->getJsonInput();
        $operations = $input['Operations'] ?? $input['operations'] ?? [];

        if (empty($operations)) {
            $this->sendError(400, 'No operations provided', 'invalidValue');
            return;
        }

        $updateData = [];

        foreach ($operations as $op) {
            $operation = strtolower($op['op'] ?? '');
            $path = $op['path'] ?? '';
            $value = $op['value'] ?? null;

            if ($operation === 'replace' || $operation === 'add') {
                if ($path === 'active' || (empty($path) && isset($value['active']))) {
                    $activeVal = $path === 'active' ? $value : $value['active'];
                    $updateData['is_active'] = $activeVal ? 1 : 0;
                }
                if (empty($path) && is_array($value)) {
                    // Bulk replace: value is an object of attributes
                    if (isset($value['name'])) {
                        $updateData['full_name'] = $this->extractFullName(['name' => $value['name']]) ?: $user['full_name'];
                    }
                    if (isset($value['emails'])) {
                        $email = $this->extractEmail(['emails' => $value['emails']]);
                        if ($email) {
                            $updateData['email'] = strtolower($email);
                        }
                    }
                    if (isset($value['department'])) {
                        $updateData['department'] = $value['department'];
                    }
                    if (isset($value['title'])) {
                        $updateData['job_title'] = $value['title'];
                    }
                } elseif ($path === 'userName' || $path === 'emails[type eq \"work\"].value') {
                    // Don't change username to avoid breaking existing references
                    // but update email if it's an email change
                    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $updateData['email'] = strtolower($value);
                    }
                } elseif ($path === 'name.givenName' || $path === 'name.familyName') {
                    // Handle partial name updates
                    $parts = explode(' ', $user['full_name'], 2);
                    $given = $parts[0] ?? '';
                    $family = $parts[1] ?? '';
                    if ($path === 'name.givenName') {
                        $given = $value;
                    } else {
                        $family = $value;
                    }
                    $updateData['full_name'] = trim($given . ' ' . $family);
                } elseif ($path === 'name.formatted') {
                    $updateData['full_name'] = $value;
                } elseif ($path === 'department') {
                    $updateData['department'] = $value;
                } elseif ($path === 'title') {
                    $updateData['job_title'] = $value;
                }
            } elseif ($operation === 'remove') {
                if ($path === 'department') {
                    $updateData['department'] = null;
                } elseif ($path === 'title') {
                    $updateData['job_title'] = null;
                }
            }
        }

        if (!empty($updateData)) {
            $this->db->update('users', $updateData, 'id = :id', [':id' => $user['id']]);
            $this->logAudit('scim_user_patched', $user['id'], $updateData);
        }

        $updated = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $user['id']]);
        $this->sendJson(200, $this->formatUser($updated));
    }

    private function deleteUser($id) {
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id',
            [':id' => intval($id)]
        );

        if (!$user) {
            $this->sendError(404, 'User not found', 'noTarget');
            return;
        }

        // Soft-delete: deactivate the user rather than hard-delete
        $this->db->update('users', ['is_active' => 0], 'id = :id', [':id' => $user['id']]);

        $this->logAudit('scim_user_deactivated', $user['id'], ['username' => $user['username']]);

        http_response_code(204);
        exit;
    }

    // ========================================================================
    // Discovery Endpoints
    // ========================================================================

    public function handleServiceProviderConfig() {
        $this->sendJson(200, [
            'schemas'               => [self::SCHEMA_SP_CONFIG],
            'documentationUri'      => null,
            'patch'                 => ['supported' => true],
            'bulk'                  => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter'                => ['supported' => true, 'maxResults' => 100],
            'changePassword'        => ['supported' => false],
            'sort'                  => ['supported' => false],
            'etag'                  => ['supported' => false],
            'authenticationSchemes' => [
                [
                    'type'        => 'oauthbearertoken',
                    'name'        => 'OAuth Bearer Token',
                    'description' => 'Authentication scheme using the OAuth Bearer Token Standard',
                ],
            ],
        ]);
    }

    public function handleSchemas() {
        $this->sendJson(200, [
            'schemas'      => [self::SCHEMA_LIST],
            'totalResults' => 1,
            'Resources'    => [
                [
                    'schemas'     => [self::SCHEMA_SCHEMA],
                    'id'          => self::SCHEMA_USER,
                    'name'        => 'User',
                    'description' => 'User Account',
                    'attributes'  => [
                        ['name' => 'userName', 'type' => 'string', 'multiValued' => false, 'required' => true, 'mutability' => 'readWrite', 'uniqueness' => 'server'],
                        ['name' => 'name', 'type' => 'complex', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite',
                            'subAttributes' => [
                                ['name' => 'formatted', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                                ['name' => 'givenName', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                                ['name' => 'familyName', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                            ],
                        ],
                        ['name' => 'emails', 'type' => 'complex', 'multiValued' => true, 'required' => true, 'mutability' => 'readWrite',
                            'subAttributes' => [
                                ['name' => 'value', 'type' => 'string', 'multiValued' => false, 'required' => true, 'mutability' => 'readWrite'],
                                ['name' => 'type', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                                ['name' => 'primary', 'type' => 'boolean', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                            ],
                        ],
                        ['name' => 'active', 'type' => 'boolean', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                        ['name' => 'title', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                        ['name' => 'department', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite'],
                    ],
                ],
            ],
        ]);
    }

    public function handleResourceTypes() {
        $this->sendJson(200, [
            'schemas'      => [self::SCHEMA_LIST],
            'totalResults' => 1,
            'Resources'    => [
                [
                    'schemas'     => [self::SCHEMA_RESOURCE_TYPE],
                    'id'          => 'User',
                    'name'        => 'User',
                    'endpoint'    => '/Users',
                    'description' => 'User Account',
                    'schema'      => self::SCHEMA_USER,
                ],
            ],
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Format a user DB row into SCIM User resource.
     */
    private function formatUser($user) {
        $nameParts = explode(' ', $user['full_name'] ?? '', 2);
        $givenName = $nameParts[0] ?? '';
        $familyName = $nameParts[1] ?? '';

        return [
            'schemas'    => [self::SCHEMA_USER],
            'id'         => (string)$user['id'],
            'externalId' => $user['email'],
            'userName'   => $user['username'],
            'name'       => [
                'formatted'  => $user['full_name'] ?? '',
                'givenName'  => $givenName,
                'familyName' => $familyName,
            ],
            'emails'     => [
                [
                    'value'   => $user['email'],
                    'type'    => 'work',
                    'primary' => true,
                ],
            ],
            'active'     => (bool)$user['is_active'],
            'title'      => $user['job_title'] ?? '',
            'department' => $user['department'] ?? '',
            'meta'       => [
                'resourceType' => 'User',
                'created'      => $user['created_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($user['created_at'])) : null,
                'lastModified' => $user['updated_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($user['updated_at'])) : null,
                'location'     => $this->baseUrl . '/Users/' . $user['id'],
            ],
        ];
    }

    /**
     * Extract email from SCIM emails array.
     */
    private function extractEmail($input) {
        $emails = $input['emails'] ?? [];
        if (empty($emails)) {
            return '';
        }

        // Look for primary email first
        foreach ($emails as $e) {
            if (!empty($e['primary']) && !empty($e['value'])) {
                return $e['value'];
            }
        }

        // Fall back to first email with a value
        foreach ($emails as $e) {
            if (!empty($e['value'])) {
                return $e['value'];
            }
        }

        return '';
    }

    /**
     * Extract full name from SCIM name object.
     */
    private function extractFullName($input) {
        $name = $input['name'] ?? [];
        if (empty($name)) {
            // Try displayName as fallback
            return $input['displayName'] ?? '';
        }

        if (!empty($name['formatted'])) {
            return $name['formatted'];
        }

        $parts = [];
        if (!empty($name['givenName'])) {
            $parts[] = $name['givenName'];
        }
        if (!empty($name['familyName'])) {
            $parts[] = $name['familyName'];
        }

        return implode(' ', $parts);
    }

    /**
     * Parse a basic SCIM filter expression.
     * Supports: userName eq "value", emails[value eq "value"], externalId eq "value"
     */
    private function parseFilter($filter) {
        // userName eq "value" — also match by email since many IdPs use email as userName
        if (preg_match('/^userName\s+eq\s+"([^"]+)"$/i', $filter, $m)) {
            return [
                'where'  => '(username = :filter_val OR LOWER(email) = :filter_email)',
                'params' => [':filter_val' => $m[1], ':filter_email' => strtolower($m[1])],
            ];
        }

        // emails.value eq "value" or emails[value eq "value"]
        if (preg_match('/^emails\[?\.?value\s+eq\s+"([^"]+)"\]?$/i', $filter, $m)) {
            return [
                'where'  => 'LOWER(email) = :filter_val',
                'params' => [':filter_val' => strtolower($m[1])],
            ];
        }

        // externalId eq "value" (we use email as externalId)
        if (preg_match('/^externalId\s+eq\s+"([^"]+)"$/i', $filter, $m)) {
            return [
                'where'  => 'LOWER(email) = :filter_val',
                'params' => [':filter_val' => strtolower($m[1])],
            ];
        }

        // email eq "value" (convenience, non-standard)
        if (preg_match('/^email\s+eq\s+"([^"]+)"$/i', $filter, $m)) {
            return [
                'where'  => 'LOWER(email) = :filter_val',
                'params' => [':filter_val' => strtolower($m[1])],
            ];
        }

        return null;
    }

    /**
     * Get the stored SCIM bearer token hash from app_config.
     */
    private function getStoredTokenHash() {
        $row = $this->db->fetchOne(
            "SELECT config_value FROM app_config WHERE config_key = 'scim_bearer_token_hash'"
        );
        return $row ? $row['config_value'] : '';
    }

    /**
     * Get JSON input from request body.
     */
    private function getJsonInput() {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            $this->sendError(400, 'Request body is required', 'invalidValue');
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(400, 'Invalid JSON: ' . json_last_error_msg(), 'invalidValue');
        }

        return $data;
    }

    /**
     * Send a JSON response.
     */
    public function sendJson($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/scim+json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send a SCIM error response.
     */
    public function sendError($statusCode, $detail, $scimType = null) {
        http_response_code($statusCode);
        header('Content-Type: application/scim+json; charset=utf-8');

        $error = [
            'schemas' => [self::SCHEMA_ERROR],
            'detail'  => $detail,
            'status'  => (string)$statusCode,
        ];

        if ($scimType) {
            $error['scimType'] = $scimType;
        }

        echo json_encode($error, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Log an audit event for SCIM operations.
     */
    private function logAudit($action, $userId, $data = []) {
        try {
            $security = Security::getInstance();
            $this->db->insert('audit_log', [
                'user_id'    => null,
                'action'     => $action,
                'table_name' => 'users',
                'record_id'  => $userId,
                'new_values' => json_encode($data),
                'ip_address' => $security->getClientIP(),
                'user_agent' => $security->getUserAgent(),
            ]);
        } catch (Exception $e) {
            error_log('SCIM audit log error: ' . $e->getMessage());
        }
    }
}
