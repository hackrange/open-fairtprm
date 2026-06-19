<?php
declare(strict_types=1);

/**
 * Virus Scan Service - ClamAV Integration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Talks to clamd over a Unix socket to scan uploaded files before they get
 * encrypted and stored. Returns a simple clean/dirty verdict. Supports
 * fail-open mode so a dead clamd doesn't brick all file uploads — because
 * "sorry, our antivirus daemon crashed so you can't submit your SOC 2 report"
 * is not a conversation anyone wants to have with a vendor.
 */
class VirusScanService
{
    private string $socketPath;
    private bool $enabled;
    private bool $failOpen;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->enabled    = (bool) $config->get('security.antivirus.enabled', true);
        $this->socketPath = $config->get('security.antivirus.socket', '/var/run/clamav/clamd.sock');
        // SECURITY: default to fail-CLOSED -- if clamd is unavailable, block the upload
        // rather than accept it unscanned. Can be overridden via config for environments
        // that deliberately run without an AV daemon.
        $this->failOpen   = (bool) $config->get('security.antivirus.fail_open', false);
    }

    /**
     * Scan a file for malware via clamd.
     *
     * Sends a SCAN command over the Unix socket and parses the response.
     * clamd responds with either "path: OK" or "path: ThreatName FOUND".
     *
     * @param string $filePath Absolute path to the file to scan (usually the tmp_name)
     * @return array{clean: bool, threat: string|null}
     * @throws \RuntimeException If clamd is unavailable and fail_open is false
     */
    public function scan(string $filePath): array
    {
        if (!$this->enabled) {
            return ['clean' => true, 'threat' => null];
        }

        // Make sure the file actually exists before we bother clamd
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Virus scan failed: file not readable');
        }

        $socket = $this->connect();
        if ($socket === false) {
            return $this->handleUnavailable('Could not connect to clamd socket');
        }

        try {
            // INSTREAM — stream the file CONTENTS to clamd over the socket rather than
            // asking clamd to read the path. Uploaded temp files are owned by the web
            // user (mode 600), so clamd (running as the 'clamav' user) cannot read them
            // from disk via SCAN ("Access denied") -- INSTREAM avoids that entirely.
            // Protocol: "zINSTREAM\0", then <4-byte BE length><bytes> chunks, terminated
            // by a zero-length chunk. clamd replies "stream: OK" or "stream: <threat> FOUND".
            $data = @file_get_contents($filePath);
            if ($data === false) {
                fclose($socket);
                throw new \RuntimeException('Virus scan failed: file not readable');
            }

            $ok = (fwrite($socket, "zINSTREAM\0") !== false);
            $offset = 0;
            $len = strlen($data);
            $chunkSize = 65536;
            while ($ok && $offset < $len) {
                $chunk = substr($data, $offset, $chunkSize);
                $offset += strlen($chunk);
                if (fwrite($socket, pack('N', strlen($chunk)) . $chunk) === false) {
                    $ok = false;
                }
            }
            if ($ok && fwrite($socket, pack('N', 0)) === false) { // zero-length chunk = end of stream
                $ok = false;
            }
            if (!$ok) {
                fclose($socket);
                return $this->handleUnavailable('Failed to stream file to clamd (INSTREAM)');
            }

            $response = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 4096);
                if ($chunk === false) {
                    break;
                }
                $response .= $chunk;
            }
            fclose($socket);

            $response = trim($response);
            if ($response === '') {
                return $this->handleUnavailable('Empty response from clamd');
            }

            return $this->parseResponse($response);
        } catch (\Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            return $this->handleUnavailable('Virus scan error: ' . $e->getMessage());
        }
    }

    /**
     * Connect to the clamd Unix socket.
     *
     * @return resource|false
     */
    private function connect()
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }

        $socket = @fsockopen('unix://' . $this->socketPath, -1, $errno, $errstr, 5);
        return $socket ?: false;
    }

    /**
     * Parse clamd's response line.
     *
     * Format: "/path/to/file: OK" or "/path/to/file: Win.Trojan.Whatever FOUND"
     *
     * @param string $response The raw response from clamd
     * @return array{clean: bool, threat: string|null}
     */
    private function parseResponse(string $response): array
    {
        // Check for the FOUND suffix — means malware detected
        if (preg_match('/:\s+(.+)\s+FOUND$/', $response, $matches)) {
            $threat = trim($matches[1]);
            error_log("ANTIVIRUS: Malware detected — {$threat}");
            return ['clean' => false, 'threat' => $threat];
        }

        // Check for the OK suffix — file is clean
        if (preg_match('/:\s+OK$/', $response)) {
            return ['clean' => true, 'threat' => null];
        }

        // Check for ERROR in response
        if (preg_match('/:\s+(.+)\s+ERROR$/', $response, $matches)) {
            return $this->handleUnavailable('clamd error: ' . trim($matches[1]));
        }

        // Unexpected response format
        return $this->handleUnavailable('Unexpected clamd response: ' . $response);
    }

    /**
     * Handle clamd being unavailable or returning an error.
     *
     * In fail-open mode: logs a warning and lets the upload proceed.
     * In fail-closed mode: throws a RuntimeException to block the upload.
     *
     * @param string $reason What went wrong
     * @return array{clean: bool, threat: string|null}
     * @throws \RuntimeException If fail_open is false
     */
    private function handleUnavailable(string $reason): array
    {
        if ($this->failOpen) {
            error_log("ANTIVIRUS WARNING (fail-open): {$reason} — upload allowed without scan");
            return ['clean' => true, 'threat' => null];
        }

        throw new \RuntimeException("Virus scan unavailable: {$reason}");
    }
}
