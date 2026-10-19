<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_workflow.php';
require_once __DIR__ . '/../sms_helper.php'; // Required for SMS updates[cite: 16]

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}

requireJsonPost();
$actor = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
$role = strtoupper((string) $actor['role']);
$actorName = (string) ($actor['full_name'] ?? '');
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
$mediator   = trim((string) ($input['mediator'] ?? ''));
$hearing    = trim((string) ($input['schedule'] ?? ''));

$allowedStatuses = ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION', 'RESOLVED', 'CLOSED'];
if ($referenceNumber === '' || !in_array($newStatus, $allowedStatuses, true)) {
    fail(422, 'Invalid reference number or target status.');
}
if (in_array($newStatus, ['RESOLVED', 'CLOSED'], true)) {
    fail(422, 'Use the findings review and resolution workflow to resolve or close a case.');
}
if (mb_strlen($actionNote) > 1000)                       fail(422, 'The note is too long (max 1000 characters).');
if ($inOfficer !== null && mb_strlen($inOfficer) > 120)  fail(422, 'Officer name is too long (max 120 characters).');
if ($inUnit !== null && mb_strlen($inUnit) > 100)        fail(422, 'Unit name is too long (max 100 characters).');
$permissionByStatus = [
    'IN_PROGRESS' => 'triage',
    'DISPATCHED' => 'assign',
    'FOR_RESOLUTION' => 'refer',
    'RESOLVED' => 'resolve',
    'CLOSED' => 'close',
];
if (!caseWorkflowCan($role, $permissionByStatus[$newStatus])) {
    fail(403, 'Your role is not authorized to set this case status.');
}
if (($hasOfficer || $hasUnit) && !caseWorkflowCan($role, 'assign')) {
    fail(403, 'Your role is not authorized to change case assignments.');
}
if (in_array($newStatus, ['FOR_RESOLUTION', 'RESOLVED', 'CLOSED'], true) && $actionNote === '') {
    fail(422, 'A reason is required for this status change.');
}
if ($newStatus === 'FOR_RESOLUTION') {
    $scheduleDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $hearing);
    if ($mediator === '' || !$scheduleDate || $scheduleDate->format('Y-m-d\TH:i') !== $hearing) {
        fail(422, 'A Lupon mediator and valid hearing schedule are required for referral.');
    }
}

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
    if ($newStatus === 'FOR_RESOLUTION') {
        $unit = $mediator;
    }
    if ($newStatus === 'DISPATCHED' && ($unit === null || $unit === '') && ($officer === null || $officer === '')) {
        $pdo->rollBack();
        fail(422, 'A responder or unit must be assigned before dispatch.');
    }
    if ($newStatus === 'IN_PROGRESS' && $oldStatus === 'CLOSED') {
        if (!caseWorkflowCan($role, 'reopen') || $actionNote === '') {
            $pdo->rollBack();
            fail(403, 'Reopening a closed case requires an authorized role and a reason.');
        }
    } elseif (!caseWorkflowAllowsTransition((string) $oldStatus, $newStatus)) {
        $pdo->rollBack();
        fail(409, 'That status transition is not allowed for this case.');
    }

    $pdo->prepare('UPDATE incident_reports SET status = ?, assigned_officer_name = ?, deployed_unit = ? WHERE id = ?')
        ->execute([$newStatus, $officer, $unit, $incidentId]);

    $note = ($actionNote !== '') ? $actionNote : "Status transitioned from {$oldStatus} to {$newStatus}.";
    $pdo->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$incidentId, $referenceNumber, $newStatus, $actorName, $unit, $note]);
    $milestoneId = (int) $pdo->lastInsertId();

    if ($newStatus === 'FOR_RESOLUTION') {
        $nextHearing = $scheduleDate->format('Y-m-d H:i:s');
        $lupon = $pdo->prepare('SELECT id FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $lupon->execute([$incidentId]);
        if ($lupon->fetchColumn()) {
            $pdo->prepare("UPDATE lupon_cases SET stage = 'MEDIATION', next_hearing = ?, status = 'ONGOING' WHERE case_id = ?")
                ->execute([$nextHearing, $incidentId]);
        } else {
            $pdo->prepare("INSERT INTO lupon_cases (case_id, stage, next_hearing, status) VALUES (?, 'MEDIATION', ?, 'ONGOING')")
                ->execute([$incidentId, $nextHearing]);
        }
    } elseif ($newStatus === 'RESOLVED') {
        $lupon = $pdo->prepare('SELECT id FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $lupon->execute([$incidentId]);
        if (!$lupon->fetchColumn()) {
            $pdo->rollBack();
            fail(409, 'Only a case with an existing Lupon referral can be resolved this way.');
        }
        $pdo->prepare("UPDATE lupon_cases SET stage = 'RESOLVED', next_hearing = NULL, status = 'RESOLVED' WHERE case_id = ?")
            ->execute([$incidentId]);
    }

    $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
        ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId, 'old_status' => $oldStatus, 'new_status' => $newStatus])]);

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[update_case_status] failed: ' . $e->getMessage());
    fail(500, 'Failed to update incident status. Nothing was changed.');
}

$phone = (string) $case['complainant_phone'];
$name = (string) $case['complainant_name'];
$officerText = $officer !== null && $officer !== '' ? " Handled by: {$officer}." : '';
$smsMsg = "BalangayLog Update: Hi {$name}, your incident ({$referenceNumber}) is now '{$newStatus}'.{$officerText}";
if ($actionNote !== '') {
    $smsMsg .= " Remarks: {$actionNote}";
}
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
if (strpos($cleanPhone, '0') === 0) $cleanPhone = '63' . substr($cleanPhone, 1);
$smsResponse = sendIprogSms($cleanPhone, $smsMsg);
error_log("[SMS] Tracking update for {$referenceNumber}: " . $smsResponse);

echo json_encode([
    'success'    => true,
    'message'    => "Case status updated to {$newStatus}.",
    'old_status' => $oldStatus,
    'new_status' => $newStatus,
    'assigned_officer_name' => $officer,
    'deployed_unit' => $unit,
]);
?>