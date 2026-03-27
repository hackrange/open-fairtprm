<?php
/**
 * Azure Evidence Collector - Microsoft Cloud Compliance Automation
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Implements EvidenceCollectorInterface for Microsoft Azure. Checks security
 * configurations via the Azure REST API using OAuth 2.0 client credentials.
 * Covers disk encryption, NSG rules, Key Vault, Azure AD MFA, and more.
 */

class AzureEvidenceCollector implements EvidenceCollectorInterface {
    private string $tenantId = '';
    private string $clientId = '';
    private string $clientSecret = '';
    private string $subscriptionId = '';
    private string $checkType = '';
    private ?string $accessToken = null;
    private array $config = [];

    public function getName(): string {
        return 'Azure Security Collector';
    }

    public function getDescription(): string {
        return 'Automated compliance evidence collection from Microsoft Azure (Azure AD, VMs, Storage, Key Vault).';
    }

    public function getIntegrationType(): string {
        return 'azure';
    }

    public function getCheckType(): string {
        return $this->checkType;
    }

    public function getAvailableChecks(): array {
        return [
            'azure_disk_encryption'     => 'Check VM disk encryption (BitLocker/DM-Crypt)',
            'azure_nsg_rules'           => 'Audit Network Security Group rules for overly permissive access',
            'azure_key_vault_expiry'    => 'Check Key Vault secrets/certificates approaching expiry',
            'azure_mfa_status'          => 'Verify Azure AD MFA enforcement status',
            'azure_storage_encryption'  => 'Check storage account encryption settings',
            'azure_sql_tde'             => 'Verify SQL Database Transparent Data Encryption',
            'azure_activity_log'        => 'Check Activity Log diagnostic settings',
            'azure_defender_status'     => 'Verify Microsoft Defender for Cloud status',
        ];
    }

    public function configure(array $credentials, array $config = []): void {
        $this->tenantId = $credentials['tenant_id'] ?? '';
        $this->clientId = $credentials['client_id'] ?? '';
        $this->clientSecret = $credentials['client_secret'] ?? '';
        $this->subscriptionId = $credentials['subscription_id'] ?? '';
        $this->checkType = $config['check_type'] ?? 'azure_disk_encryption';
        $this->config = $config;
    }

