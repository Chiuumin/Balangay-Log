<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=UTF-8');
// No CORS headers on purpose: this endpoint is same-origin only.

require_once __DIR__ . '/../auth.php';

requireJsonPost();
$actor     = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
$actorName = (string) ($actor['full_name'] ?? '');
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) jsonError(400, 'Invalid or missing JSON payload.');

$ref        = trim((string) ($input['reference_number'] ?? ''));
$actionType = strtoupper(trim((string) ($input['action_type'] ?? 'DISPATCH')));

if ($ref === '') jsonError(422, 'Reference number is required.');

// Same two outcomes as before: DISPATCH -> IN_PROGRESS, LUPON -> FOR_RESOLUTION.
$actions = [
    'DISPATCH' => ['status' => 'IN_PROGRESS',   'note' => 'Response protocol executed: case moved to active response.'],
    'LUPON'    => ['status' => 'FOR_RESOLUTION', 'note' => 'Referred to Katarungang Pambarangay (Lupon) mediation.'],
];
if (!isset($actions[$actionType])) jsonError(422, 'Unknown action type.');
$newStatus = $actions[$actionType]['status'];
$note      = $actions[$actionType]['note'];

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

    if (in_array($oldStatus, ['RESOLVED', 'CLOSED'], true)) {
        $pdo->rollBack();
        jsonError(409, 'This case is already closed.');
    }
    if ($oldStatus === $newStatus) {
        $pdo->rollBack();
        jsonError(409, 'Status was already updated.');
    }

    $pdo->prepare('UPDATE incident_reports SET status = ? WHERE id = ?')->execute([$newStatus, $incidentId]);

    $pdo->prepare('INSERT INTO case_milestones
            (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$incidentId, $ref, $newStatus, $actorName, $note]);
    $milestoneId = (int) $pdo->lastInsertId();

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