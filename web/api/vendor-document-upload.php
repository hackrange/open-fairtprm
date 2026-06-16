<?php
/**
 * Vendor Document Upload API
 *
 * Handles file uploads for vendor onboarding documents (contracts, certifications,
 * other documents). Files are validated, encrypted with AES-256, and stored as
 * BLOBs in the vendor_documents table. Only admin, procurement, and cyber_tprm
 * users can upload documents.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

// Method enforcement
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Auth check
requireAuth();
$auth = Auth::getInstance();
$session = Session::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');

if (!$isAdmin && !$isProcurement && !$isCyberTPRM) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// CSRF check
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Required fields
$vendorRequestId = intval($_POST['vendor_request_id'] ?? 0);
$documentType = $_POST['document_type'] ?? '';

if (!$vendorRequestId) {
    echo json_encode(['success' => false, 'message' => 'Missing vendor request ID']);
    exit;
}

if (!in_array($documentType, ['contract', 'certification', 'other'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid document type']);
    exit;
}

$db = Database::getInstance();

// Verify the vendor request exists
$vendorRequest = $db->fetchOne(
    "SELECT id FROM vendor_onboarding_requests WHERE id = ?",
    [$vendorRequestId]
);

if (!$vendorRequest) {
    echo json_encode(['success' => false, 'message' => 'Vendor request not found']);
    exit;
}

// Validate type-specific fields
$contractName = null;
$contractType = null;
$contractCreationDate = null;
$contractExpirationDate = null;
$contractPricing = null;
$certificationType = null;
$certificationExpirationDate = null;
$description = null;

$allowedContractTypes = ['NDA', 'DPA', 'Master Service Agreement', 'Privacy', 'Order Form', 'PO'];
$allowedCertTypes = [
    'SOC 2 Type II', 'ISO/IEC 27001', 'PCI DSS', 'HITRUST CSF',
    'NIST SP 800-171', 'NIST SP 800-53', 'NIST CSF', 'CMMC', 'FedRAMP',
    'SOC 2 Type I', 'SOC 1 Type II', 'ISO/IEC 27701', 'ISO/IEC 27017',
    'ISO/IEC 27018', 'HIPAA Attestation', 'CSA STAR', 'CAIQ', 'ISO 22301',
    'StateRAMP', 'Cyber Essentials', 'TISAX', 'Privacy Certification', 'Other'
];

if ($documentType === 'contract') {
    $contractName = trim($_POST['contract_name'] ?? '');
    $contractType = trim($_POST['contract_type'] ?? '');
    $contractCreationDate = trim($_POST['contract_creation_date'] ?? '');
    $contractExpirationDate = trim($_POST['contract_expiration_date'] ?? '');

    if (empty($contractName)) {
        echo json_encode(['success' => false, 'message' => 'Contract name is required']);
        exit;
    }
    if (!in_array($contractType, $allowedContractTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid contract type']);
        exit;
    }
    if (empty($contractCreationDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contractCreationDate)) {
        echo json_encode(['success' => false, 'message' => 'Valid creation date is required']);
        exit;
    }
    // Expiration date required only for Master Service Agreement and PO
    if (in_array($contractType, ['Master Service Agreement', 'PO'], true)) {
        if (empty($contractExpirationDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contractExpirationDate)) {
            echo json_encode(['success' => false, 'message' => 'Valid expiration date is required for this contract type']);
            exit;
        }
    } elseif (!empty($contractExpirationDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contractExpirationDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid expiration date format']);
        exit;
    }

    $contractName = $security->cleanInput($contractName);

    // Parse contract pricing for Order Form / PO
    if (in_array($contractType, ['Order Form', 'PO'], true) && !empty($_POST['contract_pricing'])) {
        $rawPricing = json_decode($_POST['contract_pricing'], true);
        if (is_array($rawPricing)) {
            $cleanPricing = [];
            $textFields = ['contractId', 'renewalNoticeWindow', 'terminationRights'];
            $selectFields = ['renewalType' => ['Auto-Renew', 'Manual', 'Evergreen'],
                             'billingFrequency' => ['Monthly', 'Quarterly', 'Annually'],
                             'renewalUplift' => ['Y', 'N']];
            $numericFields = ['annualFee', 'unitPrice', 'overagePrice', 'oneTimeFees', 'priceIncreaseCap'];

            foreach ($textFields as $f) {
                if (!empty($rawPricing[$f])) {
                    $cleanPricing[$f] = $security->cleanInput(trim($rawPricing[$f]));
                }
            }
            if (!empty($rawPricing['systemOfRecordLink']) && filter_var($rawPricing['systemOfRecordLink'], FILTER_VALIDATE_URL)) {
                $cleanPricing['systemOfRecordLink'] = $rawPricing['systemOfRecordLink'];
            }
            foreach ($selectFields as $f => $allowed) {
                if (!empty($rawPricing[$f]) && in_array($rawPricing[$f], $allowed, true)) {
                    $cleanPricing[$f] = $rawPricing[$f];
                }
            }
            foreach ($numericFields as $f) {
                if (isset($rawPricing[$f]) && $rawPricing[$f] !== '' && is_numeric($rawPricing[$f]) && floatval($rawPricing[$f]) >= 0) {
                    $cleanPricing[$f] = floatval($rawPricing[$f]);
                }
            }
            if (isset($rawPricing['includedUnits']) && $rawPricing['includedUnits'] !== '' && is_numeric($rawPricing['includedUnits']) && intval($rawPricing['includedUnits']) >= 0) {
                $cleanPricing['includedUnits'] = intval($rawPricing['includedUnits']);
            }
            if (!empty($cleanPricing)) {
                $contractPricing = json_encode($cleanPricing);
            }
        }
    }
} elseif ($documentType === 'certification') {
    $certificationType = trim($_POST['certification_type'] ?? '');
    if (!in_array($certificationType, $allowedCertTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid certification type']);
        exit;
    }
    $certificationExpirationDate = trim($_POST['certification_expiration_date'] ?? '');
    if (empty($certificationExpirationDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $certificationExpirationDate)) {
        echo json_encode(['success' => false, 'message' => 'Valid certification expiration date is required']);
        exit;
    }
} elseif ($documentType === 'other') {
    $description = trim($_POST['description'] ?? '');
    if (!empty($description)) {
        $description = $security->cleanInput($description);
    }
}

// Validate the uploaded file
$uploadService = new FileUploadService();
$result = $uploadService->validate('file');

if ($result['error']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

try {
    $file = $result['file'];

    // Read and encrypt file contents
    $fileContents = file_get_contents($file['tmp_name']);
    if ($fileContents === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to read uploaded file']);
        exit;
    }

    $encryption = new Encryption();
    $encryptedData = $encryption->encryptRaw($fileContents);

    // Generate UUID
    $fileUuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $user = $auth->getUser();
    $safeFilename = $security->cleanInput(basename($file['name']));

    $docId = $db->insert('vendor_documents', [
        'file_uuid' => $fileUuid,
        'vendor_request_id' => $vendorRequestId,
        'document_type' => $documentType,
        'contract_name' => $contractName,
        'contract_type' => $contractType,
        'contract_creation_date' => $contractCreationDate ?: null,
        'contract_expiration_date' => $contractExpirationDate ?: null,
        'certification_type' => $certificationType,
        'certification_expiration_date' => $certificationExpirationDate ?: null,
        'contract_pricing' => $contractPricing,
        'is_active' => 1,
        'description' => $description,
        'original_filename' => $safeFilename,
        'mime_type' => $file['type'],
        'file_size' => $file['size'],
        'encrypted_data' => $encryptedData,
        'uploaded_by' => $user['id'],
    ]);

    $auth->audit($user['id'], 'document_upload', 'vendor_documents', $docId, [
        'new' => ['filename' => $safeFilename, 'document_type' => $documentType, 'vendor_request_id' => $vendorRequestId]
    ]);

    echo json_encode([
        'success' => true,
        'document_id' => $docId,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor document upload error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save document']);
}