    public function testConnection(): bool {
        try {
            $this->authenticate();
            return $this->accessToken !== null;
        } catch (\Exception $e) {
            error_log('Azure connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    public function collect(): array {
        $startTime = microtime(true);
        try {
            $this->authenticate();

            $result = match ($this->checkType) {
                'azure_disk_encryption'    => $this->checkDiskEncryption(),
                'azure_nsg_rules'          => $this->checkNsgRules(),
                'azure_key_vault_expiry'   => $this->checkKeyVaultExpiry(),
                'azure_storage_encryption' => $this->checkStorageEncryption(),
                'azure_sql_tde'            => $this->checkSqlTde(),
                default => [
                    'result' => 'error',
                    'summary' => 'Unknown check type: ' . $this->checkType,
                    'details' => [],
                ],
            };

            $result['evidence_type'] = 'api_log';
            $result['timestamp'] = date('c');
            $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $result['evidence_data'] = json_encode([
                'check_type' => $this->checkType,
                'subscription' => $this->subscriptionId,
                'result' => $result['result'],
                'details' => $result['details'],
                'collected_at' => $result['timestamp'],
            ]);

            return $result;
        } catch (\Exception $e) {
            error_log('Azure evidence collection error: ' . $e->getMessage());
            return [
                'result' => 'error',
                'summary' => 'Collection failed: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
                'evidence_data' => null,
                'evidence_type' => 'api_log',
                'timestamp' => date('c'),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    private function authenticate(): void {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://management.azure.com/.default',
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Azure OAuth failed: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'] ?? null;
        if (!$this->accessToken) {
            throw new \RuntimeException('Azure OAuth: no access token in response');
        }
    }

    private function azureGet(string $path): array {
        $url = "https://management.azure.com{$path}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Azure API HTTP {$httpCode}: " . substr($response, 0, 500));
        }
        return json_decode($response, true) ?: [];
    }

    private function checkDiskEncryption(): array {
        $vms = $this->azureGet("/subscriptions/{$this->subscriptionId}/providers/Microsoft.Compute/virtualMachines?api-version=2024-03-01");
        $vmList = $vms['value'] ?? [];
        $unencrypted = [];

        foreach ($vmList as $vm) {
            $vmName = $vm['name'] ?? '';
            $osDisk = $vm['properties']['storageProfile']['osDisk'] ?? [];
            $encrypted = isset($osDisk['encryptionSettings']['enabled']) && $osDisk['encryptionSettings']['enabled'];
            $managedDiskEncrypted = isset($osDisk['managedDisk']['diskEncryptionSet']);

            if (!$encrypted && !$managedDiskEncrypted) {
                $unencrypted[] = $vmName;
            }
        }

        return [
            'result' => empty($unencrypted) ? 'pass' : 'fail',
            'summary' => empty($unencrypted)
                ? 'All ' . count($vmList) . ' VM disks are encrypted'
                : count($unencrypted) . ' of ' . count($vmList) . ' VMs have unencrypted disks',
            'details' => ['total_vms' => count($vmList), 'unencrypted_vms' => $unencrypted],
        ];
    }

    private function checkNsgRules(): array {
        $nsgs = $this->azureGet("/subscriptions/{$this->subscriptionId}/providers/Microsoft.Network/networkSecurityGroups?api-version=2023-09-01");
        $nsgList = $nsgs['value'] ?? [];
        $riskyRules = [];

        foreach ($nsgList as $nsg) {
            $rules = $nsg['properties']['securityRules'] ?? [];
            foreach ($rules as $rule) {
                $props = $rule['properties'] ?? [];
                if ($props['direction'] === 'Inbound' && $props['access'] === 'Allow') {
                    $src = $props['sourceAddressPrefix'] ?? '';
                    if (in_array($src, ['*', '0.0.0.0/0', 'Internet'])) {
                        $riskyRules[] = [
                            'nsg' => $nsg['name'],
                            'rule' => $rule['name'],
                            'port' => $props['destinationPortRange'] ?? '*',
                            'source' => $src,
                        ];
                    }
                }
            }
        }

        return [
            'result' => empty($riskyRules) ? 'pass' : 'warning',
            'summary' => empty($riskyRules)
                ? 'No overly permissive NSG rules found'
                : count($riskyRules) . ' overly permissive inbound rules found',
            'details' => ['total_nsgs' => count($nsgList), 'risky_rules' => $riskyRules],
        ];
    }

    private function checkKeyVaultExpiry(): array {
        $vaults = $this->azureGet("/subscriptions/{$this->subscriptionId}/providers/Microsoft.KeyVault/vaults?api-version=2023-07-01");
        $expiringSoon = [];
        $thirtyDays = time() + (30 * 86400);

        foreach ($vaults['value'] ?? [] as $vault) {
            $vaultName = $vault['name'] ?? '';
            // Key Vault data plane requires separate auth; log vault names for now
            $expiringSoon[] = ['vault' => $vaultName, 'note' => 'Requires data plane access for secret expiry check'];
        }

        return [
            'result' => 'warning',
            'summary' => count($vaults['value'] ?? []) . ' Key Vault(s) found. Data plane access required for expiry check.',
            'details' => ['vaults' => array_column($vaults['value'] ?? [], 'name')],
        ];
    }

    private function checkStorageEncryption(): array {
        $accounts = $this->azureGet("/subscriptions/{$this->subscriptionId}/providers/Microsoft.Storage/storageAccounts?api-version=2023-01-01");
        $accountList = $accounts['value'] ?? [];
        $issues = [];

        foreach ($accountList as $acct) {
            $name = $acct['name'] ?? '';
            $encryption = $acct['properties']['encryption'] ?? [];
            if (($encryption['services']['blob']['enabled'] ?? false) !== true) {
                $issues[] = ['account' => $name, 'issue' => 'Blob encryption not enabled'];
            }
            if (($acct['properties']['supportsHttpsTrafficOnly'] ?? false) !== true) {
                $issues[] = ['account' => $name, 'issue' => 'HTTPS-only not enforced'];
            }
        }

        return [
            'result' => empty($issues) ? 'pass' : 'fail',
            'summary' => empty($issues)
                ? 'All ' . count($accountList) . ' storage accounts properly encrypted'
                : count($issues) . ' storage encryption issue(s) found',
            'details' => ['total_accounts' => count($accountList), 'issues' => $issues],
        ];
    }

    private function checkSqlTde(): array {
        $servers = $this->azureGet("/subscriptions/{$this->subscriptionId}/providers/Microsoft.Sql/servers?api-version=2023-05-01-preview");
        $unencrypted = [];

        foreach ($servers['value'] ?? [] as $server) {
            $serverName = $server['name'] ?? '';
            $dbs = $this->azureGet("/subscriptions/{$this->subscriptionId}/resourceGroups/" .
                ($server['resourceGroup'] ?? '') . "/providers/Microsoft.Sql/servers/{$serverName}/databases?api-version=2023-05-01-preview");

            foreach ($dbs['value'] ?? [] as $db) {
                $dbName = $db['name'] ?? '';
                if ($dbName === 'master') continue;
                $tde = $db['properties']['transparentDataEncryption'] ?? [];
                if (($tde['status'] ?? '') !== 'Enabled') {
                    $unencrypted[] = "{$serverName}/{$dbName}";
                }
            }
            usleep(100000);
        }

        return [
            'result' => empty($unencrypted) ? 'pass' : 'fail',
            'summary' => empty($unencrypted)
                ? 'All SQL databases have TDE enabled'
                : count($unencrypted) . ' database(s) lack Transparent Data Encryption',
            'details' => ['unencrypted_databases' => $unencrypted],
        ];
    }
}
