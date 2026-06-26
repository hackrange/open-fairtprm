<?php
/**
 * RESTful API v2 Router - Token-Authenticated, IP-Whitelisted
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * All API v2 requests are routed through this file via .htaccess rewrite.
 * Authentication uses bearer tokens (SHA-512 hashed) with mandatory IP
 * whitelisting -- single IPs only, no CIDR ranges. Every request is logged.
 *
 * The router extracts the HTTP method and path, authenticates the token,
 * checks module access permissions, and dispatches to the appropriate handler.
 * Responses are always JSON with consistent error structure.
 */

// Resolve Authorization header -- Apache may strip it or prefix it after rewrite
$_resolvedAuthHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';
if (empty($_resolvedAuthHeader) && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $_resolvedAuthHeader = $v; break; }
    }
}

// Prevent direct browser access -- API only
if (php_sapi_name() !== 'cli' && empty($_resolvedAuthHeader) && !isset($_SERVER['HTTP_X_API_KEY'])) {
    // Swagger / Postman are opened from the admin UI via a session cookie (no
    // Authorization header), so let them reach init.php and the gated handler
    // below — which enforces admin-session OR valid-token auth (no longer public).
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/api/v2/(swagger|postman|openapi)#', $requestUri)) {
        // Defer the auth decision to the locked-down handler below.
    } else {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required', 'code' => 401]);
        exit;
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/init.php';

// CORS headers for API clients
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

$startTime = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Parse the path after /api/v2/
$basePath = '/api/v2';
$pathInfo = parse_url($requestUri, PHP_URL_PATH);
$path = substr($pathInfo, strlen($basePath));
$path = rtrim($path, '/');
if (empty($path)) $path = '/';

// Handle preflight CORS
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Detect protocol -- respect X-Forwarded-Proto from nginx (SSL terminates at nginx, not Apache)
$proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));

