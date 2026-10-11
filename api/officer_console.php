<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function officerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isLoggedIn() || !in_array(getUserRole(), ['OFFICER', 'CAPTAIN'], true)) {
    officerRespond(['success' => false, 'message' => 'Officer access required.'], 403);
}

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actorId = (int) $_SESSION['user_id'];

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

    $action = trim((string) ($input['action'] ?? ''));
    $refNumber = trim((string) ($input['reference_number'] ?? ''));

    switch ($action) {
        // Triage: Move into active blotter (Phase 2 for Resident)
        case 'accept_blotter':
            if (!$refNumber) officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
            $stmt = $pdo->prepare("UPDATE incident_reports SET status = 'IN_PROGRESS' WHERE reference_number = ?");
            $stmt->execute([$refNumber]);

            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) SELECT id, reference_number, 'IN_PROGRESS', (SELECT full_name FROM users WHERE id = ?), 'Accepted from desk triage into official blotter.', NOW() FROM incident_reports WHERE reference_number = ?");
            $stmtM->execute([$actorId, $refNumber]);

            officerRespond(['success' => true, 'message' => "Case #{$refNumber} accepted into active blotter."]);

        // Urgent / Tanod: Dispatch responders (Phase 3 for Resident)
        case 'dispatch_tanod':
            if (!$refNumber) officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
            $unit = trim((string) ($input['deployed_unit'] ?? 'Tanod Patrol Unit'));
            $notes = trim((string) ($input['dispatch_notes'] ?? 'Immediate responder unit dispatched on-site.'));

            $stmt = $pdo->prepare("UPDATE incident_reports SET status = 'DISPATCHED', deployed_unit = ?, ai_recommendation = ? WHERE reference_number = ?");
            $recommendationText = "Unit Dispatched: {$unit} assigned for on-site assessment.";
            $stmt->execute([$unit, $recommendationText, $refNumber]);

            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at) SELECT id, reference_number, 'DISPATCHED', (SELECT full_name FROM users WHERE id = ?), ?, ?, NOW() FROM incident_reports WHERE reference_number = ?");
            $stmtM->execute([$actorId, $unit, $notes, $refNumber]);

            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

        // Lupon: Endorse case for mediation (Uses deployed_unit instead of assigned_officer)
        case 'endorse_lupon':
            if (!$refNumber) officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
            $mediator = trim((string) ($input['mediator'] ?? 'Barangay Lupon Panel'));
            $schedule = trim((string) ($input['schedule'] ?? date('Y-m-d 10:00:00', strtotime('+3 days'))));

            $stmt = $pdo->prepare("UPDATE incident_reports SET status = 'FOR_RESOLUTION', deployed_unit = ? WHERE reference_number = ?");
            $stmt->execute([$mediator, $refNumber]);

            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) SELECT id, reference_number, 'FOR_RESOLUTION', ?, ?, NOW() FROM incident_reports WHERE reference_number = ?");
            $note = "Endorsed to Katarungang Pambarangay. Mediation scheduled on {$schedule} with {$mediator}.";
            $stmtM->execute([$mediator, $note, $refNumber]);

            officerRespond(['success' => true, 'message' => "Case #{$refNumber} endorsed to Lupon mediation."]);

        // Lupon: Mark mediation settled (Phase 4 "Resolved" for Resident)
        case 'settle_lupon':
            if (!$refNumber) officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
            $resolutionNotes = trim((string) ($input['notes'] ?? 'Amicable settlement reached under Katarungang Pambarangay.'));

            $stmt = $pdo->prepare("UPDATE incident_reports SET status = 'RESOLVED', ai_recommendation = 'Case settled amicably during mediation.' WHERE reference_number = ?");
            $stmt->execute([$refNumber]);

            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) SELECT id, reference_number, 'RESOLVED', (SELECT full_name FROM users WHERE id = ?), ?, NOW() FROM incident_reports WHERE reference_number = ?");
            $stmtM->execute([$actorId, $resolutionNotes, $refNumber]);

            officerRespond(['success' => true, 'message' => "Case #{$refNumber} marked as resolved and settled."]);

        // Flag / Reject / Spam
        case 'flag_reject':
            if (!$refNumber) officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
            $stmt = $pdo->prepare("UPDATE incident_reports SET status = 'RESOLVED', ai_recommendation = 'Flagged / closed as non-actionable or duplicate during triage.' WHERE reference_number = ?");
            $stmt->execute([$refNumber]);

            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) SELECT id, reference_number, 'CLOSED', (SELECT full_name FROM users WHERE id = ?), 'Case archived and closed at frontline desk.', NOW() FROM incident_reports WHERE reference_number = ?");
            $stmtM->execute([$actorId, $refNumber]);

            officerRespond(['success' => true, 'message' => "Case #{$refNumber} flagged and closed."]);

        // Encode walk-in case
        case 'encode_case':
            $name = trim((string) ($input['complainantName'] ?? ''));
            $phone = trim((string) ($input['complainantPhone'] ?? ''));
            $purok = trim((string) ($input['purok'] ?? ''));
            $street = trim((string) ($input['streetAddress'] ?? ''));
            $category = trim((string) ($input['category'] ?? 'Others'));
            $priority = trim((string) ($input['priorityLevel'] ?? 'High'));
            $narrative = trim((string) ($input['incidentNarrative'] ?? ''));

            if (!$name || !$phone || !$purok || !$narrative) {
                officerRespond(['success' => false, 'message' => 'All required blotter fields must be filled.'], 422);
            }

            $year = date('Y');
            $totalCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports")->fetchColumn();
            $newTracking = sprintf("TRK-%s-%03d", $year, $totalCount + 1);

            $status = ($priority === 'Critical') ? 'CRITICAL' : 'IN_PROGRESS';
            $fullNarrative = $narrative . ($street ? "\n\n[Location: {$street}]" : "");

            $stmt = $pdo->prepare("INSERT INTO incident_reports (reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, scenario_type, verification_level, priority_level, status, created_at) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'WALK_IN', 'VERIFIED', ?, ?, NOW())");
            $stmt->execute([$newTracking, $name, $phone, $category, $fullNarrative, $purok, $priority, $status]);

            $newId = (int) $pdo->lastInsertId();
            $stmtM = $pdo->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) VALUES (?, ?, ?, (SELECT full_name FROM users WHERE id = ?), ?, NOW())");
            $note = "Walk-in blotter intake recorded by desk officer. Priority: {$priority}.";
            $stmtM->execute([$newId, $newTracking, $status, $actorId, $note]);

            officerRespond(['success' => true, 'message' => "Blotter recorded successfully with Tracking #{$newTracking}."]);

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }
} catch (PDOException $e) {
    officerRespond(['success' => false, 'message' => 'DATABASE ERROR: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Request error: ' . $e->getMessage()], 500);
}
