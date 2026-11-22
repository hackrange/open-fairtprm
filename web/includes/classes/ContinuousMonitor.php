<?php
/**
 * Continuous Monitor - The "Drata" Feature
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Orchestrates automated evidence collection by running registered collectors
 * on schedule. When a monitor runs, it executes the collector, stores the
 * result, auto-creates evidence records, and updates the compliance status
 * of EVERY framework that the monitored control maps to -- simultaneously.
 *
 * This is the "set it and forget it" engine that keeps compliance current.
 * Configure once, and evidence freshness is maintained automatically.
 */

class ContinuousMonitor {
    /**
     * SECURITY: the only collector classes a monitor may instantiate. Both
     * getAvailableCollectors() and runMonitor() use this list, so a stored
     * collector_class outside it never runs (object-injection guard).
     */
    public const ALLOWED_COLLECTORS = [
        'AwsEvidenceCollector',
        'AzureEvidenceCollector',
        'OpenCtemEvidenceCollector',
    ];

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
     * Get all registered collectors that can be instantiated.
     * @return array<string, EvidenceCollectorInterface>
     */
    public function getAvailableCollectors(): array {
        $collectors = [];
        foreach (self::ALLOWED_COLLECTORS as $className) {
            if (class_exists($className)) {
                $instance = new $className();
                if ($instance instanceof EvidenceCollectorInterface) {
                    $collectors[$instance->getIntegrationType()] = $instance;
                }
            }
        }
        return $collectors;
    }

