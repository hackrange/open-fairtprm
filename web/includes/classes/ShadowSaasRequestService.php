<?php
/**
 * Shadow SaaS Requests - FairTPRM's own "why do you use this app?" flow.
 *
 * Admin > Shadow SaaS picks the request method. "grip" keeps Grip's built-in
 * justification (email/Slack/Teams sent by Grip). "fairtprm" sends our own
 * email: each recipient (an app user) gets a one-time link with one button per
 * configured choice (Admin > Email > Shadow SaaS, e.g. Personal Use / Business
 * Use). A choice opens an assessment form through the normal public wizard
 * (vendor-assessment.php):
 *
 *   - a "User Request" template (category user_request) -> a standalone form
 *     ending in an attestation (first name, last name, I attest) that records
 *     the IP address, user agent and time; the email address is the one the
 *     request was sent to, never one the submitter types.
 *   - an "Onboarding" template -> a draft vendor_onboarding_requests row and an
 *     onboarding assessment, i.e. the usual vendor onboarding flow. On submit
 *     the vendor leaves Shadow SaaS for onboarding review.
 *
 * When a form is submitted the reviewers listed on Admin > Email > Shadow SaaS
 * are emailed, and the request waits on Shadow SaaS > Requests for a decision.
 *
 * Only a SHA-256 of the link token is stored; the token itself exists only in
 * the email. The public page never changes state on GET (mail link scanners
 * prefetch links), so a choice is always a POST.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once __DIR__ . '/VendorAssessmentService.php';
require_once __DIR__ . '/EmailService.php';

class ShadowSaasRequestService
{
    public const METHOD_GRIP = 'grip';
    public const METHOD_FAIRTPRM = 'fairtprm';

    /** Template categories a choice may open, and the flow each runs. */
    public const CHOICE_KINDS = ['user_request' => 'personal', 'onboarding' => 'business'];

    public const MAX_CHOICES = 4;
    public const MAX_RECIPIENTS = 50;

    public const OPEN_STATUSES = ['sent', 'opened', 'in_progress'];
    public const CLOSED_STATUSES = ['approved', 'denied', 'cancelled'];

    public const DEFAULT_PERSONAL_SLUG = 'shadow-saas-personal-use';
    public const DEFAULT_BUSINESS_SLUG = 'vendor-onboarding';

    private $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /** The method the admin picked (grip | fairtprm), whatever Grip's state. */
    public static function configuredMethod(): string
    {
        return getAppConfig('shadow_saas_request_method', self::METHOD_GRIP) === self::METHOD_FAIRTPRM
            ? self::METHOD_FAIRTPRM : self::METHOD_GRIP;
    }

    /**
     * The method actually in force. Grip's justification needs Grip, so with
     * Grip off FairTPRM is the only way to send a request.
     */
    public static function method(): string
    {
        if (self::configuredMethod() === self::METHOD_FAIRTPRM) return self::METHOD_FAIRTPRM;
        return getAppConfig('grip_enabled', '0') === '1' ? self::METHOD_GRIP : self::METHOD_FAIRTPRM;
    }

    public static function usesFairTprm(): bool
    {
        return self::method() === self::METHOD_FAIRTPRM;
    }

    public static function expiryDays(): int
    {
        $d = (int)getAppConfig('shadow_saas_request_expiry_days', '14');
        return ($d >= 1 && $d <= 365) ? $d : 14;
    }

    public static function defaultAttestationText(): string
    {
        return 'I attest that the information I have provided is true, accurate and complete. I understand that misrepresenting how I use this application may lead to disciplinary action.';
    }

    public static function attestationText(): string
    {
        $t = trim((string)getAppConfig('shadow_saas_request_attestation', ''));
        return $t !== '' ? $t : self::defaultAttestationText();
    }

    /** Reviewers notified when a request form is submitted. */
    public static function recipients(): array
    {
        return self::parseEmails((string)getAppConfig('shadow_saas_request_recipients', ''))['valid'];
    }

    /**
     * Split a comma / semicolon / whitespace / newline separated list.
     * Returns ['valid' => unique lowercased addresses, 'invalid' => the rest].
     */
    public static function parseEmails(string $raw): array
    {
        $valid = []; $invalid = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $a) {
            $a = trim($a);
            if ($a === '') continue;
            if (filter_var($a, FILTER_VALIDATE_EMAIL) && strlen($a) <= 255) {
                $valid[strtolower($a)] = true;
            } else {
                $invalid[] = $a;
            }
        }
        return ['valid' => array_keys($valid), 'invalid' => $invalid];
    }

    /** Active templates a choice can open, keyed by id. */
    public function choiceTemplates(): array
    {
        $out = [];
        $cats = array_keys(self::CHOICE_KINDS);
        $in = implode(',', array_fill(0, count($cats), '?'));
        foreach ($this->db->fetchAll("SELECT id, name, slug, category FROM assessment_templates WHERE is_active = 1 AND category IN ({$in}) ORDER BY category DESC, name", $cats) as $t) {
            $out[(int)$t['id']] = $t;
        }
        return $out;
    }

    /** The factory choices: Personal Use + Business Use, when their templates exist. */
    public function defaultChoices(): array
    {
        $choices = [];
        foreach ([[self::DEFAULT_PERSONAL_SLUG, 'Personal Use', 'I use it on my own, for my individual work.'],
                  [self::DEFAULT_BUSINESS_SLUG, 'Business Use', 'My team or the business relies on it.']] as [$slug, $label, $desc]) {
            $t = $this->db->fetchOne("SELECT id FROM assessment_templates WHERE slug = :s AND is_active = 1", [':s' => $slug]);
            if ($t) $choices[] = ['label' => $label, 'description' => $desc, 'template_id' => (int)$t['id']];
        }
        return $choices;
    }

    /**
     * The configured choices, each with its template and flow (personal /
     * business). Choices whose template is gone, inactive or of another
     * category are dropped, so a request can never open a form it shouldn't.
     */
    public function choices(): array
    {
        $raw = json_decode((string)getAppConfig('shadow_saas_request_choices', ''), true);
        if (!is_array($raw) || !$raw) $raw = $this->defaultChoices();
        $templates = $this->choiceTemplates();
        $out = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_CHOICES) as $c) {
            if (!is_array($c)) continue;
            $tid = (int)($c['template_id'] ?? 0);
            $label = trim((string)($c['label'] ?? ''));
            if ($label === '' || !isset($templates[$tid])) continue;
            $out[] = [
                'label'         => mb_substr($label, 0, 60),
                'description'   => mb_substr(trim((string)($c['description'] ?? '')), 0, 200),
                'template_id'   => $tid,
                'template_name' => (string)$templates[$tid]['name'],
                'kind'          => self::CHOICE_KINDS[$templates[$tid]['category']],
            ];
        }
        return $out;
    }

    /**
     * Validate the choice rows posted from Admin > Email (label, description,
     * template_id arrays). Returns [choices, errors]. Empty rows are skipped.
     */
    public function validateChoices(array $labels, array $descriptions, array $templateIds): array
    {
        $templates = $this->choiceTemplates();
        $choices = []; $errors = [];
        foreach (array_keys($labels) as $i) {
            $label = trim((string)($labels[$i] ?? ''));
            $tid = (int)($templateIds[$i] ?? 0);
            $desc = trim((string)($descriptions[$i] ?? ''));
            if ($label === '' && $tid === 0) continue;
            if ($label === '' || mb_strlen($label) > 60) { $errors[] = 'label'; continue; }
            if (!isset($templates[$tid])) { $errors[] = 'template'; continue; }
            $choices[] = ['label' => $label, 'description' => mb_substr($desc, 0, 200), 'template_id' => $tid];
        }
        if (count($choices) > self::MAX_CHOICES) $errors[] = 'too_many';
        if (!$choices) $errors[] = 'none';
        return [$choices, array_values(array_unique($errors))];
    }

    // ---------------------------------------------------------------------
    // Creating and sending requests
    // ---------------------------------------------------------------------

    /** The app context for a shadow_saas row, or null when it does not exist. */
    public function appFromShadowRow(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT id, vendor_name, vendor_domain, grip_id FROM shadow_saas WHERE id = :id", [':id' => $id]);
        if (!$r) return null;
        return ['shadow_saas_id' => (int)$r['id'], 'grip_saas_id' => $r['grip_id'] ?: null,
                'app_name' => (string)$r['vendor_name'], 'app_domain' => $r['vendor_domain'] ?: null];
    }

    /** The app context for a Grip app id (Shadow SaaS row first, then the Grip mirror). */
    public function appFromGripId(string $gripId, string $fallbackName = '', string $fallbackDomain = ''): array
    {
        $r = $this->db->fetchOne("SELECT id, vendor_name, vendor_domain FROM shadow_saas WHERE grip_id = :g LIMIT 1", [':g' => $gripId]);
        if ($r) {
            return ['shadow_saas_id' => (int)$r['id'], 'grip_saas_id' => $gripId,
                    'app_name' => (string)$r['vendor_name'], 'app_domain' => $r['vendor_domain'] ?: null];
        }
        return ['shadow_saas_id' => null, 'grip_saas_id' => $gripId,
                'app_name' => $fallbackName !== '' ? $fallbackName : $gripId, 'app_domain' => $fallbackDomain ?: null];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create one request per address and email each one. $app comes from
     * appFromShadowRow / appFromGripId, or is a free-typed name + domain.
     * $names optionally maps a lowercased address to the person's name.
     * Addresses that already have an open, unexpired request for the same app
     * are skipped rather than sent twice.
     *
     * @return array{sent:int, failed:int, skipped:int, ids:int[], error:?string}
     */
    public function createRequests(array $app, array $emails, ?string $message, int $sentBy, array $names = []): array
    {
        $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'ids' => [], 'error' => null];
        $appName = trim((string)($app['app_name'] ?? ''));
        if ($appName === '') { $res['error'] = 'no_app'; return $res; }
        if (!$this->choices()) { $res['error'] = 'no_choices'; return $res; }
        $emails = self::parseEmails(implode(',', $emails))['valid'];
        if (!$emails) { $res['error'] = 'no_recipients'; return $res; }
        if (count($emails) > self::MAX_RECIPIENTS) { $res['error'] = 'too_many'; return $res; }

        $message = $message !== null ? trim($message) : '';
        $message = $message === '' ? null : mb_substr($message, 0, 2000);
        $expires = date('Y-m-d H:i:s', time() + self::expiryDays() * 86400);

        foreach ($emails as $email) {
            if ($this->hasOpenRequest($app, $email)) { $res['skipped']++; continue; }
            $token = bin2hex(random_bytes(32));
            $id = (int)$this->db->insert('shadow_saas_requests', [
                'token_hash'      => self::hashToken($token),
                'shadow_saas_id'  => $app['shadow_saas_id'] ?? null,
                'grip_saas_id'    => $app['grip_saas_id'] ?? null,
                'app_name'        => mb_substr($appName, 0, 500),
                'app_domain'      => !empty($app['app_domain']) ? mb_substr((string)$app['app_domain'], 0, 255) : null,
                'requester_email' => $email,
                'requester_name'  => isset($names[$email]) && trim((string)$names[$email]) !== '' ? mb_substr(trim((string)$names[$email]), 0, 255) : null,
                'message'         => $message,
                'status'          => 'sent',
                'sent_by'         => $sentBy ?: null,
                'sent_at'         => date('Y-m-d H:i:s'),
                'expires_at'      => $expires,
            ]);
            $res['ids'][] = $id;
            $ok = $this->sendRequestEmail($id, $token);
            $ok ? $res['sent']++ : $res['failed']++;
        }
        return $res;
    }

    private function hasOpenRequest(array $app, string $email): bool
    {
        $in = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $params = self::OPEN_STATUSES;
        $params[] = $email;
        if (!empty($app['shadow_saas_id'])) {
            $match = 'shadow_saas_id = ?'; $params[] = (int)$app['shadow_saas_id'];
        } elseif (!empty($app['grip_saas_id'])) {
            $match = 'grip_saas_id = ?'; $params[] = (string)$app['grip_saas_id'];
        } else {
            $match = 'app_name = ?'; $params[] = (string)$app['app_name'];
        }
        $params[] = date('Y-m-d H:i:s');
        return (bool)$this->db->fetchOne(
            "SELECT id FROM shadow_saas_requests WHERE status IN ({$in}) AND requester_email = ? AND {$match} AND expires_at > ? LIMIT 1",
            $params
        );
    }

    /** Email the request link; records the outcome on the row. */
    public function sendRequestEmail(int $id, string $token): bool
    {
        $req = $this->get($id);
        if (!$req) return false;
        $mail = new EmailService($this->db, new Encryption());
        if (!$mail->isEnabled()) {
            $this->db->update('shadow_saas_requests', ['email_status' => 'disabled', 'email_error' => 'Email is disabled.'], 'id = :id', [':id' => $id]);
            return false;
        }
        $sender = $req['sent_by'] ? $this->db->fetchOne("SELECT full_name, email FROM users WHERE id = :id", [':id' => (int)$req['sent_by']]) : null;
        $r = $mail->sendShadowSaasRequest($req, self::linkUrl($token), $this->choices(), $sender ?: []);
        $this->db->update('shadow_saas_requests', [
            'email_status' => !empty($r['success']) ? 'sent' : 'failed',
            'email_error'  => !empty($r['success']) ? null : mb_substr((string)($r['error'] ?? 'Send failed'), 0, 500),
        ], 'id = :id', [':id' => $id]);
        return !empty($r['success']);
    }

    public static function linkUrl(string $token, ?int $choice = null): string
    {
        return baseUrl('shadow-saas-request.php?t=' . rawurlencode($token) . ($choice !== null ? '&c=' . $choice : ''));
    }

    /**
     * New link + fresh expiry + email again. The old link stops working.
     * Only for requests still waiting on the user.
     */
    public function resend(int $id): bool
    {
        $req = $this->get($id);
        if (!$req || !in_array($req['status'], self::OPEN_STATUSES, true)) return false;
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::expiryDays() * 86400);
        $this->db->update('shadow_saas_requests', [
            'token_hash' => self::hashToken($token),
            'expires_at' => $expires,
            'sent_at'    => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $id]);
        if (!empty($req['assessment_id'])) {
            $this->db->update('vendor_assessments', ['expires_at' => $expires], 'id = :id AND status != :done', [':id' => (int)$req['assessment_id'], ':done' => 'completed']);
        }
        return $this->sendRequestEmail($id, $token);
    }

    // ---------------------------------------------------------------------
    // Public link (shadow-saas-request.php)
    // ---------------------------------------------------------------------

    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $r = $this->db->fetchOne("SELECT * FROM shadow_saas_requests WHERE token_hash = :h", [':h' => self::hashToken($token)]);
        return $r ?: null;
    }

    public static function isExpired(array $req): bool
    {
        return !empty($req['expires_at']) && strtotime($req['expires_at']) < time();
    }

    /** Status for display: an open request past its expiry reads "expired". */
    public static function displayStatus(array $req): string
    {
        if (in_array($req['status'], self::OPEN_STATUSES, true) && self::isExpired($req)) return 'expired';
        return (string)$req['status'];
    }

    public function markOpened(array $req): void
    {
        if ($req['status'] === 'sent') {
            $this->db->update('shadow_saas_requests', ['status' => 'opened', 'opened_at' => date('Y-m-d H:i:s')],
                'id = :id AND status = :s', [':id' => (int)$req['id'], ':s' => 'sent']);
        }
    }

    /**
     * The user picked choice $index. Creates (or reuses) the form behind it and
     * returns ['uuid' => assessment token] or ['error' => key]. Switching to a
     * different choice before submitting closes the earlier form.
     */
    public function choose(array $req, int $index): array
    {
        if (!in_array($req['status'], self::OPEN_STATUSES, true)) return ['error' => 'closed'];
        if (self::isExpired($req)) return ['error' => 'expired'];
        $choices = $this->choices();
        if (!isset($choices[$index])) return ['error' => 'bad_choice'];
        $choice = $choices[$index];
        $assessments = new VendorAssessmentService();

        // Same choice again: continue the form already started.
        if ($req['assessment_id'] && (int)$req['choice_index'] === $index && (int)$req['template_id'] === $choice['template_id']) {
            $a = $assessments->getAssessmentById((int)$req['assessment_id']);
            if ($a && $a['status'] !== 'completed' && !$assessments->isExpired($a)) return ['uuid' => $a['uuid']];
        }
        // A different choice: retire the earlier, unsubmitted form.
        if ($req['assessment_id']) {
            $this->db->update('vendor_assessments', ['expires_at' => date('Y-m-d H:i:s', time() - 1)],
                'id = :id AND status != :done', [':id' => (int)$req['assessment_id'], ':done' => 'completed']);
        }

        $days = max(1, (int)ceil((strtotime($req['expires_at']) - time()) / 86400));
        $sentBy = $req['sent_by'] ? (int)$req['sent_by'] : null;
        $vendorRequestId = null;
        if ($choice['kind'] === 'business') {
            if (!$sentBy) return ['error' => 'no_owner'];
            $vendorRequestId = $this->createBusinessDraft($req, $sentBy);
        }
        $created = $assessments->createAssessment(
            $choice['template_id'],
            (string)$req['app_name'],
            (string)$req['requester_email'],
            $req['requester_name'] ?: null,
            // No contact email: the reminder cron mails vendor wording to any
            // assessment that has one, and these forms have their own lifecycle.
            null,
            $vendorRequestId,
            $sentBy,
            $days
        );
        $this->db->update('shadow_saas_requests', [
            'status'            => 'in_progress',
            'choice_index'      => $index,
            'choice_label'      => $choice['label'],
            'choice_kind'       => $choice['kind'],
            'template_id'       => $choice['template_id'],
            'assessment_id'     => (int)$created['id'],
            'vendor_request_id' => $vendorRequestId,
            'chosen_at'         => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => (int)$req['id']]);
        return ['uuid' => $created['uuid']];
    }

    /**
     * Business Use: a draft vendor onboarding request owned by whoever sent the
     * request (so it shows up in their vendor list like any onboarding draft).
     * Reuses the draft of an earlier Business choice on this request.
     */
    private function createBusinessDraft(array $req, int $ownerId): int
    {
        if (!empty($req['vendor_request_id'])) {
            $v = $this->db->fetchOne("SELECT id, status FROM vendor_onboarding_requests WHERE id = :id", [':id' => (int)$req['vendor_request_id']]);
            if ($v && $v['status'] === 'draft') return (int)$v['id'];
        }
        require_once dirname(__DIR__) . '/partials/shadow-saas-helpers.php';
        $entry = $req['shadow_saas_id']
            ? $this->db->fetchOne("SELECT * FROM shadow_saas WHERE id = :id", [':id' => (int)$req['shadow_saas_id']])
            : null;
        if (!$entry) {
            $entry = ['vendor_name' => $req['app_name'], 'vendor_domain' => $req['app_domain'], 'relationship_manager' => null];
        }
        $data = shadowSaasOnboardDraftData($entry, $ownerId);
        if (empty($data['relationship_manager'])) {
            $data['relationship_manager'] = trim(($req['requester_name'] ?? '') . ' <' . $req['requester_email'] . '>');
        }
        $data['last_autosave'] = date('Y-m-d H:i:s');
        $vid = (int)$this->db->insert('vendor_onboarding_requests', $data);
        $this->db->insert('vendor_onboarding_stakeholders', [
            'request_id' => $vid, 'user_id' => $ownerId, 'role' => 'owner', 'assigned_by' => $ownerId,
        ]);
        return $vid;
    }

    /** The open request behind a public assessment, or null. */
    public function getByAssessment(int $assessmentId): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM shadow_saas_requests WHERE assessment_id = :a ORDER BY id DESC LIMIT 1", [':a' => $assessmentId]);
        return $r ?: null;
    }

    /**
     * The User Request attestation from api/assessment-submit.php, recorded on
     * the request before the assessment is completed.
     */
    public function recordAttestation(int $assessmentId, string $first, string $last, string $ip, string $userAgent): void
    {
        $this->db->update('shadow_saas_requests', [
            'submitter_first_name' => mb_substr($first, 0, 100),
            'submitter_last_name'  => mb_substr($last, 0, 100),
            'submitter_ip'         => mb_substr($ip, 0, 45),
            'submitter_user_agent' => mb_substr($userAgent, 0, 500),
            'attested_at'          => date('Y-m-d H:i:s'),
        ], 'assessment_id = :a', [':a' => $assessmentId]);
    }

    /**
     * Hook from VendorAssessmentService::updateStatus('completed'). Marks the
     * request submitted, finishes the Shadow SaaS -> onboarding hand-off for a
     * Business choice, and emails the reviewers once. A re-submit (?edit=1)
     * keeps the first submission time and does not notify again.
     */
    public function onAssessmentCompleted(int $assessmentId): void
    {
        $req = $this->getByAssessment($assessmentId);
        if (!$req || !in_array($req['status'], array_merge(self::OPEN_STATUSES, ['submitted']), true)) return;
        $a = $this->db->fetchOne("SELECT submitter_name, submitter_ip_address, attested_at FROM vendor_assessments WHERE id = :id", [':id' => $assessmentId]);
        $upd = ['status' => 'submitted'];
        if (empty($req['submitted_at'])) $upd['submitted_at'] = date('Y-m-d H:i:s');
        // Business (onboarding) forms use the standard attestation; keep its IP/time on the request too.
        if (empty($req['attested_at']) && $a) {
            $upd['submitter_ip'] = $a['submitter_ip_address'] ?: null;
            $upd['attested_at'] = $a['attested_at'] ?: null;
        }
        $this->db->update('shadow_saas_requests', $upd, 'id = :id', [':id' => (int)$req['id']]);

        if ($req['choice_kind'] === 'business' && $req['vendor_request_id']) {
            $this->finishBusinessOnboard($req);
        }
        if (empty($req['notified_at'])) {
            $this->notifySubmitted((int)$req['id']);
        }
    }

    /**
     * The business form is in: the app is now a vendor in onboarding review, so
     * it leaves Shadow SaaS the same way Onboard does (Grip telemetry copied to
     * the vendor, then the shadow_saas row removed).
     */
    private function finishBusinessOnboard(array $req): void
    {
        $entry = $req['shadow_saas_id']
            ? $this->db->fetchOne("SELECT * FROM shadow_saas WHERE id = :id", [':id' => (int)$req['shadow_saas_id']])
            : null;
        if (!$entry) return;
        $vid = (int)$req['vendor_request_id'];
        if (($entry['source'] ?? '') === 'grip' && !empty($entry['grip_id'])) {
            try {
                require_once __DIR__ . '/GripService.php';
                (new GripService())->stampVendorFromApp($vid, (string)$entry['grip_id']);
            } catch (Throwable $e) {
                error_log('Shadow SaaS request ' . $req['id'] . ' Grip data for vendor ' . $vid . ' failed: ' . $e->getMessage());
            }
        }
        $this->db->query("DELETE FROM shadow_saas WHERE id = ?", [(int)$entry['id']]);
    }

    /** Email the reviewers that a form came in. Returns the number of emails sent. */
    public function notifySubmitted(int $id): int
    {
        $req = $this->get($id);
        if (!$req) return 0;
        $to = self::recipients();
        if (!$to) return 0;
        $mail = new EmailService($this->db, new Encryption());
        if (!$mail->isEnabled()) return 0;
        $answers = $req['assessment_id'] ? $this->answers((int)$req['assessment_id']) : [];
        $sent = 0;
        foreach ($to as $addr) {
            $r = $mail->sendShadowSaasRequestSubmitted($req, $answers, $addr);
            if (!empty($r['success'])) $sent++;
        }
        if ($sent > 0) {
            $this->db->update('shadow_saas_requests', ['notified_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        }
        return $sent;
    }

    /**
     * The visible questions and answers of a submitted form, in template order:
     * list of ['section' => ..., 'question' => ..., 'answer' => string].
     */
    public function answers(int $assessmentId): array
    {
        $a = $this->db->fetchOne("SELECT template_id FROM vendor_assessments WHERE id = :id", [':id' => $assessmentId]);
        if (!$a) return [];
        $svc = new VendorAssessmentService();
        $responses = $svc->getResponses($assessmentId);
        $out = [];
        foreach ($svc->getSections((int)$a['template_id']) as $s) {
            foreach ($svc->getQuestions((int)$s['id']) as $q) {
                if (!$svc->isQuestionVisible($q, $responses)) continue;
                $raw = $responses[$q['id']]['response_value'] ?? null;
                if ($raw === null || $raw === '') continue;
                $dec = json_decode((string)$raw, true);
                $val = is_array($dec) ? implode(', ', array_map('strval', $dec)) : (string)$raw;
                if ($q['question_type'] === 'file') $val = '[file]';
                $out[] = ['section' => (string)$s['name'], 'question' => (string)$q['question_text'], 'answer' => $val];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Review (Shadow SaaS > Requests)
    // ---------------------------------------------------------------------

    public function get(int $id): ?array
    {
        $r = $this->db->fetchOne(
            "SELECT r.*, su.full_name AS sent_by_name, ru.full_name AS reviewed_by_name
               FROM shadow_saas_requests r
               LEFT JOIN users su ON su.id = r.sent_by
               LEFT JOIN users ru ON ru.id = r.reviewed_by
              WHERE r.id = :id",
            [':id' => $id]
        );
        return $r ?: null;
    }

    /** approve | deny a submitted request, or cancel an open one. */
    public function decide(int $id, string $decision, string $notes, int $by): bool
    {
        $req = $this->get($id);
        if (!$req) return false;
        $map = ['approve' => 'approved', 'deny' => 'denied', 'cancel' => 'cancelled'];
        if (!isset($map[$decision])) return false;
        if ($decision === 'cancel' && !in_array($req['status'], self::OPEN_STATUSES, true)) return false;
        if ($decision !== 'cancel' && $req['status'] !== 'submitted') return false;
        $this->db->update('shadow_saas_requests', [
            'status'       => $map[$decision],
            'reviewed_by'  => $by,
            'reviewed_at'  => date('Y-m-d H:i:s'),
            'review_notes' => trim($notes) !== '' ? mb_substr(trim($notes), 0, 4000) : null,
        ], 'id = :id', [':id' => $id]);
        if ($decision === 'cancel' && $req['assessment_id']) {
            $this->db->update('vendor_assessments', ['expires_at' => date('Y-m-d H:i:s', time() - 1)],
                'id = :id AND status != :done', [':id' => (int)$req['assessment_id'], ':done' => 'completed']);
        }
        return true;
    }

    /**
     * awaiting_review = submitted, waiting on a reviewer (the nav bubble).
     * awaiting_response = sent and not yet submitted, unexpired.
     */
    public function counts(): array
    {
        $out = ['awaiting_review' => 0, 'awaiting_response' => 0, 'total' => 0];
        try {
            $r = $this->db->fetchOne(
                "SELECT SUM(status = 'submitted') AS review,
                        SUM(status IN ('sent','opened','in_progress') AND expires_at > :now) AS response,
                        COUNT(*) AS total
                   FROM shadow_saas_requests",
                [':now' => date('Y-m-d H:i:s')]
            );
            $out = ['awaiting_review' => (int)($r['review'] ?? 0), 'awaiting_response' => (int)($r['response'] ?? 0), 'total' => (int)($r['total'] ?? 0)];
        } catch (Throwable $e) {
            // Table not created yet (schema update pending).
        }
        return $out;
    }

    /**
     * Requests for the list. $filter: review | waiting | closed | all.
     * $search matches app name/domain or requester email.
     */
    public function listRequests(string $filter, string $search = '', int $limit = 500): array
    {
        $where = []; $params = [];
        $now = date('Y-m-d H:i:s');
        if ($filter === 'review') {
            $where[] = "r.status = 'submitted'";
        } elseif ($filter === 'waiting') {
            $where[] = "r.status IN ('sent','opened','in_progress') AND r.expires_at > :now"; $params[':now'] = $now;
        } elseif ($filter === 'closed') {
            $where[] = "(r.status IN ('approved','denied','cancelled') OR (r.status IN ('sent','opened','in_progress') AND r.expires_at <= :now))"; $params[':now'] = $now;
        }
        if ($search !== '') {
            $where[] = '(r.app_name LIKE :q1 OR r.app_domain LIKE :q2 OR r.requester_email LIKE :q3)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
        }
        $sql = "SELECT r.*, su.full_name AS sent_by_name FROM shadow_saas_requests r LEFT JOIN users su ON su.id = r.sent_by"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY (r.status = \'submitted\') DESC, COALESCE(r.submitted_at, r.sent_at) DESC, r.id DESC LIMIT ' . max(1, min(2000, $limit));
        return $this->db->fetchAll($sql, $params);
    }

    /** Latest request per requester email for one Grip app (Grip users page). */
    public function latestByEmailForGripApp(string $gripId): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT id, requester_email, status, expires_at, sent_at FROM shadow_saas_requests WHERE grip_saas_id = :g ORDER BY id DESC", [':g' => $gripId]) as $r) {
            $k = strtolower($r['requester_email']);
            if (!isset($out[$k])) $out[$k] = $r;
        }
        return $out;
    }
}
