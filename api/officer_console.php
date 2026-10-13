<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_intake.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function officerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * One status change = one transaction: case update + milestone + outbox event.
 * $t keys: status, milestone_status, officer, note
 *          optional: unit (sets deployed_unit), recommendation (sets ai_recommendation), milestone_unit
 */
function officerTransition(PDO $pdo, string $ref, array $t): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
        $st->execute([$ref]);
        $case = $st->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            $pdo->rollBack();
            officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
        }
        $incidentId = (int) $case['id'];
        $oldStatus  = (string) $case['status'];

        $sets   = ['status = ?'];
        $params = [$t['status']];
        if (array_key_exists('unit', $t)) {
            $sets[] = 'deployed_unit = ?';
            $params[] = $t['unit'];
        }
        if (array_key_exists('recommendation', $t)) {
            $sets[] = 'ai_recommendation = ?';
            $params[] = $t['recommendation'];
        }
        $params[] = $incidentId;
        $pdo->prepare('UPDATE incident_reports SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $pdo->prepare('INSERT INTO case_milestones
                (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$incidentId, $ref, $t['milestone_status'], $t['officer'], $t['milestone_unit'] ?? null, $t['note']]);
        $milestoneId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
            ->execute([$incidentId, json_encode([
                'milestone_id' => $milestoneId,
                'old_status'   => $oldStatus,
                'new_status'   => $t['status'],
            ])]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Role is verified against the database, not just the session.
$actor     = requireApiRole(['OFFICER', 'CAPTAIN']);
$actorId   = (int) $actor['id'];
$actorName = (string) ($actor['full_name'] ?? '');
session_write_close();   // release the session lock; $_SESSION stays readable below

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // 1. Metric Counts (Safe: uses created_at)
        $pendingTriageCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status IN ('PENDING', 'UNDER_REVIEW')")->fetchColumn();
        $activeDispatchedCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'DISPATCHED' OR (deployed_unit IS NOT NULL AND status NOT IN ('RESOLVED', 'CLOSED'))")->fetchColumn();
        $underInvestigationCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'IN_PROGRESS'")->fetchColumn();
        $resolvedTodayCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'RESOLVED' AND DATE(created_at) = CURDATE()")->fetchColumn();

        // 2. Critical Alert Banner
        $criticalStmt = $pdo->query("
            SELECT reference_number, complainant_name, incident_type, purok, narrative_description 
            FROM incident_reports 
            WHERE (priority_level = 'Critical' OR status = 'CRITICAL') 
              AND status <> 'RESOLVED' 
              AND (deployed_unit IS NULL OR deployed_unit = '') 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $criticalCase = $criticalStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 3. Triage Queue (Pending)
        $triageStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, narrative_description, purok, priority_level, status, ai_urgency_score, created_at 
            FROM incident_reports 
            WHERE status IN ('PENDING', 'UNDER_REVIEW') 
            ORDER BY created_at DESC 
            LIMIT 30
        ");
        $triageQueue = $triageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Urgent / Emergency Cases (Critical + High priority)
        $urgentStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, narrative_description, purok, priority_level, status, deployed_unit, created_at 
            FROM incident_reports 
            WHERE priority_level IN ('Critical', 'High') AND status NOT IN ('RESOLVED', 'CLOSED') 
            ORDER BY (priority_level = 'Critical') DESC, created_at DESC
        ");
        $urgentCases = $urgentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 5. Lupon / Mediation Cases (Maps deployed_unit to assigned_officer)
        $luponStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, incident_type, narrative_description, purok, status, deployed_unit AS assigned_officer, created_at 
            FROM incident_reports 
            WHERE status = 'FOR_RESOLUTION' OR incident_type LIKE '%Dispute%' OR incident_type LIKE '%Altercation%'
            ORDER BY created_at DESC
        ");
        $luponCases = $luponStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 6. Tanod Responders Roster
        $tanods = [
            ['id' => 1, 'name' => 'Ramon Diaz', 'station' => 'Barangay Base', 'status' => 'AVAILABLE'],
            ['id' => 2, 'name' => 'Elena Flores', 'station' => 'Patrol 1', 'status' => 'AVAILABLE'],
            ['id' => 3, 'name' => 'Nestor Lim', 'station' => 'Purok 2', 'status' => 'DEPLOYED'],
            ['id' => 4, 'name' => 'Lito Navarro', 'station' => 'Zone 3', 'status' => 'DEPLOYED'],
            ['id' => 5, 'name' => 'Mario Aquino', 'station' => 'Base Station', 'status' => 'OFF_DUTY']
        ];

        // 7. All Blotter Cases
        $allStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, purok, priority_level, status, ai_recommendation, deployed_unit, created_at 
            FROM incident_reports 
            ORDER BY created_at DESC 
            LIMIT 100
        ");
        $allCases = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 8. Notifications
        $notifStmt = $pdo->query("
            SELECT reference_number, incident_type, complainant_name, purok, priority_level, status, created_at 
            FROM incident_reports 
            WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL') 
            ORDER BY (priority_level = 'Critical' OR status = 'CRITICAL') DESC, created_at DESC 
            LIMIT 15
        ");
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        officerRespond([
            'success' => true,
            'metrics' => [
                'pending_triage' => $pendingTriageCount,
                'active_dispatched' => $activeDispatchedCount,
                'under_investigation' => $underInvestigationCount,
                'resolved_today' => $resolvedTodayCount
            ],
            'critical_case' => $criticalCase,
            'notifications' => $notifications,
            'triage_queue' => $triageQueue,
            'urgent_cases' => $urgentCases,
            'lupon_cases' => $luponCases,
            'tanods' => $tanods,
            'all_cases' => $allCases
        ]);
    }

    if ($method !== 'POST') {
        officerRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expectedCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    $providedCsrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        officerRespond(['success' => false, 'message' => 'Security token expired. Refresh page.'], 403);
    }

    $input = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') ? $_POST : json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        officerRespond(['success' => false, 'message' => 'Invalid request data.'], 400);
    }

    $action    = trim((string) ($input['action'] ?? ''));
    $refNumber = trim((string) ($input['reference_number'] ?? ''));

    $needRef = function () use ($refNumber): void {
        if ($refNumber === '') {
            officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
        }
    };
    $tooLong = function (string $value, int $max, string $label): void {
        if (mb_strlen($value) > $max) {
            officerRespond(['success' => false, 'message' => "{$label} is too long (max {$max} characters)."], 422);
        }
    };

    switch ($action) {
        // Triage: Move into active blotter (Phase 2 for Resident)
        case 'accept_blotter':
            $needRef();
            officerTransition($pdo, $refNumber, [
                'status'           => 'IN_PROGRESS',
                'milestone_status' => 'IN_PROGRESS',
                'officer'          => $actorName,
                'note'             => 'Accepted from desk triage into official blotter.',
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} accepted into active blotter."]);

        // Urgent / Tanod: Dispatch responders (Phase 3 for Resident)
        case 'dispatch_tanod':
            $needRef();
            $unit  = trim((string) ($input['deployed_unit'] ?? ''));
            $notes = trim((string) ($input['dispatch_notes'] ?? ''));
            if ($unit === '')  $unit  = 'Tanod Patrol Unit';
            if ($notes === '') $notes = 'Immediate responder unit dispatched on-site.';
            $tooLong($unit, 100, 'Unit name');
            $tooLong($notes, 1000, 'Dispatch notes');

            officerTransition($pdo, $refNumber, [
                'status'           => 'DISPATCHED',
                'milestone_status' => 'DISPATCHED',
                'officer'          => $actorName,
                'unit'             => $unit,
                'milestone_unit'   => $unit,
                'recommendation'   => "Unit Dispatched: {$unit} assigned for on-site assessment.",
                'note'             => $notes,
            ]);
            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

        // Lupon: Endorse case for mediation (Uses deployed_unit instead of assigned_officer)
        case 'endorse_lupon':
            $needRef();
            $mediator = trim((string) ($input['mediator'] ?? ''));
            $schedule = trim((string) ($input['schedule'] ?? ''));
            if ($mediator === '') $mediator = 'Barangay Lupon Panel';
            if ($schedule === '') $schedule = date('Y-m-d 10:00:00', strtotime('+3 days'));
            $tooLong($mediator, 120, 'Mediator name');
            if (strtotime($schedule) === false) {
                officerRespond(['success' => false, 'message' => 'Invalid mediation schedule.'], 422);
            }

            officerTransition($pdo, $refNumber, [
                'status'           => 'FOR_RESOLUTION',
                'milestone_status' => 'FOR_RESOLUTION',
                'officer'          => $mediator,
                'unit'             => $mediator,
                'note'             => "Endorsed to Katarungang Pambarangay. Mediation scheduled on {$schedule} with {$mediator}.",
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} endorsed to Lupon mediation."]);

        // Lupon: Mark mediation settled (Phase 4 "Resolved" for Resident)
        case 'settle_lupon':
            $needRef();
            $resolutionNotes = trim((string) ($input['notes'] ?? ''));
            if ($resolutionNotes === '') $resolutionNotes = 'Amicable settlement reached under Katarungang Pambarangay.';
            $tooLong($resolutionNotes, 1000, 'Resolution notes');

            officerTransition($pdo, $refNumber, [
                'status'           => 'RESOLVED',
                'milestone_status' => 'RESOLVED',
                'officer'          => $actorName,
                'recommendation'   => 'Case settled amicably during mediation.',
                'note'             => $resolutionNotes,
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} marked as resolved and settled."]);

        // Flag / Reject / Spam
        case 'flag_reject':
            $needRef();
            officerTransition($pdo, $refNumber, [
                'status'           => 'RESOLVED',
                'milestone_status' => 'CLOSED',
                'officer'          => $actorName,
                'recommendation'   => 'Flagged / closed as non-actionable or duplicate during triage.',
                'note'             => 'Case archived and closed at frontline desk.',
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} flagged and closed."]);

        // Encode walk-in case: shared logic with api/encode_case.php (triage, downgrade gate, audit, outbox)
        case 'encode_case':
            // Keep the old form behavior: street goes into the narrative, with the same defaults as before.
            $street = trim((string) ($input['streetAddress'] ?? ''));
            $narr   = trim((string) ($input['incidentNarrative'] ?? ''));
            if ($narr !== '' && $street !== '') {
                $input['incidentNarrative'] = $narr . "\n\n[Location: {$street}]";
            }
            if (trim((string) ($input['category'] ?? '')) === '' && trim((string) ($input['incidentCategory'] ?? '')) === '') {
                $input['category'] = 'Others';
            }
            if (trim((string) ($input['priorityLevel'] ?? '')) === '' && trim((string) ($input['priority'] ?? '')) === '') {
                $input['priorityLevel'] = 'High';
            }

            $r = encode_case_run($input, $actor);
            if ($r['status'] === 200 && isset($r['body']['data']['reference_number'])) {
                // Same wording the dashboard showed before.
                $r['body']['message'] = "Blotter recorded successfully with Tracking #{$r['body']['data']['reference_number']}.";
            }
            officerRespond($r['body'], $r['status']);

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }
} catch (PDOException $e) {
    error_log('[officer_console] database error: ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Database error. Please try again.'], 500);
} catch (Throwable $e) {
    error_log('[officer_console] ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Request error. Please try again.'], 500);
}