<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../sms_helper.php'; // Required for SMS updates[cite: 16]

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}

requireJsonPost();
requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) fail(400, 'Invalid JSON payload.');

$referenceNumber = trim((string) ($input['reference_number'] ?? ''));
$newStatus       = trim((string) ($input['new_status'] ?? ''));
$actionNote      = trim((string) ($input['action_note'] ?? ''));
$hasOfficer = array_key_exists('assigned_officer_name', $input);
$hasUnit    = array_key_exists('deployed_unit', $input);
$inOfficer  = ($hasOfficer && $input['assigned_officer_name'] !== null) ? trim((string) $input['assigned_officer_name']) : null;
$inUnit     = ($hasUnit && $input['deployed_unit'] !== null) ? trim((string) $input['deployed_unit']) : null;

$allowedStatuses = ['PENDING', 'IN_PROGRESS', 'FOR_RESOLUTION', 'RESOLVED', 'CRITICAL'];
if ($referenceNumber === '' || !in_array($newStatus, $allowedStatuses, true)) {
    fail(422, 'Invalid reference number or target status.');
}
if (mb_strlen($actionNote) > 1000)                       fail(422, 'The note is too long (max 1000 characters).');
if ($inOfficer !== null && mb_strlen($inOfficer) > 120)  fail(422, 'Officer name is too long (max 120 characters).');
if ($inUnit !== null && mb_strlen($inUnit) > 100)        fail(422, 'Unit name is too long (max 100 characters).');

$pdo = getDatabaseConnection();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, status, assigned_officer_name, deployed_unit, complainant_name, complainant_phone
                         FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
    $st->execute([$referenceNumber]);
    $case = $st->fetch();

    if (!$case) {
        $pdo->rollBack();
        fail(404, 'Incident record not found.');
    }

    $incidentId = (int) $case['id'];
    $oldStatus  = $case['status'];
    $officer    = $hasOfficer ? $inOfficer : $case['assigned_officer_name'];
    $unit       = $hasUnit ? $inUnit : $case['deployed_unit'];

    $pdo->prepare('UPDATE incident_reports SET status = ?, assigned_officer_name = ?, deployed_unit = ? WHERE id = ?')
        ->execute([$newStatus, $officer, $unit, $incidentId]);

    $note = ($actionNote !== '') ? $actionNote : "Status transitioned from {$oldStatus} to {$newStatus}.";
    $pdo->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$incidentId, $referenceNumber, $newStatus, $officer, $unit, $note]);
    $milestoneId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
        ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId, 'old_status' => $oldStatus, 'new_status' => $newStatus])]);

    $pdo->commit();

    // Trigger iProg SMS to complainant featuring officer transparency details
    $phone = $case['complainant_phone'];
    $name = $case['complainant_name'];
    
    // Explicitly incorporating the officer who took/handled the case for full transparency
    $officerText = !empty($officer) ? " Handled by: {$officer}." : "";
    $smsMsg = "BalangayLog Update: Hi {$name}, your incident ({$referenceNumber}) is now '{$newStatus}'.{$officerText}";
    if ($actionNote !== '') {
        $smsMsg .= " Remarks: {$actionNote}";
    }
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($clean_phone, '0') === 0) $clean_phone = '63' . substr($clean_phone, 1);
    
    $smsResponse = sendIprogSms($clean_phone, $smsMsg);
    error_log("[SMS] Tracking update for {$referenceNumber}: " . $smsResponse);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[update_case_status] failed: ' . $e->getMessage());
    fail(500, 'Failed to update incident status. Nothing was changed.');
}

// Trigger iProg SMS for Captain Endorsement / Signature Approval
    $phone = $case['complainant_phone'];
    $name = $case['complainant_name'];
    $smsMsg = "BalangayLog Executive Update: Hi {$name}, your incident ({$referenceNumber}) has been officially endorsed and signed by Punong Barangay Capt. Renato Lim.";
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($clean_phone, '0') === 0) $clean_phone = '63' . substr($clean_phone, 1);
    
    sendIprogSms($clean_phone, $smsMsg);

echo json_encode([
    'success'    => true,
    'message'    => "Case status updated to {$newStatus}.",
    'old_status' => $oldStatus,
    'new_status' => $newStatus,
    'assigned_officer_name' => $officer,
    'deployed_unit' => $unit,
]);
?>