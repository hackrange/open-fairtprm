<?php
/**
 * open-ctem Evidence Service - signed CTEM reports as audit-grade evidence
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * A monitor result proves a control right now; auditors also want the detail
 * behind it and proof it held across the audit period. This service pulls
 * both from open-ctem:
 *
 *   attachReport()            monitor option "attach report": the full report
 *                             behind a check, stored as encrypted evidence
 *   collectForAuditRequest()  "Collect from CTEM" on an audit evidence request
 *   collectForEvidenceRequest() the same for a control evidence request
 *   buildAuditPackage()       daily history for the audit window, for every
 *                             CTEM-backed control, as an evidence_package artifact
 *
 * Every report and history body must carry a valid Ed25519 signature from a
 * key the admin pinned in Admin > open-ctem; unsigned or mismatched bodies are
 * refused and nothing is stored. The SHA-256, signature and key id are kept
 * beside the evidence so anyone can re-verify it later. Report content (which
 * can list hostnames and account names) only ever lands in encrypted columns,
 * never in descriptions or api_response_data.
 */

class OpenCtemEvidenceService {
    public const COLLECTOR = 'OpenCtemEvidenceCollector';
    private const MAX_WINDOW_DAYS = 400;

    private Database $db;
    private Encryption $encryption;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    /** The configured open-ctem integration row, or null. */
    public function integration(): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM grc_integrations WHERE integration_type = 'open_ctem' ORDER BY id LIMIT 1"
        ) ?: null;
    }

    /** Decrypted credentials for the integration ([] when none). */
    public function credentials(?array $integration = null): array {
        $integration ??= $this->integration();
        if (!$integration || empty($integration['credentials_encrypted'])) return [];
        $json = $this->encryption->decrypt($integration['credentials_encrypted']);
        return json_decode((string)$json, true) ?: [];
    }

    public function isConfigured(): bool {
        $i = $this->integration();
        return $i && (int)$i['is_active'] === 1 && !empty($i['endpoint_url']) && !empty($this->credentials($i)['api_token']);
    }

    public function client(): OpenCtemClient {
        $i = $this->integration();
        if (!$i || (int)$i['is_active'] !== 1) throw new OpenCtemException('open-ctem integration is not configured or is disabled');
        return OpenCtemClient::fromIntegration($this->credentials($i), (string)$i['endpoint_url']);
    }

    /** CTEM check types whose monitors validate this control. */
    public function checksForControl(int $controlId): array {
        $types = [];
        foreach ($this->ctemMonitors() as $m) {
            if (in_array($controlId, $m['control_list'], true)) $types[$m['check_type']] = true;
        }
        return array_keys($types);
    }

    /** Control ids that have at least one CTEM-backed monitor. */
    public function ctemBackedControlIds(): array {
        $ids = [];
        foreach ($this->ctemMonitors() as $m) foreach ($m['control_list'] as $c) $ids[$c] = true;
        return array_keys($ids);
    }

    private function ctemMonitors(): array {
        $rows = $this->db->fetchAll(
            'SELECT id, check_type, control_ids FROM grc_continuous_monitors WHERE collector_class = :c',
            [':c' => self::COLLECTOR]
        );
        foreach ($rows as &$r) {
            $r['control_list'] = array_values(array_filter(array_map('intval', json_decode($r['control_ids'] ?? '[]', true) ?: [])));
        }
        return array_values(array_filter($rows, fn($r) => OpenCtemClient::isValidCheckType((string)$r['check_type'])));
    }

    /**
     * Verify a signed body against the pinned keys; throws with the reason
     * when it is refused (nothing is stored in that case).
     */
    private function verified(array $signed): array {
        $pinned = $this->credentials()['pinned_keys'] ?? [];
        $why = OpenCtemClient::verifySignature($signed['body'], $signed['signature'], $signed['key_id'], is_array($pinned) ? $pinned : []);
        if ($why !== null) throw new OpenCtemException('Refused CTEM evidence: ' . $why);
        $signed['sha256'] = hash('sha256', $signed['body']);
        return $signed;
    }

    /**
     * Fetch, verify and store the report behind a check as encrypted evidence
     * linked to $controlIds. Returns the new grc_evidence id.
     */
    public function storeReport(string $checkType, array $controlIds, ?int $monitorId = null,
                                ?int $userId = null, string $format = 'csv'): int {
        if (!OpenCtemClient::isValidCheckType($checkType)) throw new OpenCtemException('Invalid CTEM check type');
        $format = $format === 'json' ? 'json' : 'csv';
        $signed = $this->verified($this->client()->getReport($checkType, $format));

        $name = OpenCtemEvidenceCollector::catalog()[$checkType]['name'] ?? $checkType;
        $stamp = date('Ymd-His');
        $this->db->insert('grc_evidence', [
            'evidence_ref'      => $this->nextRef('EV-CTEM-' . $stamp),
            'title'             => 'open-ctem report: ' . $name . ' - ' . date('Y-m-d H:i'),
            'description'       => 'Signed open-ctem report for ' . $checkType . '. SHA-256 ' . $signed['sha256']
                                 . ', Ed25519 key ' . $signed['key_id'] . '. Signature verified against the pinned key when collected.',
            'evidence_type'     => 'report',
            'collection_method' => 'automated',
            'file_name'         => $checkType . '_' . $stamp . '.' . $format,
            'file_mime'         => $format === 'json' ? 'application/json' : 'text/csv',
            'file_size'         => strlen($signed['body']),
            'encrypted_data'    => $this->encryption->encryptRaw($signed['body']),
            'content_sha256'    => $signed['sha256'],
            'signature'         => substr($signed['signature'], 0, 255),
            'signature_key_id'  => substr($signed['key_id'], 0, 100),
            'monitor_id'        => $monitorId,
            'collected_at'      => date('Y-m-d H:i:s'),
            'valid_from'        => date('Y-m-d H:i:s'),
            'valid_until'       => date('Y-m-d H:i:s', strtotime('+90 days')),
            'status'            => 'current',
            'collected_by'      => $userId,
        ]);
        $evidenceId = (int)$this->db->lastInsertId();
        $this->linkControls($evidenceId, $controlIds, $userId);
        Auth::recordEvent($userId, 'grc_ctem_report_collected', 'grc_evidence', $evidenceId, [
            'check_type' => $checkType, 'sha256' => $signed['sha256'], 'key_id' => $signed['key_id'],
            'bytes' => strlen($signed['body']), 'monitor_id' => $monitorId,
        ]);
        return $evidenceId;
    }

    /** Monitor option "attach report": called by ContinuousMonitor after a run. */
    public function attachReport(array $monitor): int {
        $controlIds = array_map('intval', json_decode($monitor['control_ids'] ?? '[]', true) ?: []);
        $cfg = json_decode($monitor['collector_config'] ?? '{}', true) ?: [];
        return $this->storeReport((string)$monitor['check_type'], $controlIds, (int)$monitor['id'], null,
            ($cfg['report_format'] ?? 'csv') === 'json' ? 'json' : 'csv');
    }

    /**
     * "Collect from CTEM" on an audit evidence request: store the report for
     * every CTEM check backing the request's control, then mark it provided.
     * A human still accepts or rejects. Returns ['evidence_ids' => [...], 'errors' => [...]].
     */
    public function collectForAuditRequest(int $requestId, int $userId): array {
        $req = $this->db->fetchOne('SELECT * FROM grc_audit_evidence_requests WHERE id = :id', [':id' => $requestId]);
        if (!$req) throw new OpenCtemException('Evidence request not found');
        if (in_array($req['status'], ['accepted'], true)) throw new OpenCtemException('Evidence request is already accepted');
        $out = $this->collectForControl((int)($req['control_id'] ?? 0), $userId);
        if ($out['evidence_ids']) {
            $existing = array_map('intval', json_decode($req['evidence_ids'] ?? '[]', true) ?: []);
            $this->db->update('grc_audit_evidence_requests', [
                'evidence_ids' => json_encode(array_values(array_unique(array_merge($existing, $out['evidence_ids'])))),
                'status'       => 'provided',
            ], 'id = :id', [':id' => $requestId]);
        }
        return $out;
    }

    /** The same for a control evidence request (grc_evidence_requests). */
    public function collectForEvidenceRequest(int $requestId, int $userId): array {
        $req = $this->db->fetchOne('SELECT * FROM grc_evidence_requests WHERE id = :id', [':id' => $requestId]);
        if (!$req) throw new OpenCtemException('Evidence request not found');
        if ($req['status'] === 'accepted') throw new OpenCtemException('Evidence request is already accepted');
        $out = $this->collectForControl((int)($req['control_id'] ?? 0), $userId);
        if ($out['evidence_ids']) {
            $this->db->update('grc_evidence_requests', [
                'evidence_id' => $out['evidence_ids'][0],
                'status'      => 'provided',
            ], 'id = :id', [':id' => $requestId]);
        }
        return $out;
    }

    private function collectForControl(int $controlId, int $userId): array {
        if ($controlId <= 0) throw new OpenCtemException('This request has no control, so there is no CTEM check to collect from');
        $checks = $this->checksForControl($controlId);
        if (!$checks) throw new OpenCtemException('No open-ctem monitor validates this control');
        $ids = []; $errors = [];
        foreach ($checks as $type) {
            try {
                $ids[] = $this->storeReport($type, [$controlId], null, $userId);
            } catch (OpenCtemException $e) {
                $errors[$type] = $e->getMessage();
            }
        }
        return ['evidence_ids' => $ids, 'errors' => $errors];
    }

    /**
     * Audit package: signed daily history for planned_start..planned_end for
     * every CTEM check whose monitor covers a control in the audit's
     * framework (all CTEM monitors when the audit has no framework). Stored as
     * an encrypted evidence_package artifact; each item keeps its raw signed
     * body, SHA-256, signature and key id so it can be re-verified.
     */
    public function buildAuditPackage(int $auditId, int $userId): array {
        $audit = $this->db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $auditId]);
        if (!$audit) throw new OpenCtemException('Audit not found');
        $from = (string)($audit['planned_start'] ?? ''); $to = (string)($audit['planned_end'] ?? '');
        if ($from === '' || $to === '') throw new OpenCtemException('Set the audit planned start and end dates first');
        $from = substr($from, 0, 10); $to = substr($to, 0, 10);
        if (strtotime($to) < strtotime($from)) throw new OpenCtemException('Audit planned end is before its start');
        $today = date('Y-m-d');
        if ($to > $today) $to = $today;
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_WINDOW_DAYS) {
            throw new OpenCtemException('Audit window is longer than ' . self::MAX_WINDOW_DAYS . ' days');
        }

        $scope = null;
        if (!empty($audit['framework_id'])) {
            $scope = array_map('intval', array_column($this->db->fetchAll(
                'SELECT DISTINCT crm.control_id FROM grc_control_requirement_map crm
                   JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
                  WHERE fr.framework_id = :f', [':f' => (int)$audit['framework_id']]), 'control_id'));
        }
        $checks = [];
        foreach ($this->ctemMonitors() as $m) {
            $controls = $scope === null ? $m['control_list'] : array_values(array_intersect($m['control_list'], $scope));
            if ($scope !== null && !$controls) continue;
            $checks[$m['check_type']] = array_values(array_unique(array_merge($checks[$m['check_type']] ?? [], $controls)));
        }
        if (!$checks) throw new OpenCtemException('No open-ctem monitor covers a control in this audit');

        $client = $this->client();
        $items = []; $errors = [];
        foreach ($checks as $type => $controls) {
            try {
                $s = $this->verified($client->getHistory($type, $from, $to));
                $items[] = ['check_type' => $type, 'control_ids' => $controls, 'sha256' => $s['sha256'],
                            'signature' => $s['signature'], 'key_id' => $s['key_id'], 'body' => $s['body']];
            } catch (OpenCtemException $e) {
                $errors[$type] = $e->getMessage();
            }
        }
        if (!$items) return ['artifact_id' => null, 'errors' => $errors];

        $package = json_encode([
            'package'      => 'open-ctem audit evidence',
            'audit_ref'    => $audit['audit_ref'] ?? null,
            'window'       => ['from' => $from, 'to' => $to],
            'generated_at' => gmdate('c'),
            'instance'     => parse_url((string)($this->integration()['endpoint_url'] ?? ''), PHP_URL_HOST),
            'verification' => 'Each item.body is the exact signed bytes; verify Ed25519(item.signature, item.body) with the key id published at /api/v1/grc/evidence-key.',
            'items'        => $items,
            'errors'       => $errors,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->db->insert('grc_audit_artifacts', [
            'audit_id'       => $auditId,
            'artifact_type'  => 'evidence_package',
            'title'          => 'open-ctem evidence package ' . $from . ' to ' . $to,
            'file_format'    => 'json',
            'file_name'      => 'ctem_evidence_' . preg_replace('/[^A-Za-z0-9\-]/', '', (string)($audit['audit_ref'] ?? $auditId)) . '_' . date('Ymd') . '.json',
            'file_mime'      => 'application/json',
            'encrypted_data' => $this->encryption->encryptRaw($package),
            'file_size'      => strlen($package),
            'generated_by'   => $userId,
            'notes'          => count($items) . ' signed CTEM history item(s)' . ($errors ? ', ' . count($errors) . ' refused' : ''),
        ]);
        $artifactId = (int)$this->db->lastInsertId();
        Auth::recordEvent($userId, 'grc_ctem_audit_package', 'grc_audit_artifacts', $artifactId, [
            'audit_id' => $auditId, 'from' => $from, 'to' => $to,
            'checks' => array_column($items, 'check_type'), 'refused' => array_keys($errors),
        ]);
        return ['artifact_id' => $artifactId, 'errors' => $errors];
    }

    private function linkControls(int $evidenceId, array $controlIds, ?int $userId): void {
        foreach (array_unique(array_filter(array_map('intval', $controlIds))) as $cid) {
            if (!$this->db->fetchOne('SELECT id FROM grc_internal_controls WHERE id = :id', [':id' => $cid])) continue;
            $this->db->query(
                'INSERT IGNORE INTO grc_evidence_control_map (evidence_id, control_id, linked_by) VALUES (:e, :c, :u)',
                [':e' => $evidenceId, ':c' => $cid, ':u' => $userId]
            );
        }
    }

    private function nextRef(string $base): string {
        $ref = substr($base, 0, 46); $n = 1;
        while ($this->db->fetchOne('SELECT id FROM grc_evidence WHERE evidence_ref = :r', [':r' => $ref])) {
            $ref = substr($base, 0, 46) . '-' . (++$n);
        }
        return $ref;
    }
}
