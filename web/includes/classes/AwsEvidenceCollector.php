<?php
/**
 * AWS Evidence Collector - Cloud Compliance Automation
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Implements the EvidenceCollectorInterface for AWS services. Checks things
 * like IAM MFA enforcement, S3 bucket encryption, CloudTrail logging, and
 * other security configurations that map to multiple compliance frameworks.
 *
 * When this collector verifies MFA is enabled for all IAM users, it
 * simultaneously satisfies SOC 2 CC6.1, ISO 27001 A.8.5, CMMC AC.L2-3.1.1,
 * PCI DSS 8.3, and NIST CSF PR.AC-1. One API call, five frameworks updated.
 *
 * Uses AWS SDK v4 REST API directly via cURL (no Composer dependency).
 * Implements AWS Signature Version 4 for request signing.
 */

class AwsEvidenceCollector implements EvidenceCollectorInterface {
    private string $accessKeyId = '';
    private string $secretAccessKey = '';
    private string $region = 'us-east-1';
    private string $checkType = '';
    private array $config = [];

    public function getName(): string {
        return 'AWS Security Collector';
    }

    public function getDescription(): string {
        return 'Automated compliance evidence collection from AWS services (IAM, S3, CloudTrail, KMS, EC2).';
    }

    public function getIntegrationType(): string {
        return 'aws';
    }

    public function getCheckType(): string {
        return $this->checkType;
    }

    public function getAvailableChecks(): array {
        return [
            'aws_iam_mfa'           => 'Check all IAM users have MFA enabled',
            'aws_iam_password_policy'=> 'Verify IAM password policy meets requirements',
            'aws_iam_unused_creds'  => 'Find IAM credentials unused for 90+ days',
            'aws_s3_encryption'     => 'Verify S3 buckets have default encryption',
            'aws_s3_public_access'  => 'Check S3 public access block configuration',
            'aws_cloudtrail'        => 'Verify CloudTrail is enabled and logging',
            'aws_kms_rotation'      => 'Check KMS key rotation is enabled',
            'aws_ebs_encryption'    => 'Verify EBS volume encryption defaults',
            'aws_rds_encryption'    => 'Check RDS instance encryption at rest',
            'aws_vpc_flow_logs'     => 'Verify VPC flow logs are enabled',
        ];
    }

    public function configure(array $credentials, array $config = []): void {
        $this->accessKeyId = $credentials['access_key_id'] ?? '';
        $this->secretAccessKey = $credentials['secret_access_key'] ?? '';
        $this->region = $credentials['region'] ?? $config['region'] ?? 'us-east-1';
        $this->checkType = $config['check_type'] ?? 'aws_iam_mfa';
        $this->config = $config;
    }

