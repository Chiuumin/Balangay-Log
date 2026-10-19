<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=UTF-8');
// No CORS headers on purpose: this endpoint is same-origin only.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_workflow.php';

requireJsonPost();
$actor     = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
$actorName = (string) ($actor['full_name'] ?? '');
$role = strtoupper((string) $actor['role']);
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) jsonError(400, 'Invalid or missing JSON payload.');

$ref        = trim((string) ($input['reference_number'] ?? ''));
$actionType = strtoupper(trim((string) ($input['action_type'] ?? 'DISPATCH')));

if ($ref === '') jsonError(422, 'Reference number is required.');

$actions = [
    'DISPATCH' => ['permission' => 'assign', 'status' => 'DISPATCHED', 'note' => 'Case dispatched to responding personnel.'],
    'LUPON'    => ['permission' => 'refer', 'status' => 'FOR_RESOLUTION', 'note' => 'Referred to Katarungang Pambarangay (Lupon) mediation.'],
];
if (!isset($actions[$actionType])) jsonError(422, 'Unknown action type.');
if (!caseWorkflowCan($role, $actions[$actionType]['permission'])) {
    jsonError(403, 'Your role is not authorized to perform this case action.');
}
$reason = trim((string) ($input['reason'] ?? ''));
$mediator = trim((string) ($input['mediator'] ?? ''));
$schedule = trim((string) ($input['schedule'] ?? ''));
$unit = trim((string) ($input['deployed_unit'] ?? ''));
if ($actionType === 'LUPON') {
    $hearing = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $schedule);
    if ($reason === '' || $mediator === '' || !$hearing || $hearing->format('Y-m-d\TH:i') !== $schedule) {
        jsonError(422, 'A referral reason, mediator, and valid Lupon hearing time are required.');
    }
} elseif ($unit === '') {
    jsonError(422, 'A responding unit is required for dispatch.');
}
$newStatus = $actions[$actionType]['status'];
$note      = $actionType === 'LUPON'
    ? $actions[$actionType]['note'] . ' Reason: ' . $reason
    : $actions[$actionType]['note'];

$pdo = getDatabaseConnection();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
    $st->execute([$ref]);
    $case = $st->fetch();

    if (!$case) {
        $pdo->rollBack();
        jsonError(404, 'Case not found.');
    }
    $incidentId = (int) $case['id'];
    $oldStatus  = (string) $case['status'];

    if (!caseWorkflowAllowsTransition($oldStatus, $newStatus)) {
        $pdo->rollBack();
        jsonError(409, 'That status transition is not allowed for this case.');
    }

    if ($actionType === 'DISPATCH') {
        $pdo->prepare('UPDATE incident_reports SET status = ?, deployed_unit = ? WHERE id = ?')->execute([$newStatus, $unit, $incidentId]);
        $note .= " Unit assigned: {$unit}.";
    } else {
        $pdo->prepare('UPDATE incident_reports SET status = ?, deployed_unit = ? WHERE id = ?')->execute([$newStatus, $mediator, $incidentId]);
    }

    $pdo->prepare('INSERT INTO case_milestones
            (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$incidentId, $ref, $newStatus, $actorName, $actionType === 'DISPATCH' ? $unit : $mediator, $note]);
    $milestoneId = (int) $pdo->lastInsertId();
    if ($actionType === 'LUPON') {
        $lupon = $pdo->prepare('SELECT id FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $lupon->execute([$incidentId]);
        $hearingAt = $hearing->format('Y-m-d H:i:s');
        if ($lupon->fetchColumn()) {
            $pdo->prepare("UPDATE lupon_cases SET stage = 'MEDIATION', next_hearing = ?, status = 'ONGOING' WHERE case_id = ?")
                ->execute([$hearingAt, $incidentId]);
        } else {
            $pdo->prepare("INSERT INTO lupon_cases (case_id, stage, next_hearing, status) VALUES (?, 'MEDIATION', ?, 'ONGOING')")
                ->execute([$incidentId, $hearingAt]);
        }
    }
    $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
        ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId, 'old_status' => $oldStatus, 'new_status' => $newStatus])]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[execute_action] failed: ' . $e->getMessage());
    jsonError(500, 'Could not execute the action. Nothing was changed.');
}

echo json_encode([
    'success'          => true,
    'message'          => 'Action executed successfully.',
    'new_status'       => $newStatus,
    'reference_number' => $ref,
    'timestamp'        => date('g:i A'),
]);