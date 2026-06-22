#!/usr/bin/env php
<?php
/**
 * AI Job Queue Processor
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Processes AI tasks that were queued by the web UI so that long-running AI
 * API calls don't block Apache worker threads. Runs every minute via cron.
 *
 * Supported job types:
 *   - fair_analysis        (FAIR risk analysis via AI)
 *   - contract_pricing     (contract document pricing extraction via AI)
 *   - assessment_autofill  (assessment questionnaire auto-fill via AI)
 *
 * Flow:
 *   1. User clicks an AI button in the web UI
 *   2. Web endpoint validates input, builds prompt, queues job in ai_job_queue
 *   3. This cron picks up pending jobs, calls the AI API, stores results
 *   4. Frontend polling detects completion and displays results
 *
 * Usage:
 *   php ai-queue.php [--verbose] [--help]
 */

// ---- CLI GUARD ----
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ---- PATH SETUP ----
define('CRON_ROOT', dirname(__FILE__));
$_appRoot = dirname(CRON_ROOT);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $_appRoot);
    define('INCLUDES_PATH', $_appRoot . '/includes');
    define('CLASSES_PATH', $_appRoot . '/includes/classes');
    define('CONFIG_PATH', $_appRoot . '/config');
}
chdir(APP_ROOT);

// ---- PARSE CLI ARGUMENTS ----
$options = getopt('', ['verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$verbose = isset($options['verbose']);

// ---- BOOTSTRAP THE APP ----
try {
    require_once APP_ROOT . '/includes/init.php';
    require_once APP_ROOT . '/includes/classes/AIPlatformService.php';
} catch (Exception $e) {
    CronHelper::error("Failed to initialize application: " . $e->getMessage());
    exit(1);
}

// ---- LOCK FILE ----
$cron = new CronHelper('ai-queue');

if (!$cron->acquireLock()) {
    if ($verbose) {
        CronHelper::warn("Another instance is already running. Exiting.");
    }
    exit(0);
}

$exitCode = 0;

try {
    $db = Database::getInstance();

    // ---- FIND PENDING JOBS ----
    // Process up to 3 jobs per run (AI calls are heavy, don't overload)
    $pending = $db->fetchAll(
        "SELECT id, job_type, user_id, request_payload, created_at
         FROM ai_job_queue
         WHERE status = 'pending'
         ORDER BY created_at ASC
         LIMIT 3"
    );

    // Recover stuck jobs: if a job has been 'processing' for over 5 minutes,
    // the worker likely crashed. Reset it to 'failed' so the user gets feedback.
    $db->query(
        "UPDATE ai_job_queue SET status = 'failed', error_message = 'Processing timed out. Please try again.', completed_at = NOW()
         WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );

    // Clean up old completed/failed jobs (older than 24 hours)
    $db->query(
        "DELETE FROM ai_job_queue WHERE status IN ('completed', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );

    if (empty($pending)) {
        if ($verbose) {
            CronHelper::info("No pending AI jobs found.");
        }
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);

    CronHelper::info("Found " . count($pending) . " pending AI job(s)");

    $ai = AIPlatformService::getInstance();
    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    $errors = [];

    foreach ($pending as $job) {
        $jobId = (int) $job['id'];
        $jobType = $job['job_type'];

        // Safety: skip jobs older than 10 minutes (user probably gave up)
        $createdAt = strtotime($job['created_at']);
        if ($createdAt && (time() - $createdAt) > 600) {
            CronHelper::warn("Job #{$jobId} ({$jobType}): older than 10 minutes, marking as failed");
            $db->update('ai_job_queue', [
                'status' => 'failed',
                'error_message' => 'Job timed out in queue (exceeded 10 minutes)',
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $jobId]);
            continue;
        }

        // Atomic claim: set status to processing
        $db->query(
            "UPDATE ai_job_queue SET status = 'processing', started_at = NOW() WHERE id = ? AND status = 'pending'",
            [$jobId]
        );

        // Verify we claimed it
        $check = $db->fetchOne("SELECT status FROM ai_job_queue WHERE id = ?", [$jobId]);
        if (!$check || $check['status'] !== 'processing') {
            if ($verbose) {
                CronHelper::info("Job #{$jobId}: already claimed by another process, skipping");
            }
            continue;
        }

        $processed++;
        CronHelper::info("Processing job #{$jobId} ({$jobType})");

        $payload = json_decode($job['request_payload'], true);
        if (!$payload || empty($payload['messages'])) {
            $db->update('ai_job_queue', [
                'status' => 'failed',
                'error_message' => 'Invalid request payload',
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $jobId]);
            $failed++;
            $errors[] = "Job #{$jobId}: Invalid request payload";
            continue;
        }

        try {
            // Call AI
            $aiOptions = $payload['ai_options'] ?? [];
            $result = $ai->chatCompletion($payload['messages'], $aiOptions);

            if (!$result['success']) {
                throw new Exception('Service error: ' . ($result['error'] ?? 'Unknown error'));
            }

            // Post-process based on job type
            $resultData = postProcessResult($jobType, $result['content'], $payload['context'] ?? []);

            $db->update('ai_job_queue', [
                'status' => 'completed',
                'result_payload' => json_encode($resultData),
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $jobId]);

            $succeeded++;
            CronHelper::info("  Job #{$jobId} completed successfully");

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $db->update('ai_job_queue', [
                'status' => 'failed',
                'error_message' => $errorMsg,
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $jobId]);
            $failed++;
            $errors[] = "Job #{$jobId}: {$errorMsg}";
            CronHelper::error("  Job #{$jobId} failed: {$errorMsg}");
        }
    }

    if ($processed > 0) {
        CronHelper::info("Queue processing complete: {$processed} processed, {$succeeded} succeeded, {$failed} failed");
    }

    $cron->logExecutionComplete($db, $processed, $succeeded, $failed, $errors);

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($db)) {
        $cron->logExecutionFailed($db, $e->getMessage());
    }
    $exitCode = 1;
}

$cron->releaseLock();
exit($exitCode);


// =============================================================================
// POST-PROCESSING FUNCTIONS
// =============================================================================

/**
 * Post-process the raw AI response based on job type.
 * Each job type has its own parsing/validation logic.
 */
function postProcessResult(string $jobType, string $content, array $context): array
{
    switch ($jobType) {
        case 'fair_analysis':
            return postProcessFairAnalysis($content, $context);
        case 'contract_pricing':
            return postProcessContractPricing($content);
        case 'assessment_autofill':
            return postProcessAssessmentAutofill($content, $context);
        case 'detailed_summary':
            return postProcessDetailedSummary($content);
        case 'grc_assistant':
            return postProcessGrcAssistant($content, $context);
        case 'grc_suggest_control':
            return postProcessGrcSuggestControl($content, $context);
        case 'grc_report':
            return postProcessGrcReport($content);
        case 'grc_requirement_desc':
            return postProcessGrcRequirementDesc($content, $context);
        default:
            throw new Exception("Unknown job type: {$jobType}");
    }
}

/**
 * Parse and validate FAIR analysis AI response.
 * Mirrors the validation logic from generate-fair-ai-analysis.php.
 */
function postProcessFairAnalysis(string $content, array $context): array
{
    // Strip markdown code fences
    $content = preg_replace('/```json\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = preg_replace('/```/', '', $content);
    $content = trim($content);

    // Extract JSON if there's extra text
    if ($content[0] !== '{') {
        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');
        if ($jsonStart !== false && $jsonEnd !== false) {
            $content = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
        }
    }

    $aiEstimates = json_decode($content, true);

    if (!$aiEstimates || !isset($aiEstimates['annualized_loss_expectancy'])) {
        throw new Exception('AI returned an invalid response format. Please try again.');
    }

    // Validate and clamp values. TEF and vulnerability are bounded, then LEF is
    // RECOMPUTED server-side as TEF x vulnerability and capped at the real-world ceiling
    // of 1.0 -- we do not trust the model's own multiplication, and an LEF above one
    // material loss event/year for a single vendor is not realistic (published breach
    // base rates put it at ~0.05-0.15, poor-posture vendors ~0.3-0.5). ALE is then
    // recomputed from the capped LEF so the whole result stays internally consistent.
    // This mirrors FairCalculator's LEF_CEILING on the deterministic path.
    $aiEstimates['threat_event_frequency'] = max(0.01, min(6.0, floatval($aiEstimates['threat_event_frequency'] ?? 0.5)));
    $aiEstimates['vulnerability_score'] = max(0.01, min(0.6, floatval($aiEstimates['vulnerability_score'] ?? 0.2)));
    $aiEstimates['primary_loss_magnitude'] = max(0, floatval($aiEstimates['primary_loss_magnitude'] ?? 0));
    $aiEstimates['secondary_loss_magnitude'] = max(0, floatval($aiEstimates['secondary_loss_magnitude'] ?? 0));

    $aiEstimates['loss_event_frequency'] = min(1.0, $aiEstimates['threat_event_frequency'] * $aiEstimates['vulnerability_score']);
    $lossMagnitude = $aiEstimates['primary_loss_magnitude'] + $aiEstimates['secondary_loss_magnitude'];
    $aiEstimates['annualized_loss_expectancy'] = $aiEstimates['loss_event_frequency'] * $lossMagnitude;

    $validRiskLevels = ['Very Low', 'Low', 'Medium', 'High', 'Very High', 'Critical'];
    if (!in_array($aiEstimates['risk_level'] ?? '', $validRiskLevels)) {
        $aiEstimates['risk_level'] = 'Medium';
    }

    $validConfidence = ['Low', 'Medium', 'High'];
    if (!in_array($aiEstimates['confidence'] ?? '', $validConfidence)) {
        $aiEstimates['confidence'] = 'Medium';
    }

    $aiEstimates['reasoning'] = strval($aiEstimates['reasoning'] ?? 'No reasoning provided.');

    // Apply revenue cap
    $maxALE = floatval($context['max_ale'] ?? 0);
    if ($maxALE > 0 && $aiEstimates['annualized_loss_expectancy'] > $maxALE) {
        $aiEstimates['annualized_loss_expectancy'] = $maxALE;
        $aiEstimates['revenue_cap_applied'] = true;
    }

    // Enforce low-risk cap
    if (!empty($context['is_low_risk_capped'])) {
        $aiEstimates['primary_loss_magnitude'] = 0;
        $aiEstimates['secondary_loss_magnitude'] = 0;
        $aiEstimates['annualized_loss_expectancy'] = min($aiEstimates['annualized_loss_expectancy'], 1000);
        $aiEstimates['risk_level'] = 'Very Low';
        $aiEstimates['recommended_cyber_insurance'] = min($aiEstimates['recommended_cyber_insurance'], 3000);
        $aiEstimates['low_risk_cap_applied'] = true;
    }

    // Derive insurance and risk level from the FINAL (post-cap) ALE so the headline
    // number, the recommended cover, and the risk label can never disagree. Thresholds
    // mirror FairCalculator::determineRiskLevel.
    $ale = $aiEstimates['annualized_loss_expectancy'];
    if (empty($aiEstimates['low_risk_cap_applied'])) {
        $aiEstimates['recommended_cyber_insurance'] = $ale * 3;
    }
    if      ($ale < 1000)    { $aiEstimates['risk_level'] = 'Very Low'; }
    elseif  ($ale < 10000)   { $aiEstimates['risk_level'] = 'Low'; }
    elseif  ($ale < 50000)   { $aiEstimates['risk_level'] = 'Medium'; }
    elseif  ($ale < 250000)  { $aiEstimates['risk_level'] = 'High'; }
    elseif  ($ale < 1000000) { $aiEstimates['risk_level'] = 'Very High'; }
    else                     { $aiEstimates['risk_level'] = 'Critical'; }

    return ['success' => true, 'ai_estimates' => $aiEstimates];
}

/**
 * Parse and validate contract pricing AI response.
 * Mirrors the validation logic from contract-pricing-ai-extract.php.
 */
function postProcessContractPricing(string $content): array
{
    // Strip markdown fences
    $content = preg_replace('/```json\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = preg_replace('/```/', '', $content);
    $content = trim($content);

    $firstBrace = strpos($content, '{');
    $lastBrace = strrpos($content, '}');
    if ($firstBrace === false || $lastBrace === false) {
        throw new Exception('AI returned an invalid response. Please try again.');
    }

    $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
    $parsed = json_decode($jsonStr, true);

    if (!$parsed || !is_array($parsed)) {
        throw new Exception('AI returned an invalid response. Please try again.');
    }

    // Validate and sanitize fields
    $validFields = [
        'contractId', 'systemOfRecordLink', 'renewalType', 'billingFrequency',
        'renewalNoticeWindow', 'terminationRights', 'annualFee', 'unitPrice',
        'includedUnits', 'overagePrice', 'oneTimeFees', 'priceIncreaseCap', 'renewalUplift'
    ];

    $validRenewalTypes = ['Auto-Renew', 'Manual', 'Evergreen'];
    $validBillingFrequencies = ['Monthly', 'Quarterly', 'Annually'];
    $validRenewalUplift = ['Y', 'N'];
    $numericFields = ['annualFee', 'unitPrice', 'includedUnits', 'overagePrice', 'oneTimeFees', 'priceIncreaseCap'];

    $pricing = [];
    foreach ($validFields as $field) {
        if (!isset($parsed[$field]) || $parsed[$field] === '' || $parsed[$field] === null) {
            continue;
        }

        $val = $parsed[$field];

        if ($field === 'renewalType' && !in_array($val, $validRenewalTypes, true)) continue;
        if ($field === 'billingFrequency' && !in_array($val, $validBillingFrequencies, true)) continue;
        if ($field === 'renewalUplift' && !in_array($val, $validRenewalUplift, true)) continue;

        if (in_array($field, $numericFields)) {
            $val = preg_replace('/[^0-9.\-]/', '', (string)$val);
            if ($val === '' || !is_numeric($val)) continue;
        }

        if (is_string($val)) {
            $val = trim($val);
            if ($val === '' || strtolower($val) === 'n/a' || strtolower($val) === 'not specified') continue;
        }

        $pricing[$field] = (string)$val;
    }

    return ['success' => true, 'pricing' => $pricing, 'filled' => count($pricing)];
}

/**
 * Parse and validate assessment autofill AI response.
 * Mirrors the validation logic from assessment-ai-autofill.php.
 * Note: actual DB saves happen back in the web endpoint after polling.
 */
function postProcessAssessmentAutofill(string $content, array $context): array
{
    // Strip markdown fences
    $content = preg_replace('/```json\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = preg_replace('/```/', '', $content);
    $content = trim($content);

    $firstBrace = strpos($content, '{');
    $lastBrace = strrpos($content, '}');
    if ($firstBrace === false || $lastBrace === false) {
        throw new Exception('AI returned an invalid response format. Please try again.');
    }

    $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
    $parsed = json_decode($jsonStr, true);

    if (!$parsed || !isset($parsed['answers']) || !is_array($parsed['answers'])) {
        throw new Exception('AI returned an invalid response format. Please try again.');
    }

    // Validate answers against the question metadata stored in context
    $unansweredQuestions = $context['unanswered_questions'] ?? [];
    $validatedAnswers = [];
    $skipped = 0;

    foreach ($parsed['answers'] as $answer) {
        $questionId = intval($answer['id'] ?? 0);
        $value = $answer['value'] ?? null;

        if (!$questionId || !isset($unansweredQuestions[$questionId])) {
            $skipped++;
            continue;
        }

        $q = $unansweredQuestions[$questionId];
        $qType = $q['question_type'];

        // Skip empty values and non-answers
        if ($value === null || $value === '') {
            $skipped++;
            continue;
        }
        if (is_string($value)) {
            $lowerVal = strtolower(trim($value));
            if (in_array($lowerVal, [
                'n/a', 'na', 'not specified', 'not specified in document',
                'not specified in documents', 'not available', 'not provided',
                'not found', 'not found in document', 'not found in documents',
                'not mentioned', 'not mentioned in document', 'not mentioned in documents',
                'unknown', 'none', 'none specified', 'not applicable',
            ]) || preg_match('/^not\s+(specified|provided|found|mentioned|available|stated|indicated)/i', $lowerVal)) {
                $skipped++;
                continue;
            }
        }

        // Validate based on question type
        $validOptions = [];
        if (!empty($q['options']) && is_array($q['options'])) {
            $validOptions = array_map(function($opt) {
                return is_array($opt) ? ($opt['value'] ?? $opt['label'] ?? '') : $opt;
            }, $q['options']);
        }

        if (in_array($qType, ['select', 'radio', 'button_group'])) {
            if (!empty($validOptions) && !in_array($value, $validOptions, true)) {
                $skipped++;
                continue;
            }
        } elseif (in_array($qType, ['checkbox', 'button_group_multi'])) {
            if (!is_array($value)) {
                $skipped++;
                continue;
            }
            if (!empty($validOptions)) {
                $allValid = true;
                foreach ($value as $v) {
                    if (!in_array($v, $validOptions, true)) {
                        $allValid = false;
                        break;
                    }
                }
                if (!$allValid) {
                    $skipped++;
                    continue;
                }
            }
            $value = json_encode($value);
        }

        $validatedAnswers[] = ['id' => $questionId, 'value' => $value];
    }

    return [
        'success' => true,
        'answers' => $validatedAnswers,
        'filled' => count($validatedAnswers),
        'skipped' => $skipped,
        'total' => intval($context['total_unanswered'] ?? 0),
    ];
}


/**
 * Post-process GRC assistant AI responses.
 * Handles: assistant_guidance, assistant_rewrite, assistant_explain,
 *          assistant_rewrite_and_recommend, refine_notes, requirement_detail.
 */
function postProcessGrcAssistant(string $content, array $context): array
{
    $action = $context['action'] ?? '';

    // Strip markdown fences
    $cleaned = preg_replace('/^```(?:json|html)?\s*/i', '', $content);
    $cleaned = preg_replace('/```\s*$/s', '', $cleaned);
    $cleaned = trim($cleaned);

    if ($action === 'assistant_rewrite_and_recommend') {
        $parsed = json_decode($cleaned, true);
        if ($parsed && isset($parsed['proposed_rewrite'])) {
            return ['success' => true, 'content' => $content, 'parsed' => $parsed];
        }
        return ['success' => true, 'content' => $content, 'parsed' => [
            'proposed_rewrite' => $content,
            'current_maturity' => '',
            'current_conformity' => '',
            'recommendations' => 'Could not parse recommendations. Please try again.'
        ]];
    }

    if ($action === 'refine_notes') {
        return ['success' => true, 'refined' => $cleaned];
    }

    if ($action === 'requirement_detail') {
        // Sanitize HTML
        $cleaned = preg_replace('/<\!DOCTYPE[^>]*>/i', '', $cleaned);
        $cleaned = preg_replace('/<\/?html[^>]*>/i', '', $cleaned);
        $cleaned = preg_replace('/<\/?head[^>]*>/i', '', $cleaned);
        $cleaned = preg_replace('/<\/?body[^>]*>/i', '', $cleaned);
        $cleaned = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $cleaned);
        $allowed = '<h4><h5><p><ul><ol><li><strong><em><b><i><br><table><thead><tbody><tr><th><td>';
        $cleaned = strip_tags(trim($cleaned), $allowed);
        $cleaned = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $cleaned);
        return [
            'success' => true,
            'content' => $cleaned,
            'requirement_ref' => $context['requirement_ref'] ?? '',
            'requirement_title' => $context['requirement_title'] ?? '',
            'framework' => $context['framework'] ?? '',
        ];
    }

    // assistant_guidance, assistant_rewrite, assistant_explain
    return ['success' => true, 'content' => $cleaned];
}

/**
 * Post-process GRC suggest control AI response (JSON).
 */
function postProcessGrcSuggestControl(string $content, array $context): array
{
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = trim($content);

    $suggestion = json_decode($content, true);
    if (!$suggestion || !is_array($suggestion)) {
        throw new Exception('AI returned an invalid response. Please try again.');
    }

    $validTypes = ['preventive', 'detective', 'corrective', 'directive'];
    $validCategories = ['technical', 'administrative', 'physical'];
    $validStatuses = ['planned', 'in_progress', 'implemented', 'not_applicable'];
    $validFrequencies = ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annually', 'ad_hoc'];
    $validRiskLevels = ['low', 'medium', 'high', 'critical'];

    $requirementTitle = $context['requirement_title'] ?? '';

    return ['success' => true, 'suggestion' => [
        'title' => substr(trim($suggestion['title'] ?? $requirementTitle), 0, 500),
        'description' => trim($suggestion['description'] ?? ''),
        'control_type' => in_array($suggestion['control_type'] ?? '', $validTypes) ? $suggestion['control_type'] : 'preventive',
        'control_category' => in_array($suggestion['control_category'] ?? '', $validCategories) ? $suggestion['control_category'] : 'technical',
        'implementation_status' => in_array($suggestion['implementation_status'] ?? '', $validStatuses) ? $suggestion['implementation_status'] : 'planned',
        'frequency' => in_array($suggestion['frequency'] ?? '', $validFrequencies) ? $suggestion['frequency'] : 'ad_hoc',
        'risk_level' => in_array($suggestion['risk_level'] ?? '', $validRiskLevels) ? $suggestion['risk_level'] : 'medium',
    ]];
}

/**
 * Post-process GRC report AI response (JSON with sections).
 */
function postProcessGrcReport(string $content): array
{
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);
    $sections = [];
    if ($parsed && is_array($parsed)) {
        foreach ($parsed as $key => $section) {
            if (is_array($section) && !empty($section['content'])) {
                $sections[$key] = [
                    'title' => $section['title'] ?? ucfirst(str_replace('_', ' ', $key)),
                    'content' => $section['content'],
                ];
            }
        }
    }
    return ['success' => true, 'sections' => $sections];
}

/**
 * Post-process GRC requirement description AI response.
 * Saves the result directly to the database.
 */
function postProcessGrcRequirementDesc(string $content, array $context): array
{
    $raw = trim($content);
    $title = $context['current_title'] ?? '';
    $desc = $raw;
    $reqId = (int)($context['requirement_id'] ?? 0);

    if (preg_match('/^Title:\s*(.+)/mi', $raw, $tm)) {
        $title = trim($tm[1]);
    }
    if (preg_match('/^Description:\s*(.+)/mi', $raw, $dm)) {
        $desc = trim($dm[1]);
    }

    if ($reqId > 0) {
        $db = Database::getInstance();
        $db->query(
            'UPDATE grc_framework_requirements SET title = :title, description = :desc, updated_at = NOW() WHERE id = :id',
            [':title' => $title, ':desc' => $desc, ':id' => $reqId]
        );
    }

    return ['success' => true, 'id' => $reqId, 'title' => $title, 'description' => $desc];
}

/**
 * Parse and sanitize detailed summary AI response (HTML output).
 * Mirrors the validation logic from generate-detailed-summary.php.
 */
function postProcessDetailedSummary(string $content): array
{
    // Clean up markdown fences and stray HTML wrappers
    $content = preg_replace('/```html\s*/i', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = preg_replace('/```/s', '', $content);
    $content = preg_replace('/<\!DOCTYPE[^>]*>/i', '', $content);
    $content = preg_replace('/<\/?html[^>]*>/i', '', $content);
    $content = preg_replace('/<\/?head[^>]*>/i', '', $content);
    $content = preg_replace('/<\/?body[^>]*>/i', '', $content);
    $content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);

    // Sanitize: allow only safe tags
    $allowed = '<h3><h4><h5><p><ul><ol><li><strong><em><b><i><br><table><thead><tbody><tr><th><td>';
    $content = strip_tags(trim($content), $allowed);
    $content = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $content);

    return ['success' => true, 'summary' => $content];
}


// =============================================================================
// HELP TEXT
// =============================================================================

function showHelp(): void
{
    echo <<<HELP
AI Job Queue Processor

Processes AI tasks queued by the web UI (FAIR analysis, contract pricing
extraction, assessment auto-fill) so that long-running AI API calls
don't block Apache worker threads.

USAGE:
    php ai-queue.php [OPTIONS]

OPTIONS:
    --verbose   Show detailed progress information
    --help      Display this help message

CRON SETUP:
    * * * * * /usr/bin/php /path/to/cron/ai-queue.php >> /var/log/php/cron-ai-queue.log 2>&1

HOW IT WORKS:
    1. User clicks an AI button in the web UI
    2. Web endpoint validates input, builds prompt, queues job
    3. This script picks up pending jobs and calls the AI API
    4. Frontend polling detects completion and displays results

SAFETY:
    - Lock file prevents overlapping executions
    - Atomic DB claims prevent duplicate processing
    - Stale jobs (>10 min) are automatically expired
    - Old completed/failed jobs are cleaned up after 24 hours

HELP;
}
