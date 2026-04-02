<?php
/**
 * AJAX endpoint: Check AI job queue status.
 * Returns JSON with job status and result when complete.
 *
 * Author: Tim Rice
 */
require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($jobId <= 0) {
    echo json_encode(['error' => 'Invalid job ID']);
    exit;
}

$db = Database::getInstance();
$auth = Auth::getInstance();
$user = $auth->getUser();

$job = $db->fetchOne(
    'SELECT id, job_type, status, result_payload, error_message, created_at, completed_at
     FROM ai_job_queue WHERE id = ? AND user_id = ?',
    [$jobId, $user['id']]
);

if (!$job) {
    echo json_encode(['error' => 'Job not found']);
    exit;
}

$response = [
    'status' => $job['status'],
    'job_type' => $job['job_type'],
];

if ($job['status'] === 'completed' && !empty($job['result_payload'])) {
    $response['result'] = json_decode($job['result_payload'], true);
} elseif ($job['status'] === 'failed') {
    $response['error'] = $job['error_message'] ?: 'Processing failed. Please try again.';
}

// Include a fresh CSRF token
$security = Security::getInstance();
$response['csrf_token'] = $security->getCSRFToken();

echo json_encode($response);