    public function testConnection(): bool {
        try {
            $response = $this->awsApiCall('iam', 'GET', '/', [
                'Action' => 'GetUser',
                'Version' => '2010-05-08',
            ], 'us-east-1');
            return isset($response['GetUserResponse']) || isset($response['ErrorResponse']);
        } catch (\Exception $e) {
            error_log('AWS connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    public function collect(): array {
        $startTime = microtime(true);
        try {
            $result = match ($this->checkType) {
                'aws_iam_mfa'            => $this->checkIamMfa(),
                'aws_iam_password_policy'=> $this->checkIamPasswordPolicy(),
                'aws_iam_unused_creds'   => $this->checkUnusedCredentials(),
                'aws_s3_encryption'      => $this->checkS3Encryption(),
                'aws_s3_public_access'   => $this->checkS3PublicAccess(),
                'aws_cloudtrail'         => $this->checkCloudTrail(),
                'aws_kms_rotation'       => $this->checkKmsRotation(),
                'aws_ebs_encryption'     => $this->checkEbsEncryption(),
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
                'region' => $this->region,
                'result' => $result['result'],
                'details' => $result['details'],
                'collected_at' => $result['timestamp'],
            ]);

            return $result;
        } catch (\Exception $e) {
            error_log('AWS evidence collection error: ' . $e->getMessage());
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

    // =========================================================================
    // CHECK IMPLEMENTATIONS
    // =========================================================================

    private function checkIamMfa(): array {
        $response = $this->awsApiCall('iam', 'GET', '/', [
            'Action' => 'ListUsers',
            'Version' => '2010-05-08',
        ], 'us-east-1');

        $users = $response['ListUsersResponse']['ListUsersResult']['Users']['member'] ?? [];
        if (isset($users['UserName'])) $users = [$users]; // Single user edge case

        $usersWithoutMfa = [];
        $totalUsers = 0;

        foreach ($users as $user) {
            $totalUsers++;
            $mfaResponse = $this->awsApiCall('iam', 'GET', '/', [
                'Action' => 'ListMFADevices',
                'Version' => '2010-05-08',
                'UserName' => $user['UserName'],
            ], 'us-east-1');

            $mfaDevices = $mfaResponse['ListMFADevicesResponse']['ListMFADevicesResult']['MFADevices']['member'] ?? [];
            if (empty($mfaDevices)) {
                $usersWithoutMfa[] = $user['UserName'];
            }
            usleep(250000); // Rate limit: 4 calls/sec
        }

        $allHaveMfa = empty($usersWithoutMfa);
        return [
            'result' => $allHaveMfa ? 'pass' : 'fail',
            'summary' => $allHaveMfa
                ? "All {$totalUsers} IAM users have MFA enabled"
                : count($usersWithoutMfa) . " of {$totalUsers} IAM users lack MFA",
            'details' => [
                'total_users' => $totalUsers,
                'users_without_mfa' => $usersWithoutMfa,
                'mfa_coverage' => $totalUsers > 0 ? round(($totalUsers - count($usersWithoutMfa)) / $totalUsers * 100, 1) : 100,
            ],
        ];
    }

    private function checkIamPasswordPolicy(): array {
        $response = $this->awsApiCall('iam', 'GET', '/', [
            'Action' => 'GetAccountPasswordPolicy',
            'Version' => '2010-05-08',
        ], 'us-east-1');

        $policy = $response['GetAccountPasswordPolicyResponse']['GetAccountPasswordPolicyResult']['PasswordPolicy'] ?? null;
        if (!$policy) {
            return [
                'result' => 'fail',
                'summary' => 'No password policy configured',
                'details' => ['error' => 'Password policy not found'],
            ];
        }

        $issues = [];
        if (($policy['MinimumPasswordLength'] ?? 0) < 14) $issues[] = 'Minimum length < 14';
        if (($policy['RequireUppercaseCharacters'] ?? 'false') !== 'true') $issues[] = 'Uppercase not required';
        if (($policy['RequireLowercaseCharacters'] ?? 'false') !== 'true') $issues[] = 'Lowercase not required';
        if (($policy['RequireNumbers'] ?? 'false') !== 'true') $issues[] = 'Numbers not required';
        if (($policy['RequireSymbols'] ?? 'false') !== 'true') $issues[] = 'Symbols not required';
        if (($policy['MaxPasswordAge'] ?? 999) > 90) $issues[] = 'Max password age > 90 days';

        return [
            'result' => empty($issues) ? 'pass' : 'fail',
            'summary' => empty($issues) ? 'Password policy meets requirements' : count($issues) . ' policy issue(s) found',
            'details' => ['policy' => $policy, 'issues' => $issues],
        ];
    }

    private function checkUnusedCredentials(): array {
        $response = $this->awsApiCall('iam', 'GET', '/', [
            'Action' => 'GenerateCredentialReport',
            'Version' => '2010-05-08',
        ], 'us-east-1');

        sleep(2);

        $response = $this->awsApiCall('iam', 'GET', '/', [
            'Action' => 'GetCredentialReport',
            'Version' => '2010-05-08',
        ], 'us-east-1');

        $report = $response['GetCredentialReportResponse']['GetCredentialReportResult']['Content'] ?? '';
        $csvData = base64_decode($report);
        $lines = explode("\n", $csvData);

        $unusedUsers = [];
        if (count($lines) > 1) {
            $headers = str_getcsv(array_shift($lines));
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $row = array_combine($headers, str_getcsv($line));
                $lastUsed = $row['password_last_used'] ?? 'N/A';
                if ($lastUsed !== 'N/A' && $lastUsed !== 'no_information') {
                    $daysSince = (int)((time() - strtotime($lastUsed)) / 86400);
                    if ($daysSince > 90) {
                        $unusedUsers[] = ['user' => $row['user'] ?? '', 'days_inactive' => $daysSince];
                    }
                }
            }
        }

        return [
            'result' => empty($unusedUsers) ? 'pass' : 'warning',
            'summary' => empty($unusedUsers)
                ? 'No credentials unused for 90+ days'
                : count($unusedUsers) . ' users with credentials unused for 90+ days',
            'details' => ['unused_credentials' => $unusedUsers],
        ];
    }

    private function checkS3Encryption(): array {
        $response = $this->awsApiCall('s3', 'GET', '/', [], $this->region);
        $buckets = [];
        $xmlStr = $response['_raw'] ?? '';

        if (preg_match_all('/<Name>(.*?)<\/Name>/', $xmlStr, $matches)) {
            $buckets = $matches[1];
        }

        $unencrypted = [];
        foreach ($buckets as $bucket) {
            try {
                $encResponse = $this->awsApiCall('s3', 'GET', "/{$bucket}?encryption", [], $this->region, $bucket);
                // If we get here without error, encryption is configured
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'ServerSideEncryptionConfigurationNotFoundError') !== false) {
                    $unencrypted[] = $bucket;
                }
            }
            usleep(100000);
        }

        return [
            'result' => empty($unencrypted) ? 'pass' : 'fail',
            'summary' => empty($unencrypted)
                ? 'All ' . count($buckets) . ' S3 buckets have default encryption'
                : count($unencrypted) . ' of ' . count($buckets) . ' buckets lack default encryption',
            'details' => [
                'total_buckets' => count($buckets),
                'unencrypted_buckets' => $unencrypted,
            ],
        ];
    }

