<?php
/**
 * open-ctem Evidence Collector - CTEM telemetry as continuous-monitor evidence
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Implements EvidenceCollectorInterface for open-ctem. Each monitor names one
 * CTEM check (ctem_edr_coverage, ctem_kev_remediation, ...). collect() calls
 * GET {ctem}/api/v1/grc/checks/{check_type} and maps the answer straight onto
 * the collector result; ContinuousMonitor then stores the run, creates the
 * automated evidence and updates every mapped control.
 *
 * The rule that matters: this collector never reports "pass" on anything it
 * cannot vouch for. A transport error, a non-200, a malformed body, a result
 * for a different check, or a measurement older than max_age_hours all come
 * back as "error", which never marks a control effective.
 */

class OpenCtemEvidenceCollector implements EvidenceCollectorInterface {
    /** api_response_data is a TEXT column (64 KB). Stay well under it. */
    private const MAX_EVIDENCE_BYTES = 60000;
    private const DEFAULT_MAX_AGE_HOURS = 26;

    /**
     * Fallback catalog (plan section 2.1): check_type => [name, description, suggested controls].
     * Used when the integration is not configured yet or the live catalog is unavailable.
     */
    public const STATIC_CATALOG = [
        'ctem_edr_coverage'        => ['EDR coverage', 'Share of devices seen in 30 days with active, healthy EDR (pass >= 98%, warn >= 90%)', 'NIST CSF DE.CM-01, CIS 10.1, ISO 27001 A.8.7, SOC 2 CC6.8'],
        'ctem_edr_containment'     => ['EDR containment', 'Devices contained by EDR for more than 24 hours (pass = 0)', 'NIST CSF RS.MI-01, ISO A.5.26'],
        'ctem_kev_remediation'     => ['CISA KEV remediation', 'Open KEV findings past their due date (BOD 22-01, default 14 days; pass = 0)', 'NIST CSF ID.RA-01, CIS 7.7, ISO A.8.8, PCI 6.3.3'],
        'ctem_vuln_sla'            => ['Vulnerability SLA', 'Reachable criticals older than 15 days / highs older than 30 days (pass = 0, warn <= 5)', 'ISO A.8.8, CIS 7.3, NIST 800-53 RA-5, SI-2'],
        'ctem_exposed_criticals'   => ['Exposed criticals', 'Internet-facing assets with a reachable critical or KEV vulnerability (pass = 0)', 'NIST CSF PR.IR-01, CIS 12, ISO A.8.20'],
        'ctem_attack_paths'        => ['Validated attack paths', 'Open validated critical attack paths (pass = 0)', 'NIST CSF ID.RA-05, ISO A.8.8'],
        'ctem_asset_inventory'     => ['Asset inventory', 'Devices with an owner and a criticality (pass >= 95%); single-source assets counted', 'CIS 1.1, NIST CSF ID.AM-01, ISO A.5.9'],
        'ctem_stale_ad_computers'  => ['Stale AD computers', 'AD computer accounts with no logon in 90 days (warn > 0, fail > 2%)', 'CIS 1.1/5.3, ISO A.5.9'],
        'ctem_dormant_accounts'    => ['Dormant accounts', 'Enabled, non-service users idle more than 90 days (pass = 0, warn <= 10)', 'NIST 800-53 AC-2(3), CIS 5.3, ISO A.5.18, SOC 2 CC6.2'],
        'ctem_password_policy'     => ['Password policy', 'Non-service accounts whose password never expires (pass = 0)', 'CIS 5.2, ISO A.5.17, NIST IA-5'],
        'ctem_privileged_accounts' => ['Privileged accounts', 'Privileged group membership over target, or disabled but still privileged', 'CIS 5.4, ISO A.8.2, NIST AC-6'],
        'ctem_itdr_alerts'         => ['Identity threat alerts', 'Active critical/high ITDR alerts older than 24 hours (pass = 0)', 'NIST CSF DE.AE-02, RS.MA-01, ISO A.5.25'],
        'ctem_shadow_ai'           => ['Shadow AI', 'Unsanctioned AI applications in use (pass = 0, warn <= 3)', 'ISO 42001, NIST AI RMF GOVERN 1.6, ISO A.5.23'],
        'ctem_shadow_saas'         => ['Shadow SaaS', 'Unsanctioned SaaS applications with users', 'ISO A.5.23, CIS 2.3, SOC 2 CC9.2'],
        'ctem_host_hardening'      => ['Host hardening', 'Firewall enabled and USB storage blocked coverage (pass >= 98%)', 'CIS 4.4, 4.5, 10.3, ISO A.8.9'],
        'ctem_risk_exceptions'     => ['Risk exceptions', 'Expired or permanent risk exceptions without review (pass = 0)', 'ISO 27001 6.1.3, NIST CSF GV.RM'],
        'ctem_monitoring_health'   => ['Monitoring health', 'Every enabled source synced within its window and every shipper checked in', 'NIST CSF DE.CM, SOC 2 CC7.1, ISO A.8.16'],
    ];

    private ?OpenCtemClient $client = null;
    private ?string $configError = null;
    private string $checkType = '';
    private string $instance = '';
    private array $config = [];

    public function getName(): string { return 'open-ctem'; }

    public function getDescription(): string {
        return 'Continuous evidence from open-ctem telemetry: EDR, vulnerabilities and KEV, attack paths, identity hygiene, ITDR, shadow AI/SaaS, hardening and monitoring health.';
    }

    public function getIntegrationType(): string { return 'open_ctem'; }

    public function getCheckType(): string { return $this->checkType; }

    public function getAvailableChecks(): array {
        $out = [];
        foreach (self::catalog() as $type => $c) $out[$type] = $c['description'];
        return $out;
    }