// API spec / Postman collection — LOCKED DOWN (previously public, which leaked the
// API surface to anonymous callers). Requires EITHER an authenticated admin
// session (the admin UI opens these in a browser, so there's no Authorization
// header) OR a valid API bearer token.
if (preg_match('#^/(swagger|openapi)(\.json)?$#', $path) || $path === '/postman') {
    $docAuthorized = false;
    try {
        $auth = Auth::getInstance();
        if ($auth->isAuthenticated() && ($auth->isAdmin() || Session::getInstance()->get('is_super_admin'))) {
            $docAuthorized = true;
        }
    } catch (Throwable $e) { /* fall through to token check */ }
    if (!$docAuthorized && preg_match('/^Bearer\s+(.+)$/i', $_resolvedAuthHeader, $m)) {
        try {
            if (APIService::getInstance()->authenticateToken(trim($m[1]), $_SERVER['REMOTE_ADDR'] ?? '')) {
                $docAuthorized = true;
            }
        } catch (Throwable $e) { /* not authorized */ }
    }
    if (!$docAuthorized) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required', 'code' => 401]);
        exit;
    }

    $apiService = APIService::getInstance();
    $baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $spec = $apiService->getSwaggerSpec($baseUrl);
    echo json_encode(
        $path === '/postman' ? generatePostmanCollection($spec) : $spec,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// ============================================================================
// AUTHENTICATION
// ============================================================================
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$bearerToken = '';

// Extract bearer token from Authorization header (resolved at top of file)
if (preg_match('/^Bearer\s+(.+)$/i', $_resolvedAuthHeader, $matches)) {
    $bearerToken = $matches[1];
}

if (empty($bearerToken)) {
    apiError(401, 'Bearer token required');
}

$apiService = APIService::getInstance();
$token = $apiService->authenticateToken($bearerToken, $clientIp);

if (!$token) {
    apiError(401, 'Invalid token, expired, or IP not whitelisted');
}

// ============================================================================
// ROUTING
// ============================================================================
$db = Database::getInstance();
$grc = GRCService::getInstance();

try {
    // Parse path segments
    $segments = array_values(array_filter(explode('/', $path)));
    $resource = $segments[0] ?? '';
    $resourceId = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;
    $subResource = $segments[2] ?? null;

    switch ($resource) {
        // ====================================================================
        // TPRM ENDPOINTS
        // ====================================================================
        case 'vendors':
            if (!$apiService->hasModuleAccess($token, 'tprm')) {
                apiError(403, 'No access to TPRM module');
            }
            // --- BOLA fix: scope vendor access to the token's user unless they are an
            // org-wide reviewer. Mirrors the web UI (vendor-onboarding-list.php) scoping.
            $tokenUserId = (int)($token['user_id'] ?? 0);
            $tokenGroups = array_column($db->fetchAll(
                'SELECT g.group_name FROM acl_groups g
                   JOIN user_acl_groups ug ON g.id = ug.group_id
                  WHERE ug.user_id = :uid AND g.is_active = 1', [':uid' => $tokenUserId]), 'group_name');
            $tokenIsSuperAdmin = !empty($db->fetchOne('SELECT 1 AS x FROM users WHERE id = :id AND is_super_admin = 1', [':id' => $tokenUserId]));
            $tokenCanReadAllVendors = $tokenIsSuperAdmin || (bool)array_intersect($tokenGroups, ['administrator', 'cyber_tprm', 'procurement', 'auditor']);
            $tokenCanApproveVendors = $tokenIsSuperAdmin || (bool)array_intersect($tokenGroups, ['administrator', 'cyber_tprm']);
            $tokenCanAccessVendor = function ($vid) use ($db, $tokenUserId, $tokenCanReadAllVendors) {
                if ($tokenCanReadAllVendors) return true;
                return !empty($db->fetchOne(
                    'SELECT 1 AS x FROM vendor_onboarding_requests r
                      WHERE r.id = :id AND (r.created_by = :u
                        OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s
                                   WHERE s.request_id = r.id AND s.user_id = :u2))',
                    [':id' => (int)$vid, ':u' => $tokenUserId, ':u2' => $tokenUserId]));
            };
            if ($method === 'GET' && $resourceId === null) {
                $status = $_GET['status'] ?? null;
                $search = $_GET['search'] ?? null;
                $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
                $offset = max(0, (int)($_GET['offset'] ?? 0));

                $where = ['1=1'];
                $params = [];
                if ($status) { $where[] = 'v.status = :s'; $params[':s'] = $status; }
                if ($search) { $where[] = '(v.vendor_name LIKE :q OR v.vendor_domain LIKE :q2)'; $params[':q'] = "%{$search}%"; $params[':q2'] = "%{$search}%"; }
                if (!$tokenCanReadAllVendors) {
                    $where[] = '(v.created_by = :scu OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s WHERE s.request_id = v.id AND s.user_id = :scu2))';
                    $params[':scu'] = $tokenUserId; $params[':scu2'] = $tokenUserId;
                }

                $sql = 'SELECT v.id, v.vendor_name, v.vendor_domain, v.status, v.vendor_tier, v.created_at, v.updated_at
                        FROM vendor_onboarding_requests v WHERE ' . implode(' AND ', $where) .
                       " ORDER BY v.vendor_name LIMIT {$limit} OFFSET {$offset}";
                $vendors = $db->fetchAll($sql, $params);
                $total = (int)($db->fetchOne('SELECT COUNT(*) as c FROM vendor_onboarding_requests v WHERE ' . implode(' AND ', $where), $params)['c'] ?? 0);

                apiSuccess(['data' => $vendors, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
            } elseif ($method === 'GET' && $resourceId !== null && $subResource === null) {
                if (!$tokenCanAccessVendor($resourceId)) apiError(404, 'Vendor not found');
                $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $resourceId]);
                if (!$vendor) apiError(404, 'Vendor not found');
                // Remove BLOB fields from API response
                unset($vendor['vendor_favicon']);
                // Include custom onboarding data: template-defined custom fields that
                // have no vendor_onboarding_requests column (values from assessment
                // responses). Same source as the Custom Data tab + CSV export.
                require_once dirname(dirname(__DIR__)) . '/includes/classes/VendorAssessmentService.php';
                $vasApi = new VendorAssessmentService();
                // Role gating: an API key inherits the visibility of its owning user.
                // Blank any standard onboarding columns, and skip any custom fields,
                // the token owner could not see in the GUI (no BOLA/IDOR via API).
                foreach ($vasApi->getHiddenOnboardingFieldNames($resourceId, $tokenGroups, $tokenIsSuperAdmin) as $hiddenFnl => $_unused) {
                    if (array_key_exists($hiddenFnl, $vendor)) $vendor[$hiddenFnl] = null;
                }
                $apiViewer = ['groups' => $tokenGroups, 'super' => $tokenIsSuperAdmin];
                $customData = [];
                foreach ($vasApi->getCustomOnboardingData($resourceId, $apiViewer) as $cf) {
                    $customData[] = [
                        'field_name'    => $cf['field_name'],
                        'label'         => $cf['label'],
                        'value'         => $cf['value'],
                        'type'          => $cf['type'],
                        'section'       => $cf['section'],
                        'template_name' => $cf['template_name'],
                    ];
                }
                $vendor['custom_onboarding_data'] = $customData;
                apiSuccess(['data' => $vendor]);
            } elseif ($method === 'GET' && $resourceId !== null && $subResource === 'srs') {
                if (!$tokenCanAccessVendor($resourceId)) apiError(404, 'Vendor not found');
                $scores = $db->fetchAll('SELECT * FROM vendor_srs_scores WHERE vendor_id = :id ORDER BY scored_at DESC LIMIT 10', [':id' => $resourceId]);
                apiSuccess(['data' => $scores]);
            } elseif ($method === 'GET' && $resourceId !== null && $subResource === 'assessments') {
                if (!$tokenCanAccessVendor($resourceId)) apiError(404, 'Vendor not found');
                $assessments = $db->fetchAll('SELECT id, vendor_id, template_id, status, created_at, completed_at FROM vendor_assessments WHERE vendor_id = :id ORDER BY created_at DESC', [':id' => $resourceId]);
                apiSuccess(['data' => $assessments]);
            } elseif ($method === 'PUT' && $resourceId !== null && $subResource === null) {
                if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                if (!$tokenCanAccessVendor($resourceId)) apiError(404, 'Vendor not found');
                $vendor = $db->fetchOne('SELECT id FROM vendor_onboarding_requests WHERE id = :id', [':id' => $resourceId]);
                if (!$vendor) apiError(404, 'Vendor not found');
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) apiError(400, 'Request body required');

                // Validate enum fields before touching the DB
                $validStatuses = ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive', 'evaluation'];
                if (isset($input['status']) && !in_array($input['status'], $validStatuses, true)) {
                    apiError(400, 'Invalid status. Allowed: ' . implode(', ', $validStatuses));
                }
                // BrokenFunctionAuth fix: privileged status transitions require an elevated role.
                if (isset($input['status']) && in_array($input['status'], ['approved', 'rejected', 'inactive'], true) && !$tokenCanApproveVendors) {
                    apiError(403, 'Changing vendor status to ' . $input['status'] . ' requires elevated privileges');
                }
                if (isset($input['vendor_tier']) && !in_array($input['vendor_tier'], ['1', '2', '3'], true)) {
                    apiError(400, 'Invalid vendor_tier. Allowed: 1, 2, 3');
                }

                $allowed = ['vendor_name', 'vendor_domain', 'status', 'vendor_tier', 'vendor_type',
                            'relationship_manager', 'product_service_description', 'primary_contact_email',
                            'primary_contact_details', 'primary_contact_title', 'primary_contact_phone',
                            'nda_in_place', 'business_impact', 'additional_information', 'status_notes'];
                $update = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $input)) $update[$f] = $input[$f];
                }
                if (empty($update)) apiError(400, 'No valid fields to update');
                $update['updated_at'] = date('Y-m-d H:i:s');
                $db->update('vendor_onboarding_requests', $update, 'id = :id', [':id' => $resourceId]);
                $updated = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $resourceId]);
                unset($updated['vendor_favicon']);
                apiSuccess(['data' => $updated]);
            } else {
                apiError(405, 'Method not allowed');
            }
            break;

        case 'fair':
            if (!$apiService->hasModuleAccess($token, 'tprm')) {
                apiError(403, 'No access to TPRM module');
            }
            if ($method === 'GET' && ($segments[1] ?? '') === 'analyses') {
                $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
                $offset = max(0, (int)($_GET['offset'] ?? 0));
                $analyses = $db->fetchAll(
                    "SELECT id, vendor_name, risk_level, annualized_loss_expectancy, created_at
                     FROM tprm_results ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
                );
                apiSuccess(['data' => $analyses]);
            } else {
                apiError(405, 'Method not allowed');
            }
            break;

        // ====================================================================
        // GRC ENDPOINTS
        // ====================================================================
        case 'grc':
            if (!$apiService->hasModuleAccess($token, 'grc')) {
                apiError(403, 'No access to GRC module');
            }

            $grcResource = $segments[1] ?? '';
            $grcId = isset($segments[2]) && is_numeric($segments[2]) ? (int)$segments[2] : null;
            $grcSub = $segments[3] ?? null;

            switch ($grcResource) {
                case 'dashboard':
                    if ($method === 'GET') {
                        apiSuccess(['data' => $grc->getDashboardStats()]);
                    }
                    break;

                case 'frameworks':
                    if ($method === 'GET' && $grcId === null) {
                        apiSuccess(['data' => $grc->getFrameworks()]);
                    } elseif ($method === 'GET' && $grcId !== null && $grcSub === 'requirements') {
                        apiSuccess(['data' => $grc->getAllRequirements($grcId)]);
                    } elseif ($method === 'GET' && $grcId !== null && $grcSub === 'status') {
                        apiSuccess(['data' => $grc->getFrameworkComplianceStatus($grcId)]);
                    } elseif ($method === 'GET' && $grcId !== null) {
                        $fw = $grc->getFramework($grcId);
                        if (!$fw) apiError(404, 'Framework not found');
                        apiSuccess(['data' => $fw]);
                    } elseif ($method === 'PUT' && $grcId !== null) {
                        if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                        $fw = $grc->getFramework($grcId);
                        if (!$fw) apiError(404, 'Framework not found');
                        $input = json_decode(file_get_contents('php://input'), true);
                        if (!$input) apiError(400, 'Request body required');
                        $allowed = ['name', 'description', 'is_active', 'compliance_year', 'scope_id'];
                        $update = [];
                        foreach ($allowed as $f) {
                            if (array_key_exists($f, $input)) $update[$f] = $input[$f];
                        }
                        if (!empty($update)) {
                            $db->update('grc_frameworks', $update, 'id = :id', [':id' => $grcId]);
                        }
                        apiSuccess(['data' => $grc->getFramework($grcId)]);
                    }
                    break;

                case 'controls':
                    if ($method === 'GET' && $grcId === null) {
                        $filters = array_intersect_key($_GET, array_flip(['status', 'framework_id', 'search', 'type']));
                        apiSuccess(['data' => $grc->getControls($filters)]);
                    } elseif ($method === 'GET' && $grcId !== null) {
                        $control = $grc->getControl($grcId);
                        if (!$control) apiError(404, 'Control not found');
                        apiSuccess(['data' => $control]);
                    } elseif ($method === 'POST' && $grcId === null) {
                        if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                        $input = json_decode(file_get_contents('php://input'), true);
                        if (!$input || empty($input['title'])) apiError(400, 'Title is required');
                        $id = $grc->createControl($input, (int)$token['user_id']);
                        apiSuccess(['data' => $grc->getControl($id)], 201);
                    } elseif ($method === 'PUT' && $grcId !== null) {
                        if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                        $input = json_decode(file_get_contents('php://input'), true);
                        if (!$input) apiError(400, 'Request body required');
                        $grc->updateControl($grcId, $input);
                        apiSuccess(['data' => $grc->getControl($grcId)]);
                    }
                    break;

                case 'evidence':
                    if ($method === 'GET') {
                        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
                        $evidence = $db->fetchAll(
                            'SELECT id, evidence_ref, title, evidence_type, collection_method, status,
                                    collected_at, valid_until,
                                    (valid_until IS NOT NULL AND valid_until < NOW()) AS is_expired
                             FROM grc_evidence ORDER BY collected_at DESC LIMIT ' . $limit
                        );
                        apiSuccess(['data' => $evidence]);
                    }
                    break;

                case 'policies':
                    $policyService = PolicyService::getInstance();
                    if ($method === 'GET' && $grcId === null) {
                        apiSuccess(['data' => $policyService->getPolicies()]);
                    } elseif ($method === 'GET' && $grcId !== null) {
                        $policy = $policyService->getPolicy($grcId);
                        if (!$policy) apiError(404, 'Policy not found');
                        apiSuccess(['data' => $policy]);
                    }
                    break;

                case 'requirements':
                    if ($method === 'PUT' && $grcId !== null) {
                        if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                        $req = $db->fetchOne('SELECT id FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
                        if (!$req) apiError(404, 'Requirement not found');
                        $input = json_decode(file_get_contents('php://input'), true);
                        if (!$input) apiError(400, 'Request body required');
                        $allowed = ['title', 'description', 'guidance', 'is_required', 'sort_order'];
                        $update = [];
                        foreach ($allowed as $f) {
                            if (array_key_exists($f, $input)) $update[$f] = $input[$f];
                        }
                        if (!empty($update)) {
                            $db->update('grc_framework_requirements', $update, 'id = :id', [':id' => $grcId]);
                        }
                        $updated = $db->fetchOne('SELECT * FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
                        apiSuccess(['data' => $updated]);
                    } elseif ($method === 'GET' && $grcId !== null) {
                        $req = $db->fetchOne('SELECT * FROM grc_framework_requirements WHERE id = :id', [':id' => $grcId]);
                        if (!$req) apiError(404, 'Requirement not found');
                        apiSuccess(['data' => $req]);
                    } else {
                        apiError(405, 'Method not allowed');
                    }
                    break;

                case 'audits':
                    if ($method === 'GET' && $grcId === null) {
                        $audits = $db->fetchAll(
                            'SELECT a.*, f.code as framework_code
                             FROM grc_audits a LEFT JOIN grc_frameworks f ON f.id = a.framework_id
                             ORDER BY a.created_at DESC'
                        );
                        apiSuccess(['data' => $audits]);
                    } elseif ($method === 'GET' && $grcId !== null) {
                        $audit = $db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $grcId]);
                        if (!$audit) apiError(404, 'Audit not found');
                        unset($audit['report_encrypted_data']);
                        $audit['findings'] = $db->fetchAll(
                            'SELECT * FROM grc_audit_findings WHERE audit_id = :aid ORDER BY severity DESC',
                            [':aid' => $grcId]
                        );
                        apiSuccess(['data' => $audit]);
                    } elseif ($method === 'PUT' && $grcId !== null) {
                        if (!$apiService->hasWriteAccess($token)) apiError(403, 'Write access required');
                        $audit = $db->fetchOne('SELECT id FROM grc_audits WHERE id = :id', [':id' => $grcId]);
                        if (!$audit) apiError(404, 'Audit not found');
                        $input = json_decode(file_get_contents('php://input'), true);
                        if (!$input) apiError(400, 'Request body required');

                        $validStatuses = ['planning', 'fieldwork', 'reporting', 'remediation', 'closed'];
                        if (isset($input['status']) && !in_array($input['status'], $validStatuses, true)) {
                            apiError(400, 'Invalid status. Allowed: ' . implode(', ', $validStatuses));
                        }
                        $validTypes = ['internal', 'external', 'certification', 'surveillance', 'readiness'];
                        if (isset($input['audit_type']) && !in_array($input['audit_type'], $validTypes, true)) {
                            apiError(400, 'Invalid audit_type. Allowed: ' . implode(', ', $validTypes));
                        }

                        $allowed = ['title', 'description', 'status', 'audit_type', 'planned_start', 'planned_end'];
                        $update = [];
                        foreach ($allowed as $f) {
                            if (array_key_exists($f, $input)) $update[$f] = $input[$f];
                        }
                        if (!empty($update)) {
                            $db->update('grc_audits', $update, 'id = :id', [':id' => $grcId]);
                        }
                        $updated = $db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $grcId]);
                        unset($updated['report_encrypted_data']);
                        apiSuccess(['data' => $updated]);
                    }
                    break;

                case 'crosswalk':
                    if ($method === 'GET') {
                        $srcId = (int)($_GET['source_framework_id'] ?? 0);
                        $tgtId = (int)($_GET['target_framework_id'] ?? 0);
                        if (!$srcId || !$tgtId) apiError(400, 'source_framework_id and target_framework_id required');
                        apiSuccess(['data' => $grc->getCrosswalk($srcId, $tgtId)]);
                    }
                    break;

                case 'monitors':
                    $cm = ContinuousMonitor::getInstance();
                    if ($method === 'GET') {
                        apiSuccess(['data' => $cm->getMonitors()]);
                    }
                    break;

                case 'risks':
                    if ($method === 'GET') {
                        $risks = $db->fetchAll('SELECT * FROM grc_risk_register ORDER BY created_at DESC');
                        apiSuccess(['data' => $risks]);
                    }
                    break;

                default:
                    apiError(404, 'GRC resource not found: ' . $grcResource);
            }
            break;

        default:
            apiError(404, 'Resource not found: ' . $resource);
    }
} catch (\Exception $e) {
    error_log('API error: ' . $e->getMessage());
    apiError(500, 'Internal server error');
}

