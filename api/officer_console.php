<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function officerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require_once __DIR__ . '/../auth.php';
    require_once __DIR__ . '/../lib/case_workflow.php';
    if (file_exists(__DIR__ . '/../lib/triage.php')) {
        require_once __DIR__ . '/../lib/triage.php';
    }
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Include error: ' . $e->getMessage()], 500);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN'];
$verifiedActor = requireApiRole($allowedRoles);
$userType = strtoupper((string) $verifiedActor['role']);
$actorId = (int) $verifiedActor['id'];
$actorName = (string) ($verifiedActor['full_name'] ?? 'Desk Officer');

function officerRequirePermission(string $permission): void
{
    global $userType;
    if (!caseWorkflowCan($userType, $permission)) {
        officerRespond(['success' => false, 'message' => 'Your role is not authorized to perform this case action.'], 403);
    }
}

function officerWorkflowTablesAvailable(PDO $pdo): bool
{
    $stmt = $pdo->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('case_findings', 'case_resolutions', 'case_workflow_closures')
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($tables) === 3;
}

function officerTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function officerReleaseDispatchResponders(PDO $pdo, int $incidentId, int $actorId, string $actorName): void
{
    if (!officerTableExists($pdo, 'tanod_dispatch_records')
        || !officerTableExists($pdo, 'tanod_dispatch_record_responders')) {
        return;
    }

    $activeDispatches = $pdo->prepare("
        SELECT id
        FROM tanod_dispatch_records
        WHERE incident_id = ? AND status <> 'RESPONSE_SUBMITTED'
        FOR UPDATE
    ");
    $activeDispatches->execute([$incidentId]);
    $dispatchIds = array_map('intval', $activeDispatches->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$dispatchIds) {
        $legacyUnitStmt = $pdo->prepare('SELECT deployed_unit FROM incident_reports WHERE id = ? LIMIT 1');
        $legacyUnitStmt->execute([$incidentId]);
        $legacyUnit = trim((string) $legacyUnitStmt->fetchColumn());
        if ($legacyUnit !== '') {
            $deployedResponders = $pdo->query("
                SELECT tp.id, tp.full_name
                FROM tanod_personnel tp
                WHERE tp.status = 'DEPLOYED'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM tanod_dispatch_record_responders responders
                      JOIN tanod_dispatch_records dispatch ON dispatch.id = responders.dispatch_id
                      WHERE responders.tanod_id = tp.id
                        AND dispatch.status <> 'RESPONSE_SUBMITTED'
                  )
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $releaseLegacyResponder = $pdo->prepare("
                UPDATE tanod_personnel SET status = 'AVAILABLE'
                WHERE id = ? AND status = 'DEPLOYED'
            ");
            foreach ($deployedResponders as $responder) {
                if (str_contains($legacyUnit, (string) $responder['full_name'])) {
                    $releaseLegacyResponder->execute([(int) $responder['id']]);
                }
            }
        }
        return;
    }

    $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
    $releaseResponders = $pdo->prepare("
        UPDATE tanod_personnel tp
        JOIN tanod_dispatch_record_responders responders ON responders.tanod_id = tp.id
        SET tp.status = 'AVAILABLE'
        WHERE responders.dispatch_id IN ({$placeholders})
          AND tp.status = 'DEPLOYED'
    ");
    $releaseResponders->execute($dispatchIds);

    $finishDispatches = $pdo->prepare("
        UPDATE tanod_dispatch_records
        SET status = 'RESPONSE_SUBMITTED',
            updated_by_user_id = ?, updated_by_name = ?, updated_at = NOW()
        WHERE id IN ({$placeholders}) AND status <> 'RESPONSE_SUBMITTED'
    ");
    $finishDispatches->execute(array_merge([$actorId, $actorName], $dispatchIds));
}

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
        if (isset($t['expected_status']) && $case['status'] !== $t['expected_status']) {
            $pdo->rollBack();
            officerRespond(['success' => false, 'message' => 'This case is no longer in Lupon mediation.'], 409);
        }
        if (isset($t['allowed_from']) && !in_array($case['status'], $t['allowed_from'], true)) {
            $pdo->rollBack();
            officerRespond(['success' => false, 'message' => 'This action is not valid for the case status.'], 409);
        }
        if (!caseWorkflowAllowsTransition((string) $case['status'], (string) $t['status'])) {
            $pdo->rollBack();
            officerRespond(['success' => false, 'message' => 'That status transition is not allowed for this case.'], 409);
        }
        $incidentId = (int) $case['id'];

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

        if (isset($t['dispatch'])) {
            if (!officerTableExists($pdo, 'tanod_dispatch_records')
                || !officerTableExists($pdo, 'tanod_dispatch_record_responders')) {
                $pdo->rollBack();
                officerRespond([
                    'success' => false,
                    'message' => 'Dispatch tracking is not installed. Apply tanod_dispatch_migration.sql before assigning responders.',
                ], 503);
            }
            $dispatch = $t['dispatch'];
            $responderIds = array_values(array_unique(array_map('intval', $dispatch['responder_ids'] ?? [])));
            if (!$responderIds) {
                $pdo->rollBack();
                officerRespond(['success' => false, 'message' => 'Select at least one available responder.'], 422);
            }
            $placeholders = implode(',', array_fill(0, count($responderIds), '?'));
            $respondersStmt = $pdo->prepare("
                SELECT id, full_name, status
                FROM tanod_personnel
                WHERE id IN ({$placeholders})
                ORDER BY id ASC
                FOR UPDATE
            ");
            $respondersStmt->execute($responderIds);
            $responders = $respondersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($responders) !== count($responderIds)
                || array_filter($responders, static fn (array $responder): bool => $responder['status'] !== 'AVAILABLE')) {
                $pdo->rollBack();
                officerRespond(['success' => false, 'message' => 'One or more responders are no longer available. Refresh the board and try again.'], 409);
            }

            $unit = implode(', ', array_column($responders, 'full_name'));
            $pdo->prepare('UPDATE incident_reports SET deployed_unit = ? WHERE id = ?')->execute([$unit, $incidentId]);
            $pdo->prepare('UPDATE case_milestones SET deployed_unit = ? WHERE id = ?')->execute([$unit, $milestoneId]);
            $dispatchStmt = $pdo->prepare("
                INSERT INTO tanod_dispatch_records
                    (incident_id, unit, status, directive_notes, dispatched_by_user_id, dispatched_by_name, dispatched_at)
                VALUES (?, ?, 'DISPATCHED', ?, ?, ?, NOW())
            ");
            $dispatchStmt->execute([$incidentId, $unit, $dispatch['notes'], $dispatch['user_id'], $dispatch['user_name']]);
            $dispatchId = (int) $pdo->lastInsertId();
            $linkStmt = $pdo->prepare('INSERT INTO tanod_dispatch_record_responders (dispatch_id, tanod_id) VALUES (?, ?)');
            $setDeployed = $pdo->prepare("UPDATE tanod_personnel SET status = 'DEPLOYED' WHERE id = ?");
            foreach ($responders as $responder) {
                $linkStmt->execute([$dispatchId, (int) $responder['id']]);
                $setDeployed->execute([(int) $responder['id']]);
            }
        }

        if (isset($t['lupon_case'])) {
            $lupon = $t['lupon_case'];
            $existingLupon = $pdo->prepare('SELECT id FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $existingLupon->execute([$incidentId]);
            if ($existingLupon->fetchColumn()) {
                $pdo->prepare('UPDATE lupon_cases SET stage = ?, next_hearing = ?, status = ? WHERE case_id = ?')
                    ->execute([$lupon['stage'], $lupon['next_hearing'], $lupon['status'], $incidentId]);
            } else {
                $pdo->prepare('INSERT INTO lupon_cases (case_id, stage, next_hearing, status) VALUES (?, ?, ?, ?)')
                    ->execute([$incidentId, $lupon['stage'], $lupon['next_hearing'], $lupon['status']]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    $pdo = getDatabaseConnection();
    $dispatchTrackingReady = officerTableExists($pdo, 'tanod_dispatch_records')
        && officerTableExists($pdo, 'tanod_dispatch_record_responders');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $officerProfile = [];
        try {
            $officerStmt = $pdo->prepare('SELECT full_name, username, email_or_phone, contact_number, user_type, profile_picture FROM users WHERE id = ? LIMIT 1');
            $officerStmt->execute([$actorId]);
            $officerProfile = $officerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $pendingTriageCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL')")->fetchColumn() ?: 0);
        $activeDispatchedCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'DISPATCHED'")->fetchColumn() ?: 0);
        $underInvestigationCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'IN_PROGRESS'")->fetchColumn() ?: 0);
        $resolvedTodayCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'RESOLVED' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0);

        $criticalCase = null;
        try {
            $criticalStmt = $pdo->query("
                SELECT reference_number, complainant_name, incident_type, purok, narrative_description 
                FROM incident_reports 
                WHERE (priority_level = 'Critical' OR status = 'CRITICAL') 
                  AND status NOT IN ('RESOLVED', 'CLOSED')
                  AND (deployed_unit IS NULL OR deployed_unit = '') 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $criticalCase = $criticalStmt ? $criticalStmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Throwable $e) {}

        $triageQueue = [];
        try {
            $triageStmt = $pdo->query("
                SELECT * FROM incident_reports 
                WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL')
                ORDER BY created_at DESC 
                LIMIT 30
            ");
            $triageQueue = $triageStmt ? $triageStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $urgentCases = [];
        try {
            $urgentStmt = $pdo->query("
                SELECT * FROM incident_reports 
                WHERE priority_level IN ('Critical', 'High') AND status NOT IN ('RESOLVED', 'CLOSED') 
                ORDER BY (priority_level = 'Critical') DESC, created_at DESC
            ");
            $urgentCases = $urgentStmt ? $urgentStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $luponCases = [];
        try {
            $luponCases = $pdo->query("
                SELECT ir.*, lc.next_hearing, ir.deployed_unit AS assigned_officer
                FROM incident_reports ir
                LEFT JOIN lupon_cases lc ON lc.case_id = ir.id
                    AND lc.id = (SELECT MAX(lc_latest.id) FROM lupon_cases lc_latest WHERE lc_latest.case_id = ir.id)
                WHERE ir.status = 'FOR_RESOLUTION'
                ORDER BY lc.next_hearing IS NULL, lc.next_hearing ASC, ir.created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $tanods = [];
        $dispatchQueue = [];
        $dispatchMetrics = ['on_duty' => 0, 'available' => 0, 'assigned' => 0, 'off_duty' => 0];
        try {
            $tanodQuery = "
                SELECT tp.id, tp.full_name AS name, UPPER(COALESCE(tp.status, 'AVAILABLE')) AS status,
                    COALESCE(tp.station, 'Patrol Beat') AS station, tp.contact_number,
                    NULL AS current_assignment, NULL AS assigned_reference
                FROM tanod_personnel tp
                ORDER BY tp.id ASC
            ";
            if ($dispatchTrackingReady) {
                $tanodQuery = "
                    SELECT tp.id, tp.full_name AS name, UPPER(COALESCE(tp.status, 'AVAILABLE')) AS status,
                        COALESCE(tp.station, 'Patrol Beat') AS station, tp.contact_number,
                        active_dispatch.unit AS current_assignment, active_case.reference_number AS assigned_reference
                    FROM tanod_personnel tp
                    LEFT JOIN (
                        SELECT responder.tanod_id, dispatch.incident_id, dispatch.unit
                        FROM tanod_dispatch_record_responders responder
                        JOIN tanod_dispatch_records dispatch ON dispatch.id = responder.dispatch_id
                        JOIN (
                            SELECT responder_latest.tanod_id, MAX(dispatch_latest.id) AS dispatch_id
                            FROM tanod_dispatch_record_responders responder_latest
                            JOIN tanod_dispatch_records dispatch_latest ON dispatch_latest.id = responder_latest.dispatch_id
                            GROUP BY responder_latest.tanod_id
                        ) latest ON latest.dispatch_id = dispatch.id AND latest.tanod_id = responder.tanod_id
                        WHERE dispatch.status <> 'RESPONSE_SUBMITTED'
                    ) active_dispatch ON active_dispatch.tanod_id = tp.id
                    LEFT JOIN incident_reports active_case ON active_case.id = active_dispatch.incident_id
                    ORDER BY tp.id ASC
                ";
            }
            $tanodStmt = $pdo->query($tanodQuery);
            $tanods = $tanodStmt ? $tanodStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($tanods as $tanod) {
                $status = strtoupper((string) ($tanod['status'] ?? 'AVAILABLE'));
                if (in_array($status, ['AVAILABLE', 'DEPLOYED'], true)) $dispatchMetrics['on_duty']++;
                if ($status === 'AVAILABLE') $dispatchMetrics['available']++;
                if ($status === 'DEPLOYED') $dispatchMetrics['assigned']++;
                if ($status === 'OFF_DUTY') $dispatchMetrics['off_duty']++;
            }
        } catch (Throwable $e) {}

        try {
            if ($dispatchTrackingReady) {
                $queueStmt = $pdo->query("
                    SELECT ir.id, ir.reference_number, ir.incident_type, ir.complainant_name,
                        ir.purok, ir.priority_level, ir.status, ir.created_at, ir.deployed_unit,
                        latest.id AS dispatch_id, latest.status AS dispatch_status,
                        latest.unit AS dispatch_unit, latest.directive_notes, latest.dispatched_at,
                        latest.dispatched_by_name,
                        latest.acknowledged_at, latest.acknowledged_by_name, latest.arrived_at,
                        latest.arrived_by_name, latest.findings, latest.action_taken,
                        latest.updated_by_name, latest.updated_at,
                        GROUP_CONCAT(DISTINCT tp.full_name ORDER BY tp.full_name SEPARATOR ', ') AS responders
                    FROM incident_reports ir
                    LEFT JOIN (
                        SELECT d.*
                        FROM tanod_dispatch_records d
                        JOIN (
                            SELECT incident_id, MAX(id) AS id
                            FROM tanod_dispatch_records
                            GROUP BY incident_id
                        ) most_recent ON most_recent.id = d.id
                    ) latest ON latest.incident_id = ir.id
                    LEFT JOIN tanod_dispatch_record_responders dr ON dr.dispatch_id = latest.id
                    LEFT JOIN tanod_personnel tp ON tp.id = dr.tanod_id
                    WHERE ir.status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL', 'IN_PROGRESS', 'DISPATCHED')
                    GROUP BY ir.id, ir.reference_number, ir.incident_type, ir.complainant_name,
                        ir.purok, ir.priority_level, ir.status, ir.created_at, ir.deployed_unit,
                        latest.id, latest.status, latest.unit, latest.directive_notes, latest.dispatched_at,
                        latest.dispatched_by_name,
                        latest.acknowledged_at, latest.acknowledged_by_name, latest.arrived_at,
                        latest.arrived_by_name, latest.findings, latest.action_taken,
                        latest.updated_by_name, latest.updated_at
                    HAVING ir.status = 'DISPATCHED'
                        OR (latest.id IS NOT NULL AND latest.status <> 'RESPONSE_SUBMITTED')
                        OR ((ir.deployed_unit IS NULL OR ir.deployed_unit = '') AND latest.id IS NULL)
                    ORDER BY (ir.status = 'DISPATCHED') DESC, ir.created_at ASC
                ");
                $dispatchQueue = $queueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $dispatchQueue = $pdo->query("
                    SELECT id, reference_number, incident_type, complainant_name, purok,
                        priority_level, status, created_at, deployed_unit, NULL AS dispatch_id,
                        NULL AS dispatch_status, deployed_unit AS dispatch_unit, NULL AS directive_notes,
                        NULL AS dispatched_at, NULL AS acknowledged_at, NULL AS acknowledged_by_name,
                        NULL AS arrived_at, NULL AS arrived_by_name,
                        NULL AS findings, NULL AS action_taken, NULL AS updated_by_name,
                        NULL AS updated_at, NULL AS responders
                    FROM incident_reports
                    WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL', 'IN_PROGRESS', 'DISPATCHED')
                        AND (status = 'DISPATCHED' OR deployed_unit IS NULL OR deployed_unit = '')
                    ORDER BY (status = 'DISPATCHED') DESC, created_at ASC
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}

        $allCases = [];
        try {
            $allCases = $pdo->query("
                SELECT * FROM incident_reports 
                ORDER BY created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $notifications = [];
        try {
            $notifications = $pdo->query("
                SELECT reference_number, incident_type, complainant_name, purok, priority_level, status, created_at 
                FROM incident_reports 
                WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL') 
                ORDER BY (priority_level = 'Critical' OR status = 'CRITICAL') DESC, created_at DESC 
                LIMIT 15
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        officerRespond([
            'success' => true,
            'officer' => [
                'full_name' => $officerProfile['full_name'] ?? $actorName,
                'role' => $officerProfile['user_type'] ?? $userType,
                'contact_number' => $officerProfile['contact_number'] ?? '',
                'profile_picture' => $officerProfile['profile_picture'] ?? null
            ],
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
            'dispatch_queue' => $dispatchQueue,
            'dispatch_metrics' => $dispatchMetrics,
            'dispatch_tracking_ready' => $dispatchTrackingReady,
            'all_cases' => $allCases
        ]);
    }

    if ($method !== 'POST') {
        officerRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = file_get_contents('php://input');
    $jsonDecoded = json_decode($rawInput, true);
    $input = is_array($jsonDecoded) ? $jsonDecoded : $_POST;

    if (!is_array($input)) {
        officerRespond(['success' => false, 'message' => 'Invalid request data format.'], 400);
    }

    $action    = trim((string) ($input['action'] ?? ''));
    $refNumber = trim((string) ($input['reference_number'] ?? ''));
    if (in_array($action, ['record_case_findings', 'resolve_case', 'close_case'], true)
        && !officerWorkflowTablesAvailable($pdo)) {
        officerRespond([
            'success' => false,
            'message' => 'Case workflow storage is not installed. Apply case_workflow_migration.sql, then try again.',
        ], 503);
    }
    $actionPermissions = [
        'accept_blotter' => 'triage',
        'dispatch_tanod' => 'assign',
        'create_responder' => 'assign',
        'update_responder' => 'assign',
        'update_responder_availability' => 'assign',
        'dispatch_response_update' => 'assign',
        'record_case_findings' => 'findings',
        'endorse_lupon' => 'refer',
        'refer_case' => 'refer',
        'reschedule_lupon' => 'refer',
        'request_captain_endorsement' => 'refer',
        'resolve_case' => 'resolve',
        'flag_reject' => 'close',
        'close_case' => 'close',
        'reopen_case' => 'reopen',
        'encode_case' => 'triage',
    ];
    if (isset($actionPermissions[$action])) {
        officerRequirePermission($actionPermissions[$action]);
    }

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
        case 'accept_blotter':
        case 'dispatch_tanod':
        case 'create_responder':
        case 'update_responder':
        case 'update_responder_availability':
        case 'dispatch_response_update':
        case 'record_case_findings':
        case 'resolve_case':
        case 'close_case':
        case 'endorse_lupon':
        case 'refer_case':
        case 'reschedule_lupon':
        case 'request_captain_endorsement':
        case 'reopen_case':
        case 'get_case_review_details':
        case 'encode_case':
        case 'profile_update':
        case 'password_update':
            // Handled below in switch body
            break;
        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }

    // Full action implementation block for officer console
    switch ($action) {
        case 'accept_blotter':
            $needRef();
            $customNote = trim((string) ($input['note'] ?? ''));
            $note = ($customNote !== '') ? $customNote : 'Accepted from desk triage into official blotter.';
            $tooLong($note, 1000, 'Desk note');

            officerTransition($pdo, $refNumber, [
                'status'           => 'IN_PROGRESS',
                'milestone_status' => 'IN_PROGRESS',
                'milestone_stage'  => 'DESK_REVIEW',
                'officer'          => $actorName,
                'note'             => $note,
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} accepted into active blotter."]);

        case 'dispatch_tanod':
            $needRef();
            $notes = trim((string) ($input['dispatch_notes'] ?? ''));
            if ($notes === '') $notes = 'Immediate responder unit dispatched on-site.';
            $tooLong($notes, 1000, 'Dispatch notes');
            $responderIds = $input['responder_ids'] ?? [];
            if (!is_array($responderIds)) {
                $responderIds = [];
            }
            $responderIds = array_values(array_unique(array_filter(array_map('intval', $responderIds), static fn (int $id): bool => $id > 0)));

            officerTransition($pdo, $refNumber, [
                'status'           => 'DISPATCHED',
                'milestone_status' => 'DISPATCHED',
                'milestone_stage'  => 'UNIT_DISPATCHED',
                'officer'          => $actorName,
                'unit'             => 'Responder assignment pending',
                'milestone_unit'   => 'Responder assignment pending',
                'recommendation'   => 'Responder unit assigned for on-site assessment.',
                'note'             => $notes,
                'dispatch'         => [
                    'responder_ids' => $responderIds,
                    'notes'         => $notes,
                    'user_id'       => $actorId,
                    'user_name'     => $actorName,
                ],
            ]);

            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

        case 'get_case_review_details':
            $needRef();
            $stmt = $pdo->prepare("SELECT * FROM incident_reports WHERE reference_number = :ref LIMIT 1");
            $stmt->execute([':ref' => $refNumber]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                officerRespond(['success' => false, 'message' => 'Case not found.'], 404);
            }

            $aiData = !empty($case['ai_analysis_json']) ? json_decode($case['ai_analysis_json'], true) : null;
            if (!$aiData && class_exists('TriageEngine')) {
                $aiData = TriageEngine::evaluateIncident($case['narrative_description']);
            }

            officerRespond([
                'success'           => true,
                'case'              => $case,
                'ai_analysis'       => $aiData,
                'timeline'          => [],
                'endorsements'      => [],
                'findings'          => [],
                'resolutions'       => [],
                'closures'          => [],
                'workflow_ready'    => officerWorkflowTablesAvailable($pdo),
                'lupon'             => null
            ]);

        case 'encode_case':
            $narrative = trim((string) ($input['narrative_description'] ?? $input['narrative'] ?? ''));
            if ($narrative === '') {
                officerRespond(['success' => false, 'message' => 'Narrative description is required.'], 400);
            }

            $analysis = [
                'incident_type'     => 'General Incident',
                'priority_level'    => 'Low',
                'ai_urgency_score'  => 0.10,
                'legal_routing'     => 'Standard Desk Blotter Recording',
                'weapon_flag'       => 'NONE',
                'vawc_minor_flag'   => false,
                'medical_flag'      => 'None indicated.',
                'substance_flag'    => 'None indicated.',
                'detected_keywords' => [],
                'trigger_excerpts'  => [],
                'reasoning'         => 'Brief narrative assessed under standard intake.',
                'ai_recommendation' => 'Proceed with regular verification.'
            ];

            if (class_exists('TriageEngine')) {
                try {
                    $aiResult = TriageEngine::evaluateIncident($narrative);
                    if (is_array($aiResult)) {
                        $analysis = array_merge($analysis, $aiResult);
                    }
                } catch (Throwable $t) {}
            }

            $finalCategory = !empty($input['incident_type']) && $input['incident_type'] !== 'General Incident' 
                ? trim((string) $input['incident_type']) 
                : ($analysis['incident_type'] ?? 'General Incident');

            $aiPriority = $analysis['priority_level'] ?? 'Low';
            $officerPriority = !empty($input['priority_level']) ? trim((string) ($input['priority_level'])) : $aiPriority;

            $weights = ['Low' => 1, 'Moderate' => 2, 'Medium' => 2, 'High' => 3, 'Critical' => 4];
            $aiWeight = $weights[$aiPriority] ?? 1;
            $requestedWeight = $weights[$officerPriority] ?? $aiWeight;

            $finalPriority = ($requestedWeight < $aiWeight) ? $officerPriority : $aiPriority;
            $refNumber = 'IR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_reports (
                        reference_number, complainant_name, complainant_phone, incident_type,
                        narrative_description, incident_datetime, purok, priority_level,
                        ai_urgency_score, ai_recommendation, status, assigned_officer_id, assigned_officer_name
                    ) VALUES (
                        :ref, :name, :phone, :type,
                        :narrative, NOW(), :purok, :priority,
                        :ai_score, :recommendation, 'PENDING', :officer_id, :officer_name
                    )
                ");

                $stmt->execute([
                    ':ref'            => $refNumber,
                    ':name'           => trim((string) ($input['complainant_name'] ?? 'Walk-in Resident')),
                    ':phone'          => trim((string) ($input['complainant_phone'] ?? 'N/A')),
                    ':type'           => $finalCategory,
                    ':narrative'      => $narrative,
                    ':purok'          => trim((string) ($input['purok'] ?? 'Purok 1')),
                    ':priority'       => $finalPriority,
                    ':ai_score'       => (float) ($analysis['ai_urgency_score'] ?? 0.00),
                    ':recommendation' => $analysis['ai_recommendation'] ?? 'Standard intake',
                    ':officer_id'     => $actorId,
                    ':officer_name'   => $actorName
                ]);

                $incidentId = (int) $pdo->lastInsertId();

                if (officerTableExists($pdo, 'case_milestones')) {
                    $pdo->prepare("
                        INSERT INTO case_milestones (
                            incident_id, tracking_id, status_snapshot, officer_in_charge, action_note
                        ) VALUES (?, ?, 'PENDING', ?, ?)
                    ")->execute([
                        $incidentId,
                        $refNumber,
                        $actorName,
                        "Incident encoded. Assessed priority: {$finalPriority}."
                    ]);
                }

                $pdo->commit();

                officerRespond([
                    'success'          => true,
                    'message'          => "Case {$refNumber} successfully recorded.",
                    'reference_number' => $refNumber,
                    'priority'         => $finalPriority
                ]);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

        case 'profile_update':
            $name = trim((string) ($input['full_name'] ?? ''));
            $contact = trim((string) ($input['contact_number'] ?? ''));

            if ($name === '') {
                officerRespond(['success' => false, 'message' => 'Full name cannot be blank.'], 422);
            }

            $picturePath = null;
            if (!empty($_FILES['profile_picture']['tmp_name'])) {
                $picture = $_FILES['profile_picture'];
                if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) {
                    officerRespond(['success' => false, 'message' => 'Profile picture must be smaller than 2 MB.'], 422);
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($picture['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    officerRespond(['success' => false, 'message' => 'Use a JPG, PNG, or WebP profile picture.'], 422);
                }

                $directory = __DIR__ . '/../uploads/profiles';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    officerRespond(['success' => false, 'message' => 'Could not create profile images directory.'], 500);
                }

                $filename = 'officer_' . $actorId . '_' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($picture['tmp_name'], $directory . '/' . $filename)) {
                    officerRespond(['success' => false, 'message' => 'Could not save the profile picture.'], 500);
                }
                $picturePath = 'uploads/profiles/' . $filename;
            }

            if ($picturePath !== null) {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, profile_picture = ? WHERE id = ?');
                $stmt->execute([$name, $contact, $picturePath, $actorId]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?');
                $stmt->execute([$name, $contact, $actorId]);
            }

            $_SESSION['full_name'] = $name;
            officerRespond(['success' => true, 'message' => 'Profile information updated successfully.']);

        case 'password_update':
            $currentPassword = (string) ($input['current_password'] ?? '');
            $newPassword = (string) ($input['new_password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');

            if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) {
                officerRespond(['success' => false, 'message' => 'New passwords must match and be at least 8 characters.'], 422);
            }

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$actorId]);
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($currentPassword, $hash)) {
                officerRespond(['success' => false, 'message' => 'Current password is incorrect.'], 422);
            }

            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actorId]);

            officerRespond(['success' => true, 'message' => 'Password changed successfully.']);

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }
} catch (PDOException $e) {
    error_log('[officer_console] database error: ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('[officer_console] ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}