<?php
declare(strict_types=1);

/**
 * File Upload Service
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The bouncer at the file upload nightclub. Validates MIME types (using finfo,
 * not file extensions, because we weren't born yesterday), enforces size limits,
 * encrypts the file contents, and stores them as BLOBs in the database. No
 * filesystem storage because Docker containers and persistent files have a
 * relationship status of "it's complicated."
 *
 * Used by both the question-level file upload and the certificate upload endpoints.
 * Two doorways, same bouncer.
 */
class FileUploadService
{
    // 10MB ought to be enough for anybody's security policy PDF.
    // If it's bigger, they probably left the stock photos in.
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    // The VIP list of allowed MIME types. No executables, no scripts,
    // no "totally_legit_policy.exe" shenanigans.
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    // Human-readable error messages for PHP's upload error codes,
    // because "UPLOAD_ERR_CANT_WRITE" means nothing to a vendor
    // who just wants to upload their cert and go to lunch.
    private const UPLOAD_ERROR_MESSAGES = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
        UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
    ];

    private Database $db;
    private Encryption $encryption;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    /**
     * Validate an uploaded file — checks presence, size, and MIME type.
     * Returns the file array on success or a string error message on failure.
     *
     * @param string $fieldName The $_FILES key (e.g. 'file' or 'certificate')
     * @return array{file: array, error: null}|array{file: null, error: string}
     */
    public function validate(string $fieldName): array
    {
        // Did they actually send a file, or just an empty form?
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = self::UPLOAD_ERROR_MESSAGES[$errorCode] ?? 'Unknown upload error';
            return ['file' => null, 'error' => $errorMsg];
        }

        $file = $_FILES[$fieldName];

        // Size check — 10MB max
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['file' => null, 'error' => 'File too large (max 10MB)'];
        }

        // MIME type check using finfo (not the extension, because we're
        // security professionals, dammit). Trust but verify.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ['file' => null, 'error' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, CSV, XLS, XLSX'];
        }

        // Scan for malware before we accept the file
        $scanError = $this->scanForVirus($file['tmp_name']);
        if ($scanError !== null) {
            return ['file' => null, 'error' => $scanError];
        }

        // Stash the detected MIME type on the file array for downstream use
        $file['detected_mime'] = $mimeType;

        return ['file' => $file, 'error' => null];
    }

    /**
     * Scan a file for viruses/malware via ClamAV.
     *
     * @param string $tmpPath Path to the uploaded temp file
     * @return string|null Error message if malware found, null if clean
     */
    private function scanForVirus(string $tmpPath): ?string
    {
        try {
            $scanner = new VirusScanService();
            $result = $scanner->scan($tmpPath);

            if (!$result['clean']) {
                $threat = $result['threat'] ?? 'unknown threat';
                error_log("FILE UPLOAD BLOCKED: Malware detected ({$threat}) in upload");
                return 'File rejected: malware detected. Please scan your file and try again.';
            }

            return null;
        } catch (\RuntimeException $e) {
            // VirusScanService throws when fail_open is false and clamd is down
            error_log("FILE UPLOAD BLOCKED: " . $e->getMessage());
            return 'File upload temporarily unavailable. Please try again later.';
        }
    }

    /**
     * Encrypt a file and store it in the database as a BLOB.
     * Returns the "db:{uuid}" reference string for retrieval.
     *
     * The "db:" prefix tells the download endpoint to look in the database
     * instead of the filesystem. The UUID prevents file ID enumeration.
     * Same pattern, every time — boring is good when it comes to crypto.
     *
     * @param array  $file         The $_FILES entry (with 'detected_mime' from validate())
     * @param int    $assessmentId The assessment this file belongs to
     * @param string $fileType     Either 'attachment' or 'certificate'
     * @param int|null $questionId The question ID (null for certificates)
     * @return string The "db:{uuid}" reference path
     * @throws \RuntimeException If the file can't be read, encrypted, or stored
     */
    public function encryptAndStore(
        array $file,
        int $assessmentId,
        string $fileType = 'attachment',
        ?int $questionId = null
    ): string {
        // Read raw bytes so we can encrypt them
        $fileContents = file_get_contents($file['tmp_name']);
        if ($fileContents === false) {
            throw new \RuntimeException('Failed to read uploaded file');
        }

        $encryptedData = $this->encryption->encryptRaw($fileContents);

        // Generate a non-guessable UUID for the file reference
        $fileUuid = bin2hex(random_bytes(16));

        // Store the encrypted file in the database
        $this->db->insert('assessment_files', [
            'file_uuid'      => $fileUuid,
            'assessment_id'  => $assessmentId,
            'file_type'      => $fileType,
            'question_id'    => $questionId,
            'original_filename' => basename(str_replace("\0", '', $file['name'])),
            'mime_type'      => $file['detected_mime'] ?? $file['type'],
            'file_size'      => $file['size'],
            'encrypted_data' => $encryptedData,
        ]);

        return 'db:' . $fileUuid;
    }
}
