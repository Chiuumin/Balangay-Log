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
    require_once __DIR__ . '/sms_helper.php';
    if (file_exists(__DIR__ . '/../lib/triage.php')) {
        require_once __DIR__ . '/../lib/triage.php';
    }
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Include error: ' . $e->getMessage()], 500);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


function sendCaseUpdateSms(PDO $pdo, int $incidentId, string $newStatus, string $remarks = ''): void {
    try {
        $stmt = $pdo->prepare('SELECT reference_number, complainant_name, complainant_phone, assigned_officer_name, deployed_unit FROM incident_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$incidentId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$case || empty($case['complainant_phone'])) {
            return;
        }

        $phone = trim($case['complainant_phone']);
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($cleanPhone, '0') === 0) {
            $cleanPhone = '63' . substr($cleanPhone, 1);
        } elseif (strlen($cleanPhone) === 10 && strpos($cleanPhone, '9') === 0) {
            $cleanPhone = '63' . $cleanPhone;
        }

        $name = trim($case['complainant_name'] ?? 'Resident');
        $ref = $case['reference_number']; 
        $handler = !empty($case['deployed_unit']) ? $case['deployed_unit'] : (!empty($case['assigned_officer_name']) ? $case['assigned_officer_name'] : '');
        $handlerText = $handler !== '' ? " Assigned: {$handler}." : '';

        $smsMsg = "BalangayLog Update: Hi {$name}, your case [Ref: {$ref}] status is now '{$newStatus}'.{$handlerText}";
        if ($remarks !== '') {
            $smsMsg .= " Remarks: {$remarks}";
        }

        $response = sendIprogSms($cleanPhone, $smsMsg);
        error_log("[SMS] Sent update for {$ref} to {$cleanPhone}: " . $response);
    } catch (Throwable $e) {
        error_log("[SMS] Failed to send update: " . $e->getMessage());
    }
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