    /**
     * Next free monitor_ref (MON-001, MON-002...). monitor_ref is NOT NULL and
     * UNIQUE, so every insert must set it; strict SQL mode rejects one without.
     */
    public static function nextMonitorRef(): string {
        $db = Database::getInstance();
        $max = (int)($db->fetchOne(
            "SELECT MAX(CAST(SUBSTRING(monitor_ref, 5) AS UNSIGNED)) AS n
               FROM grc_continuous_monitors WHERE monitor_ref REGEXP '^MON-[0-9]+$'"
        )['n'] ?? 0);
        return 'MON-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get all monitors with their current status.
     */
    public function getMonitors(bool $enabledOnly = false): array {
        $sql = 'SELECT m.*, i.name as integration_name, i.integration_type
                FROM grc_continuous_monitors m
                LEFT JOIN grc_integrations i ON i.id = m.integration_id';

        if ($enabledOnly) {
            $sql .= ' WHERE m.is_enabled = 1';
        }
        $sql .= ' ORDER BY m.name';
        return $this->db->fetchAll($sql);
    }

    /**
     * Get monitors that are due to run.
     */
    public function getDueMonitors(): array {
        return $this->db->fetchAll(
            'SELECT m.*, i.name as integration_name, i.integration_type,
                    i.credentials_encrypted, i.endpoint_url, i.region
             FROM grc_continuous_monitors m
             JOIN grc_integrations i ON i.id = m.integration_id
             WHERE m.is_enabled = 1
               AND (m.next_run_at IS NULL OR m.next_run_at <= NOW())
             ORDER BY m.next_run_at ASC'
        );
    }

    /**
     * Run a specific monitor. This is the core execution loop.
     */
    public function runMonitor(int $monitorId): array {
        $monitor = $this->db->fetchOne(
            'SELECT m.*, i.credentials_encrypted, i.integration_type, i.endpoint_url, i.region
             FROM grc_continuous_monitors m
             JOIN grc_integrations i ON i.id = m.integration_id
             WHERE m.id = :id',
            [':id' => $monitorId]
        );

        if (!$monitor) {
            return ['result' => 'error', 'summary' => 'Monitor not found'];
        }

        // Decrypt integration credentials
        $credentials = [];
        if (!empty($monitor['credentials_encrypted'])) {
            $decrypted = $this->encryption->decrypt($monitor['credentials_encrypted']);
            $credentials = json_decode($decrypted, true) ?: [];
        }
        if (!empty($monitor['region'])) {
            $credentials['region'] = $monitor['region'];
        }
        $credentials['endpoint_url'] = (string)($monitor['endpoint_url'] ?? '');

        // Instantiate the collector
        $collectorClass = $monitor['collector_class'];
        // SECURITY: only instantiate known, vetted collector classes — never an
        // arbitrary class name from the stored collector_class (object-injection guard).
        if (!in_array($collectorClass, self::ALLOWED_COLLECTORS, true)) {
            return ['result' => 'error', 'summary' => "Collector class not allowed: {$collectorClass}"];
        }
        if (!class_exists($collectorClass)) {
            return ['result' => 'error', 'summary' => "Collector class not found: {$collectorClass}"];
        }

        $collector = new $collectorClass();
        if (!($collector instanceof EvidenceCollectorInterface)) {
            return ['result' => 'error', 'summary' => "Class {$collectorClass} does not implement EvidenceCollectorInterface"];
        }

        $collectorConfig = json_decode($monitor['collector_config'] ?? '{}', true) ?: [];
        $collectorConfig['check_type'] = $monitor['check_type'];
        $collector->configure($credentials, $collectorConfig);

        // Execute the check
        $startTime = microtime(true);
        $result = $collector->collect();
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        // Auto-create evidence record for pass/fail results
        $evidenceId = null;
        if (in_array($result['result'], ['pass', 'fail', 'warning']) && !empty($result['evidence_data'])) {
            $evidenceId = $this->createEvidenceFromResult($monitor, $result);
        }

        // open-ctem "attach report": also store the signed detail report as
        // encrypted evidence. A refused or failed report never changes the
        // check's result; the reason is kept on the run.
        if ($collectorClass === 'OpenCtemEvidenceCollector' && !empty($monitor['attach_report'])
            && $result['result'] !== 'error') {
            try {
                $result['details']['report_evidence_id'] = (new OpenCtemEvidenceService())->attachReport($monitor);
            } catch (Throwable $e) {
                $result['details']['report_error'] = $e instanceof OpenCtemException ? $e->getMessage() : 'report could not be stored';
                if (!($e instanceof OpenCtemException)) error_log('open-ctem attach report: ' . $e->getMessage());
            }
        }

        // Store the result
        $this->db->insert('grc_monitor_results', [
            'monitor_id' => $monitorId,
            'run_at' => date('Y-m-d H:i:s'),
            'result' => $result['result'],
            'result_detail' => json_encode($result['details'] ?? []),
            'evidence_id' => $evidenceId,
            'duration_ms' => $durationMs,
            'error_message' => ($result['result'] === 'error') ? ($result['summary'] ?? null) : null,
        ]);

        // Update monitor status
        $nextRun = $this->calculateNextRun($monitor['frequency']);
        $failureCount = ($result['result'] === 'error' || $result['result'] === 'fail')
            ? (int)$monitor['failure_count'] + 1
            : 0;

        $this->db->update('grc_continuous_monitors', [
            'last_run_at' => date('Y-m-d H:i:s'),
            'last_result' => $result['result'],
            'last_result_detail' => json_encode($result['details'] ?? []),
            'next_run_at' => $nextRun,
            'failure_count' => $failureCount,
        ], 'id = :id', [':id' => $monitorId]);

        // Update compliance status of all mapped controls
        $this->updateControlComplianceFromMonitor($monitor, $result);

        $result['evidence_id'] = $evidenceId;
        $result['duration_ms'] = $durationMs;
        return $result;
    }

    /**
     * Run all due monitors. Called by the cron job.
     */
    public function runDueMonitors(): array {
        $results = [];
        $dueMonitors = $this->getDueMonitors();

        foreach ($dueMonitors as $monitor) {
            $results[$monitor['id']] = $this->runMonitor((int)$monitor['id']);
        }

        return $results;
    }

    /**
     * Create an evidence record from a monitor result.
     */
    private function createEvidenceFromResult(array $monitor, array $result): int {
        $grc = GRCService::getInstance();
        $ref = 'EV-AUTO-' . str_pad($monitor['id'], 3, '0', STR_PAD_LEFT) . '-' . date('Ymd-His');

        $this->db->insert('grc_evidence', [
            'evidence_ref' => $ref,
            'title' => $monitor['name'] . ' - ' . date('Y-m-d H:i'),
            'description' => $result['summary'] ?? '',
            'evidence_type' => 'automated',
            'collection_method' => 'automated',
            'api_response_data' => $result['evidence_data'] ?? null,
            'monitor_id' => $monitor['id'],
            'collected_at' => date('Y-m-d H:i:s'),
            'valid_from' => date('Y-m-d H:i:s'),
            'valid_until' => date('Y-m-d H:i:s', strtotime('+90 days')),
            'status' => 'current',
        ]);

        $evidenceId = (int)$this->db->lastInsertId();

        // Link evidence to all controls this monitor covers
        $controlIds = json_decode($monitor['control_ids'] ?? '[]', true) ?: [];
        foreach ($controlIds as $controlId) {
            $this->db->insert('grc_evidence_control_map', [
                'evidence_id' => $evidenceId,
                'control_id' => (int)$controlId,
            ]);
        }

        return $evidenceId;
    }

    /**
     * Update the compliance status of all controls mapped to this monitor.
     * This is where the multi-framework magic happens.
     */
    private function updateControlComplianceFromMonitor(array $monitor, array $result): void {
        $controlIds = json_decode($monitor['control_ids'] ?? '[]', true) ?: [];

        foreach ($controlIds as $controlId) {
            $newEffectiveness = match ($result['result']) {
                'pass' => 'effective',
                'warning' => 'partially_effective',
                'fail', 'error' => 'ineffective',
                default => 'not_tested',
            };

            // Newest event wins: last_tested_at is the time of the latest
            // monitor run or control test attestation. A test may be recorded
            // up to a day ahead, so a run never overwrites a newer event.
            $now = date('Y-m-d H:i:s');
            $this->db->update('grc_internal_controls', [
                'effectiveness' => $newEffectiveness,
                'last_tested_at' => $now,
            ], 'id = :id AND (last_tested_at IS NULL OR last_tested_at <= :run_at)', [':id' => (int)$controlId, ':run_at' => $now]);
        }
    }

    private function calculateNextRun(string $frequency): string {
        $interval = match ($frequency) {
            'hourly' => '+1 hour',
            'daily' => '+1 day',
            'weekly' => '+1 week',
            'monthly' => '+1 month',
            default => '+1 day',
        };
        return date('Y-m-d H:i:s', strtotime($interval));
    }

    /**
     * Get result history for a monitor.
     */
    public function getMonitorHistory(int $monitorId, int $limit = 30): array {
        return $this->db->fetchAll(
            'SELECT * FROM grc_monitor_results
             WHERE monitor_id = :mid
             ORDER BY run_at DESC
             LIMIT ' . (int)$limit,
            [':mid' => $monitorId]
        );
    }
}
