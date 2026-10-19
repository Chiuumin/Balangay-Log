<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_workflow.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

$actor = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
if (!caseWorkflowCan((string) $actor['role'], 'assign')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your role is not authorized to manage responder assignments.']);
    exit();
}
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
$tanodId = (int) ($input['tanod_id'] ?? 0);
$tanodName = trim((string) ($input['tanod_name'] ?? ''));
$newStatus = strtoupper(trim((string) ($input['status'] ?? ''))); 

$allowedStatuses = ['AVAILABLE', 'OFF_DUTY'];

if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "Invalid status value: {$newStatus}"]);
    exit();
}

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    $selector = $tanodId > 0 ? 'id = ?' : 'TRIM(full_name) = TRIM(?)';
    $selectorValue = $tanodId > 0 ? $tanodId : $tanodName;
    if ($tanodId < 1 && $tanodName === '') {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a responder to update.']);
        exit();
    }
    $st = $pdo->prepare("SELECT id, status FROM tanod_personnel WHERE {$selector} LIMIT 1 FOR UPDATE");
    $st->execute([$selectorValue]);
    $responder = $st->fetch(PDO::FETCH_ASSOC);
    if (!$responder) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tanod responder not found.']);
        exit();
    }
    if ($responder['status'] === 'DEPLOYED') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Complete the active dispatch before changing availability.']);
        exit();
    }
    $pdo->prepare('UPDATE tanod_personnel SET status = ? WHERE id = ?')
        ->execute([$newStatus, (int) $responder['id']]);
    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Responder status updated successfully.'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[update_tanod_status] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}