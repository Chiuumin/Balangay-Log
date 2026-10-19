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
$actorName = mb_substr((string) ($actor['full_name'] ?? ''), 0, 120);   // the author is the logged-in user
$role = strtoupper((string) $actor['role']);
if (!caseWorkflowCan($role, 'findings')) {
    jsonError(403, 'Your role is not authorized to record case findings.');
}
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) jsonError(400, 'Invalid or missing JSON payload.');
// Any officer_in_charge sent by the browser is ignored on purpose.

$referenceNumber = trim((string) ($input['reference_number'] ?? ''));
$actionNote      = trim((string) ($input['action_note'] ?? ''));

if ($referenceNumber === '' || $actionNote === '') {
    jsonError(422, 'Case reference and note are required.');
}
if (mb_strlen($actionNote) > 1000) {
    jsonError(422, 'The note is too long (max 1000 characters).');
}

$pdo = getDatabaseConnection();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, status, deployed_unit FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
    $st->execute([$referenceNumber]);
    $case = $st->fetch();

    if (!$case) {
        $pdo->rollBack();
        jsonError(404, 'Incident record not found.');
    }
    $incidentId = (int) $case['id'];
    $status     = (string) $case['status'];
    if (in_array($status, ['RESOLVED', 'CLOSED'], true)) {
        $pdo->rollBack();
        jsonError(409, 'Resolved or closed cases cannot receive new findings.');
    }
    if (stripos($actionNote, 'Field findings:') === 0 && $status !== 'DISPATCHED') {
        $pdo->rollBack();
        jsonError(409, 'Field findings can only be recorded after the case is dispatched.');
    }

    $pdo->prepare('INSERT INTO case_milestones
            (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$incidentId, $referenceNumber, $status, $actorName, $case['deployed_unit'], $actionNote]);
    $milestoneId = (int) $pdo->lastInsertId();

    // A note changes no status, so old and new status are equal. Agent 2 reads the milestone by id.
    $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
        ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId, 'old_status' => $status, 'new_status' => $status, 'note_only' => true])]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[add_case_note] failed: ' . $e->getMessage());
    jsonError(500, 'Unable to add note to the case timeline.');
}

echo json_encode(['success' => true, 'message' => 'Note added to the case timeline.']);