    private function checkS3PublicAccess(): array {
        $response = $this->awsApiCall('s3control', 'GET', '/v20180820/configuration/publicAccessBlock', [
            'x-amz-account-id' => $this->config['account_id'] ?? '',
        ], $this->region);

        $blocked = true;
        $details = [];
        $fields = ['BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets'];
        foreach ($fields as $field) {
            $val = $response[$field] ?? 'false';
            $details[$field] = $val;
            if ($val !== 'true') $blocked = false;
        }

        return [
            'result' => $blocked ? 'pass' : 'fail',
            'summary' => $blocked ? 'Account-level S3 public access block is enabled' : 'S3 public access block is not fully configured',
            'details' => $details,
        ];
    }

    private function checkCloudTrail(): array {
        $response = $this->awsApiCall('cloudtrail', 'POST', '/', [
            'Action' => 'DescribeTrails',
        ], $this->region);

        $trails = $response['DescribeTrailsResponse']['DescribeTrailsResult']['trailList'] ?? [];
        if (empty($trails)) {
            return [
                'result' => 'fail',
                'summary' => 'No CloudTrail trails configured',
                'details' => [],
            ];
        }

        $multiRegionTrails = array_filter($trails, fn($t) => ($t['IsMultiRegionTrail'] ?? false));
        $loggingTrails = array_filter($trails, fn($t) => ($t['IsLogging'] ?? false));

        $hasMultiRegion = !empty($multiRegionTrails);
        $allLogging = count($loggingTrails) === count($trails);

        return [
            'result' => ($hasMultiRegion && $allLogging) ? 'pass' : 'warning',
            'summary' => count($trails) . ' trail(s) found. Multi-region: ' . ($hasMultiRegion ? 'Yes' : 'No') . '. All logging: ' . ($allLogging ? 'Yes' : 'No'),
            'details' => [
                'trail_count' => count($trails),
                'multi_region' => $hasMultiRegion,
                'all_logging' => $allLogging,
            ],
        ];
    }