function officerIncidentStatusSupports(PDO $pdo, string $status): bool
{
    $stmt = $pdo->query("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'incident_reports'
          AND COLUMN_NAME = 'status'
        LIMIT 1
    ");
    $columnType = $stmt->fetchColumn();
    return is_string($columnType) && str_contains($columnType, "'" . $status . "'");
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

        sendCaseUpdateSms($pdo, $incidentId, $t['status'], $t['note'] ?? '');

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
                  AND status NOT IN ('RESOLVED', 'CLOSED', 'REJECTED')
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
                WHERE priority_level IN ('Critical', 'High') AND status NOT IN ('RESOLVED', 'CLOSED', 'REJECTED')
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
        } catch (Throwable $e) {
            throw $e;
        }

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
                    WHERE ir.status IN ('IN_PROGRESS', 'DISPATCHED')
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
                    WHERE status IN ('IN_PROGRESS', 'DISPATCHED')
                        AND (status = 'DISPATCHED' OR deployed_unit IS NULL OR deployed_unit = '')
                    ORDER BY (status = 'DISPATCHED') DESC, created_at ASC
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            throw $e;
        }

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
    if ($action === 'flag_reject' && !officerIncidentStatusSupports($pdo, 'REJECTED')) {
        officerRespond([
            'success' => false,
            'message' => 'Report rejection is not enabled in this database. Apply case_rejection_migration.sql, then try again.',
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
        'flag_reject' => 'triage',
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
    $parseSchedule = function (string $schedule): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $schedule);
        if (!$date || $date->format('Y-m-d\TH:i') !== $schedule) {
            officerRespond(['success' => false, 'message' => 'Enter a valid mediation hearing date and time.'], 422);
        }
        return $date->format('Y-m-d H:i:s');
    };

    switch ($action) {
        case 'record_case_findings':
            $needRef();
            $observations = trim((string) ($input['observations'] ?? ''));
            $personsContacted = trim((string) ($input['persons_contacted'] ?? ''));
            $actionTaken = trim((string) ($input['action_taken'] ?? ''));
            $findings = trim((string) ($input['findings'] ?? ''));
            $recommendedNextSteps = trim((string) ($input['recommended_next_steps'] ?? ''));
            foreach ([
                'Observations' => $observations,
                'Persons contacted' => $personsContacted,
                'Action taken' => $actionTaken,
                'Findings' => $findings,
                'Recommended next steps' => $recommendedNextSteps,
            ] as $label => $value) {
                $tooLong($value, 4000, $label);
            }
            if ($observations === '' || $actionTaken === '' || $findings === '') {
                officerRespond(['success' => false, 'message' => 'Observations, action taken, and findings are required.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $caseStmt = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
                $caseStmt->execute([$refNumber]);
                $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                if (!$case) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
                }
                if (in_array($case['status'], ['RESOLVED', 'CLOSED'], true)) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Findings cannot be added after resolution or closure.'], 409);
                }
                $incidentId = (int) $case['id'];
                $pdo->prepare('INSERT INTO case_findings
                        (incident_id, observations, persons_contacted, action_taken, findings, recommended_next_steps,
                         submitted_by_user_id, submitted_by_name, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([
                        $incidentId,
                        $observations,
                        $personsContacted !== '' ? $personsContacted : null,
                        $actionTaken,
                        $findings,
                        $recommendedNextSteps !== '' ? $recommendedNextSteps : null,
                        $actorId,
                        $actorName,
                    ]);
                $findingId = (int) $pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            officerRespond(['success' => true, 'message' => "Findings #{$findingId} recorded by {$actorName}."]);

        case 'resolve_case':
        case 'close_case':
            $needRef();
            if ($action === 'close_case') {
                $pdo->beginTransaction();
                try {
                    $closeCaseStmt = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
                    $closeCaseStmt->execute([$refNumber]);
                    $closeCase = $closeCaseStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$closeCase) {
                        $pdo->rollBack();
                        officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
                    }
                    $latestResolutionStmt = $pdo->prepare('SELECT id, outcome, findings_reviewed_summary, conclusion,
                            remaining_obligations, follow_up_at, resulting_status
                        FROM case_resolutions WHERE incident_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                    $latestResolutionStmt->execute([(int) $closeCase['id']]);
                    $savedResolution = $latestResolutionStmt->fetch(PDO::FETCH_ASSOC);
                    if ($closeCase['status'] === 'RESOLVED'
                        && $savedResolution
                        && $savedResolution['resulting_status'] === 'RESOLVED') {
                        if ($savedResolution['remaining_obligations'] !== null && trim($savedResolution['remaining_obligations']) !== '') {
                            $pdo->rollBack();
                            officerRespond(['success' => false, 'message' => 'Complete outstanding obligations before finalizing this resolution.'], 409);
                        }
                        if ($savedResolution['follow_up_at'] !== null) {
                            $pdo->rollBack();
                            officerRespond(['success' => false, 'message' => 'Complete the scheduled follow-up before finalizing this resolution.'], 409);
                        }
                        if (!caseWorkflowAllowsTransition('RESOLVED', 'CLOSED')) {
                            $pdo->rollBack();
                            officerRespond(['success' => false, 'message' => 'This resolved case cannot be finalized from its current status.'], 409);
                        }
                        $incidentId = (int) $closeCase['id'];
                        $pdo->prepare('UPDATE incident_reports SET status = ? WHERE id = ?')
                            ->execute(['CLOSED', $incidentId]);
                        $pdo->prepare('INSERT INTO case_workflow_closures
                                (incident_id, resolution_id, outcome, findings_reviewed_summary, conclusion,
                                 closed_by_user_id, closed_by_name, closed_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                            ->execute([
                                $incidentId,
                                (int) $savedResolution['id'],
                                $savedResolution['outcome'],
                                $savedResolution['findings_reviewed_summary'],
                                $savedResolution['conclusion'],
                                $actorId,
                                $actorName,
                            ]);
                        officerReleaseDispatchResponders($pdo, $incidentId, $actorId, $actorName);
                        $pdo->prepare('UPDATE incident_reports SET deployed_unit = NULL WHERE id = ?')
                            ->execute([$incidentId]);
                        $pdo->prepare('INSERT INTO case_milestones
                                (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())')
                            ->execute([
                                $incidentId,
                                $refNumber,
                                'CLOSED',
                                $actorName,
                                null,
                                'Resolution finalized after confirming the saved resolution and findings.',
                            ]);
                        $pdo->commit();
                        officerRespond([
                            'success' => true,
                            'message' => "Case #{$refNumber} marked Resolved and locked for editing.",
                            'status' => 'CLOSED',
                        ]);
                    }
                    $pdo->rollBack();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
            $outcome = strtoupper(trim((string) ($input['outcome'] ?? '')));
            $conclusion = trim((string) ($input['conclusion'] ?? ''));
            $settlementTerms = trim((string) ($input['settlement_terms'] ?? ''));
            $remainingObligations = trim((string) ($input['remaining_obligations'] ?? ''));
            $followUpInput = trim((string) ($input['follow_up_at'] ?? ''));
            $closeRequested = $action === 'close_case' || !empty($input['close_case']);
            $allowedOutcomes = [
                'RESOLVED_THROUGH_INTERVENTION',
                'SETTLED_THROUGH_MEDIATION',
                'REFERRED_TO_ANOTHER_AUTHORITY',
                'WITHDRAWN',
                'OTHER',
            ];
            if (!in_array($outcome, $allowedOutcomes, true)) {
                officerRespond(['success' => false, 'message' => 'Choose a valid resolution outcome.'], 422);
            }
            if (mb_strlen($conclusion) < 30) {
                officerRespond(['success' => false, 'message' => 'Enter a clear conclusion of at least 30 characters, covering what happened and why this outcome is appropriate.'], 422);
            }
            foreach ([
                'Conclusion' => $conclusion,
                'Settlement terms' => $settlementTerms,
                'Remaining obligations' => $remainingObligations,
            ] as $label => $value) {
                $tooLong($value, 4000, $label);
            }
            if ($outcome === 'SETTLED_THROUGH_MEDIATION' && $settlementTerms === '') {
                officerRespond(['success' => false, 'message' => 'Record the agreed settlement terms before finalizing mediation.'], 422);
            }
            $followUpAt = null;
            if ($followUpInput !== '') {
                $followUp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $followUpInput);
                if (!$followUp || $followUp->format('Y-m-d\TH:i') !== $followUpInput) {
                    officerRespond(['success' => false, 'message' => 'Enter a valid follow-up date and time.'], 422);
                }
                $followUpAt = $followUp->format('Y-m-d H:i:s');
            }
            if ($outcome === 'REFERRED_TO_ANOTHER_AUTHORITY' && $followUpAt === null && $remainingObligations === '') {
                officerRespond(['success' => false, 'message' => 'Record a follow-up date or remaining obligation for an external referral.'], 422);
            }
            if ($closeRequested && !caseWorkflowCan($userType, 'close')) {
                officerRespond(['success' => false, 'message' => 'Your role may record a resolution, but cannot close the case.'], 403);
            }
            if ($closeRequested && ($remainingObligations !== '' || $followUpAt !== null)) {
                officerRespond(['success' => false, 'message' => 'Complete outstanding obligations and follow-up before closing this case.'], 409);
            }
            if (empty($input['findings_reviewed'])) {
                officerRespond(['success' => false, 'message' => 'Review and confirm the case findings before finalizing.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $caseStmt = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
                $caseStmt->execute([$refNumber]);
                $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                if (!$case) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
                }
                $currentStatus = (string) $case['status'];
                $eligibleStatuses = $closeRequested
                    ? ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION', 'RESOLVED']
                    : ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION'];
                if (!in_array($currentStatus, $eligibleStatuses, true)) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'This case status is not eligible for this resolution action.'], 409);
                }
                if ($closeRequested && !caseWorkflowAllowsTransition($currentStatus, 'CLOSED')) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'This case cannot be finalized from its current status.'], 409);
                }

                $findingStmt = $pdo->prepare('SELECT observations, persons_contacted, action_taken, findings, recommended_next_steps,
                        submitted_by_name, created_at
                    FROM case_findings WHERE incident_id = ? ORDER BY created_at DESC, id DESC');
                $findingStmt->execute([(int) $case['id']]);
                $findingRows = $findingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!$findingRows) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Record findings before resolving or closing this case.'], 409);
                }
                $findingsSummary = implode("\n\n", array_map(static function (array $finding): string {
                    return implode("\n", array_filter([
                        'Observations: ' . $finding['observations'],
                        $finding['persons_contacted'] ? 'Persons contacted: ' . $finding['persons_contacted'] : '',
                        'Action taken: ' . $finding['action_taken'],
                        'Findings: ' . $finding['findings'],
                        $finding['recommended_next_steps'] ? 'Recommended next steps: ' . $finding['recommended_next_steps'] : '',
                        'Recorded by: ' . $finding['submitted_by_name'] . ' on ' . $finding['created_at'],
                    ]));
                }, $findingRows));

                if ($outcome === 'SETTLED_THROUGH_MEDIATION' && $currentStatus !== 'FOR_RESOLUTION') {
                    $luponStmt = $pdo->prepare("SELECT id FROM lupon_cases WHERE case_id = ? AND status <> 'RESOLVED' LIMIT 1 FOR UPDATE");
                    $luponStmt->execute([(int) $case['id']]);
                    if (!$luponStmt->fetchColumn()) {
                        $pdo->rollBack();
                        officerRespond(['success' => false, 'message' => 'A mediation settlement requires an active Lupon referral.'], 409);
                    }
                }

                $resultingStatus = $closeRequested ? 'CLOSED' : 'RESOLVED';
                if (!caseWorkflowAllowsTransition($currentStatus, $resultingStatus)) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'That resolution status transition is not allowed for this case.'], 409);
                }
                $incidentId = (int) $case['id'];
                $pdo->prepare('INSERT INTO case_resolutions
                        (incident_id, outcome, findings_reviewed_summary, conclusion, settlement_terms, remaining_obligations,
                         follow_up_at, resulting_status, resolved_by_user_id, resolved_by_name, resolved_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([
                        $incidentId,
                        $outcome,
                        $findingsSummary,
                        $conclusion,
                        $settlementTerms !== '' ? $settlementTerms : null,
                        $remainingObligations !== '' ? $remainingObligations : null,
                        $followUpAt,
                        $resultingStatus,
                        $actorId,
                        $actorName,
                    ]);
                $resolutionId = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE incident_reports SET status = ? WHERE id = ?')
                    ->execute([$resultingStatus, $incidentId]);
                if ($outcome === 'SETTLED_THROUGH_MEDIATION' || $resultingStatus === 'RESOLVED' || $resultingStatus === 'CLOSED') {
                    $latestLuponStmt = $pdo->prepare('SELECT id FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                    $latestLuponStmt->execute([$incidentId]);
                    $latestLuponId = $latestLuponStmt->fetchColumn();
                    if ($latestLuponId) {
                        $pdo->prepare("UPDATE lupon_cases SET stage = 'RESOLVED', next_hearing = NULL, status = 'RESOLVED' WHERE id = ?")
                            ->execute([(int) $latestLuponId]);
                    }
                }
                officerReleaseDispatchResponders($pdo, $incidentId, $actorId, $actorName);
                $pdo->prepare('UPDATE incident_reports SET deployed_unit = NULL WHERE id = ?')
                    ->execute([$incidentId]);
                $resolutionStatus = 'RESOLVED';
                $resolutionNote = $outcome === 'SETTLED_THROUGH_MEDIATION'
                    ? 'Mediation settlement documented. Outcome: Settled Through Mediation.'
                    : 'Case resolution documented. Outcome: ' . str_replace('_', ' ', $outcome) . '.';
                $pdo->prepare('INSERT INTO case_milestones
                        (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([
                        $incidentId,
                        $refNumber,
                        $resolutionStatus,
                        $actorName,
                        null,
                        $resolutionNote . ' ' . $conclusion,
                    ]);
                if ($closeRequested) {
                    $pdo->prepare('INSERT INTO case_workflow_closures
                                (incident_id, resolution_id, outcome, findings_reviewed_summary, conclusion,
                                 closed_by_user_id, closed_by_name, closed_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                            ->execute([$incidentId, $resolutionId, $outcome, $findingsSummary, $conclusion, $actorId, $actorName]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $resolutionMessage = $resultingStatus === 'CLOSED'
                ? "Case #{$refNumber} marked Resolved and finalized."
                : ($resultingStatus === 'RESOLVED' && $outcome === 'SETTLED_THROUGH_MEDIATION'
                    ? "Case #{$refNumber} marked Resolved — Settled Through Mediation."
                    : "Case #{$refNumber} resolved.");
            officerRespond([
                'success' => true,
                'message' => $resolutionMessage,
                'status' => $resultingStatus,
                'resolution_id' => $resolutionId,
            ]);

        case 'create_responder':
            $name = trim((string) ($input['name'] ?? ''));
            $station = trim((string) ($input['station'] ?? ''));
            $contact = trim((string) ($input['contact_number'] ?? ''));
            if ($name === '') officerRespond(['success' => false, 'message' => 'Responder name is required.'], 422);
            $tooLong($name, 150, 'Responder name');
            $tooLong($station, 120, 'Station');
            $tooLong($contact, 30, 'Contact number');
            $duplicateResponder = $pdo->prepare('SELECT id FROM tanod_personnel WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1');
            $duplicateResponder->execute([$name]);
            if ($duplicateResponder->fetchColumn()) {
                officerRespond(['success' => false, 'message' => 'A responder with that name already exists.'], 409);
            }
            $pdo->prepare("
                INSERT INTO tanod_personnel (full_name, station, status, contact_number)
                VALUES (?, ?, 'AVAILABLE', ?)
            ")->execute([$name, $station !== '' ? $station : 'Barangay Base', $contact !== '' ? $contact : null]);
            officerRespond(['success' => true, 'message' => 'Responder added to the roster.']);

        case 'update_responder':
            $tanodId = (int) ($input['tanod_id'] ?? 0);
            $name = trim((string) ($input['name'] ?? ''));
            $station = trim((string) ($input['station'] ?? ''));
            $contact = trim((string) ($input['contact_number'] ?? ''));
            if ($tanodId < 1 || $name === '') {
                officerRespond(['success' => false, 'message' => 'Select a responder and provide a name.'], 422);
            }
            $tooLong($name, 150, 'Responder name');
            $tooLong($station, 120, 'Station');
            $tooLong($contact, 30, 'Contact number');
            $duplicateResponder = $pdo->prepare('SELECT id FROM tanod_personnel WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
            $duplicateResponder->execute([$name, $tanodId]);
            if ($duplicateResponder->fetchColumn()) {
                officerRespond(['success' => false, 'message' => 'Another responder already uses that name.'], 409);
            }
            $updateResponder = $pdo->prepare("
                UPDATE tanod_personnel
                SET full_name = ?, station = ?, contact_number = ?
                WHERE id = ?
            ");
            $updateResponder->execute([$name, $station !== '' ? $station : 'Barangay Base', $contact !== '' ? $contact : null, $tanodId]);
            if ($updateResponder->rowCount() === 0) {
                $existsResponder = $pdo->prepare('SELECT 1 FROM tanod_personnel WHERE id = ?');
                $existsResponder->execute([$tanodId]);
                if (!$existsResponder->fetchColumn()) {
                    officerRespond(['success' => false, 'message' => 'Responder not found.'], 404);
                }
            }
            officerRespond(['success' => true, 'message' => 'Responder profile updated.']);

        case 'update_responder_availability':
            $tanodId = (int) ($input['tanod_id'] ?? 0);
            $availability = strtoupper(trim((string) ($input['status'] ?? '')));
            if ($tanodId < 1 || !in_array($availability, ['AVAILABLE', 'OFF_DUTY'], true)) {
                officerRespond(['success' => false, 'message' => 'Choose Available or Off Duty for a valid responder.'], 422);
            }
            $pdo->beginTransaction();
            try {
                $responderStmt = $pdo->prepare('SELECT status FROM tanod_personnel WHERE id = ? LIMIT 1 FOR UPDATE');
                $responderStmt->execute([$tanodId]);
                $currentStatus = $responderStmt->fetchColumn();
                if ($currentStatus === false) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Responder not found.'], 404);
                }
                if ($currentStatus === 'DEPLOYED') {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => "Complete the active dispatch before changing this responder's availability."], 409);
                }
                $pdo->prepare('UPDATE tanod_personnel SET status = ? WHERE id = ?')->execute([$availability, $tanodId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            officerRespond(['success' => true, 'message' => 'Responder availability updated.']);

        case 'dispatch_response_update':
            $needRef();
            if (!$dispatchTrackingReady) {
                officerRespond([
                    'success' => false,
                    'message' => 'Dispatch response tracking is not installed. Apply tanod_dispatch_migration.sql before recording field updates.',
                ], 503);
            }
            $step = strtoupper(trim((string) ($input['step'] ?? '')));
            if (!in_array($step, ['ACKNOWLEDGE', 'ARRIVE', 'SUBMIT'], true)) {
                officerRespond(['success' => false, 'message' => 'Choose acknowledge, arrival, or response submission.'], 422);
            }
            if ($step === 'SUBMIT' && !officerWorkflowTablesAvailable($pdo)) {
                officerRespond([
                    'success' => false,
                    'message' => 'Apply case_workflow_migration.sql before submitting field findings so the desk can continue the case toward resolution.',
                ], 503);
            }
            $findings = trim((string) ($input['findings'] ?? ''));
            $actionTaken = trim((string) ($input['action_taken'] ?? ''));
            if ($step === 'SUBMIT' && ($findings === '' || $actionTaken === '')) {
                officerRespond(['success' => false, 'message' => 'Enter both field findings and action taken before submitting the response.'], 422);
            }
            $tooLong($findings, 5000, 'Field findings');
            $tooLong($actionTaken, 5000, 'Action taken');

            $pdo->beginTransaction();
            try {
                $caseStmt = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
                $caseStmt->execute([$refNumber]);
                $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                if (!$case) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
                }
                $dispatchStmt = $pdo->prepare("
                    SELECT id, status, unit
                    FROM tanod_dispatch_records
                    WHERE incident_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $dispatchStmt->execute([(int) $case['id']]);
                $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
                if (!$dispatch) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'This case has no tracked responder assignment. Assign responders first.'], 409);
                }
                $currentStep = strtoupper((string) $dispatch['status']);
                $nextStep = match ($step) {
                    'ACKNOWLEDGE' => ['from' => ['DISPATCHED'], 'to' => 'ACKNOWLEDGED', 'stamp' => 'acknowledged_at', 'actor' => 'acknowledged_by_name', 'note' => 'Responder acknowledged the dispatch assignment.'],
                    'ARRIVE' => ['from' => ['ACKNOWLEDGED'], 'to' => 'ON_SCENE', 'stamp' => 'arrived_at', 'actor' => 'arrived_by_name', 'note' => 'Responder arrived on scene.'],
                    default => ['from' => ['ON_SCENE'], 'to' => 'RESPONSE_SUBMITTED', 'stamp' => null, 'note' => ''],
                };
                if (!in_array($currentStep, $nextStep['from'], true)) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'That response step is not available for the current dispatch progress.'], 409);
                }
                if ($step === 'SUBMIT' && !caseWorkflowAllowsTransition((string) $case['status'], 'IN_PROGRESS')) {
                    $pdo->rollBack();
                    officerRespond(['success' => false, 'message' => 'The case cannot continue to active handling from its current status.'], 409);
                }

                if ($step === 'SUBMIT') {
                    $pdo->prepare("
                        INSERT INTO case_findings
                            (incident_id, observations, action_taken, findings,
                             submitted_by_user_id, submitted_by_name, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        (int) $case['id'],
                        "Field response recorded by {$actorName} on behalf of {$dispatch['unit']}.",
                        $actionTaken,
                        $findings,
                        $actorId,
                        $actorName,
                    ]);
                    $pdo->prepare("
                        UPDATE tanod_dispatch_records
                        SET status = 'RESPONSE_SUBMITTED', findings = ?, action_taken = ?,
                            updated_by_user_id = ?, updated_by_name = ?, updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$findings, $actionTaken, $actorId, $actorName, (int) $dispatch['id']]);
                    $pdo->prepare("UPDATE incident_reports SET status = 'IN_PROGRESS', deployed_unit = NULL WHERE id = ?")
                        ->execute([(int) $case['id']]);
                    $pdo->prepare("
                        UPDATE tanod_personnel tp
                        JOIN tanod_dispatch_record_responders dr ON dr.tanod_id = tp.id
                        SET tp.status = 'AVAILABLE'
                        WHERE dr.dispatch_id = ?
                    ")->execute([(int) $dispatch['id']]);
                    $note = "Field response submitted by {$actorName} on behalf of {$dispatch['unit']}. Findings: {$findings} Action taken: {$actionTaken}";
                    $milestoneStatus = 'IN_PROGRESS';
                } else {
                    $pdo->prepare("
                        UPDATE tanod_dispatch_records
                        SET status = ?, {$nextStep['stamp']} = NOW(), {$nextStep['actor']} = ?,
                            updated_by_user_id = ?, updated_by_name = ?, updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$nextStep['to'], $actorName, $actorId, $actorName, (int) $dispatch['id']]);
                    $note = $nextStep['note'] . " Recorded by desk officer {$actorName} on behalf of {$dispatch['unit']}.";
                    $milestoneStatus = (string) $case['status'];
                }
                $pdo->prepare("
                    INSERT INTO case_milestones
                        (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    (int) $case['id'],
                    $refNumber,
                    $milestoneStatus,
                    $actorName,
                    $dispatch['unit'],
                    $note,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            officerRespond([
                'success' => true,
                'message' => $step === 'SUBMIT'
                    ? 'Field response recorded. The case is back in active handling for desk follow-up.'
                    : ($step === 'ARRIVE' ? 'Responder arrival recorded.' : 'Responder acknowledgement recorded.'),
            ]);

        case 'accept_blotter':
            $needRef();
            $customNote = trim((string) ($input['note'] ?? ''));
            $note = ($customNote !== '') ? $customNote : 'Accepted from desk triage into official blotter.';
            $tooLong($note, 1000, 'Desk note');

            officerTransition($pdo, $refNumber, [
                'status'           => 'IN_PROGRESS',
                'allowed_from'     => ['PENDING', 'UNDER_REVIEW', 'CRITICAL'],
                'milestone_status' => 'IN_PROGRESS',
                'milestone_stage'  => 'DESK_REVIEW',
                'officer'          => $actorName,
                'note'             => $note,
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} accepted into active blotter."]);

        case 'flag_reject':
            $needRef();
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                officerRespond(['success' => false, 'message' => 'Provide a reason for rejecting this report.'], 422);
            }
            $tooLong($reason, 1000, 'Rejection reason');

            officerTransition($pdo, $refNumber, [
                'status' => 'REJECTED',
                'allowed_from' => ['PENDING', 'UNDER_REVIEW', 'CRITICAL'],
                'milestone_status' => 'REJECTED',
                'milestone_stage' => 'REPORT_REJECTED',
                'officer' => $actorName,
                'note' => "Report rejected during triage. Reason: {$reason}",
            ]);

            $idStmt = $pdo->prepare('SELECT id FROM incident_reports WHERE reference_number = ? LIMIT 1');
            $idStmt->execute([$refNumber]);
            $rejId = (int) $idStmt->fetchColumn();
            if ($rejId > 0) {
                sendCaseUpdateSms($pdo, $rejId, 'REJECTED', $reason);
            }

            officerRespond(['success' => true, 'message' => "Report #{$refNumber} rejected and removed from triage."]);

        case 'dispatch_tanod':
            $needRef();
            $notes = trim((string) ($input['dispatch_notes'] ?? ''));
            if ($notes === '') $notes = 'Immediate responder unit dispatched on-site.';
            $tooLong($notes, 1000, 'Dispatch notes');
            $responderIds = $input['responder_ids'] ?? [];
            if (!is_array($responderIds)) {
                officerRespond(['success' => false, 'message' => 'Select one or more responders for this dispatch.'], 422);
            }
            $responderIds = array_values(array_unique(array_filter(array_map('intval', $responderIds), static fn (int $id): bool => $id > 0)));
            if (!$responderIds) {
                officerRespond(['success' => false, 'message' => 'Select one or more available responders for this dispatch.'], 422);
            }
            $tooLong(implode(',', $responderIds), 1000, 'Responder selection');

            officerTransition($pdo, $refNumber, [
                'status'           => 'DISPATCHED',
                'allowed_from'     => ['IN_PROGRESS', 'DISPATCHED'],
                'milestone_status' => 'DISPATCHED',
                'milestone_stage'  => 'UNIT_DISPATCHED',
                'officer'          => $actorName,
                'unit'             => 'Responder assignment pending',
                'milestone_unit'   => 'Responder assignment pending',
                'recommendation'   => 'Responder unit assigned for on-site assessment.',
                'note'             => $notes,
                'dispatch'         => [
                    'responder_ids' => $responderIds,
                    'notes' => $notes,
                    'user_id' => $actorId,
                    'user_name' => $actorName,
                ],
            ]);

            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

        case 'endorse_lupon':
        case 'refer_case':
        case 'request_captain_endorsement':
            $needRef();
            $defaultDestination = $action === 'endorse_lupon' ? 'LUPON' : ($action === 'request_captain_endorsement' ? 'CAPTAIN' : '');
            $destination = strtoupper(trim((string) ($input['destination'] ?? $defaultDestination)));
            $reason = trim((string) ($input['reason'] ?? ($input['remarks'] ?? '')));
            if (!in_array($destination, ['LUPON', 'CAPTAIN'], true)) {
                officerRespond(['success' => false, 'message' => 'Choose Lupon or Captain as the referral destination.'], 422);
            }
            if ($reason === '') {
                officerRespond(['success' => false, 'message' => 'A referral reason is required.'], 422);
            }
            $tooLong($reason, 1000, 'Referral reason');

            if ($destination === 'CAPTAIN') {
                $pdo->beginTransaction();
                try {
                    $caseStmt = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
                    $caseStmt->execute([$refNumber]);
                    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$case) {
                        $pdo->rollBack();
                        officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
                    }
                    if (!in_array($case['status'], ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION'], true)) {
                        $pdo->rollBack();
                        officerRespond(['success' => false, 'message' => 'Refer the case after triage or dispatch.'], 409);
                    }
                    $existingCaptainReferralStmt = $pdo->prepare("
                        SELECT status
                        FROM endorsements
                        WHERE case_id = ? AND type = 'CAPTAIN_ENDORSEMENT'
                        ORDER BY id ASC
                        LIMIT 1
                    ");
                    $existingCaptainReferralStmt->execute([(int) $case['id']]);
                    $existingCaptainReferral = $existingCaptainReferralStmt->fetchColumn();
                    if ($existingCaptainReferral !== false) {
                        $pdo->rollBack();
                        officerRespond([
                            'success' => false,
                            'message' => $existingCaptainReferral === 'ENDORSED'
                                ? 'This case has already been referred to and endorsed by the Captain.'
                                : 'This case has already been referred to the Captain.',
                        ], 409);
                    }
                    $pdo->prepare("
                        INSERT INTO endorsements (case_id, type, status, referred_by_user_id, referred_at, remarks)
                        VALUES (?, 'CAPTAIN_ENDORSEMENT', 'PENDING', ?, NOW(), ?)
                    ")->execute([(int) $case['id'], $actorId, $reason]);
                    $pdo->prepare('INSERT INTO case_milestones
                            (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())')
                        ->execute([(int) $case['id'], $refNumber, $case['status'], $actorName, "Referred to Captain. Reason: {$reason}"]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                officerRespond(['success' => true, 'message' => "Case #{$refNumber} referred to the Captain."]);
            }

            $mediator = trim((string) ($input['mediator'] ?? ''));
            $schedule = trim((string) ($input['schedule'] ?? ''));
            if ($mediator === '') {
                officerRespond(['success' => false, 'message' => 'A Lupon mediator is required.'], 422);
            }
            $tooLong($mediator, 120, 'Mediator name');
            $schedule = $parseSchedule($schedule);

            officerTransition($pdo, $refNumber, [
                'status'           => 'FOR_RESOLUTION',
                'allowed_from'     => ['IN_PROGRESS', 'DISPATCHED'],
                'milestone_status' => 'FOR_RESOLUTION',
                'milestone_stage'  => 'MEDIATION',
                'officer'          => $mediator,
                'unit'             => $mediator,
                'note'             => "Referred to Katarungang Pambarangay. Reason: {$reason} Mediation scheduled on {$schedule} with {$mediator}.",
                'lupon_case'       => ['stage' => 'MEDIATION', 'next_hearing' => $schedule, 'status' => 'ONGOING'],
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} endorsed to Lupon mediation."]);

        case 'reschedule_lupon':
            $needRef();
            $mediator = trim((string) ($input['mediator'] ?? ''));
            $schedule = $parseSchedule(trim((string) ($input['schedule'] ?? '')));
            if ($mediator !== '') {
                $tooLong($mediator, 120, 'Mediator name');
            }

            $transition = [
                'status'           => 'FOR_RESOLUTION',
                'expected_status'  => 'FOR_RESOLUTION',
                'milestone_status' => 'FOR_RESOLUTION',
                'milestone_stage'  => 'MEDIATION_RESCHEDULED',
                'officer'          => $actorName,
                'note'             => "Lupon mediation hearing rescheduled to {$schedule}.",
                'lupon_case'       => ['stage' => 'MEDIATION', 'next_hearing' => $schedule, 'status' => 'ONGOING'],
            ];
            if ($mediator !== '') {
                $transition['unit'] = $mediator;
            }
            officerTransition($pdo, $refNumber, $transition);
            officerRespond(['success' => true, 'message' => "Hearing for case #{$refNumber} rescheduled."]);

        case 'settle_lupon':
            officerRespond(['success' => false, 'message' => 'Use the findings review and resolution workflow to record a mediation settlement.'], 422);

        case 'reopen_case':
            $needRef();
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                officerRespond(['success' => false, 'message' => 'A reason is required to reopen this case.'], 422);
            }
            $tooLong($reason, 1000, 'Reopening reason');
            officerTransition($pdo, $refNumber, [
                'status' => 'IN_PROGRESS',
                'expected_status' => 'CLOSED',
                'milestone_status' => 'IN_PROGRESS',
                'officer' => $actorName,
                'note' => "Case reopened by authorized review. Reason: {$reason}",
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} reopened for authorized review."]);

        case 'get_case_review_details':
            $needRef();
            $stmt = $pdo->prepare("SELECT * FROM incident_reports WHERE reference_number = :ref LIMIT 1");
            $stmt->execute([':ref' => $refNumber]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                officerRespond(['success' => false, 'message' => 'Case not found.'], 404);
            }

            $milestoneStmt = $pdo->prepare("
                SELECT status_snapshot, officer_in_charge, deployed_unit, action_note, created_at
                FROM case_milestones
                WHERE incident_id = ?
                ORDER BY created_at ASC, id ASC
            ");
            $milestoneStmt->execute([(int) $case['id']]);
            $timeline = array_map(static function (array $entry): array {
                return [
                    'action' => $entry['action_note'],
                    'status' => $entry['status_snapshot'],
                    'user' => $entry['officer_in_charge'] ?: 'System',
                    'assigned_to' => $entry['deployed_unit'],
                    'created_at' => $entry['created_at'],
                    'type' => 'case_activity',
                ];
            }, $milestoneStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $workflowReady = officerWorkflowTablesAvailable($pdo);
            $caseFindings = [];
            $caseResolutions = [];
            $caseClosures = [];
            if ($workflowReady) {
                $findingsStmt = $pdo->prepare('SELECT observations, persons_contacted, action_taken, findings,
                        recommended_next_steps, submitted_by_name, created_at
                    FROM case_findings WHERE incident_id = ? ORDER BY created_at ASC, id ASC');
                $findingsStmt->execute([(int) $case['id']]);
                $caseFindings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($caseFindings as $finding) {
                    $timeline[] = [
                        'action' => 'Field findings recorded. Observations: ' . $finding['observations']
                            . ' Action taken: ' . $finding['action_taken']
                            . ' Findings: ' . $finding['findings']
                            . ($finding['persons_contacted'] ? ' Persons contacted: ' . $finding['persons_contacted'] : '')
                            . ($finding['recommended_next_steps'] ? ' Recommended next steps: ' . $finding['recommended_next_steps'] : ''),
                        'status' => 'FINDINGS_RECORDED',
                        'user' => $finding['submitted_by_name'],
                        'assigned_to' => null,
                        'created_at' => $finding['created_at'],
                        'type' => 'finding',
                    ];
                }

                $resolutionStmt = $pdo->prepare('SELECT id, outcome, findings_reviewed_summary, conclusion, settlement_terms,
                        remaining_obligations, follow_up_at, resulting_status, resolved_by_name, resolved_at
                    FROM case_resolutions WHERE incident_id = ? ORDER BY resolved_at ASC, id ASC');
                $resolutionStmt->execute([(int) $case['id']]);
                $caseResolutions = $resolutionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($caseResolutions as $resolution) {
                    $timeline[] = [
                        'action' => 'Resolution outcome: ' . $resolution['outcome'] . '. Conclusion: ' . $resolution['conclusion']
                            . ($resolution['settlement_terms'] ? ' Settlement terms: ' . $resolution['settlement_terms'] : '')
                            . ($resolution['remaining_obligations'] ? ' Remaining obligations: ' . $resolution['remaining_obligations'] : '')
                            . ($resolution['follow_up_at'] ? ' Follow-up scheduled: ' . $resolution['follow_up_at'] . '.' : ''),
                        'status' => $resolution['resulting_status'],
                        'user' => $resolution['resolved_by_name'],
                        'assigned_to' => null,
                        'created_at' => $resolution['resolved_at'],
                        'type' => 'resolution',
                    ];
                }
                $closureStmt = $pdo->prepare('SELECT outcome, findings_reviewed_summary, conclusion, closed_by_name, closed_at
                    FROM case_workflow_closures WHERE incident_id = ? ORDER BY closed_at ASC, id ASC');
                $closureStmt->execute([(int) $case['id']]);
                $caseClosures = $closureStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($caseClosures as $closure) {
                    $timeline[] = [
                        'action' => 'Resolution finalized. Outcome: ' . $closure['outcome'] . '. Conclusion: ' . $closure['conclusion'],
                        'status' => 'CLOSED',
                        'user' => $closure['closed_by_name'],
                        'assigned_to' => null,
                        'created_at' => $closure['closed_at'],
                        'type' => 'closure',
                    ];
                }
            }

            $severityColumns = [];
            try {
                $severityColumns = array_column($pdo->query("SHOW COLUMNS FROM severity_audit_logs")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            } catch (Throwable $e) {
                $severityColumns = [];
            }
            if (in_array('created_at', $severityColumns, true)
                && in_array('incident_id', $severityColumns, true)
                && in_array('action', $severityColumns, true)
                && in_array('final_priority', $severityColumns, true)
                && in_array('original_priority', $severityColumns, true)
                && in_array('actor_user_id', $severityColumns, true)) {
                $severityStmt = $pdo->prepare("
                    SELECT sal.action, sal.original_priority, sal.final_priority, sal.created_at, u.full_name AS actor_name
                    FROM severity_audit_logs sal
                    LEFT JOIN users u ON u.id = sal.actor_user_id
                    WHERE sal.incident_id = ?
                    ORDER BY sal.created_at ASC
                ");
                $severityStmt->execute([(int) $case['id']]);
                foreach ($severityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $severityEvent) {
                    $isTriage = strtoupper((string) $severityEvent['action']) === 'AI_TRIAGE';
                    $timeline[] = [
                        'action' => $isTriage
                            ? 'Triage assessment completed. Priority: ' . $severityEvent['final_priority'] . '.'
                            : 'Priority updated from ' . ($severityEvent['original_priority'] ?: 'unrecorded')
                                . ' to ' . $severityEvent['final_priority'] . '.',
                        'status' => $isTriage ? 'TRIAGE' : 'PRIORITY_UPDATED',
                        'user' => $severityEvent['actor_name'] ?: 'System',
                        'assigned_to' => null,
                        'created_at' => $severityEvent['created_at'],
                        'type' => 'priority_change',
                    ];
                }
            }

            $endorsementsTableStmt = $pdo->prepare("
                SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endorsements'
                LIMIT 1
            ");
            $endorsementsTableStmt->execute();
            $caseEndorsements = [];
            if ($endorsementsTableStmt->fetchColumn()) {
                $endorsementStmt = $pdo->prepare("
                    SELECT e.id, e.type, e.status, e.referred_at, e.endorsed_at, e.remarks,
                        referred.full_name AS referred_by, endorsed.full_name AS endorsed_by
                    FROM endorsements e
                    LEFT JOIN users referred ON referred.id = e.referred_by_user_id
                    LEFT JOIN users endorsed ON endorsed.id = e.endorsed_by_user_id
                    WHERE e.case_id = ?
                    ORDER BY e.referred_at ASC, e.id ASC
                ");
                $endorsementStmt->execute([(int) $case['id']]);
                $caseEndorsements = $endorsementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($caseEndorsements as $endorsement) {
                    $isCaptainReferral = $endorsement['type'] === 'CAPTAIN_ENDORSEMENT';
                    $referralTarget = $isCaptainReferral ? 'Captain' : 'Lupon';
                    if (!empty($endorsement['referred_at'])) {
                        $timeline[] = [
                            'action' => "Referred to {$referralTarget}" . ($endorsement['remarks'] ? ': ' . $endorsement['remarks'] : '.'),
                            'status' => 'REFERRAL',
                            'user' => $endorsement['referred_by'] ?: 'System',
                            'assigned_to' => $referralTarget,
                            'created_at' => $endorsement['referred_at'],
                            'type' => 'referral',
                        ];
                    }
                    if (!empty($endorsement['endorsed_at'])) {
                        $timeline[] = [
                            'action' => $isCaptainReferral ? 'Captain endorsement completed and signed.' : 'Lupon endorsement completed.',
                            'status' => $isCaptainReferral ? 'CAPTAIN_ENDORSED' : 'ENDORSED',
                            'user' => $endorsement['endorsed_by'] ?: 'System',
                            'assigned_to' => $referralTarget,
                            'created_at' => $endorsement['endorsed_at'],
                            'type' => 'referral',
                        ];
                    }
                }
            }
            usort($timeline, static fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

            $luponStmt = $pdo->prepare('SELECT stage, next_hearing, status, parties FROM lupon_cases WHERE case_id = ? ORDER BY id DESC LIMIT 1');
            $luponStmt->execute([(int) $case['id']]);
            $luponDetails = $luponStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $aiData = !empty($case['ai_analysis_json']) ? json_decode($case['ai_analysis_json'], true) : null;
            if (!$aiData && class_exists('TriageEngine')) {
                $aiData = TriageEngine::evaluateIncident($case['narrative_description']);
                try {
                    $upd = $pdo->prepare("
                        UPDATE incident_reports 
                        SET ai_analysis_json = :json, legal_routing = :lr, weapon_detected = :wp, vawc_minor_flag = :vf
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':json' => json_encode($aiData, JSON_UNESCAPED_UNICODE),
                        ':lr'   => $aiData['legal_routing'] ?? null,
                        ':wp'   => $aiData['weapon_flag'] ?? 'NONE',
                        ':vf'   => !empty($aiData['vawc_minor_flag']) ? 1 : 0,
                        ':id'   => $case['id']
                    ]);
                } catch (Throwable $ignore) {}
            }

            $dupStmt = $pdo->prepare("
                SELECT reference_number, incident_type, complainant_name, created_at
                FROM incident_reports
                WHERE purok = :purok
                  AND id <> :current_id
                  AND created_at >= (NOW() - INTERVAL 2 HOUR)
                ORDER BY created_at DESC
                LIMIT 3
            ");
            $dupStmt->execute([
                ':purok'      => $case['purok'],
                ':current_id' => $case['id']
            ]);
            $recentPurokDuplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $histStmt = $pdo->prepare("
                SELECT reference_number, incident_type, status, priority_level, created_at 
                FROM incident_reports 
                WHERE complainant_name = :cname
                  AND id <> :current_id
                ORDER BY created_at DESC 
                LIMIT 4
            ");
            $histStmt->execute([
                ':cname'      => $case['complainant_name'],
                ':current_id' => $case['id']
            ]);
            $complainantHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            officerRespond([
                'success'           => true,
                'case'              => $case,
                'timeline'          => $timeline,
                'endorsements'      => $caseEndorsements,
                'findings'          => $caseFindings,
                'resolutions'       => $caseResolutions,
                'closures'          => $caseClosures,
                'workflow_ready'    => $workflowReady,
                'lupon'             => $luponDetails,
                'ai_analysis'       => $aiData,
                'duplicates'        => $recentPurokDuplicates,
                'history_matches'   => $complainantHistory
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

                $msStmt = $pdo->prepare("
                    INSERT INTO case_milestones (
                        incident_id, tracking_id, status_snapshot, officer_in_charge, action_note
                    ) VALUES (
                        :inc_id, :track_id, 'PENDING', :officer, :note
                    )
                ");
                $msStmt->execute([
                    ':inc_id'   => $incidentId,
                    ':track_id' => $refNumber,
                    ':officer'  => $actorName,
                    ':note'     => "Incident encoded. Assessed priority: {$finalPriority}."
                ]);

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

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 405);
    }
} catch (PDOException $e) {
    officerRespond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}