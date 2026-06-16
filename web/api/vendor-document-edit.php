<?php
/**
 * Vendor Document Edit Metadata API
 *
 * Allows admin and cyber_tprm users to update document metadata
 * such as contract name, type, dates, certification info, etc.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireAuth();
$auth = Auth::getInstance();
$session = Session::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$documentId = intval($_POST['document_id'] ?? 0);

if (!$documentId) {
    echo json_encode(['success' => false, 'message' => 'Missing document ID']);
    exit;
}

$db = Database::getInstance();

try {
    $doc = $db->fetchOne(
        "SELECT id, document_type FROM vendor_documents WHERE id = ?",
        [$documentId]
    );

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    // Procurement users can only edit contract-type documents
    if ($isProcurement && !$isAdmin && !$isCyberTPRM && $doc['document_type'] !== 'contract') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Procurement can only edit contract documents.']);
        exit;
    }

    $updates = [];
    $documentType = trim($_POST['document_type'] ?? $doc['document_type']);

    // Validate document type
    if (!in_array($documentType, ['contract', 'certification', 'other'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid document type']);
        exit;
    }
    $updates['document_type'] = $documentType;

    // Active status
    if (isset($_POST['is_active'])) {
        $updates['is_active'] = intval($_POST['is_active']) ? 1 : 0;
    }

    // Contract fields
    if ($documentType === 'contract') {
        $updates['contract_name'] = trim($_POST['contract_name'] ?? '') ?: null;
        $contractType = trim($_POST['contract_type'] ?? '') ?: null;
        $updates['contract_type'] = $contractType;
        $updates['contract_creation_date'] = !empty($_POST['contract_creation_date']) ? $_POST['contract_creation_date'] : null;
        $updates['contract_expiration_date'] = !empty($_POST['contract_expiration_date']) ? $_POST['contract_expiration_date'] : null;

        // Contract pricing for Order Form / PO
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
                $updates['contract_pricing'] = !empty($cleanPricing) ? json_encode($cleanPricing) : null;
            } else {
                $updates['contract_pricing'] = null;
            }
        } else {
            // Clear pricing when not Order Form / PO, or when empty
            $updates['contract_pricing'] = null;
        }

        // Clear certification fields
        $updates['certification_type'] = null;
        $updates['certification_expiration_date'] = null;
        $updates['description'] = null;
    } elseif ($documentType === 'certification') {
        $updates['certification_type'] = trim($_POST['certification_type'] ?? '') ?: null;
        $updates['certification_expiration_date'] = !empty($_POST['certification_expiration_date']) ? $_POST['certification_expiration_date'] : null;
        // Clear contract fields
        $updates['contract_name'] = null;
        $updates['contract_type'] = null;
        $updates['contract_creation_date'] = null;
        $updates['contract_expiration_date'] = null;
        $updates['contract_pricing'] = null;
        $updates['description'] = null;
    } else {
        $updates['description'] = trim($_POST['description'] ?? '') ?: null;
        // Clear contract and certification fields
        $updates['contract_name'] = null;
        $updates['contract_type'] = null;
        $updates['contract_creation_date'] = null;
        $updates['contract_expiration_date'] = null;
        $updates['contract_pricing'] = null;
        $updates['certification_type'] = null;
        $updates['certification_expiration_date'] = null;
    }

    // Capture old values for audit log
    $oldDoc = $db->fetchOne(
        "SELECT document_type, contract_name, contract_type, contract_creation_date, contract_expiration_date, contract_pricing, certification_type, certification_expiration_date, description, is_active FROM vendor_documents WHERE id = ?",
        [$documentId]
    );

    $db->update('vendor_documents', $updates, 'id = :doc_id', [':doc_id' => $documentId]);

    $user = $auth->getUser();
    $auth->audit($user['id'], 'document_edit', 'vendor_documents', $documentId, [
        'old' => $oldDoc,
        'new' => $updates
    ]);

    echo json_encode([
        'success' => true,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor document edit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update document metadata']);
}