// Log the successful request
$responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
$apiService->logRequest($token['id'] ?? null, $method, $path, $clientIp, http_response_code(), $responseTimeMs);

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function apiSuccess(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(int $code, string $message): void {
    global $apiService, $token, $clientIp, $method, $path, $startTime;
    $responseTimeMs = isset($startTime) ? (int)((microtime(true) - $startTime) * 1000) : null;

    if (isset($apiService)) {
        $apiService->logRequest($token['id'] ?? null, $method ?? 'GET', $path ?? '/',
            $clientIp ?? '', $code, $responseTimeMs, $message);
    }

    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message, 'code' => $code]);
    exit;
}

function generatePostmanCollection(array $swagger): array {
    $items = [];
    foreach ($swagger['paths'] as $path => $methods) {
        foreach ($methods as $method => $spec) {
            $items[] = [
                'name' => $spec['summary'] ?? "{$method} {$path}",
                'request' => [
                    'method' => strtoupper($method),
                    'header' => [
                        ['key' => 'Authorization', 'value' => 'Bearer {{api_token}}'],
                        ['key' => 'Content-Type', 'value' => 'application/json'],
                    ],
                    'url' => [
                        'raw' => '{{base_url}}' . $path,
                        'host' => ['{{base_url}}'],
                        'path' => array_values(array_filter(explode('/', $path))),
                    ],
                ],
            ];
        }
    }

    return [
        'info' => [
            'name' => 'TPRM & GRC API v2.6.2',
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'variable' => [
            ['key' => 'base_url', 'value' => $swagger['servers'][0]['url'] ?? ''],
            ['key' => 'api_token', 'value' => ''],
        ],
        'item' => $items,
    ];
}
