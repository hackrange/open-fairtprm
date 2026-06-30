<?php
/**
 * Procurement Note Manage API (edit / delete)
 *
 * Edits the text of, or deletes, a single procurement "status note"
 * (one vendor_procurement_updates row) shown on procurement-cyber-status.php.
 *
 * Authorization is a PER-OBJECT check, not a flat role gate: a note may be
 * edited or deleted by an administrator, any cyber_tprm member, OR the user who
 * authored it (created_by). Read/list access to the page is NOT sufficient --
 * a non-privileged viewer is confined to notes they wrote themselves. The note
 * id is server-resolved and re-validated here (never trust the client's claim
 * about who owns a note).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAuth();
$auth = Auth::getInstance();
$session = Session::getInstance();
$db = Database::getInstance();
$user = $auth->getUser();

// Page-level gate mirrors procurement-cyber-status.php (admin/procurement/cyber_tprm).
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
if (!$isAdmin && !hasGroup('procurement') && !hasGroup('cyber_tprm')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Validate without rotating (stable per-session token): the page may issue
// several edit/delete calls per load and shares the token with the existing
// "Provide Update" modal; rotating here would 403 the other in-flight requests.
if (!$security->validateCSRFToken($input['csrf_token'] ?? '', false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

$noteId = intval($input['note_id'] ?? 0);
$action = trim((string)($input['action'] ?? ''));
if ($noteId <= 0 || !in_array($action, ['edit', 'delete'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

// Load the note and enforce per-object authorization.
$note = $db->fetchOne(
    'SELECT id, request_id, update_text, created_by FROM vendor_procurement_updates WHERE id = :id',
    [':id' => $noteId]
);
if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Note not found.']);
    exit;
}

$isAuthor = ($note['created_by'] !== null && (int)$note['created_by'] === (int)$user['id']);
$canManage = $isAdmin || hasGroup('cyber_tprm') || $isAuthor;
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You may only edit or delete notes you authored.']);
    exit;
}

try {
    if ($action === 'delete') {
        $db->query('DELETE FROM vendor_procurement_updates WHERE id = :id', [':id' => $noteId]);
        $auth->audit($user['id'], 'procurement_update_deleted', 'vendor_onboarding_requests', (int)$note['request_id'], [
            'note_id'     => $noteId,
            'update_text' => $note['update_text'],
        ]);
        echo json_encode(['success' => true, 'action' => 'delete', 'note_id' => $noteId]);
        exit;
    }

    // action === 'edit'
    $newText = trim((string)($input['update_text'] ?? ''));
    if ($newText === '') {
        echo json_encode(['success' => false, 'error' => 'Note text is required.']);
        exit;
    }
    if (mb_strlen($newText) > 10000) {
        echo json_encode(['success' => false, 'error' => 'Note is too long (10000 character limit).']);
        exit;
    }
    if ($newText === (string)$note['update_text']) {
        // Nothing changed -- report success without a pointless write/audit row.
        echo json_encode(['success' => true, 'action' => 'edit', 'note_id' => $noteId, 'text' => $newText, 'unchanged' => true]);
        exit;
    }

    $db->query(
        'UPDATE vendor_procurement_updates SET update_text = :txt, edited_at = NOW(), edited_by = :uid WHERE id = :id',
        [':txt' => $newText, ':uid' => $user['id'], ':id' => $noteId]
    );
    $auth->audit($user['id'], 'procurement_update_edited', 'vendor_onboarding_requests', (int)$note['request_id'], [
        'note_id' => $noteId,
        'old'     => ['update_text' => $note['update_text']],
        'new'     => ['update_text' => $newText],
    ]);

    echo json_encode([
        'success'   => true,
        'action'    => 'edit',
        'note_id'   => $noteId,
        'text'      => $newText,
        'edited_at' => date('M j, Y g:i A'),
    ]);
} catch (Exception $e) {
    error_log('procurement-note-manage error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Operation failed. Please try again.']);
}
