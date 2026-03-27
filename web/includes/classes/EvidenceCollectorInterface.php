<?php
/**
 * Evidence Collector Interface - The "Automation Engine" Contract
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Every automated evidence collector must implement this interface. Whether
 * you're checking AWS IAM for MFA status, Azure AD for conditional access,
 * or GitHub for branch protection rules -- the pattern is the same:
 * configure, collect, and return a structured result.
 *
 * The continuous monitor cron job calls collect() on each enabled collector,
 * stores the result, and updates compliance status across all mapped
 * frameworks simultaneously. One check, many frameworks updated.
 */

interface EvidenceCollectorInterface {
    /**
     * Human-readable name for this collector.
     */
    public function getName(): string;

    /**
     * What this collector checks (for display in UI).
     */
    public function getDescription(): string;

    /**
     * The integration type this collector requires (aws, azure, github, etc.)
     */
    public function getIntegrationType(): string;

    /**
     * The specific check type identifier.
     * Examples: aws_iam_mfa, azure_disk_encryption, github_branch_protection
     */
    public function getCheckType(): string;

    /**
     * Configure the collector with integration credentials and any
     * collector-specific settings.
     *
     * @param array $credentials Decrypted credentials from grc_integrations
     * @param array $config Collector-specific configuration from grc_continuous_monitors
     */
    public function configure(array $credentials, array $config = []): void;

    /**
     * Test the connection to the external service.
     * Returns true if the service is reachable and credentials are valid.
     */
    public function testConnection(): bool;

    /**
     * Execute the evidence collection check.
     *
     * @return array{
     *     result: 'pass'|'fail'|'error'|'warning',
     *     summary: string,
     *     details: array,
     *     evidence_data: ?string,
     *     evidence_type: string,
     *     timestamp: string
     * }
     */
    public function collect(): array;

    /**
     * Get the list of control check types this collector can perform.
     * Used by the UI to show available checks when configuring monitors.
     *
     * @return array<string, string> check_type => description
     */
    public function getAvailableChecks(): array;
}