    /**
     * Catalog for the UI: the live catalog cached by Admin > open-ctem (Test
     * connection), else the static list. check_type => [name, description, controls].
     */
    public static function catalog(): array {
        $cached = json_decode((string)getAppConfig('open_ctem_catalog', ''), true);
        $out = [];
        if (is_array($cached)) {
            foreach ($cached as $c) {
                $t = (string)($c['check_type'] ?? '');
                if (!OpenCtemClient::isValidCheckType($t)) continue;
                $static = self::STATIC_CATALOG[$t] ?? [$t, '', ''];
                $sc = $c['suggested_controls'] ?? $static[2];
                $out[$t] = [
                    'name'        => mb_substr((string)($c['name'] ?? $static[0]), 0, 120),
                    'description' => mb_substr((string)($c['description'] ?? $static[1]), 0, 500),
                    'controls'    => mb_substr(is_array($sc) ? implode(', ', array_map('strval', $sc)) : (string)$sc, 0, 500),
                ];
            }
        }
        if ($out) return $out;
        foreach (self::STATIC_CATALOG as $t => [$n, $d, $ctl]) {
            $out[$t] = ['name' => $n, 'description' => $d, 'controls' => $ctl];
        }
        return $out;
    }

    public function configure(array $credentials, array $config = []): void {
        $this->checkType = (string)($config['check_type'] ?? '');
        $this->config = $config;
        $endpoint = (string)($credentials['endpoint_url'] ?? '');
        $this->instance = (string)(parse_url($endpoint, PHP_URL_HOST) ?: '');
        try {
            $this->client = OpenCtemClient::fromIntegration($credentials, $endpoint);
        } catch (OpenCtemException $e) {
            $this->client = null;
            $this->configError = $e->getMessage();
        }
    }

    public function testConnection(): bool {
        if (!$this->client) return false;
        try {
            $this->client->getCatalog();
            return true;
        } catch (OpenCtemException $e) {
            return false;
        }
    }

    public function collect(): array {
        if (!$this->client) return $this->error($this->configError ?? 'open-ctem integration is not configured');
        if (!OpenCtemClient::isValidCheckType($this->checkType)) {
            return $this->error('Not an open-ctem check type: ' . substr(preg_replace('/[^a-z0-9_]/i', '', $this->checkType), 0, 60));
        }
        try {
            $r = $this->client->getCheck($this->checkType);
        } catch (OpenCtemException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            error_log('open-ctem collect: ' . $e->getMessage());
            return $this->error('CTEM request failed');
        }
        return $this->mapResult($r);
    }

    /** Map a CTEM check body onto the collector result. Public for the test suite. */
    public function mapResult(array $r): array {
        $result = (string)($r['result'] ?? '');
        if (!in_array($result, ['pass', 'warning', 'fail', 'error'], true)) {
            return $this->error('CTEM returned no valid result');
        }
        if (($r['check_type'] ?? null) !== $this->checkType) {
            return $this->error('CTEM answered for a different check');
        }
        $summary = mb_substr(trim((string)($r['summary'] ?? '')), 0, 1000);
        $measured = strtotime((string)($r['measured_at'] ?? ''));
        if ($measured === false) return $this->error('CTEM result has no measured_at');

        $maxAge = (int)($this->config['max_age_hours'] ?? self::DEFAULT_MAX_AGE_HOURS);
        $maxAge = max(1, min(24 * 14, $maxAge));
        if ($result !== 'error' && $measured < time() - $maxAge * 3600) {
            return $this->error('CTEM measurement is stale (measured ' . gmdate('Y-m-d H:i', $measured) . ' UTC, older than ' . $maxAge . ' h)');
        }
        if ($measured > time() + 900) return $this->error('CTEM measurement is dated in the future');

        $details = is_array($r['details'] ?? null) ? $r['details'] : [];
        $details['check_version'] = (int)($r['check_version'] ?? 0);
        $details['measured_at'] = gmdate('c', $measured);
        if ($result === 'error') {
            // CTEM's own refusal (e.g. stale source data) is surfaced as-is, never as pass.
            return $this->error('CTEM: ' . ($summary !== '' ? $summary : 'check could not be evaluated'), $details);
        }

        $evidence = [
            'source'         => 'open_ctem',
            'instance'       => $this->instance,
            'check_type'     => $this->checkType,
            'check_version'  => (int)($r['check_version'] ?? 0),
            'result'         => $result,
            'summary'        => $summary,
            'measured_at'    => gmdate('c', $measured),
            'collected_at'   => gmdate('c'),
            'thresholds'     => is_array($r['thresholds'] ?? null) ? $r['thresholds'] : [],
            'data_freshness' => is_array($r['data_freshness'] ?? null) ? $r['data_freshness'] : [],
            'details'        => $details,
            'evidence_data'  => $r['evidence_data'] ?? null,
        ];
        $json = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > self::MAX_EVIDENCE_BYTES) {
            $evidence['evidence_data'] = null;
            $evidence['details'] = ['check_version' => $details['check_version'], 'measured_at' => $details['measured_at']];
            $evidence['truncated'] = true;
            $json = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return [
            'result'        => $result,
            'summary'       => $summary !== '' ? $summary : ucfirst($result),
            'details'       => $details,
            'evidence_data' => $json,
            'evidence_type' => 'automated',
            'timestamp'     => date('c'),
        ];
    }

    private function error(string $why, array $details = []): array {
        return [
            'result'        => 'error',
            'summary'       => mb_substr($why, 0, 500),
            'details'       => $details + ['error' => mb_substr($why, 0, 500)],
            'evidence_data' => null,
            'evidence_type' => 'automated',
            'timestamp'     => date('c'),
        ];
    }
}
