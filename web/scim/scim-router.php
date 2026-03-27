<?php
/**
 * SCIM 2.0 API Router
 *
 * Single entry point for all SCIM 2.0 requests. Routes based on
 * the URI path to the appropriate SCIMHandler method.
 *
 * Routing:
 *   /scim/v2/Users            → handleUsers (GET list, POST create)
 *   /scim/v2/Users/{id}       → handleUsers (GET, PUT, PATCH, DELETE)
 *   /scim/v2/ServiceProviderConfig → handleServiceProviderConfig
 *   /scim/v2/Schemas          → handleSchemas
 *   /scim/v2/ResourceTypes    → handleResourceTypes
 */

require_once __DIR__ . '/../includes/init.php';

// SCIM always speaks JSON
header('Content-Type: application/scim+json; charset=utf-8');

$scim = new SCIMHandler();

// Parse the request path
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/scim/v2/';
$relativePath = '';

$pos = strpos($requestUri, $basePath);
if ($pos !== false) {
    $relativePath = substr($requestUri, $pos + strlen($basePath));
}

$relativePath = rtrim($relativePath, '/');
$segments = $relativePath !== '' ? explode('/', $relativePath) : [];
$resource = $segments[0] ?? '';
$resourceId = $segments[1] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// ServiceProviderConfig, Schemas, and ResourceTypes are public discovery endpoints.
// All other endpoints require authentication.
$publicEndpoints = ['ServiceProviderConfig', 'Schemas', 'ResourceTypes'];

if (!in_array($resource, $publicEndpoints, true)) {
    $scim->authenticate();
}

switch ($resource) {
    case 'Users':
        $scim->handleUsers($method, $resourceId);
        break;

    case 'ServiceProviderConfig':
        if ($method !== 'GET') {
            $scim->sendError(405, 'Method not allowed', 'invalidValue');
        }
        $scim->handleServiceProviderConfig();
        break;

    case 'Schemas':
        if ($method !== 'GET') {
            $scim->sendError(405, 'Method not allowed', 'invalidValue');
        }
        $scim->handleSchemas();
        break;

    case 'ResourceTypes':
        if ($method !== 'GET') {
            $scim->sendError(405, 'Method not allowed', 'invalidValue');
        }
        $scim->handleResourceTypes();
        break;

    case '':
        // Root /scim/v2/ - return basic info
        $scim->sendJson(200, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 3,
            'Resources' => [
                ['endpoint' => '/Users', 'description' => 'User provisioning'],
                ['endpoint' => '/ServiceProviderConfig', 'description' => 'Service provider configuration'],
                ['endpoint' => '/Schemas', 'description' => 'Available schemas'],
            ],
        ]);
        break;

    default:
        $scim->sendError(404, "Resource '{$resource}' not found", 'noTarget');
}
