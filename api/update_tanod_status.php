<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
$tanodId = (int) ($input['tanod_id'] ?? 0);
$tanodName = trim((string) ($input['tanod_name'] ?? ''));
$newStatus = strtoupper(trim((string) ($input['status'] ?? ''))); 

$allowedStatuses = ['AVAILABLE', 'OFF_DUTY', 'DEPLOYED'];

if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "Invalid status value: {$newStatus}"]);
    exit();
}

try {
    $pdo = getDatabaseConnection();
    $updated = false;

    if ($tanodId > 0) {
        $st = $pdo->prepare('UPDATE tanod_personnel SET status = ? WHERE id = ?');
        $st->execute([$newStatus, $tanodId]);
        if ($st->rowCount() > 0) $updated = true;
    }

    if (!$updated && $tanodName !== '') {
        $st = $pdo->prepare('UPDATE tanod_personnel SET status = ? WHERE TRIM(full_name) = TRIM(?)');
        $st->execute([$newStatus, $tanodName]);
        if ($st->rowCount() > 0) $updated = true;
    }

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Tanod responder not found."]);
        exit();
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Responder status updated successfully.'
    ]);
} catch (Throwable $e) {
    error_log('[update_tanod_status] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}