<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_workflow.php';
require_once __DIR__ . '/../lib/triage.php';          // SEV_RANK
require_once __DIR__ . '/../lib/override_gate.php';

function fail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'message' => $msg], $extra));
    exit();
}

/** Old rows may hold 'Moderate', so rank across all four stored values. */
function sev4(string $p): int {
    return ['Low' => 1, 'Moderate' => 2, 'High' => 3, 'Critical' => 4][$p] ?? 0;
}

function audit_insert(PDO $pdo, int $incidentId, string $action, int $actorId, string $orig, ?string $proposed,
                      string $final, ?float $conf, ?string $just, ?string $verifiedBy, ?string $evidence,
                      string $verdict, ?string $reason, string $model, int $ms): void {
    $pdo->prepare("INSERT INTO severity_audit_logs
        (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority,
         ai_confidence, justification_text, verified_by, evidence_type, verdict, verdict_reason, model, latency_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$incidentId, $action, $actorId, $orig, $proposed, $final, $conf, $just, $verifiedBy, $evidence,
                   $verdict, $reason === null ? null : mb_substr($reason, 0, 500), $model, $ms]);
}

requireJsonPost();
$actor   = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
$actorId = (int) $actor['id'];
$role = strtoupper((string) $actor['role']);
if (!caseWorkflowCan($role, 'priority')) {
    fail(403, 'Your role is not authorized to change case priority.');
}
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) fail(400, 'Invalid or missing JSON payload.');

$ref           = trim((string) ($input['reference_number'] ?? ''));
$newPriority   = trim((string) ($input['new_priority'] ?? ''));
$justification = trim((string) ($input['overrideJustification'] ?? ''));
$verifiedBy    = trim((string) ($input['overrideVerifiedBy'] ?? ''));
$evidenceType  = strtoupper(trim((string) ($input['overrideEvidenceType'] ?? '')));

if ($ref === '' || !isset(SEV_RANK[$newPriority])) fail(422, 'Invalid reference number or priority.');

try {
    $pdo = getDatabaseConnection();

    // ---- Read the case ----
    $st = $pdo->prepare('SELECT id, priority_level, narrative_description FROM incident_reports WHERE reference_number = ? LIMIT 1');
    $st->execute([$ref]);
    $case = $st->fetch();
} catch (Throwable $e) {
    error_log('[change_severity] read failed: ' . $e->getMessage());
    fail(500, 'Database error.');
}
if (!$case) fail(404, 'Incident record not found.');

$incidentId = (int) $case['id'];
$current    = $case['priority_level'];
if ($current === $newPriority) fail(422, "The case is already {$current}.");

$isRaise = sev4($newPriority) > sev4($current);
$ov      = null;

// ---- Downgrade gate (any network call happens BEFORE the transaction) ----
if (!$isRaise) {
    if ($justification === '' && $verifiedBy === '' && $evidenceType === '') {
        fail(409, "Lowering this case from {$current} to {$newPriority} requires a justification.", [
            'requires_override' => true,
            'required_fields'   => ['overrideJustification', 'overrideVerifiedBy', 'overrideEvidenceType'],
            'evidence_types'    => OVERRIDE_EVIDENCE_TYPES,
        ]);
    }
    $ov = evaluate_override([
        'narrative'     => $case['narrative_description'],
        'original'      => $current,
        'proposed'      => $newPriority,
        'justification' => $justification,
        'verified_by'   => $verifiedBy,
        'evidence_type' => $evidenceType,
    ]);
}

// ---- Single transaction ----
$rejected      = false;
$finalPriority = $current;
$finalStatus   = null;
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT priority_level, status FROM incident_reports WHERE id = ? FOR UPDATE');
    $st->execute([$incidentId]);
    $locked = $st->fetch();

    if (!$locked || $locked['priority_level'] !== $current) {
        $pdo->rollBack();
        fail(409, 'This case was changed by someone else. Reload and try again.');
    }
    $status      = $locked['status'];
    if (in_array($status, ['RESOLVED', 'CLOSED', 'REJECTED'], true)) {
        $pdo->rollBack();
        fail(409, 'This case is resolved or rejected and its severity cannot be changed.');
    }
    $finalStatus = $status;

    if ($isRaise) {
        $finalPriority = $newPriority;
        if ($newPriority === 'Critical' && $status === 'PENDING') $finalStatus = 'CRITICAL';
        $pdo->prepare('UPDATE incident_reports SET priority_level = ?, status = ? WHERE id = ?')
            ->execute([$finalPriority, $finalStatus, $incidentId]);
        audit_insert($pdo, $incidentId, 'SEVERITY_RAISE', $actorId, $current, $newPriority, $finalPriority,
                     null, null, null, null, 'N/A', null, 'manual', 0);

    } elseif ($ov['verdict'] === 'REJECTED') {
        $rejected = true;
        audit_insert($pdo, $incidentId, 'OVERRIDE_REQUEST', $actorId, $current, $newPriority, $current,
                     $ov['confidence'], $justification, $verifiedBy, $evidenceType,
                     'REJECTED', $ov['reason'], $ov['model'], $ov['latency_ms']);

    } elseif ($ov['verdict'] === 'ACCEPTED') {
        $finalPriority = $newPriority;
        if ($status === 'CRITICAL') $finalStatus = 'PENDING';   // no longer a critical-status case
        $pdo->prepare('UPDATE incident_reports SET priority_level = ?, status = ? WHERE id = ?')
            ->execute([$finalPriority, $finalStatus, $incidentId]);
        audit_insert($pdo, $incidentId, 'OVERRIDE_REQUEST', $actorId, $current, $newPriority, $finalPriority,
                     $ov['confidence'], $justification, $verifiedBy, $evidenceType,
                     'ACCEPTED', $ov['reason'], $ov['model'], $ov['latency_ms']);

    } else {   // ESCALATED: the case keeps its current severity until the Captain decides
        $pdo->prepare("UPDATE incident_reports SET severity_review_status = 'PENDING_CAPTAIN' WHERE id = ?")
            ->execute([$incidentId]);
        audit_insert($pdo, $incidentId, 'OVERRIDE_REQUEST', $actorId, $current, $newPriority, $current,
                     $ov['confidence'], $justification, $verifiedBy, $evidenceType,
                     'ESCALATED', $ov['reason'], $ov['model'], $ov['latency_ms']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[change_severity] failed: ' . $e->getMessage());
    fail(500, 'Could not change the severity. Nothing was changed.');
}

if ($rejected) {
    fail(422, 'Justification rejected: ' . $ov['reason'], ['override_rejected' => true]);
}

$pending = ($ov !== null && $ov['verdict'] === 'ESCALATED');
echo json_encode([
    'success' => true,
    'message' => $pending
        ? "Downgrade to {$newPriority} is pending Captain approval. The case stays {$current} until then."
        : "Severity changed to {$finalPriority}.",
    'data' => [
        'reference_number' => $ref,
        'priority'         => $finalPriority,
        'status'           => $finalStatus,
        'override'         => $ov === null ? null : ['verdict' => $ov['verdict'], 'reason' => $ov['reason'], 'pending_captain' => $pending],
    ],
]);