    private function checkKmsRotation(): array {
        $response = $this->awsApiCall('kms', 'POST', '/', [
            'Action' => 'ListKeys',
        ], $this->region);

        $keys = $response['ListKeysResponse']['ListKeysResult']['Keys'] ?? [];
        $nonRotating = [];

        foreach ($keys as $key) {
            $keyId = $key['KeyId'] ?? '';
            if (empty($keyId)) continue;

            $rotResponse = $this->awsApiCall('kms', 'POST', '/', [
                'Action' => 'GetKeyRotationStatus',
                'KeyId' => $keyId,
            ], $this->region);

            if (($rotResponse['GetKeyRotationStatusResponse']['GetKeyRotationStatusResult']['KeyRotationEnabled'] ?? 'false') !== 'true') {
                $nonRotating[] = $keyId;
            }
            usleep(100000);
        }

        return [
            'result' => empty($nonRotating) ? 'pass' : 'warning',
            'summary' => empty($nonRotating)
                ? 'All ' . count($keys) . ' KMS keys have rotation enabled'
                : count($nonRotating) . ' of ' . count($keys) . ' KMS keys lack rotation',
            'details' => [
                'total_keys' => count($keys),
                'non_rotating_keys' => $nonRotating,
            ],
        ];
    }

    private function checkEbsEncryption(): array {
        $response = $this->awsApiCall('ec2', 'GET', '/', [
            'Action' => 'GetEbsEncryptionByDefault',
            'Version' => '2016-11-15',
        ], $this->region);

        $enabled = ($response['GetEbsEncryptionByDefaultResponse']['ebsEncryptionByDefault'] ?? 'false') === 'true';

        return [
            'result' => $enabled ? 'pass' : 'fail',
            'summary' => $enabled ? 'EBS encryption by default is enabled' : 'EBS encryption by default is NOT enabled',
            'details' => ['ebs_encryption_default' => $enabled],
        ];
    }

    // =========================================================================
    // AWS API HELPERS (Signature V4)
    // =========================================================================

    private function awsApiCall(string $service, string $method, string $path, array $params, string $region, ?string $bucket = null): array {
        $host = match ($service) {
            'iam' => 'iam.amazonaws.com',
            's3' => $bucket ? "{$bucket}.s3.{$region}.amazonaws.com" : "s3.{$region}.amazonaws.com",
            's3control' => "s3-control.{$region}.amazonaws.com",
            'ec2' => "ec2.{$region}.amazonaws.com",
            'cloudtrail' => "cloudtrail.{$region}.amazonaws.com",
            'kms' => "kms.{$region}.amazonaws.com",
            default => "{$service}.{$region}.amazonaws.com",
        };

        $now = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $queryString = ($method === 'GET' && !empty($params) && $service !== 's3') ? http_build_query($params) : '';
        $url = "https://{$host}{$path}" . ($queryString ? "?{$queryString}" : '');

        $body = ($method === 'POST' && !empty($params)) ? http_build_query($params) : '';
        $payloadHash = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-amz-date' => $now,
            'x-amz-content-sha256' => $payloadHash,
        ];
        if ($method === 'POST' && $service !== 's3') {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders[] = strtolower($k);
        }
        $signedHeaderStr = implode(';', $signedHeaders);

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $queryString,
            $canonicalHeaders,
            $signedHeaderStr,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true),
                true),
            true),
        true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaderStr}, Signature={$signature}";

        $curlHeaders = ["Authorization: {$authHeader}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("AWS API call failed: {$error}");
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException("AWS API returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        // Parse XML response to array
        if (strpos($response, '<?xml') !== false) {
            $xml = @simplexml_load_string($response);
            if ($xml) {
                $parsed = json_decode(json_encode($xml), true);
                $parsed['_raw'] = $response;
                return $parsed;
            }
        }

        // Try JSON
        $decoded = json_decode($response, true);
        if ($decoded !== null) return $decoded;

        return ['_raw' => $response];
    }
}
